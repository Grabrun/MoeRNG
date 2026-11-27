// MoeRNG 共享 helpers：在 app.js 之前加载，所有页面（含登录页）都使用。

(function () {
    'use strict';

    // 首帧前应用主题，避免闪烁
    var t = localStorage.getItem('moerng-theme');
    if (!t) { t = 'dark'; }
    document.documentElement.setAttribute('data-theme', t);
})();

// 必须用 textContent：响应原文含分类名等数据库字段，拼进 innerHTML 即存储型 XSS
// 样式必须走 class：CSP 无 unsafe-inline，innerHTML 解析出的 style 属性会被静默丢弃
window.renderSafeText = function (container, text, cssClass) {
    if (!container) { return; }
    var pre = document.createElement('pre');
    pre.textContent = text == null ? '' : String(text);
    if (cssClass) { pre.className = cssClass; }
    container.textContent = '';
    container.appendChild(pre);
};

window.escapeHtml = function (str) {
    if (str === null || str === undefined) { return ''; }
    return String(str).replace(/[&<>"']/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
};

// 定义在 helpers.js，登录页（无 app.js）也能切换主题
window.toggleTheme = function () {
    var root = document.documentElement;
    var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('moerng-theme', next); } catch (e) { /* private mode */ }
};

function initThemeToggle() {
    document.querySelectorAll('.theme-toggle').forEach(function (btn) {
        btn.addEventListener('click', window.toggleTheme);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initThemeToggle);
} else {
    initThemeToggle();
}

// CSP 移除 unsafe-inline 后内联 onclick 全部失效，统一改为 data-* 事件委托
(function () {
    'use strict';

    if (!window.openModal) {
        window.openModal = function (id) {
            var el = document.getElementById(id);
            if (el) el.classList.add('active');
        };
    }
    if (!window.closeModal) {
        window.closeModal = function (id) {
            var el = document.getElementById(id);
            if (el) el.classList.remove('active');
        };
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-open-modal]');
        if (t) { openModal(t.getAttribute('data-open-modal')); return; }
        t = e.target.closest('[data-close-modal]');
        if (t) { closeModal(t.getAttribute('data-close-modal')); return; }
        t = e.target.closest('[data-refresh-captcha]');
        if (t) { t.src = '/admin/captcha?' + Date.now(); return; }
        t = e.target.closest('[data-auto-submit]');
        if (t) { var f = t.closest('form'); if (f) f.submit(); return; }
    });

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-edit-category]');
        if (!t) return;
        var d;
        try { d = JSON.parse(t.getAttribute('data-edit-category') || '{}'); }
        catch (err) { return; }
        if (window.editCategory) {
            window.editCategory(d.id, d.name || '', d.slug || '', d.desc || '', d.parent_id != null ? d.parent_id : '', d.sort_order != null ? d.sort_order : 0);
        }
    });

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-edit-user]');
        if (!t) return;
        var d;
        try { d = JSON.parse(t.getAttribute('data-edit-user') || '{}'); }
        catch (err) { return; }
        if (window.editUser) {
            window.editUser(d.id, d.username || '', d.email || '', d.role || '');
        }
    });

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-toggle-class]');
        if (!t) return;
        var el = document.getElementById(t.getAttribute('data-toggle-class'));
        if (!el) return;
        el.classList.toggle(t.getAttribute('data-class') || 'active');
    });

    document.addEventListener('change', function (e) {
        var t = e.target.closest('[data-storage-driver-toggle]');
        if (!t) return;
        if (window.toggleStorageFields) window.toggleStorageFields();
    });

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-confirm]');
        if (!t) return;
        var msg = t.getAttribute('data-confirm') || '确定执行该操作？';
        if (!window.confirm(msg)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);
    document.addEventListener('submit', function (e) {
        var t = e.target.closest('[data-confirm-submit]');
        if (!t) return;
        var msg = t.getAttribute('data-confirm-submit') || '确定执行该操作？';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    }, true);
})();

(function () {
    'use strict';

    var links = document.querySelectorAll('.site-nav-links a');
    links.forEach(function (a) {
        a.addEventListener('click', function () {
            links.forEach(function (x) { x.classList.remove('active'); });
            a.classList.add('active');
        });
    });

    // 小屏移除 open 让汉堡可展开；桌面需要 open 才显示链接
    var menu = document.querySelector('.site-nav-menu');
    if (!menu) return;
    var sync = function () { menu.removeAttribute('open'); };
    if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
        sync();
    }
    window.addEventListener('resize', function () {
        if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
            sync();
        }
    });
})();

