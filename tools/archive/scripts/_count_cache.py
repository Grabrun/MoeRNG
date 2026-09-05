import re
p = 'src/app/Controllers/HomeController.php'
s = open(p, encoding='utf-8').read()

old = '''        $totalImages = 0;
        $totalCategories = 0;

        try {
            $totalImages = Image::count("status = 'active'");
            $totalCategories = Category::count();
        } catch (\\Throwable) {
            // DB may not be available
        }
'''
new = '''        // v1.2.1 性能优化: 统计数字 60s 短缓存（文件缓存，避免每次请求都跑 COUNT）。
        // 对用户无感（60s 内的数字变化不影响体验），DB 压力降至 1/60。
        $totalImages = 0;
        $totalCategories = 0;
        $cacheFile = __DIR__ . '/../../../var/cache/home_stats.php';
        $cacheTtl = 60;
        $cacheData = null;
        try {
            if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
                $cacheData = include $cacheFile;
                $cacheData = is_array($cacheData) ? $cacheData : null;
            }
        } catch (\\Throwable) {}

        if ($cacheData !== null) {
            $totalImages = (int) ($cacheData['images'] ?? 0);
            $totalCategories = (int) ($cacheData['categories'] ?? 0);
        } else {
            try {
                $totalImages = Image::count("status = 'active'");
                $totalCategories = Category::count();
                $dir = dirname($cacheFile);
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                $payload = "<?php return " . var_export(['images' => $totalImages, 'categories' => $totalCategories], true) . ";";
                @file_put_contents($cacheFile, $payload, LOCK_EX);
            } catch (\\Throwable) {
                // DB may not be available — skip caching, fall back to zero.
            }
        }
'''
if old in s:
    s = s.replace(old, new, 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('OK: home_stats 60s cache added')
else:
    print('MISS controller block')
