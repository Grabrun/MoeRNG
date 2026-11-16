// MoeRNG - Frontend JavaScript

// 5s 较长是有意的：后台操作后页面会短暂延时 reload，提示要来得及读完。
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

window.openModal = function(id) {
    document.getElementById(id).classList.add('active');
    document.body.classList.add('modal-open');
};
window.closeModal = function(id) {
    document.getElementById(id).classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) {
        document.body.classList.remove('modal-open');
    }
};

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
        if (!document.querySelector('.modal-overlay.active')) {
            document.body.classList.remove('modal-open');
        }
    }
});

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

// X-Requested-With 决定后端返回 JSON 而不是 302
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
    let parseErr = '';
    try { payload = await parseJsonResponse(resp); } catch (e) { payload = null; parseErr = e.message; }

    if (!resp.ok || !payload || payload.success !== true) {
        const msg = (payload && (payload.error || payload.message)) || parseErr || ('请求失败（HTTP ' + resp.status + '）');
        throw new Error(msg);
    }
    return payload;
};

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

function initApiTester() {
    const tester = document.getElementById('api-tester');
    if (!tester) return;

    const categorySelect = document.getElementById('test-category');
    const typeSelect = document.getElementById('test-type');
    const sizeSelect = document.getElementById('test-size');
    const runBtn = document.getElementById('test-run');
    const resultBox = document.getElementById('test-result');
    const urlDisplay = document.getElementById('test-url');
    const curlDisplay = document.getElementById('test-curl');
    const statusBadge = document.getElementById('test-status');
    const durationEl = document.getElementById('test-duration');
    const metaBox = document.getElementById('test-meta');

    const TEST_HISTORY_KEY = 'moerng_test_history';

    function showMeta(status, statusText, ms) {
        if (!metaBox) return;
        // 用 classList：.hidden 带 !important，内联 display 会被覆盖
        const ok = status >= 200 && status < 400;
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

    // 参数与路径只有这一份：URL 展示、cURL 提示、真实请求都由它派生
    function buildParams() {
        const params = new URLSearchParams();
        if (categorySelect.value) params.set('category', categorySelect.value);
        if (typeSelect.value) params.set('type', typeSelect.value);
        if (sizeSelect && sizeSelect.value) params.set('size', sizeSelect.value);
        return params;
    }

    function buildApiPath() {
        const qs = buildParams().toString();
        return '/api/v1/random' + (qs ? '?' + qs : '');
    }

    function updateUrl() {
        const url = window.location.origin + buildApiPath();
        urlDisplay.textContent = url;
        curlDisplay.textContent = 'curl -H "X-API-Key: YOUR_API_KEY" "' + url + '"';
    }

    categorySelect.addEventListener('change', updateUrl);
    typeSelect.addEventListener('change', updateUrl);
    if (sizeSelect) sizeSelect.addEventListener('change', updateUrl);
    updateUrl();

    runBtn.addEventListener('click', async function() {
        runBtn.disabled = true;
        runBtn.textContent = 'Loading...';
        resultBox.innerHTML = '<div class="spinner"></div>';
        if (metaBox) metaBox.classList.add('hidden');

        const type = typeSelect.value;
        const apiPath = buildApiPath();
        const t0 = performance.now();

        // redirect 分支靠 Image 异步完成，用 Promise 保证 finally 在两条分支都执行
        let resolveRequest;
        const requestDone = new Promise(r => { resolveRequest = r; });

        try {
            if (type === 'redirect') {
                // 跨域 302 目标不带 CORS 头时 fetch 会失败，改用 Image 探测（img 渲染不受 CORS 限制）
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
                // 302+图片会被启发式缓存，追加时间戳+随机数强制重新请求
                var cacheBust = (apiPath.indexOf('?') > -1 ? '&' : '?') + '_=' + Date.now() + Math.random();
                tester.src = apiPath + cacheBust;
            } else {
                const resp = await fetch(apiPath, { cache: 'no-store' });
                const ms = performance.now() - t0;
                let text = await resp.text();
                let pretty;
                try { pretty = JSON.stringify(JSON.parse(text), null, 2); }
                catch (e) { pretty = text; }
                if (!resp.ok) {
                    let hint = '';
                    try { const j = JSON.parse(text); hint = (j && (j.message || j.error)) || ''; }
                    catch (e2) { /* non-JSON body */ }
                    // 用 renderSafeText（textContent）避免存储型 XSS，见 helpers.js
                    window.renderSafeText(resultBox,
                        '请求失败（HTTP ' + resp.status + '）'
                        + (hint ? '\n' + hint : '\n接口返回了非 JSON 错误体 —— 多为网关/伪静态（URL Rewrite）未配置，请求未到达应用层。')
                        + '\n\n原始响应：\n' + pretty,
                        'test-result-error');
                    showMeta(resp.status, resp.statusText || 'Error', ms);
                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');
                    resolveRequest();
                } else {
                    window.renderSafeText(resultBox, pretty, 'test-result-raw');
                    showMeta(resp.status, resp.statusText || 'OK', ms);
                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');
                    resolveRequest();
                }
            }

            await requestDone;
        } catch(e) {
            const ms = performance.now() - t0;
            // 异常分支也必须 resolve，否则 await requestDone 永久挂起
            window.renderSafeText(resultBox, 'Error: ' + e.message, 'test-result-error');
            showMeta(0, 'Network Error', ms);
            saveTestHistory(apiPath, 'Error: ' + e.message);
            resolveRequest();
        }

        runBtn.disabled = false;
        runBtn.textContent = 'Send Request';
    });
}

function initImageGrid() {
    const grid = document.querySelector('.image-grid');
    if (!grid) return;

    // 选择集跨分页保存在 window.__imgSel，DOM class 只反映当前页
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
                openPreview(item.dataset.thumbLg || item.dataset.url, item.dataset.name, item.dataset.url);
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
        // 双击前已触发两次单击切换，这里撤销第二次
        item.classList.remove('selected');
        window.__imgSel.delete(item.dataset.id);
        updateBatchBar();
        if (window.openLightbox) {
            const all = Array.from(grid.querySelectorAll('.image-item'));
            window.openLightbox(all.indexOf(item));
        } else {
            openPreview(item.dataset.thumbLg || item.dataset.url, item.dataset.name, item.dataset.url);
        }
    });

    grid.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', function() {
            const item = this.closest('.image-item');
            // 缩略图失效先回退原图重试，仍失败才标记破图
            const orig = (item && item.dataset.url) || '';
            if (orig && !this.dataset.fellBack && this.getAttribute('src') !== orig) {
                this.dataset.fellBack = '1';
                this.removeAttribute('srcset');
                this.src = orig;
                return;
            }
            item?.classList.add('image-broken');
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

    document.getElementById('select-all-all')?.addEventListener('click', async function() {
        const params = new URLSearchParams(location.search);
        try {
            const resp = await fetch('/admin/images/ids?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await parseJsonResponse(resp);
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

    document.getElementById('requeue-failed')?.addEventListener('click', async function() {
        if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
        uploading = true;
        try {
            const fd = new FormData();
            fd.append('_csrf_token', getCsrfToken());
            const r = await fetch('/admin/images/requeue-failed', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const j = await parseJsonResponse(r);
            if (!j || !j.success) { showToast('重试失败: ' + ((j && j.error) || '未知错误'), 'error', 6000); return; }
            if (j.requeued > 0) {
                showToast('已重新入队 ' + j.requeued + ' 张，开始处理…', 'success', 5000);
                const res = await runProcessQueue(function() {});
                showToast('重试完成：成功 ' + res.done + ' 张' + (res.failed ? '，仍失败 ' + res.failed + ' 张' : ''), res.failed ? 'error' : 'success', 6000);
                setTimeout(() => window.location.reload(), 1200);
            } else {
                showToast('没有需要重试的失败项', 'success', 4000);
            }
        } catch (e) {
            showToast('重试请求异常: ' + e.message, 'error', 8000);
        } finally {
            uploading = false;
        }
    });

    document.getElementById('backfill-thumbs')?.addEventListener('click', async function() {
        if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
        const box = document.getElementById('backfill-progress');
        const fill = document.getElementById('backfill-fill');
        const text = document.getElementById('backfill-text');
        const detail = document.getElementById('backfill-detail');
        const setUI = function(pct, t, d) {
            if (fill) fill.style.width = Math.min(100, Math.round(pct * 100)) + '%';
            if (text) text.textContent = t;
            if (detail && d !== undefined) detail.textContent = d;
        };
        uploading = true;
        if (box) { box.classList.remove('hidden'); setUI(0, '准备补全缩略图…', ''); }
        try {
            const res = await runBackfillThumbs(setUI);
            showToast('缩略图补全完成：已补 ' + res.done + ' 张' + (res.failed ? '，失败 ' + res.failed + ' 张' : ''), res.failed ? 'error' : 'success', 8000);
            setTimeout(() => window.location.reload(), 1500);
        } catch (e) {
            showToast('缩略图补全异常: ' + e.message, 'error', 10000);
        } finally {
            uploading = false;
            if (box) setTimeout(() => box.classList.add('hidden'), 1200);
        }
    });

    const backfillBtn = document.getElementById('backfill-hashes');
    // 两段式确认：window.confirm 可能被浏览器「阻止额外对话框」静默吞掉
    let backfillArmed = false, backfillArmTimer = null;
    backfillBtn?.addEventListener('click', async function() {
        if (uploading) { showToast('有上传任务进行中，请稍候', 'error', 4000); return; }
        if (!backfillArmed) {
            backfillArmed = true;
            backfillBtn.textContent = '再次点击确认回填';
            backfillArmTimer = setTimeout(function() { backfillArmed = false; backfillBtn.textContent = '补全历史图片哈希'; }, 4000);
            return;
        }
        clearTimeout(backfillArmTimer); backfillArmed = false;
        backfillBtn.textContent = '补全历史图片哈希';

        const box = document.getElementById('backfill-progress');
        const fill = document.getElementById('backfill-fill');
        const text = document.getElementById('backfill-text');
        const detail = document.getElementById('backfill-detail');
        const setUI = function(pct, t, d) {
            fill.style.width = Math.min(100, Math.round(pct * 100)) + '%';
            if (t) text.textContent = t;
            if (d !== undefined) detail.textContent = d;
        };
        box.classList.remove('hidden');
        setUI(0, '准备回填…', '');
        backfillBtn.disabled = true;
        uploading = true;

        try {
            const res = await runHashBackfill(setUI);
            let msg = res.updated > 0
                ? ('哈希回填完成：已补 ' + res.updated + ' 张')
                : '哈希回填完成：所有图片均已携带哈希';
            if (res.failed > 0) msg += '，失败 ' + res.failed + ' 张（详情见控制台）';
            showToast(msg, res.failed > 0 ? 'error' : 'success', 8000);
            setTimeout(function() { window.location.reload(); }, 1500);
        } catch (e) {
            showToast('回填请求异常: ' + e.message, 'error', 8000);
        } finally {
            uploading = false;
            backfillBtn.disabled = false;
            setTimeout(function() { box.classList.add('hidden'); }, 1500);
        }
    });

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

    // src 是展示图（可用 lg 缩略图），fullUrl 始终是原图链接
    function openPreview(src, name, fullUrl) {
        const modal = document.getElementById('preview-modal');
        if (!modal) return;
        const img = document.getElementById('preview-image');
        const title = document.getElementById('preview-title');
        const link = document.getElementById('preview-link');
        const full = fullUrl || src || '';
        if (img) { img.removeAttribute('srcset'); img.src = src || ''; }
        if (title) title.textContent = name || '预览';
        if (link) { link.href = full || '#'; link.textContent = full || ''; }
        modal.classList.add('active');
    }
    window.openPreview = openPreview;
}

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

        // 完整 key 不进 DOM，只能创建后从弹窗复制一次；丢失就删除重建
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

    const escapeHtml = window.escapeHtml;

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
        row.dataset.rateLimit = item.rate_limit;
        row.dataset.rateWindow = item.rate_window;
        row.dataset.permissions = JSON.stringify(item.permissions);

        const badges = item.permissions.map(p => '<span class="badge badge-info">' + escapeHtml(p) + '</span>').join(' ');
        const active = item.status === 'active';

        row.innerHTML =
            '<td>' + escapeHtml(item.name) + '</td>' +
            '<td><code class="key-preview">' + escapeHtml(item.key_preview) + '</code></td>' +
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

function initCategoryActions() {
    // 文档级委托：容器改名不会让事件绑定悄悄失效
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
                setTimeout(() => window.location.reload(), 1800);
            } catch (err) {
                showToast(err.message, 'error');
                btn.disabled = false;
            }
        });

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
            setTimeout(() => window.location.reload(), 1800);
        } catch (err) {
            showToast(err.message || '保存失败', 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
}

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

    input?.addEventListener('change', function() {
        const p = zone.querySelector('p');
        if (p && this.files.length > 0) p.textContent = '已选择 ' + this.files.length + ' 个文件';
    });

    document.getElementById('upload-submit')?.addEventListener('click', () => handleFiles(input ? input.files : []));

    function handleFiles(files) {
        if (!files.length) return;
        if (uploading) { showToast('上传进行中，请等待当前批次完成', 'error', 4000); return; }

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

        // 容量 = post_max_size×90% − 8KB，留 multipart 边界与中文文件名膨胀余量
        var postMax = parseSize(zone.dataset.postMax || '');
        var capacity = postMax > 0 ? Math.floor(postMax * 0.9) - 8192 : 0;
        if (capacity < 0) capacity = 0;

        // PHP max_file_uploads 超限会静默丢弃文件，批次要同时受字节数与文件数约束
        var maxFiles = parseInt(zone.dataset.maxFiles || '', 10);
        if (!isFinite(maxFiles) || maxFiles <= 0) maxFiles = 20;

        var batches = [], current = [], currentBytes = 0, oversized = [];
        for (var fi = 0; fi < files.length; fi++) {
            var f = files[fi];
            if (capacity > 0 && f.size > capacity) { oversized.push(f); continue; }
            if ((capacity > 0 && current.length && currentBytes + f.size > capacity)
                || current.length >= maxFiles) {
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

        if (!(progressBar && progressFill)) {
            buildForm(batches[0]).submit();
            uploading = false;
            return;
        }

        var batchCount = batches.length;
        var uploadedCount = 0, allErrors = [];
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

        async function finish(aborted) {
            uploading = false;
            progressBar.classList.add('hidden');
            progressFill.classList.remove('processing');
            if (uploadedCount > 0 || allDuplicates.length > 0) {
                var msg = uploadedCount > 0 ? ('上传成功 ' + uploadedCount + ' 张，已进入处理队列') : '所选图片均为重复，未新增';
                if (allDuplicates.length) msg += uploadedCount > 0 ? ('，重复跳过 ' + allDuplicates.length + ' 张') : ('（共 ' + allDuplicates.length + ' 张）');
                if (allErrors.length) msg += '，失败 ' + allErrors.length + ' 项';
                if (aborted) msg += '（后续批次已中止：登录状态过期，请刷新页面重试）';
                showToast(msg, aborted || allErrors.length ? 'error' : 'success', aborted || allErrors.length ? 10000 : 5000);
                if (allErrors.length && window.console) console.warn('upload errors:', allErrors);
                if (allDuplicates.length && window.console) console.info('upload duplicates:', allDuplicates);

                if (uploadedCount > 0) {
                    const qbox = document.getElementById('backfill-progress');
                    const qfill = document.getElementById('backfill-fill');
                    const qtext = document.getElementById('backfill-text');
                    const qdetail = document.getElementById('backfill-detail');
                    const setUI = function(pct, t, d) {
                        if (qfill) qfill.style.width = Math.min(100, Math.round(pct * 100)) + '%';
                        if (qtext && t) qtext.textContent = t;
                        if (qdetail && d !== undefined) qdetail.textContent = d;
                    };
                    if (qbox) { qbox.classList.remove('hidden'); setUI(0, '图片处理中…', ''); }
                    try {
                        const qres = await runProcessQueue(setUI);
                        showToast('图片处理完成：已入库 ' + qres.done + ' 张'
                            + (qres.failed ? '，失败 ' + qres.failed + ' 张（可在「图片处理」页重试）' : '')
                            + (qres.skipped ? '，' + qres.skipped + ' 张过大仅存原图（无缩略图）' : ''),
                            qres.failed ? 'error' : 'success', 7000);
                    } catch (qe) {
                        showToast('队列处理异常：' + qe.message + '（可到「图片处理」页重试）', 'error', 9000);
                    } finally {
                        if (qbox) setTimeout(() => qbox.classList.add('hidden'), 1200);
                    }
                }
                setTimeout(() => window.location.reload(), 1400);
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
                // 419 = CSRF 校验失败（登录过期），终止队列免得后续批次全撞 419
                if (result.status === 419) { finish(true); return; }
                if (result.ok) {
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

        function uploadBatch(form, onProgress, onDone) {
            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) onProgress(e.loaded / e.total, false);
            };
            xhr.upload.onload = function() { onProgress(1, true); };
            xhr.onload = function() {
                let payload = null;
                try { payload = JSON.parse(xhr.responseText || ''); } catch (_) {}
                if (payload && typeof payload.success !== 'undefined') {
                    onDone({
                        ok: !!payload.success,
                        errors: Array.isArray(payload.errors) ? payload.errors : [],
                        duplicates: Array.isArray(payload.duplicates) ? payload.duplicates : [],
                        status: xhr.status,
                    });
                    return;
                }
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
            xhr.open('POST', form.action);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(new FormData(form));
        }
    }

}

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
            .then(r => parseJsonResponse(r, '保存排序'))
            .then(d => { if (d.success) showToast('Sort order saved', 'success'); })
            .catch(e => showToast(e.message, 'error', 6000));
    }
}

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

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.content;
    const input = document.querySelector('input[name="_csrf_token"]');
    return input ? input.value : '';
}

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

// 上传与回填共用的防重入标志（两处互斥）
var uploading = false;

// 空响应体通常是 PHP 致命错误或执行超时，统一转成可读错误
async function parseJsonResponse(resp, label) {
    const where = label ? (label + '：') : '';
    let raw;
    try {
        raw = await resp.text();
    } catch (e) {
        throw new Error(where + '读取响应失败：' + (e && e.message ? e.message : e));
    }
    if (raw === '' || raw.trim() === '') {
        throw new Error(where + '服务器返回空响应（HTTP ' + resp.status
            + '）——通常是 PHP 致命错误或执行超时，请查看 PHP 错误日志');
    }
    try {
        return JSON.parse(raw);
    } catch (e) {
        const snippet = raw.replace(/\s+/g, ' ').slice(0, 160);
        throw new Error(where + '服务器返回了非 JSON 响应（HTTP ' + resp.status + '）：' + snippet);
    }
}

async function postBatch(url, fields, label, signal) {
    const fd = new FormData();
    fd.append('_csrf_token', getCsrfToken());
    if (fields) {
        for (const k of Object.keys(fields)) fd.append(k, String(fields[k]));
    }
    const r = await fetch(url, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: signal,
    });
    const j = await parseJsonResponse(r, label);
    if (!j || !j.success) {
        let msg = (j && j.error) || '未知错误';
        if (j && j.fatal) {
            msg += '（memory_limit=' + (j.memory_limit || '?')
                + '，峰值 ' + (j.peak_mb === undefined ? '?' : j.peak_mb) + ' MiB）';
        }
        throw new Error(msg);
    }
    return j;
}

async function runProcessQueue(setUI, onTick) {
    // 单批张数由服务端注入的 meta[queue-batch] 决定
    const batchMeta = document.querySelector('meta[name="queue-batch"]');
    const BATCH = Math.max(1, Number(batchMeta && batchMeta.content) || 20);
    // AbortController 只兜「请求根本没回来」：服务端预算 45s，90s 是余量
    const QUEUE_REQUEST_TIMEOUT = 90000;
    let totalDone = 0, totalFailed = 0, totalSkipped = 0;
    let scopeTotal = null;
    let lastStats = null;

    if (onTick) onTick({ phase: 'start', stats: null });

    for (;;) {
        const ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        const guard = ctrl ? setTimeout(function () { ctrl.abort(); }, QUEUE_REQUEST_TIMEOUT) : null;
        let j;
        try {
            j = await postBatch(
                '/admin/images/process-queue',
                { batch: BATCH },
                '处理队列',
                ctrl ? ctrl.signal : undefined
            );
        } catch (e) {
            if (ctrl && ctrl.signal.aborted) {
                throw new Error('请求超时（' + Math.round(QUEUE_REQUEST_TIMEOUT / 1000)
                    + ' 秒未响应），已停止本轮；队列进度会自动刷新，可稍后再点');
            }
            throw e;
        } finally {
            if (guard) clearTimeout(guard);
        }

        const nDone = Number(j.done) || 0;
        const nFailed = Number(j.failed) || 0;
        const nSkipped = Number(j.skipped) || 0;
        const remaining = Number(j.remaining) || 0;

        if (scopeTotal === null) {
            scopeTotal = remaining + nDone + nFailed;
        }
        const processed = Math.max(0, scopeTotal - remaining);

        totalDone += nDone;
        totalFailed += nFailed;
        totalSkipped += nSkipped;

        setUI(scopeTotal > 0 ? processed / scopeTotal : 1,
            '处理中… ' + processed + '/' + scopeTotal,
            '已完成 ' + totalDone + ' 张'
                + (totalFailed ? '，失败 ' + totalFailed + ' 张' : '')
                + (totalSkipped ? '，' + totalSkipped + ' 张过大仅存原图（无缩略图）' : ''));

        lastStats = j.stats || null;
        if (onTick) onTick({ phase: 'batch', stats: lastStats });

        if (remaining === 0) break;
        // 整批全败即中止，避免无限空转
        if (nDone === 0 && nFailed > 0) {
            const first = (j.results && j.results[0] && j.results[0].error) || '';
            throw new Error('连续失败，已中止' + (first ? '（' + first + '）' : ''));
        }
        // 被并发方抢先认领时短暂退避，避免以 RTT 间隔空转
        if (j.contended) {
            if (onTick) onTick({ phase: 'contended', stats: lastStats });
            await new Promise(function (r) { setTimeout(r, 800 + Math.random() * 400); });
        }
    }

    if (onTick) onTick({ phase: 'done', stats: lastStats });
    return { done: totalDone, failed: totalFailed, total: scopeTotal || 0, skipped: totalSkipped };
}

async function runBackfillThumbs(setUI) {
    let totalDone = 0, totalFailed = 0, total = 0, done = 0;
    for (;;) {
        const j = await postBatch('/admin/images/backfill-thumbs', { batch: 10 }, '补全缩略图');
        total = j.total;
        done = total - j.remaining;
        totalDone += j.done;
        totalFailed += j.failed;
        setUI(total > 0 ? done / total : 1,
            '补全缩略图… ' + done + '/' + total,
            '已补 ' + totalDone + ' 张' + (totalFailed ? '，失败 ' + totalFailed + ' 张' : ''));
        if (j.remaining === 0) break;
        if (j.done === 0 && j.failed > 0) {
            throw new Error('连续失败，已中止（' + ((j.results && j.results[0] && j.results[0].error) || '') + '）');
        }
    }
    return { done: totalDone, failed: totalFailed, total: total };
}

// 必须用扫描游标推进：按判据重选每轮都会取到同一批失败行，无限循环
async function runThumbMetaRetry(setUI) {
    let from = 0, prevFrom = -1, rounds = 0;
    let retried = 0, scanned = 0, remaining = 0;

    for (;;) {
        rounds++;
        const j = await postBatch(
            '/admin/images/backfill-thumbs',
            { scope: 'retry', batch: 10, from: from },
            '重试元数据补全'
        );

        const n = Number(j.scanned) || 0;
        scanned += n;
        retried += Number(j.done) || 0;
        remaining = Number(j.remaining) || 0;
        setUI(remaining === 0 ? 1 : Math.min(0.95, scanned / Math.max(1, scanned + remaining)),
            '重试元数据补全… 已扫描 ' + scanned + ' 行',
            '已重试 ' + retried + ' 行' + (remaining > 0 ? ' · 游标之后仍有 ' + remaining + ' 行' : ''));

        const next = Number(j.next_from) || 0;
        if (n === 0 || next <= prevFrom) break;
        prevFrom = next;
        from = next;
        if (rounds > 500) break;
    }

    return { retried: retried, scanned: scanned, rounds: rounds };
}

// 干跑单轮出全量清单（重扫会重复计数），执行按批循环到 remaining 收敛
async function runStorageCleanup(mode, setUI) {
    const apply = mode === 'apply';
    let done = 0, planned = 0, failed = 0, skipped = 0, scanned = 0, rounds = 0;
    let firstError = '';
    const samples = [];

    for (;;) {
        rounds++;
        const j = await postBatch(
            '/admin/images/cleanup-storage',
            { mode: apply ? 'apply' : 'dry-run', batch: apply ? 10 : 1000 },
            '存储清理'
        );

        const s = j.staging || {};
        scanned += Number(s.scanned) || 0;
        done += apply ? (Number(s.deleted) || 0) : (Number(s.planned) || 0);
        planned += Number(s.planned) || 0;
        failed += Number(s.failed) || 0;
        skipped += Number(s.skipped) || 0;
        for (const item of s.samples || []) {
            if (samples.length < 8) samples.push(item);
        }

        const remaining = Number(s.remaining) || 0;
        setUI(remaining === 0 ? 1 : Math.min(0.95, scanned / Math.max(1, scanned + remaining)),
            (apply ? '清理中… 已删除 ' : '检查中… 待清理 ') + done + ' 项',
            '已扫描 ' + scanned + ' 个文件' + (remaining > 0 ? ' · 剩余 ' + remaining + ' 个' : ''));

        if (remaining === 0) break;
        if (!apply) break;
        if ((Number(s.deleted) || 0) === 0) {
            firstError = '目录无推进（文件被占用？），已中止';
            break;
        }
        if (rounds > 500) {
            firstError = '达到轮次上限（500），已中止';
            break;
        }
    }

    return { done: done, planned: planned, failed: failed, skipped: skipped,
        scanned: scanned, samples: samples, firstError: firstError, rounds: rounds };
}

async function runOriginalConversion(mode, setUI) {
    const apply = mode === 'apply';
    let done = 0, skipped = 0, failed = 0, orphan = 0, scanned = 0, rounds = 0;
    let firstRemaining = -1, firstError = '';
    const notes = {};
    const samples = [];

    // 干跑是零 I/O 的全表筛查；执行按服务端游标逐批推进
    let from = apply ? null : 0;
    let prevFrom = apply ? -1 : 0;

    for (;;) {
        rounds++;
        const fields = { mode: apply ? 'apply' : 'dry-run', batch: apply ? 5 : 200 };
        if (from !== null) fields.from = from;
        const j = await postBatch('/admin/images/convert-originals', fields, '原图转换');

        if (firstRemaining < 0) firstRemaining = Number(j.remaining) || 0;
        const remaining = Number(j.remaining) || 0;
        const thisScanned = Number(j.scanned) || 0;
        scanned += thisScanned;
        done += apply ? (Number(j.converted) || 0) : (Number(j.planned) || 0);
        skipped += Number(j.skipped) || 0;
        failed += Number(j.failed) || 0;
        orphan += Number(j.orphan_risk) || 0;

        for (const k of Object.keys(j.notes || {})) {
            notes[k] = (notes[k] || 0) + Number(j.notes[k] || 0);
        }
        for (const item of (j.samples || [])) {
            if (samples.length < 8) samples.push(item);
        }

        const pct = firstRemaining > 0
            ? Math.min(0.95, Math.max(0.05, 1 - remaining / firstRemaining))
            : 1;
        const topNotes = Object.keys(notes).sort((a, b) => notes[b] - notes[a]).slice(0, 2)
            .map(k => k + ' ' + notes[k]).join('；');
        setUI(pct, (apply ? '转换中… 已转 ' : '筛查中… 候选 ') + done + ' 张',
            '已扫描 ' + scanned + ' 行 · 跳过 ' + skipped + ' · 失败 ' + failed
            + (remaining > 0 ? ' · 剩余 ' + remaining + ' 行' : '')
            + (topNotes ? '　｜　' + topNotes : ''));

        if (remaining === 0) break;

        if (!apply) {
            const nextFrom = Number(j.next_from) || 0;
            if (nextFrom <= prevFrom) {
                firstError = '扫描位置未推进，已中止';
                break;
            }
            prevFrom = nextFrom;
            from = nextFrom;
        } else if (thisScanned === 0) {
            firstError = '本轮未扫描到任何行但仍有剩余，已中止';
            break;
        }
        if (rounds > 500) {
            firstError = '达到轮次上限（500），已中止';
            break;
        }
    }

    return { done, skipped, failed, orphan, scanned, notes, samples, firstError, rounds };
}
function initOriginalConversion() {
    const checkBtn = document.getElementById('convert-check');
    const runBtn = document.getElementById('convert-run');
    if (!checkBtn && !runBtn) return;

    const statEl = document.getElementById('convert-stat');
    const box = document.getElementById('convert-progress');
    const fill = document.getElementById('convert-fill');
    const text = document.getElementById('convert-text');
    const detail = document.getElementById('convert-detail');
    const setUI = function (pct, t, d) {
        if (box) box.classList.remove('hidden');
        if (fill) fill.style.width = Math.min(100, Math.round(pct * 100)) + '%';
        if (text && t) text.textContent = t;
        if (detail && d !== undefined) detail.textContent = d;
    };

    function describe(res) {
        const parts = [];
        for (const item of res.samples) {
            if (item && item.from) parts.push(item.from + ' → ' + item.to);
            else if (item && item.note) parts.push(item.note);
            if (parts.length >= 2) break;
        }
        const reasons = Object.keys(res.notes).sort((a, b) => res.notes[b] - res.notes[a]).slice(0, 3);
        return (parts.join('；') || '—') + (reasons.length ? '　｜　跳过原因：' + reasons.join('、') : '');
    }

    checkBtn?.addEventListener('click', async function () {
        checkBtn.disabled = true;
        try {
            const res = await runOriginalConversion('dry-run', setUI);
            statEl.textContent = '可转候选 ' + res.done + ' 张，跳过 ' + res.skipped + ' 张（已扫描 '
                + res.scanned + ' 行）'
                + (res.done + res.skipped > 0 ? '。示例：' + describe(res) : ' —— 没有需要转换的原图。')
                + '候选 ≠ 必然更小：执行时逐张判定，不划算的保留原图并计入跳过。'
                + (res.firstError ? '　注意：' + res.firstError : '');
            setUI(1, '检查完成', '可转候选 ' + res.done + ' 张 · 扫描 ' + res.scanned + ' 行');
            if (runBtn) runBtn.disabled = res.done === 0;
            showToast('干跑完成：扫描 ' + res.scanned + ' 行，可转候选 ' + res.done + ' 张', 'success', 7000);
        } catch (e) {
            statEl.textContent = '检查失败：' + e.message;
            showToast('干跑失败: ' + e.message, 'error', 8000);
        } finally {
            checkBtn.disabled = false;
        }
    });

    const label = runBtn ? runBtn.textContent : '';
    let armed = false, timer = null;
    runBtn?.addEventListener('click', async function () {
        if (!armed) {
            armed = true;
            runBtn.textContent = '再次点击确认开始转换';
            timer = setTimeout(function () { armed = false; runBtn.textContent = label; }, 6000);
            return;
        }
        clearTimeout(timer);
        armed = false;
        runBtn.textContent = label;
        runBtn.disabled = true;
        try {
            const res = await runOriginalConversion('apply', setUI);
            statEl.textContent = '已转换 ' + res.done + ' 张（跳过 ' + res.skipped + '，失败 ' + res.failed + '）'
                + (res.orphan ? '，另有 ' + res.orphan + ' 个旧对象未删成功（已成孤立对象，可稍后用「清理暂存残留」处理）' : '')
                + (res.firstError ? '。' + res.firstError : '。');
            setUI(1, '转换完成', '已转换 ' + res.done + ' 张');
            showToast('转换完成：' + res.done + ' 张' + (res.failed ? '，失败 ' + res.failed + ' 张' : ''),
                res.failed ? 'error' : 'success', 9000);
            if (res.firstError) showToast(res.firstError, 'error', 9000);
        } catch (e) {
            statEl.textContent = '转换失败：' + e.message;
            showToast('转换失败: ' + e.message, 'error', 9000);
        } finally {
            runBtn.disabled = false;
        }
    });
}

function initStorageCleanup() {
    const checkBtn = document.getElementById('cleanup-check');
    const runBtn = document.getElementById('cleanup-run');
    if (!checkBtn && !runBtn) return;

    const statEl = document.getElementById('cleanup-stat');
    const box = document.getElementById('cleanup-progress');
    const fill = document.getElementById('cleanup-fill');
    const text = document.getElementById('cleanup-text');
    const detail = document.getElementById('cleanup-detail');
    const setUI = function (pct, t, d) {
        if (box) box.classList.remove('hidden');
        if (fill) fill.style.width = Math.min(100, Math.round(pct * 100)) + '%';
        if (text && t) text.textContent = t;
        if (detail && d !== undefined) detail.textContent = d;
    };

    function describe(found) {
        const parts = [];
        for (const item of found) {
            if (!item) continue;
            if (item.path) parts.push(item.path);
            else if (item.note) parts.push(item.note);
            if (parts.length >= 3) break;
        }
        return parts.join('；');
    }

    checkBtn?.addEventListener('click', async function () {
        checkBtn.disabled = true;
        try {
            const res = await runStorageCleanup('dry-run', setUI);
            statEl.textContent = '待清理 ' + res.planned + ' 项 · 扫描 ' + res.scanned + ' 个文件';
            const sample = describe(res.samples);
            if (sample && res.planned > 0) statEl.textContent += '。示例：' + sample;
            setUI(1, '检查完成', '待清理 ' + res.planned + ' 项 · 扫描 ' + res.scanned + ' 个文件');
            if (runBtn) runBtn.disabled = res.planned === 0;
            showToast('干跑完成：扫描 ' + res.scanned + ' 个文件，待清理 ' + res.planned + ' 项', 'success', 7000);
        } catch (e) {
            statEl.textContent = '检查失败：' + e.message;
            showToast('干跑失败: ' + e.message, 'error', 8000);
        } finally {
            checkBtn.disabled = false;
        }
    });

    const label = runBtn ? runBtn.textContent : '';
    let armed = false, timer = null;
    runBtn?.addEventListener('click', async function () {
        if (!armed) {
            armed = true;
            runBtn.textContent = '再次点击确认执行清理';
            timer = setTimeout(function () { armed = false; runBtn.textContent = label; }, 6000);
            return;
        }
        clearTimeout(timer);
        armed = false;
        runBtn.textContent = label;
        runBtn.disabled = true;
        try {
            const res = await runStorageCleanup('apply', setUI);
            statEl.textContent = '已删除 ' + res.done + ' 项（失败 ' + res.failed + '，跳过 ' + res.skipped
                + '，扫描 ' + res.scanned + ' 个文件）' + (res.firstError ? '。' + res.firstError : '。');
            setUI(1, '清理完成', '已删除 ' + res.done + ' 项');
            showToast('清理完成：删除 ' + res.done + ' 项'
                + (res.failed ? '，失败 ' + res.failed + ' 项' : ''), res.failed ? 'error' : 'success', 8000);
            if (res.firstError) showToast(res.firstError, 'error', 9000);
        } catch (e) {
            statEl.textContent = '清理失败：' + e.message;
            showToast('清理失败: ' + e.message, 'error', 9000);
        } finally {
            runBtn.disabled = false;
        }
    });
}

async function runHashBackfill(setUI) {
    let totalUpdated = 0, totalFailed = 0, total = 0, done = 0;
    for (;;) {
        const j = await postBatch('/admin/images/backfill-hashes', { batch: 5 }, '哈希回填');
        total = j.total;
        done = total - j.remaining;
        totalUpdated += j.updated;
        totalFailed += j.failed;
        setUI(total > 0 ? done / total : 1,
            '回填中… ' + done + '/' + total,
            '已补 ' + totalUpdated + ' 张' + (totalFailed ? '，失败 ' + totalFailed + ' 张' : ''));
        if (j.remaining === 0) break;
    }
    return { updated: totalUpdated, failed: totalFailed, total: total };
}

function initHealthPanel() {
const healthRun = document.getElementById('health-run');
const healthFix = document.getElementById('health-fix');
const healthResults = document.getElementById('health-results');
const healthBackfillBox = document.getElementById('health-backfill-box');
const healthBackfillText = document.getElementById('health-backfill-text');
const healthBackfillFill = document.getElementById('health-backfill-fill');
const healthBackfillDetail = document.getElementById('health-backfill-detail');

function renderHealth(checks) {
    const badge = function(ok) { return '<span class="' + (ok ? 'health-ok' : 'health-bad') + '">' + (ok ? '[ OK ]' : '[待修复]') + '</span>'; };
    healthResults.innerHTML = Object.keys(checks).map(function(k) {
        const c = checks[k];
        const labels = { schema: '数据库结构完整性', orphan_settings: '遗留设置行', image_queue: '图片处理队列', thumb_backfill: '历史缩略图', hash_backfill: '历史图片哈希', dedupe_key: '上传去重判据' };
        // c.detail 含 PDO 异常文本（库/表/字段名），必须转义
        const esc = window.escapeHtml;
        return '<div>' + badge(c.ok) + ' ' + esc(labels[k] || k) + ' — ' + esc(c.detail) + '</div>';
    }).join('');
    const fixable = Object.keys(checks).some(function(k) {
        return !checks[k].ok && checks[k].fixable && k !== 'hash_backfill' && k !== 'image_queue' && k !== 'thumb_backfill';
    });
    healthFix.disabled = !fixable;
    const needBackfill = checks.hash_backfill && !checks.hash_backfill.ok;
    healthBackfillBox?.classList.toggle('hidden', !needBackfill);
}

async function runHealthCheck() {
    healthResults.textContent = '检查中…';
    try {
        const r = await fetch('/admin/settings/health', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const j = await parseJsonResponse(r);
        if (!j || !j.success) { healthResults.textContent = '检查失败。'; return; }
        renderHealth(j.checks);
    } catch (e) {
        healthResults.textContent = '检查请求异常: ' + e.message;
    }
}

healthRun?.addEventListener('click', runHealthCheck);
if (healthRun) runHealthCheck();

let healthFixArmed = false, healthFixArmTimer = null;
healthFix?.addEventListener('click', async function() {
    if (!healthFixArmed) {
        healthFixArmed = true;
        healthFix.textContent = '再次点击确认修复';
        healthFixArmTimer = setTimeout(function() { healthFixArmed = false; healthFix.textContent = '执行修复'; }, 4000);
        return;
    }
    clearTimeout(healthFixArmTimer); healthFixArmed = false;
    healthFix.textContent = '执行修复';
    healthFix.disabled = true;
    healthResults.textContent = '修复中…';
    try {
        const fd = new FormData();
        fd.append('_csrf_token', getCsrfToken());
        const r = await fetch('/admin/settings/health-fix', {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const j = await parseJsonResponse(r);
        if (!j || !j.success) {
            healthResults.innerHTML = '<div>[FAIL] 修复未完全成功' +
                (j && j.errors && j.errors.length ? ' — ' + j.errors.join('; ') : '') + '</div>';
            showToast('修复未完全成功，详见检查结果', 'error', 8000);
            return;
        }
        showToast('修复完成：补全 ' + j.applied + ' 项，清理 ' + j.deleted_rows + ' 行遗留设置', 'success', 6000);
        await runHealthCheck();
    } catch (e) {
        showToast('修复请求异常: ' + e.message, 'error', 8000);
    } finally {
        healthFix.disabled = false;
    }
});

const healthBackfillRun = document.getElementById('health-backfill-run');
let hpBackfillArmed = false, hpBackfillArmTimer = null;
healthBackfillRun?.addEventListener('click', async function() {
    if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
    if (!hpBackfillArmed) {
        hpBackfillArmed = true;
        healthBackfillRun.textContent = '再次点击确认';
        hpBackfillArmTimer = setTimeout(function() { hpBackfillArmed = false; healthBackfillRun.textContent = '开始回填'; }, 4000);
        return;
    }
    clearTimeout(hpBackfillArmTimer); hpBackfillArmed = false;
    healthBackfillRun.textContent = '开始回填';
    uploading = true;
    healthBackfillRun.disabled = true;
    const setUI = function(pct, t, d) {
        healthBackfillFill.style.width = Math.min(100, Math.round(pct * 100)) + '%';
        if (t) healthBackfillText.textContent = t;
        if (d !== undefined) healthBackfillDetail.textContent = d;
    };
    try {
        const res = await runHashBackfill(setUI);
        healthBackfillText.textContent = res.failed > 0
            ? ('回填完成，失败 ' + res.failed + ' 张')
            : '回填完成：所有图片均已携带哈希';
        healthBackfillDetail.textContent = '已补 ' + res.updated + ' 张';
        showToast('哈希回填完成：已补 ' + res.updated + ' 张', res.failed > 0 ? 'error' : 'success', 6000);
        await runHealthCheck();
    } catch (err) {
        showToast('回填请求异常: ' + err.message, 'error', 8000);
    } finally {
        healthBackfillRun.disabled = false;
        uploading = false;
    }
});
}

function initQueuePage() {
    const startBtn = document.getElementById('queue-start');
    const requeueBtn = document.getElementById('queue-requeue');
    if (!startBtn && !requeueBtn) return;
    // 提前声明供 refreshButtons 引用，避免 const 的 TDZ
    const clearBtn = document.getElementById('queue-clear');
    const clearScope = document.getElementById('queue-clear-scope');

    const NOOP = function () {};

    function busy(btn, on, label) {
        if (!btn) return;
        if (on) {
            if (!btn.dataset.label) btn.dataset.label = btn.textContent;
            btn.textContent = label;
            btn.disabled = true;
        } else {
            if (btn.dataset.label) btn.textContent = btn.dataset.label;
            btn.disabled = false;
        }
    }

    const statEls = {
        pending: document.getElementById('stat-pending'),
        processing: document.getElementById('stat-processing'),
        done: document.getElementById('stat-done'),
        failed: document.getElementById('stat-failed'),
    };
    const last = { stats: null };
    function paint(stats) {
        if (!stats) return;
        for (const key of Object.keys(statEls)) {
            const el = statEls[key];
            if (!el) continue;
            const v = Number(stats[key]);
            el.textContent = Number.isFinite(v) ? v.toLocaleString() : '0';
        }
    }
    function onTick(t) {
        if (t.phase === 'batch' || t.phase === 'done') {
            last.stats = t.stats || last.stats;
            paint(last.stats);
            // 按钮可用性只在 refreshButtons() 一处计算
            if (t.phase === 'done') refreshButtons();
        }
    }

    async function runQueue() {
        if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
        uploading = true;
        busy(startBtn, true, '处理中…');
        if (requeueBtn) requeueBtn.disabled = true;
        try {
            const res = await runProcessQueue(NOOP, onTick);
            showToast('处理完成：成功 ' + res.done + ' 张'
                + (res.failed ? '，失败 ' + res.failed + ' 张' : '')
                + (res.skipped ? '，' + res.skipped + ' 张过大仅存原图（无缩略图）' : ''),
                res.failed ? 'error' : 'success', 6000);
            setTimeout(() => window.location.reload(), 1500);
        } catch (e) {
            showToast('处理异常: ' + e.message, 'error', 8000);
        } finally {
            uploading = false;
            busy(startBtn, false);
            await refreshStats();
            refreshButtons();
            const sAfter = last.stats;
            if (sAfter && (Number(sAfter.pending) > 0 || Number(sAfter.processing) > 0)) startPolling();
        }
    }

    startBtn?.addEventListener('click', function() { runQueue(); });

    const thumbBtn = document.getElementById('queue-backfill-thumbs');
    thumbBtn?.addEventListener('click', async function() {
        if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
        uploading = true;
        busy(thumbBtn, true, '补全中…');
        try {
            const res = await runBackfillThumbs(NOOP);
            showToast('缩略图补全完成：已补 ' + res.done + ' 张' + (res.failed ? '，失败 ' + res.failed + ' 张' : ''), res.failed ? 'error' : 'success', 8000);
            setTimeout(() => window.location.reload(), 1500);
        } catch (e) {
            showToast('缩略图补全异常: ' + e.message, 'error', 10000);
        } finally {
            uploading = false;
            busy(thumbBtn, false);
            await refreshStats();
            refreshButtons();
        }
    });

    let pollTimer = null;
    let statsInFlight = false;

    async function refreshStats() {
        if (statsInFlight) return null;
        statsInFlight = true;
        try {
            const r = await fetch('/admin/images/queue-stats', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const j = await parseJsonResponse(r, '队列统计');
            if (j && j.success) {
                last.stats = j.stats || last.stats;
                paint(last.stats);
            }
            return j || null;
        } catch (e) {
            if (window.console) console.warn('queue-stats 刷新失败:', e && e.message ? e.message : e);
            return null;
        } finally {
            statsInFlight = false;
        }
    }

        // 统计未知时回落按 uploading 判断，绝不把按钮永久锁死
    function refreshButtons() {
        const s = last.stats;
        if (!s) {
            if (startBtn) startBtn.disabled = uploading;
            if (requeueBtn) requeueBtn.disabled = uploading;
            if (thumbBtn) thumbBtn.disabled = uploading;
            if (clearBtn) clearBtn.disabled = uploading;
            return;
        }
        if (startBtn) startBtn.disabled = uploading || Number(s.pending) === 0;
        if (requeueBtn) requeueBtn.disabled = uploading || (Number(s.failed) === 0 && Number(s.meta_retryable) === 0);
        if (thumbBtn) thumbBtn.disabled = uploading || Number(s.no_thumb) === 0;
        if (clearBtn) clearBtn.disabled = uploading || (Number(s.pending) + Number(s.failed) === 0);
    }

    function startPolling() {
        if (pollTimer !== null) return;
        pollTimer = setInterval(async function() {
            const j = await refreshStats();
            refreshButtons();
            const s = j && j.stats;
            if (!s || (Number(s.pending) === 0 && Number(s.processing) === 0)) stopPolling();
        }, 5000);
    }
    function stopPolling() {
        if (pollTimer !== null) { clearInterval(pollTimer); pollTimer = null; }
    }

    const queueList = document.getElementById('queue-list');
    queueList?.addEventListener('click', async function(ev) {
        const btn = ev.target && ev.target.closest ? ev.target.closest('[data-retry-id]') : null;
        if (!btn) return;
        const id = btn.getAttribute('data-retry-id');
        if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
        uploading = true;
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('_csrf_token', getCsrfToken());
            fd.append('id', id);
            const r = await fetch('/admin/images/requeue-one', {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const j = await parseJsonResponse(r, '单张重试');
            if (!j || !j.success) { showToast('重试失败: ' + ((j && j.error) || '未知错误'), 'error', 6000); return; }
            showToast('已重新入队 #' + id + '，开始处理…', 'success', 4000);
        } catch (e) {
            showToast('重试请求异常: ' + e.message, 'error', 8000);
            uploading = false;
            btn.disabled = false;
            return;
        }
        uploading = false;
        runQueue();
    });

    const dangerBox = document.getElementById('queue-danger');
    const clearLabel = clearBtn ? (clearBtn.textContent || '').trim() : '确认清空';
    let clearArmed = false, clearTimer = null;
    clearBtn?.addEventListener('click', async function() {
        if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
        const scope = clearScope ? clearScope.value : 'pending';
        if (!clearArmed) {
            clearArmed = true;
            clearBtn.textContent = '再次点击确认';
            clearTimer = setTimeout(function() {
                clearArmed = false;
                clearBtn.textContent = clearLabel;
            }, 5000);
            return;
        }
        clearTimeout(clearTimer);
        clearArmed = false;
        clearBtn.textContent = clearLabel;

        uploading = true;
        clearBtn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('_csrf_token', getCsrfToken());
            fd.append('scope', scope);
            const r = await fetch('/admin/images/queue-clear', {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const j = await parseJsonResponse(r, '清空队列');
            if (!j || !j.success) {
                showToast('清空失败: ' + ((j && j.error) || '未知错误'), 'error', 6000);
                return;
            }
            let msg = '已清空 ' + Number(j.deleted) + ' 条记录';
            if (Number(j.temp_removed) > 0) msg += '，清理临时文件 ' + Number(j.temp_removed) + ' 个';
            if (Number(j.orphan_risk) > 0) msg += '；其中 ' + Number(j.orphan_risk) + ' 张失败项的原图可能已在存储中，需自行清理';
            showToast(msg, 'success', 9000);
            setTimeout(() => window.location.reload(), 1800);
        } catch (e) {
            showToast('清空请求异常: ' + e.message, 'error', 8000);
        } finally {
            uploading = false;
            await refreshStats();
            refreshButtons();
        }
    });

    document.addEventListener('click', function(ev) {
        if (dangerBox && dangerBox.open && !dangerBox.contains(ev.target)) {
            dangerBox.open = false;
        }
    });
    document.addEventListener('keydown', function(ev) {
        if (ev.key === 'Escape' && dangerBox && dangerBox.open) {
            dangerBox.open = false;
            dangerBox.querySelector('summary')?.focus();
        }
    });

    (async function() {
        const j = await refreshStats();
        refreshButtons();
        const s = j && j.stats;
        if (s && (Number(s.pending) > 0 || Number(s.processing) > 0)) startPolling();
    })();

    requeueBtn?.addEventListener('click', async function() {
        if (uploading) { showToast('有任务进行中，请稍候', 'error', 4000); return; }
        uploading = true;
        busy(requeueBtn, true, '重试中…');
        try {
            const fd = new FormData();
            fd.append('_csrf_token', getCsrfToken());
            const r = await fetch('/admin/images/requeue-failed', {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const j = await parseJsonResponse(r, '重试失败项');
            if (!j || !j.success) throw new Error((j && j.error) || '未知错误');

            if (Number(j.requeued) === 0 && (!last.stats || Number(last.stats.meta_retryable) === 0)) {
                showToast('没有需要重试的失败项', 'success', 4000);
                uploading = false;
                return;
            }

            const res = await runProcessQueue(NOOP, onTick);
            const mres = await runThumbMetaRetry(NOOP);
            showToast('重试失败项完成：已重新处理 ' + res.done + ' 张'
                + (res.failed ? '，仍失败 ' + res.failed + ' 张' : '')
                + (mres.retried ? '；元数据补齐 ' + mres.retried + ' 张' : ''),
                res.failed ? 'error' : 'success', 9000);
            setTimeout(() => window.location.reload(), 1500);
        } catch (e) {
            showToast('重试失败项异常: ' + e.message, 'error', 10000);
        } finally {
            uploading = false;
            busy(requeueBtn, false);
            await refreshStats();
            refreshButtons();
            const sAfter = last.stats;
            if (sAfter && (Number(sAfter.pending) > 0 || Number(sAfter.processing) > 0)) startPolling();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initThemeToggle();
    initApiTester();
    initImageGrid();
    initHealthPanel();
    initQueuePage();
    initStorageCleanup();
    initOriginalConversion();
    initApiKeys();
    initCategoryActions();
    initDropZone();
    initSortable();
    initTabs();

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

// 与 views/partials/icons.php 保持同一套图标
const UX_ICON = {
    close: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>',
    prev: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg>',
    next: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>',
    copy: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>',
    check: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4.5 12.5l5 5 10-11"/></svg>',
    imageOff: '<svg class="ic" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="5" width="16" height="14" rx="2.5"/><path d="M4 5.5L20 18.5"/><path d="M20 5.5L4 18.5"/></svg>',
    menu: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>'
};

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
    // 灯箱显示 lg 缩略图，复制始终用原图
    let currentOriginal = '';

    function refreshItems() {
        items = grid ? Array.from(grid.querySelectorAll('.image-item')) : [];
    }

    function render() {
        if (current < 0 || current >= items.length) { close(); return; }
        const item = items[current];
        const url = item.dataset.url || '';
        currentOriginal = url;
        img.removeAttribute('srcset');
        img.src = item.dataset.thumbLg || url;
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

    // 点击/双击入口都先 refreshItems()，否则首次点击时 items 还是空数组
    grid?.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-image-action="view"]');
        if (!btn) return;
        e.stopPropagation();
        e.preventDefault();
        const item = btn.closest('.image-item');
        if (item) {
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

    document.getElementById('lb-close')?.addEventListener('click', close);
    document.getElementById('lb-prev')?.addEventListener('click', function() { step(-1); });
    document.getElementById('lb-next')?.addEventListener('click', function() { step(1); });
    copyBtn?.addEventListener('click', async function() {
        const url = currentOriginal || img.src || '';
        const ok = await copyText(url);
        showToast(ok ? '链接已复制到剪贴板' : '复制失败', ok ? 'success' : 'error');
    });
    box.addEventListener('click', function(e) {
        if (e.target === box) close();
    });
}

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
    let currentLbUrl = '';

    function showImage(url, category, thumbs) {
        const t = thumbs || {};
        currentLbUrl = t.lg || url;
        currentUrl = url;
        currentName = (category ? category + '-' : '') + 'random.' + (url.split('.').pop() || 'jpg');
        imgBox.removeAttribute('srcset');
        imgBox.src = t.md || url;
        imgBox.alt = category ? '随机图片：' + category : '随机图片';
        imgBox.classList.remove('hidden');
        if (placeholder) placeholder.style.display = 'none';
        if (urlEl) urlEl.textContent = url;
        if (zoomBtn) zoomBtn.classList.remove('hidden');
        if (dlBtn) dlBtn.classList.remove('hidden');
        // 含 query 即视为临时签名链接（COS/OSS 用 q-sign-*，本地 /files 用 p=）
        const isSigned = url.includes('?') || url.includes('&');
        if (metaEl) {
            metaEl.textContent = (isSigned ? '临时签名链接' : '永久链接') + (category ? ' · 分类: ' + category : '');
            metaEl.classList.remove('hidden');
        }
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

    function openLightbox() {
        if (!currentUrl) return;
        if (lbImg) { lbImg.removeAttribute('srcset'); lbImg.src = currentLbUrl || currentUrl; }
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
            const data = await parseJsonResponse(resp);
            if (!data.success || !data.data || !data.data.url) throw new Error(data.message || '请求失败');
            showImage(data.data.url, data.data.category || '', data.data.thumbs || null);
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

}

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
    sidebar.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
}

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

// CSP 禁止内联 style，动态宽高改由 data-* 经 el.style.* 设置
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

function initImageFallback() {
    document.addEventListener('error', function(e) {
        const target = e.target;
        if (target.tagName !== 'IMG') return;
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
