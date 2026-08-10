#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""按用户模板更新 v1.1.1-beta.3 的 GitHub Release Notes。"""
import json, os, sys, urllib.request

TOKEN = os.environ.get('GITHUB_TOKEN', '')
API = 'https://api.github.com'
REPO = 'Grabrun/MoeRNG'
CL_URL = 'https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md'

body = """## v1.1.1-beta.3

**版本概述**：重构后台分类管理的展示结构，将平铺列表改为「每顶级分类一张卡片」的树形分组，层级关系一目了然。

### 🚨 破坏性变更（Breaking Changes）

无

### 🚀 新功能（New Features）

- （本版无新增功能）

### ⬆️ 功能增强（Enhancements）

- 后台分类管理：每个顶级分类独立卡片展示，子分类带树形连接线与层级缩进（commit aede526）
- 二级及以上子分类使用虚线边框区分层级（commit aede526）
- 分类卡片头部显示「顶级分类」标识、子类数量与排序；子项操作按钮 hover 浮现（commit aede526）

### 🐛 Bug 修复（Bug Fixes）

- （本版无修复）

### 📚 文档与依赖（Documentation & Dependencies）

- 新增 CHANGELOG.md，按 Keep a Changelog 约定记录版本变更（commit 本次提交）

### 升级指南（Upgrade Guide）

1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。
2. 重启 PHP-FPM 触发数据库自动迁移。
3. 运行 doctor.php 验证部署健康后删除。

### 贡献者致谢

- （本版无外部贡献者）

完整变更日志请查看 [CHANGELOG.md](CL_URL)。""".replace('CL_URL', CL_URL)

# 找到 release id（按 tag）
req = urllib.request.Request(
    f'{API}/repos/{REPO}/releases/tags/v1.1.1-beta.3',
    headers={'Authorization': f'Bearer {TOKEN}', 'Accept': 'application/vnd.github+json'},
    method='GET',
)
with urllib.request.urlopen(req) as resp:
    release = json.loads(resp.read().decode('utf-8'))
rid = release['id']
print('release id:', rid)

req = urllib.request.Request(
    f'{API}/repos/{REPO}/releases/{rid}',
    data=json.dumps({'body': body}).encode('utf-8'),
    headers={'Authorization': f'Bearer {TOKEN}', 'Accept': 'application/vnd.github+json',
             'Content-Type': 'application/json'},
    method='PATCH',
)
with urllib.request.urlopen(req) as resp:
    updated = json.loads(resp.read().decode('utf-8'))
print('notes updated:', updated['html_url'])
