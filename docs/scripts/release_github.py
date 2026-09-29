# -*- coding: utf-8 -*-
"""为 v2.0.0-beta.5~8 创建 GitHub Release 并上传 zip 归档（GitHub REST API）"""
import io, json, os, urllib.request, urllib.error

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"

with io.open(os.path.join(ROOT, '.dsh', 'moerng.token'), encoding='utf-8') as f:
    TOKEN = f.read().strip()

def api(url, data=None, headers=None, method=None, binary=None):
    h = {"Authorization": "token " + TOKEN, "User-Agent": "moerng-release"}
    if headers:
        h.update(headers)
    body = None
    if binary is not None:
        body = binary
    elif data is not None:
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(url, data=body, headers=h, method=method or ('POST' if body is not None else 'GET'))
    try:
        with urllib.request.urlopen(req) as r:
            raw = r.read()
            return json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        raise SystemExit('[FAIL] HTTP %s %s\n%s' % (e.code, url, e.read().decode('utf-8', 'replace')[:400]))

RELEASES = [
    ("v2.0.0-beta.8",
     "releases/MoeRNG-2.0.0-beta.8-20260929-104620.zip",
     "## 字体 CSP 修复\n\n- **字体加载被 CSP 批量拦截**：miaoda.feishu.cn 的 css2 字体入口返回的 @font-face 实际指向 sf3-scmcdn-cn.feishucdn.com 的 woff2 分片（入口与字体文件两跳分离）；font-src 此前仅放行入口域名，思源字体全部被浏览器阻止、回退系统字体\n- **修复**：font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com（放行实测唯一字体文件域名，精确最小化）\n- 回归：syntax 2912/2912、API 契约 67 项、design_contract 63 项、page_matrix / hero_stats 全绿"),
    ("v2.0.0-beta.7",
     "releases/MoeRNG-2.0.0-beta.7-20260928-225357.zip",
     "## 覆盖安装重置修复\n\n- **根因**：src/config/app.php（installed=false）被 git 跟踪且打进 release 归档，覆盖部署时把线上已安装标志覆盖重置，每次覆盖都回到安装向导\n- **修复**：src/config/*.php 整体移出版本控制（git rm --cached + .gitignore）；build_release 归档排除 config/*.php，仅保留 .htaccess 保护文件；.gitignore 规则行尾注释清理（行中 # 会让整行静默失效）\n- 全新部署流程不变（Config::load() 自愈生成默认 app.php 引导安装）\n- 回归：syntax 2912/2912、API 契约 67 项、design_contract 63 项、page_matrix 全绿"),
    ("v2.0.0-beta.6",
     "releases/MoeRNG-2.0.0-beta.6-20260928-222608.zip",
     "## 空库 404 优雅化\n\n- /api/v1/random 空库/分类空：404 + JSON 错误体（error 保持英文机器码兼容既有调用方，新增 code: NO_IMAGES_AVAILABLE + 中文引导 message，分类参数已 HTML 转义）\n- 在线测试页：非 2xx 响应展示服务端 message；非 JSON 错误体（nginx 默认页）提示网关/伪静态未配置\n- 文档补充空库行为说明\n- **已知问题（已在 beta.7 修复）**：本版本归档含 config/app.php（installed=false），覆盖部署会重置安装状态——请升级 v2.0.0-beta.7+"),
    ("v2.0.0-beta.5",
     "releases/MoeRNG-2.0.0-beta.5-20260928-221645.zip",
     "## M2「晴空画册」视觉重设计\n\n- 双主题 token 换肤：浅色暖米白 #FAF6F3 / 珊瑚粉 #E8597A / 鼠尾草青 #5F9E8C / 蜜橘 #E8A24B；深色炭紫黑 #211E26 / 提亮 #F2789A；圆角收敛 14/10/18、阴影中性化、去霓虹\n- 字体体系：思源宋体（Noto Serif SC）display + 思源黑体（Noto Sans SC）正文，走 miaoda.feishu.cn 自托管镜像（禁 Google Fonts）\n- CSP 放行 font-src 入口域名；前台 hero-kicker + stats 桌面细分隔线；feature 卡左对齐\n- CSS 90711B（<90KB 预算）；新增 .gitattributes 稳定 style.css eol=lf\n- **已知问题（已在 beta.8 修复）**：font-src 未放行字体文件域名 sf3-scmcdn-cn.feishucdn.com，思源字体会被 CSP 拦截——请升级 v2.0.0-beta.8+"),
]

for tag, arc, body in RELEASES:
    path = os.path.join(ROOT, arc.replace('/', os.sep))
    if not os.path.isfile(path):
        print('[SKIP] %s 归档缺失: %s' % (tag, path))
        continue
    rel = api("https://api.github.com/repos/%s/releases" % REPO,
              {"tag_name": tag, "name": tag, "body": body, "draft": False, "prerelease": True})
    print('[OK] release %s 创建: %s' % (tag, rel['html_url']))
    # 上传资产
    upload_url = rel['upload_url'].replace('{?name,label}', '?name=' + urllib.parse.quote(os.path.basename(path)))
    with open(path, 'rb') as f:
        binary = f.read()
    up = api(upload_url, headers={"Content-Type": "application/zip"}, method="POST", binary=binary)
    print('[OK] 资产上传: %s (%d bytes) → %s' % (up['name'], up['size'], up['browser_download_url']))
