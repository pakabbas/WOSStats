<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/functions.php';

$existing = [];
for ($i = 1; $i <= 50; $i++) {
    $existing[] = [
        'id' => (string) (1000 + $i),
        'name' => '[TAG]Player' . $i,
        'rank' => $i,
        'power' => 1000000 - ($i * 1000),
        'stove_lv' => 30,
        'last_jump_pct' => null,
        'updated_at' => '2026-01-01 00:00:00',
    ];
}

$incoming = [
    ['rank' => 1, 'name' => '[TAG]Player1', 'power' => 999999999], // raise
    ['rank' => 2, 'name' => 'Player2', 'power' => 1], // bare name match, lower → skip
    ['rank' => 3, 'name' => 'BrandNewHero', 'power' => 500000], // add
];

$merge = mergePlayersPowerByName($existing, $incoming);
$after = count($merge['players']);
$before = count($existing);

echo "before={$before} after={$after}\n";
echo 'matched=' . $merge['stats']['matched'] . "\n";
echo 'added=' . $merge['stats']['added'] . "\n";
echo 'power_increased=' . $merge['stats']['power_increased'] . "\n";
echo 'skipped_not_higher=' . $merge['stats']['skipped_not_higher'] . "\n";

if ($after < $before) {
    fwrite(STDERR, "FAIL: board shrank\n");
    exit(1);
}
if ($after !== 51) {
    fwrite(STDERR, "FAIL: expected 51 players (50 keep + 1 new), got {$after}\n");
    exit(1);
}
if ((int) $merge['players'][0]['power'] !== 999999999) {
    fwrite(STDERR, "FAIL: top power not raised\n");
    exit(1);
}

echo "PASS\n";
