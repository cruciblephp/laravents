// check-site.mjs - load every page the way a reader does and fail on what a
// reader would hit: a script error, a request that 404s, a same-site link to
// a file that is not here, or a docs page that could not load its Markdown.
//
// A browser rather than an HTML validator because most of the site is built at
// view time: the changelog renders CHANGELOG.md and docs.html renders the
// repository's own Markdown, both served beside the pages.
//
// Run from the repository root:
//      npm install --no-save playwright@1.62.1 && npx playwright install chromium
//      node scripts/check-site.mjs

import { createServer } from 'node:http';
import { createReadStream, existsSync, statSync } from 'node:fs';
import { extname, join, normalize, resolve } from 'node:path';
import { chromium } from 'playwright';

const ROOT = resolve('.');
const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.md': 'text/markdown; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json',
};

// The documents docs.js lists, in its order. Kept here rather than parsed out
// of the script: if the two ever disagree, a document the site links to is
// one this check never loaded, and the check says so below.
const DOCS = [
  'README', 'docs/README', 'docs/installation', 'docs/modules', 'docs/commands-and-queries', 'docs/events',
  'docs/interceptors', 'docs/metadata-and-actor', 'docs/outbox', 'docs/http-gateway', 'docs/testing', 'docs/console',
  'docs/verification-and-docs', 'docs/reference', 'docs/laravents/README', 'docs/laravents/Band',
  'docs/laravents/Press', 'docs/laravents/FanClub', 'CONTRIBUTING', 'SECURITY',
];
const PAGES = ['index.html', 'changelog.html', ...DOCS.map((doc) => `docs.html#/${doc}`)];

// Hosts the page may reach that are not this site. Google Translate loads
// only when a reader picks a language, which this check never does.
const IGNORED_HOSTS = new Set(['translate.google.com', 'translate.googleapis.com']);

// The changelog asks GitHub whether each release exists before it offers a
// download link, and hides the link on a 404 (assets/changelog.js,
// releaseExists). A 404 there is the page working, for a release that is
// tagged but not yet published — not a broken page. Only that answer, from
// that endpoint, is excused.
const RELEASE_PROBE = /^https:\/\/api\.github\.com\/repos\/[^/]+\/[^/]+\/releases\/tags\//;
const expectedProbe = (url, status) => status === 404 && RELEASE_PROBE.test(url);

const server = createServer((req, res) => {
  const requested = decodeURIComponent((req.url ?? '/').split('?')[0]);
  const path = join(ROOT, normalize(requested === '/' ? '/index.html' : requested));
  if (!path.startsWith(ROOT) || !existsSync(path) || !statSync(path).isFile()) {
    res.writeHead(404).end();
    return;
  }
  res.writeHead(200, { 'content-type': TYPES[extname(path)] ?? 'application/octet-stream' });
  createReadStream(path).pipe(res);
});
await new Promise((ready) => server.listen(0, '127.0.0.1', ready));
const base = `http://127.0.0.1:${server.address().port}/`;

const problems = [];
const links = new Set();
const browser = await chromium.launch();
const context = await browser.newContext();

for (const page of PAGES) {
  const tab = await context.newPage();
  const report = (what) => problems.push(`${page}: ${what}`);
  tab.on('pageerror', (error) => report(`script error: ${error.message}`));
  tab.on('console', (message) => {
    if (message.type() !== 'error') return;
    // The browser logs the release probe's 404 too; its location names the URL.
    if (message.text().includes('status of 404') && RELEASE_PROBE.test(message.location().url ?? '')) return;
    report(`console error: ${message.text()}`);
  });
  tab.on('requestfailed', (request) => {
    if (!IGNORED_HOSTS.has(new URL(request.url()).hostname)) report(`request failed: ${request.url()}`);
  });
  tab.on('response', (response) => {
    if (response.status() >= 400 && !expectedProbe(response.url(), response.status())) {
      report(`HTTP ${response.status()}: ${response.url()}`);
    }
  });

  await tab.goto(base + page, { waitUntil: 'networkidle' });

  if (page.startsWith('docs.html')) {
    // docs.js removes aria-busy once a document has rendered, success or not.
    await tab.waitForFunction(() => !document.getElementById('doc').hasAttribute('aria-busy'), null, { timeout: 15000 });
    const state = await tab.evaluate(() => ({
      failed: document.querySelector('#doc .loading')?.textContent ?? null,
      headings: document.querySelectorAll('#doc h1, #doc h2').length,
    }));
    if (state.failed) report(state.failed);
    else if (state.headings === 0) report('rendered no headings');
  }

  for (const href of await tab.evaluate(() => [...document.querySelectorAll('a[href], link[href], script[src], img[src]')].map((node) => node.href || node.src))) {
    links.add(href);
  }
  await tab.close();
}

// Every same-site file a page points at must exist. Routes inside docs.html
// (#/HELP) are the router's, not files, and are covered by loading them above.
for (const href of links) {
  const url = new URL(href);
  if (url.origin !== new URL(base).origin) continue;
  const path = url.pathname === '/' ? '/index.html' : url.pathname;
  if (!existsSync(join(ROOT, decodeURIComponent(path)))) problems.push(`broken link: ${path}`);
  if (path === '/docs.html' && url.hash.startsWith('#/')) {
    const doc = url.hash.slice(2).split('#')[0];
    if (doc && !DOCS.includes(doc)) problems.push(`link to a document the docs page does not list: ${url.hash}`);
  }
}

await browser.close();
server.close();

if (problems.length) {
  console.error(`${problems.length} problem(s):`);
  for (const problem of problems) console.error(`  ${problem}`);
  process.exit(1);
}
console.log(`${PAGES.length} pages loaded, ${links.size} links checked, nothing broken.`);
