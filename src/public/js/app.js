// MoeRNG - Frontend JavaScript

// Toast notifications. Default lifetime is 5s; clicking a toast dismisses it
// early. The lifetime is deliberately generous — admin mutations (category
// create/update/delete) reload the page after a short delay, and we want the
// success message to stay readable instead of being wiped by the reload.
window.showToast = function(message, type = 'info', duration = 5000) {
    const container = document.querySelector('.toast-container') || createToastContainer();
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    toast.style.cursor = 'pointer';
    container.appendChild(toast);
    const timer = setTimeout(() => { toast.remove(); }, duration);
    toast.addEventListener('click', () => { clearTimeout(timer); toast.remove(); });
};

function createToastContainer() {
    const container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
    return container;
}

// Modal
window.openModal = function(id) {
    document.getElementById(id).classList.add('active');
    // v1.1.1-beta.2: lock background scroll while a modal is open.
    document.body.classList.add('modal-open');
};
window.closeModal = function(id) {
    document.getElementById(id).classList.remove('active');
    // v1.1.1-beta.2: release scroll lock when no overlay stays open.
    if (!document.querySelector('.modal-overlay.active')) {
        document.body.classList.remove('modal-open');
    }
};

// Close modal on overlay click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
        if (!document.querySelector('.modal-overlay.active')) {
            document.body.classList.remove('modal-open');
        }
    }
});

// v1.1.1-beta.2: ESC closes the topmost open modal.
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var open = document.querySelector('.modal-overlay.active');
        if (open) {
            open.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.classList.remove('modal-open');
            }
        }
    }
});

// ---------------------------------------------------------------------------
// Shared AJAX helper
// ---------------------------------------------------------------------------
// Every admin mutation goes through here. The X-Requested-With header is what
// tells the backend to answer with JSON instead of a 302, which is why actions
// used to navigate the browser to a bare JSON document.
window.adminPost = async function(url, data) {
    const body = new FormData();
    body.append('_csrf_token', getCsrfToken());

    Object.keys(data || {}).forEach(key => {
        const value = data[key];
        if (Array.isArray(value)) {
            value.forEach(v => body.append(key.endsWith('[]') ? key : key + '[]', v));
        } else if (value !== undefined && value !== null) {
            body.append(key, value);
        }
    });

    const resp = await fetch(url, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    });

    let payload = null;
    try { payload = await resp.json(); } catch (e) { payload = null; }

    if (!resp.ok || !payload || payload.success !== true) {
        const msg = (payload && (payload.error || payload.message)) || ('请求失败（HTTP ' + resp.status + '）');
        throw new Error(msg);
    }
    return payload;
};

// Clipboard with a fallback for non-secure contexts (plain http admin panels).
window.copyText = async function(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try { await navigator.clipboard.writeText(text); return true; } catch (e) { /* fall through */ }
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    return ok;
};

// API Tester
function initApiTester() {
    const tester = document.getElementById('api-tester');
    if (!tester) return;

    const categorySelect = document.getElementById('test-category');
    const typeSelect = document.getElementById('test-type');
    const runBtn = document.getElementById('test-run');
    const resultBox = document.getElementById('test-result');
    const urlDisplay = document.getElementById('test-url');
    const curlDisplay = document.getElementById('test-curl');

    function updateUrl() {
        const category = categorySelect.value;
        const type = typeSelect.value;
        const base = window.location.origin + '/api/v1/random';
        const params = new URLSearchParams();
        if (category) params.set('category', category);
        if (type) params.set('type', type);
        const url = base + (params.toString() ? '?' + params.toString() : '');
        urlDisplay.textContent = url;
        curlDisplay.textContent = 'curl -H "X-API-Key: YOUR_API_KEY" "' + url + '"';
    }

    categorySelect.addEventListener('change', updateUrl);
    typeSelect.addEventListener('change', updateUrl);
    updateUrl();

    runBtn.addEventListener('click', async function() {
        runBtn.disabled = true;
        runBtn.textContent = 'Loading...';
        resultBox.innerHTML = '<div class="spinner"></div>';

        const category = categorySelect.value;
        const type = typeSelect.value;
        const params = new URLSearchParams();
        if (category) params.set('category', category);
        if (type) params.set('type', type);

        try {
            const apiPath = '/api/v1/random?' + params.toString();

            if (type === 'redirect') {
                // fetch() following a 302 to a cross-origin object-storage URL
                // (e.g. COS bucket) blows up with "Failed to fetch" when the
                // bucket doesn't serve CORS headers — yet the image itself
                // loads fine in <img>. So we test the redirect target as an
                // Image element: it auto-follows the 302, doesn't require
                // CORS for the <img> render path, and correctly reports
                // load/error so the tester reflects real-world usability.
                const tester = new Image();
                tester.alt = 'Random Image';
                tester.style.cssText = 'max-width:100%;max-height:400px;object-fit:contain;';
                tester.onload = function() {
                    // Success path: render the image at the URL the server
                    // 302-redirected to. We intentionally do NOT surface the
                    // raw API path here — the operator already sees the URL
                    // they're testing in the "请求 URL" field above, so a
                    // duplicate under the image is just noise.
                    tester.removeAttribute('style');
                    tester.style.cssText = 'max-width:100%;max-height:400px;object-fit:contain;display:block;margin:0 auto;';
                    resultBox.innerHTML = '';
                    resultBox.appendChild(tester);
                };
                tester.onerror = function() {
                    resultBox.innerHTML =
                        '<pre style="color:var(--danger)">重定向目标无法加载（目标地址不可达或返回非图片）。'
                        + '\n这是一个客户端 CORS 探测限制 —— 在 curl / 服务器端 HTTP 客户端中跟随 302 是正常的，'
                        + '浏览器 fetch 在跨域时会拦截（Failed to fetch）。'
                        + '\n本测试改用 img 探测，已绕开此限制。'
                        + '\n\n请求 URL：' + apiPath + '</pre>';
                };
                tester.src = apiPath;
            } else {
                const resp = await fetch(apiPath);
                const data = await resp.json();
                resultBox.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            }
        } catch(e) {
            resultBox.innerHTML = '<pre style="color:var(--danger)">Error: ' + e.message + '</pre>';
        }

        runBtn.disabled = false;
        runBtn.textContent = 'Send Request';
    });
}

