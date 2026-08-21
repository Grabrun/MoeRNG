# MoeRNG 宝塔面板部署指南

> 适用于宝塔面板（BT Panel）+ Nginx 的部署形态。本文档与 `nginx.conf.example` 内容对应，但以宝塔「伪静态」编辑器的可直接粘贴格式给出。

## 1. 宝塔「伪静态」配置（v1.2.1-beta.2 起）

**宝塔面板 → 网站（images.grabrun.top）→ 设置 → 伪静态**，整段替换为：

```nginx
location ~ ^/(config|app|views|releases|backups|var)/ { deny all; return 404; }
location ~* \.(sql|zip|md|log|ini|lock|yml|yaml)$ { deny all; return 404; }
location ^~ /public/uploads/ { location ~ \.php$ { deny all; } }
location /api    { try_files $uri /api.php$is_args$args; }
location /admin  { try_files $uri /admin.php$is_args$args; }
location /install{ try_files $uri /install.php$is_args$args; }
location /       { try_files $uri /index.php$is_args$args; }
```

保存后宝塔自动 reload，无需重启。

### 各行作用

| 行 | 防护对象 |
|----|---------|
| 第 1 行 deny 路径 | `config/`（数据库配置）、`app/`（源码）、`views/`（模板）、`releases/`（发布包）、**`backups/`（备份目录，含 DB + 上传 zip，v1.2.1-beta.2 新增）**、**`var/`（限流计数/锁文件，v1.2.1-beta.2 新增）** |
| 第 2 行 deny 后缀 | `.sql`（备份/迁移 SQL）、**`.zip`（备份压缩包，v1.2.1-beta.2 新增）**、`.md`/`.log`/`.ini`/`.lock`/`.yml`/`.yaml` |
| 第 3 行 | 上传目录绝不执行 PHP（防上传 webshell） |
| 第 4-7 行 | 前端控制器 rewrite（/api → api.php 等） |

### ⚠️ 升级提醒

每次升级对照 `CHANGELOG.md` 检查伪静态是否有新增 deny 路径/后缀。v1.2.1-beta.2 相比 beta.1 新增：

- 第 1 行路径：`backups|var`
- 第 2 行后缀：`zip`

若停留在旧版伪静态，**备份目录 backups/ 与备份 zip 可被公网直接下载**（含数据库全部数据 + 上传文件），属严重安全风险。

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
https://你的域名/admin       → 后台
https://你的域名/api/v1/random → API
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
