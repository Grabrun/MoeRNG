#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""按新标准（无内容章节不输出）重写所有历史 Release Notes。"""
import json, os, sys, urllib.request

TOKEN = os.environ.get('GITHUB_TOKEN', '')
if not TOKEN:
    print('[FAIL] GITHUB_TOKEN 未设置')
    sys.exit(1)
API = 'https://api.github.com'
REPO = 'Grabrun/MoeRNG'
CL_URL = 'https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md'

UPGRADE = (
    '### 升级指南（Upgrade Guide）\n\n'
    '1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。\n'
    '2. 重启 PHP-FPM 触发数据库自动迁移。\n'
    '3. 运行 doctor.php 验证部署健康后删除。\n\n'
    f'完整变更日志请查看 [CHANGELOG.md]({CL_URL})。'
)

NOTES = {
    'v1.1.0': f"""## v1.1.0

**版本概述**：MoeRNG 首个正式版本——基于 PHP 8.4 + MySQL 的随机二次元图片 API 服务，提供双模式 API、对象存储接入与完整管理后台。

### 🚀 新功能（New Features）

- RESTful API：随机图片 / 图片列表 / 多级分类 / 服务统计
- 双模式返回：JSON 结构化数据 或 302 重定向直接输出图片
- API Key 鉴权 + 速率限制（DB token bucket）
- 对象存储：腾讯云 COS / 阿里云 OSS / AWS S3 / 华为云 OBS（官方 SDK）
- 管理后台：图片 / 分类 / 用户 / API Key / 存储实例（多实例配置）
- 系统设置 v2：站点信息 / 安全与访问 / 性能与缓存 / 邮件与通知 / 备份与恢复
- 登录验证码 + 失败锁定、SMTP 邮件通知、自动备份、操作审计日志
- 响应式前台：顶部导航、随机图展示、API 在线测试、关于页

### 📚 文档与依赖（Documentation & Dependencies）

- 新增 README（项目简介 / 安装部署 / API 用法）与 MIT LICENSE
- 对象存储 SDK 全部采用官方 SDK（qcloud/cos-sdk-v5、alibabacloud/oss-v2、aws/aws-sdk-php、esdk-obs-php）

{UPGRADE}""",
    'v1.1.1-beta.1': f"""## v1.1.1-beta.1

**版本概述**：全站排版与用户体验优化（第一批）——统一排版层级、打磨组件交互、补齐响应式与无障碍细节。

### ⬆️ 功能增强（Enhancements）

- 全局排版层级统一（h1-h4 字号/行高/字距）、按钮按压态与焦点环
- 前台导航小屏换行、Hero 统计卡微动效、在线测试面板容器化
- 后台侧边栏 active 菜单左侧指示条
- 表格/表单/模态/滚动条打磨，prefers-reduced-motion 无障碍降级，响应式断点补全

{UPGRADE}""",
    'v1.1.1-beta.2': f"""## v1.1.1-beta.2

**版本概述**：全站 UX 优化（第二批）——Modal 交互增强、上传进度可视化、代码块可读性提升。

### ⬆️ 功能增强（Enhancements）

- Modal 弹窗支持 ESC 关闭，打开时锁定背景滚动（body.modal-open）
- 图片上传进度条实时显示百分比
- 前台文档代码块限高滚动，复制按钮固定悬浮右上角

{UPGRADE}""",
    'v1.1.1-beta.3': f"""## v1.1.1-beta.3

**版本概述**：重构后台分类管理的展示结构，将平铺列表改为「每顶级分类一张卡片」的树形分组，层级关系一目了然。

### ⬆️ 功能增强（Enhancements）

- 后台分类管理：每个顶级分类独立卡片展示，子分类带树形连接线与层级缩进
- 二级及以上子分类使用虚线边框区分层级
- 分类卡片头部显示「顶级分类」标识、子类数量与排序；子项操作按钮 hover 浮现

{UPGRADE}""",
}

headers = {
    'Authorization': f'Bearer {TOKEN}',
    'Accept': 'application/vnd.github+json',
    'Content-Type': 'application/json',
}


def api(method, url, payload=None):
    data = json.dumps(payload).encode('utf-8') if payload is not None else None
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode('utf-8'))


for tag, body in NOTES.items():
    rel = api('GET', f'{API}/repos/{REPO}/releases/tags/{tag}')
    rid = rel['id']
    api('PATCH', f'{API}/repos/{REPO}/releases/{rid}', {'body': body})
    print(f'[OK] {tag} notes updated -> {rel["html_url"]}')
print('done')
