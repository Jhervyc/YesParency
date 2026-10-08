/* ── AjaxList ──────────────────────────────────────────────────────────────
   Filter / search / paginate a server-rendered list in place.

   The page must return only the list fragment when called with ?ajax=1.
   The URL is kept in sync with history.replaceState (no new history
   entries), so Back leaves the page and refresh keeps the filter.

   AjaxList.init({
       root:     '#procListRoot',     // element whose innerHTML is swapped
       form:     '#procFilterForm',   // filter form (inputs, selects, buttons)
       endpoint: 'procurement.php',   // defaults to the current path
   });

   - Text inputs: debounced live search (and Enter / submit button).
   - Selects: reload on change.
   - <button name="x" value="y"> in the form: acts as a tab — sets x=y and
     toggles .active among buttons with the same name.
   - Pagination: any a[data-page] inside root.
   - Fires 'ajaxlist:updated' on root after each swap.
   ───────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    function init(opts) {
        const root = typeof opts.root === 'string' ? document.querySelector(opts.root) : opts.root;
        const form = typeof opts.form === 'string' ? document.querySelector(opts.form) : opts.form;
        if (!root || !form) return;

        // Fetch the page's own current URL. The root .htaccess 301s "page.php"
        // to the clean "page", so an explicit 'page.php' endpoint for this same
        // page would cost a redirect on every request; only use opts.endpoint
        // when it points at a different page.
        const baseName = u => u.split('?')[0].split('/').pop().replace(/\.php$/, '');
        const endpoint = opts.endpoint && baseName(opts.endpoint) !== baseName(location.pathname)
            ? opts.endpoint
            : location.pathname;
        const delay    = opts.debounce ?? 400;

        // Lets CSS drop the (now redundant) Search button on phones
        form.classList.add('ajax-list-ready');

        // Tab-button state (name -> value), seeded from the .active buttons
        const tabs = {};
        form.querySelectorAll('button[name].active').forEach(b => { tabs[b.name] = b.value; });

        let page       = parseInt(new URLSearchParams(location.search).get('page'), 10) || 1;
        let controller = null;
        let timer      = null;

        function buildParams() {
            const params = new URLSearchParams();
            new FormData(form).forEach((v, k) => {
                v = String(v).trim();
                if (v !== '') params.set(k, v);
            });
            Object.entries(tabs).forEach(([k, v]) => { if (v !== '') params.set(k, v); });
            if (page > 1) params.set('page', page);
            return params;
        }

        function load() {
            clearTimeout(timer);
            if (controller) controller.abort();
            controller = new AbortController();

            const params = buildParams();
            const qs     = params.toString();
            const ajaxQs = new URLSearchParams(params);
            ajaxQs.set('ajax', '1');

            root.classList.add('is-loading');
            root.setAttribute('aria-busy', 'true');

            fetch(endpoint + '?' + ajaxQs.toString(), {
                signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(html => {
                    root.innerHTML = html;
                    history.replaceState(history.state, '', location.pathname + (qs ? '?' + qs : ''));
                    root.classList.remove('is-loading');
                    root.removeAttribute('aria-busy');
                    root.dispatchEvent(new CustomEvent('ajaxlist:updated', { bubbles: true }));
                })
                .catch(err => {
                    if (err.name === 'AbortError') return;
                    root.classList.remove('is-loading');
                    root.removeAttribute('aria-busy');
                    showError();
                });
        }

        function showError() {
            let el = root.querySelector('.ajax-list-error');
            if (!el) {
                el = document.createElement('div');
                el.className = 'ajax-list-error';
                root.prepend(el);
            }
            el.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Could not load the list. Please try again.';
        }

        // On phones the tab row scrolls sideways — keep the active tab in view.
        // (Horizontal only; scrollIntoView would also scroll the page.)
        function centerTab(btn) {
            const row = btn.parentElement;
            if (!row || row.scrollWidth <= row.clientWidth) return;
            const offset = btn.getBoundingClientRect().left - row.getBoundingClientRect().left;
            const left   = row.scrollLeft + offset - (row.clientWidth - btn.offsetWidth) / 2;
            row.scrollTo({ left: Math.max(0, left), behavior: 'smooth' });
        }
        form.querySelectorAll('button[name].active').forEach(centerTab);

        // Tab buttons + submit button
        form.addEventListener('submit', e => {
            e.preventDefault();
            const btn = e.submitter;
            if (btn && btn.name) {
                tabs[btn.name] = btn.value;
                form.querySelectorAll('button[name="' + btn.name + '"]').forEach(b => {
                    b.classList.toggle('active', b === btn);
                });
                centerTab(btn);
            }
            page = 1;
            load();
        });

        // Live search on text inputs
        form.addEventListener('input', e => {
            if (!e.target.matches('input[type="text"], input[type="search"], input:not([type])')) return;
            clearTimeout(timer);
            timer = setTimeout(() => { page = 1; load(); }, delay);
        });

        // Enter in a text field: implicit submit would use the form's first
        // button (e.g. the "All" tab) as submitter, so handle it here instead.
        form.addEventListener('keydown', e => {
            if (e.key !== 'Enter' || !e.target.matches('input')) return;
            e.preventDefault();
            page = 1;
            load();
        });

        // Selects / dates
        form.addEventListener('change', e => {
            if (!e.target.matches('select, input[type="date"], input[type="checkbox"], input[type="radio"]')) return;
            page = 1;
            load();
        });

        // Pagination
        root.addEventListener('click', e => {
            const a = e.target.closest('a[data-page]');
            if (!a || !root.contains(a)) return;
            e.preventDefault();
            if (a.classList.contains('disabled') || a.classList.contains('active')) return;
            page = parseInt(a.dataset.page, 10) || 1;
            load();
            const top = root.getBoundingClientRect().top;
            if (top < 0) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        return { reload: load };
    }

    /* ── In-place POST ──────────────────────────────────────────────────────
       <form method="POST" data-post-inplace> (anywhere on the page, including
       inside a swapped list): submits with fetch instead of navigating, then
       reloads the current URL with location.replace — so the action doesn't
       add a history entry and the current filter/page is kept.
       redirect:'manual' stops fetch following the server's PRG redirect, so
       the flash message set in $_SESSION is still there for the reload.
       Falls back to a normal submit if fetch fails. */
    document.addEventListener('submit', e => {
        const form = e.target;
        if (!form.matches('form[data-post-inplace]') || e.defaultPrevented) return;
        e.preventDefault();

        const data = new FormData(form);
        if (e.submitter && e.submitter.name) data.append(e.submitter.name, e.submitter.value);
        form.querySelectorAll('button, input[type="submit"]').forEach(b => { b.disabled = true; });

        // form.action resolves action="" to the current (replaceState'd) URL
        // Use the raw action attribute (not form.action DOM property) so that
        // history.replaceState clean URLs don't break the POST target.
        const rawAction = form.getAttribute('action');
        const target = rawAction
            ? new URL(rawAction, document.baseURI).href
            : (location.origin + location.pathname.replace(/(?:\.php)?$/, '.php') + location.search);
        fetch(target, {
            method: 'POST', body: data, credentials: 'same-origin', redirect: 'manual',
        })
            .then(() => location.replace(location.href))
            .catch(() => { form.removeAttribute('data-post-inplace'); HTMLFormElement.prototype.submit.call(form); });
    });

    window.AjaxList = { init };
})();
