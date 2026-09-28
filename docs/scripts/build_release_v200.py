# -*- coding: utf-8 -*-
"""MoeRNG v2.0.0-beta.1 重新归档：src/ → releases zip（含校验）"""
import io, os, re, zipfile, datetime

ROOT = r"E:\Projects\DouBao\MoeRNG"
SRC = os.path.join(ROOT, 'src')
REL = os.path.join(ROOT, 'releases')
ts = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
name = f'MoeRNG-v2.0.0-beta.1-{ts}.zip'
out = os.path.join(REL, name)

# 收集 src/ 下所有文件（保留相对 src/ 的路径）
entries = []
for dp, dns, fns in os.walk(SRC):
    dns[:] = [d for d in dns if d not in {'.git', '__pycache__', 'node_modules'}]
    for fn in fns:
        p = os.path.join(dp, fn)
        arc = os.path.relpath(p, SRC).replace(os.sep, '/')
        entries.append((p, arc))
entries.sort(key=lambda x: x[1])

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, allowZip64=True) as z:
    for p, arc in entries:
        z.write(p, arc)

# ── 校验 1：zip 完整性 ──
bad = zipfile.ZipFile(out).testzip()
print(f'zip 完整性: {"OK" if bad is None else "损坏: " + str(bad)}')

# ── 校验 2：文件数 / AWS 文件数 ──
z = zipfile.ZipFile(out)
names = z.namelist()
aws = [n for n in names if n.startswith('sdk/aws/')]
print(f'总文件: {len(names)}（期望 1385 量级）')
print(f'sdk/aws: {len(aws)}（期望 397）')

# ── 校验 3：敏感串扫描（真实密钥形态）──
cred_pat = re.compile(r"(sk-[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{16}|(?:'|\")?(?:access_key_id|secret_access_key|secret_key)(?:'|\")?\s*[=:]\s*['\"][A-Za-z0-9+/]{16,}['\"])")
hits = []
for n in names:
    if not n.endswith('.php'):
        continue
    t = z.read(n).decode('utf-8', 'replace')
    for m in cred_pat.finditer(t):
        hits.append((n, m.group(0)))
print(f'真实凭据形态扫描: {"干净" if not hits else hits[:5]}')

# ── 校验 4：兼容层符号在 zip 内 src 代码的功能性残留 ──
func_pats = {
    "storage_s3_provider": re.compile(r"Config::get\('settings\.storage_s3_provider'"),
    "migrateLayout": re.compile(r"function migrateLayout|->migrateLayout\("),
    "runLocalRootMigration": re.compile(r"function runLocalRootMigration|->runLocalRootMigration\("),
    "migrateProviderCredentials": re.compile(r"function migrateProviderCredentials|->migrateProviderCredentials\("),
    "cleanupLegacyObjects": re.compile(r"function cleanupLegacyObjects|->cleanupLegacyObjects\("),
    "LEGACY_REL_DIR": re.compile(r"LEGACY_REL_DIR"),
}
print('兼容层功能性残留（zip 内 src 代码）:')
for k, pat in func_pats.items():
    loc = []
    for n in names:
        if not n.endswith('.php'):
            continue
        t = z.read(n).decode('utf-8', 'replace')
        for m in pat.finditer(t):
            loc.append(f'{n}:{t.count(chr(10), 0, m.start())+1}')
    print(f'  {k}: {"干净" if not loc else loc}')

print(f'\n归档: {out}')