// Image grid: selection, preview and AJAX deletion
function initImageGrid() {
    const grid = document.querySelector('.image-grid');
    if (!grid) return;

    // v1.2.0 迭代: cross-page selection — a global Set survives pagination
    // and filtering, so 全选/批量删除 can span every page, not just the
    // visible one. The DOM class only mirrors what's visible now.
    if (!window.__imgSel) window.__imgSel = new Set();
    window.__imgTotal = parseInt(document.getElementById('image-total')?.dataset.total || '0', 10) || 0;
    const selectedIds = () => Array.from(window.__imgSel);

    function syncSetFromDom() {
        window.__imgSel = new Set(
            Array.from(grid.querySelectorAll('.image-item.selected')).map(el => el.dataset.id)
        );
    }
    function syncDomFromSet() {
        grid.querySelectorAll('.image-item').forEach(el => {
            el.classList.toggle('selected', window.__imgSel.has(el.dataset.id));
        });
    }

    function updateBatchBar() {
        const bar = document.getElementById('batch-bar');
        if (!bar) return;
        const count = window.__imgSel.size;
        bar.classList.toggle('hidden', count === 0);
        const label = bar.querySelector('.count');
        if (label) label.textContent = count;
        // v1.2.0 迭代: button text stays "全选"; the toast shows how many were
        // selected, so no dynamic label needed here.
    }
    window.updateBatchBar = updateBatchBar;

    grid.addEventListener('click', function(e) {
        const actionBtn = e.target.closest('[data-image-action]');
        if (actionBtn) {
            e.stopPropagation();
            e.preventDefault();
            const item = actionBtn.closest('.image-item');
            if (!item) return;
            if (actionBtn.dataset.imageAction === 'view') {
                return; // handled by initLightbox — do NOT toggle selection
            }
            if (actionBtn.dataset.imageAction === 'preview') {
                openPreview(item.dataset.url, item.dataset.name);
            } else if (actionBtn.dataset.imageAction === 'delete') {
                deleteImages([item.dataset.id], item.dataset.name);
            }
            return;
        }

        const item = e.target.closest('.image-item');
        if (!item) return;
        item.classList.toggle('selected');
        if (item.classList.contains('selected')) {
            window.__imgSel.add(item.dataset.id);
        } else {
            window.__imgSel.delete(item.dataset.id);
        }
        updateBatchBar();
    });

    grid.addEventListener('dblclick', function(e) {
        const item = e.target.closest('.image-item');
        if (!item) return;
        // A double click also fired two single-click toggles; undo the second.
        item.classList.remove('selected');
        window.__imgSel.delete(item.dataset.id);
        updateBatchBar();
        // v1.0.32: double-click opens the fullscreen lightbox.
        if (window.openLightbox) {
            const all = Array.from(grid.querySelectorAll('.image-item'));
            window.openLightbox(all.indexOf(item));
        } else {
            openPreview(item.dataset.url, item.dataset.name);
        }
    });

    // Broken thumbnails should be obvious rather than silently blank.
    grid.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', function() {
            this.closest('.image-item')?.classList.add('image-broken');
        });
    });

    document.getElementById('select-all')?.addEventListener('click', function() {
        const items = Array.from(grid.querySelectorAll('.image-item'));
        const allSelected = items.length > 0 && items.every(i => i.classList.contains('selected'));
        items.forEach(i => {
            i.classList.toggle('selected', !allSelected);
            if (!allSelected) {
                window.__imgSel.add(i.dataset.id);
            } else {
                window.__imgSel.delete(i.dataset.id);
            }
        });
        updateBatchBar();
    });

    // v1.2.0 迭代: select EVERYTHING matching the current filters, across all
    // pages — fetches the full id list from the server.
    document.getElementById('select-all-all')?.addEventListener('click', async function() {
        const params = new URLSearchParams(location.search);
        try {
            const resp = await fetch('/admin/images/ids?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();
            if (!data || !Array.isArray(data.ids)) {
                showToast('获取全部图片失败', 'error');
                return;
            }
            data.ids.forEach(id => window.__imgSel.add(String(id)));
            syncDomFromSet();
            updateBatchBar();
            showToast('已选择全部 ' + data.ids.length + ' 张图片', 'success');
        } catch (_) {
            showToast('获取全部图片失败', 'error');
        }
    });

    document.getElementById('clear-selection')?.addEventListener('click', function() {
        window.__imgSel.clear();
        syncDomFromSet();
        updateBatchBar();
    });

    document.getElementById('batch-delete')?.addEventListener('click', function() {
        const ids = selectedIds();
        if (ids.length === 0) return;
        deleteImages(ids);
    });

    async function deleteImages(ids, name) {
        const label = ids.length === 1 ? ('「' + (name || '这张图片') + '」') : ('选中的 ' + ids.length + ' 张图片');
        if (!confirm('确定删除' + label + '？此操作不可撤销。')) return;

        const btn = document.getElementById('batch-delete');
        if (btn) btn.disabled = true;

        try {
            const url = ids.length === 1 ? '/admin/images/delete' : '/admin/images/batch-delete';
            const payload = ids.length === 1 ? { id: ids[0] } : { 'ids[]': ids };
            const result = await adminPost(url, payload);

            (result.deleted || ids).forEach(id => {
                id = String(id);
                window.__imgSel.delete(id);
                grid.querySelector('.image-item[data-id="' + id + '"]')?.remove();
            });
            updateBatchBar();
            refreshEmptyState();
            updateTotalCount(-(result.deleted || ids).length);
            showToast(result.message || '删除成功', 'success');
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    function refreshEmptyState() {
        if (grid.querySelector('.image-item')) return;
        const placeholder = document.getElementById('image-empty');
        if (placeholder) placeholder.classList.remove('hidden');
    }

    function updateTotalCount(delta) {
        const el = document.getElementById('image-total');
        if (!el) return;
        const current = parseInt(el.dataset.total || el.textContent.replace(/[^\d]/g, ''), 10) || 0;
        const next = Math.max(0, current + delta);
        el.dataset.total = next;
        el.textContent = next.toLocaleString();
    }

    function openPreview(url, name) {
        const modal = document.getElementById('preview-modal');
        if (!modal) return;
        const img = document.getElementById('preview-image');
        const title = document.getElementById('preview-title');
        const link = document.getElementById('preview-link');
        if (img) img.src = url || '';
        if (title) title.textContent = name || '预览';
        if (link) { link.href = url || '#'; link.textContent = url || ''; }
        modal.classList.add('active');
    }
    window.openPreview = openPreview;
}

// API Key management (create / edit / toggle / delete — all without navigation)
function initApiKeys() {
    const table = document.getElementById('apikey-table');
    if (!table) return;

    const form = document.getElementById('apikey-form');
    const modalTitle = document.getElementById('apikey-modal-title');
    const submitBtn = document.getElementById('apikey-submit');

    function resetForm(mode, data) {
        form.dataset.mode = mode;
        document.getElementById('key-id').value = data ? data.id : '';
        document.getElementById('key-name').value = data ? data.name : '';
        document.getElementById('key-rate-limit').value = data ? data.rateLimit : 60;
        document.getElementById('key-rate-window').value = data ? data.rateWindow : 60;

        const perms = data ? data.permissions : ['read'];
        form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = perms.indexOf(cb.value) !== -1;
        });

        modalTitle.textContent = mode === 'update' ? '编辑 API Key' : '生成 API Key';
        submitBtn.textContent = mode === 'update' ? '保存' : '生成';
    }

    document.getElementById('apikey-new')?.addEventListener('click', function() {
        resetForm('create', null);
        openModal('apikey-modal');
    });

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const mode = form.dataset.mode === 'update' ? 'update' : 'create';
        const permissions = Array.from(form.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);

        const payload = {
            name: document.getElementById('key-name').value.trim(),
            rate_limit: document.getElementById('key-rate-limit').value,
            rate_window: document.getElementById('key-rate-window').value,
            'permissions[]': permissions.length ? permissions : ['read']
        };
        if (mode === 'update') payload.id = document.getElementById('key-id').value;

        submitBtn.disabled = true;
        try {
            const result = await adminPost('/admin/apikeys/' + mode, payload);
            closeModal('apikey-modal');

            if (mode === 'create') {
                upsertRow(result.item, true);
                showGeneratedKey(result.plain_key, result.item.name);
            } else {
                upsertRow(result.item, false);
                showToast(result.message, 'success');
            }
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    table.addEventListener('click', async function(e) {
        const btn = e.target.closest('[data-key-action]');
        if (!btn) return;
        e.preventDefault();

        const row = btn.closest('tr');
        const id = row?.dataset.id;
        const action = btn.dataset.keyAction;

        if (action === 'copy') {
            const ok = await copyText(row.dataset.key || '');
            showToast(ok ? 'API Key 已复制到剪贴板' : '复制失败，请手动选中复制', ok ? 'success' : 'error');
            return;
        }

        if (action === 'edit') {
            let permissions = [];
            try { permissions = JSON.parse(row.dataset.permissions || '[]'); } catch (err) { permissions = []; }
            resetForm('update', {
                id: id,
                name: row.dataset.name || '',
                rateLimit: row.dataset.rateLimit || 60,
                rateWindow: row.dataset.rateWindow || 60,
                permissions: permissions
            });
            openModal('apikey-modal');
            return;
        }

        if (action === 'toggle' || action === 'delete') {
            if (action === 'delete' && !confirm('确定删除 API Key「' + (row.dataset.name || '') + '」？调用方将立即失效。')) return;

            btn.disabled = true;
            try {
                const result = await adminPost('/admin/apikeys/' + (action === 'toggle' ? 'toggle-status' : 'delete'), { id: id });
                if (action === 'delete') {
                    row.remove();
                    refreshEmpty();
                } else {
                    upsertRow(result.item, false);
                }
                showToast(result.message, 'success');
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }
    });

    function refreshEmpty() {
        const body = table.querySelector('tbody');
        if (body.querySelector('tr[data-id]')) return;
        document.getElementById('apikey-empty')?.classList.remove('hidden');
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function upsertRow(item, isNew) {
        const body = table.querySelector('tbody');
        document.getElementById('apikey-empty')?.classList.add('hidden');

        let row = body.querySelector('tr[data-id="' + item.id + '"]');
        if (!row) {
            row = document.createElement('tr');
            row.dataset.id = item.id;
            body.prepend(row);
        }

        row.dataset.name = item.name;
        row.dataset.key = item.key;
        row.dataset.rateLimit = item.rate_limit;
        row.dataset.rateWindow = item.rate_window;
        row.dataset.permissions = JSON.stringify(item.permissions);

        const badges = item.permissions.map(p => '<span class="badge badge-info">' + escapeHtml(p) + '</span>').join(' ');
        const active = item.status === 'active';

        row.innerHTML =
            '<td>' + escapeHtml(item.name) + '</td>' +
            '<td><div class="flex gap-1" style="align-items:center">' +
                '<code style="font-size:0.8rem">' + escapeHtml(item.key_preview) + '</code>' +
                '<button type="button" class="btn btn-outline btn-sm" data-key-action="copy" title="复制完整 Key" aria-label="复制完整 Key">' + (window.MOERNG_ICON_COPY || '复制') + '</button>' +
            '</div></td>' +
            '<td>' + badges + '</td>' +
            '<td>' + item.rate_limit + ' / ' + item.rate_window + 's</td>' +
            '<td><span class="badge badge-' + (active ? 'success' : 'danger') + '">' + escapeHtml(item.status) + '</span></td>' +
            '<td><div class="flex gap-1">' +
                '<button type="button" class="btn btn-outline btn-sm" data-key-action="edit">编辑</button>' +
                '<button type="button" class="btn btn-sm ' + (active ? 'btn-danger' : 'btn-outline') + '" data-key-action="toggle">' + (active ? '禁用' : '启用') + '</button>' +
                '<button type="button" class="btn btn-danger btn-sm" data-key-action="delete">删除</button>' +
            '</div></td>';

        if (isNew) row.classList.add('row-highlight');
    }

    function showGeneratedKey(plainKey, name) {
        const modal = document.getElementById('newkey-modal');
        if (!modal) { showToast('API Key: ' + plainKey, 'success'); return; }
        document.getElementById('newkey-name').textContent = name || '';
        document.getElementById('newkey-value').textContent = plainKey;
        openModal('newkey-modal');
    }

    document.getElementById('newkey-copy')?.addEventListener('click', async function() {
        const value = document.getElementById('newkey-value').textContent;
        const ok = await copyText(value);
        this.textContent = ok ? '已复制' : '复制失败';
        setTimeout(() => { this.textContent = '复制 Key'; }, 2000);
        if (!ok) showToast('复制失败，请手动选中复制', 'error');
    });
}

// Category deletion without navigating to the JSON endpoint
function initCategoryActions() {
    // v1.1.1-beta.4: bind at document level — the delete buttons used to be
    // scoped to #category-tree, but the v1.1.1-beta.3 redesign renamed the
    // container to .category-list and the handler silently never attached
    // (clicks did nothing, no request fired). Document-level delegation is
    // resilient to future container renames.
    document.addEventListener('click', async function(e) {
        const btn = e.target.closest('[data-category-delete]');
        if (!btn) return;
        e.preventDefault();

            const id = btn.dataset.categoryDelete;
            const name = btn.dataset.name || '';
            if (!confirm('确定删除分类「' + name + '」？其子分类会一并删除，分类下的图片将变为未分类。')) return;

            btn.disabled = true;
            try {
                const result = await adminPost('/admin/categories/delete', { id: id });
                showToast(result.message, 'success');
                // Let the success toast stay visible for a beat before the page
                // reloads, otherwise it gets wiped instantly and the user never
                // sees the confirmation.
                setTimeout(() => window.location.reload(), 1800);
            } catch (err) {
                showToast(err.message, 'error');
                btn.disabled = false;
            }
        });

    // Create / edit category via AJAX. The name (and every other field) is read
    // straight from the DOM and appended to FormData explicitly, so the value can
    // never be lost in a native modal-form post — that was the cause of the
    // "Category name is required" error even when the field was filled in.
    const form = document.getElementById('category-form');
    if (!form) return;
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const id = (document.getElementById('cat-id').value || '').trim();
        const payload = {
            name: (document.getElementById('cat-name').value || '').trim(),
            slug: (document.getElementById('cat-slug').value || '').trim(),
            description: (document.getElementById('cat-desc').value || '').trim(),
            parent_id: document.getElementById('cat-parent').value,
            sort_order: document.getElementById('cat-sort').value,
        };
        if (id) payload.id = id;

        const url = id ? '/admin/categories/update' : '/admin/categories/create';
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const result = await adminPost(url, payload);
            showToast(result.message || '保存成功', 'success');
            closeModal('category-modal');
            // Same as delete: hold the toast long enough to read before reload.
            setTimeout(() => window.location.reload(), 1800);
        } catch (err) {
            showToast(err.message || '保存失败', 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
}

// Drag and drop upload
function initDropZone() {
    const zone = document.querySelector('.drop-zone');
    if (!zone) return;

    const input = zone.querySelector('input[type="file"]');
    const progressBar = document.querySelector('.progress-bar');
    const progressFill = progressBar?.querySelector('.fill');

    zone.addEventListener('click', () => input?.click());

    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (input) input.files = e.dataTransfer.files;
        handleFiles(e.dataTransfer.files);
    });

    // Selecting files only updates the label; the explicit "开始上传" button
    // (or a drag-drop) kicks off the actual upload.
    input?.addEventListener('change', function() {
        const p = zone.querySelector('p');
        if (p && this.files.length > 0) p.textContent = '已选择 ' + this.files.length + ' 个文件';
    });

    document.getElementById('upload-submit')?.addEventListener('click', () => handleFiles(input ? input.files : []));

    function handleFiles(files) {
        if (!files.length) return;

        // v1.2.0 迭代: pre-flight size check — a batch bigger than PHP
        // post_max_size makes the server drop the whole body and answer 419
        // (CSRF). Tell the user up front instead of failing mid-upload.
        function parseSize(s) {
            if (!s) return 0;
            var m = String(s).trim().match(/^([\d.]+)\s*([kmg]?)b?$/i);
            if (!m) return 0;
            var n = parseFloat(m[1]);
            switch ((m[2] || '').toLowerCase()) {
                case 'g': return n * 1073741824;
                case 'm': return n * 1048576;
                case 'k': return n * 1024;
                default: return n;
            }
        }
        function fmtSize(b) {
            if (b >= 1073741824) return (b / 1073741824).toFixed(1) + 'GB';
            if (b >= 1048576) return (b / 1048576).toFixed(1) + 'MB';
            if (b >= 1024) return (b / 1024).toFixed(0) + 'KB';
            return b + 'B';
        }
        var total = 0;
        for (var fi = 0; fi < files.length; fi++) total += files[fi].size || 0;
        var postMax = parseSize(zone.dataset.postMax || '');
        if (postMax > 0 && total > postMax) {
            showToast('所选文件共 ' + fmtSize(total) + '，超过单次上传限制 ' + fmtSize(postMax)
                + '（' + zone.dataset.postMax + '），请分批上传（建议一次 5-10 张）', 'error', 8000);
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/admin/images/upload';
        form.enctype = 'multipart/form-data';
        form.innerHTML = '<input type="hidden" name="_csrf_token" value="' + getCsrfToken() + '">';

        const catId = document.querySelector('[name="upload_category_id"]')?.value || '';
        const catInput = document.createElement('input');
        catInput.type = 'hidden';
        catInput.name = 'category_id';
        catInput.value = catId;
        form.appendChild(catInput);

        // v1.0.33: dynamic storage instance picked in the upload dialog.
        const profileId = document.querySelector('[name="storage_profile_id"]')?.value || '';
        if (profileId) {
            const pInput = document.createElement('input');
            pInput.type = 'hidden';
            pInput.name = 'storage_profile_id';
            pInput.value = profileId;
            form.appendChild(pInput);
        }

        // Clone file input
        const fileInput = input.cloneNode();
        fileInput.name = 'images[]';
        fileInput.style.display = 'none';
        form.appendChild(fileInput);

        // Transfer files
        const dt = new DataTransfer();
        for (let f of files) dt.items.add(f);
        fileInput.files = dt.files;

        document.body.appendChild(form);

        if (progressBar && progressFill) {
            // v1.2.0 迭代: label floats above the bar (absolute positioning) —
            // display block keeps fill + floating label; flex used to squeeze
            // the text out at 100% width.
            progressBar.style.display = 'block';
            progressFill.style.width = '0%';
            progressFill.classList.remove('processing');
            // v1.1.1-beta.2: show a live percentage label next to the bar.
            var progressText = progressBar.querySelector('.progress-text');
            if (!progressText) {
                progressText = document.createElement('span');
                progressText.className = 'progress-text';
                progressBar.appendChild(progressText);
            }
            progressText.textContent = '0%';

            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var pct = Math.round(e.loaded / e.total * 100);
                    progressFill.style.width = pct + '%';
                    if (progressText) progressText.textContent = pct + '%';
                }
            };
            // v1.2.0 迭代: the transfer itself finished but the backend is still
            // saving thumbnails / rows — tell the user instead of a dead 100%.
            xhr.upload.onload = function() {
                if (progressText) progressText.textContent = '正在保存…';
                if (progressFill) progressFill.classList.add('processing');
            };
            // v1.0.22 tried `status >= 200 && < 400 -> reload`. That was wrong:
            // the backend answers EVERY upload with 302 + Session flash, and an
            // XHR silently follows the 302 — so xhr.status is always 200 and the
            // flash is consumed by the follow, making errors vanish again.
            // v1.0.23 makes the backend answer XHR requests with JSON, so the
            // frontend can show a real toast; the HTML-alert fallback below
            // still catches non-XHR-shaped responses (e.g. nginx 413 pages).
            xhr.onload = function() {
                if (progressBar) progressBar.style.display = 'none';
                if (progressFill) progressFill.classList.remove('processing');
                // Primary path: backend JSON verdict ({success, message, errors}).
                let payload = null;
                try { payload = JSON.parse(xhr.responseText || ''); } catch (_) {}
                if (payload) {
                    if (payload.success) {
                        showToast(payload.message || '上传成功', 'success', 5000);
                        // Let the success toast be visible before refreshing.
                        setTimeout(() => window.location.reload(), 900);
                    } else {
                        const err = (Array.isArray(payload.errors) && payload.errors.length)
                            ? payload.errors.join(' | ')
                            : (payload.message || ('上传失败 (HTTP ' + xhr.status + ')'));
                        showToast(err, 'error', 8000);
                    }
                    return;
                }
                // Fallback: not JSON (e.g. nginx 413 / 50x HTML page). Try to
                // extract a rendered alert block, else show the raw status.
                let msg = '上传失败 (HTTP ' + xhr.status + ')';
                try {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = xhr.responseText || '';
                    const alertEl = tmp.querySelector('.alert.alert-error, .alert.alert-danger, .alert.alert-warning');
                    if (alertEl && alertEl.textContent.trim()) {
                        msg = alertEl.textContent.trim();
                    }
                } catch (_) { /* responseText was empty / not HTML — keep default */ }
                showToast(msg, 'error', 8000);
            };
            xhr.onerror = function() {
                if (progressBar) progressBar.style.display = 'none';
                showToast('网络错误：上传请求未到达服务器', 'error', 8000);
            };
            // Marks this request as AJAX so the backend returns JSON instead of
            // the 302+flash redirect (which an XHR follow would swallow).
            xhr.open('POST', form.action);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(new FormData(form));
        } else {
            form.submit();
        }
    }
}

// Sortable images (drag to reorder)
function initSortable() {
    const container = document.getElementById('sortable-container');
    if (!container) return;

    let draggedItem = null;

    container.addEventListener('dragstart', function(e) {
        draggedItem = e.target.closest('.image-item');
        if (!draggedItem) return;
        draggedItem.classList.add('sortable-chosen');
        e.dataTransfer.effectAllowed = 'move';
    });

    container.addEventListener('dragend', function() {
        if (draggedItem) draggedItem.classList.remove('sortable-chosen');
        draggedItem = null;
        saveSortOrder();
    });

    container.addEventListener('dragover', function(e) {
        e.preventDefault();
        const item = e.target.closest('.image-item');
        if (!item || item === draggedItem) return;

        const rect = item.getBoundingClientRect();
        const mid = rect.top + rect.height / 2;
        if (e.clientY < mid) {
            item.parentNode.insertBefore(draggedItem, item);
        } else {
            item.parentNode.insertBefore(draggedItem, item.nextSibling);
        }
    });

    function saveSortOrder() {
        const items = container.querySelectorAll('.image-item');
        const order = Array.from(items).map(el => el.dataset.id);

        const form = new FormData();
        form.append('_csrf_token', getCsrfToken());
        order.forEach((id, i) => form.append('order[]', id));

        fetch('/admin/images/sort', { method: 'POST', body: form })
            .then(r => r.json())
            .then(d => { if (d.success) showToast('Sort order saved', 'success'); });
    }
}

// Tabs
function initTabs() {
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const group = this.closest('.tabs');
            group.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            const target = document.getElementById(this.dataset.tab);
            if (target) {
                document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
                target.classList.remove('hidden');
            }
        });
    });
}

