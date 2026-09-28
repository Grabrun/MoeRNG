# -*- coding: utf-8 -*-
"""v2.0.0-beta.2: home.php — 服务可用性动态渲染（CRLF 兼容）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\views\home.php"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "<!-- 三个统计数字必须挂同一套类：此前这一项只挂了 num、没有 stat-value，\n"
    "                             于是拿不到统一下来的 line-height，行盒比另两项高约 16px，\n"
    "                             把它的标签「服务可用性」顶得偏低（2026-09-14 对齐修复）。 -->\n"
    "                        <div class=\"num stat-value\">99.9%</div>"
).replace('\n', nl)
new = (
    "<!-- 三个统计数字必须挂同一套类：此前这一项只挂了 num、没有 stat-value，\n"
    "                             于是拿不到统一下来的 line-height，行盒比另两项高约 16px，\n"
    "                             把它的标签「服务可用性」顶得偏低（2026-09-14 对齐修复）。 -->\n"
    "                        <!-- v2.0.0-beta.2: 服务可用性实装 —— 近 7 天 API 请求成功率\n"
    "                             （4xx 视为可用、5xx 计失败）；无样本（新装/无流量）显示默认 99.9%。 -->\n"
    "                        <div class=\"num stat-value\" title=\"近 7 天 API 请求成功率\"><?= $availability === null ? '99.9%' : number_format($availability * 100, 1) . '%' ?></div>"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] home.php availability anchor not found')
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] home.php: 服务可用性动态渲染')
