// changelog.js — changelog.html only. Fetches changelog.md, parses a small
// markdown subset, renders the article + TOC, and drives the active-version
// highlight with a plain scroll listener (CSS scroll-timelines aren't used,
// for browser-support reasons). The TOC stays in its grid column while
// tracking scroll via plain CSS `position: sticky` (changelog.css).
//
// No innerHTML anywhere in this file, on purpose: every node is built with
// createElement/textContent, so changelog.md content can never be
// interpreted as markup, no manual escaping required. The one bit of real
// markup (the download-icon SVG) is a hardcoded constant, parsed once via
// DOMParser and cloned per use rather than assigned through innerHTML.

const SOURCE = './CHANGELOG.md';
const WORDS_PER_MINUTE = 220;
const TAGS = ['Added', 'Fixed', 'Changed', 'Improved', 'Removed', 'Deprecated', 'Updated', 'Security'];
const GITHUB_REPO = (document.body.dataset.githubRepo || '').trim();

const DOWNLOAD_ICON_NODE = new DOMParser().parseFromString(
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="dl-icon" aria-hidden="true"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 20h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  'image/svg+xml'
).documentElement;

function releaseUrl(version) {
  return `https://github.com/${GITHUB_REPO}/releases/tag/${encodeURIComponent(version)}`;
}

// Confirms a release tag actually exists before the changelog ever offers a
// download link for it -- a changelog heading that doesn't exactly match a
// real GitHub tag (or was never tagged/released yet) would otherwise render
// as a dead link. Only a confirmed-absent tag (404) counts as "hide the
// link"; a network failure or rate limit is inconclusive, so it fails open.
async function releaseExists(version) {
  if (!GITHUB_REPO || !version) return false;
  try {
    const res = await fetch(
      `https://api.github.com/repos/${GITHUB_REPO}/releases/tags/${encodeURIComponent(version)}`
    );
    return res.status !== 404;
  } catch {
    return true;
  }
}

// Inline markdown subset (`code`, **bold**, [text](url)), appended directly
// as real nodes — text runs become text nodes, so nothing here is ever at
// risk of being parsed as HTML, unlike building a string and assigning it
// via innerHTML.
function appendInlineMarkdown(container, raw) {
  const pattern = /`([^`]+)`|\*\*([^*]+)\*\*|\[([^\]]+)\]\(([^)]+)\)/g;
  let last = 0;
  let m;
  while ((m = pattern.exec(raw))) {
    if (m.index > last) container.appendChild(document.createTextNode(raw.slice(last, m.index)));
    if (m[1] !== undefined) {
      const code = document.createElement('code');
      code.textContent = m[1];
      container.appendChild(code);
    } else if (m[2] !== undefined) {
      const strong = document.createElement('strong');
      strong.textContent = m[2];
      container.appendChild(strong);
    } else {
      const a = document.createElement('a');
      a.href = m[4];
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = m[3];
      container.appendChild(a);
    }
    last = pattern.lastIndex;
  }
  if (last < raw.length) container.appendChild(document.createTextNode(raw.slice(last)));
}

function leadingTag(text) {
  const m = text.match(/^([A-Za-z]+)\b/);
  return m && TAGS.includes(m[1]) ? m[1] : null;
}

// Exported (module scope) so site.js's cross-page search can parse
// changelog.md the same way without duplicating the version/date split or
// the section-N id scheme — those two have to stay in lockstep or a search
// result would link to an id this function never actually assigns.
export function parseChangelog(md) {
  const lines = md.replace(/\r\n/g, '\n').split('\n');
  let title = 'Changelog';
  const dek = [];
  const sections = [];
  let current = null;
  let seenTitle = false;

  for (const line of lines) {
    const h1 = line.match(/^#\s+(.*)/);
    const h2 = line.match(/^##\s+(.*)/);
    const item = line.match(/^[-*]\s+(.*)/);

    if (h1) {
      title = h1[1].trim();
      seenTitle = true;
      continue;
    }
    if (h2) {
      current = { heading: h2[1].trim(), items: [] };
      sections.push(current);
      continue;
    }
    if (item) {
      if (current) current.items.push(item[1].trim());
      continue;
    }
    if (!current && seenTitle && line.trim()) {
      dek.push(line.trim());
    }
  }

  const UNRELEASED_RE = /\s-\s*Unreleased\s*$/i;
  sections.forEach((s, i) => {
    if (UNRELEASED_RE.test(s.heading)) {
      s.version = s.heading.replace(UNRELEASED_RE, '').trim();
      s.date = 'Unreleased';
      s.unreleased = true;
    } else {
      // The em dash of the site's own copy, or the hyphen of the code repository's CHANGELOG.md.
      const [version, date] = s.heading.split(/\s+[—-]\s+/);
      s.version = version || s.heading;
      s.date = date || '';
      s.unreleased = false;
    }
    s.id = `section-${i + 1}`;
  });

  return { title, dek: dek.join(' '), sections };
}

function render({ title, dek, sections }, versionExists) {
  document.title = `${title} · Laravents`;
  document.getElementById('pageTitle').textContent = title;
  document.getElementById('dek').textContent = dek;

  const wordCount = sections.reduce(
    (n, s) => n + s.items.join(' ').split(/\s+/).filter(Boolean).length,
    0
  );
  const minutes = Math.max(1, Math.round(wordCount / WORDS_PER_MINUTE));
  const totalRead = document.getElementById('totalRead');
  totalRead.replaceChildren();
  const minutesEl = document.createElement('strong');
  minutesEl.textContent = String(minutes);
  totalRead.append(minutesEl, ' min read');

  const articleSections = document.getElementById('sections');
  const tocList = document.getElementById('tocList');
  const currentVersion = document.getElementById('currentVersion');
  articleSections.replaceChildren();
  tocList.replaceChildren();
  currentVersion.replaceChildren();

  sections.forEach((s) => {
    const section = document.createElement('section');
    section.className = 'sec';
    section.id = s.id;
    section.dataset.title = s.version;

    const h2 = document.createElement('h2');
    h2.textContent = s.version;
    const anchor = document.createElement('a');
    anchor.className = 'heading-anchor';
    anchor.href = `#${s.id}`;
    anchor.textContent = '#';
    anchor.setAttribute('aria-label', `Permalink to version ${s.version}`);
    h2.appendChild(anchor);
    section.appendChild(h2);

    if (s.date) {
      const date = document.createElement('p');
      date.className = 'date';
      date.textContent = s.date;
      section.appendChild(date);
    }

    const ul = document.createElement('ul');
    ul.className = 'changelog-entry-list';
    s.items.forEach((raw) => {
      const tag = leadingTag(raw);
      const rest = tag ? raw.slice(tag.length).replace(/^:?\s*/, '') : raw;
      const li = document.createElement('li');
      if (tag) {
        const tagSpan = document.createElement('span');
        tagSpan.className = `tag tag-${tag.toLowerCase()}`;
        tagSpan.textContent = tag;
        li.appendChild(tagSpan);
        li.appendChild(document.createTextNode(' '));
      }
      appendInlineMarkdown(li, rest);
      ul.appendChild(li);
    });
    section.appendChild(ul);
    articleSections.appendChild(section);

    // The sticky-positioned TOC backgrounds the active <a> itself directly
    // (changelog.css .changelog-toc-list li.active a), no separate
    // highlight node needed.
    const tocLi = document.createElement('li');
    const a = document.createElement('a');
    a.href = `#${s.id}`;
    a.textContent = s.version;
    tocLi.appendChild(a);
    tocList.appendChild(tocLi);
  });

  setupScrollSpy(sections, tocList, currentVersion, versionExists);
}