// Storage driver toggle in settings
function initStorageToggle() {
    const driverSelect = document.querySelector('[name="storage_driver"]');
    if (!driverSelect) return;

    const localFields = document.getElementById('local-fields');
    const s3Fields = document.getElementById('s3-fields');

    function toggle() {
        const val = driverSelect.value;
        if (localFields) localFields.classList.toggle('hidden', val !== 'local');
        if (s3Fields) s3Fields.classList.toggle('hidden', val !== 's3');
    }

    driverSelect.addEventListener('change', toggle);
    toggle();
}

// Copy to clipboard (enhanced v1.0.32: supports data-copy-text for arbitrary
// strings — e.g. code blocks and image URLs — plus an inline check feedback).
function initCopyButtons() {
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            let text = '';
            if (this.dataset.copyText !== undefined) {
                text = this.dataset.copyText;
            } else if (this.dataset.copy) {
                const target = document.getElementById(this.dataset.copy);
                if (target) text = target.textContent;
            }
            if (!text) return;
            const ok = await copyText(text.trim());
            const orig = this.innerHTML;
            this.innerHTML = UX_ICON.check + (ok ? ' 已复制' : ' 复制失败');
            this.classList.toggle('copied', ok);
            setTimeout(() => { this.innerHTML = orig; this.classList.remove('copied'); }, 2000);
        });
    });
}

