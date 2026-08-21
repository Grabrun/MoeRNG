![MoeRNG Banner](./src/assets/banner-readme.png)

<h1 align="center">MoeRNG</h1>

<p align="center">
  <strong>随机二次元图片 API · 萌系自托管图床</strong>
  <br>
  基于 PHP 8.4 + MySQL，6 家对象存储官方 SDK 聚合
</p>

<br>

## ✨ 特性

- 🎲 **真随机取图** — 数据库 `ORDER BY RAND()`，每次请求独立抽样
- 🌳 **多级分类树** — 任意嵌套，按分类（含子分类）取图
- ⚡ **双模式返回** — JSON 结构化数据 / 302 重定向直出图片
- 🔐 **API Key + 限流** — 可选鉴权，DB token bucket 速率限制（默认关闭）
- ☁️ **6 家对象存储** — 腾讯云 COS / 阿里云 OSS / AWS S3 / 华为云 OBS / 又拍云 / 七牛 Kodo（全部官方 SDK，零自研签名）
- 🎨 **萌系视觉** — 粉紫主题 + 主题切换 + 双语界面
- 🛡️ **安全基线** — CSP / 安全响应头 / 防会话固定 / 签名密钥独立 / 限流 fail-closed / 操作审计
- 🖥️ **管理后台** — 图片/分类/用户/Key/存储实例/设置/审计日志/备份

<br>

## 🚀 30 秒预览

`GET /api/v1/random` 默认返回 JSON：

```json
{
  "success": true,
  "data": {
    "id": 42,
    "url": "https://cdn.example.com/xxx.png",
    "title": "示例图片",
    "category": { "id": 2, "name": "风景", "slug": "landscape" }
  }
}
```

加 `?format=redirect` 直接 302 跳到图片 URL。

<br>

## 📦 技术栈

| 层 | 选型 |
|----|------|
| 后端 | PHP 8.4 FPM（PSR-4 自研框架，零重型依赖） |
| 数据库 | MySQL 8.0+ |
| 会话 | Redis（`tcp://127.0.0.1:6379`） |
| 服务器 | Nginx + 宝塔面板 |
| 对象存储 | 6 家官方 SDK 聚合（qcloud/cos-sdk-v5、alibabacloud/oss-v2、aws/aws-sdk-php、esdk-obs-php、upyun-sdk-php、qiniu-sdk-php） |

<br>

## ⚙️ 快速开始

### 环境要求

- PHP 8.4+（扩展：`pdo_mysql`, `curl`, `gd`, `simplexml`, `libxml`, `mbstring`, `fileinfo`）
- MySQL 8.0+
- Redis（推荐，用于会话存储）
- Nginx

### 安装

1. 将 `src/` 全部内容上传到站点根目录
2. 确保 `config/`、`public/uploads/` 目录可写
3. 配置 Nginx rewrite（参考 `src/nginx.conf.example`）
   - **宝塔用户**：直接使用 `src/docs/BT-DEPLOY.md` 中的「伪静态」配置（含 v1.2.1-beta.2 起的安全加固）
4. 浏览器访问站点根目录 → 进入安装向导 → 填写数据库 + 管理员 + 存储
5. 进入 `/admin` 管理后台

> **覆盖部署**：升级时直接覆盖代码文件，数据库结构在运行时自动迁移（`Application::runStorageMigration`），**无需手动执行 SQL**。部署后记得重启 PHP-FPM（清 OPcache）。

<br>

## 🔌 API 速查

Base URL：`https://your-domain.com`

| 路径 | 说明 |
|------|------|
| `GET /api/v1/random` | 真随机取图 |
| `GET /api/v1/random?category=landscape` | 按分类取图（含子分类） |
| `GET /api/v1/random?format=redirect` | 302 重定向直出图片 |
| `GET /api/v1/random?format=json` | JSON 结构化（默认） |
| `GET /api/v1/images?page=1&per_page=20` | 图片列表 |
| `GET /api/v1/categories` | 分类列表 |
| `GET /api/v1/stats` | 服务统计 |

**鉴权**（可选）：后台「API Keys」生成 Key 后请求头携带 `X-API-Key: your-api-key`。

<br>

## 🗂 目录结构

```
src/
├── admin.php          # 后台入口
├── api.php            # API 入口
├── index.php          # 首页入口
├── install.php        # 安装向导
├── doctor.php         # 部署诊断工具（验证后建议删除）
├── app/
│   ├── Core/          # 框架核心（Router / Application / Config / Database）
│   ├── Controllers/   # 控制器
│   ├── Models/        # 数据模型
│   ├── Middleware/    # 中间件（认证 / CSRF / 限流）
│   └── Storage/       # 6 家存储驱动（Local / S3 / COS / OSS / AWS / OBS / UPYUN / QINIU）
├── views/             # 视图模板
├── public/            # 静态资源与上传目录
├── sdk/               # 对象存储官方 SDK（发布包内置，源码仓库忽略）
├── schema.sql         # 初始表结构
└── config/            # 配置（database.php / signing_key.php 由运行时生成）
```

<br>

## 📚 文档

| 文档 | 说明 |
|------|------|
| [`src/docs/BT-DEPLOY.md`](./src/docs/BT-DEPLOY.md) | 宝塔面板部署指南（含伪静态配置 + 升级安全加固） |
| [`src/nginx.conf.example`](./src/nginx.conf.example) | Nginx 配置参考（含 deny 路径/后缀的安全规则） |
| [`src/.htaccess`](./src/.htaccess) | Apache 配置参考 |
| [`CHANGELOG.md`](./CHANGELOG.md) | 版本变更记录 |

<br>

## 🤝 参与

欢迎 Issue / PR。发版门禁遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)，详见 CHANGELOG。

<br>

## 📄 许可证

[MIT](LICENSE)