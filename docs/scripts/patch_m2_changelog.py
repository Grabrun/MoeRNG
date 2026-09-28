# -*- coding: utf-8 -*-
"""M2：CHANGELOG 插入 2.0.0-beta.5 条目"""
import io

p = r"E:\Projects\DouBao\MoeRNG\CHANGELOG.md"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

block = (
    "## [2.0.0-beta.5] - 2026-09-28\n"
    "\n"
    "> M2「晴空画册」视觉重设计落地：全站换肤 + 前台/后台关键结构增强（按已评审设计提案 `design/ui-redesign.html` 执行）。\n"
    "\n"
    "### 🎨 视觉重设计（全站）\n"
    "\n"
    "- **双主题设计 token 换肤**：浅色（暖米白 `#FAF6F3` 底 + 珊瑚粉 `#E8597A` 主色 + 鼠尾草青 `#5F9E8C` 辅色 + 蜜橘 `#E8A24B` 点缀）/ 深色（炭紫黑 `#211E26` 底 + 提亮珊瑚粉 `#F2789A`）；圆角收敛（卡片 14 / 控件 10 / 大容器 18），阴影中性化，全面去霓虹与粉紫渐变\n"
    "- **字体体系**：标题/数字走思源宋体（`--font-display: 'Noto Serif SC'`，含宋体/Georgia 回退栈），正文思源黑体 + 系统中文栈；`miaoda.feishu.cn` 自托管字体镜像（禁直连 Google Fonts），CSP 新增 `font-src` 并放行 `style-src` 字体域\n"
    "- **前台 hero**：新增 kicker 眉标「自托管 · 真随机 · 治愈感」；banner 容器圆角收敛；stats 三数字加细分隔线（仅桌面横排态，不影响 hero_stats 对齐契约）；feature 卡改左对齐 + 图标前置\n"
    "- **后台**：侧栏品牌走 display 字体；stat-card hover 上浮保留；设置页 tab 柔光/健康徽标残影随主题色（color-mix）\n"
    "\n"
    "### 🔧 体积治理\n"
    "\n"
    "- CSS 重复声明合并（btn/card/th/stat-card/toast/table-wrap/truncate 等 18 处）、reduced-motion 四块收敛为一、粉紫硬编码 rgba 换 color-mix 跟随主题\n"
    "- 新增 `.gitattributes`（`style.css eol=lf`）：稳定字节口径，消除 CRLF 抖动；最终 `src/public/css/style.css` **90711B < 90KB 预算**\n"
    "- 分页（transition/hover 上浮）、模态过渡、代码块高度限制等行为规则原样保留，无回归\n"
    "\n"
    "### ✅ 回归\n"
    "\n"
    "- design_contract 63 项全过（CSS 体积达标）；syntax 2912/2912；page_matrix / click_matrix / scope / click 全绿；hero_stats 36 项全过；xref_audit 367 个 class 全部可溯源；桌面+移动、浅色+深色截图自检正常\n"
    "\n"
).replace('\n', nl)

anchor = "## [2.0.0-beta.4] - 2026-09-28"
if block.replace('\n', nl).strip() in t:
    print('[SKIP] beta.5 已存在')
elif anchor in t:
    t = t.replace(anchor, block + anchor, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] CHANGELOG 插入 beta.5')
else:
    print('[FAIL] CHANGELOG 锚点')
