p = 'src/app/Controllers/Admin/DashboardController.php'
s = open(p, encoding='utf-8').read()

old = '''        // 分类分布：扁平树 + 每类图片数
        $categoryStats = [];
        foreach (Category::getFlatTree() as $cat) {
            $categoryStats[] = [
                'id'    => (int) $cat->id,
                'name'  => (string) $cat->name,
                'slug'  => (string) $cat->getSlug(),
                'count' => Image::count('category_id = ?', [(int) $cat->id]),
            ];
        }
        usort($categoryStats, fn ($a, $b) => $b['count'] <=> $a['count']);'''

new = '''        // 分类分布：扁平树 + 每类图片数
        // v1.2.1 deep-audit: N+1 修复——单条 GROUP BY 聚合替代每类一次 COUNT。
        $countsByCat = [];
        try {
            $rows = Database::getInstance()
                ->query("SELECT category_id, COUNT(*) AS c FROM `images` WHERE status = 'active' GROUP BY category_id")
                ->fetchAll(\\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $countsByCat[(int) $r['category_id']] = (int) $r['c'];
            }
        } catch (\\Throwable) {}

        $categoryStats = [];
        foreach (Category::getFlatTree() as $cat) {
            $categoryStats[] = [
                'id'    => (int) $cat->id,
                'name'  => (string) $cat->name,
                'slug'  => (string) $cat->getSlug(),
                'count' => $countsByCat[(int) $cat->id] ?? 0,
            ];
        }
        usort($categoryStats, fn ($a, $b) => $b['count'] <=> $a['count']);'''

if old in s:
    s = s.replace(old, new, 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('OK: dashboard N+1 fixed')
else:
    print('MISS')