// Helper
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.content;
    const input = document.querySelector('input[name="_csrf_token"]');
    return input ? input.value : '';
}

// Inline delete confirmation
function initDeleteButtons() {
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });
}

// ---------------------------------------------------------------------------
// Theme toggle (dark / light), persisted to localStorage('moerng-theme')
// ---------------------------------------------------------------------------
window.toggleTheme = function() {
    const root = document.documentElement;
    const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('moerng-theme', next); } catch (e) { /* private mode */ }
};

function initThemeToggle() {
    document.querySelectorAll('.theme-toggle').forEach(function(btn) {
        btn.addEventListener('click', window.toggleTheme);
    });
}

// Init all on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    initThemeToggle();
    initApiTester();
    initImageGrid();
    initApiKeys();
    initCategoryActions();
    initDropZone();
    initSortable();
    initTabs();

    // v1.2.0 迭代: per-page selector on the image list — keeps the current
    // search / category filters, resets to page 1.
    const perPageSelect = document.getElementById('per-page-select');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            const params = new URLSearchParams(location.search);
            params.set('per_page', this.value);
            params.delete('page');
            location.search = params.toString();
        });
    }
    initStorageToggle();
    initCopyButtons();
    initDeleteButtons();
    initLightbox();
    initReveal();
    initRandomDemo();
    initSidebarDrawer();
    initGlobalKeys();
    initStatCount();
    initImageFallback();
});

