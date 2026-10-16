#!/usr/bin/env python3
"""Check an already-restored sealed WordPress runtime without unsealing it."""
import json
import sys
from pathlib import Path
from http_permissions import Session


def assert_sealed(owner=None, router_qualified=False):
    anonymous = Session()
    cases = {}
    sessions = [("anonymous", anonymous)]
    if owner is not None:
        sessions.append(("alreadyAuthenticatedOwner", owner))
    for name, session in sessions:
        for path in ["/wp-login.php", "/wp-admin/", "/wp-admin/plugins.php", "/wp-admin/plugin-install.php", "/wp-admin/plugin-editor.php", "/wp-admin/admin.php?page=dualscan-cloud-images", "/wp-admin/admin-ajax.php?action=rest-nonce", "/?rest_route=/dualscan-cloud/v1/images", "/?rest_route=/wp/v2/users", "/wp-json/wp/v2/users", "/xmlrpc.php"]:
            status, body = session.call(path)
            assert status in [403, 404], (name, path, status, body[:160])
            cases[name + ":" + path] = {"passed": True, "status": status}
    status, body = (owner or anonymous).call("/")
    assert status == 200 and 'id="wpadminbar"' not in body
    cases["publicWebAppServesWithoutAdminBar"] = {"passed": True, "status": status}
    if router_qualified:
        for path in ["/wp-content/plugins/fixture-active/fixture-active.php", "/wp-content/plugins/fixture-inactive/fixture-inactive.php", "/wp-config.php", "/wp-includes/version.php"]:
            status, body = anonymous.call(path)
            assert status in [403, 404], (path, status)
            cases["hostRouter:" + path] = {"passed": True, "status": status}
    return cases


def main():
    router_qualified = "--router-qualified" in sys.argv
    cases = assert_sealed(router_qualified=router_qualified)
    report = {"passed": True, "scope": "Exclusively owned disposable localhost fixture", "mode": "Restored sealed runtime; no unsealing operation", "authenticatedOwnerQualified": False, "hostRouterQualified": router_qualified, "cases": cases}
    Path(sys.argv[1]).write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({"passed": True, "cases": len(cases), "report": sys.argv[1]}))


if __name__ == "__main__":
    main()
