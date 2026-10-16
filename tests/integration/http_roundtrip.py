#!/usr/bin/env python3
"""Exercise the installed plugin's real owner web API on a disposable localhost site."""
import hashlib
import json
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

from http_permissions import BASE, Session
from sealed_permissions import assert_sealed

PHP = "/Applications/MAMP/bin/php/php8.2.0/bin/php"
RUNTIME = Path("/Users/williamgrecia/.local/share/dualscan-cloud-os-image-manager-runtime/20261016")
ROOT = RUNTIME / "sites/source"
PASSWORD = "Web-Adapter-Fixture-2026"


def config_definitions(path):
    text = path.read_text()
    values = {match[1]: match[2].strip() for match in re.findall(r"\bdefine\(\s*(['\"])([^'\"]+)\1\s*,(.*?)\);", text, re.S)}
    prefix = re.search(r"\$table_prefix\s*=\s*([^;]+);", text)
    values['$table_prefix'] = prefix.group(1) if prefix else None
    return values


def api(session, path, nonce, data=None):
    code, body = session.call("/?rest_route=/dualscan-cloud/v1/" + path, data, nonce)
    result = json.loads(body)
    assert code == 200, (code, result)
    return result


def database(action):
    code = '''$p=new PDO('mysql:host=127.0.0.1;port=33617;dbname=dualscan_source;charset=utf8mb4','dualscan_test','LocalOnlyFixture2026');$p->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);'''
    if action == "snapshot":
        code += '''$r=$p->query('SELECT id,label,HEX(payload) AS payload,optional_value,amount,serialized,HEX(latin_text) AS latin_text,HEX(latin_label) AS latin_label,CAST(json_value AS CHAR CHARACTER SET utf8mb4) AS json_value,UNIX_TIMESTAMP(timestamp_value) AS timestamp_instant FROM wp_dualscan_fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode($r,JSON_THROW_ON_ERROR);'''
    elif action == "mutate":
        code += '''$p->exec("UPDATE wp_dualscan_fixture SET label='Altered by isolated web integration test' WHERE id=1");'''
    else:
        raise AssertionError("Unsupported fixture action")
    return subprocess.check_output([PHP, "-r", code])


def policy_snapshot():
    helper = str(Path(__file__).with_name('sealed_database_audit.php').resolve())
    code = "require " + repr(helper) + ";$p=new PDO('mysql:host=127.0.0.1;port=33617;dbname=dualscan_source;charset=utf8mb4','dualscan_test','LocalOnlyFixture2026',[PDO::ATTR_STRINGIFY_FETCHES=>true]);echo base64_encode(serialize(captureApplianceDatabaseState($p)));"
    return subprocess.check_output([PHP, '-r', code]).decode()


def policy_verify(before, policy):
    helper = str(Path(__file__).with_name('sealed_database_audit.php').resolve())
    code = "require " + repr(helper) + ";$v=json_decode(file_get_contents('php://stdin'),true,512,JSON_THROW_ON_ERROR);$p=new PDO('mysql:host=127.0.0.1;port=33617;dbname=dualscan_source;charset=utf8mb4','dualscan_test','LocalOnlyFixture2026',[PDO::ATTR_STRINGIFY_FETCHES=>true]);echo json_encode(assertApplianceDatabaseState($p,unserialize(base64_decode($v['before']),['allowed_classes'=>false]),$v['policy']),JSON_THROW_ON_ERROR);"
    return json.loads(subprocess.check_output([PHP, '-r', code], input=json.dumps({'before': before, 'policy': policy}).encode()))


def rejected(session, path, nonce, data, name, cases):
    code, body = session.call('/?rest_route=/dualscan-cloud/v1/' + path, data, nonce)
    assert code == 400, (name, code, body[:160])
    cases[name] = {'passed': True, 'status': code}