// ---------------------------------------------------------------------------
// v1.0.32 — UX enhancements
// ---------------------------------------------------------------------------

// Inline SVG fragments for JS-rendered controls (outline, 1.5 stroke, 24 viewBox
// — matches views/partials/icons.php so no second icon system ever appears).
const UX_ICON = {
    close: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>',
    prev: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg>',
    next: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>',
    copy: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>',
    check: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4.5 12.5l5 5 10-11"/></svg>',
    imageOff: '<svg class="ic" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="5" width="16" height="14" rx="2.5"/><path d="M4 5.5L20 18.5"/><path d="M20 5.5L4 18.5"/></svg>',
    menu: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>'
};

// Fullscreen lightbox for the image grid. Opens from the quick-actions "view"
// button or double-click, navigates with prev/next arrows + arrow keys, Esc
// closes, and every view exposes a copy-link button.
function initLightbox() {
    const box = document.getElementById('lightbox');
    if (!box) return;

    const img = document.getElementById('lb-image');
    const nameEl = document.getElementById('lb-name');
    const urlEl = document.getElementById('lb-url');
    const countEl = document.getElementById('lb-count');
    const copyBtn = document.getElementById('lb-copy');
    const grid = document.querySelector('.image-grid');

    let items = [];
    let current = -1;

    function refreshItems() {
        items = grid ? Array.from(grid.querySelectorAll('.image-item')) : [];
    }

    function render() {
        if (current < 0 || current >= items.length) { close(); return; }
        const item = items[current];
        const url = item.dataset.url || '';
        img.src = url;
        img.alt = item.dataset.name || '';
        if (nameEl) nameEl.textContent = item.dataset.name || '';
        if (urlEl) urlEl.textContent = url;
        if (countEl && items.length > 1) countEl.textContent = (current + 1) + ' / ' + items.length;
        if (countEl && items.length <= 1) countEl.textContent = '';
    }

    function open(index) {
        refreshItems();
        if (index < 0 || index >= items.length) return;
        current = index;
        box.classList.add('active');
        document.body.style.overflow = 'hidden';
        render();
    }

    function close() {
        box.classList.remove('active');
        document.body.style.overflow = '';
        current = -1;
        img.src = '';
    }

    function step(dir) {
        if (!items.length) return;
        current = (current + dir + items.length) % items.length;
        render();
    }

    window.openLightbox = open;
    window.closeLightbox = close;

    // Entry points: quick-actions view button + double-click.
    grid?.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-image-action="view"]');
        if (!btn) return;
        e.stopPropagation();
        e.preventDefault();
        const item = btn.closest('.image-item');
        if (item) open(items.indexOf(item));
    });
    grid?.addEventListener('dblclick', function(e) {
        const item = e.target.closest('.image-item');
        if (item) open(items.indexOf(item));
    });

    // Controls.
    document.getElementById('lb-close')?.addEventListener('click', close);
    document.getElementById('lb-prev')?.addEventListener('click', function() { step(-1); });
    document.getElementById('lb-next')?.addEventListener('click', function() { step(1); });
    copyBtn?.addEventListener('click', async function() {
        const url = img.src || '';
        const ok = await copyText(url);
        showToast(ok ? '链接已复制到剪贴板' : '复制失败', ok ? 'success' : 'error');
    });
    // Click on the dark backdrop (but not the image/meta) closes.
    box.addEventListener('click', function(e) {
        if (e.target === box) close();
    });
}