(function () {
    // 用 classList 与视图渲染的 hidden 类保持同一来源
    var tabs = document.querySelectorAll('.settings-tab');
    var groups = document.querySelectorAll('.settings-group');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            var gid = tab.getAttribute('href').split('#')[1];
            groups.forEach(function (g) { g.classList.toggle('hidden', g.dataset.group !== gid); });
            tabs.forEach(function (t) { t.classList.toggle('btn-primary', t === tab); });
        });
    });
    var box = document.getElementById('settings-search');
    if (box) {
        box.addEventListener('input', function () {
            var q = box.value.trim().toLowerCase();
            groups.forEach(function (g) {
                if (g.classList.contains('hidden')) return;
                g.querySelectorAll('.setting-item').forEach(function (item) {
                    item.style.display = (q === '' || item.dataset.search.indexOf(q) !== -1) ? '' : 'none';
                });
            });
        });
    }
    document.querySelectorAll('.toggle input[type=checkbox]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var text = cb.closest('.toggle-wrap').querySelector('.toggle-text');
            if (text) text.textContent = cb.checked ? '已开启' : '已关闭';
        });
    });

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';
    document.querySelectorAll('[data-logo-upload]').forEach(function (btn) {
        var field = btn.dataset.logoUpload;
        var fileInput = document.getElementById('logo-file-' + field);
        var urlInput = document.querySelector('.logo-uploader[data-field="' + field + '"] input[name="' + field + '"]');
        btn.addEventListener('click', function () {
            fileInput.click();
        });
        fileInput.addEventListener('change', function () {
            var f = fileInput.files[0];
            if (!f) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                var img = document.getElementById('logo-preview-' + field);
                img.src = e.target.result;
                img.style.display = 'block';
                var hint = document.getElementById('logo-default-hint-' + field);
                if (hint) hint.style.display = 'none';
            };
            reader.readAsDataURL(f);
            var fd = new FormData();
            fd.append('logo_file', f);
            fd.append('_csrf_token', csrf);
            btn.disabled = true;
            btn.textContent = '上传中…';
            fetch('/admin/settings/logo-upload', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false;
                    btn.textContent = '上传';
                    if (res.success) {
                        urlInput.value = res.url;
                        var img = document.getElementById('logo-preview-' + field);
                        img.src = res.url;
                        img.style.display = 'block';
                        var hint = document.getElementById('logo-default-hint-' + field);
                        if (hint) hint.style.display = 'none';
                        var row = btn.closest('.logo-upload-row');
                        if (row && !row.querySelector('[data-logo-remove]')) {
                            var rm = document.createElement('button');
                            rm.type = 'button';
                            rm.className = 'btn btn-sm';
                            rm.setAttribute('data-logo-remove', field);
                            rm.textContent = '移除 Logo';
                            row.insertBefore(rm, urlInput);
                            bindLogoRemove(rm);
                        }
                        if (window.toast) toast('Logo 上传成功', 'success');
                        else alert('Logo 上传成功');
                    } else {
                        if (window.toast) toast(res.message || '上传失败', 'error');
                        else alert(res.message || '上传失败');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = '上传';
                    if (window.toast) toast('网络错误，请重试', 'error');
                    else alert('网络错误，请重试');
                });
        });
    });
    function bindLogoRemove(btn) {
        btn.addEventListener('click', function () {
            var field = btn.dataset.logoRemove;
            var urlInput = document.querySelector('.logo-uploader[data-field="' + field + '"] input[name="' + field + '"]');
            urlInput.value = '';
            var img = document.getElementById('logo-preview-' + field);
            img.src = '/assets/logo.png';
            img.style.display = 'block';
            var hint = document.getElementById('logo-default-hint-' + field);
            if (hint) hint.style.display = '';
            btn.style.display = 'none';
        });
    }
    document.querySelectorAll('[data-logo-remove]').forEach(bindLogoRemove);
})();

function editUser(id, username, email, role) {
    document.getElementById('user-modal-title').textContent = '编辑用户';
    document.getElementById('user-id').value = id;
    document.getElementById('user-username').value = username;
    document.getElementById('user-email').value = email;
    document.getElementById('user-role').value = role;
    document.getElementById('user-password').value = '';
    document.getElementById('pwd-hint').textContent = '(留空不修改)';
    document.getElementById('user-form').action = '/admin/users/update';
    document.getElementById('user-modal').classList.add('active');
}
if (document.getElementById('user-modal')) {
    var newUserBtn = document.querySelector('[data-open-modal="user-modal"]');
    if (newUserBtn) newUserBtn.addEventListener('click', function() {
        document.getElementById('user-modal-title').textContent = '新建用户';
        document.getElementById('user-id').value = '';
        document.getElementById('user-username').value = '';
        document.getElementById('user-email').value = '';
        document.getElementById('user-password').value = '';
        document.getElementById('pwd-hint').textContent = '';
        document.getElementById('user-form').action = '/admin/users/create';
    });
}

