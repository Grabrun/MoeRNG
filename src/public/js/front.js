// 前台页面子集（home/gallery/docs/tester/about），相关改动需与 app.js 同步。

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

    // 参数与路径只此一份：URL 展示、cURL 提示、真实请求都由它派生
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
        runBtn.textContent = '加载中…';
        resultBox.innerHTML = '<div class="spinner"></div>';
        if (metaBox) metaBox.classList.add('hidden');

        const type = typeSelect.value;
        const apiPath = buildApiPath();
        const t0 = performance.now();

        // redirect 分支靠 Image 异步完成，用 Promise 让 finally 两条分支都执行
        let resolveRequest;
        const requestDone = new Promise(r => { resolveRequest = r; });

        try {
            if (type === 'redirect') {
                // 跨域 302 目标无 CORS 头时 fetch 失败，改用 Image 探测（img 渲染不受 CORS 限制）
                const tester = new Image();
                tester.alt = '随机图片';
                tester.style.cssText = 'max-width:100%;max-height:400px;object-fit:contain;';
                tester.onload = function() {
                    const ms = performance.now() - t0;
                    tester.removeAttribute('style');
                    tester.style.cssText = 'max-width:100%;max-height:400px;object-fit:contain;display:block;margin:0 auto;';
                    resultBox.innerHTML = '';
                    resultBox.appendChild(tester);
                    showMeta(200, '成功', ms);
                    saveTestHistory(apiPath, '302 → 图片（200 成功，' + formatDuration(ms) + '）');
                    resolveRequest();
                };
                tester.onerror = function() {
                    const ms = performance.now() - t0;
                    // 必须走 renderSafeText：apiPath 含用户可控参数，innerHTML 拼接等于反射型 XSS
                    window.renderSafeText(resultBox,
                        '重定向目标无法加载：地址不可达，或返回的不是图片。'
                        + '\n这是浏览器的跨域限制；用 curl 或服务端客户端跟随 302 是正常的。'
                        + '\n本测试改用 img 探测，已绕开该限制。'
                        + '\n\n请求 URL：' + apiPath, 'test-result-error');
                    showMeta(0, '失败', ms);
                    saveTestHistory(apiPath, '加载失败（' + formatDuration(ms) + '）');
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
                        + (hint ? '\n' + hint : '\n接口返回了非 JSON 错误体。常见原因是网关或伪静态（URL Rewrite）未配置，请求没有到达应用层。')
                        + '\n\n原始响应：\n' + pretty,
                        'test-result-error');
                    showMeta(resp.status, resp.statusText || '错误', ms);
                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');
                    resolveRequest();
                } else {
                    window.renderSafeText(resultBox, pretty, 'test-result-raw');
                    showMeta(resp.status, '成功', ms);
                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');
                    resolveRequest();
                }
            }

            await requestDone;
        } catch(e) {
            const ms = performance.now() - t0;
            window.renderSafeText(resultBox, '错误：' + e.message, 'test-result-error');
            showMeta(0, '网络错误', ms);
            saveTestHistory(apiPath, '错误：' + e.message);
            // 异常分支也要 resolve，否则 await requestDone 永久挂起
            resolveRequest();
        }

        runBtn.disabled = false;
        runBtn.textContent = '发送请求';
    });
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

const UX_ICON = {
    close: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>',
    prev: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg>',
    next: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>',
    copy: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>',
    check: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4.5 12.5l5 5 10-11"/></svg>',
    imageOff: '<svg class="ic" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="5" width="16" height="14" rx="2.5"/><path d="M4 5.5L20 18.5"/><path d="M20 5.5L4 18.5"/></svg>',
    menu: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>'
};

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

    // 预览框用 md、灯箱用 lg；下载与复制链接始终是原图
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
        // 含 query 即临时签名链接（COS/OSS 用 q-sign-*，本地 /files 用 p=）
        const isSigned = url.includes('?') || url.includes('&');
        if (metaEl) {
            metaEl.textContent = (isSigned ? '临时签名链接' : '永久链接') + (category ? ' · 分类：' + category : '');
            metaEl.classList.remove('hidden');
        }
        // 抽卡动效仅在未开启 prefers-reduced-motion 时执行
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

document.addEventListener('DOMContentLoaded', function() {
    initThemeToggle();   // defined in helpers.js (loaded first)
    initApiTester();
    initCopyButtons();
    initReveal();
    initRandomDemo();
    initStatCount();
    applyDynamicStyles();
});
