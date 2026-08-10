# MoeRNG

随机二次元图片 API 服务 — 基于 PHP 8.4 + MySQL 的轻量 RESTful API。

提供 JSON 结构化数据与 302 重定向双模式返回，支持多级分类、API Key 鉴权、速率限制，以及腾讯云 COS / 阿里云 OSS / AWS S3 / 华为云 OBS 四家对象存储接入。

## 特性

- 轻量自研框架（PSR-4 自动加载），零重型依赖，API 平均响应时间 < 50ms
- 真随机取图：数据库级 `ORDER BY RAND()`，每次请求独立随机
- 多级分类树，可按分类（含子分类）取图
- 双模式返回：JSON 结构化数据 / 302 重定向直接输出图片
- API Key 鉴权 + 速率限制（DB token bucket）
- 对象存储接入：COS / OSS / AWS S3 / OBS（全部使用官方 SDK）
- 管理后台：图片 / 分类 / 用户 / API Key / 存储实例（多实例配置）
- 系统设置：站点信息 / 安全与访问 / 性能与缓存 / 邮件与通知 / 备份与恢复
- 操作审计日志、登录验证码与失败锁定、SMTP 邮件通知、自动备份

## 技术栈

| 层 | 技术 |
|----|------|
| 后端 | PHP 8.4 (FPM) |
| 数据库 | MySQL 8.0+ |
| 会话 | Redis (tcp://127.0.0.1:6379) |
| 服务器 | Nginx + 宝塔面板（常见部署） |
| 对象存储 SDK | qcloud/cos-sdk-v5 · alibabacloud/oss-v2 · aws/aws-sdk-php · esdk-obs-php |

## 快速开始

### 环境要求

- PHP 8.4+（扩展：pdo_mysql、curl、gd、simplexml、libxml、mbstring、fileinfo）
- MySQL 8.0+
- Nginx（提供 rewrite 到入口文件）
- Redis（会话存储，可选但推荐）

### 安装

1. 将 `src/` 目录内容上传到站点根目录（或 `public/` 子目录，取决于你的 Nginx 配置）。
2. 确保 `config/`、`public/uploads/` 目录可写。
3. Nginx 配置参考 `nginx.conf.example`（将所有非静态资源请求 rewrite 到对应入口）。
4. 浏览器访问站点根目录，进入安装向导：填写数据库信息、管理员账号、存储配置。
5. 安装完成后进入 `/admin` 管理后台。

> 部署说明：本项目采用**覆盖部署**模式——升级时直接覆盖代码文件，数据库结构在运行时自动迁移（`Application::runStorageMigration`），无需手动执行 SQL。部署后重启 PHP-FPM。

### 存储配置

- 后台「存储管理」可配置多个存储实例（本地磁盘 / 对象存储），并标记默认实例。
- 对象存储凭据按服务商隔离（COS / OSS / AWS S3 / OBS 各自独立）。
- 上传图片时可动态选择存储实例，每张图片记录其所属实例。

## API 用法

所有公开接口位于 `/api/v1`。示例 Base URL：`https://your-domain.com`

### 随机图片

```
GET /api/v1/random
GET /api/v1/random?category=landscape
GET /api/v1/random?format=json       # 默认返回 JSON
GET /api/v1/random?format=redirect   # 302 重定向到图片
```

JSON 响应示例：

```json
{
  "success": true,
  "data": {
    "id": 1,
    "url": "https://your-domain.com/public/uploads/2026/08/xxx.png",
    "path": "2026/08/xxx.png",
    "title": "示例图片",
    "category": { "id": 2, "name": "风景", "slug": "landscape" }
  }
}
```

### 图片列表

```
GET /api/v1/images?category=landscape&page=1&per_page=20
```

### 分类列表

```
GET /api/v1/categories
```

### 服务统计

```
GET /api/v1/stats
```

### 鉴权

管理后台「API Keys」页面生成 API Key 后，请求头携带：

```
X-API-Key: your-api-key
```

未携带 Key 的请求受速率限制（可在「系统设置 → 安全与访问」调整）。

## 目录结构

```
src/
├── admin.php          # 管理后台入口
├── api.php            # API 入口
├── index.php          # 首页入口
├── install.php        # 安装向导
├── doctor.php         # 部署诊断工具（验证后建议删除）
├── app/
│   ├── Core/          # 框架核心（Router/Application/Config/Database...）
│   ├── Controllers/   # 控制器
│   ├── Models/        # 数据模型
│   ├── Middleware/    # 中间件（认证/CSRF/限流）
│   └── Storage/       # 存储驱动（Local/S3/COS/OSS/AWS/OBS）
├── views/             # 视图模板
├── public/            # 静态资源与上传目录
├── sdk/               # 对象存储官方 SDK（发布包内置）
├── schema.sql         # 初始表结构
└── config/            # 配置（database.php 由安装器生成）
```

## 许可证

[MIT](LICENSE)
