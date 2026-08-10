#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""补发 1.1.1-beta.1 / 1.1.1-beta.2 的 GitHub Release（tag + release + zip 资产）。"""
import os, subprocess, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from release import github_release  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TOKEN = os.environ.get('GITHUB_TOKEN', '')
if not TOKEN:
    print('[FAIL] GITHUB_TOKEN 未设置')
    sys.exit(1)

RELEASES = [
    ('1.1.1-beta.1', 'MoeRNG-v1.1.1-beta.1-20260810-152015.zip',
     '## MoeRNG v1.1.1-beta.1 — 测试版\n\n全站排版与 UX 优化（第一批）：\n\n'
     '- 全局排版层级统一（h1-h4/行高/字距）、按钮按压态、卡片 hover、表格/表单/模态/滚动条打磨\n'
     '- 前台导航小屏换行、Hero 层级优化、在线测试面板容器化\n'
     '- 后台侧边栏 active 指示条\n'
     '- prefers-reduced-motion 无障碍降级、响应式断点补全'),
    ('1.1.1-beta.2', 'MoeRNG-v1.1.1-beta.2-20260810-152654.zip',
     '## MoeRNG v1.1.1-beta.2 — 测试版\n\n全站 UX 优化（第二批）：\n\n'
     '- Modal 支持 ESC 关闭 + 打开时锁定背景滚动\n'
     '- 图片上传进度百分比实时显示\n'
     '- 代码块限高滚动 + 复制按钮固定悬浮'),
]

for version, fname, note in RELEASES:
    tag = f'v{version}'
    url = f'https://Grabrun:{TOKEN}@github.com/Grabrun/MoeRNG.git'
    r = subprocess.run(['git', 'tag', tag], cwd=ROOT, capture_output=True, text=True)
    if r.returncode != 0:
        print(f'[WARN] tag {tag}: {r.stderr.strip()}')
    r2 = subprocess.run(['git', 'push', url, tag], cwd=ROOT, capture_output=True, text=True)
    print(r2.stdout.strip() or r2.stderr.strip())
    zip_path = os.path.join(ROOT, 'releases', fname)
    if not os.path.isfile(zip_path):
        print(f'[FAIL] zip 不存在: {fname}')
        continue
    github_release(version, zip_path, note)
