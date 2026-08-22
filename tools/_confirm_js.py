p = 'src/public/js/helpers.js'
s = open(p, encoding='utf-8').read()

# 在委托层末尾（storage driver change 委托后）补 confirm 处理
anchor = """    // 委托：storage 驱动切换（data-storage-driver-toggle）
    document.addEventListener('change', function (e) {
        var t = e.target.closest('[data-storage-driver-toggle]');
        if (!t) return;
        if (window.toggleStorageFields) window.toggleStorageFields();
    });
})();
"""
addition = """    // 委托：storage 驱动切换（data-storage-driver-toggle）
    document.addEventListener('change', function (e) {
        var t = e.target.closest('[data-storage-driver-toggle]');
        if (!t) return;
        if (window.toggleStorageFields) window.toggleStorageFields();
    });

    // 委托：确认对话框 — data-confirm（click 型）/ data-confirm-submit（submit 型）
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
"""
if anchor in s:
    s = s.replace(anchor, addition, 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('OK: confirm delegation added')
else:
    print('MISS anchor')
