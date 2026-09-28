# -*- coding: utf-8 -*-
"""MoeRNG 深度审计：兼容层残留全量扫描（区分功能残留 vs 文档提及）"""
import io, os, re

ROOT = r"E:\Projects\DouBao\MoeRNG"
skip_dirs = {'.git', 'node_modules', 'releases', '.dsh', 'storage', 'public/storage',
             'design', 'var', 'backups', '.workbuddy', '__pycache__'}

# 符号 → 类别（功能代码命中=残留风险；注释/文档命中=提及）
pats = {
    # 旧布局
    'LAYOUT_V1': '旧布局常量',
    r"layout.?=.'v1'|'v1'.?布局": 'v1 布局标识',
    r"thumbs/'|thumbs_prefix|refs_thumbs_prefix": '旧缩略图前缀',
    # 旧根
    'LEGACY_REL_DIR': '旧根常量',
    'legacyUploadDir': '旧根方法',
    'legacy_dir': '旧根变量',
    # 旧凭据/迁移
    'storage_s3_': '旧凭据键',
    'storage_providers': '旧凭据键',
    'migrateProviderCredentials': '旧凭据迁移',
    'migrateLegacyToProfiles': '旧凭据迁移',
    'insertProfile': '迁移函数',
    'hasDefaultProfile': '迁移函数',
    # 本地根迁移链
    'runLocalRootMigration': '迁根链',
    'migrateLocalMediaRoot': '迁根链',
    'rollbackMedia': '迁根链',
    'moveMediaEntry': '迁根链',
    'rewriteLocalProfilePaths': '迁根链',
    'revertLocalProfilePaths': '迁根链',
    'LOCAL_ROOT_LAYOUT': '迁根门禁',
    'local_root_layout': '迁根门禁',
    'local_root_error': '迁根错误',
    # 迁移工具
    'migrateLayout': '迁移工具',
    'copyObjectWithinDriver': '迁移工具',
    # 清理分支
    'cleanupLegacyObjects': '旧清理',
    'cleanupLegacyRootFiles': '旧清理',
    'cleanupDiagnostics': '旧清理',
    'listFilesRecursive': '旧清理',
    'pruneEmptyDirs': '旧清理',
    # 死 API
    'configFields': '死API',
    'isPost': '死API',
    'defaultDriver': '死API',
    # 版本标识
    r'1\.5\.0-beta\.1': '旧版本号',
    r'v1\.5\.0': '旧版本号',
}

compiled = {k: re.compile(k, re.I) for k in pats}
hits = {}
for dp, dns, fns in os.walk(ROOT):
    dns[:] = [d for d in dns if d not in skip_dirs]
    for fn in fns:
        if not fn.endswith(('.php', '.js', '.md', '.html', '.sql', '.py', '.json', '.txt', '.css')):
            continue
        p = os.path.join(dp, fn)
        rel = os.path.relpath(p, ROOT).replace(os.sep, '/')
        try:
            t = io.open(p, encoding='utf-8', errors='replace').read()
        except Exception:
            continue
        for k, pat in compiled.items():
            for m in pat.finditer(t):
                # 跳过注释行内的是注释还是代码？先记录全部，报告里区分
                line = t.count('\n', 0, m.start()) + 1
                start = max(0, m.start() - 30)
                ctx = t[start:m.end() + 30].replace('\n', '⏎')
                hits.setdefault(k, []).append((rel, line, ctx))

print('═══════ 兼容层残留扫描（按符号）═══════')
total = 0
for k in pats:
    v = hits.get(k, [])
    if not v:
        print(f'[干净] {k}')
        continue
    total += len(v)
    print(f'\n[{k}] {len(v)} 处 —— {pats[k]}')
    for rel, ln, ctx in v[:15]:
        print(f'  {rel}:{ln}: …{ctx}…')
    if len(v) > 15:
        print(f'  … 其余 {len(v)-15} 处')
print(f'\n总计命中: {total}')
