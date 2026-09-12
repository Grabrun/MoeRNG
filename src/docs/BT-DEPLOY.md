# MoeRNG 宝塔面板部署指南

> 适用于宝塔面板（BT Panel）+ Nginx 的部署形态。本文档与 `nginx.conf.example` 内容对应，但以宝塔「伪静态」编辑器的可直接粘贴格式给出。

## 1. 宝塔「伪静态」配置（v1.3.1 起，含静态资源长缓存）

**宝塔面板 → 网站（你的域名）→ 设置 → 伪静态**，整段替换为：

```nginx
location ~ ^/(config|app|views|releases|backups|var|storage)/ { deny all; return 404; }
location ~* \.(sql|zip|md|log|ini|lock|yml|yaml)$ { deny all; return 404; }
# v1.5.0-beta.1: 媒体根已迁到 storage/uploads（上面已整体 deny，只能走 /files 签名）；
# 这里仅剩品牌 logo（站点静态资源），保留该 location 供其直读与长缓存。
location ^~ /public/uploads/ {
    expires 30d;
    add_header Cache-Control "public, immutable";
    add_header X-Content-Type-Options "nosniff";
    location ~ \.php$ { deny all; }
}
# v1.3.1: /public/ 资源全部带 ?v=ASSET_VER 戳（版本号+文件 mtime），365d + immutable 安全
location ^~ /public/ {
    expires 365d;
    add_header Cache-Control "public, immutable";
    try_files $uri =404;
}
# /assets/ 中 og-image 等少数文件无版本戳，30d 折衷
location ^~ /assets/ {
    expires 30d;
    add_header Cache-Control "public";
    try_files $uri =404;
}
location /api    { try_files $uri /api.php$is_args$args; }
location /admin  { try_files $uri /admin.php$is_args$args; }
location /install{ try_files $uri /install.php$is_args$args; }
location /       { try_files $uri /index.php$is_args$args; }
```

保存后宝塔自动 reload，无需重启。

> **⚠️ add_header 继承坑**：nginx 的规则是「location 内一旦出现自己的 `add_header`，就不再继承 server 级的 `add_header`」。如果你在宝塔的**全局配置文件**里手工加过 `add_header`（如 HSTS），上面这些 location 会让它失效——需把那几行也复制进对应 location。MoeRNG 自身的安全响应头（CSP 等）由 PHP 输出，不受此影响。

### 各行作用

| 行 | 防护对象 |
|----|---------|
| 第 1 行 deny 路径 | `config/`（数据库配置）、`app/`（源码）、`views/`（模板）、`releases/`（发布包）、**`backups/`（备份目录，含 DB + 上传 zip，v1.2.1-beta.2 新增）**、**`var/`（限流计数/锁文件，v1.2.1-beta.2 新增）**、**`storage/`（媒体根与上传中转，v1.5.0-beta.1 新增 —— 媒体迁出 web 根后这条是签名机制的兜底）** |
| 第 2 行 deny 后缀 | `.sql`（备份/迁移 SQL）、**`.zip`（备份压缩包，v1.2.1-beta.2 新增）**、`.md`/`.log`/`.ini`/`.lock`/`.yml`/`.yaml` |
| 第 3 行 | `public/uploads/`：**v1.5.0-beta.1 起只剩品牌 logo**（媒体已迁到 `storage/uploads`）。保留静态服务 + 30d 缓存 + 绝不执行 PHP + nosniff |
| `/public/` `/assets/` | 静态资源长缓存：`/public/` 365d + immutable（资源带 `?v=ASSET_VER` 版本戳，v1.3.1 新增）；`/assets/` 30d |
| 入口 rewrite 行 | 前端控制器 rewrite（/api → api.php 等） |

### ⚠️ 升级提醒

每次升级对照 `CHANGELOG.md` 检查伪静态是否有新增 deny 路径/后缀/缓存规则。**v1.5.0-beta.1 的关键一条：第 1 行必须包含 `storage`** —— 媒体根迁到 `storage/uploads` 后，若缺这条，文件会被 web 服务器按静态路径直接读到，`/files` 的短时签名形同虚设（旧版伪静态没有 `storage`，**务必按上面整段替换**）。

### 图片加载慢？先看这一条（v1.5.0-beta.1）