def deployment_approval(session, nonce, imported, inspection, create_id):
    cases = {}
    rejected(session, 'deployments', nonce, {'importId': imported['id'], 'inspectionId': create_id}, 'nonInspectionJobCannotApproveDeployment', cases)
    plan = api(session, 'deployments', nonce, {'importId': imported['id'], 'inspectionId': inspection['id']})
    assert plan['step'] == 0 and hashlib.sha256(plan['termsText'].encode()).hexdigest() == plan['termsSha256']
    path = 'deployments/' + plan['id'] + '/confirm'
    rejected(session, path, nonce, {'step': 2, 'accepted': True}, 'cannotSkipFirstConfirmation', cases)
    rejected(session, path, nonce, {'step': 1, 'accepted': False}, 'uncheckedFirstConfirmationDenied', cases)
    api(session, path, nonce, {'step': 1, 'accepted': True})
    rejected(session, path, nonce, {'step': 1, 'accepted': True}, 'cannotReplayOrReorderConfirmation', cases)
    rejected(session, path, nonce, {'step': 3, 'accepted': True}, 'cannotSkipSecondConfirmation', cases)
    api(session, path, nonce, {'step': 2, 'accepted': True})
    restore = {'type': 'restore', 'importId': imported['id'], 'deploymentId': plan['id'], 'confirmation': 'DEPLOY INSTANCE', 'quiesced': True}
    rejected(session, 'jobs', nonce, restore, 'cannotDeployWithoutFinalEulaConfirmation', cases)
    final = {'step': 3, 'accepted': True, 'eulaAccepted': True, 'claimsAccepted': True, 'adminAccessWaived': True, 'termsSha256': plan['termsSha256']}
    for field in ['accepted', 'eulaAccepted', 'claimsAccepted', 'adminAccessWaived']:
        rejected(session, path, nonce, {**final, field: False}, 'uncheckedFinalField:' + field, cases)
    rejected(session, path, nonce, {**final, 'termsSha256': '0' * 64}, 'mismatchedTermsDigestDenied', cases)
    api(session, path, nonce, final)
    rejected(session, 'jobs', nonce, {**restore, 'confirmation': 'RESTORE'}, 'legacySingleRestoreConfirmationDenied', cases)
    rejected(session, 'jobs', nonce, {**restore, 'quiesced': False}, 'deploymentRequiresPausedWritersAcknowledgement', cases)
    # An independently owned upload identifier must not reuse approval for the inspected image.
    other = api(session, 'imports', nonce, {'name': 'binding-fixture.dualscan', 'bytes': 64})
    request = urllib.request.Request(BASE + '/?rest_route=/dualscan-cloud/v1/imports/' + other['id'] + '/chunks', data=b'Binding test only; not an executable image'.ljust(64, b'.'), headers={'Content-Type': 'application/octet-stream', 'X-WP-Nonce': nonce, 'X-Dualscan-Offset': '0'}, method='POST')
    with session.client.open(request, timeout=30) as response:
        assert json.load(response)['state'] == 'ready'
    rejected(session, 'deployments', nonce, {'importId': other['id'], 'inspectionId': inspection['id']}, 'inspectionBoundToExactUpload', cases)
    rejected(session, 'jobs', nonce, {**restore, 'importId': other['id']}, 'deploymentApprovalBoundToExactUpload', cases)
    expired = api(session, 'deployments', nonce, {'importId': imported['id'], 'inspectionId': inspection['id']})
    expired_path = 'deployments/' + expired['id'] + '/confirm'
    for step in [1, 2]:
        api(session, expired_path, nonce, {'step': step, 'accepted': True})
    api(session, expired_path, nonce, {**final, 'termsSha256': expired['termsSha256']})
    # Alter only the owned test plan's private expiry to model passage of time.
    code = "$_SERVER['HTTP_HOST']='127.0.0.1:8767';$_SERVER['REQUEST_URI']='/';require " + repr(str(ROOT / 'wp-load.php')) + ";if(!defined('DUALSCAN_IMAGE_STORAGE'))throw new RuntimeException('Explicit owned test storage is required.');echo DUALSCAN_IMAGE_STORAGE;"
    storage = Path(subprocess.check_output([PHP, '-r', code]).decode())
    plan_file = storage / ('deployment-' + expired['id'] + '.json')
    assert plan_file.is_file() and storage == RUNTIME / 'private-source'
    record = json.loads(plan_file.read_text()); record['expiresAt'] = 0
    plan_file.write_text(json.dumps(record))
    rejected(session, 'jobs', nonce, {**restore, 'deploymentId': expired['id']}, 'expiredDeploymentApprovalDenied', cases)
    job = api(session, 'jobs', nonce, restore)
    rejected(session, 'jobs', nonce, restore, 'consumedDeploymentApprovalCannotBeReused', cases)
    return job, cases


