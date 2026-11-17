<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('X-Robots-Tag: noindex, nofollow');

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$bounds = playerHistoryTimeBounds();
$today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
$earliestDate = $bounds['min'] !== null ? substr($bounds['min'], 0, 10) : $today;

$parseDate = static function (?string $value): ?DateTimeImmutable {
    $value = trim((string) $value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
};

$parseLevel = static function (mixed $value): ?int {
    $value = trim((string) $value);
    if (preg_match('/^\d+$/', $value) !== 1) {
        return null;
    }
    $level = (int) $value;
    return ($level > 0 && $level <= 200) ? $level : null;
};

$startDate = $parseDate($_GET['start'] ?? null) ?? $parseDate($earliestDate);
$endDate = $parseDate($_GET['end'] ?? null) ?? $parseDate($today);
if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$startKey = $startDate?->format('Y-m-d') ?? $earliestDate;
$endKey = $endDate?->format('Y-m-d') ?? $today;
$startTs = $startDate?->setTime(0, 0, 0)->getTimestamp() ?? strtotime($startKey . ' 00:00:00');
$endTs = $endDate?->setTime(23, 59, 59)->getTimestamp() ?? strtotime($endKey . ' 23:59:59');

$allowedSorts = [
    'name',
    'alliance',
    'furnace_initial',
    'furnace_final',
    'power_initial',
    'power_final',
    'power_change',
    'initial_at',
    'final_at',
];
$sort = (string) ($_GET['sort'] ?? 'power_final');
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'power_final';
}
$dir = strtolower(trim((string) ($_GET['dir'] ?? '')));
if ($dir !== 'asc' && $dir !== 'desc') {
    $dir = ($sort === 'name' || $sort === 'alliance') ? 'asc' : 'desc';
}

$allianceQuery = trim((string) ($_GET['alliance'] ?? ''));
$initialMin = $parseLevel($_GET['furnace_initial_min'] ?? null);
$initialMax = $parseLevel($_GET['furnace_initial_max'] ?? null);
$finalMin = $parseLevel($_GET['furnace_final_min'] ?? null);
$finalMax = $parseLevel($_GET['furnace_final_max'] ?? null);
if ($initialMin !== null && $initialMax !== null && $initialMin > $initialMax) {
    [$initialMin, $initialMax] = [$initialMax, $initialMin];
}
if ($finalMin !== null && $finalMax !== null && $finalMin > $finalMax) {
    [$finalMin, $finalMax] = [$finalMax, $finalMin];
}

$filterParams = array_filter([
    'alliance' => $allianceQuery,
    'furnace_initial_min' => $initialMin,
    'furnace_initial_max' => $initialMax,
    'furnace_final_min' => $finalMin,
    'furnace_final_max' => $finalMax,
], static fn(mixed $value): bool => $value !== null && $value !== '');

$filtersActive = $filterParams !== [];

// Pad the query window by a day so "nearest" can pick a snapshot just outside the range.
$from = (new DateTimeImmutable('@' . $startTs))->setTimezone(new DateTimeZone('UTC'))
    ->modify('-1 day')->format('Y-m-d H:i:s');
$to = (new DateTimeImmutable('@' . $endTs))->setTimezone(new DateTimeZone('UTC'))
    ->modify('+1 day')->format('Y-m-d H:i:s');

$history = loadPlayerHistoryBetween($from, $to);
$built = buildPlayerProgressRows(is_array($history) ? $history : [], $startTs, $endTs);
$rows = attachPlayerProgressAlliances($built['rows']);
$furnaceOptions = progressFurnaceLevelOptions($rows);
$totalPlayers = count($rows);
$rows = filterPlayerProgressRows($rows, $allianceQuery, $initialMin, $initialMax, $finalMin, $finalMax);
$rows = sortPlayerProgressRows($rows, $sort, $dir);
$snapshotCount = $built['snapshot_count'];

