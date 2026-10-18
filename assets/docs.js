// docs.js - renders the repository's own Markdown (MANUAL.md, HELP.md,
// RELEASE.md, ...) into docs.html. vapor-chamber's api.js, adapted: that file
// renders a generated API reference, this one renders documents that already
// exist, so there is no generator at all - the page reads the same files the
// repository shows, and a doc edit is live on the site the moment it lands.
// Nothing here is a second copy of the documentation to keep in step.
//
// NO innerHTML ANYWHERE, deliberately, following the same rule as treegen2's
// changelog.js: every node is built with createElement/textContent, so nothing
// fetched from a .md file can ever be interpreted as markup and no manual
// escaping is needed. HELP.md is dense with `<file>` and `<N>` placeholders,
// which is exactly the input that punishes a string-concat renderer.
//
// THE MARKDOWN SUBSET IS THE ONE THESE FILES USE: headings, fenced code,
// tables, links, inline code, bold, emphasis, blockquotes, bullet and numbered
// lists (with indented continuation lines), rules and paragraphs. Anything
// outside the set renders as literal text, which is the right failure for a
// viewer: visible, and harmless.

(function () {
  'use strict';

  var body = document.body;
  var REPO = (body.dataset.repo || '').replace(/\/$/, '');
  var BLOB = REPO ? 'https://github.com/' + REPO + '/blob/main/' : '';
  // Where the Markdown is read from. The site and the code are separate
  // repositories, and the documents belong to the code: reading them from
  // the code repository's raw files keeps one copy of each, the one the
  // suite checks, instead of a site copy that could fall behind it.
  // Empty means the page's own origin.
  var SOURCE = body.dataset.source || '';
  if (SOURCE && SOURCE.charAt(SOURCE.length - 1) !== '/') SOURCE += '/';
  var navEl = document.getElementById('nav');
  var tocEl = document.getElementById('toc');
  var docEl = document.getElementById('doc');

  /**
   * The documents, in reading order. A fixed list rather than a directory
   * listing: a static host has no index to ask, and this order is an editorial
   * choice - the manual first, the reasoning last - not an alphabetical one.
   * `name` is the path without `.md`, and doubles as the route.
   */
  var pages = [
    { name: 'README', label: 'Overview' },
    { name: 'docs/README', label: 'Manual' },
    { name: 'docs/installation', label: 'Installation' },
    { name: 'docs/modules', label: 'Modules' },
    { name: 'docs/commands-and-queries', label: 'Commands and queries' },
    { name: 'docs/events', label: 'Events' },
    { name: 'docs/interceptors', label: 'Interceptors' },
    { name: 'docs/metadata-and-actor', label: 'Metadata and the actor' },
    { name: 'docs/outbox', label: 'The outbox' },
    { name: 'docs/http-gateway', label: 'The HTTP gateway' },
    { name: 'docs/testing', label: 'Testing' },
    { name: 'docs/console', label: 'Console' },
    { name: 'docs/verification-and-docs', label: 'Verification and docs' },
    { name: 'docs/reference', label: 'Reference' },
    { name: 'docs/laravents/README', label: 'Example modules' },
    { name: 'docs/laravents/Band', label: 'Example: Band' },
    { name: 'docs/laravents/Press', label: 'Example: Press' },
    { name: 'docs/laravents/FanClub', label: 'Example: FanClub' },
    { name: 'CONTRIBUTING', label: 'Contributing' },
    { name: 'SECURITY', label: 'Security' },
  ];

  /** GitHub's slug rule, closely enough that every in-file `#anchor` in these documents resolves. */
  function anchor(name) {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  /**
   * Scrolls `node` into view WITHIN `container` and nowhere else.
   *
   * Not `scrollIntoView`: that walks every scrollable ancestor, the document
   * included, so the scroll-spy marking a row while the reader scrolled was
   * also nudging the page - the column appeared to drag the content with it.
   * Adjusting scrollTop by hand touches exactly one box.
   */
  function keepInView(container, node) {
    var box = container.getBoundingClientRect();
    var item = node.getBoundingClientRect();
    if (item.top < box.top) container.scrollTop -= box.top - item.top;
    else if (item.bottom > box.bottom) container.scrollTop += item.bottom - box.bottom;
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  var SVG_NS = 'http://www.w3.org/2000/svg';

  /**
   * The outbound marker on a link that leaves the docs (a source file on
   * GitHub). Drawn rather than typed: a glyph would inherit the font's weight
   * instead of the link's.
   */
  function externalIcon() {
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'ext');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', 'M9 5 H19 V15 M19 5 L7 17');
    svg.appendChild(path);
    return svg;
  }

  // ---------- links ----------

  /** The directory of the page being rendered, so a relative link resolves against it. */
  function dirOf(page) {
    var cut = page.lastIndexOf('/');
    return cut === -1 ? '' : page.slice(0, cut + 1);
  }

  /** `a/b/../c` -> `a/c`; a path that climbs above the root keeps its remainder. */
  function normalize(path) {
    var out = [];
    path.split('/').forEach(function (part) {
      if (part === '..') out.pop();
      else if (part !== '.' && part !== '') out.push(part);
    });
    return out.join('/');
  }

  function isPage(name) {
    return pages.some(function (page) {
      return page.name === name;
    });
  }

  /**
   * Where a link in the Markdown should go on the site. Three cases, and the
   * third is the one the API reference never had: these documents link to
   * source files and directories (`examples/03-doubles/PaymentsTest.php`),
   * which exist in the repository but not on the site, so they go to GitHub.
   */
  function resolveHref(href) {
    if (/^(https?:|mailto:)/.test(href)) return { href: href, external: true };
    if (href.charAt(0) === '#') return { href: '#/' + currentPage + href, external: false };

    var hash = '';
    var cut = href.indexOf('#');
    if (cut !== -1) {
      hash = href.slice(cut);
      href = href.slice(0, cut);
    }
    var path = normalize(dirOf(currentPage) + href);
    var name = path.replace(/\.md$/, '');
    if (/\.md$/.test(path) && isPage(name)) return { href: '#/' + name + hash, external: false };
    if (!BLOB) return { href: path + hash, external: false };
    return { href: BLOB + path + hash, external: true };
  }

  // ---------- markdown ----------

  // Appends an inline run as real nodes.
  //
  // The regex is declared INSIDE the function, as treegen2's changelog.js does
  // it, and that is load-bearing rather than stylistic: this function recurses
  // for link labels, and a shared /g regex hoisted to module scope has one
  // lastIndex for both frames. The inner call rewound it and the outer loop
  // stopped terminating.
  //
  // Emphasis takes `*x*` and `_x_` only when the underscore stands at a word
  // boundary: `--coverage_html` style names and `snake_case` must stay text.
  function appendInline(container, raw) {
    var pattern = /`([^`]+)`|\*\*([^*]+)\*\*|\[([^\]]+)\]\(([^)]+)\)|\*([^*\s][^*]*)\*|(?:^|(?<=[\s(]))_([^_]+)_(?=[\s.,;:)]|$)/g;
    var last = 0;
    var match;
    while ((match = pattern.exec(raw))) {
      if (match.index > last) container.appendChild(document.createTextNode(raw.slice(last, match.index)));
      if (match[1] !== undefined) {
        container.appendChild(el('code', null, match[1]));
      } else if (match[2] !== undefined) {
        var strong = el('strong');
        appendInline(strong, match[2]);
        container.appendChild(strong);
      } else if (match[3] !== undefined) {
        // The LABEL is inline markdown too: nearly every link in these
        // documents is a code span, and setting textContent would print its
        // backticks literally.
        var target = resolveHref(match[4]);
        var link = el('a');
        link.href = target.href;
        appendInline(link, match[3]);
        if (target.external) {
          link.target = '_blank';
          link.rel = 'noreferrer';
          // Marked, because it opens a new tab and leaves the docs: a source
          // link looks identical to an in-page one otherwise.
          link.classList.add('external');
          link.appendChild(externalIcon());
        }
        container.appendChild(link);
      } else {
        var em = el('em');
        appendInline(em, match[5] !== undefined ? match[5] : match[6]);
        container.appendChild(em);
      }
      last = match.index + match[0].length;
    }
    if (last < raw.length) container.appendChild(document.createTextNode(raw.slice(last)));
  }

  // A cell may contain an escaped pipe, which is data rather than a column
  // break: HELP.md has `<n\|max>`.
  function splitRow(row) {
    var cells = row.replace(/^\||\|$/g, '').split(/(?<!\\)\|/);
    return cells.map(function (cell) {
      return cell.trim().replace(/\\\|/g, '|');
    });
  }

  // ---------- syntax highlighting ----------
  //
  // A PHP highlighter for configuration and short samples is a tokenizer, not
  // a dependency. The corpus is narrow - almost every block is a `crucible.php`
  // fragment, a test, or a command line - so comments, strings, keywords,
  // numbers and type-position identifiers cover it, and anything unmatched
  // stays plain text. vapor-chamber's TypeScript tokenizer, with PHP's
  // vocabulary: `#` comments as well as `//`, no template literals, and PHP's
  // keyword set. `$variables` are deliberately plain - there is no token class
  // for them, and a sixth colour would be a value no palette here defines.
  //
  // Order in the alternation IS the precedence: comments and strings come
  // first so a keyword inside either is not re-coloured.
  var PHP_TOKENS = new RegExp(
    [
      '(//[^\\n]*|#(?!\\[)[^\\n]*|/\\*[\\s\\S]*?\\*/)', // 1 comment (not an #[Attribute])
      '(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")', // 2 string
      '\\b(abstract|and|array|as|break|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|enum|extends|final|finally|fn|for|foreach|function|global|if|implements|include|instanceof|interface|match|namespace|new|null|or|private|protected|public|readonly|require|require_once|return|self|static|switch|throw|trait|true|false|try|use|var|void|while|yield|int|string|bool|float|iterable|mixed|never|object|callable)\\b', // 3 keyword
      '\\b(\\d[\\d_]*(?:\\.\\d+)?)\\b', // 4 number
      '\\b([A-Z][A-Za-z0-9_]*)\\b', // 5 type-ish identifier
      '([A-Za-z_][A-Za-z0-9_]*)(?=\\s*\\()', // 6 call
    ].join('|'),
    'g',
  );

  // Shell samples get comments and strings only: everything else in a command
  // line is a word, and colouring words by guesswork would mislead.
  var SHELL_TOKENS = /(#[^\n]*)|('(?:[^'])*'|"(?:\\.|[^"\\])*")/g;

  var TOKEN_CLASS = { 1: 'tok-comment', 2: 'tok-string', 3: 'tok-keyword', 4: 'tok-number', 5: 'tok-type', 6: 'tok-call' };

  function highlight(code, lang) {
    var node = el('code', lang ? 'lang-' + lang : null);
    var source = lang === 'php' ? PHP_TOKENS : lang === 'bash' || lang === 'sh' ? SHELL_TOKENS : null;
    if (!source) {
      node.textContent = code;
      return node;
    }
    var pattern = new RegExp(source.source, 'g');
    var last = 0;
    var match;
    while ((match = pattern.exec(code))) {
      if (match.index > last) node.appendChild(document.createTextNode(code.slice(last, match.index)));
      for (var group = 1; group <= 6; group++) {
        if (match[group] !== undefined) {
          node.appendChild(el('span', TOKEN_CLASS[group], match[group]));
          break;
        }
      }
      last = match.index + match[0].length;
    }
    if (last < code.length) node.appendChild(document.createTextNode(code.slice(last)));
    return node;
  }

  function render(markdown, target) {
    var lines = markdown.replace(/\r\n/g, '\n').split('\n');
    var paragraph = [];
    var list = null;
    var headings = [];

    function flushParagraph() {
      if (!paragraph.length) return;
      var p = el('p');
      appendInline(p, paragraph.join(' '));
      target.appendChild(p);
      paragraph = [];
    }

    function flushList() {
      if (!list) return;
      var element = el(list.ordered ? 'ol' : 'ul');
      if (list.ordered && list.start !== 1) element.start = list.start;
      list.items.forEach(function (item) {
        var li = el('li');
        appendInline(li, item);
        element.appendChild(li);
      });
      target.appendChild(element);
      list = null;
    }

    function flush() {
      flushParagraph();
      flushList();
    }

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];

      if (!line.trim() || line.indexOf('<!--') === 0) {
        flush();
        continue;
      }

      // Fenced code first: no inline rule may rewrite the inside of a sample.
      if (line.indexOf('```') === 0) {
        flush();
        var lang = line.slice(3).trim();
        var code = [];
        i++;
        while (i < lines.length && lines[i].indexOf('```') !== 0) code.push(lines[i++]);
        var pre = el('pre');
        pre.appendChild(highlight(code.join('\n'), lang));
        target.appendChild(pre);
        continue;
      }

      if (/^(-{3,}|\*{3,}|_{3,})$/.test(line.trim())) {
        flush();
        target.appendChild(el('hr'));
        continue;
      }

      var heading = line.match(/^(#{1,6})\s+(.*)$/);
      if (heading) {
        flush();
        var level = heading[1].length;
        var plain = heading[2].replace(/`/g, '');
        var h = el('h' + level);
        h.id = anchor(plain);
        appendInline(h, heading[2]);
        target.appendChild(h);
        if (level === 2 || level === 3) headings.push({ text: plain, id: h.id, level: level });
        continue;
      }

      // A table is a header row followed by the delimiter row.
      if (line.charAt(0) === '|' && /^\|[\s:|-]+\|$/.test(lines[i + 1] || '')) {
        flush();
        var table = el('table');
        var thead = el('thead');
        var headRow = el('tr');
        splitRow(line).forEach(function (cell) {
          var th = el('th');
          appendInline(th, cell);
          headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = el('tbody');
        i += 2;
        while (i < lines.length && lines[i].charAt(0) === '|') {
          var tr = el('tr');
          splitRow(lines[i]).forEach(function (cell) {
            var td = el('td');
            appendInline(td, cell);
            tr.appendChild(td);
          });
          tbody.appendChild(tr);
          i++;
        }
        i--;
        table.appendChild(tbody);
        target.appendChild(table);
        continue;
      }

      if (line.indexOf('> ') === 0) {
        flush();
        var quote = el('blockquote');
        appendInline(quote, line.slice(2));
        target.appendChild(quote);
        continue;
      }

      var bullet = line.match(/^[-*]\s+(.*)$/);
      var numbered = line.match(/^(\d+)\.\s+(.*)$/);
      if (bullet || numbered) {
        flushParagraph();
        var ordered = Boolean(numbered);
        if (list && list.ordered !== ordered) flushList();
        if (!list) list = { ordered: ordered, start: ordered ? Number(numbered[1]) : 1, items: [] };
        list.items.push(ordered ? numbered[2] : bullet[1]);
        continue;
      }

      // An indented line under a list item continues it: these documents wrap
      // their bullets at the column, and a new paragraph there would split one
      // sentence across two blocks.
      if (list && /^\s+\S/.test(line)) {
        list.items[list.items.length - 1] += ' ' + line.trim();
        continue;
      }

      flushList();
      paragraph.push(line.trim());
    }

    flush();
    return headings;
  }

  // ---------- pages ----------

  var cache = {};
  /** The page currently rendered, so in-page anchors can be namespaced to it. */
  var currentPage = pages[0].name;

  function fetchPage(name) {
    if (cache[name]) return cache[name];
    cache[name] = fetch(SOURCE + name + '.md').then(function (res) {
      if (!res.ok) throw new Error(res.status + ' ' + name);
      return res.text();
    });
    return cache[name];
  }

  function buildNav(current) {
    navEl.textContent = '';
    pages.forEach(function (page) {
      var link = el('a', page.name === current ? 'active' : null);
      link.href = '#/' + page.name;
      link.appendChild(el('span', 'entry', page.label));
      // The manual's shared docs/ prefix is dropped here only, so the labels keep their room.
      link.appendChild(el('span', 'file', page.name.replace(/^docs\//, '') + '.md'));
      navEl.appendChild(link);
    });
  }

  var spy = null;

  function buildToc(headings) {
    tocEl.textContent = '';
    headings.forEach(function (h) {
      var link = el('a', h.level === 3 ? 'sub' : null);
      link.href = '#/' + currentPage + '#' + h.id;
      link.dataset.target = h.id;
      link.appendChild(el('span', 'label', h.text));
      tocEl.appendChild(link);
    });

    // Scroll-spy: marks the entry whose heading the reader is currently under.
    //
    // A plain scroll listener, as treegen2's changelog.js does it, rather than
    // an IntersectionObserver. That was not a style preference: the observer
    // version is written against a narrow band under the top bar, and for most
    // scroll positions NO heading is inside that band, so it reported nothing
    // to mark and the list sat with no highlight at all. "The last heading
    // above the line" always has an answer.
    if (spy) window.removeEventListener('scroll', spy);
    if (!headings.length) return;

    var nodes = headings
      .map(function (h) {
        return document.getElementById(h.id);
      })
      .filter(Boolean);

    var marked = null;
    spy = function () {
      var current = nodes[0];
      for (var i = 0; i < nodes.length; i++) {
        if (nodes[i].getBoundingClientRect().top > 80) break;
        current = nodes[i];
      }
      if (!current || current.id === marked) return;
      marked = current.id;
      var previous = tocEl.querySelector('a.active');
      if (previous) previous.classList.remove('active');
      var link = tocEl.querySelector('a[data-target="' + marked + '"]');
      if (link) {
        link.classList.add('active');
        keepInView(tocEl, link);
      }
    };

    window.addEventListener('scroll', spy, { passive: true });
    spy();
  }

  function scrollToAnchor(hash) {
    if (!hash) {
      window.scrollTo(0, 0);
      return;
    }
    var target = document.getElementById(hash);
    if (target) target.scrollIntoView();
  }

  function show(name, hash) {
    // Already rendered: clicking a table-of-contents entry is a scroll, not a
    // reload, and re-rendering DESIGN.md's hundred records to move the
    // viewport flickers.
    if (name === currentPage && !docEl.hasAttribute('aria-busy')) {
      scrollToAnchor(hash);
      return;
    }
    currentPage = name;
    docEl.setAttribute('aria-busy', 'true');
    fetchPage(name)
      .then(function (markdown) {
        docEl.textContent = '';
        var headings = render(markdown, docEl);
        docEl.removeAttribute('aria-busy');
        var page = pages.filter(function (p) {
          return p.name === name;
        })[0];
        document.title = page.label + ' · Laravents docs';
        buildNav(name);
        buildToc(headings);
        syncStepper();
        scrollToAnchor(hash);
      })
      .catch(function (error) {
        docEl.textContent = '';
        docEl.appendChild(el('p', 'loading', 'Could not load ' + name + '.md (' + error.message + ').'));
        docEl.removeAttribute('aria-busy');
      });
  }

  // Route is `#/page` with an optional `#anchor` appended after it.
  function route() {
    var raw = location.hash.replace(/^#\/?/, '');
    if (!raw) return show(pages[0].name, '');
    var cut = raw.indexOf('#');
    var name = cut === -1 ? raw : raw.slice(0, cut);
    var hash = cut === -1 ? '' : raw.slice(cut + 1);
    show(isPage(name) ? name : pages[0].name, hash);
  }

  // ---------- sticky offset ----------
  //
  // The two columns pin themselves under the top bar, and the offset has to be
  // the bar's REAL height. A literal drifts the moment anything in the bar
  // changes - a wrapped nav, a hidden search box at narrow widths, a font
  // landing late - and the columns then either slide under the bar on the
  // first scroll or leave a gap above themselves.
  (function trackTopbar() {
    var bar = document.querySelector('.topbar');
    if (!bar) return;
    var apply = function () {
      // getBoundingClientRect, not offsetHeight: the latter rounds to whole
      // pixels, so a fractional bar still crept under the columns on scroll.
      document.documentElement.style.setProperty('--topbar-h', bar.getBoundingClientRect().height + 'px');
    };
    apply();
    // Both, not one or the other: a ResizeObserver is the precise signal, but
    // it was observed not to fire under emulated viewport changes.
    window.addEventListener('resize', apply);
    if (typeof ResizeObserver === 'function') new ResizeObserver(apply).observe(bar);
    // Late web fonts change the bar's height after first paint.
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(apply);
  })();

  // ---------- stepping between documents ----------

  function order() {
    return pages.map(function (page) {
      return page.name;
    });
  }

  function step(delta) {
    var all = order();
    var at = all.indexOf(currentPage);
    if (at === -1) return;
    var next = all[at + delta];
    if (next) location.hash = '#/' + next;
  }

  function syncStepper() {
    var all = order();
    var at = all.indexOf(currentPage);
    var prev = document.getElementById('prev-entry');
    var next = document.getElementById('next-entry');
    if (prev) prev.disabled = at <= 0;
    if (next) next.disabled = at === -1 || at >= all.length - 1;
  }

  (function initStepper() {
    var prev = document.getElementById('prev-entry');
    var next = document.getElementById('next-entry');
    if (prev) prev.addEventListener('click', function () { step(-1); });
    if (next) next.addEventListener('click', function () { step(1); });
  })();

  // ---------- keyboard ----------
  //
  // Only when the reader is not typing, and never with a modifier held, so
  // browser and OS shortcuts keep working. The search modal owns the keyboard
  // while it is open and is handled separately.
  document.addEventListener('keydown', function (event) {
    if (event.metaKey || event.ctrlKey || event.altKey) return;
    var modal = document.getElementById('search-modal');
    if (modal && !modal.hidden) return;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) return;

    if (event.key === 'ArrowLeft' || event.key === '[') {
      event.preventDefault();
      step(-1);
    } else if (event.key === 'ArrowRight' || event.key === ']') {
      event.preventDefault();
      step(1);
    } else if (event.key === 'g') {
      event.preventDefault();
      window.scrollTo(0, 0);
    } else if (event.key === 'G') {
      event.preventDefault();
      window.scrollTo(0, document.body.scrollHeight);
    } else if (event.key === 'j' || event.key === 'k') {
      // Move through the sections of the current page, following the same
      // list the scroll-spy marks, so the two never disagree.
      event.preventDefault();
      var links = [].slice.call(tocEl.querySelectorAll('a'));
      if (!links.length) return;
      var at = links.indexOf(tocEl.querySelector('a.active'));
      var to = links[Math.min(links.length - 1, Math.max(0, at + (event.key === 'j' ? 1 : -1)))];
      if (to) to.click();
    }
  });

  // ---------- search ----------

  (function initSearch() {
    var trigger = document.getElementById('search-trigger');
    var modal = document.getElementById('search-modal');
    var input = document.getElementById('search-input');
    var results = document.getElementById('search-results');
    if (!trigger || !modal || !input || !results) return;

    var index = null;
    var active = 0;

    // Built once, on first open, from every `##` and `###` heading of every
    // document. Fetching the whole set is what makes the search complete
    // rather than page-local, and it is deferred to first use so a reader who
    // never searches never pays for it.
    function buildIndex() {
      if (index) return Promise.resolve(index);
      return Promise.all(
        pages.map(function (page) {
          return fetchPage(page.name).then(function (markdown) {
            var entries = [];
            markdown.split('\n').forEach(function (line) {
              var heading = /^(#{2,3})\s+(.*)$/.exec(line);
              if (!heading) return;
              var text = heading[2].replace(/`/g, '').trim();
              entries.push({ text: text, page: page.name, where: page.label, id: anchor(text) });
            });
            return entries;
          });
        }),
      ).then(function (all) {
        // The documents themselves are searchable too, and sort first, so
        // typing "options" lands on HELP.md rather than on a heading in it.
        var docs = pages.map(function (page) {
          return { text: page.label, page: page.name, where: page.name + '.md', id: '', doc: true };
        });
        index = docs.concat(
          all.reduce(function (flat, entries) {
            return flat.concat(entries);
          }, []),
        );
        return index;
      });
    }

    function draw(query) {
      results.textContent = '';
      active = 0;
      if (!index) return;
      var needle = query.trim().toLowerCase();
      var matches = !needle
        ? []
        : index
            .filter(function (entry) {
              return entry.text.toLowerCase().indexOf(needle) !== -1;
            })
            // Documents first, then exact prefixes: both are what someone
            // typing a bare name is most likely reaching for.
            .sort(function (a, b) {
              var ad = a.doc ? 0 : 1;
              var bd = b.doc ? 0 : 1;
              var ap = a.text.toLowerCase().indexOf(needle) === 0 ? 0 : 1;
              var bp = b.text.toLowerCase().indexOf(needle) === 0 ? 0 : 1;
              return ad - bd || ap - bp || a.text.length - b.text.length;
            })
            .slice(0, 40);

      if (!needle) {
        results.appendChild(el('p', 'empty', index.length + ' sections indexed. Start typing.'));
        return;
      }
      if (!matches.length) {
        results.appendChild(el('p', 'empty', 'Nothing matches "' + query.trim() + '".'));
        return;
      }
      matches.forEach(function (entry, i) {
        var link = el('a', i === 0 ? 'active' : null);
        link.href = '#/' + entry.page + (entry.id ? '#' + entry.id : '');
        link.appendChild(el('span', 'symbol', entry.text));
        link.appendChild(el('span', 'where', entry.where));
        link.addEventListener('click', close);
        results.appendChild(link);
      });
    }

    function move(delta) {
      var links = results.querySelectorAll('a');
      if (!links.length) return;
      links[active].classList.remove('active');
      active = (active + delta + links.length) % links.length;
      links[active].classList.add('active');
      keepInView(results, links[active]);
    }

    function open() {
      modal.hidden = false;
      input.value = '';
      results.textContent = '';
      results.appendChild(el('p', 'empty', 'Indexing...'));
      input.focus();
      buildIndex().then(function () {
        draw(input.value);
      });
    }

    function close() {
      modal.hidden = true;
    }

    trigger.addEventListener('click', open);
    input.addEventListener('input', function () {
      draw(input.value);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
      } else if (event.key === 'Enter') {
        var current = results.querySelector('a.active');
        if (current) {
          event.preventDefault();
          location.hash = current.getAttribute('href').slice(1);
          close();
        }
      }
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) close();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) {
        close();
        return;
      }
      // "/" is the shortcut, but not while the reader is typing somewhere else.
      var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
      if (event.key === '/' && modal.hidden && !typing) {
        event.preventDefault();
        open();
      }
    });
  })();

  // ---------- boot ----------

  window.addEventListener('hashchange', route);
  route();
})();
