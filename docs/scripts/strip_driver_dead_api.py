# -*- coding: utf-8 -*-
"""批量删除 7 个存储驱动的 configFields()/name() 死 API（v2.0.0-beta.1 收口）。
providerFieldDefs() 保留（StorageProfileController / install step4 表单在用）。
"""
import re, io, sys

ROOT = r"E:\Projects\DouBao\MoeRNG\src\app\Storage"

def strip_block(text, start_marker, end_marker):
    """删除从 start_marker 行开始到 end_marker 行结束（含）的块，返回处理后的文本。"""
    lines = text.split('\n')
    out = []
    skipping = False
    removed = 0
    for ln in lines:
        if not skipping and start_marker in ln:
            skipping = True
        if skipping:
            removed += 1
            if end_marker in ln:
                skipping = False
            continue
        out.append(ln)
    return '\n'.join(out), removed

# —— 1) 四个“委托 providerFieldDefs”的驱动：块以注释行开始，以 “}” 文件结尾结束 ——
delegated = ["CosSdkDriver.php", "ObsSdkDriver.php", "AwsSdkDriver.php", "OssSdkDriver.php"]
for name in delegated:
    p = ROOT + "\\" + name
    with io.open(p, encoding='utf-8') as f:
        text = f.read()
    # 只删文件尾部的 configFields+name 块：从 "Required by StorageInterface" 到最后的 "}"
    idx = text.find("/** Required by StorageInterface; field defs are shared via S3Driver. */")
    if idx == -1:
        print(f"[MISS] {name}: marker not found")
        continue
    text2, removed = strip_block(text, "/** Required by StorageInterface", "\n}\n")
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(text2)
    print(f"[OK] {name}: removed block ({removed} lines)")

# —— 2) S3Driver：删 configFields+name，保留 providerFieldDefs ——
p = ROOT + "\\S3Driver.php"
with io.open(p, encoding='utf-8') as f:
    text = f.read()
text2, removed = strip_block(text, "    public static function configFields(): array", "    }\n}")
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(text2)
print(f"[OK] S3Driver.php: removed block ({removed} lines)")

# —— 3) UpyunSdkDriver：独立 configFields + name（块以 configFields 开始） ——
p = ROOT + "\\UpyunSdkDriver.php"
with io.open(p, encoding='utf-8') as f:
    text = f.read()
text2, removed = strip_block(text, "    public static function configFields(): array", "    }\n}")
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(text2)
print(f"[OK] UpyunSdkDriver.php: removed block ({removed} lines)")

# —— 4) QiniuSdkDriver：独立 configFields + name ——
p = ROOT + "\\QiniuSdkDriver.php"
with io.open(p, encoding='utf-8') as f:
    text = f.read()
text2, removed = strip_block(text, "    public static function configFields(): array", "    }\n}")
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(text2)
print(f"[OK] QiniuSdkDriver.php: removed block ({removed} lines)")

# —— 校验：全部文件不应再出现 configFields/name 定义 ——
import subprocess
print("\n== residual check ==")
for name in delegated + ["S3Driver.php", "UpyunSdkDriver.php", "QiniuSdkDriver.php", "LocalDriver.php"]:
    p = ROOT + "\\" + name
    with io.open(p, encoding='utf-8') as f:
        t = f.read()
    hits = [ln for ln in t.split('\n') if 'configFields' in ln or 'public static function name' in ln]
    if hits:
        print(f"[RESIDUAL] {name}: {hits}")
    else:
        print(f"[CLEAN] {name}")
