#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# 向 MEMORY.md 追加 SemVer 版本命名规范（用户约定，2026-08-09）
p = r'D:\projects\2026-08-05-10-13-25\.workbuddy\memory\MEMORY.md'
s = open(p, encoding='utf-8').read()

note = '''
## 版本命名规范（用户约定 2026-08-09：按 semver.org/lang/zh-CN/）
- **格式 `X.Y.Z`**（主.次.修订，禁前导零）：主=不兼容 API 改动、次=向下兼容新功能、修订=向下兼容修复。每次递增后低位归零。
- **测试阶段用预发布后缀 `-beta.N`**：标识符限 `[0-9A-Za-z-]`、点分隔、禁前导零。优先级：`1.0.33-beta.1 < 1.0.33-beta.2 < ... < 1.0.33`（正式版）。
- **测试期递增规则**：修复 bug → core 不变、`beta.N+1`（1.0.33-beta.1 → 1.0.33-beta.2）；新增向下兼容功能 → `MINOR+1` + `beta.1`（1.1.0-beta.1）；不兼容改动 → `MAJOR+1` + `beta.1`（2.0.0-beta.1）；**测试通过正式发布 → 去掉后缀**（1.0.33）。
- **当前版本：`1.0.33-beta.1`**（bootstrap.php 定义，ApiController:144 与 home.php:278 兜底字面量 + _package.py 文件名模板同步；升版时四处一起改）。
- 文件名带 `v` 前缀是 git tag 惯例（`v1.0.33-beta.1`），semver 本体不含 v —— 文件名 `MoeRNG-v1.0.33-beta.1-{stamp}.zip`。
'''

open(p, 'a', encoding='utf-8').write(note)
print('MEMORY.md updated, length:', len(open(p, encoding='utf-8').read()))
