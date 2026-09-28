# -*- coding: utf-8 -*-
"""MoeRNG v2.0.0-beta.2 归档：src/ → releases zip（含校验，版本号动态读取）"""
import io, os, re, zipfile, datetime

ROOT = r"E:\Projects\DouBao\MoeRNG"
SRC = os.path.join(ROOT, 'src')
REL = os.path.join(ROOT, 'releases')

# 版本号从 bootstrap.php 动态读取（单一来源）
boot = io.open(os.path.join(SRC, 'bootstrap.php'), encoding='utf-8').read()
m = re.search(r"define\('APP_VERSION', '([^']+)'\)", boot)
VER = m.group(1)
ts = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
name = f'MoeRNG-{VER}-{ts}.zip'
out = os.path.join(REL, name)
print(f'版本: {VER}')

entries = []
for dp, dns, fns in os.walk(SRC):
    dns[:] = [d for d in dns if d not in {'.git', '__pycache__', 'node_modules'}]
    for fn in fns:
        p = os.path.join(dp, fn)
        arc = os.path.relpath(p, SRC).replace(os.sep, '/')
        # 覆盖安装修复: config/*.php 是安装器生成的本地状态（installed 标志/凭据），
        # 归档若携带 installed=false 的 app.php，覆盖部署会把安装状态重置回安装向导。
        if arc.startswith('config/') and arc.endswith('.php'):
            continue
        entries.append((p, arc))
entries.sort(key=lambda x: x[1])

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, allowZip64=True) as z:
    for p, arc in entries:
        z.write(p, arc)

bad = zipfile.ZipFile(out).testzip()
print(f'zip 完整性: {"OK" if bad is None else "损坏: " + str(bad)}')
z = zipfile.ZipFile(out)
names = z.namelist()
print(f'总文件: {len(names)}')
print(f'sdk/aws: {sum(1 for n in names if n.startswith("sdk/aws/"))}')

cred_pat = re.compile(r"(sk-[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{16}|(?:'|\")?(?:access_key_id|secret_access_key|secret_key)(?:'|\")?\s*[=:]\s*['\"][A-Za-z0-9+/]{16,}['\"])")
hits = []
for n in names:
    if not n.endswith('.php'):
        continue
    t = z.read(n).decode('utf-8', 'replace')
    for mm in cred_pat.finditer(t):
        hits.append((n, mm.group(0)))
print(f'真实凭据形态扫描: {"干净" if not hits else hits[:5]}')

func_pats = {
    "settings.storage_s3_provider 读取": re.compile(r"Config::get\('settings\.storage_s3_provider'"),
    "migrateLayout": re.compile(r"function migrateLayout|->migrateLayout\("),
    "runLocalRootMigration": re.compile(r"function runLocalRootMigration|->runLocalRootMigration\("),
    "migrateProviderCredentials": re.compile(r"function migrateProviderCredentials|->migrateProviderCredentials\("),
    "cleanupLegacyObjects": re.compile(r"function cleanupLegacyObjects|->cleanupLegacyObjects\("),
    "LEGACY_REL_DIR": re.compile(r"LEGACY_REL_DIR"),
    "bumpFail 存在": re.compile(r"function bumpFail"),
    "availability 存在": re.compile(r"function availability"),
}
print('关键符号（zip 内 src 代码）:')
for k, pat in func_pats.items():
    loc = []
    for n in names:
        if not n.endswith('.php'):
            continue
        t = z.read(n).decode('utf-8', 'replace')
        for mm in pat.finditer(t):
            loc.append(f'{n}:{t.count(chr(10), 0, mm.start())+1}')
    print(f'  {k}: {"零命中(预期)" if not loc and "残留" in k else ("✅ " + str(loc) if loc else "零命中")}')

print(f'\n归档: {out}')
