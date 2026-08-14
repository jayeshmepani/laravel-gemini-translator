(function () {
    'use strict';

    const root = document.querySelector('[data-translation-manager]');
    if (!root) {
        return;
    }

    const endpoints = {
        data: root.dataset.endpointData || '',
        languages: root.dataset.endpointLanguages || '',
        existing: root.dataset.endpointExisting || '',
        scan: root.dataset.endpointScan || '',
        save: root.dataset.endpointSave || '',
        addLanguages: root.dataset.endpointAddLanguages || '',
    };

    const csrf = root.dataset.csrf || '';
    const languageNames = parseJson(root.dataset.languageNames, {});
    const packMap = parseJson(root.dataset.packMap, {});
    const supportsDialog = typeof HTMLDialogElement !== 'undefined'
        && typeof HTMLDialogElement.prototype.showModal === 'function';
    const supportsPopover = typeof HTMLElement !== 'undefined'
        && typeof HTMLElement.prototype.showPopover === 'function';

    const els = {
        type: root.querySelector('[data-filter="type"]'),
        moduleWrap: root.querySelector('[data-module-filter]'),
        module: root.querySelector('[data-filter="module"]'),
        packWrap: root.querySelector('[data-pack-filter]'),
        pack: root.querySelector('[data-filter="pack"]'),
        fileWrap: root.querySelector('[data-file-filter]'),
        filesToggle: root.querySelector('[data-files-toggle]'),
        filesMenu: root.querySelector('[data-files-menu]'),
        filesSummary: root.querySelector('[data-files-summary]'),
        scope: root.querySelector('[data-filter="scope"]'),
        language: root.querySelector('[data-filter="language"]'),
        missing: root.querySelector('[data-filter="missing"]'),
        search: root.querySelector('[data-search]'),
        table: root.querySelector('[data-table]'),
        tableWrap: root.querySelector('[data-table-wrap]'),
        head: root.querySelector('[data-table-head]'),
        body: root.querySelector('[data-table-body]'),
        skeleton: root.querySelector('[data-skeleton]'),
        empty: root.querySelector('[data-empty]'),
        error: root.querySelector('[data-error]'),
        errorCopy: root.querySelector('[data-error-copy]'),
        retry: root.querySelector('[data-action="retry"]'),
        pager: root.querySelector('[data-pager]'),
        pagerMeta: root.querySelector('[data-pager-meta]'),
        pagerNav: root.querySelector('[data-pager-nav]'),
        save: root.querySelector('[data-action="save"]'),
        saveCount: root.querySelector('[data-save-count]'),
        sync: root.querySelector('[data-action="sync"]'),
        refresh: root.querySelector('[data-action="refresh"]'),
        addLangs: root.querySelector('[data-action="add-languages"]'),
        columnsToggle: root.querySelector('[data-action="columns"]'),
        columnsMenu: root.querySelector('[data-columns-menu]'),
        dialog: root.querySelector('[data-dialog]'),
        dialogGrid: root.querySelector('[data-language-grid]'),
        dialogClose: root.querySelectorAll('[data-dialog-close]'),
        dialogConfirm: root.querySelector('[data-action="confirm-languages"]'),
        toasts: root.querySelector('[data-toasts]'),
        pageSize: root.querySelector('[data-page-size]'),
        theme: root.querySelector('[data-action="theme"]'),
    };

    const THEME_KEY = 'gemini-translator-theme';

    const state = {
        type: els.type ? els.type.value : 'all',
        module: els.module ? els.module.value : 'all',
        pack: els.pack ? els.pack.value : 'all',
        files: [],
        scope: els.scope ? els.scope.value : 'all',
        language: els.language ? els.language.value : 'all',
        showOnlyMissing: els.missing ? els.missing.checked : false,
        search: '',
        sort: 'key',
        order: 'asc',
        limit: els.pageSize ? els.pageSize.value : '5',
        offset: 0,
        total: 0,
        rows: [],
        availableCodes: collectInitialCodes(),
        hiddenCodes: {},
        changes: [],
        loading: false,
        error: '',
        loadController: null,
    };

    function systemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function storedTheme() {
        try {
            const value = window.localStorage.getItem(THEME_KEY);
            return value === 'dark' || value === 'light' ? value : '';
        } catch (error) {
            return '';
        }
    }

    function currentTheme() {
        return storedTheme() || systemTheme();
    }

    function applyTheme(theme) {
        const resolved = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', resolved);
        document.documentElement.style.colorScheme = resolved;
        root.setAttribute('data-theme', resolved);
        if (document.body && document.body.classList.contains('translation-manager-body')) {
            document.body.setAttribute('data-theme', resolved);
        }

        const lightMeta = document.querySelector('[data-theme-color-light]');
        const darkMeta = document.querySelector('[data-theme-color-dark]');
        if (lightMeta) {
            lightMeta.setAttribute('content', resolved === 'dark' ? '#050505' : '#f1f5f9');
            lightMeta.removeAttribute('media');
        }
        if (darkMeta) {
            darkMeta.setAttribute('media', resolved === 'dark' ? 'all' : '(prefers-color-scheme: dark)');
        }

        if (els.theme) {
            els.theme.checked = resolved === 'dark';
            els.theme.setAttribute('aria-checked', resolved === 'dark' ? 'true' : 'false');
            els.theme.setAttribute('aria-label', resolved === 'dark' ? 'Use light theme' : 'Use dark theme');
        }
    }

    function parseJson(value, fallback) {
        if (!value) {
            return fallback;
        }
        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    function collectInitialCodes() {
        const codes = [];
        root.querySelectorAll('[data-language-code]').forEach((node) => {
            const code = node.getAttribute('data-language-code');
            if (code && !codes.includes(code)) {
                codes.push(code);
            }
        });
        return codes;
    }

    function langLabel(code) {
        const found = Object.keys(languageNames).find((name) => languageNames[name] === code);
        if (found) {
            return found;
        }
        return String(code).toUpperCase();
    }

    function visibleCodes() {
        const filtered = state.language !== 'all' ? [state.language] : state.availableCodes.slice();
        return filtered.filter((code) => !state.hiddenCodes[code]);
    }

    function changeKey(row, lang) {
        return [row.module || '', row.pack || '', row.scope || '', row.key, lang].join('\u0001');
    }

    function findChange(row, lang) {
        return state.changes.find((item) => (
            item.lang === lang
            && item.key === row.key
            && item.module === (row.module || '')
            && item.pack === (row.pack || '')
            && item.scope === (row.scope || '')
        ));
    }

    function packLabel(pack) {
        return pack ? 'lang/' + pack + '/' : 'lang/';
    }

    function packsForContext() {
        if (state.type === 'non-module') {
            return Array.isArray(packMap['']) ? packMap[''].slice() : [''];
        }
        if (state.type === 'module' && state.module !== 'all') {
            return Array.isArray(packMap[state.module]) ? packMap[state.module].slice() : [''];
        }
        return [''];
    }

    function packOptionValue(pack) {
        return pack === '' ? '__root__' : pack;
    }

    function syncPackFilter() {
        const packs = packsForContext();
        const scoped = state.type === 'non-module'
            || (state.type === 'module' && state.module !== 'all');
        const show = scoped && packs.length > 1;
        if (els.packWrap) {
            els.packWrap.classList.toggle('is-hidden', !show);
        }
        if (!els.pack) {
            return;
        }
        if (!show) {
            state.pack = 'all';
            els.pack.value = 'all';
            return;
        }
        const current = state.pack;
        const allowed = ['all'].concat(packs.map(packOptionValue));
        els.pack.innerHTML = '<option value="all">All packs</option>' + packs.map((pack) => {
            return '<option value="' + escapeHtml(packOptionValue(pack)) + '">' + escapeHtml(packLabel(pack)) + '</option>';
        }).join('');
        state.pack = allowed.includes(current) ? current : 'all';
        els.pack.value = state.pack;
    }

    function updateSaveBadge() {
        const count = state.changes.length;
        if (els.save) {
            els.save.disabled = count === 0;
            els.save.classList.toggle('is-pending', count > 0);
        }
        if (els.saveCount) {
            els.saveCount.textContent = String(count);
            els.saveCount.classList.toggle('is-visible', count > 0);
        }
    }

    function toast(message, tone) {
        if (!els.toasts) {
            return;
        }
        const item = document.createElement('div');
        item.className = 'manager-toast' + (tone ? ' manager-toast-' + tone : '');
        item.textContent = message;
        els.toasts.appendChild(item);
        window.setTimeout(() => item.remove(), 3600);
    }

    function autoResize(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 160) + 'px';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setHidden(node, hidden) {
        if (!node) {
            return;
        }
        node.hidden = Boolean(hidden);
        if (hidden) {
            node.setAttribute('aria-hidden', 'true');
        } else {
            node.removeAttribute('aria-hidden');
        }
    }

    function setView(mode, errorMessage) {
        const loading = mode === 'loading';
        const empty = mode === 'empty';
        const error = mode === 'error';
        const ready = mode === 'ready';

        if (els.tableWrap) {
            els.tableWrap.hidden = !ready && !loading;
            els.tableWrap.setAttribute('aria-busy', loading ? 'true' : 'false');
        }
        if (els.skeleton) {
            els.skeleton.hidden = !loading;
            els.skeleton.setAttribute('aria-hidden', 'true');
        }
        setHidden(els.empty, !empty);
        setHidden(els.error, !error);
        if (els.errorCopy && error) {
            els.errorCopy.textContent = errorMessage || 'The translations request failed.';
        }
        if (els.pager && !ready) {
            els.pager.hidden = true;
        }
    }

    async function request(url, options) {
        const headers = Object.assign({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        }, options && options.headers ? options.headers : {});
        if (csrf) {
            headers['X-CSRF-TOKEN'] = csrf;
        }
        const response = await fetch(url, Object.assign({}, options, { headers }));
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.error || payload.message || 'Request failed');
        }
        return payload;
    }

    function sortStateFor(field) {
        if (state.sort !== field) {
            return 'none';
        }
        return state.order === 'desc' ? 'descending' : 'ascending';
    }

    function renderHead() {
        if (!els.head) {
            return;
        }
        const codes = visibleCodes();
        let html = '<tr><th scope="col" class="manager-key-head" aria-sort="' + sortStateFor('key')
            + '"><button type="button" class="manager-sort" data-sort="key">Key</button></th>';
        codes.forEach((code) => {
            html += '<th scope="col" aria-sort="' + sortStateFor(code)
                + '"><button type="button" class="manager-sort" data-sort="'
                + escapeHtml(code) + '">' + escapeHtml(langLabel(code)) + '</button></th>';
        });
        html += '</tr>';
        els.head.innerHTML = html;
    }

    function renderBody() {
        if (!els.body) {
            return;
        }
        const codes = visibleCodes();
        if (state.rows.length === 0) {
            els.body.innerHTML = '';
            return;
        }
        els.body.innerHTML = state.rows.map((row) => {
            const pack = row.pack || '';
            const packMarkup = pack
                ? '<span class="manager-key-pack">' + escapeHtml(packLabel(pack)) + '</span>'
                : '';
            let cells = '<th scope="row" class="manager-key-cell"><span class="manager-key-stack">'
                + '<span class="manager-key-text">' + escapeHtml(row.key) + '</span>'
                + packMarkup + '</span></th>';
            codes.forEach((code) => {
                const change = findChange(row, code);
                const value = change ? change.value : (row[code] ?? '');
                const dirty = change ? ' is-changed' : '';
                cells += '<td><textarea class="manager-editor' + dirty + '" rows="1" data-editor'
                    + ' data-key="' + escapeHtml(row.key) + '"'
                    + ' data-lang="' + escapeHtml(code) + '"'
                    + ' data-module="' + escapeHtml(row.module || '') + '"'
                    + ' data-pack="' + escapeHtml(pack) + '"'
                    + ' data-scope="' + escapeHtml(row.scope || '') + '"'
                    + ' aria-label="' + escapeHtml(langLabel(code) + ' translation for ' + row.key) + '">'
                    + escapeHtml(value) + '</textarea></td>';
            });
            return '<tr>' + cells + '</tr>';
        }).join('');
        els.body.querySelectorAll('[data-editor]').forEach(autoResize);
    }

    function renderPager() {
        if (!els.pager || !els.pagerMeta || !els.pagerNav) {
            return;
        }
        if (state.total === 0 || state.error) {
            els.pager.hidden = true;
            return;
        }
        els.pager.hidden = false;
        const limit = state.limit === 'All' ? state.total : Number(state.limit);
        const start = state.total === 0 ? 0 : state.offset + 1;
        const end = state.limit === 'All' ? state.total : Math.min(state.offset + limit, state.total);
        els.pagerMeta.textContent = 'Showing ' + start + ' to ' + end + ' of ' + state.total + ' rows';
        const pages = state.limit === 'All' ? 1 : Math.max(1, Math.ceil(state.total / limit));
        const current = state.limit === 'All' ? 1 : Math.floor(state.offset / limit) + 1;
        els.pagerNav.innerHTML = '';

        const goTo = (page) => {
            const nextPage = Math.min(pages, Math.max(1, page));
            state.offset = (nextPage - 1) * (state.limit === 'All' ? state.total : Number(state.limit) || 1);
            loadRows();
        };

        const chevron = (direction) => {
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '2.25');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('focusable', 'false');
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', direction === 'prev' ? 'M14.5 6 8.5 12l6 6' : 'M9.5 6 15.5 12l-6 6');
            svg.append(path);
            return svg;
        };

        const makeButton = (label, options) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'manager-page-button' + (options.current ? ' is-current' : '');
            if (options.current) {
                button.setAttribute('aria-current', 'page');
            }
            button.disabled = Boolean(options.disabled);
            button.setAttribute('aria-label', options.ariaLabel);
            if (options.icon) {
                button.append(chevron(options.icon));
            } else {
                button.textContent = label;
            }
            if (!options.disabled && !options.current && options.page) {
                button.addEventListener('click', () => goTo(options.page));
            }
            return button;
        };

        els.pagerNav.append(makeButton('', {
            icon: 'prev',
            page: current - 1,
            disabled: current <= 1,
            ariaLabel: 'Previous page',
        }));

        pageItems(current, pages).forEach((item) => {
            if (item === 'ellipsis') {
                const gap = document.createElement('span');
                gap.className = 'manager-page-ellipsis';
                gap.setAttribute('aria-hidden', 'true');
                gap.textContent = '…';
                els.pagerNav.append(gap);
                return;
            }
            els.pagerNav.append(makeButton(String(item), {
                page: item,
                current: item === current,
                ariaLabel: item === current ? 'Page ' + item + ', current page' : 'Page ' + item,
            }));
        });

        els.pagerNav.append(makeButton('', {
            icon: 'next',
            page: current + 1,
            disabled: current >= pages,
            ariaLabel: 'Next page',
        }));
    }

    function pageItems(current, pages) {
        if (pages <= 7) {
            return Array.from({ length: pages }, (_, index) => index + 1);
        }

        const marks = new Set();
        if (current <= 3 || current >= pages - 2) {
            marks.add(1);
            marks.add(2);
            marks.add(3);
        } else {
            marks.add(1);
            marks.add(current - 1);
            marks.add(current);
            marks.add(current + 1);
        }
        if (current >= pages - 2) {
            marks.add(pages - 2);
        }
        marks.add(pages - 1);
        marks.add(pages);

        const sorted = [...marks].filter((page) => page >= 1 && page <= pages).sort((left, right) => left - right);
        const items = [];
        sorted.forEach((page, index) => {
            if (index > 0) {
                const gap = page - sorted[index - 1];
                if (gap === 2) {
                    items.push(sorted[index - 1] + 1);
                } else if (gap > 2) {
                    items.push('ellipsis');
                }
            }
            items.push(page);
        });

        return items;
    }

    function updateFilesSummary() {
        if (!els.filesSummary) {
            return;
        }
        if (state.files.length === 0) {
            els.filesSummary.textContent = 'All PHP files';
            return;
        }
        if (state.files.length === 1) {
            els.filesSummary.textContent = state.files[0];
            return;
        }
        els.filesSummary.textContent = state.files.length + ' files';
    }

    function rebuildFilesMenu(files) {
        if (!els.filesMenu) {
            return;
        }
        const allowed = Array.isArray(files) ? files.filter((file) => typeof file === 'string' && file !== '') : [];
        state.files = state.files.filter((file) => allowed.includes(file));
        if (allowed.length === 0) {
            els.filesMenu.innerHTML = '<p class="manager-empty-copy">No PHP language files found.</p>';
        } else {
            els.filesMenu.innerHTML = allowed.map((file) => {
                const checked = state.files.includes(file) ? ' checked' : '';
                return '<label class="manager-check"><input type="checkbox" data-file-toggle value="'
                    + escapeHtml(file) + '"' + checked + '><span>' + escapeHtml(file) + '</span></label>';
            }).join('');
        }
        updateFilesSummary();
    }

    function syncFileFilter() {
        const show = state.scope === 'php'
            && (state.type === 'non-module' || (state.type === 'module' && state.module !== 'all'));
        if (els.fileWrap) {
            els.fileWrap.classList.toggle('is-hidden', !show);
        }
        if (!show && state.files.length) {
            state.files = [];
            if (els.filesMenu) {
                els.filesMenu.querySelectorAll('[data-file-toggle]').forEach((input) => {
                    input.checked = false;
                });
            }
        }
        updateFilesSummary();
    }

    function renderColumnsMenu() {
        if (!els.columnsMenu) {
            return;
        }
        els.columnsMenu.innerHTML = state.availableCodes.map((code) => {
            const checked = !state.hiddenCodes[code] ? ' checked' : '';
            return '<label class="manager-check"><input type="checkbox" data-column-toggle value="'
                + escapeHtml(code) + '"' + checked + '><span>' + escapeHtml(langLabel(code)) + '</span></label>';
        }).join('');
    }

    function renderAll() {
        renderHead();
        renderBody();
        renderPager();
        renderColumnsMenu();
        updateSaveBadge();

        if (state.loading) {
            setView('loading');
            return;
        }
        if (state.error) {
            setView('error', state.error);
            return;
        }
        if (!endpoints.data) {
            setView('error', 'No data endpoint is configured. Pass $endpoints[\'data\'] from your controller, or publish the manager and wire the API routes.');
            return;
        }
        if (state.rows.length === 0) {
            setView('empty');
            return;
        }
        setView('ready');
    }

    async function loadLanguages() {
        if (!endpoints.languages && !endpoints.existing) {
            return;
        }
        try {
            const jobs = [];
            if (endpoints.languages) {
                jobs.push(request(endpoints.languages));
            } else {
                jobs.push(Promise.resolve(languageNames));
            }
            if (endpoints.existing) {
                jobs.push(request(endpoints.existing));
            } else {
                jobs.push(Promise.resolve(null));
            }
            const [langMap, existing] = await Promise.all(jobs);
            Object.assign(languageNames, langMap || {});
            const codes = new Set(state.availableCodes);
            if (existing && typeof existing === 'object') {
                Object.values(existing).forEach((scopes) => {
                    Object.values(scopes || {}).forEach((langs) => {
                        Object.keys(langs || {}).forEach((code) => codes.add(code));
                    });
                });
            }
            state.availableCodes = Array.from(codes).sort();
        } catch (error) {
            toast(error.message || 'Failed to load languages.', 'error');
        }
    }

    async function loadRows() {
        if (!endpoints.data) {
            renderAll();
            return;
        }
        if (state.loadController) {
            state.loadController.abort();
        }
        const controller = new AbortController();
        state.loadController = controller;
        state.loading = true;
        state.error = '';
        renderAll();
        const params = new URLSearchParams();
        params.set('type', state.type);
        params.set('module', state.module);
        params.set('pack', state.pack);
        params.set('scope', state.scope);
        state.files.forEach((file) => params.append('files[]', file));
        params.set('language', state.language);
        params.set('showOnlyMissing', state.showOnlyMissing ? '1' : '0');
        params.set('search', state.search);
        params.set('sort', state.sort);
        params.set('order', state.order);
        params.set('limit', state.limit);
        params.set('offset', String(state.offset));
        try {
            const payload = await request(
                endpoints.data + (endpoints.data.includes('?') ? '&' : '?') + params.toString(),
                { signal: controller.signal },
            );
            if (controller.signal.aborted) {
                return;
            }
            state.total = Number(payload.total || 0);
            state.rows = Array.isArray(payload.rows) ? payload.rows : [];
            if (Object.prototype.hasOwnProperty.call(payload, 'files')) {
                rebuildFilesMenu(payload.files);
            }
            state.rows.forEach((row) => {
                Object.keys(row).forEach((key) => {
                    if (!['key', 'module', 'pack', 'scope', 'file'].includes(key) && !state.availableCodes.includes(key)) {
                        state.availableCodes.push(key);
                    }
                });
            });
            state.availableCodes.sort();
            state.error = '';
        } catch (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            state.rows = [];
            state.total = 0;
            state.error = error.message || 'Could not fetch translations.';
            toast(state.error, 'error');
        } finally {
            if (state.loadController === controller) {
                state.loading = false;
                renderAll();
            }
        }
    }

    function trackEditor(textarea) {
        const row = {
            key: textarea.dataset.key,
            module: textarea.dataset.module || '',
            pack: textarea.dataset.pack || '',
            scope: textarea.dataset.scope || '',
        };
        const lang = textarea.dataset.lang;
        const value = textarea.value;
        const idx = state.changes.findIndex((item) => changeKey(item, item.lang) === changeKey({
            module: row.module,
            pack: row.pack,
            scope: row.scope,
            key: row.key,
        }, lang));
        const original = (state.rows.find((item) => (
            item.key === row.key
            && (item.module || '') === row.module
            && (item.pack || '') === row.pack
            && (item.scope || '') === row.scope
        )) || {})[lang] ?? '';
        if (value === original) {
            if (idx > -1) {
                state.changes.splice(idx, 1);
            }
            textarea.classList.remove('is-changed');
        } else if (idx > -1) {
            state.changes[idx].value = value;
            textarea.classList.add('is-changed');
        } else {
            state.changes.push({
                lang,
                module: row.module,
                pack: row.pack,
                scope: row.scope,
                key: row.key,
                value,
            });
            textarea.classList.add('is-changed');
        }
        updateSaveBadge();
    }

    function openDialog() {
        if (!els.dialog || !els.dialogGrid) {
            return;
        }
        const pairs = Object.entries(languageNames).sort((a, b) => a[0].localeCompare(b[0]));
        const catalog = pairs.length ? pairs : state.availableCodes.map((code) => [langLabel(code), code]);
        els.dialogGrid.innerHTML = catalog.map(([name, code]) => {
            const exists = state.availableCodes.includes(code);
            return '<label class="manager-language-option"><input type="checkbox" data-add-lang value="'
                + escapeHtml(code) + '"' + (exists ? ' checked disabled' : '') + '><span>'
                + escapeHtml(name) + ' (' + escapeHtml(code) + ')</span></label>';
        }).join('') || '<p class="manager-empty-copy">No language catalog was provided.</p>';

        if (supportsDialog) {
            if (!els.dialog.open) {
                els.dialog.showModal();
            }
        } else {
            els.dialog.setAttribute('open', '');
        }
        const closeBtn = els.dialog.querySelector('[data-dialog-close]');
        if (closeBtn) {
            closeBtn.focus();
        }
    }

    function closeDialog() {
        if (!els.dialog) {
            return;
        }
        if (supportsDialog && els.dialog.open) {
            els.dialog.close();
            return;
        }
        els.dialog.removeAttribute('open');
    }

    function bind() {
        if (els.type) {
            els.type.addEventListener('change', () => {
                state.type = els.type.value;
                state.offset = 0;
                if (els.moduleWrap) {
                    els.moduleWrap.classList.toggle('is-hidden', state.type !== 'module');
                }
                syncPackFilter();
                syncFileFilter();
                loadRows();
            });
        }
        if (els.module) {
            els.module.addEventListener('change', () => {
                state.module = els.module.value;
                state.offset = 0;
                syncPackFilter();
                syncFileFilter();
                loadRows();
            });
        }
        if (els.pack) {
            els.pack.addEventListener('change', () => {
                state.pack = els.pack.value;
                state.offset = 0;
                syncFileFilter();
                loadRows();
            });
        }
        if (els.scope) {
            els.scope.addEventListener('change', () => {
                state.scope = els.scope.value;
                state.offset = 0;
                syncFileFilter();
                loadRows();
            });
        }
        if (els.language) {
            els.language.addEventListener('change', () => {
                state.language = els.language.value;
                state.offset = 0;
                loadRows();
            });
        }
        if (els.missing) {
            els.missing.addEventListener('change', () => {
                state.showOnlyMissing = els.missing.checked;
                state.offset = 0;
                loadRows();
            });
        }
        let searchTimer;
        if (els.search) {
            els.search.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(() => {
                    state.search = els.search.value.trim();
                    state.offset = 0;
                    loadRows();
                }, 250);
            });
        }
        if (els.pageSize) {
            els.pageSize.addEventListener('change', () => {
                state.limit = els.pageSize.value;
                state.offset = 0;
                loadRows();
            });
        }
        if (els.head) {
            els.head.addEventListener('click', (event) => {
                const button = event.target.closest('[data-sort]');
                if (!button) {
                    return;
                }
                const field = button.getAttribute('data-sort');
                if (state.sort === field) {
                    state.order = state.order === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sort = field;
                    state.order = 'asc';
                }
                loadRows();
            });
        }
        if (els.body) {
            els.body.addEventListener('input', (event) => {
                const editor = event.target.closest('[data-editor]');
                if (!editor) {
                    return;
                }
                autoResize(editor);
                trackEditor(editor);
            });
        }
        if (els.theme) {
            els.theme.addEventListener('change', () => {
                const next = els.theme.checked ? 'dark' : 'light';
                try {
                    window.localStorage.setItem(THEME_KEY, next);
                } catch (error) {}
                applyTheme(next);
            });
        }
        if (window.matchMedia) {
            const media = window.matchMedia('(prefers-color-scheme: dark)');
            const onSchemeChange = (event) => {
                if (!storedTheme()) {
                    applyTheme(event.matches ? 'dark' : 'light');
                }
            };
            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', onSchemeChange);
            }
        }
        if (els.refresh) {
            els.refresh.addEventListener('click', () => loadRows());
        }
        if (els.retry) {
            els.retry.addEventListener('click', () => loadRows());
        }
        if (els.sync) {
            els.sync.addEventListener('click', async () => {
                if (!endpoints.scan) {
                    toast('No scan endpoint is configured.', 'error');
                    return;
                }
                els.sync.disabled = true;
                toast('Scanning project files…', 'info');
                try {
                    const res = await request(endpoints.scan, { method: 'POST' });
                    toast(res.message || 'Sync complete.', 'success');
                    await loadRows();
                } catch (error) {
                    toast(error.message || 'Sync failed.', 'error');
                } finally {
                    els.sync.disabled = false;
                }
            });
        }
        if (els.save) {
            els.save.addEventListener('click', async () => {
                if (state.changes.length === 0) {
                    return;
                }
                if (!endpoints.save) {
                    toast('No save endpoint is configured.', 'error');
                    return;
                }
                els.save.disabled = true;
                try {
                    const res = await request(endpoints.save, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ changes: state.changes }),
                    });
                    state.changes = [];
                    updateSaveBadge();
                    toast(res.message || 'Translations saved.', 'success');
                    await loadRows();
                } catch (error) {
                    toast(error.message || 'Failed to save changes.', 'error');
                    updateSaveBadge();
                }
            });
        }
        if (els.addLangs) {
            els.addLangs.addEventListener('click', openDialog);
        }
        els.dialogClose.forEach((button) => button.addEventListener('click', closeDialog));
        if (els.dialog) {
            els.dialog.addEventListener('cancel', () => {
                closeDialog();
            });
        }
        if (els.dialogConfirm) {
            els.dialogConfirm.addEventListener('click', async () => {
                const codes = Array.from(root.querySelectorAll('[data-add-lang]:checked:not(:disabled)')).map((input) => input.value);
                if (codes.length === 0) {
                    closeDialog();
                    return;
                }
                if (!endpoints.addLanguages) {
                    toast('No add-languages endpoint is configured.', 'error');
                    return;
                }
                els.dialogConfirm.disabled = true;
                try {
                    const res = await request(endpoints.addLanguages, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ languages: codes }),
                    });
                    toast(res.message || 'Languages added.', 'success');
                    window.location.reload();
                } catch (error) {
                    toast(error.message || 'Could not add languages.', 'error');
                } finally {
                    els.dialogConfirm.disabled = false;
                    closeDialog();
                }
            });
        }
        if (els.filesToggle && els.filesMenu) {
            const placeFilesMenu = () => {
                if (!els.filesMenu.hasAttribute('popover')) {
                    return;
                }
                const button = els.filesToggle.getBoundingClientRect();
                els.filesMenu.style.position = 'fixed';
                els.filesMenu.style.inset = 'auto';
                els.filesMenu.style.margin = '0';
                els.filesMenu.style.top = Math.round(button.bottom + 4) + 'px';
                els.filesMenu.style.left = Math.round(button.left) + 'px';
                els.filesMenu.style.right = 'auto';
                els.filesMenu.style.bottom = 'auto';
            };
            if (supportsPopover) {
                els.filesMenu.addEventListener('toggle', (event) => {
                    const open = event.newState ? event.newState === 'open' : els.filesMenu.matches(':popover-open');
                    els.filesToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (open) {
                        placeFilesMenu();
                    }
                });
                window.addEventListener('resize', () => {
                    if (els.filesMenu.matches(':popover-open')) {
                        placeFilesMenu();
                    }
                });
            } else {
                els.filesMenu.removeAttribute('popover');
                els.filesToggle.removeAttribute('popovertarget');
                els.filesToggle.addEventListener('click', () => {
                    const open = !els.filesMenu.classList.contains('is-open');
                    els.filesMenu.classList.toggle('is-open', open);
                    els.filesToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            }
            els.filesMenu.addEventListener('change', (event) => {
                const input = event.target.closest('[data-file-toggle]');
                if (!input) {
                    return;
                }
                if (input.checked) {
                    if (!state.files.includes(input.value)) {
                        state.files.push(input.value);
                    }
                } else {
                    state.files = state.files.filter((file) => file !== input.value);
                }
                state.files.sort();
                state.offset = 0;
                updateFilesSummary();
                loadRows();
            });
        }
        if (els.columnsToggle && els.columnsMenu) {
            const placeColumnsMenu = () => {
                if (!els.columnsMenu.hasAttribute('popover')) {
                    return;
                }
                const button = els.columnsToggle.getBoundingClientRect();
                const gap = 4;
                els.columnsMenu.style.position = 'fixed';
                els.columnsMenu.style.inset = 'auto';
                els.columnsMenu.style.margin = '0';
                els.columnsMenu.style.top = Math.round(button.bottom + gap) + 'px';
                els.columnsMenu.style.right = Math.round(Math.max(8, window.innerWidth - button.right)) + 'px';
                els.columnsMenu.style.left = 'auto';
                els.columnsMenu.style.bottom = 'auto';
            };

            if (supportsPopover) {
                els.columnsMenu.addEventListener('toggle', (event) => {
                    const open = event.newState ? event.newState === 'open' : els.columnsMenu.matches(':popover-open');
                    els.columnsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (open) {
                        placeColumnsMenu();
                    }
                });
                window.addEventListener('resize', () => {
                    if (els.columnsMenu.matches(':popover-open')) {
                        placeColumnsMenu();
                    }
                });
                document.addEventListener('scroll', () => {
                    if (els.columnsMenu.matches(':popover-open')) {
                        placeColumnsMenu();
                    }
                }, true);
            } else {
                els.columnsMenu.removeAttribute('popover');
                els.columnsToggle.removeAttribute('popovertarget');
                els.columnsToggle.addEventListener('click', () => {
                    const open = !els.columnsMenu.classList.contains('is-open');
                    els.columnsMenu.classList.toggle('is-open', open);
                    els.columnsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                document.addEventListener('click', (event) => {
                    if (!els.columnsMenu.contains(event.target) && event.target !== els.columnsToggle && !els.columnsToggle.contains(event.target)) {
                        els.columnsMenu.classList.remove('is-open');
                        els.columnsToggle.setAttribute('aria-expanded', 'false');
                    }
                });
            }
            els.columnsMenu.addEventListener('change', (event) => {
                const input = event.target.closest('[data-column-toggle]');
                if (!input) {
                    return;
                }
                state.hiddenCodes[input.value] = !input.checked;
                renderAll();
            });
        }
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && els.dialog && (els.dialog.open || els.dialog.hasAttribute('open'))) {
                closeDialog();
            }
        });
        syncPackFilter();
        syncFileFilter();
    }

    async function init() {
        applyTheme(currentTheme());
        bind();
        updateSaveBadge();
        await loadLanguages();
        await loadRows();
    }

    init();
})();