var t=localStorage.getItem('moerng-theme');if(!t){t='dark';}document.documentElement.setAttribute('data-theme',t);

    (function () {
        var links = document.querySelectorAll('.site-nav-links a');
        links.forEach(function (a) {
            a.addEventListener('click', function () {
                links.forEach(function (x) { x.classList.remove('active'); });
                a.classList.add('active');
            });
        });
    })();
    (function () {
        var menu = document.querySelector('.site-nav-menu');
        if (!menu) return;
        var sync = function () { menu.removeAttribute('open'); };
        if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) sync();
        window.addEventListener('resize', function () {
            if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) sync();
        });
    })();

var t=localStorage.getItem('moerng-theme');if(!t){t='dark';}document.documentElement.setAttribute('data-theme',t);

(function() {
    const table = document.getElementById('profile-table');
    if (!table) return;
    const form = document.getElementById('profile-form');
    const modal = document.getElementById('profile-modal');
    const submitBtn = document.getElementById('profile-submit');
    const title = document.getElementById('profile-modal-title');

    // 各 provider 的字段定义：null = 该 provider 隐藏此字段
    const PV_FIELDS = {
        cos:   { key:{l:'SecretId', p:'AKID 开头', r:true}, secret:{l:'SecretKey', p:'', r:true},
                 region:{l:'Region', p:'ap-guangzhou / ap-shanghai…', r:true},
                 bucket:{l:'Bucket', p:'mybucket-1250000000（需带 APPID）', r:true}, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false},
                 source_domain:{l:'自定义源站域名（可选）', p:'在 COS 控制台「自定义源站域名」绑定后填入', r:false},
                 signed_ttl:{l:'签名链接有效期（秒）', p:'300（5 分钟，对象存储预签名/本地临时链接）', r:false} },
        oss:   { key:{l:'AccessKeyId', p:'LTAI 开头', r:true}, secret:{l:'AccessKeySecret', p:'', r:true},
                 region:{l:'Region', p:'cn-hangzhou / oss-cn-…', r:true},
                 bucket:{l:'Bucket', p:'mybucket', r:true}, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false},
                 source_domain:{l:'自定义源站域名（可选）', p:'在 OSS 控制台绑定自定义域名后填入', r:false},
                 signed_ttl:{l:'签名链接有效期（秒）', p:'300（5 分钟，对象存储预签名/本地临时链接）', r:false} },
        aws:   { key:{l:'Access Key ID', p:'AKIA 开头', r:true}, secret:{l:'Secret Access Key', p:'', r:true},
                 region:{l:'Region', p:'us-east-1 / ap-southeast-1…', r:true},
                 bucket:{l:'Bucket', p:'mybucket', r:true}, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false},
                 source_domain:{l:'自定义源站域名（可选）', p:'S3 兼容网关或自定义 CNAME（如 MinIO/R2），留空用 region 默认域名', r:false},
                 signed_ttl:{l:'签名链接有效期（秒）', p:'300（5 分钟，对象存储预签名/本地临时链接）', r:false} },
        obs:   { key:{l:'Access Key', p:'', r:true}, secret:{l:'Secret Key', p:'', r:true}, region:null,
                 bucket:{l:'Bucket', p:'mybucket', r:true},
                 endpoint:{l:'Endpoint', p:'https://obs.cn-north-4.myhuaweicloud.com', r:true},
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false},
                 source_domain:{l:'自定义源站域名（可选）', p:'OBS 绑定自定义域名后填入（优先于 Endpoint）', r:false},
                 signed_ttl:{l:'签名链接有效期（秒）', p:'300（5 分钟，对象存储预签名/本地临时链接）', r:false} },
        upyun: { bucket:{l:'服务名（Service = Bucket）', p:'mybucket', r:true},
                 key:{l:'操作员名（Operator）', p:'', r:true}, secret:{l:'操作员密码（Password）', p:'', r:true},
                 region:null, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false},
                 source_domain:{l:'自定义源站域名（可选）', p:'又拍云控制台绑定域名后填入', r:false},
                 signed_ttl:{l:'签名链接有效期（秒）', p:'300（5 分钟，对象存储预签名/本地临时链接）', r:false},
                 token_key:{l:'Token 防盗链密钥（可选）', p:'又拍云控制台「防盗链」密钥；留空=不签名', r:false} },
        qiniu: { key:{l:'Access Key', p:'', r:true}, secret:{l:'Secret Key', p:'', r:true},
                 region:{l:'区域（Region）', p:'z0(华东) / z1(华北) / z2(华南) / z3(华东2) / as0(新加坡) / na0(北美)', r:true},
                 bucket:{l:'空间名（Bucket）', p:'mybucket', r:true}, endpoint:null,
                 cdn:{l:'CDN 下载域名（可选）', p:'https://cdn.example.com', r:false},
                 source_domain:{l:'自定义源站域名（可选）', p:'七牛控制台绑定域名后填入', r:false},
                 signed_ttl:{l:'签名链接有效期（秒）', p:'300（5 分钟，对象存储预签名/本地临时链接）', r:false} },
    };
    // 凭据字段编辑时留空 = 保留原值
    const SECRET_FIELDS = { key: 1, secret: 1, token_key: 1 };

    function applyProvider(pv) {
        const def = PV_FIELDS[pv] || PV_FIELDS.cos;
        document.querySelectorAll('.pv-field').forEach(function(el) {
            const f = el.dataset.field;
            const d = def[f];
            if (!d) { el.classList.add('hidden'); return; }
            el.classList.remove('hidden');
            const label = el.querySelector('.pv-label');
            const req = el.querySelector('.pv-req');
            const input = el.querySelector('input');
            if (label) label.textContent = d.l;
            if (req) req.style.display = d.r ? 'inline' : 'none';
            if (input && !input.value) input.placeholder = d.p || '';
        });
    }
    document.getElementById('profile-provider').addEventListener('change', function() {
        applyProvider(this.value);
    });

    function toggleFields() {
        const isS3 = document.getElementById('profile-driver').value === 's3';
        document.getElementById('profile-local-fields').classList.toggle('hidden', isS3);
        document.getElementById('profile-s3-fields').classList.toggle('hidden', !isS3);
        document.getElementById('profile-provider-group').classList.toggle('hidden', !isS3);
        if (isS3) applyProvider(document.getElementById('profile-provider').value);
    }
    document.getElementById('profile-driver').addEventListener('change', toggleFields);

    function resetForm(mode, row) {
        form.reset();
        document.getElementById('profile-id').value = row ? row.dataset.id : '';
        document.getElementById('profile-name').value = row ? row.dataset.name : '';
        document.getElementById('profile-driver').value = row ? (row.dataset.driver || 'local') : 'local';
        document.getElementById('profile-provider').value = row ? (row.dataset.provider || 'cos') : 'cos';

        let cfg = {};
        try { cfg = row ? JSON.parse(row.dataset.config || '{}') : {}; } catch (e) { cfg = {}; }
        document.getElementById('profile-cfg-path').value = cfg.path || '';
        document.getElementById('profile-cfg-cdn-local').value = cfg.cdn || '';
        document.getElementById('profile-cfg-key').value = '';
        document.getElementById('profile-cfg-secret').value = '';
        document.getElementById('profile-cfg-token-key').value = '';
        document.getElementById('profile-cfg-region').value = cfg.region || '';
        document.getElementById('profile-cfg-bucket').value = cfg.bucket || '';
        document.getElementById('profile-cfg-endpoint').value = cfg.endpoint || '';
        document.getElementById('profile-cfg-cdn').value = cfg.cdn || '';
        document.getElementById('profile-cfg-source-domain').value = cfg.source_domain || '';
        document.getElementById('profile-cfg-signed-ttl').value = cfg.signed_ttl || '';
        document.getElementById('profile-cfg-signed-ttl-local').value = cfg.signed_ttl || '';
        document.getElementById('profile-is-default').checked = row ? row.dataset.default === '1' : false;

        toggleFields();
        if (mode === 'update') {
            document.getElementById('profile-cfg-key').placeholder = '留空则保持原值';
            document.getElementById('profile-cfg-secret').placeholder = '留空则保持原值';
            document.getElementById('profile-cfg-token-key').placeholder = '留空则保持原值';
        }
        title.textContent = mode === 'update' ? '编辑存储实例' : '新增存储实例';
        submitBtn.textContent = mode === 'update' ? '保存修改' : '创建实例';
    }

    document.getElementById('profile-new').addEventListener('click', function() {
        resetForm('create', null);
        modal.classList.add('active');
    });

    submitBtn.addEventListener('click', async function() {
        const id = document.getElementById('profile-id').value;
        const mode = id ? 'update' : 'create';
        // local 用自己的 ttl / cdn 输入：driver=local 时 s3 那组是隐藏的
        const _ttlEl = document.getElementById('profile-driver').value === 's3'
            ? document.getElementById('profile-cfg-signed-ttl')
            : document.getElementById('profile-cfg-signed-ttl-local');
        const _cdnEl = document.getElementById('profile-driver').value === 's3'
            ? document.getElementById('profile-cfg-cdn')
            : document.getElementById('profile-cfg-cdn-local');
        const payload = {
            id: id,
            name: document.getElementById('profile-name').value.trim(),
            driver: document.getElementById('profile-driver').value,
            provider: document.getElementById('profile-provider').value,
            cfg_key: document.getElementById('profile-cfg-key').value.trim(),
            cfg_secret: document.getElementById('profile-cfg-secret').value,
            cfg_region: document.getElementById('profile-cfg-region').value.trim(),
            cfg_bucket: document.getElementById('profile-cfg-bucket').value.trim(),
            cfg_endpoint: document.getElementById('profile-cfg-endpoint').value.trim(),
            cfg_cdn: _cdnEl ? _cdnEl.value.trim() : '',
            cfg_source_domain: document.getElementById('profile-cfg-source-domain').value.trim(),
            cfg_signed_ttl: _ttlEl ? _ttlEl.value.trim() : '',
            cfg_token_key: document.getElementById('profile-cfg-token-key').value,
            cfg_path: document.getElementById('profile-cfg-path').value.trim(),
            is_default: document.getElementById('profile-is-default').checked ? '1' : '0'
        };
        if (!payload.name) { showToast('请填写实例名称', 'error'); return; }
        if (payload.driver === 's3') {
            const def = PV_FIELDS[payload.provider] || PV_FIELDS.cos;
            const miss = [];
            for (const f in def) {
                if (mode === 'update' && SECRET_FIELDS[f]) continue;
                if (def[f] && def[f].r && !(payload['cfg_' + f] || '').trim()) {
                    miss.push(def[f].l);
                }
            }
            if (miss.length) {
                showToast('对象存储实例需填写完整的：' + miss.join(' / '), 'error'); return;
            }
        }

        submitBtn.disabled = true;
        try {
            const result = await adminPost('/admin/storage/profiles/' + mode, payload);
            modal.classList.remove('active');
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 500);
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    table.addEventListener('click', async function(e) {
        const btn = e.target.closest('[data-profile-action]');
        if (!btn) return;
        e.preventDefault();
        const row = btn.closest('tr');
        const id = row?.dataset.id;
        const action = btn.dataset.profileAction;

        if (action === 'edit') { resetForm('update', row); modal.classList.add('active'); return; }

        if (action === 'delete') {
            if (!confirm('确定删除存储实例「' + (row?.dataset.name || '') + '」？此操作不可撤销。')) return;
        }

        btn.disabled = true;
        try {
            const url = '/admin/storage/profiles/' + action;
            const result = await adminPost(url, { id: id });
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 400);
        } catch (err) {
            showToast(err.message, 'error');
            btn.disabled = false;
        }
    });
})();