// Scroll-reveal animation (respects prefers-reduced-motion via CSS).
function initReveal() {
    const els = document.querySelectorAll('.reveal');
    if (!els.length) return;
    if (!('IntersectionObserver' in window)) {
        els.forEach(el => el.classList.add('in'));
        return;
    }
    const io = new IntersectionObserver(function(entries) {
        entries.forEach(en => {
            if (en.isIntersecting) {
                en.target.classList.add('in');
                io.unobserve(en.target);
            }
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    els.forEach(el => io.observe(el));
}

// Hero "try your luck" demo — calls the real API and shows the image.
function initRandomDemo() {
    const demo = document.querySelector('.random-demo');
    if (!demo) return;

    const btn = document.getElementById('rd-run');
    const imgBox = document.getElementById('rd-image');
    const urlEl = document.getElementById('rd-url');
    const catSel = document.getElementById('rd-category');
    const loading = document.getElementById('rd-loading');
    const placeholder = document.getElementById('rd-placeholder');

    btn?.addEventListener('click', async function() {
        btn.disabled = true;
        if (loading) loading.classList.remove('hidden');
        imgBox.style.opacity = '0.2';
        if (urlEl) urlEl.textContent = '加载中…';

        const params = new URLSearchParams({ type: 'json' });
        if (catSel && catSel.value) params.set('category', catSel.value);

        try {
            const resp = await fetch('/api/v1/random?' + params.toString());
            const data = await resp.json();
            if (!data.success || !data.data || !data.data.url) throw new Error(data.message || '请求失败');
            imgBox.src = data.data.url;
            imgBox.alt = data.data.category || '随机图片';
            imgBox.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
            if (urlEl) urlEl.textContent = data.data.url;
        } catch (e) {
            imgBox.style.opacity = '1';
            imgBox.src = '';
            imgBox.style.display = 'none';
            if (placeholder) placeholder.style.display = '';
            if (urlEl) urlEl.textContent = '加载失败：' + e.message;
            showToast('随机图片加载失败', 'error');
        } finally {
            btn.disabled = false;
            if (loading) loading.classList.add('hidden');
            imgBox.style.opacity = '1';
        }
    });
}

// Mobile drawer sidebar (hamburger + backdrop).
function initSidebarDrawer() {
    const toggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (!toggle || !sidebar) return;

    function close() {
        sidebar.classList.remove('open');
        overlay?.classList.remove('active');
    }
    toggle.addEventListener('click', function() {
        const open = sidebar.classList.toggle('open');
        overlay?.classList.toggle('active', open);
    });
    overlay?.addEventListener('click', close);
    // Closing a nav link on mobile is a nice touch; keep it simple: clicking a
    // link inside the drawer just closes it.
    sidebar.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
}

// Global keyboard shortcuts: Esc closes lightbox/modals, arrows navigate.
function initGlobalKeys() {
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const lb = document.getElementById('lightbox');
            if (lb?.classList.contains('active')) { closeLightbox && closeLightbox(); return; }
            document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
            return;
        }
        const lb = document.getElementById('lightbox');
        if (lb?.classList.contains('active')) {
            if (e.key === 'ArrowLeft') { document.getElementById('lb-prev')?.click(); e.preventDefault(); }
            if (e.key === 'ArrowRight') { document.getElementById('lb-next')?.click(); e.preventDefault(); }
        }
    });
}

// Animated stat counters (.stat-value[data-count]).
function initStatCount() {
    const els = document.querySelectorAll('.stat-value[data-count]');
    if (!els.length) return;
    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced || !('IntersectionObserver' in window)) {
        els.forEach(el => { el.textContent = Number(el.dataset.count).toLocaleString(); });
        return;
    }
    const io = new IntersectionObserver(function(entries) {
        entries.forEach(en => {
            if (!en.isIntersecting) return;
            const el = en.target;
            const target = Number(el.dataset.count) || 0;
            const dur = 700;
            const t0 = performance.now();
            (function tick(t) {
                const p = Math.min(1, (t - t0) / dur);
                const eased = 1 - Math.pow(1 - p, 3);
                el.textContent = Math.round(target * eased).toLocaleString();
                if (p < 1) requestAnimationFrame(tick);
                else el.textContent = target.toLocaleString();
            })(t0);
            io.unobserve(el);
        });
    }, { threshold: 0.4 });
    els.forEach(el => io.observe(el));
}

// Image load-failure placeholder (thumbnail + lightbox).
function initImageFallback() {
    document.addEventListener('error', function(e) {
        const target = e.target;
        if (target.tagName !== 'IMG') return;
        // Lightbox image: keep the frame, dim it, hide the broken glyph.
        if (target.id === 'lb-image') {
            target.classList.add('lb-failed');
            return;
        }
        const item = target.closest('.image-item');
        if (item) {
            item.classList.add('image-broken');
            item.classList.remove('selected');
            target.style.display = 'none';
            if (!item.querySelector('.image-broken-fallback')) {
                const fb = document.createElement('div');
                fb.className = 'image-broken-fallback';
                fb.innerHTML = UX_ICON.imageOff;
                item.appendChild(fb);
            }
        }
    }, true);
}
