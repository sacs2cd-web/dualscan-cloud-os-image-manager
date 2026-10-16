import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

// Execute the production request function, without copying its URL construction.
const asset = new URL('../../assets/manager.js', import.meta.url);
const source = fs.readFileSync(asset, 'utf8');
const start = source.indexOf('  async function request(');
const end = source.indexOf('\n  async function run(', start);
assert(start >= 0 && end > start, 'Cannot locate the production request function.');
const functionSource = source.slice(start, end);
const cases = [];
for (const [name, api, path, expectedPath, expectedRoute] of [
  ['plain-root', 'https://fixture.invalid/?rest_route=/dualscan-cloud/v1/', 'images', '/', '/dualscan-cloud/v1/images'],
  ['plain-index-subdirectory', 'https://fixture.invalid/blog/index.php?rest_route=/dualscan-cloud/v1/&lang=de', 'jobs/abc/run', '/blog/index.php', '/dualscan-cloud/v1/jobs/abc/run'],
  ['pretty-root', 'https://fixture.invalid/wp-json/dualscan-cloud/v1/', 'images', '/wp-json/dualscan-cloud/v1/images', null],
  ['pretty-subdirectory', 'https://fixture.invalid/blog/wp-json/dualscan-cloud/v1/?lang=de', 'imports/abc/chunks', '/blog/wp-json/dualscan-cloud/v1/imports/abc/chunks', null],
]) {
  let observed;
  const context = vm.createContext({URL, config: {api, nonce: 'synthetic-session-nonce'}, fetch: async (url, options) => {
    observed = {url: new URL(url), options};
    return {ok: true, json: async () => ({images: []})};
  }});
  vm.runInContext(functionSource, context);
  await vm.runInContext(`request(${JSON.stringify(path)})`, context);
  assert.equal(observed.url.origin, 'https://fixture.invalid');
  assert.equal(observed.url.pathname, expectedPath);
  assert.equal(observed.url.searchParams.get('rest_route'), expectedRoute);
  if (api.includes('lang=de')) assert.equal(observed.url.searchParams.get('lang'), 'de');
  assert.equal(observed.options.credentials, 'same-origin');
  assert.equal(observed.options.headers['X-WP-Nonce'], 'synthetic-session-nonce');
  cases.push({name, passed: true, path: observed.url.pathname, restRoute: expectedRoute});
}
const context = vm.createContext({URL, config: {api: 'https://fixture.invalid/?rest_route=/dualscan-cloud/v1/', nonce: 'synthetic'}, fetch: async () => ({ok: false, status: 503, json: async () => { throw new SyntaxError('HTML maintenance page'); }})});
vm.runInContext(functionSource, context);
await assert.rejects(vm.runInContext('request("images")', context), /maintenance mode/);
cases.push({name: 'non-json-maintenance-response', passed: true});
const report = {passed: true, testedProductionAsset: 'assets/manager.js', productionAssetSha256: crypto.createHash('sha256').update(source).digest('hex'), method: 'Executed actual production request function in Node VM with observed fetch URLs', cases};
fs.writeFileSync(process.argv[2] ?? new URL('../../verification/integration-manager-endpoints.json', import.meta.url), JSON.stringify(report, null, 2) + '\n');
process.stdout.write(JSON.stringify({passed: true, cases: cases.length}) + '\n');