if (document.getElementById('category-modal')) {
function editCategory(id, name, slug, desc, parentId, sort) {
    document.getElementById('category-modal-title').textContent = '编辑分类';
    document.getElementById('cat-id').value = id;
    document.getElementById('cat-name').value = name;
    document.getElementById('cat-slug').value = slug;
    document.getElementById('cat-desc').value = desc;
    document.getElementById('cat-parent').value = parentId;
    document.getElementById('cat-sort').value = sort;
    document.getElementById('category-form').action = '/admin/categories/update';
    document.getElementById('category-modal').classList.add('active');
}

var newCatBtn = document.querySelector('[data-open-modal="category-modal"]');
if (newCatBtn) newCatBtn.addEventListener('click', function() {
    document.getElementById('category-modal-title').textContent = '新建分类';
    document.getElementById('cat-id').value = '';
    document.getElementById('cat-name').value = '';
    document.getElementById('cat-slug').value = '';
    document.getElementById('cat-desc').value = '';
    document.getElementById('cat-parent').value = '';
    document.getElementById('cat-sort').value = '0';
    document.getElementById('category-form').action = '/admin/categories/create';
});
}

var t=localStorage.getItem('moerng-theme');if(!t){t='dark';}document.documentElement.setAttribute('data-theme',t);

function toggleStorageFields() {
        const val = document.getElementById('storage-driver-select').value;
        document.getElementById('local-fields').classList.toggle('hidden', val !== 'local');
        document.getElementById('s3-fields').classList.toggle('hidden', val !== 's3');
    }
