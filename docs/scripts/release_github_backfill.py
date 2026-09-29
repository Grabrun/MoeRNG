# -*- coding: utf-8 -*-
"""补发 v2.0.0-beta.1~4 GitHub Release + zip 归档（GitHub REST API）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

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
    ("v2.0.0-beta.4",
     "releases/MoeRNG-2.0.0-beta.4-20260928-161546.zip",
     "## 后台「系统设置」排版与 UI 优化（全 CSS 增强，结构/交互挂钩零变动）\n\n- 设置标签行 pill 化（active 标签渐变压 + 柔光，非 active 悬停高亮）；分组卡片标题左侧渐变强调条；设置项 hover 高亮（浅紫底 + 圆角，label 加粗）\n- 批量处理面板标题状态点统一视觉锚点；健康检查结果状态徽标（[ OK ] 绿 pill / [待修复] 红 pill）\n- 保存提示未保存改为警告色；工具栏自适应换行\n- 约束保持：design_contract 63 项全过、CSS 92141B < 90KB、scope/click/page_matrix/syntax 全绿"),
    ("v2.0.0-beta.3",
     "releases/MoeRNG-2.0.0-beta.3-20260928-150314.zip",
     "## 全站代码检查 + 图片处理架构审计\n\n- 上传循环 finfo 提出循环（批量上传时 fileinfo 库仅循环外打开一次）；大图流式下载（FileController::show() 弃 gzip 输出缓冲改 readfile，图片已压缩，gzip 白费 CPU 且内存翻倍）\n- 基线余量常量化（两处裸 16777216 收敛为 MEMORY_BASELINE_HEADROOM）；删死函数 e()（helpers.php，全站零调用）\n- 文档：docs/audit/2026-09-28-image-arch-audit.md（全站检查结论 + 图片处理架构审计 + 域服务抽取边界，harness 方法体级契约锁定）\n- 回归：全量 19 项 harness 全绿、语法 2912/2912"),
    ("v2.0.0-beta.2",
     "releases/MoeRNG-2.0.0-beta.2-20260928-144759.zip",
     "## 前台「服务可用性」实装\n\n- 首页 hero 第三个统计从静态字面量改为近 7 天 API 请求成功率（SLA 口径，随真实流量变化）：api_stats 新增 fail 列；api.php 未捕获异常（5xx）单独记失败，正常响应（含 4xx 业务校验失败）仍走成功计数；Stats::availability() 计算近 7 天成功率\n- 无样本兜底：全新/尚无流量时 availability = null，前台显示默认 99.9%；有样本后显示真实值（1 位小数）\n- SCHEMA_VERSION 升至 2026-09-28-2（api_stats.fail 幂等迁移，权限被拒时降级不破坏站点）；深度审计：Application.php backfill 改为从默认 profile 推导，删除旧设置键读取；删死调试函数 dd()\n- 回归：全量 harness 通过"),
    ("v2.0.0-beta.1",
     "releases/MoeRNG-v2.0.0-beta.1-20260928-141050.zip",
     "## 破坏性变更：取消对之前的所有兼容与迁移，使用新版本方案\n\n- 存储布局唯一化：只认 v2 {yyyy}/{mm}/{uuid}/original.{ext} + thumb-{size}.webp；Image::assetParts() 不再识别 v1 形态（返回 null），thumbKey() 对非 v2 路径抛 RuntimeException\n- 迁移工具整体移除：POST /admin/images/migrate-layout 路由、存储结构迁移面板、app.js::runLayoutMigration/initLayoutMigration 全部删除\n- 本地媒体根收口：files 读取不再回退 public/uploads 历史根（仅默认根 storage/uploads + 存储实例自定义根）；LocalDriver::legacyUploadDir() / LEGACY_REL_DIR 删除\n- 旧凭证迁移链删除：migrateProviderCredentials() / migrateLegacyToProfiles() / insertProfile() / hasDefaultProfile() 移除；storage_profiles 是唯一配置来源\n- 升级前务必阅读 src/docs/STORAGE-LAYOUT.md 与 src/docs/BT-DEPLOY.md 的升级注意"),
]

for tag, arc, body in RELEASES:
    path = os.path.join(ROOT, arc.replace('/', os.sep))
    if not os.path.isfile(path):
        print('[SKIP] %s 归档缺失: %s' % (tag, path))
        continue
    rel = api("https://api.github.com/repos/%s/releases" % REPO,
              {"tag_name": tag, "name": tag, "body": body, "draft": False, "prerelease": True})
    print('[OK] release %s 创建: %s' % (tag, rel['html_url']))
    upload_url = rel['upload_url'].replace('{?name,label}', '?name=' + urllib.parse.quote(os.path.basename(path)))
    with open(path, 'rb') as f:
        binary = f.read()
    up = api(upload_url, headers={"Content-Type": "application/zip"}, method="POST", binary=binary)
    print('[OK] 资产上传: %s (%d bytes)' % (up['name'], up['size']))
