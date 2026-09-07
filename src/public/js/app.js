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

// v1.2.1 迭代: expand / collapse all in the category tree (admin UI audit C1).
document.addEventListener('click', function(e) {
    const expand = e.target.closest ? e.target.closest('#cat-expand-all') : null;
    const collapse = e.target.closest ? e.target.closest('#cat-collapse-all') : null;
    if (!expand && !collapse) return;
    const tree = document.getElementById('category-tree');
    if (!tree) return;
    const show = !!expand;
    tree.querySelectorAll('.cat-children, .cat-grandchildren').forEach(function(el) {
        el.style.display = show ? '' : 'none';
    });
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
    const statusBadge = document.getElementById('test-status');
    const durationEl = document.getElementById('test-duration');
    const metaBox = document.getElementById('test-meta');

    // v1.2.1 UI 深度分析 (TEST-01): 最近 10 次请求历史（localStorage 文本记录，
    // 与已移除的随机图缩略图历史不同——这里保存的是请求/响应文本，不依赖签名 URL）。
    const TEST_HISTORY_KEY = 'moerng_test_history';

    function showMeta(status, statusText, ms) {
        if (!metaBox) return;
        const ok = status >= 200 && status < 400;
        // v1.2.1 修复: test-meta carries a 'hidden' class, use classList (inline
        // display would lose to the !important rule).
        metaBox.classList.remove('hidden');
        if (statusBadge) {
            statusBadge.textContent = status + ' ' + statusText;
            statusBadge.className = 'badge ' + (ok ? 'badge-success' : 'badge-danger');
        }
        if (durationEl) durationEl.textContent = '响应时间 ' + formatDuration(ms);
    }
    function formatDuration(ms) {
        if (ms < 1000) return Math.round(ms) + 'ms';
        return (ms / 1000).toFixed(2) + 's';
    }
    function saveTestHistory(url, summary) {
        try {
            let h = JSON.parse(localStorage.getItem(TEST_HISTORY_KEY) || '[]');
            h.unshift({ url: url, summary: summary, time: Date.now() });
            if (h.length > 10) h = h.slice(0, 10);
            localStorage.setItem(TEST_HISTORY_KEY, JSON.stringify(h));
        } catch (e) { /* private mode — ignore */ }
    }

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
        // v1.2.1-beta.3: 守卫式禁用 + 视觉 Loading 反馈（防止用户在异步完成前重复点击）
        runBtn.disabled = true;
        runBtn.textContent = 'Loading...';
        resultBox.innerHTML = '<div class="spinner"></div>';
        if (metaBox) metaBox.classList.add('hidden');

        const category = categorySelect.value;
        const type = typeSelect.value;
        const params = new URLSearchParams();
        if (category) params.set('category', category);
        if (type) params.set('type', type);
        const t0 = performance.now();

        // Promise wrapper so finally runs in BOTH json/redirect branches,
        // regardless of which branch executes. Without this, the redirect
        // branch returns immediately (Image is async) and the user could
        // click again before the previous request finishes — dev tools
        // would show no second request because the first click handler
        // hadn't completed. We resolve the promise on Image load/error.
        let resolveRequest;
        const requestDone = new Promise(r => { resolveRequest = r; });

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
                    const ms = performance.now() - t0;
                    tester.removeAttribute('style');
                    tester.style.cssText = 'max-width:100%;max-height:400px;object-fit:contain;display:block;margin:0 auto;';
                    resultBox.innerHTML = '';
                    resultBox.appendChild(tester);
                    showMeta(200, 'OK', ms);
                    saveTestHistory(apiPath, '302 → 图片 (200 OK, ' + formatDuration(ms) + ')');
                    resolveRequest();
                };
                tester.onerror = function() {
                    const ms = performance.now() - t0;
                    resultBox.innerHTML =
                        '<pre style="color:var(--danger)">重定向目标无法加载（目标地址不可达或返回非图片）。'
                        + '\n这是一个客户端 CORS 探测限制 —— 在 curl / 服务器端 HTTP 客户端中跟随 302 是正常的，'
                        + '浏览器 fetch 在跨域时会拦截（Failed to fetch）。'
                        + '\n本测试改用 img 探测，已绕开此限制。'
                        + '\n\n请求 URL：' + apiPath + '</pre>';
                    showMeta(0, 'Failed', ms);
                    saveTestHistory(apiPath, '加载失败 (' + formatDuration(ms) + ')');
                    resolveRequest();
                };
                // v1.2.1-beta.3 修复: 重复点击无请求 —— 服务器 redirect 响应未带
                // Cache-Control:no-store，浏览器会把 302 + 图片按启发式规则缓存；
                // 第二次设置相同 src 时直接命中缓存，不发任何请求（devtools 无记录，
                // 页面只有闪一下的 UI 反馈）。给 URL 追加一次性时间戳 + 随机数 query
                // 强制绕过缓存——即使快速双击落在同一毫秒，随机数也能保证 src 唯一。
                var cacheBust = (apiPath.indexOf('?') > -1 ? '&' : '?') + '_=' + Date.now() + Math.random();
                tester.src = apiPath + cacheBust;
            } else {
                const resp = await fetch(apiPath, { cache: 'no-store' });
                const ms = performance.now() - t0;
                let text = await resp.text();
                let pretty;
                try { pretty = JSON.stringify(JSON.parse(text), null, 2); }
                catch (e) { pretty = text; }
                resultBox.innerHTML = '<pre>' + pretty + '</pre>';
                showMeta(resp.status, resp.statusText || (resp.ok ? 'OK' : 'Error'), ms);
                saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');
                resolveRequest();
            }

            await requestDone;
        } catch(e) {
            const ms = performance.now() - t0;
            resultBox.innerHTML = '<pre style="color:var(--danger)">Error: ' + e.message + '</pre>';
            showMeta(0, 'Network Error', ms);
            saveTestHistory('/api/v1/random?' + params.toString(), 'Error: ' + e.message);
            // v1.2.1-beta.3 修复: 异常分支也要 resolve，否则 await requestDone 永远挂起 → 按钮卡 Loading
            resolveRequest();
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

    // v1.2.1 迭代: bulk re-categorize (admin UI audit I2)
    const batchCat = document.getElementById('batch-category');
    const batchCatBtn = document.getElementById('batch-categorize');
    if (batchCat) {
        batchCat.addEventListener('change', function() {
            if (batchCatBtn) batchCatBtn.disabled = batchCat.value === '';
        });
    }
    if (batchCatBtn) {
        batchCatBtn.addEventListener('click', async function() {
            const ids = selectedIds();
            if (!batchCat || batchCat.value === '' || ids.length === 0) return;
            if (!confirm('将选中的 ' + ids.length + ' 张图片移动到所选分类？')) return;
            batchCatBtn.disabled = true;
            try {
                const result = await adminPost('/admin/images/batch-categorize', {
                    'ids[]': ids,
                    category_id: batchCat.value,
                });
                showToast(result.message || '分类更新成功', 'success');
                // Refresh the grid so category filtering reflects the change.
                if (window.location.search.includes('category')) {
                    window.location.reload();
                }
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                batchCatBtn.disabled = false;
            }
        });
    }

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

    // v1.3.1 迭代: 防重入——串行队列进行中时忽略再次触发
    var uploading = false;

    document.getElementById('upload-submit')?.addEventListener('click', () => handleFiles(input ? input.files : []));

    function handleFiles(files) {
        if (!files.length) return;
        if (uploading) { showToast('上传进行中，请等待当前批次完成', 'error', 4000); return; }

        // v1.3.1 迭代: 对象存储直传开关开启时，走 sign → PUT → confirm 直传
        // （图片不经过服务器）；签名端点返回 direct=false（未启用/本地存储/
        // 驱动不支持）则回退下方服务器分批上传。
        if (document.getElementById('upload-modal')?.dataset.directUpload === '1') {
            handleDirectUpload(files);
            return;
        }

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

        // ── 1) 贪心装箱：把所选文件按顺序切分为 ≤ 容量的批次 ──────────
        // 容量 = post_max_size × 90% − 8KB：水位留 multipart 边界、表单字段
        // 与中文文件名 UTF-8 膨胀的余量。postMax 缺失时 capacity=0 → 单批
        // 全传（等同旧行为，无法预判就交给服务端）。
        var postMax = parseSize(zone.dataset.postMax || '');
        var capacity = postMax > 0 ? Math.floor(postMax * 0.9) - 8192 : 0;
        if (capacity < 0) capacity = 0;

        var batches = [], current = [], currentBytes = 0, oversized = [];
        for (var fi = 0; fi < files.length; fi++) {
            var f = files[fi];
            if (capacity > 0 && f.size > capacity) { oversized.push(f); continue; }
            if (capacity > 0 && current.length && currentBytes + f.size > capacity) {
                batches.push(current); current = []; currentBytes = 0;
            }
            current.push(f); currentBytes += f.size;
        }
        if (current.length) batches.push(current);

        if (oversized.length) {
            var names = oversized.slice(0, 3).map(function(x) { return x.name + '(' + fmtSize(x.size) + ')'; }).join('、');
            showToast(oversized.length + ' 个文件超过单批容量被跳过：' + names + (oversized.length > 3 ? ' 等' : ''), 'error', 8000);
        }
        if (!batches.length) return;

        uploading = true;

        // ── 2) 无进度条降级：保持旧同步提交（理论不可达，进度条在视图中恒在）──
        if (!(progressBar && progressFill)) {
            buildForm(batches[0]).submit();
            uploading = false;
            return;
        }

        // ── 3) 串行上传队列 + 跨批次聚合进度 ──────────────────────────
        var batchCount = batches.length;
        var uploadedCount = 0, allErrors = [];
        // v1.3.1: 重复跳过聚合（与失败分开呈现，不打断其它文件上传）
        var allDuplicates = [];
        progressBar.classList.remove('hidden');
        progressFill.style.width = '0%';
        progressFill.classList.remove('processing');
        var progressText = progressBar.querySelector('.progress-text');
        if (!progressText) {
            progressText = document.createElement('span');
            progressText.className = 'progress-text';
            progressBar.appendChild(progressText);
        }
        progressText.textContent = batchCount > 1 ? '批次 1/' + batchCount + ' · 0%' : '0%';

        function updateProgress(batchIdx, batchPct, saving) {
            var totalPct = Math.round((batchIdx + batchPct) / batchCount * 100);
            progressFill.style.width = Math.min(100, totalPct) + '%';
            var label = batchCount > 1 ? ('批次 ' + (batchIdx + 1) + '/' + batchCount + ' · ') : '';
            progressText.textContent = saving ? (label + '正在保存…') : (label + Math.min(100, totalPct) + '%');
        }

        function finish(aborted) {
            uploading = false;
            progressBar.classList.add('hidden');
            progressFill.classList.remove('processing');
            if (uploadedCount > 0 || allDuplicates.length > 0) {
                var msg = uploadedCount > 0 ? ('上传完成：成功 ' + uploadedCount + ' 张') : '所选图片均为重复，未新增';
                if (allDuplicates.length) msg += uploadedCount > 0 ? ('，重复跳过 ' + allDuplicates.length + ' 张') : ('（共 ' + allDuplicates.length + ' 张）');
                if (allErrors.length) msg += '，失败 ' + allErrors.length + ' 项';
                if (aborted) msg += '（后续批次已中止：登录状态过期，请刷新页面重试）';
                showToast(msg, aborted || allErrors.length ? 'error' : 'success', aborted || allErrors.length ? 10000 : 5000);
                if (allErrors.length && window.console) console.warn('upload errors:', allErrors);
                if (allDuplicates.length && window.console) console.info('upload duplicates:', allDuplicates);
                setTimeout(() => window.location.reload(), 1200);
            } else {
                showToast(aborted ? '登录状态已过期（CSRF），请刷新页面后重试' : (allErrors.length ? allErrors.join(' | ') : '上传失败：没有文件被上传'), 'error', 8000);
            }
        }

        function nextBatch(idx) {
            if (idx >= batchCount) { finish(false); return; }
            var batch = batches[idx];
            var form = buildForm(batch);
            document.body.appendChild(form);
            uploadBatch(form, function(pct, saving) {
                updateProgress(idx, pct, saving);
            }, function(result) {
                document.body.removeChild(form);
                // 419 = CSRF 校验失败（登录过期/token 失效）：终止队列，避免
                // 后续批次全部撞 419。
                if (result.status === 419) { finish(true); return; }
                if (result.ok) {
                    // v1.3.1: 重复文件不计入成功数（后端已跳过存储）
                    uploadedCount += Math.max(0, batch.length - result.errors.length - result.duplicates.length);
                }
                allErrors = allErrors.concat(result.errors.map(function(e) {
                    return batchCount > 1 ? ('批次' + (idx + 1) + '：' + e) : e;
                }));
                allDuplicates = allDuplicates.concat(result.duplicates.map(function(e) {
                    return batchCount > 1 ? ('批次' + (idx + 1) + '：' + e) : e;
                }));
                nextBatch(idx + 1);
            });
        }
        nextBatch(0);

        // ── 单批构造：一次普通 upload POST（后端零改动）──────────────
        function buildForm(batchFiles) {
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

            const fileInput = input.cloneNode();
            fileInput.name = 'images[]';
            fileInput.style.display = 'none';
            form.appendChild(fileInput);

            const dt = new DataTransfer();
            for (let bf of batchFiles) dt.items.add(bf);
            fileInput.files = dt.files;

            return form;
        }

        // ── 单批上传：Promise 化的 XHR（JSON 判定 + 413/50x 降级解析）──
        function uploadBatch(form, onProgress, onDone) {
            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) onProgress(e.loaded / e.total, false);
            };
            // v1.2.0 迭代: the transfer finished but the backend is still saving.
            xhr.upload.onload = function() { onProgress(1, true); };
            xhr.onload = function() {
                let payload = null;
                try { payload = JSON.parse(xhr.responseText || ''); } catch (_) {}
                if (payload && typeof payload.success !== 'undefined') {
                    onDone({
                        ok: !!payload.success,
                        errors: Array.isArray(payload.errors) ? payload.errors : [],
                        // v1.3.1: 重复跳过明细（后端 SHA-256 去重结果）
                        duplicates: Array.isArray(payload.duplicates) ? payload.duplicates : [],
                        status: xhr.status,
                    });
                    return;
                }
                // Fallback: not JSON (nginx 413 / 50x HTML page) — try to
                // extract a rendered alert block, else the raw status.
                let msg = '上传失败 (HTTP ' + xhr.status + ')';
                try {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = xhr.responseText || '';
                    const alertEl = tmp.querySelector('.alert.alert-error, .alert.alert-danger, .alert.alert-warning');
                    if (alertEl && alertEl.textContent.trim()) msg = alertEl.textContent.trim();
                } catch (_) {}
                onDone({ ok: false, errors: [msg], duplicates: [], status: xhr.status });
            };
            xhr.onerror = function() {
                onDone({ ok: false, errors: ['网络错误：上传请求未到达服务器'], duplicates: [], status: 0 });
            };
            // AJAX marker so the backend answers JSON instead of 302+flash.
            xhr.open('POST', form.action);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(new FormData(form));
        }
    }

    // ── v1.3.1 迭代: 对象存储直传（浏览器 → 云，图片不经过服务器）─────
    // 流程：direct-sign 批量签名 → 逐张 XHR PUT（串行，字节级聚合进度）→
    // direct-confirm 登记入库。任何 sign/PUT 层失败都会把 directUpload 关闭
    // 标志翻为回退，由调用方决定是否走服务器分批路径。
    async function handleDirectUpload(files) {
        uploading = true;
        const catId = document.querySelector('[name="upload_category_id"]')?.value || '';
        const profileId = document.querySelector('[name="storage_profile_id"]')?.value || '';

        progressBar.classList.remove('hidden');
        progressFill.style.width = '0%';
        progressFill.classList.remove('processing');
        let progressText = progressBar.querySelector('.progress-text');
        if (!progressText) {
            progressText = document.createElement('span');
            progressText.className = 'progress-text';
            progressBar.appendChild(progressText);
        }
        const setPct = function(pct, note) {
            progressFill.style.width = Math.min(100, Math.round(pct * 100)) + '%';
            progressText.textContent = note ? ('直传 · ' + note) : ('直传 · ' + Math.min(100, Math.round(pct * 100)) + '%');
        };
        setPct(0, '计算哈希…');

        // v1.3.1: 内容级去重 —— 每个文件预计算 SHA-256（crypto.subtle，仅安全
        // 上下文可用；拿不到时置空串、该文件跳过去重照常直传）。重复项服务端
        // 不签发 PUT，浏览器根本不上传字节 —— 省流量。
        const hashes = [];
        for (let i = 0; i < files.length; i++) {
            hashes.push(await sha256Hex(files[i]));
        }

        // 1) 批量签名
        const fd = new FormData();
        fd.append('_csrf_token', getCsrfToken());
        fd.append('category_id', catId);
        if (profileId) fd.append('storage_profile_id', profileId);
        const items = [];
        for (let i = 0; i < files.length; i++) {
            items.push({ name: files[i].name, size: files[i].size, mime: files[i].type || 'application/octet-stream', hash: hashes[i] });
        }
        fd.append('items', JSON.stringify(items));

        let sign = null;
        try {
            const r = await fetch('/admin/images/direct-sign', {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            sign = await r.json();
        } catch (_) {
            sign = { direct: false, message: '签名请求失败' };
        }

        // 回退：开关关闭 / 本地存储 / 驱动不支持 → 服务器分批上传
        if (!sign || sign.direct !== true) {
            uploading = false;
            progressBar.classList.add('hidden');
            showToast('直传不可用（' + ((sign && sign.message) || '未知原因') + '），改为服务器上传', 'error', 6000);
            handleFiles(files);
            return;
        }

        // 2) 逐张 PUT + 3) confirm（串行；进度按字节占比聚合）
        const totalBytes = files.reduce(function(a, f) { return a + (f.size || 0); }, 0);
        let doneBytes = 0;
        const fileByNameSize = {};
        for (let i = 0; i < files.length; i++) {
            fileByNameSize[files[i].name + '|' + files[i].size] = files[i];
        }
        let uploaded = 0;
        const errors = [];
        // v1.3.1: 重复跳过清单（与错误分开呈现，不打断其它文件）
        let dupCount = 0;
        const dupMsgs = [];
        const signedItems = Array.isArray(sign.items) ? sign.items : [];

        for (let i = 0; i < signedItems.length; i++) {
            const it = signedItems[i];
            if (it.error) { errors.push((it.name || '文件') + ': ' + it.error); continue; }
            if (it.duplicate) {
                dupCount++;
                dupMsgs.push((it.name || '文件') + '：' + (it.message || '重复，已跳过'));
                doneBytes += (it.size || 0);
                continue;
            }
            const file = fileByNameSize[it.name + '|' + it.size]
                || files.find(function(f) { return f.name === it.name && f.size === it.size; });
            if (!file) { errors.push((it.name || '文件') + ': 未找到本地文件'); continue; }

            const base = doneBytes / (totalBytes || 1);
            const span = (it.size || 0) / (totalBytes || 1);
            const res = await xhrPut(it.url, file, (it.headers && it.headers['Content-Type']) || it.mime, function(pct) {
                setPct(base + span * pct, '');
            });
            if (!res.ok) {
                doneBytes += (it.size || 0);
                errors.push((it.name || '文件') + ': 直传失败 (HTTP ' + res.status + ')' +
                    (res.status === 403 ? '——通常是 Bucket 未配置 CORS，或签名已过期' : ''));
                continue;
            }
            setPct(base + span, '登记入库…');
            const cf = new FormData();
            cf.append('_csrf_token', getCsrfToken());
            cf.append('key', it.key);
            cf.append('original_name', it.name);
            cf.append('mime', it.mime);
            cf.append('size', it.size);
            if (it.hash) cf.append('hash', it.hash);
            cf.append('category_id', catId);
            if (profileId) cf.append('storage_profile_id', profileId);
            try {
                const cr = await fetch('/admin/images/direct-confirm', {
                    method: 'POST', body: cf,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const cj = await cr.json();
                if (cj && cj.success) { uploaded++; }
                else if (cj && cj.duplicate) { dupCount++; dupMsgs.push((it.name || '文件') + '：' + (cj.error || '重复，未登记')); }
                else { errors.push((it.name || '文件') + ': 登记失败 — ' + ((cj && cj.error) || '未知错误')); }
            } catch (_) {
                errors.push((it.name || '文件') + ': 登记请求失败');
            }
            doneBytes += (it.size || 0);
        }

        uploading = false;
        progressBar.classList.add('hidden');
        progressFill.classList.remove('processing');
        // v1.3.1: 汇总区分「成功 / 重复跳过 / 失败」——重复不是失败，不打断
        if (uploaded > 0 || dupCount > 0) {
            let msg = uploaded > 0 ? ('直传完成：成功 ' + uploaded + ' 张') : '所选图片均为重复，未新增';
            if (dupCount > 0) msg += uploaded > 0 ? ('，重复跳过 ' + dupCount + ' 张') : ('（共 ' + dupCount + ' 张）');
            if (errors.length) msg += '，失败 ' + errors.length + ' 项';
            showToast(msg, errors.length ? 'error' : 'success', errors.length || dupCount ? 10000 : 5000);
            if (window.console) {
                if (errors.length) console.warn('direct upload errors:', errors);
                if (dupMsgs.length) console.info('direct upload duplicates:', dupMsgs);
            }
            setTimeout(function() { window.location.reload(); }, 1200);
        } else {
            let msg = errors.length ? errors.join(' | ') : '直传失败：没有文件被上传';
            if (errors.some(function(e) { return e.indexOf('CORS') !== -1 || e.indexOf('403') !== -1; })) {
                msg = '直传失败：请在云控制台为 Bucket 配置 CORS（允许站点域名 PUT/HEAD + content-type 头）后重试';
            }
            showToast(msg, 'error', 10000);
        }
    }

    // v1.3.1: 文件 SHA-256（hex）。crypto.subtle 仅在安全上下文可用；
    // 不可用或读文件失败时返回空串（服务端据此跳过去重，不影响上传）。
    async function sha256Hex(file) {
        try {
            if (!window.crypto || !crypto.subtle || typeof crypto.subtle.digest !== 'function') return '';
            const buf = await file.arrayBuffer();
            const digest = await crypto.subtle.digest('SHA-256', buf);
            return Array.from(new Uint8Array(digest)).map(function(b) { return b.toString(16).padStart(2, '0'); }).join('');
        } catch (_) {
            return '';
        }
    }

    function xhrPut(url, file, contentType, onPct) {
        return new Promise(function(resolve) {
            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) onPct(e.loaded / e.total);
            };
            xhr.onload = function() { resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status }); };
            xhr.onerror = function() { resolve({ ok: false, status: 0 }); };
            xhr.open('PUT', url);
            if (contentType) xhr.setRequestHeader('Content-Type', contentType);
            xhr.send(file);
        });
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
// API 文档 tab 切换: 点击左侧导航只显示对应 endpoint，其余隐藏。
// 绑定到 document 层（CSP 迁移后的铁律：事件委托用 document，不用具体容器 id）。
function initDocsNav() {
    var nav = document.querySelector('.docs-sidebar nav');
    if (!nav) return;
    var links = nav.querySelectorAll('a[data-doc-target]');
    var panes = document.querySelectorAll('.docs-content .doc-pane');
    if (!panes.length) return;
    links.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var target = link.getAttribute('data-doc-target');
            links.forEach(function (l) { l.classList.remove('active'); });
            link.classList.add('active');
            panes.forEach(function (p) {
                p.classList.toggle('active', '#' + p.id === target);
            });
        });
    });
}

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
// Theme toggle (dark / light) — moved to helpers.js (loaded on every page,
// incl. the login page where app.js is absent). initThemeToggle() below now
// resolves to the helpers.js copy.
// ---------------------------------------------------------------------------

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
    initDocsNav();
    initRandomDemo();
    initSidebarDrawer();
    initGlobalKeys();
    initStatCount();
    applyDynamicStyles();
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
        if (item) {
            // v1.2.1 修复: refreshItems() must run BEFORE reading items, else on
            // the very first click items is still [] and indexOf returns -1,
            // so open(-1) no-ops — "first click does nothing".
            refreshItems();
            open(items.indexOf(item));
        }
    });
    grid?.addEventListener('dblclick', function(e) {
        const item = e.target.closest('.image-item');
        if (item) {
            refreshItems();
            open(items.indexOf(item));
        }
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
    const metaEl = document.getElementById('rd-meta');
    const zoomBtn = document.getElementById('rd-zoom');
    const dlBtn = document.getElementById('rd-download');
    const lb = document.getElementById('rd-lightbox');
    const lbImg = document.getElementById('rd-lb-img');
    const lbClose = document.getElementById('rd-lb-close');

    let currentUrl = '';
    let currentName = '';

    function showImage(url, category) {
        currentUrl = url;
        currentName = (category ? category + '-' : '') + 'random.' + (url.split('.').pop() || 'jpg');
        imgBox.src = url;
        imgBox.alt = category ? '随机图片：' + category : '随机图片';
        // v1.2.1 修复: imgBox/zoomBtn/dlBtn carry a 'hidden' class (display:none
        // !important) in the markup, so toggle via classList — an inline
        // style.display would be overridden by the !important rule.
        imgBox.classList.remove('hidden');
        if (placeholder) placeholder.style.display = 'none';
        if (urlEl) urlEl.textContent = url;
        if (zoomBtn) zoomBtn.classList.remove('hidden');
        if (dlBtn) dlBtn.classList.remove('hidden');
        // v1.2.1-beta.3 修复: "已签名"判断从 p= 改为 query 存在性——
        // COS/OSS 签名 URL 用 q-sign-* 参数（无 p=），本地 /files 用 p=，
        // 统一按「含 query 即临时签名链接」判断。
        const isSigned = url.includes('?') || url.includes('&');
        if (metaEl) {
            metaEl.textContent = (isSigned ? '临时签名链接' : '永久链接') + (category ? ' · 分类: ' + category : '');
            metaEl.classList.remove('hidden');
        }
        // v1.2.1-beta.3 迭代: gacha 抽卡动效 — 霓虹边框闪动 + 过冲弹入（可关）。
        // 仅在用户未通过 prefers-reduced-motion 关闭动效时执行。
        // v1.2.1-beta.3 移除: 历史缩略图（签名 URL 默认 5 分钟 TTL，过期后图裂）已删除。
        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!reduceMotion) {
            const stage = imgBox.closest('.rd-preview');
            if (stage) {
                stage.classList.remove('gacha-flash');
                void stage.offsetWidth; // restart animation
                stage.classList.add('gacha-flash');
                setTimeout(() => stage.classList.remove('gacha-flash'), 700);
            }
            imgBox.classList.remove('gacha-pop');
            void imgBox.offsetWidth;
            imgBox.classList.add('gacha-pop');
            setTimeout(() => imgBox.classList.remove('gacha-pop'), 450);
        }
    }

    // v1.2.1 迭代: view-large (lightbox)
    // v1.2.1-beta.3 修复: 之前只有图片本体有点击事件，眼睛图标按钮（zoomBtn）无反应
    function openLightbox() {
        if (!currentUrl) return;
        if (lbImg) lbImg.src = currentUrl;
        if (lb) lb.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    if (imgBox) {
        imgBox.addEventListener('click', openLightbox);
    }
    if (zoomBtn) {
        zoomBtn.addEventListener('click', openLightbox);
    }
    if (lbClose && lb) {
        lbClose.addEventListener('click', closeLb);
        lb.addEventListener('click', (e) => { if (e.target === lb) closeLb(); });
    }
    function closeLb() {
        if (lb) lb.classList.add('hidden');
        document.body.style.overflow = '';
    }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && lb && !lb.classList.contains('hidden')) closeLb(); });

    // v1.2.1 迭代: download button
    if (dlBtn) {
        dlBtn.addEventListener('click', () => {
            if (!currentUrl) return;
            const a = document.createElement('a');
            a.href = currentUrl; a.download = currentName; a.rel = 'noopener';
            document.body.appendChild(a); a.click(); a.remove();
        });
    }

    btn?.addEventListener('click', async function() {
        btn.disabled = true;
        if (loading) loading.classList.remove('hidden');
        imgBox.style.opacity = '0.2';
        if (urlEl) urlEl.textContent = '加载中…';

        const params = new URLSearchParams({ type: 'json' });
        if (catSel && catSel.value) params.set('category', catSel.value);

        try {
            const resp = await fetch('/api/v1/random?' + params.toString(), { cache: 'no-store' });
            const data = await resp.json();
            if (!data.success || !data.data || !data.data.url) throw new Error(data.message || '请求失败');
            showImage(data.data.url, data.data.category || '');
        } catch (e) {
            imgBox.style.opacity = '1';
            imgBox.src = '';
            imgBox.classList.add('hidden');
            if (placeholder) placeholder.style.display = '';
            if (urlEl) urlEl.textContent = '加载失败：' + e.message;
            showToast('随机图片加载失败', 'error');
        } finally {
            btn.disabled = false;
            if (loading) loading.classList.add('hidden');
            imgBox.style.opacity = '1';
        }
    });

    // v1.2.1-beta.3 移除: 历史缩略图已删除（renderHistory / saveHistory 一并移除）
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

// v1.2.1 CSP 合规：动态样式（宽高百分比）经 data-* 属性由 JS 补设。
// CSP style-src 禁止 unsafe-inline，HTML 内联 style 属性会被拦截；
// JS 通过 el.style.* 设置不受 style-src 限制（由 script-src nonce 管控）。
function applyDynamicStyles() {
    document.querySelectorAll('[data-h]').forEach(function (el) {
        var h = parseInt(el.getAttribute('data-h'), 10);
        if (!isNaN(h)) el.style.height = h + 'px';
    });
    document.querySelectorAll('[data-w]').forEach(function (el) {
        var w = parseFloat(el.getAttribute('data-w'));
        if (!isNaN(w)) el.style.width = w + '%';
    });
    document.querySelectorAll('[data-bg]').forEach(function (el) {
        var bg = el.getAttribute('data-bg');
        if (bg) el.style.background = bg;
    });
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