图片是本项目最大的带宽消耗，**能不能被浏览器缓存**直接决定"翻一页要不要重下 20 张图"：

| 存储方式 | URL 形态 | 浏览器缓存 |
|---|---|---|
| 本地存储 | `/files?p=…&e=…&s=…`（签名按 **60s 窗口**对齐） | ✅ 同一窗口内 URL 完全一致 → 命中缓存 |
| 对象存储**未绑 CDN** | 每次渲染重新预签名的直链 | ❌ URL 每秒都变 → 每次浏览全量重下 |
| 对象存储**已绑 CDN/自定义源站域名** | `https://cdn.example.com/{键}` | ✅ 稳定地址 + 边缘缓存 |

**结论：用 COS/OSS/S3 的话，务必在「存储管理 → 该实例 → CDN 域名」里绑定一个加速域名**（或在对象存储控制台给 bucket 绑自定义域名并设为公共读）。这是对象存储方案下最有效的加载优化——比任何代码调整都明显。`doctor.php` 的「云端图片 URL 稳定性」一栏会明确告诉你当前是否已配好。

另外务必确认 **OPcache 已开启**（`opcache.enable=1`），它对首字节时间的影响通常大于代码层面的微调。

v1.3.1 相比 v1.2.1-beta.2 新增：

- 上传目录补齐 `expires 30d` + `Cache-Control` + `nosniff`
- 新增 `/public/`（365d + immutable）与 `/assets/`（30d）静态缓存 location

若停留在旧版伪静态：静态资源无缓存头（每次重复下载 CSS/JS，首屏变慢）——**v1.3.1 起 CSS/JS 带 ASSET_VER 版本戳，配合长缓存才有完整收益**。

## 2. 部署后校验

保存配置后访问以下地址应全部 **404**：

```
https://你的域名/backups/
https://你的域名/backups/moerng-xxx.zip
https://你的域名/var/
https://你的域名/test.sql
https://你的域名/test.sql.zip
```

正常路径不受影响：

```
https://你的域名/            → 首页
https://你的域名/gallery     → 图库（v1.3.1）
https://你的域名/admin       → 后台
https://你的域名/api/v1/random → API
```

**静态缓存头校验**（v1.3.1）：

```bash
curl -sI https://你的域名/public/css/style.css | grep -iE "cache-control|expires"
# 期望: Cache-Control: public, immutable 与 Expires 一年后
```

## 3. 部署纪律（每次覆盖部署后）

1. 解压 release zip 到站点根目录（覆盖）
2. **重启 PHP-FPM**（清 OPcache；PHP 文件变更必须重启）
3. 浏览器**清缓存/Cookie**（设置页样式变更）
4. 跑 `doctor.php` 验证（存储连通性/签名自检），验证后**删除** doctor.php
5. 对照本文档确认伪静态配置为最新

## 4. 常见问题

- **后台 404**：伪静态未保存 / `location /admin` 行缺失
- **图片 404 / 签名失效**：确认已重启 PHP-FPM；签名密钥存 `config/signing_key.php`
- **对象存储图片加载失败**：检查 CSP——v1.2.1-beta.2 起 CSP 自动白名单存储 CDN/源站域名，若仍失败请确认存储管理里的 CDN/源站域名已配置

## 6. 关于「对象存储前端直传」（已移除）

v1.3.1-beta.1/beta.2 迭代期曾实现过浏览器直传云存储（presigned PUT）方案，
beta.2 迭代中**已整体移除**——原因：规划中的图片处理能力（缩略图 / 水印 /
格式转换）需要图片字节经过服务器，直传架构与此冲突。当前架构回归
「浏览器 → 服务器 → 对象存储」，服务器端可对字节做任意处理后再转存云上。
若未来某类大文件确需直传，可从 git 历史（commit 0bd34e8..eca36e8）找回
相关实现作参考。

---

## v1.3.2-beta.2 补充：拒绝 storage/ 访问（必加）

上传改用「临时目录 + 异步处理队列」后，站点根出现 `storage/incoming/` 临时目录。
它不应被 Web 访问，请在伪静态规则中一并拒绝：

```nginx
location ~ ^/(config|app|views|releases|backups|var|storage)/ {
    deny all;
    return 404;
}
```

> 若使用 Apache，`storage/incoming/.htaccess`（程序自动生成）已含 `Require all denied`，无需额外配置。
