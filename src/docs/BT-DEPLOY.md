# MoeRNG 宝塔面板部署指南

> 适用于宝塔面板（BT Panel）+ Nginx 的部署形态。本文档与 `nginx.conf.example` 内容对应，但以宝塔「伪静态」编辑器的可直接粘贴格式给出。

## 1. 宝塔「伪静态」配置（v1.3.1 起，含静态资源长缓存）

**宝塔面板 → 网站（你的域名）→ 设置 → 伪静态**，整段替换为：

```nginx
location ~ ^/(config|app|views|releases|backups|var)/ { deny all; return 404; }
location ~* \.(sql|zip|md|log|ini|lock|yml|yaml)$ { deny all; return 404; }
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
| 第 1 行 deny 路径 | `config/`（数据库配置）、`app/`（源码）、`views/`（模板）、`releases/`（发布包）、**`backups/`（备份目录，含 DB + 上传 zip，v1.2.1-beta.2 新增）**、**`var/`（限流计数/锁文件，v1.2.1-beta.2 新增）** |
| 第 2 行 deny 后缀 | `.sql`（备份/迁移 SQL）、**`.zip`（备份压缩包，v1.2.1-beta.2 新增）**、`.md`/`.log`/`.ini`/`.lock`/`.yml`/`.yaml` |
| 第 3 行 | 上传目录：绝不执行 PHP（防上传 webshell）+ 30d 缓存 + nosniff（v1.3.1 补齐） |
| `/public/` `/assets/` | 静态资源长缓存：`/public/` 365d + immutable（资源带 `?v=ASSET_VER` 版本戳，v1.3.1 新增）；`/assets/` 30d |
| 入口 rewrite 行 | 前端控制器 rewrite（/api → api.php 等） |

### ⚠️ 升级提醒

每次升级对照 `CHANGELOG.md` 检查伪静态是否有新增 deny 路径/后缀/缓存规则。v1.3.1 相比 v1.2.1-beta.2 新增：

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

## 6. 对象存储前端直传（v1.3.1，可选）

「系统设置 → 图片与存储 → 对象存储前端直传」开启后，上传图片由浏览器**直传云存储**（不消耗服务器流量；单文件上限 200MB，不受 post_max_size 限制）。

**生效条件**（缺一不可）：

1. 开关打开（系统设置 → 图片与存储）；
2. 当前选中的存储实例为 **AWS S3 / 腾讯云 COS / 阿里云 OSS / 华为 OBS**（本地存储、又拍云、七牛自动回退服务器上传）；
3. **官方 SDK 已部署**（release zip 内的 `sdk/` 目录完整——直传签名由各云官方 SDK 原生生成；SDK 缺失时自动回退服务器上传，上传弹窗会提示原因）；
4. **云控制台为 Bucket 配置 CORS**（各云控制台 → Bucket → 跨域/CORS 设置）：

| 项 | 值 |
|----|----|
| 来源 Origin | `https://你的域名` |
| 方法 Methods | `PUT`、`HEAD` |
| 允许 Headers | `Content-Type` |
| Expose Headers | `ETag`（可选） |

5. 关闭开关或条件不满足时自动回退服务器上传（功能永远可用）；直传 403 时前端会明确提示 CORS 未配置。
