/* assets/copilot.js — chat ng Admin Copilot (Phase 8e-3). Markup: includes/copilot_widget.php, backend: admin/copilot_api.php.
 * Lahat ng teksto ay inilalagay gamit ang textContent / DOM node (walang innerHTML), kaya hindi makakapagpasok ng HTML ang sagot ng AI. */
(function () {
    'use strict';

    var root = document.getElementById('sx-copilot');
    if (!root) return;

    var API = '/sweepxpress/admin/copilot_api.php';
    var STORE = 'sx-copilot-v1';
    var MAX_STORED = 20;
    var HISTORY = 6;
    var csrf = root.dataset.csrf || '';
    var ready = root.dataset.ready === '1';
    var chips = [];
    try { chips = JSON.parse(root.dataset.chips || '[]'); } catch (e) { chips = []; }

    var fab = root.querySelector('.cp-fab');
    var panel = root.querySelector('.cp-panel');
    var log = root.querySelector('.cp-log');
    var form = root.querySelector('.cp-form');
    var input = form.querySelector('textarea');
    var send = form.querySelector('.cp-send');
    var hint = root.querySelector('[data-cp-hint]');
    var baseHint = hint ? hint.textContent : '';

    var TOOL_LABELS = {
        get_business_snapshot: 'Business snapshot', get_sales_summary: 'Sales', get_top_products: 'Top products', get_low_stock: 'Low stock',
        get_stock_forecast: 'Stock forecast', get_reorder_suggestions: 'Reorder points', get_orders_overview: 'Orders',
        get_purchase_orders: 'Purchase orders', get_supplier_scorecard: 'Supplier scorecard', find_product: 'Product lookup'
    };
    var PAGES = 'reorder_points|reorder|purchase_orders|purchase_order|supplier_scorecard|suppliers|supplier|inventory|products|allorders|deliveries';

    var messages = load();
    var busy = false;
    var controller = null;

    // ---------- storage (sessionStorage; puwedeng wala o bawal) ----------
    function load() {
        try {
            var raw = JSON.parse(sessionStorage.getItem(STORE) || '[]');
            return Array.isArray(raw) ? raw.filter(function (m) { return m && typeof m.content === 'string' && /^(user|assistant|error)$/.test(m.role); }).slice(-MAX_STORED) : [];
        } catch (e) { return []; }
    }
    function save() {
        try { sessionStorage.setItem(STORE, JSON.stringify(messages.slice(-MAX_STORED))); } catch (e) { /* ayos lang */ }
    }

    // ---------- pagbuo ng DOM ----------
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    // **bold** at mga pangalan ng admin page (whitelist lang) → link
    var INLINE = new RegExp('\\*\\*(.+?)\\*\\*|\\b(' + PAGES + ')\\.php\\b', 'g');
    function inline(parent, text) {
        var last = 0, m;
        INLINE.lastIndex = 0;
        while ((m = INLINE.exec(text))) {
            if (m.index > last) parent.appendChild(document.createTextNode(text.slice(last, m.index)));
            if (m[1] != null) {
                parent.appendChild(el('strong', '', m[1]));
            } else {
                var a = el('a', '', m[2] + '.php');
                a.href = '/sweepxpress/admin/' + m[2] + '.php';
                parent.appendChild(a);
            }
            last = m.index + m[0].length;
        }
        if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
    }

    function cells(line) {
        return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) { return c.trim(); });
    }

    // Napakasimpleng markdown: talata, bullet, numbered, at table na may |
    function format(text) {
        var frag = document.createDocumentFragment();
        var lines = text.replace(/\r/g, '').split('\n');
        var i = 0;
        while (i < lines.length) {
            var line = lines[i];
            if (/^\s*$/.test(line)) { i++; continue; }
            var bullet = /^\s*[-*•]\s+/, num = /^\s*\d+[.)]\s+/;
            if (bullet.test(line) || num.test(line)) {
                var re = bullet.test(line) ? bullet : num;
                var list = el(re === bullet ? 'ul' : 'ol');
                while (i < lines.length && re.test(lines[i])) {
                    inline(list.appendChild(el('li')), lines[i].replace(re, ''));
                    i++;
                }
                frag.appendChild(list);
            } else if (/^\s*\|/.test(line)) {
                var rows = [];
                while (i < lines.length && /^\s*\|/.test(lines[i])) {
                    if (!/^\s*\|?\s*:?-{2,}/.test(lines[i])) rows.push(cells(lines[i]));
                    i++;
                }
                var wrap = el('div', 'cp-table'), table = wrap.appendChild(el('table'));
                rows.forEach(function (r, idx) {
                    var tr = table.appendChild(el('tr'));
                    r.forEach(function (c) { inline(tr.appendChild(el(idx === 0 ? 'th' : 'td')), c); });
                });
                frag.appendChild(wrap);
            } else {
                var p = el('p');
                var first = true;
                while (i < lines.length && !/^\s*$/.test(lines[i]) && !bullet.test(lines[i]) && !num.test(lines[i]) && !/^\s*\|/.test(lines[i])) {
                    if (!first) p.appendChild(document.createElement('br'));
                    inline(p, lines[i]);
                    first = false;
                    i++;
                }
                frag.appendChild(p);
            }
        }
        return frag;
    }

    function copy(text, btn) {
        function done() { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () {});
            return;
        }
        var t = el('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* walang magawa */ }
        document.body.removeChild(t);
    }

    function render() {
        log.textContent = '';
        if (!messages.length) {
            var w = el('div', 'cp-welcome');
            var logo = el('span', 'cp-logo'); logo.appendChild(el('i', 'fa-solid fa-wand-magic-sparkles'));
            w.appendChild(logo);
            w.appendChild(el('h3', '', 'How can I help?'));
            w.appendChild(el('p', '', ready
                ? 'Ask about stock, sales, purchase orders or suppliers. I read your store data and cannot change anything.'
                : 'The assistant is not set up yet. Add GROQ_API_KEY to the .env file (see .env.example), then reload this page.'));
            if (ready) {
                var box = el('div', 'cp-chips');
                chips.forEach(function (q) {
                    var b = el('button', 'cp-chip', q); b.type = 'button';
                    b.addEventListener('click', function () { ask(q); });
                    box.appendChild(b);
                });
                w.appendChild(box);
            }
            log.appendChild(w);
        }
        messages.forEach(function (m) {
            var d = el('div', 'cp-msg ' + m.role);
            if (m.role === 'assistant') {
                d.appendChild(format(m.content));
                var meta = el('div', 'cp-meta');
                (m.tools || []).forEach(function (t) { meta.appendChild(el('span', 'cp-tool', TOOL_LABELS[t] || t)); });
                var c = el('button', 'cp-copy', 'Copy'); c.type = 'button';
                c.addEventListener('click', function () { copy(m.content, c); });
                meta.appendChild(c);
                d.appendChild(meta);
            } else {
                d.textContent = m.content;
            }
            log.appendChild(d);
        });
        if (busy) {
            var t = el('div', 'cp-msg assistant'); t.setAttribute('aria-label', 'Copilot is thinking');
            var dots = el('span', 'cp-typing'); dots.appendChild(el('i')); dots.appendChild(el('i')); dots.appendChild(el('i'));
            t.appendChild(dots);
            log.appendChild(t);
        }
        log.scrollTop = log.scrollHeight;
        sync();
    }

    function sync() {
        input.disabled = !ready;
        send.disabled = !ready || busy || input.value.trim() === '';
        input.placeholder = ready ? 'Ask about stock, sales, suppliers…' : 'Not set up yet';
    }

    // ---------- bukas / sara ----------
    function open() {
        root.classList.add('cp-open');
        fab.setAttribute('aria-expanded', 'true');
        setTimeout(function () { if (ready) input.focus(); }, 60);
    }
    function close() {
        root.classList.remove('cp-open');
        fab.setAttribute('aria-expanded', 'false');
        fab.focus();
    }

    // ---------- pagtatanong ----------
    function push(m) { messages.push(m); if (messages.length > MAX_STORED) messages = messages.slice(-MAX_STORED); }

    function ask(question) {
        question = (question || '').trim();
        if (!ready) { open(); return; }   // ipakita ang paalala sa panel kung wala pang key
        if (busy || !question) return;
        open();
        var history = messages.filter(function (m) { return m.role !== 'error'; }).slice(-HISTORY)
            .map(function (m) { return { role: m.role, content: m.content }; });
        push({ role: 'user', content: question });
        input.value = ''; autosize();
        busy = true; render(); save();

        controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = setTimeout(function () { if (controller) controller.abort(); }, 55000);
        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ question: question, history: history }),
            signal: controller ? controller.signal : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; });
        }).then(function (res) {
            if (res.ok && res.d.reply) {
                push({ role: 'assistant', content: String(res.d.reply), tools: Array.isArray(res.d.tools) ? res.d.tools : [] });
                if (hint) hint.textContent = typeof res.d.remaining_today === 'number'
                    ? baseHint + ' ' + res.d.remaining_today + ' questions left today.' : baseHint;
            } else {
                push({ role: 'error', content: res.d.message || 'Something went wrong. Please try again.' });
            }
        }).catch(function (err) {
            push({ role: 'error', content: err && err.name === 'AbortError' ? 'That took too long. Try a simpler question.' : 'Could not reach the server. Check your connection and try again.' });
        }).then(function () {
            clearTimeout(timer); busy = false; controller = null; render(); save();
        });
    }

    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    // ---------- mga event ----------
    fab.addEventListener('click', open);
    root.querySelectorAll('[data-cp-close]').forEach(function (b) { b.addEventListener('click', close); });
    root.querySelector('[data-cp-clear]').addEventListener('click', function () {
        if (controller) controller.abort();
        messages = []; busy = false; save(); render();
        if (hint) hint.textContent = baseHint;
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    input.addEventListener('input', function () { autosize(); sync(); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(input.value); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.classList.contains('cp-open')) close();
    });

    // Para sa ibang bahagi ng admin (hal. "Daily brief" sa dashboard)
    window.sxCopilot = { open: open, close: close, ask: ask };

    // Mga buton kahit saan sa pahina: data-cp-ask="tanong" ay nagtatanong, data-cp-open ay nagbubukas lang
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-cp-ask], [data-cp-open]') : null;
        if (!t) return;
        e.preventDefault();
        if (t.hasAttribute('data-cp-ask')) ask(t.getAttribute('data-cp-ask')); else open();
    });

    render();
}());
