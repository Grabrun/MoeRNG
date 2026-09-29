// MoeRNG - Front-site JavaScript (v2.0.0-beta.9 性能优化拆分)
//
// 本文件是 app.js 的前台子集，只包含前台页面（home/gallery/docs/tester/about）
// 实际使用的功能；后台管理页仍加载完整的 app.js（本文件不与之同页加载，无冲突）。
// 来源保持与 app.js 逐字节一致（由 docs/scripts/patch_m6_perf.py 按行切片生成），
// 任何前台交互的修改应同步到 app.js 或回归本文件。

// ---------- Toast (app.js L1-L23) ----------
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

// ---------- copyText (app.js L113-L128) ----------
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

// ---------- initApiTester (app.js L131-L309) ----------
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

    // v1.5.0-beta.2: 参数构造与路径拼装**只有这一份** —— URL 展示、cURL 提示、
    // 实际发出的请求全部由它派生。此前 updateUrl() 与点击处理器各写一遍参数逻辑，
    // 加了 size 之后必然漂移：页面显示的 URL 与实际请求不一致，测试页就失去了
    // "所见即所测"的意义。
    function buildParams() {
        const params = new URLSearchParams();
        if (categorySelect.value) params.set('category', categorySelect.value);
        if (typeSelect.value) params.set('type', typeSelect.value);
        // 空值 = 不传 size：由服务端决定缺省（JSON 为 md、redirect 为原图），
        // 测试页因此也能验证「向后兼容」这一条。
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
        // v1.2.1-beta.3: 守卫式禁用 + 视觉 Loading 反馈（防止用户在异步完成前重复点击）
        runBtn.disabled = true;
        runBtn.textContent = '加载中…';
        resultBox.innerHTML = '<div class="spinner"></div>';
        if (metaBox) metaBox.classList.add('hidden');

        const type = typeSelect.value;
        // 与 updateUrl() 同源：页面上显示的 URL 与实际请求只差一个 origin 前缀
        const apiPath = buildApiPath();
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
            if (type === 'redirect') {
                // fetch() following a 302 to a cross-origin object-storage URL
                // (e.g. COS bucket) blows up with "Failed to fetch" when the
                // bucket doesn't serve CORS headers — yet the image itself
                // loads fine in <img>. So we test the redirect target as an
                // Image element: it auto-follows the 302, doesn't require
                // CORS for the <img> render path, and correctly reports
                // load/error so the tester reflects real-world usability.
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
                    resultBox.innerHTML =
                        '<pre style="color:var(--danger)">重定向目标无法加载（目标地址不可达或返回非图片）。'
                        + '\n这是一个客户端 CORS 探测限制 —— 在 curl / 服务器端 HTTP 客户端中跟随 302 是正常的，'
                        + '浏览器 fetch 在跨域时会拦截（Failed to fetch）。'
                        + '\n本测试改用 img 探测，已绕开此限制。'
                        + '\n\n请求 URL：' + apiPath + '</pre>';
                    showMeta(0, '失败', ms);
                    saveTestHistory(apiPath, '加载失败（' + formatDuration(ms) + '）');
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
                // v2.0.0-beta.5: 非 2xx 响应优雅化 —— JSON 错误体直接展示服务端 message
                // （空库引导等）；非 JSON 体（nginx 默认错误页等）说明网关/伪静态未配置，
                // 请求根本没到应用层，给可读提示而不是裸「404 Not Found / nginx」。
                if (!resp.ok) {
                    let hint = '';
                    try { const j = JSON.parse(text); hint = (j && (j.message || j.error)) || ''; }
                    catch (e2) { /* non-JSON body */ }
                    resultBox.innerHTML =
                        '<pre style="color:var(--danger)">请求失败（HTTP ' + resp.status + '）'
                        + (hint ? '\n' + hint : '\n接口返回了非 JSON 错误体 —— 多为网关/伪静态（URL Rewrite）未配置，请求未到达应用层。')
                        + '\n\n原始响应：\n' + pretty + '</pre>';
                    showMeta(resp.status, resp.statusText || '错误', ms);
                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');
                    resolveRequest();
                } else {
                    resultBox.innerHTML = '<pre>' + pretty + '</pre>';
                    showMeta(resp.status, resp.statusText || '成功', ms);
                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');
                    resolveRequest();
                }
            }

            await requestDone;
        } catch(e) {
            const ms = performance.now() - t0;
            resultBox.innerHTML = '<pre style="color:var(--danger)">错误：' + e.message + '</pre>';
            showMeta(0, '网络错误', ms);
            saveTestHistory(apiPath, '错误：' + e.message);
            // v1.2.1-beta.3 修复: 异常分支也要 resolve，否则 await requestDone 永远挂起 → 按钮卡 Loading
            resolveRequest();
        }

        runBtn.disabled = false;
        runBtn.textContent = '发送请求';
    });
}

// ---------- initCopyButtons (app.js L1267-L1285) ----------
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

// ---------- parseJsonResponse (app.js L1363-L1381) ----------
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

// ---------- UX_ICON (app.js L2351-L2359) ----------
const UX_ICON = {
    close: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>',
    prev: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg>',
    next: '<svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>',
    copy: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>',
    check: '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4.5 12.5l5 5 10-11"/></svg>',
    imageOff: '<svg class="ic" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="5" width="16" height="14" rx="2.5"/><path d="M4 5.5L20 18.5"/><path d="M20 5.5L4 18.5"/></svg>',
    menu: '<svg class="ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>'
};

// ---------- initReveal (app.js L2464-L2480) ----------
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

// ---------- initRandomDemo (app.js L2483-L2612) ----------
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
    let currentLbUrl = '';   // v1.3.2-beta.2: 灯箱用的 lg 缩略图（回退原图）

    // v1.3.2-beta.2: thumbs 为 API 返回的多尺寸映射（sm/md/lg）——预览框用 md
    // （CSS 上限 480px），灯箱用 lg；下载与复制链接始终是原图 url。
    function showImage(url, category, thumbs) {
        const t = thumbs || {};
        currentLbUrl = t.lg || url;
        currentUrl = url;
        currentName = (category ? category + '-' : '') + 'random.' + (url.split('.').pop() || 'jpg');
        imgBox.removeAttribute('srcset');
        imgBox.src = t.md || url;
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
            metaEl.textContent = (isSigned ? '临时签名链接' : '永久链接') + (category ? ' · 分类：' + category : '');
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

    // v1.2.1-beta.3 移除: 历史缩略图已删除（renderHistory / saveHistory 一并移除）
}

// ---------- initStatCount (app.js L2653-L2679) ----------
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

// ---------- applyDynamicStyles (app.js L2684-L2697) ----------
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

// ---------- Init on DOM ready (front-site subset) ----------
document.addEventListener('DOMContentLoaded', function() {
    initThemeToggle();   // defined in helpers.js (loaded first)
    initApiTester();
    initCopyButtons();
    initReveal();
    initRandomDemo();
    initStatCount();
    applyDynamicStyles();
});
