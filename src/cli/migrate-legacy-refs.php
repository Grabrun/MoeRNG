<?php
declare(strict_types=1);

/**
 * MoeRNG - 历史兼容数据一次性迁移（干跑优先）
 *
 * v2.0.0-beta.19 起，运行时不再为两类旧数据保留兼容分支：
 *   ① images.storage_profile_id 为空 —— 读取时会**明确报错**，不再按
 *      storage / storage_provider 去猜实例（猜错会把图片指向另一个桶，
 *      表现为"图片 404 / 读到别人的图"，且没有任何报错）；
 *   ② images.url 非空 —— URL 一律由 path + 存储驱动实时生成，该列不再被读取。
 *
 * 迁移逻辑只在这里跑一次（可观察、可干跑），而不是每个请求都猜一遍。
 *
 * 用法：
 *   php src/cli/migrate-legacy-refs.php            # 干跑：打印将要做的映射
 *   php src/cli/migrate-legacy-refs.php --apply    # 执行
 *
 * 幂等：只处理 storage_profile_id IS NULL 的行、只清非空 url；重复执行无副作用。
 */

use App\Core\Database;
use App\Models\StorageProfile;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$apply = in_array('--apply', array_slice($argv, 1), true);

require dirname(__DIR__) . '/bootstrap.php';

try {
    $pdo = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, "数据库连接失败（站点尚未安装？）: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

/* ── 候选实例 ─────────────────────────────────────────────────────────── */

$default = StorageProfile::defaultProfile();
$all     = StorageProfile::all('sort_order ASC, id ASC');

$localProfiles = [];
$byProvider    = [];
foreach ($all as $p) {
    if (!$p->isEnabled()) {
        continue;
    }
    if ($p->isS3()) {
        $byProvider[(string) $p->provider][] = $p;
    } else {
        $localProfiles[] = $p;
    }
}

/** 从候选里挑一个：优先默认实例，否则排序第一个。 */
$pick = static function (array $cands) use ($default): ?StorageProfile {
    if ($cands === []) {
        return null;
    }
    foreach ($cands as $c) {
        if ($default !== null && (int) $c->id === (int) $default->id) {
            return $c;
        }
    }
    return $cands[0];
};

/* ── ① 回填 storage_profile_id ────────────────────────────────────────── */

$rows = $pdo->query(
    "SELECT `id`, `path`, `storage`, `storage_provider` FROM `images` "
    . "WHERE `storage_profile_id` IS NULL ORDER BY `id` ASC"
)->fetchAll(\PDO::FETCH_ASSOC);

$resolved  = [];   // id => profileId
$unmatched = [];   // 无法自动判定的行（保持原样，打印出来人工处理）

foreach ($rows as $r) {
    $id     = (int) $r['id'];
    $isS3   = (string) ($r['storage'] ?? 'local') === 's3';
    $target = $isS3
        ? $pick($byProvider[(string) ($r['storage_provider'] ?? '')] ?? [])
        : $pick($localProfiles);

    if ($target === null) {
        $unmatched[] = $r;
        continue;
    }
    $resolved[$id] = (int) $target->id;
}

$urlLeft = (int) $pdo->query(
    "SELECT COUNT(*) FROM `images` WHERE `url` IS NOT NULL AND `url` <> ''"
)->fetchColumn();

/* ── 报告 ─────────────────────────────────────────────────────────────── */

printf("%s 待回填存储归属: %d 行；无法自动判定: %d 行；待清空 url: %d 行%s",
    $apply ? '[执行]' : '[干跑]',
    count($resolved),
    count($unmatched),
    $urlLeft,
    PHP_EOL
);

$printed = 0;
foreach ($resolved as $id => $profileId) {
    if ($printed >= 10) {
        echo '  …（其余略）' . PHP_EOL;
        break;
    }
    printf("  #%d → 实例 #%d「%s」%s", $id, $profileId,
        (string) (StorageProfile::find($profileId)?->name ?? '?'), PHP_EOL);
    $printed++;
}

foreach ($unmatched as $r) {
    printf("  ⚠ #%d 无法判定（storage=%s, provider=%s, path=%s）：无匹配的启用实例，保持原样%s",
        (int) $r['id'], (string) $r['storage'], (string) $r['storage_provider'],
        (string) $r['path'], PHP_EOL);
}

if (!$apply) {
    echo PHP_EOL . '干跑结束，未写入任何数据。确认映射无误后执行：' . PHP_EOL
        . '  php src/cli/migrate-legacy-refs.php --apply' . PHP_EOL;
    exit(0);
}

/* ── ② 执行 ───────────────────────────────────────────────────────────── */

$updatedProfile = 0;
if ($resolved !== []) {
    $upd = $pdo->prepare(
        "UPDATE `images` SET `storage_profile_id` = ? WHERE `id` = ? AND `storage_profile_id` IS NULL"
    );
    $pdo->beginTransaction();
    try {
        foreach ($resolved as $id => $profileId) {
            $upd->execute([$profileId, $id]);
            $updatedProfile += $upd->rowCount();
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, '回填失败（已回滚）: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$clearedUrl = (int) $pdo->exec("UPDATE `images` SET `url` = '' WHERE `url` <> ''");

printf("完成：回填存储归属 %d 行，清空 url %d 行，仍有 %d 行无法自动判定。%s",
    $updatedProfile, $clearedUrl, count($unmatched), PHP_EOL);

if ($unmatched !== []) {
    echo '无法判定的行请到「存储管理」确认后手工处理（或删除这些图片记录）。' . PHP_EOL;
    exit(2);
}
exit(0);
