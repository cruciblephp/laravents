(() => {
  'use strict';

  document.documentElement.classList.add('js');
  // FOUC guard: site.js is a plain (non-async/defer) script at the very
  // end of body, so by the time it runs the DOM above it is fully parsed
  // — reveal immediately rather than waiting on a DOMContentLoaded event
  // that has, practically speaking, already fired.
  document.body.classList.add('loaded');

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Several independent modules below each need their own "window resize"
  // handler (scrollbar-width var, nav-toggle layout check, carousel overflow
  // check). A raw `resize` listener can fire many times per frame during an
  // active window drag; this collapses each module's handler to at most once
  // per animation frame, same idea as the scroll handler further down.
  function onResizeThrottled(fn) {
    let ticking = false;
    window.addEventListener('resize', () => {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(() => {
        ticking = false;
        fn();
      });
    });
  }

  // Generic ARIA toggle-pair: flips aria-expanded on the button and
  // aria-hidden on the target, wired to a click. Extra per-toggle behavior
  // (inert management, closing on outside click, etc.) layers on top via
  // the optional onToggle callback rather than needing its own copy of the
  // click-handling/state-tracking boilerplate. Returns { setOpen, isOpen }
  // so callers can also drive it programmatically (e.g. on resize).
  function createToggle(toggleEl, targetEl, onToggle) {
    if (!toggleEl || !targetEl) return null;
    function setOpen(open) {
      toggleEl.setAttribute('aria-expanded', String(open));
      targetEl.setAttribute('aria-hidden', String(!open));
      if (onToggle) onToggle(open);
    }
    function isOpen() {
      return toggleEl.getAttribute('aria-expanded') === 'true';
    }
    toggleEl.addEventListener('click', () => setOpen(!isOpen()));
    return { setOpen, isOpen };
  }

  // <meta name="x" content="key=value|key=value"> parsed into a plain
  // object — lets a page declare small config blobs (a feature flag, a
  // version pin) without an inline <script> block. No current caller on
  // this page; available for pages built on this template that need one.
  function parseMetaConfig(name, delimiter = '|', pairDelimiter = '=') {
    const metaTag = document.querySelector(`meta[name="${name}"]`);
    if (!metaTag || !metaTag.content) return null;
    return Object.fromEntries(
      metaTag.content.split(delimiter).map((pair) => pair.split(pairDelimiter))
    );
  }

  // A transitioning property whose value depends on a responsive
  // breakpoint (e.g. .nav .links' max-height, mobile-only) can animate
  // janky mid-drag as an active window resize repeatedly crosses that
  // breakpoint. Strip the transition while actively resizing, restore
  // shortly after it settles.
  function disableTransitionDuringResize(el, className = 'resizing') {
    if (!el) return;
    let resizeTimer;
    window.addEventListener('resize', () => {
      el.classList.add(className);
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => el.classList.remove(className), 100);
    });
  }

  /* ---------- Scrollbar-width-safe full-bleed breakout ---------- */
  /* 100vw is defined as the viewport width INCLUDING a classic (non-overlay)
     scrollbar's gutter, while actual visible content width doesn't include
     it — so anything sized off raw 100vw (the carousel breakout) overshoots
     past the real right edge on platforms with a reserved-space scrollbar
     (Linux/Windows Chromium+Firefox), and that sliver gets clipped by
     overflow-x:clip on html/body — including a few px of an inset focus
     ring that's supposed to sit safely inside the box. */
  (function setScrollbarWidthVar() {
    function update() {
      const w = window.innerWidth - document.documentElement.clientWidth;
      document.documentElement.style.setProperty('--scrollbar-w', `${w}px`);
    }
    update();
    onResizeThrottled(update);
  })();

  /* ---------- Print masthead: title/URL/date-time ---------- */
  /* Populated right at print time (not on load, and not on a ticking
     interval) — beforeprint fires exactly when printing is triggered
     (Ctrl+P, File>Print, window.print()), so this is guaranteed accurate
     to the second at the actual moment of printing, with zero background
     work while the tab just sits open. */
  (function initPrintMeta() {
    const titleEl = document.getElementById('print-meta-title');
    const urlEl = document.getElementById('print-meta-url');
    const dateEl = document.getElementById('print-meta-date');
    if (!titleEl || !urlEl || !dateEl) return;

    // ISO 8601 (YYYY-MM-DD HH:MM:SS) instead of toLocaleString() — that's
    // locale-ambiguous (08/02 reads as Aug 2 in the US, Feb 8 almost
    // everywhere else), which is exactly the wrong property for a printed
    // date. Zone abbreviation (CEST, EST, ...) appended via Intl, since
    // Date has no built-in way to name it (only a raw UTC offset).
    const pad = (n) => String(n).padStart(2, '0');
    function formatPrintDate(d) {
      const iso = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} `
        + `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
      const tz = new Intl.DateTimeFormat('en-US', { timeZoneName: 'short' })
        .formatToParts(d)
        .find((p) => p.type === 'timeZoneName');
      return tz ? `${iso} ${tz.value}` : iso;
    }

    window.addEventListener('beforeprint', () => {
      titleEl.textContent = document.title;
      urlEl.textContent = location.href;
      dateEl.textContent = formatPrintDate(new Date());
    });
  })();

  /* ---------- Scroll-reveal ---------- */
  const revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealEls.length) {
    const revealIo = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('in-view');
            revealIo.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
    );
    revealEls.forEach((el) => revealIo.observe(el));
  } else {
    revealEls.forEach((el) => el.classList.add('in-view'));
  }

  /* ---------- Nav: subtle at the very top, fuller bar once scrolled ---------- */
  (function initNavScrollState() {
    const nav = document.querySelector('.nav');
    if (!nav) return;
    let ticking = false;
    function apply() {
      nav.classList.toggle('scrolled', window.scrollY > 4);
      ticking = false;
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(apply);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    apply();
  })();

  /* ---------- Hamburger menu (mobile) ---------- */
  (function initNavToggle() {
    const toggle = document.getElementById('nav-toggle');
    const links = document.getElementById('nav-links');
    if (!toggle || !links) return;

    const nav = createToggle(toggle, links, (open) => {
      links.classList.toggle('open', open);
      // Below the hamburger breakpoint, a closed menu is only hidden via
      // max-height/overflow — its links stay in the tab order, so keyboard
      // users land on invisible, clipped-outline links mid-Tab. `inert`
      // pulls them out of focus/AT entirely while closed. Desktop never
      // collapses the menu, so it must never end up inert there.
      const isMobileLayout = getComputedStyle(toggle).display !== 'none';
      links.inert = isMobileLayout && !open;
    });

    onResizeThrottled(() => nav.setOpen(nav.isOpen()));
    disableTransitionDuringResize(links);
    nav.setOpen(false);

    links.addEventListener('click', (e) => {
      if (e.target.closest('a')) nav.setOpen(false);
    });

    document.addEventListener('click', (e) => {
      if (!nav.isOpen()) return;
      if (e.target === toggle || toggle.contains(e.target)) return;
      if (links.contains(e.target)) return;
      nav.setOpen(false);
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && nav.isOpen()) {
        nav.setOpen(false);
        toggle.focus();
      }
    });
  })();

  /* ---------- Search / jump to (command palette) ---------- */
  /* Harvests h1/h2 (live DOM, re-read on every keystroke) and jumps to the
     nearest ancestor that carries an id (header#top, section#compare, ...)
     rather than assigning the heading itself a fresh one — that ancestor
     already has scroll-margin-top set for the sticky nav. Plain
     case-insensitive substring filter, no fuzzy matching. */
  (function initSearch() {
    const trigger = document.getElementById('search-trigger');
    const modal = document.getElementById('search-modal');
    const input = document.getElementById('search-input');
    const resultsEl = document.getElementById('search-results');
    if (!trigger || !modal || !input || !resultsEl) return;

    // Two-page site: whichever of these isn't the current page is "the
    // other page" to fall back to on a local search miss. Anything that
    // isn't literally changelog.html is treated as index.html, so this
    // still resolves sanely from "/" (a static server's implicit root).
    const CURRENT_PAGE = location.pathname.split('/').pop() === 'changelog.html' ? 'changelog.html' : 'index.html';
    const OTHER_PAGE = CURRENT_PAGE === 'changelog.html' ? 'index.html' : 'changelog.html';
    const OTHER_PAGE_LABEL = OTHER_PAGE === 'changelog.html' ? 'Changelog' : 'Home';

    // Mirrors assets/locale.css's :lang()/:has() reveal rule in JS instead
    // of leaning on rendering, because harvestHeadings also runs against a
    // fetched-but-never-attached other-page Document (below), which has no
    // layout — innerText there falls back to textContent per spec, which
    // glues every [data-locale] span together since nothing is actually
    // hidden without layout to hide it with. Explicitly picking the span
    // for the current locale (or [data-i18n-default]) works the same
    // whether el is live or detached, so both callers can share it.
    const localizedText = (el, lang) => {
      const override = el.querySelector(`:scope > [data-locale="${lang}"]`);
      if (override) return override.textContent.trim();
      const fallback = el.querySelector(':scope > [data-i18n-default]');
      if (fallback) return fallback.textContent.trim();
      return (el.innerText ?? el.textContent).trim();
    };

    // Shared by local and cross-page (index.html side) harvesting: given a
    // Document (the live one, or one parsed from a fetched page), pulls
    // every h1/h2 and resolves it to the nearest ancestor that actually
    // carries an id (a section, a header) — that ancestor already has
    // scroll-margin-top set for the sticky nav, so a heading never needs
    // one assigned just for this.
    const harvestHeadings = (doc) =>
      Array.from(doc.querySelectorAll('h1, h2'))
        .filter((h) => !h.closest('.search-modal'))
        .map((h) => {
          const target = h.closest('[id]');
          return target ? { text: localizedText(h, document.documentElement.lang), id: target.id } : null;
        })
        .filter(Boolean);

    // Changelog entry bullets aren't headings, so a query like "compat-check" (a
    // specific release note) needs its own harvest — text from the <li>,
    // id from its ancestor section (changelog.js gives every version
    // section that id; there's nothing more specific inside it to jump
    // to). No-op on index.html, which has no .changelog-entry-list. Always
    // called against the live document only (changelog.html's version
    // entries are never fetched raw — the cross-page path parses
    // changelog.md directly instead, see fetchOtherPageItems below) — li
    // bullets never carry [data-locale] children either (changelog stays
    // Google-Translate-only by design), so localizedText's fallback to
    // innerText/textContent is what actually runs here; routed through
    // the same helper anyway so both harvesters stay consistent.
    const harvestEntries = (doc) =>
      Array.from(doc.querySelectorAll('.changelog-entry-list li'))
        .map((li) => {
          const target = li.closest('[id]');
          return target ? { text: localizedText(li, document.documentElement.lang), id: target.id } : null;
        })
        .filter(Boolean);

    const MAX_RESULTS = 40;

    // The other page's real content, fetched lazily on a local-search miss
    // and cached after that (a promise, not just the resolved array, so
    // concurrent misses while the first fetch is still in flight share it
    // instead of firing a second request).
    let otherPageItemsPromise = null;

    const fetchOtherPageItems = () => {
      if (otherPageItemsPromise) return otherPageItemsPromise;
      otherPageItemsPromise = (async () => {
        try {
          if (OTHER_PAGE === 'changelog.html') {
            // changelog.html's real version headings AND entry bullets only
            // exist once its own JS renders them from changelog.md —
            // fetching the HTML shell would see nothing but empty
            // containers. Parsing changelog.md's own "## VERSION — DATE"
            // and "- item" lines directly, and assigning ids with the exact
            // same sequential section-N scheme changelog.js's parseChangelog
            // uses when it renders, keeps the two in lockstep without
            // sharing a module (this file has no <script type="module">, so
            // it can't import parseChangelog directly, even though
            // changelog.js exports it specifically for this — see that
            // file's own comment on the export).
            // Bullet text runs through the same inline-markdown subset
            // appendInlineMarkdown (changelog.js) turns into real nodes —
            // matched here only to strip the syntax markers back out
            // (`code`, **bold**, [text](url) -> code/bold/text), since a
            // query like "mutate" shouldn't have to also match a literal
            // backtick to find `crucible mutate`. Duplicated from that same
            // regex for the same reason as the rest of this branch: no
            // shared module to pull it from instead.
            const stripMarkdown = (raw) =>
              raw.replace(
                /`([^`]+)`|\*\*([^*]+)\*\*|\[([^\]]+)\]\(([^)]+)\)/g,
                (whole, code, bold, linkText) => code ?? bold ?? linkText ?? whole
              );
            const res = await fetch('CHANGELOG.md', { cache: 'no-store' });
            const lines = (await res.text()).replace(/\r\n/g, '\n').split('\n');
            const items = [];
            let n = 0;
            let currentId = null;
            lines.forEach((line) => {
              const h2 = line.match(/^##\s+(.*)/);
              const bullet = line.match(/^[-*]\s+(.*)/);
              if (h2) {
                n += 1;
                currentId = `section-${n}`;
                const heading = h2[1].trim();
                const [version] = heading.split(/\s+[—-]\s+/);
                items.push({ text: version || heading, id: currentId });
                return;
              }
              // A bullet before the first "## " heading can't belong to any
              // section changelog.js will actually render an id for.
              if (bullet && currentId) {
                items.push({ text: stripMarkdown(bullet[1].trim()), id: currentId });
              }
            });
            return items;
          }
          const res = await fetch(OTHER_PAGE, { cache: 'no-store' });
          const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
          return harvestHeadings(doc);
        } catch {
          return [];
        }
      })();
      return otherPageItemsPromise;
    };

    // Wraps the matched substring in a plain <span> (not <mark>, which
    // Chrome insists on painting with its own default yellow highlight
    // regardless of an explicit background override). textContent/
    // createElement, not innerHTML, even though every source string here
    // is same-origin page content — no reason to open an innerHTML path
    // for search-result text.
    const appendHighlighted = (container, text, query) => {
      const idx = text.toLowerCase().indexOf(query);
      if (idx === -1) {
        container.appendChild(document.createTextNode(text));
        return;
      }
      container.appendChild(document.createTextNode(text.slice(0, idx)));
      const mark = document.createElement('span');
      mark.className = 'search-match';
      mark.textContent = text.slice(idx, idx + query.length);
      container.appendChild(mark);
      container.appendChild(document.createTextNode(text.slice(idx + query.length)));
    };

    // Active option for ArrowUp/Down + Enter, tracked by index into the
    // currently rendered <a role="option"> list. Reset on every re-render
    // since the option list underneath it just changed.
    let activeIndex = -1;

    const setActive = (index) => {
      const options = Array.from(resultsEl.querySelectorAll('a[role="option"]'));
      const prev = options[activeIndex];
      if (prev) {
        prev.classList.remove('is-active');
        prev.setAttribute('aria-selected', 'false');
      }
      activeIndex = index;
      const next = options[activeIndex];
      if (!next) return;
      next.classList.add('is-active');
      next.setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', next.id);
      next.scrollIntoView({ block: 'nearest' });
    };

    const moveActive = (delta) => {
      const count = resultsEl.querySelectorAll('a[role="option"]').length;
      if (count === 0) return;
      const next = activeIndex === -1
        ? (delta > 0 ? 0 : count - 1)
        : (activeIndex + delta + count) % count;
      setActive(next);
    };

    const goTo = (id) => {
      modal.close();
      const el = document.getElementById(id);
      if (el) el.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
    };

    const renderResults = (items, query) => {
      resultsEl.replaceChildren();
      activeIndex = -1;
      input.removeAttribute('aria-activedescendant');
      if (!query) return;
      if (items.length === 0) {
        const li = document.createElement('li');
        li.className = 'search-empty';
        li.textContent = `No results for "${query}"`;
        resultsEl.appendChild(li);
        return;
      }
      items.forEach((item, i) => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        // item.page is only set on cross-page results (undefined locally) —
        // those need a real navigation (this page has no PJAX-style swap
        // yet), local ones jump in place via goTo below instead.
        a.href = item.page ? `${item.page}#${item.id}` : `#${item.id}`;
        a.id = `search-option-${i}`;
        a.setAttribute('role', 'option');
        a.setAttribute('aria-selected', 'false');
        const label = document.createElement('span');
        appendHighlighted(label, item.text, query);
        a.appendChild(label);
        if (item.page) {
          const pageTag = document.createElement('span');
          pageTag.className = 'search-result-page';
          pageTag.textContent = OTHER_PAGE_LABEL;
          a.appendChild(pageTag);
        } else {
          a.addEventListener('click', (e) => {
            e.preventDefault();
            goTo(item.id);
          });
        }
        li.appendChild(a);
        resultsEl.appendChild(li);
      });
    };

    const MIN_QUERY_LENGTH = 2;

    const runSearch = async () => {
      const query = input.value.trim().toLowerCase();
      // #search-input is the combobox; its expanded state belongs on the
      // combobox itself, not on #search-results (a plain role=listbox) —
      // true whenever there's a query, since a hint/empty/results state
      // all count as "the popup has something showing".
      input.setAttribute('aria-expanded', String(Boolean(query)));
      if (!query) {
        renderResults([], '');
        return;
      }
      if (query.length < MIN_QUERY_LENGTH) {
        resultsEl.replaceChildren();
        const li = document.createElement('li');
        li.className = 'search-hint';
        li.textContent = `Type at least ${MIN_QUERY_LENGTH} characters`;
        resultsEl.appendChild(li);
        return;
      }
      const local = [...harvestHeadings(document), ...harvestEntries(document)]
        .filter((i) => i.text.toLowerCase().includes(query));
      if (local.length > 0) {
        renderResults(local.slice(0, MAX_RESULTS), query);
        return;
      }
      const other = (await fetchOtherPageItems())
        .filter((i) => i.text.toLowerCase().includes(query))
        .map((i) => ({ ...i, page: OTHER_PAGE }));
      // The query can change while the fetch above is in flight — a stale
      // response landing after a newer keystroke would otherwise overwrite
      // whatever's now actually being typed.
      if (input.value.trim().toLowerCase() !== query) return;
      renderResults(other.slice(0, MAX_RESULTS), query);
    };

    const openSearch = () => {
      modal.showModal();
      input.value = '';
      input.setAttribute('aria-expanded', 'false');
      renderResults([], '');
      input.focus();
    };

    trigger.addEventListener('click', openSearch);

    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.close();
    });

    input.addEventListener('input', runSearch);

    // Standard listbox-popup keyboard pattern: focus stays on the input
    // (aria-activedescendant announces the active option instead), so
    // typing keeps working normally between arrow presses.
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        moveActive(1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveActive(-1);
      } else if (e.key === 'Enter' && activeIndex !== -1) {
        e.preventDefault();
        resultsEl.querySelectorAll('a[role="option"]')[activeIndex]?.click();
      }
    });

    // Bare "s" opens search from anywhere, matching GitHub's own shortcut.
    // Unmodified letter keys are never claimed by browser chrome ("/" was
    // tried first but Firefox reserves it for quick-find, and Ctrl/Cmd+K
    // collide with browser search-bar shortcuts too).
    document.addEventListener('keydown', (e) => {
      if (e.key !== 's' || e.metaKey || e.ctrlKey || e.altKey) return;
      const active = document.activeElement;
      const isTyping =
        active &&
        (active.tagName === 'INPUT' ||
          active.tagName === 'TEXTAREA' ||
          active.isContentEditable);
      if (isTyping || document.querySelector('dialog[open]')) return;
      e.preventDefault();
      openSearch();
    });
  })();

  /* ---------- Active nav link while scrolling ---------- */
  const navLinks = Array.from(document.querySelectorAll('.nav a.link[href^="#"]'));
  if ('IntersectionObserver' in window && navLinks.length) {
    const byId = new Map(navLinks.map((a) => [a.getAttribute('href').slice(1), a]));
    const sections = navLinks
      .map((a) => document.getElementById(a.getAttribute('href').slice(1)))
      .filter(Boolean);
    const navIo = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          const link = byId.get(entry.target.id);
          if (!link) return;
          navLinks.forEach((a) => {
            a.classList.remove('active');
            a.removeAttribute('aria-current');
          });
          link.classList.add('active');
          link.setAttribute('aria-current', 'location');
        });
      },
      { rootMargin: '-40% 0px -55% 0px' }
    );
    sections.forEach((s) => navIo.observe(s));
  }

  /* ---------- Reveal footer: keep .shell's bottom spacer synced to the
     fixed footer's real (zoom-adjusted) height, so it peeks out exactly
     when .shell finishes scrolling past. ---------- */
  (function initRevealFooter() {
    const shell = document.querySelector('.shell');
    const footer = document.querySelector('footer.reveal-footer');
    if (!shell || !footer) return;

    function sync() {
      shell.style.marginBottom = `${footer.getBoundingClientRect().height}px`;
    }

    if ('ResizeObserver' in window) {
      new ResizeObserver(sync).observe(footer);
    } else {
      onResizeThrottled(sync);
    }
    sync();
    window.addEventListener('load', sync);

    // footer is position:fixed and always spatially within the viewport, so
    // native scroll-into-view behavior has nothing to do here — the footer
    // links are covered (z-index) by .shell's own content on top of it
    // until the page is scrolled all the way down. Tabbing onto a footer
    // link needs to actively trigger that same page-bottom scroll, or the
    // now-focused link stays hidden under the page above it.
    const doc = document.documentElement;
    footer.addEventListener('focusin', () => {
      const atBottom = doc.scrollHeight - doc.scrollTop - doc.clientHeight < 4;
      if (!atBottom) {
        window.scrollTo({ top: doc.scrollHeight, behavior: reducedMotion ? 'auto' : 'smooth' });
      }
    });
  })();

  /* ---------- Back to top: fixed bottom-right, visible once scrolled off the top ---------- */
  (function initBackToTop() {
    const btn = document.getElementById('back-to-top');
    if (!btn) return;
    let ticking = false;
    function apply() {
      btn.classList.toggle('is-visible', window.scrollY > 400);
      ticking = false;
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(apply);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    apply();
    btn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
    });
  })();

  /* ---------- FAQ accordion (single item open at a time) ---------- */
  document.querySelectorAll('.accordion').forEach((accordion) => {
    const triggers = Array.from(accordion.querySelectorAll('.accordion-trigger'));
    triggers.forEach((trigger) => {
      trigger.addEventListener('click', () => {
        const isOpen = trigger.getAttribute('aria-expanded') === 'true';
        triggers.forEach((t) => t.setAttribute('aria-expanded', 'false'));
        trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
      });
    });
  });

  /* ---------- Use-case carousel: buttons + pointer drag-to-scroll ---------- */
  (function initCarousel() {
    const track = document.getElementById('use-case-carousel');
    const viewport = document.querySelector('.carousel-viewport');
    const prev = document.getElementById('carousel-prev');
    const next = document.getElementById('carousel-next');
    if (!track) return;

    const scrollByCard = (dir) => {
      const card = track.querySelector('.carousel-card');
      const step = card ? card.getBoundingClientRect().width + 16 : track.clientWidth * 0.8;
      track.scrollBy({ left: dir * step, behavior: reducedMotion ? 'auto' : 'smooth' });
    };
    if (prev) prev.addEventListener('click', () => scrollByCard(-1));
    if (next) next.addEventListener('click', () => scrollByCard(1));

    // Arrows are only useful once the row actually overflows — on a wide
    // enough screen every card can already be visible at once, and buttons
    // that do nothing are worse than no buttons. Re-checked on resize since
    // the breakout carousel's overflow depends entirely on viewport width.
    function updateArrows() {
      const hasOverflow = track.scrollWidth > track.clientWidth + 1;
      if (viewport) viewport.classList.toggle('has-overflow', hasOverflow);
      // .carousel-nav is display:none via CSS whenever !hasOverflow, so a
      // stale disabled value here never actually shows — reset it anyway
      // rather than leaving it stale, so the buttons' real state always
      // matches what updateArrows last computed instead of what happened
      // to be true the last time the row overflowed.
      if (!hasOverflow) {
        if (prev) prev.disabled = false;
        if (next) next.disabled = false;
        return;
      }
      const atStart = track.scrollLeft <= 1;
      const atEnd = track.scrollLeft >= track.scrollWidth - track.clientWidth - 1;
      if (prev) prev.disabled = atStart;
      if (next) next.disabled = atEnd;
    }
    track.addEventListener('scroll', updateArrows, { passive: true });
    onResizeThrottled(updateArrows);
    updateArrows();

    let isDown = false;
    let startX = 0;
    let startScroll = 0;
    let moved = false;

    track.addEventListener('pointerdown', (e) => {
      isDown = true;
      moved = false;
      startX = e.clientX;
      startScroll = track.scrollLeft;
      track.classList.add('dragging');
      track.setPointerCapture(e.pointerId);
    });
    track.addEventListener('pointermove', (e) => {
      if (!isDown) return;
      const dx = e.clientX - startX;
      if (Math.abs(dx) > 3) moved = true;
      track.scrollLeft = startScroll - dx;
    });
    const endDrag = () => {
      isDown = false;
      track.classList.remove('dragging');
    };
    track.addEventListener('pointerup', endDrag);
    track.addEventListener('pointercancel', endDrag);
    track.addEventListener('pointerleave', endDrag);
    // Suppress the click that would otherwise fire on a dragged card right after release.
    track.addEventListener(
      'click',
      (e) => {
        if (moved) {
          e.preventDefault();
          e.stopPropagation();
        }
      },
      true
    );
  })();

  /* ---------- Copy-to-clipboard on real code blocks ---------- */
  document.querySelectorAll('pre:not(.filetree-ascii):not(.demo-pre)').forEach((pre) => {
    const wrap = document.createElement('div');
    wrap.className = 'pre-wrap';
    pre.parentNode.insertBefore(wrap, pre);
    wrap.appendChild(pre);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'copy-btn';
    btn.textContent = 'Copy';
    wrap.appendChild(btn);

    btn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(pre.textContent);
        btn.textContent = 'Copied';
        btn.classList.add('copied');
      } catch (err) {
        btn.textContent = 'Select & copy';
      }
      setTimeout(() => {
        btn.textContent = 'Copy';
        btn.classList.remove('copied');
      }, 1800);
    });
  });

  /* ---------- Restart a CSS animation on demand (remove -> reflow -> add) ---------- */
  function restartAnimation(el, className) {
    el.classList.remove(className);
    // eslint-disable-next-line no-unused-expressions
    void el.offsetWidth; // force reflow so the browser re-registers the animation
    el.classList.add(className);
  }

  /* ---------- Feature icons: redraw their stroke on every hover ---------- */
  document.querySelectorAll('.feature').forEach((feature) => {
    const icon = feature.querySelector('.ic-svg');
    if (!icon) return;
    feature.addEventListener('mouseenter', () => {
      if (reducedMotion) return;
      restartAnimation(icon, 'redraw');
    });
  });

  /* ---------- Live demo: marker types itself, then morphs into the tree ---------- */
  (function initDemo() {
    const card = document.getElementById('demo-card');
    const marker = document.getElementById('demo-marker');
    const tree = document.getElementById('demo-tree');
    const replay = document.getElementById('demo-replay');
    if (!card || !marker || !tree) return;

    // Border-draw (.border-draw-rect, styled in site.css) reverses the
    // instant :hover drops, mid-transition, since it's a plain CSS
    // transition tied straight to the pseudo-class. A single flickered
    // frame of lost hover — a fast pointer pass across the card, or some
    // compositors' cursor-auto-hide momentarily counting as pointer-left —
    // is enough to snap it backward before it resumes forward on
    // re-entry, which reads as two disconnected arcs instead of one
    // continuous sweep. .is-hovered mirrors :hover in the CSS selector
    // (site.css) but its removal is debounced here, so a re-entry within
    // the window cancels the pending un-hover and the transition never
    // reverses at all.
    let leaveTimer = null;
    card.addEventListener('mouseenter', () => {
      clearTimeout(leaveTimer);
      card.classList.add('is-hovered');
    });
    card.addEventListener('mouseleave', () => {
      leaveTimer = setTimeout(() => card.classList.remove('is-hovered'), 150);
    });

    // Read from the element itself instead of a separate hardcoded string —
    // the marker's real text already has to live in the HTML (it's the
    // no-JS/initial-paint fallback), so a second copy here was two sources
    // of truth for the same value with no way to keep them in sync if a
    // project reusing this ever changed its marker syntax.
    const MARKER_TEXT = marker.textContent;
    const lines = Array.from(tree.querySelectorAll('.line'));
    let playing = false;
    let timers = [];

    const clearTimers = () => {
      timers.forEach((t) => clearTimeout(t));
      timers = [];
    };
    const after = (ms, fn) => timers.push(setTimeout(fn, ms));

    function play() {
      clearTimers();
      playing = true;
      lines.forEach((l) => l.classList.remove('show'));

      if (reducedMotion) {
        marker.textContent = MARKER_TEXT;
        lines.forEach((l) => l.classList.add('show'));
        playing = false;
        return;
      }

      marker.textContent = '';
      let i = 0;
      function typeNext() {
        marker.textContent = MARKER_TEXT.slice(0, i);
        if (i < MARKER_TEXT.length) {
          i += 1;
          after(55, typeNext);
        } else {
          after(450, revealLines);
        }
      }
      function revealLines() {
        lines.forEach((line, idx) => after(idx * 160, () => line.classList.add('show')));
        after(lines.length * 160 + 250, () => {
          playing = false;
        });
      }
      typeNext();
    }

    if (replay) {
      replay.addEventListener('click', play);
    }

    if ('IntersectionObserver' in window) {
      const demoIo = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting && !playing) {
              play();
              demoIo.disconnect();
            }
          });
        },
        { threshold: 0.4 }
      );
      demoIo.observe(card);
    } else {
      marker.textContent = MARKER_TEXT;
      lines.forEach((l) => l.classList.add('show'));
    }
  })();
})();