def main():
    assert ROOT.is_dir() and "dualscan-cloud-os-image-manager-runtime" in str(ROOT)
    began = time.monotonic()
    session = Session()
    session.login("dualscan_admin", "LocalAdminOnly2026!")
    nonce = session.nonce()
    original_database = database("snapshot")
    original_policy_state = policy_snapshot()
    original_files = {name: (ROOT / name).read_bytes() for name in ["custom-root-data.json", "wp-content/uploads/2026/10/photo.png", ".dualscan-fixture-hidden"]}
    configuration_hash = hashlib.sha256((ROOT / "wp-config.php").read_bytes()).hexdigest()
    original_configuration_definitions = config_definitions(ROOT / "wp-config.php")
    create = api(session, "jobs", nonce, {"type": "create", "title": "Synthetic WordPress web integration", "quiesced": True})
    created = api(session, "jobs/" + create["id"] + "/run", nonce, {"password": PASSWORD})
    assert created["state"] == "complete", created
    manifest = created["result"]["manifest"]
    assert manifest["scope"]["excludedPaths"] == []
    expected_hash = created["result"]["sha256"]
    listing = api(session, "images", nonce)
    saved = next(item for item in listing["images"] if item["id"] == create["id"])
    output = RUNTIME / "images/http-roundtrip.dualscan"
    digest = hashlib.sha256()
    with session.client.open(saved["downloadUrl"], timeout=120) as response, output.open("wb") as out:
        assert response.headers.get_content_type() == "application/octet-stream"
        assert response.headers.get("X-Content-Type-Options") == "nosniff"
        while True:
            chunk = response.read(1048576)
            if not chunk:
                break
            digest.update(chunk)
            out.write(chunk)
    assert digest.hexdigest() == expected_hash and output.stat().st_size == saved["bytes"]
    imported = api(session, "imports", nonce, {"name": "http-roundtrip.dualscan", "bytes": output.stat().st_size})
    chunk_bytes = imported["chunkBytes"]
    count = 0
    offset = 0
    with output.open("rb") as image:
        while True:
            chunk = image.read(chunk_bytes)
            if not chunk:
                break
            request = urllib.request.Request(BASE + "/?rest_route=/dualscan-cloud/v1/imports/" + imported["id"] + "/chunks", data=chunk, headers={"Content-Type": "application/octet-stream", "X-WP-Nonce": nonce, "X-Dualscan-Offset": str(offset)}, method="POST")
            with session.client.open(request, timeout=120) as response:
                result = json.load(response)
            offset += len(chunk)
            count += 1
            assert result["receivedBytes"] == offset
    assert result["state"] == "ready"
    inspect_job = api(session, "jobs", nonce, {"type": "inspect", "importId": imported["id"]})
    inspected = api(session, "jobs/" + inspect_job["id"] + "/run", nonce, {"password": PASSWORD})
    assert inspected["state"] == "complete" and inspected["result"] == manifest
    for name in original_files:
        (ROOT / name).write_bytes(b"Intentionally changed by disposable integration test")
    database("mutate")
    restore_job, approval_cases = deployment_approval(session, nonce, imported, inspect_job, create['id'])
    previous_owner = session.clone()
    restored = api(session, "jobs/" + restore_job["id"] + "/run", nonce, {"password": PASSWORD})
    logout_cookie_headers = session.last_headers.get_all('Set-Cookie', [])
    assert restored["state"] == "complete", restored
    assert restored['result']['redirectUrl'] == BASE + '/' and restored['result']['loggedOut'] is True
    assert logout_cookie_headers and all('expires=' in header.lower() or 'max-age=0' in header.lower() for header in logout_cookie_headers)
    assert database("snapshot") == original_database
    assert all((ROOT / name).read_bytes() == data for name, data in original_files.items())
    assert config_definitions(ROOT / "wp-config.php") == original_configuration_definitions
    assert not (ROOT / ".maintenance").exists()
    assert "wp-config.php" in restored["result"]["preservedPaths"]
    sealed_cases = assert_sealed(previous_owner)
    account_policy_proof = policy_verify(original_policy_state, restored['result']['deployment']['databasePolicy'])
    report = {"passed": True, "runtime": "Actual WordPress plugin HTTP owner session, wpdb adapter, jobs, download, chunked import, authenticated inspect and restore", "scope": "Disposable localhost synthetic site only", "seconds": round(time.monotonic() - began, 3), "imageBytes": output.stat().st_size, "imageSha256": expected_hash, "databaseTables": len(manifest["database"]["tables"]), "fileAndDirectoryEntries": len([item for item in manifest["entries"] if item["path"].startswith("site/")]), "uploadedChunks": count, "maximumChunkBytes": chunk_bytes, "downloadSha256MatchesCreatedImage": True, "inspectedManifestMatchesCreatedManifest": True, "mutatedBinaryUtf8NullDecimalFixtureRowsRecovered": True, "mutatedRootHiddenAndMediaFilesRecovered": True, "wpConfigPreserved": True, "managerPreserved": any("dualscan-cloud-os-image-manager" in item for item in restored["result"]["preservedPaths"]), "maintenanceGuardRemovedAfterSuccess": True, "encryptedRollbackImageCreated": restored["result"]["rollbackImage"].endswith(".dualscan")}
    report['runtime'] += ' with default sealed deployment'
    report['sealedAdminAndRestRouteCases'] = sealed_cases
    report['sealedGuardOverridesCapturedAuthoringFlag'] = True
    report['logoutSetCookieHeaderCount'] = len(logout_cookie_headers)
    report['threeSequentialDeploymentConfirmations'] = approval_cases
    report['accountRemovalPolicy'] = account_policy_proof
    report['redirectToPublicHomepage'] = restored['result']['redirectUrl']
    report['loggedOutAfterDeployment'] = True
    Path(sys.argv[1]).write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
