# -*- coding: utf-8 -*-
"""字体 CSP 修复：font-src 放行字体文件实际域名 sf3-scmcdn-cn.feishucdn.com
   （miaoda.feishu.cn 的 css2 入口返回的 @font-face 指向 feishucdn.com 分片，两跳分离）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\app\Core\Application.php"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "        // CSP uses nonce for script-src and style-src (see CspNonce).\n"
).replace('\n', nl)
anchor = "header(\"Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}' https://miaoda.feishu.cn; font-src 'self' https://miaoda.feishu.cn; img-src {$imgSrc}; connect-src 'self'; frame-ancestors 'self'\");".replace('\n', nl)
new = "header(\"Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}' https://miaoda.feishu.cn; font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com; img-src {$imgSrc}; connect-src 'self'; frame-ancestors 'self'\");".replace('\n', nl)

if anchor not in t:
    raise SystemExit('[FAIL] CSP 行锚点')
t = t.replace(anchor, new, 1)

# 同步更新上方注释（可选增强：说明两跳域名）
note_old = "        // CSP uses nonce for script-src and style-src (see CspNonce).\n".replace('\n', nl)
note_new = (
    "        // CSP uses nonce for script-src and style-src (see CspNonce).\n"
    "        // v2.0.0-beta.8: font-src 放行 sf3-scmcdn-cn.feishucdn.com —— miaoda.feishu.cn 的\n"
    "        // css2 入口返回的 @font-face 实际指向 feishucdn.com 的 woff2 分片（两跳分离），\n"
    "        // 此前仅放行入口域名导致所有字体被 CSP 拦截（控制台批量报错、回退系统字体）。\n"
).replace('\n', nl)
if note_old in t:
    t = t.replace(note_old, note_new, 1)

with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] Application.php CSP font-src 放行字体域名')
