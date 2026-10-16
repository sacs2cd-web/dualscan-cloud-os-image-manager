#!/usr/bin/env python3
"""Qualify the supplied PHP public router on an isolated separate localhost port."""
import hashlib
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        return None


def call(path, method='GET', data=None):
    # The secondary listener uses the installation's canonical Host, avoiding a
    # redirect to the primary port that would otherwise hide routing failures.
    request = urllib.request.Request('http://127.0.0.1:8769' + path, data=data, method=method, headers={'Host': '127.0.0.1:8767', 'Content-Type': 'application/x-www-form-urlencoded'})
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as response:
            return response.status, response.read(), response.headers
    except urllib.error.HTTPError as error:
        return error.code, error.read(), error.headers


def main():
    cases = {}
    for path in ['/wp-admin/', '/wp-admin/load-scripts.php', '/wp-admin/load-styles.php', '/wp-admin/setup-config.php', '/wp-login.php', '/xmlrpc.php', '/wp-json/wp/v2/users', '/?rest_route=/dualscan-cloud/v1/images', '/wp-content/plugins/fixture-active/fixture-active.php', '/wp-content/plugins/dualscan-cloud-os-image-manager/includes/class-plugin.php', '/wp-config.php', '/wp-includes/version.php', '/wp-content/mu-plugins/dualscan-appliance-policy.json', '/index.php/wp-admin/', '/%77p-admin/load-scripts.php', '/wp-admin%252fsetup-config.php']:
        status, body, headers = call(path)
        assert status == 403, (path, status, body[:100])
        cases[path] = {'passed': True, 'status': status}
    for method, data in [('GET', None), ('HEAD', None), ('POST', b'dualscan_fixture_frontend=1')]:
        status, body, headers = call('/', method, data)
        assert status == 200, (method, status, body[:100])
        assert b'id="wpadminbar"' not in body
        cases['publicFront:' + method] = {'passed': True, 'status': status}
    status, body, headers = call('/wp-includes/css/dist/block-library/style.min.css')
    assert status == 200 and len(body) > 100
    cases['publicStaticCssPreserved'] = {'passed': True, 'status': status, 'bytes': len(body)}
    status, body, headers = call('/', 'DELETE')
    assert status == 403
    cases['unsupportedHttpMethodDenied'] = {'passed': True, 'status': status}
    router = Path(__file__).resolve().parents[2] / 'deployment/public-router.php'
    report = {'passed': True, 'runtime': 'Native PHP development server with supplied deployment/public-router.php on separate localhost listener', 'routerSha256': hashlib.sha256(router.read_bytes()).hexdigest(), 'nginxExecuted': False, 'scope': 'Disposable restored sealed site, direct response statuses checked without following redirects', 'cases': cases}
    Path(sys.argv[1]).write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({'passed': True, 'cases': len(cases), 'report': sys.argv[1]}))


if __name__ == '__main__':
    main()
