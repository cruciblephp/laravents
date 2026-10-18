// locale.js — index.html + changelog.html both. Plain script (no
// type="module"): this never needs anything from changelog.js's exports, it
// only manipulates the rendered DOM the same way Google Translate itself
// does. site.js's createToggle is closure-scoped inside its own IIFE and
// unreachable from a second <script src> tag, so this file carries its own
// small duplicate below rather than exposing it from site.js.

(() => {
  'use strict';

  const STORAGE_KEY = 'laravents-locale';

  // Every menu option. `static: true` = this locale has real [data-locale]
  // content hand-authored in the HTML; `static: false` = the entry exists
  // purely to trigger whole-page Google Translate. `endonym` is the
  // language's own name for itself — a proper noun, never translated,
  // used only as the accessible name / tooltip for that option, never
  // rendered as visible switcher text (see index.html's locale-menu markup
  // — flags only). Adding a Google-Translate-only language is a one-line
  // edit here; adding a new HAND-TRANSLATED language additionally needs a
  // new CSS rule pair in locale.css and real [data-locale] spans authored
  // throughout the HTML — nothing here auto-derives those, there's no
  // build step in this project to generate one from the other.
  const LOCALES = [
    { code: 'en', endonym: 'English', flag: 'flag-us', static: true },
    { code: 'es', endonym: 'Español', flag: 'flag-es', static: true },
    { code: 'it', endonym: 'Italiano', flag: 'flag-it', static: true },
    { code: 'bg', endonym: 'Български', flag: 'flag-bg', static: false },
    { code: 'hr', endonym: 'Hrvatski', flag: 'flag-hr', static: false },
    { code: 'cs', endonym: 'Čeština', flag: 'flag-cz', static: false },
    { code: 'da', endonym: 'Dansk', flag: 'flag-dk', static: false },
    { code: 'nl', endonym: 'Nederlands', flag: 'flag-nl', static: false },
    { code: 'et', endonym: 'Eesti', flag: 'flag-ee', static: false },
    { code: 'fi', endonym: 'Suomi', flag: 'flag-fi', static: false },
    { code: 'fr', endonym: 'Français', flag: 'flag-fr', static: false },
    { code: 'de', endonym: 'Deutsch', flag: 'flag-de', static: false },
    { code: 'el', endonym: 'Ελληνικά', flag: 'flag-gr', static: false },
    { code: 'hu', endonym: 'Magyar', flag: 'flag-hu', static: false },
    { code: 'ga', endonym: 'Gaeilge', flag: 'flag-ie', static: false },
    { code: 'lv', endonym: 'Latviešu', flag: 'flag-lv', static: false },
    { code: 'lt', endonym: 'Lietuvių', flag: 'flag-lt', static: false },
    { code: 'mt', endonym: 'Malti', flag: 'flag-mt', static: false },
    { code: 'pl', endonym: 'Polski', flag: 'flag-pl', static: false },
    { code: 'pt', endonym: 'Português', flag: 'flag-pt', static: false },
    { code: 'ro', endonym: 'Română', flag: 'flag-ro', static: false },
    { code: 'sk', endonym: 'Slovenčina', flag: 'flag-sk', static: false },
    { code: 'sl', endonym: 'Slovenščina', flag: 'flag-si', static: false },
    { code: 'sv', endonym: 'Svenska', flag: 'flag-se', static: false },
    { code: 'zh-CN', endonym: '中文', flag: 'flag-cn', static: false },
  ];

  // Small local duplicate of site.js's createToggle — same shape (flip
  // aria-expanded on the trigger, aria-hidden on the target, an optional
  // callback for extra per-caller behavior), just not shared since it
  // can't be imported (see file header).
  function localToggle(toggleEl, targetEl, onToggle) {
    if (!toggleEl || !targetEl) return null;
    let open = false;
    function setOpen(next) {
      open = next;
      toggleEl.setAttribute('aria-expanded', String(open));
      targetEl.setAttribute('aria-hidden', String(!open));
      if (onToggle) onToggle(open);
    }
    return { setOpen, isOpen: () => open };
  }

  /* ---------- Nav overflow guard ---------- */
  // site.css's hamburger breakpoint (@media max-width, tuned in px) can't
  // actually stay correct once nav link text is translated — Spanish/
  // Italian labels already run longer than English ("Use cases" ->
  // "Casos de uso"), and the ~22 languages that go through Google
  // Translate can inject text of any length at runtime. This measures
  // whether .nav .wrap's content genuinely fits and forces the same
  // compact/hamburger layout (site.css .nav.is-compact) when it doesn't,
  // regardless of viewport width or which language is responsible. The
  // @media breakpoint stays in place as a no-JS baseline.
  const checkNavOverflow = (() => {
    const nav = document.querySelector('.nav');
    const wrap = nav ? nav.querySelector('.wrap') : null;
    // The last element that's actually visible in expanded/desktop mode,
    // before #nav-toggle (display:none until compact) would take that
    // spot instead — whichever of these exists first in the toolbar tail.
    const tail = document.getElementById('locale-switcher') || document.getElementById('search-trigger');
    if (!nav || !wrap || !tail) return () => {};

    // scrollWidth is deliberately not used to detect this: for an
    // overflow:visible element (.nav .wrap never sets overflow),
    // scrollWidth just echoes clientWidth back rather than reflecting
    // content that visually overflows past it. Comparing the actual
    // rendered position of the last toolbar item against the nav's own
    // right edge is what actually reflects overflow.
    // 24px margin, not 1px: right at the edge, .nav .links' own
    // pre-existing overflow-x:auto (site.css) can make a "fits" reading
    // technically true while actually relying on that scroll to hide the
    // last link or two. A real margin means "fits" only when there's
    // genuine room, not just zero pixels of it.
    function fits() {
      const navRight = nav.getBoundingClientRect().right;
      const tailRect = tail.getBoundingClientRect();
      return tailRect.width > 0 && tailRect.right <= navRight - 24;
    }

    let busy = false;
    function measure() {
      if (busy) return;
      busy = true;
      // Staged, cheapest-first — search is the LAST thing to shrink, not
      // an independent guess at its own viewport width. Each stage
      // reverts to the previous class state before testing the next, so
      // a later stage never measures the compounded effect of an earlier
      // one still being applied by mistake, and everything is re-tried
      // from stage 1 on every check — a stage that was needed a moment
      // ago (a longer language) can just as correctly turn back off once
      // it isn't (a shorter one, or more viewport width).
      nav.classList.remove('is-compact', 'search-compact');
      if (fits()) {
        busy = false;
        return;
      }
      nav.classList.add('is-compact');
      if (fits()) {
        busy = false;
        return;
      }
      nav.classList.add('search-compact');
      busy = false;
    }

    let raf = null;
    function scheduled() {
      if (raf) return;
      raf = requestAnimationFrame(() => {
        raf = null;
        measure();
      });
    }

    new ResizeObserver(scheduled).observe(wrap);
    // Synchronous, not scheduled() (which defers a frame via
    // requestAnimationFrame) — this first call runs as this script tag
    // itself executes, right after the nav's own markup has already been
    // parsed, so the correct is-compact/search-compact state is applied
    // before the very first paint has a chance to show the wrong one and
    // then visibly snap. The ResizeObserver above still uses the debounced
    // scheduled() for every check after this one, so a live window resize
    // doesn't thrash measure() on every intermediate pixel.
    measure();
    return scheduled;
  })();

  /* ---------- Locale switcher UI ---------- */
  let switcher = null;

  (function initLocaleSwitcher() {
    const root = document.getElementById('locale-switcher');
    const trigger = document.getElementById('locale-trigger');
    const menu = document.getElementById('locale-menu');
    const announce = document.getElementById('locale-announce');
    if (!root || !trigger || !menu) return;

    menu.replaceChildren();
    LOCALES.forEach((locale) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'none');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'locale-option';
      button.setAttribute('role', 'menuitemradio');
      button.setAttribute('aria-checked', 'false');
      button.dataset.localeCode = locale.code;
      button.title = locale.endonym;
      button.setAttribute('aria-label', locale.endonym);
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'locale-flag');
      svg.setAttribute('aria-hidden', 'true');
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', `#${locale.flag}`);
      svg.appendChild(use);
      button.appendChild(svg);
      li.appendChild(button);
      menu.appendChild(li);
      button.addEventListener('click', () => {
        setLocale(locale.code);
        toggle.setOpen(false);
        trigger.focus();
      });
    });

    const options = () => Array.from(menu.querySelectorAll('.locale-option'));

    const toggle = localToggle(trigger, menu, (open) => {
      root.classList.toggle('is-open', open);
      // Without this, focus stays on the trigger after opening — the
      // arrow-key/Home/End handler below is bound to `menu` and only ever
      // sees keydowns whose target is inside it, so with focus still on
      // the (sibling, not descendant) trigger, every key press was
      // silently going nowhere: Enter just re-toggled the trigger, arrows
      // did nothing.
      if (open) {
        const active = menu.querySelector('.locale-option[aria-checked="true"]') || options()[0];
        active?.focus();
      }
    });
    switcher = toggle;

    trigger.addEventListener('click', () => toggle.setOpen(!toggle.isOpen()));

    document.addEventListener('click', (e) => {
      if (!toggle.isOpen()) return;
      if (e.target === trigger || trigger.contains(e.target)) return;
      if (menu.contains(e.target)) return;
      toggle.setOpen(false);
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && toggle.isOpen()) {
        toggle.setOpen(false);
        trigger.focus();
      }
    });

    // 'l' opens the language menu — same guarded-shortcut pattern as
    // site.js's 's' for search: plain key only (no modifiers, so it
    // doesn't fight Cmd/Ctrl+L for the address bar), skipped while typing
    // in a field or while any <dialog> (the search modal) is open.
    document.addEventListener('keydown', (e) => {
      if (e.key.toLowerCase() !== 'l' || e.metaKey || e.ctrlKey || e.altKey) return;
      const active = document.activeElement;
      const isTyping =
        active &&
        (active.tagName === 'INPUT' ||
          active.tagName === 'TEXTAREA' ||
          active.isContentEditable);
      if (isTyping || document.querySelector('dialog[open]')) return;
      e.preventDefault();
      toggle.setOpen(true);
    });

    // Arrow-key navigation between flag options. This is a real 2D grid
    // (locale.css: grid-template-columns: repeat(5, 1fr)), not a flat
    // list — Right/Left move within the current row, Up/Down move a full
    // row (+/-5), matching how a visible grid is actually expected to
    // navigate rather than treating every arrow as "next/previous".
    const GRID_COLUMNS = 5;
    menu.addEventListener('keydown', (e) => {
      const opts = options();
      const current = opts.indexOf(document.activeElement);
      const clamp = (i) => Math.max(0, Math.min(opts.length - 1, i));
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        opts[clamp(current + 1)]?.focus();
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        opts[clamp(current - 1)]?.focus();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        opts[clamp(current + GRID_COLUMNS)]?.focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        opts[clamp(current - GRID_COLUMNS)]?.focus();
      } else if (e.key === 'Home') {
        e.preventDefault();
        opts[0]?.focus();
      } else if (e.key === 'End') {
        e.preventDefault();
        opts[opts.length - 1]?.focus();
      }
    });

    function markActive(code) {
      options().forEach((btn) => {
        btn.setAttribute('aria-checked', String(btn.dataset.localeCode === code));
      });
    }
    switcher.markActive = markActive;
    switcher.announce = (text) => {
      if (announce) announce.textContent = text;
    };
  })();

  /* ---------- Google Translate ---------- */
  // Lazy — the actual google.translate.TranslateElement init + script tag
  // load only happens on first non-English selection, so a visitor who
  // never switches language never pays for it. Custom UI (#locale-menu)
  // drives Google's real hidden <select class="goog-te-combo">
  // programmatically instead of showing any of Google's own widget chrome.
  let googleReadyPromise = null;
  let googleEngaged = false;

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function ensureGoogleTranslateLoaded() {
    if (googleReadyPromise) return googleReadyPromise;
    googleReadyPromise = new Promise((resolve) => {
      const includedLanguages = LOCALES.filter((l) => l.code !== 'en')
        .map((l) => l.code)
        .join(',');
      window.__laraventsInitGoogleTranslate = () => {
        // eslint-disable-next-line no-undef
        new google.translate.TranslateElement(
          {
            pageLanguage: 'en',
            includedLanguages,
            autoDisplay: false,
            // VERTICAL, not SIMPLE: SIMPLE renders a click-to-open popup
            // with no <select> in the main document at all (the language
            // list lives in a separate, same-origin-inaccessible surface),
            // so .goog-te-combo never appears and driving it
            // programmatically below silently does nothing.
            layout: google.translate.TranslateElement.InlineLayout.VERTICAL,
          },
          'translate'
        );
        // Resolve once the combo Google generates actually HAS its option
        // list populated — the <select> itself appears before Google has
        // filled in its <option>s, and setting .value against an option
        // that doesn't exist yet is the same silent no-op as a wrong value
        // string.
        const check = setInterval(() => {
          const combo = document.querySelector('.goog-te-combo');
          if (combo && combo.options.length > 0) {
            clearInterval(check);
            resolve(combo);
          }
        }, 100);
        setTimeout(() => clearInterval(check), 8000);
      };
      loadScript('https://translate.google.com/translate_a/element.js?cb=__laraventsInitGoogleTranslate');
    });
    return googleReadyPromise;
  }

  // Google rewrites the page's text nodes progressively, in several
  // mutation bursts, not in one shot — with nothing hiding it, the nav
  // sits in the pre-switch language and then visibly snaps word-by-word as
  // each burst lands. There's no "translation complete" event Google's
  // widget exposes, so "settled" is inferred: hide the nav, watch for mutations
  // to stop for a real quiet stretch (600ms — a margin above the ~460ms
  // gaps actually observed between bursts, so it doesn't reveal itself in
  // the gap between two waves), then reveal it and re-measure overflow
  // (translated text can be longer or shorter than what was there
  // before). cleanupPrevious cancels a still-running watch from a rapid
  // second switch, so two overlapping observers never both try to
  // finish() the same nav. maxTimer is a hard ceiling — if Google (or a
  // flaky network) never settles, the nav still comes back rather than
  // staying invisible forever.
  const SETTLE_QUIET_MS = 600;
  const SETTLE_MAX_MS = 8000;
  let cleanupPreviousWatch = null;

  function hideNavUntilTranslationSettles() {
    if (cleanupPreviousWatch) cleanupPreviousWatch();
    const nav = document.querySelector('.nav');
    if (!nav) return;
    nav.classList.add('translating');

    let quietTimer = null;
    let maxTimer = null;
    let finished = false;
    const observer = new MutationObserver(() => {
      clearTimeout(quietTimer);
      quietTimer = setTimeout(finish, SETTLE_QUIET_MS);
    });

    function finish() {
      if (finished) return;
      finished = true;
      observer.disconnect();
      clearTimeout(quietTimer);
      clearTimeout(maxTimer);
      nav.classList.remove('translating');
      checkNavOverflow();
      cleanupPreviousWatch = null;
    }

    observer.observe(nav, { childList: true, characterData: true, subtree: true });
    // Also armed with nothing having mutated yet, so a switch Google ends
    // up treating as a no-op (nothing left on this page to translate)
    // still reveals the nav instead of hanging until maxTimer.
    quietTimer = setTimeout(finish, SETTLE_QUIET_MS);
    maxTimer = setTimeout(finish, SETTLE_MAX_MS);
    cleanupPreviousWatch = finish;
  }

  let switchDebounceTimer = null;
  function switchGoogleTranslate(code) {
    ensureGoogleTranslateLoaded().then((combo) => {
      clearTimeout(switchDebounceTimer);
      switchDebounceTimer = setTimeout(() => {
        googleEngaged = true;
        hideNavUntilTranslationSettles();
        combo.value = code;
        combo.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
      }, 1500);
    });
  }

  function resetGoogleTranslateIfEngaged() {
    if (!googleEngaged) return;
    clearTimeout(switchDebounceTimer);
    // combo.value = '' does nothing useful: '' is just Google's own
    // unselected placeholder option ("Select language"), not an "undo" —
    // once Google has rewritten the DOM there is no supported in-place
    // revert available from outside its own visible banner (deliberately
    // hidden here in favor of our own UI). The googtrans cookie is what
    // Google's script reads on load to decide whether to translate at all,
    // so clearing it and reloading is what actually gets back to the real
    // original English.
    document.cookie = 'googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    document.cookie = `googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=${location.hostname};`;
    googleEngaged = false;
    location.reload();
  }

  /* ---------- Locale state ---------- */
  function setLocale(code) {
    document.documentElement.lang = code; // (1) single source of truth for every :lang() rule (locale.css) — always wins immediately, since that content is .notranslate
    try {
      localStorage.setItem(STORAGE_KEY, code);
    } catch {
      /* storage disabled — locale still applies for this load, just doesn't persist */
    }
    if (switcher) {
      switcher.markActive(code);
      const locale = LOCALES.find((l) => l.code === code);
      if (locale) switcher.announce(`Language changed to ${locale.endonym}`);
    }
    // (2) Google Translate is triggered on EVERY non-English selection
    // alike — hand-translated (es/it) or not. Its job is uniformly
    // "translate whatever isn't .notranslate": for es/it that's just the
    // un-overridden [data-i18n-default] gap strings; for a zero-content
    // language it's the whole page. One trigger path, not two — this is
    // what makes the per-string fallback real. Selecting 'en' — the
    // language everything was originally written in — never engages
    // Google at all, whether on first load or when switching back to it.
    if (code === 'en') {
      resetGoogleTranslateIfEngaged();
    } else {
      switchGoogleTranslate(code);
    }
    // Immediate check for our own static [data-locale] swap, which is
    // instant (pure CSS, no async gap to wait out). Google Translate's own
    // rewrite is async and arrives later with no fixed timing — for any
    // non-English code that's switchGoogleTranslate above, and
    // hideNavUntilTranslationSettles (inside it) is what re-checks
    // overflow once that rewrite actually finishes, instead of guessing
    // fixed delays that could fire before Google's done or long after.
    checkNavOverflow();
  }

  (function applyStoredLocale() {
    let code = null;
    try {
      code = localStorage.getItem(STORAGE_KEY);
    } catch {
      /* storage disabled — default to English, same as a first-ever visit */
    }
    if (code && code !== 'en' && LOCALES.some((l) => l.code === code)) {
      setLocale(code);
    } else if (switcher) {
      switcher.markActive('en');
    }
  })();
})();
