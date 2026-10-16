#!/usr/bin/env python3
"""HTTP integration checks against the isolated localhost WordPress fixture only."""
import http.cookiejar
import copy
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

BASE = "http://127.0.0.1:8767"


class Session:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.last_headers = None

    def clone(self):
        result = Session()
        for cookie in self.cookies:
            result.cookies.set_cookie(copy.copy(cookie))
        return result

    def call(self, path, data=None, nonce=None, method=None):
        headers = {}
        if data is not None:
            data = json.dumps(data).encode()
            headers["Content-Type"] = "application/json"
        if nonce is not None:
            headers["X-WP-Nonce"] = nonce
        request = urllib.request.Request(BASE + path, data=data, headers=headers, method=method)
        try:
            with self.client.open(request, timeout=120) as response:
                self.last_headers = response.headers
                return response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            self.last_headers = error.headers
            return error.code, error.read().decode()

    def login(self, login, password):
        self.call("/wp-login.php")
        data = urllib.parse.urlencode({"log": login, "pwd": password, "wp-submit": "Log In", "testcookie": "1", "redirect_to": BASE + "/wp-admin/"}).encode()
        with self.client.open(urllib.request.Request(BASE + "/wp-login.php", data=data), timeout=30) as response:
            html = response.read().decode()
        assert "login_error" not in html, f"Local synthetic login failed for {login}"

    def nonce(self):
        status, text = self.call("/wp-admin/admin-ajax.php?action=rest-nonce")
        assert status == 200 and re.fullmatch(r"[a-f0-9]{10}", text), "Core WordPress did not return the local session nonce"
        return text


def main():
    cases = {}
    anonymous = Session()
    code, body = anonymous.call("/?rest_route=/dualscan-cloud/v1/images")
    assert code == 403
    cases["anonymousImageListDenied"] = {"passed": True, "status": code}
    owner = Session()
    owner.login("dualscan_admin", "LocalAdminOnly2026!")
    nonce = owner.nonce()
    code, body = owner.call("/wp-admin/admin.php?page=dualscan-cloud-images")
    assert code == 200 and "OS Image Manager" in body and "dc-create" in body
    cases["ownerWorkspaceLoads"] = {"passed": True, "status": code}
    code, body = owner.call("/?rest_route=/dualscan-cloud/v1/images", nonce=nonce)
    assert code == 200, body
    cases["ownerAuthenticatedImageListAllowed"] = {"passed": True, "status": code}
    for label, invalid_nonce in [("missingNonceDenied", None), ("wrongNonceDenied", "invalid0000")]:
        code, body = owner.call("/?rest_route=/dualscan-cloud/v1/images", nonce=invalid_nonce)
        assert code == 403
        cases[label] = {"passed": True, "status": code}
    code, body = owner.call("/?rest_route=/dualscan-cloud/v1/jobs", {"type": "create", "quiesced": False}, nonce)
    assert code == 400, body
    cases["backupRequiresPausedWritersAcknowledgement"] = {"passed": True, "status": code}
    code, body = owner.call("/?rest_route=/dualscan-cloud/v1/imports", {"name": "forbidden.zip", "bytes": 1024}, nonce)
    assert code == 400, body
    cases["unsupportedUploadExtensionDenied"] = {"passed": True, "status": code}
    for login, password in [("dualscan_client", "LocalClientOnly2026!"), ("dualscan_editor", "LocalEditorOnly2026!")]:
        client = Session()
        client.login(login, password)
        client_nonce = client.nonce()
        for path in ["/wp-admin/plugins.php", "/wp-admin/plugin-install.php", "/wp-admin/plugin-editor.php", "/wp-admin/admin.php?page=dualscan-cloud-images", "/?rest_route=/dualscan-cloud/v1/images", "/?rest_route=/wp/v2/plugins"]:
            code, body = client.call(path, nonce=client_nonce)
            assert code == 403, (login, path, code, body[:160])
            cases[login + ":" + path] = {"passed": True, "status": code}
    report = {"passed": True, "runtime": "Actual HTTP cookies, WordPress nonce verification and native admin/REST routes", "mode": "Original authoring installation before image restoration", "scope": "Synthetic localhost fixture; no live sites", "cases": cases}
    Path(sys.argv[1]).write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({"passed": True, "cases": len(cases), "report": sys.argv[1]}))


if __name__ == "__main__":
    main()
