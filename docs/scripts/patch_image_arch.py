# -*- coding: utf-8 -*-
"""v2.0.0-beta.3: ImageController 精确优化（不迁移方法）
1) finfo 提出循环  2) MEMORY_BASELINE_HEADROOM 常量（两处引用）
"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\app\Controllers\Admin\ImageController.php"
with io.open(P, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

# 1) finfo 提出循环（幂等）
if 'v2.0.0-beta.3: finfo 每次循环重建是纯浪费' not in t:
    # 循环内旧块 → 新块
    old_block = ("            // Validate MIME" + nl
                 + "            $finfo = finfo_open(FILEINFO_MIME_TYPE);" + nl
                 + "            $detectedMime = finfo_file($finfo, $tmpName);" + nl
                 + "            finfo_close($finfo);" + nl)
    new_block = ("            // Validate MIME（finfo 在循环外打开，见上传循环起点）" + nl
                 + "            $detectedMime = finfo_file($finfo, $tmpName);" + nl)
    if old_block not in t:
        raise SystemExit('[FAIL] finfo 循环内块未找到')
    t = t.replace(old_block, new_block, 1)
    # 循环起点插入
    anchor = "$fileCount = count($files['tmp_name']);" + nl
    init = ("$fileCount = count($files['tmp_name']);" + nl
            + "        // v2.0.0-beta.3: finfo 每次循环重建是纯浪费 —— 提到循环外创建一次。" + nl
            + "        $finfo = finfo_open(FILEINFO_MIME_TYPE);" + nl)
    if anchor not in t:
        raise SystemExit('[FAIL] 循环起点锚点未找到')
    t = t.replace(anchor, init, 1)
    print('[OK] finfo 提出循环')
else:
    print('[SKIP] finfo 已在循环外')

# 2) MEMORY_BASELINE_HEADROOM（幂等）
if 'private const MEMORY_BASELINE_HEADROOM' not in t:
    anchor2 = "    private const CONVERT_MEMORY_HEADROOM = 16777216; // 16 MiB" + nl
    if anchor2 not in t:
        raise SystemExit('[FAIL] CONVERT_MEMORY_HEADROOM 锚点未找到')
    t = t.replace(anchor2, anchor2 + "    private const MEMORY_BASELINE_HEADROOM = 16777216; // v2.0.0-beta.3: 解码/转码基线余量（原裸数字两处）" + nl, 1)
    print('[OK] MEMORY_BASELINE_HEADROOM 常量声明')
else:
    print('[SKIP] 常量已存在')

# 两处引用替换（幂等）
n = t.count('$available = $limit - memory_get_usage(true) - 16777216;   // 再留 16 MiB 基线余量')
if n >= 1:
    t = t.replace('$available = $limit - memory_get_usage(true) - 16777216;   // 再留 16 MiB 基线余量',
                  '$available = $limit - memory_get_usage(true) - self::MEMORY_BASELINE_HEADROOM;   // 再留 16 MiB 基线余量')
    print(f'[OK] 基线余量引用常量化（{n} 处）')
else:
    print('[SKIP] 引用已常量化')

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[DONE] ImageController 精确优化完成')