function setupScrollSpy(sections, tocList, currentVersion, versionExists) {
  const tocItems = Array.from(tocList.children);
  const sectionEls = sections.map((s) => document.getElementById(s.id));

  let activeIndex = -1;
  function setActive(index) {
    if (index === activeIndex) return;
    activeIndex = index;
    tocItems.forEach((li, i) => li.classList.toggle('active', i === index));

    const section = sections[index];
    const version = section ? section.version : '';
    currentVersion.replaceChildren();
    if (GITHUB_REPO && version && !section?.unreleased && versionExists.get(version)) {
      currentVersion.href = releaseUrl(version);
      currentVersion.appendChild(document.createTextNode(version));
      currentVersion.appendChild(DOWNLOAD_ICON_NODE.cloneNode(true));
    } else {
      currentVersion.removeAttribute('href');
      currentVersion.textContent = version;
    }
  }

  let suppressUntil = 0;
  let ticking = false;
  function update() {
    ticking = false;
    if (Date.now() < suppressUntil) return;

    const atBottom = window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2;
    if (atBottom) {
      setActive(sections.length - 1);
      return;
    }

    const triggerY = window.scrollY + 96;
    let index = 0;
    sectionEls.forEach((el, i) => {
      if (el.offsetTop <= triggerY) index = i;
    });
    setActive(index);
  }

  window.addEventListener(
    'scroll',
    () => {
      if (!ticking) {
        ticking = true;
        requestAnimationFrame(update);
      }
    },
    { passive: true }
  );
  window.addEventListener('resize', update, { passive: true });

  tocItems.forEach((li, i) => {
    const a = li.querySelector('a');
    a.addEventListener('click', () => {
      setActive(i);
      suppressUntil = Date.now() + 700;
    });
  });

  update();
}

export async function initChangelog() {
  const target = document.getElementById('sections');
  try {
    const res = await fetch(SOURCE, { cache: 'no-store' });
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
    const parsed = parseChangelog(await res.text());
    const versionExists = new Map();
    await Promise.all(
      [...new Set(parsed.sections.filter((s) => !s.unreleased).map((s) => s.version))].map(
        async (v) => {
          versionExists.set(v, await releaseExists(v));
        }
      )
    );
    render(parsed, versionExists);
    // A #section-N in the URL (a bookmarked link, or a cross-page search
    // result from site.js) names a target that doesn't exist yet at the
    // point the browser normally does its own load-time hash scroll — the
    // sections above are only just now being created. Finish what the
    // browser would have done itself if the content had been there in time.
    if (location.hash) {
      const hashTarget = document.querySelector(location.hash);
      if (hashTarget) hashTarget.scrollIntoView({ block: 'start' });
    }
  } catch (err) {
    const p = document.createElement('p');
    p.className = 'changelog-loading';
    p.textContent = `Could not load CHANGELOG.md (${err.message || err}). If you opened this file directly, serve it over HTTP instead of file:// — e.g. "php -S localhost:8000".`;
    target.replaceChildren(p);
  }
}

initChangelog();