$sortUrl = static function (string $key) use ($startKey, $endKey, $sort, $dir, $filterParams): string {
    $nextDir = 'desc';
    if ($key === 'name' || $key === 'alliance') {
        $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    } elseif ($sort === $key && $dir === 'desc') {
        $nextDir = 'asc';
    }
    return 'progress-x7k2.php?' . http_build_query(array_merge(
        [
            'start' => $startKey,
            'end' => $endKey,
        ],
        $filterParams,
        [
            'sort' => $key,
            'dir' => $nextDir,
        ]
    ));
};

$ariaSort = static function (string $key) use ($sort, $dir): string {
    if ($sort !== $key) {
        return 'none';
    }
    return $dir === 'asc' ? 'ascending' : 'descending';
};

$furnaceOption = static function (int $level, ?int $selected): string {
    $sel = $selected !== null && $selected === $level ? ' selected' : '';
    return '<option value="' . (int) $level . '"' . $sel . '>' . h(formatFurnaceLevel($level)) . '</option>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#070b12">
    <title>Player Progress — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
    <style>
        .progress-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            gap: 1rem;
            align-items: end;
        }
        .compare-field input[type="date"],
        .compare-field input[type="search"],
        .compare-field select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: rgba(0, 0, 0, 0.28);
            color: var(--text);
            font: inherit;
            outline: none;
        }
        .progress-form .compare-actions {
            display: flex;
            align-items: end;
        }
        .th-sort-link {
            color: inherit;
            text-decoration: none;
        }
        .th-sort-link[aria-current="true"] {
            text-decoration: underline;
        }
        .alliance-table th:nth-child(2),
        .alliance-table td.progress-alliance-cell {
            max-width: 10rem;
            width: 10rem;
        }
        .progress-alliance-text {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .alliance-table td[data-label="Player"] {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="page-header">
            <div>
                <p class="eyebrow">WHITEOUT SURVIVAL</p>
                <h1>Player Progress</h1>
                <p class="subtitle"><?= h($stateName) ?> · furnace and power between two dates · top 200 snapshots</p>
            </div>
        </header>

        <section class="panel">
            <form class="compare-form progress-form" method="get" action="progress-x7k2.php">
                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <input type="hidden" name="dir" value="<?= h($dir) ?>">
                <div class="compare-field">
                    <label for="start">Start date</label>
                    <input type="date" id="start" name="start" value="<?= h($startKey) ?>" required>
                </div>
                <div class="compare-field">
                    <label for="end">End date</label>
                    <input type="date" id="end" name="end" value="<?= h($endKey) ?>" required>
                </div>
                <div class="compare-field">
                    <label for="alliance">Alliance</label>
                    <input type="search" id="alliance" name="alliance" value="<?= h($allianceQuery) ?>" placeholder="Name or tag" autocomplete="off">
                </div>
                <div class="compare-field">
                    <label for="furnace_initial_min">Initial furnace min</label>
                    <select id="furnace_initial_min" name="furnace_initial_min">
                        <option value="">Any</option>
                        <?php foreach ($furnaceOptions as $level): ?>
                            <?= $furnaceOption((int) $level, $initialMin) ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="compare-field">
                    <label for="furnace_initial_max">Initial furnace max</label>
                    <select id="furnace_initial_max" name="furnace_initial_max">
                        <option value="">Any</option>
                        <?php foreach ($furnaceOptions as $level): ?>
                            <?= $furnaceOption((int) $level, $initialMax) ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="compare-field">
                    <label for="furnace_final_min">Final furnace min</label>
                    <select id="furnace_final_min" name="furnace_final_min">
                        <option value="">Any</option>
                        <?php foreach ($furnaceOptions as $level): ?>
                            <?= $furnaceOption((int) $level, $finalMin) ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="compare-field">
                    <label for="furnace_final_max">Final furnace max</label>
                    <select id="furnace_final_max" name="furnace_final_max">
                        <option value="">Any</option>
                        <?php foreach ($furnaceOptions as $level): ?>
                            <?= $furnaceOption((int) $level, $finalMax) ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="compare-actions">
                    <button type="submit" class="btn-primary">Show</button>
                </div>
            </form>

            <p class="subtitle" style="margin-top: 1rem;">
                <?= (int) count($rows) ?> players
                <?php if ($filtersActive): ?>
                    · filtered from <?= (int) $totalPlayers ?>
                <?php endif; ?>
                · <?= (int) $snapshotCount ?> snapshots in this window
                <?php if ($bounds['min'] !== null && $bounds['max'] !== null): ?>
                    · history available <?= h(formatDisplayDate($bounds['min'])) ?>
                    to <?= h(formatDisplayDate($bounds['max'])) ?>
                    (<?= (int) $bounds['count'] ?> snapshots, kept ~90 days)
                <?php else: ?>
                    · no history snapshots stored yet
                <?php endif; ?>
            </p>

            <div class="table-wrap">
                <table class="alliance-table responsive-table">
                    <thead>
                        <tr>
                            <th>Serial Number</th>
                            <th aria-sort="<?= h($ariaSort('alliance')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('alliance')) ?>"<?= $sort === 'alliance' ? ' aria-current="true"' : '' ?>>Alliance</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('name')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('name')) ?>"<?= $sort === 'name' ? ' aria-current="true"' : '' ?>>Player</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('furnace_initial')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('furnace_initial')) ?>"<?= $sort === 'furnace_initial' ? ' aria-current="true"' : '' ?>>Initial furnace</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('furnace_final')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('furnace_final')) ?>"<?= $sort === 'furnace_final' ? ' aria-current="true"' : '' ?>>Final furnace</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('power_initial')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('power_initial')) ?>"<?= $sort === 'power_initial' ? ' aria-current="true"' : '' ?>>Initial power</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('power_final')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('power_final')) ?>"<?= $sort === 'power_final' ? ' aria-current="true"' : '' ?>>Final power</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('power_change')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('power_change')) ?>"<?= $sort === 'power_change' ? ' aria-current="true"' : '' ?>>Power change</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('initial_at')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('initial_at')) ?>"<?= $sort === 'initial_at' ? ' aria-current="true"' : '' ?>>Initial date</a>
                            </th>
                            <th aria-sort="<?= h($ariaSort('final_at')) ?>">
                                <a class="th-sort-link" href="<?= h($sortUrl('final_at')) ?>"<?= $sort === 'final_at' ? ' aria-current="true"' : '' ?>>Final date</a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="10" class="empty-cell">No players match this date range and filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php $allianceLabel = (string) ($row['alliance'] ?? ''); ?>
                                <tr>
                                    <td data-label="Serial Number"><?= (int) $index + 1 ?></td>
                                    <td data-label="Alliance" class="progress-alliance-cell">
                                        <span class="progress-alliance-text"<?php if ($allianceLabel !== ''): ?> title="<?= h($allianceLabel) ?>"<?php endif; ?>><?= h($allianceLabel) ?></span>
                                    </td>
                                    <td data-label="Player"><?= h((string) $row['name']) ?></td>
                                    <td data-label="Initial furnace"><?= h(isset($row['furnace_initial']) ? formatFurnaceLevel((int) $row['furnace_initial']) : '—') ?></td>
                                    <td data-label="Final furnace"><?= h(isset($row['furnace_final']) ? formatFurnaceLevel((int) $row['furnace_final']) : '—') ?></td>
                                    <td data-label="Initial power"><?= h(formatPower($row['power_initial'] ?? null)) ?></td>
                                    <td data-label="Final power"><?= h(formatPower($row['power_final'] ?? null)) ?></td>
                                    <td data-label="Power change" class="<?= changeClass($row['power_change'] !== null ? (float) $row['power_change'] : null) ?>">
                                        <?= h(formatPowerChange($row['power_change'] ?? null)) ?>
                                    </td>
                                    <td data-label="Initial date"><?= h(formatDisplayDate($row['initial_at'] ?? null)) ?></td>
                                    <td data-label="Final date"><?= h(formatDisplayDate($row['final_at'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>
</html>
