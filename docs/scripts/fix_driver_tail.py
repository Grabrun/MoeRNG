# -*- coding: utf-8 -*-
"""无条件为 7 个驱动文件补类收尾 }（当前尾部 } 均为方法结尾）。"""
import io

FILES = [
    "CosSdkDriver.php", "ObsSdkDriver.php", "AwsSdkDriver.php", "OssSdkDriver.php",
    "S3Driver.php", "UpyunSdkDriver.php", "QiniuSdkDriver.php",
]
ROOT = r"E:\Projects\DouBao\MoeRNG\src\app\Storage"

for name in FILES:
    p = ROOT + "\\" + name
    with io.open(p, encoding='utf-8') as f:
        t = f.read().rstrip()
    if t.endswith('}'):
        t += '\n}\n'
    else:
        raise SystemExit(f"[FAIL] {name}: does not end with }}")
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print(f"[OK] {name}: class closing brace restored")
