(() => {
  'use strict';
  const root = document.getElementById('dualscan-cloud');
  if (!root || !window.DualscanCloud) return;
  const config = window.DualscanCloud;
  const byId = id => document.getElementById(id);
  let busy = false;
  let reviewed = null;
  const phases = {
    database: 'Saving your database', files: 'Collecting your site files',
    encrypting: 'Protecting your image', validating: 'Verifying the complete image',
    'rollback-snapshot': 'Saving a recovery point', restoring: 'Restoring your site',
    complete: 'Image verified', uploading: 'Uploading your image',
  };
  const bytes = value => {
    if (!Number.isFinite(Number(value))) return '—';
    const n = Number(value);
    if (n < 1024) return `${n} B`;
    const i = Math.min(Math.floor(Math.log(n) / Math.log(1024)), 4);
    return `${(n / (1024 ** i)).toFixed(i > 1 ? 1 : 0)} ${['B', 'KiB', 'MiB', 'GiB', 'TiB'][i]}`;
  };
  function notice(message, kind = 'info') {
    const box = byId('dc-notice');
    box.hidden = false;
    box.dataset.kind = kind;
    box.textContent = message;
  }
  function updateRestoreButton() {
    byId('dc-confirm-first').disabled = busy || !reviewed || !byId('dc-restore-quiet').checked;
    byId('dc-confirm-second').disabled = busy || !reviewed || reviewed.plan.step !== 1 || !byId('dc-ip-accepted').checked;
    byId('dc-restore').disabled = busy || !reviewed || reviewed.plan.step !== 2 || !['dc-eula-accepted','dc-claims-accepted','dc-admin-waived'].every(id => byId(id).checked);
  }
  function setBusy(value) {
    busy = value;
    root.setAttribute('aria-busy', String(value));
    root.querySelectorAll('button,input').forEach(element => { element.disabled = value; });
    updateRestoreButton();
  }
  function progress(phase, done = 0, total = 0) {
    byId('dc-progress').hidden = false;
    byId('dc-phase').textContent = phases[phase] || phase || 'Working on your image';
    const percent = total > 0 ? Math.min(100, Math.floor((done / total) * 100)) : 0;
    byId('dc-percent').textContent = total > 0 ? `${percent}%` : '';
    byId('dc-progress-bar').value = percent;
  }
  async function request(path, {method = 'GET', data, body, headers = {}} = {}) {
    const endpoint = new URL(config.api);
    // WordPress also supports plain permalinks, where rest_url uses a query.
    if (endpoint.searchParams.has('rest_route')) {
      endpoint.searchParams.set('rest_route', `${endpoint.searchParams.get('rest_route').replace(/\/?$/, '/')}${path}`);
    } else { endpoint.pathname = `${endpoint.pathname.replace(/\/?$/, '/')}${path}`; }
    const response = await fetch(endpoint, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: {'X-WP-Nonce': config.nonce, ...(data ? {'Content-Type': 'application/json'} : {}), ...headers},
      body: data ? JSON.stringify(data) : body,
    });
    let result;
    try { result = await response.json(); } catch (_) {
      throw new Error(response.status === 503 ? 'The site is in maintenance mode.' : 'The server ended the request. Check the operation status before trying again.');
    }
    if (!response.ok) throw new Error(result.message || `The request could not be completed (${response.status}).`);
    return result;
  }
  async function run(type, params) {
    const job = await request('jobs', {method: 'POST', data: {type, ...params}});
    let finished = false;
    let timer;
    const poll = async () => {
      if (finished) return;
      try {
        const state = await request(`jobs/${job.id}`);
        if (state.progress) progress(state.progress.phase, state.progress.done, state.progress.total);
      } catch (_) { /* The restoring site's temporary maintenance page also covers polls. */ }
      if (!finished) timer = setTimeout(poll, 1500);
    };
    timer = setTimeout(poll, 1000);
    try {
      const state = await request(`jobs/${job.id}/run`, {method: 'POST', data: {}});
      if (state.state !== 'complete') throw new Error(state.error || 'This operation did not complete.');
      return {result: state.result, id: job.id};
    } catch (error) {
      // Never automatically repeat a restore after a broken HTTP connection.
      throw new Error(`${error.message} Operation: ${job.id}.`);
    } finally { finished = true; clearTimeout(timer); }
  }
  async function refreshImages() {
    const {images} = await request('images');
    const list = byId('dc-image-list');
    if (!images.length) return;
    list.replaceChildren();
    for (const image of images) {
      const row = document.createElement('article'); row.className = 'dc-image-row';
      const symbol = document.createElement('span'); symbol.className = 'dc-file-symbol'; symbol.textContent = '◇';
      const detail = document.createElement('div'); detail.className = 'dc-image-detail';
      const title = document.createElement('strong'); title.textContent = image.title;
      const meta = document.createElement('span'); meta.textContent = `${new Date(image.createdAt).toLocaleString()} · ${bytes(image.bytes)} · .dualscan`;
      detail.append(title, meta);
      const badge = document.createElement('span'); badge.className = 'dc-verified'; badge.textContent = 'Verified';
      const download = document.createElement('a'); download.className = 'dc-download'; download.textContent = 'Download ↓'; download.href = image.downloadUrl;
      row.append(symbol, detail, badge, download); list.append(row);
    }
  }
  byId('dc-create').addEventListener('submit', async event => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); progress('database'); notice('Creating your image. Keep this window open.');
    try {
      const {result} = await run('create', {title: String(form.get('title') || ''), quiesced: form.get('quiet') === 'on'});
      progress('complete', 1, 1);
      const count = result.manifest?.entries?.filter(entry => entry.kind === 'file' && entry.path.startsWith('site/')).length;
      notice(`Your image is ready${count ? `: ${count.toLocaleString()} site files verified` : ''}. Download it below. The server manages encryption automatically.`, 'success');
      await refreshImages();
    } catch (error) { notice(error.message, 'error'); }
    finally {
      setBusy(false);
    }
  });
  byId('dc-image').addEventListener('change', event => {
    reviewed = null; byId('dc-review').hidden = true; updateRestoreButton();
    byId('dc-file-label').textContent = event.target.files[0]?.name || 'Choose a .dualscan image';
  });
  byId('dc-inspect').addEventListener('submit', async event => {
    event.preventDefault();
    const file = byId('dc-image').files[0];
    if (!file || !file.name.toLowerCase().endsWith('.dualscan')) { notice('Choose a .dualscan image.', 'error'); return; }
    reviewed = null; byId('dc-review').hidden = true; setBusy(true); progress('uploading', 0, file.size);
    try {
      const upload = await request('imports', {method: 'POST', data: {name: file.name, bytes: file.size}});
      for (let offset = 0; offset < file.size; offset += upload.chunkBytes) {
        const chunk = file.slice(offset, Math.min(offset + upload.chunkBytes, file.size));
        const state = await request(`imports/${upload.id}/chunks`, {method: 'POST', body: chunk, headers: {'Content-Type': 'application/octet-stream', 'X-Dualscan-Offset': String(offset)}});
        progress('uploading', state.receivedBytes, file.size);
      }
      progress('validating');
      const {result: manifest, id: inspectionId} = await run('inspect', {importId: upload.id});
      const plan = await request('deployments', {method: 'POST', data: {importId: upload.id, inspectionId}});
      const details = byId('dc-review-details'); details.replaceChildren();
      for (const [name, value] of [
        ['Source', manifest.source?.siteUrl || 'Recorded site'],
        ['Created', manifest.createdAt ? new Date(manifest.createdAt).toLocaleString() : 'Recorded image'],
        ['Site files', String(manifest.entries?.filter(entry => entry.kind === 'file' && entry.path.startsWith('site/')).length || 0)],
        ['Database tables', String(manifest.database?.tables?.length || 0)],
      ]) {
        const term = document.createElement('dt'); term.textContent = name;
        const definition = document.createElement('dd'); definition.textContent = value;
        details.append(term, definition);
      }
      reviewed = {importId: upload.id, plan};
      byId('dc-review').hidden = false;
      for (const id of ['dc-restore-quiet','dc-ip-accepted','dc-eula-accepted','dc-claims-accepted','dc-admin-waived']) byId(id).checked = false;
      byId('dc-confirm-1').hidden = false; byId('dc-confirm-2').hidden = true; byId('dc-confirm-3').hidden = true;
      byId('dc-terms').textContent = plan.termsText;
      byId('dc-terms-version').textContent = `Deployment agreement · ${plan.termsVersion}`;
      progress('complete', 1, 1);
      notice('The image is verified. Complete all three confirmations before deploying the public application.', 'success');
    } catch (error) { notice(error.message, 'error'); }
    finally { setBusy(false); }
  });
  for (const id of ['dc-restore-quiet','dc-ip-accepted','dc-eula-accepted','dc-claims-accepted','dc-admin-waived']) byId(id).addEventListener('change', updateRestoreButton);
  async function confirmStep(step) {
    if (!reviewed || busy) return;
    setBusy(true);
    try {
      reviewed.plan = await request(`deployments/${reviewed.plan.id}/confirm`, {method: 'POST', data: {step, accepted: true}});
      byId(`dc-confirm-${step}`).hidden = true;
      byId(`dc-confirm-${step + 1}`).hidden = false;
    } catch (error) { notice(error.message, 'error'); }
    finally { setBusy(false); }
  }
  byId('dc-confirm-first').addEventListener('click', () => { if (byId('dc-restore-quiet').checked) confirmStep(1); });
  byId('dc-confirm-second').addEventListener('click', () => { if (byId('dc-ip-accepted').checked) confirmStep(2); });
  byId('dc-restore').addEventListener('click', async () => {
    if (!reviewed || reviewed.plan.step !== 2 || !['dc-eula-accepted','dc-claims-accepted','dc-admin-waived'].every(id => byId(id).checked)) return;
    const selected = reviewed;
    setBusy(true); progress('validating'); notice('Restoring this site. Keep this window open until the operation completes.');
    try {
      selected.plan = await request(`deployments/${selected.plan.id}/confirm`, {method: 'POST', data: {step: 3, accepted: true, eulaAccepted: true, claimsAccepted: true, adminAccessWaived: true, termsSha256: selected.plan.termsSha256}});
      const {result} = await run('restore', {importId: selected.importId, deploymentId: selected.plan.id, confirmation: 'DEPLOY INSTANCE', quiesced: true});
      reviewed = null; byId('dc-review').hidden = true; progress('complete', 1, 1);
      notice('Restore completed. WordPress administrator accounts have been removed. Your public application is running.', 'success');
      byId('dc-deployed').hidden = false;
      root.querySelector('.dc-columns').hidden = true;
      root.querySelector('.dc-library').hidden = true;
      window.location.replace(result.redirectUrl || config.siteUrl);
    } catch (error) { notice(error.message, 'error'); }
    finally { setBusy(false); }
  });
  byId('dc-refresh').addEventListener('click', () => refreshImages().catch(error => notice(error.message, 'error')));
  window.addEventListener('beforeunload', event => { if (busy) { event.preventDefault(); event.returnValue = ''; } });
  refreshImages().catch(error => notice(error.message, 'error'));
})();
