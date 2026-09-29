# -*- coding: utf-8 -*-
"""v2.0.0-beta.10 hotfix：白屏防御性修复

1. 视图内 CspNonce::attr() 改全限定名 \App\Core\CspNonce::attr() ——
   消除对「视图在 App\Core 命名空间上下文被 include」的隐式依赖：
   若任一渲染路径不在该上下文（install/旁路），未限定名会解析为 \CspNonce
   导致 PHP Fatal Error（Class not found）→ 整页白屏 + ob 缓冲丢弃。
2. 字体切换脚本 try/catch 健壮化（任何异常都静默降级，不中断页面）。
3. CSP connect-src 放行字体两跳域（preconnect 不再被 connect-src 'self' 拦截报错）。
幂等。
"""
import io, os

ROOT = r"E:\Projects\DouBao\MoeRNG"

def read(p):
    with io.open(os.path.join(ROOT, p), encoding='utf-8') as f:
        return f.read()

def write(p, t):
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8', newline='') as f:
        f.write(t)

# ---- 1) front_header.php ----
p1 = 'src/views/partials/front_header.php'
t1 = read(p1)
old_script = '<script<?= CspNonce::attr() ?>>'
new_script = '<script<?= \\App\\Core\\CspNonce::attr() ?>>'
old_body = "(function(){var l=document.getElementById('font-css');if(l){l.media='all';}})();"
new_body = "try{var l=document.getElementById('font-css');if(l){l.media='all';}}catch(e){}"
if old_script in t1 and old_body in t1:
    t1 = t1.replace(old_script, new_script, 1).replace(old_body, new_body, 1)
    write(p1, t1)
    print('[OK] front_header.php：全限定名 + try/catch')
else:
    print('[SKIP] front_header.php 目标未命中（可能已修复）')

# ---- 2) admin/layout.php ----
p2 = 'src/views/admin/layout.php'
t2 = read(p2)
if old_script in t2 and old_body in t2:
    t2 = t2.replace(old_script, new_script, 1).replace(old_body, new_body, 1)
    write(p2, t2)
    print('[OK] admin/layout.php：全限定名 + try/catch')
else:
    print('[SKIP] admin/layout.php 目标未命中（可能已修复）')

# ---- 3) CSP connect-src 放行字体域（Application.php）----
p3 = 'src/app/Core/Application.php'
t3 = read(p3)
old_csp = "connect-src 'self'; frame-ancestors 'self'"
new_csp = "connect-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com; frame-ancestors 'self'"
if old_csp in t3:
    t3 = t3.replace(old_csp, new_csp, 1)
    write(p3, t3)
    print('[OK] Application.php：connect-src 放行字体两跳域')
else:
    print('[SKIP] Application.php CSP 行未命中（可能已修复）')

print('[DONE]')
