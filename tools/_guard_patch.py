import re

p = 'src/public/js/helpers.js'
s = open(p, encoding='utf-8').read()

# 在文件开头（theme 检测 IIFE 之后）插入统一事件委托层
anchor = """(function () {
    'use strict';

    // -- Theme detection (FOUT-guard): apply data-theme before paint --
    var t = localStorage.getItem('moerng-theme');
    if (!t) { t = 'dark'; }
    document.documentElement.setAttribute('data-theme', t);
})();
"""
delegate = anchor + """

/**
 * v1.2.1 CSP nonce 修复 (V-02): 统一事件委托层。
 * CSP 移除 'unsafe-inline' 后，inline onclick/onchange 属性全部被浏览器拦截，
 * 所有后台交互（modal 开关/编辑按钮/验证码刷新/自动提交等）迁移到 data-* 委托。
 */
(function () {
    'use strict';

    // openModal/closeModal 全局函数（供部分未迁移点兼容）
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

    // 委托：data-open-modal / data-close-modal（modal 开关）
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

    // 委托：编辑分类按钮（data-edit-category 携带 JSON）
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

    // 委托：编辑用户按钮（data-edit-user 携带 JSON）
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

    // 委托：上传图片按钮 / 预览关闭（data-toggle-class="id" + data-class="active"）
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-toggle-class]');
        if (!t) return;
        var el = document.getElementById(t.getAttribute('data-toggle-class'));
        if (!el) return;
        el.classList.toggle(t.getAttribute('data-class') || 'active');
    });

    // 委托：storage 驱动切换（data-storage-driver-toggle）
    document.addEventListener('change', function (e) {
        var t = e.target.closest('[data-storage-driver-toggle]');
        if (!t) return;
        if (window.toggleStorageFields) window.toggleStorageFields();
    });
})();
"""
if anchor in s:
    s = s.replace(anchor, delegate, 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('OK: delegate layer inserted')
else:
    print('MISS anchor')
