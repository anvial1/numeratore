<?php
/**
 * Admin: statistics of the tickets taken (printed) per day and per service (reparto), and the
 * average wait (ticket → first call). Source: queue_daily_counts, filled by queueTake() (tickets)
 * and queueCall() (wait_seconds / waited); it is not touched by the midnight reset nor by
 * "Azzera la coda di oggi". ?csv=1 downloads the table, ?pdf=1 a PDF report (includes/StatsPdf.php).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole(['admin']);

$today   = date('Y-m-d');
$isDate  = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$presets = [
    'oggi'  => ['Oggi', $today, $today],
    '7'     => ['Ultimi 7 giorni', date('Y-m-d', strtotime('-6 days')), $today],
    '30'    => ['Ultimi 30 giorni', date('Y-m-d', strtotime('-29 days')), $today],
    'mese'  => ['Questo mese', date('Y-m-01'), $today],
    'mesep' => ['Mese scorso', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'anno'  => ["Quest'anno", date('Y-01-01'), $today],
];
$preset = $_GET['p'] ?? (isset($_GET['from']) ? '' : '30');
if (isset($presets[$preset])) {
    [, $from, $to] = $presets[$preset];
} else {
    $from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : $presets['30'][1];
    $to   = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $today;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $preset = '';
}

$services = queueServices(false);
$byId     = array_column($services, null, 'id');
$st = getDBConnection()->prepare("SELECT count_date, service_id, tickets, wait_seconds, waited FROM queue_daily_counts WHERE count_date BETWEEN ? AND ? ORDER BY count_date");
$st->execute([$from, $to]);
$rows = $st->fetchAll();

// Services with tickets in the period (a disabled one still counts if it had tickets).
$used = [];
foreach ($rows as $r) {
    $used[(int) $r['service_id']] = true;
}
$cols = array_values(array_filter($services, static fn($s) => isset($used[(int) $s['id']])));

// Day (or month, for periods longer than 3 months) => [service_id => tickets]
$days  = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
$grain = $days > 92 ? 'month' : 'day';
$keyOf = static fn(string $d) => $grain === 'month' ? substr($d, 0, 7) : $d;
$buckets = [];
for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
    $buckets[$keyOf(date('Y-m-d', $t))] = [];
}
$perService = [];
$perDay     = [];
// Waiting time (ticket taken → first call): [seconds, tickets] per bucket, per service and in total.
$waitBucket = $waitService = [];
$waitTotal  = [0, 0];
$addWait    = static function (?array &$w, array $r): void {
    $w = [($w[0] ?? 0) + (int) $r['wait_seconds'], ($w[1] ?? 0) + (int) $r['waited']];
};
foreach ($rows as $r) {
    $k = $keyOf($r['count_date']);
    $sid = (int) $r['service_id'];
    $buckets[$k][$sid] = ($buckets[$k][$sid] ?? 0) + (int) $r['tickets'];
    $perService[$sid]  = ($perService[$sid] ?? 0) + (int) $r['tickets'];
    $perDay[$r['count_date']] = ($perDay[$r['count_date']] ?? 0) + (int) $r['tickets'];
    $addWait($waitBucket[$k], $r);
    $addWait($waitService[$sid], $r);
    $addWait($waitTotal, $r);
}
$avgWait   = static fn(?array $w) => !empty($w[1]) ? $w[0] / $w[1] : null;
$total     = array_sum($perService);
$elapsed   = max(1, (int) round((strtotime(min($to, $today)) - strtotime($from)) / 86400) + 1); // future days don't lower the average
$avg       = $from > $today ? 0 : $total / $elapsed;
$bestDay   = $perDay ? array_search(max($perDay), $perDay, true) : null;

$weekdays = ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'];
$months   = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
$label = static function (string $k, bool $long = false) use ($grain, $weekdays, $months) {
    if ($grain === 'month') {
        return $months[(int) substr($k, 5, 2) - 1] . ' ' . substr($k, 0, 4);
    }
    $t = strtotime($k);
    return ($long ? $weekdays[(int) date('w', $t)] . ' ' : '') . date('d/m' . ($long ? '/Y' : ''), $t);
};
$svcName = static fn(array $s) => trim($s['letter'] . ' · ' . $s['name'], ' ·');

if (!empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="biglietti_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel reads the accents
    fputcsv($out, array_merge([$grain === 'month' ? 'Mese' : 'Data'], array_map($svcName, $cols), ['Totale', 'Attesa media (minuti)']), ';');
    foreach ($buckets as $k => $b) {
        $line = [$grain === 'month' ? $k : date('d/m/Y', strtotime($k))];
        foreach ($cols as $s) {
            $line[] = $b[(int) $s['id']] ?? 0;
        }
        $line[] = array_sum($b);
        $aw = $avgWait($waitBucket[$k] ?? null);
        $line[] = $aw === null ? '' : number_format($aw / 60, 1, ',', '');
        fputcsv($out, $line, ';');
    }
    exit;
}

$maxBucket = max(1, ...array_map('array_sum', array_values($buckets) ?: [[]]));
$maxSvc    = $perService ? max($perService) : 1;
$qs        = static fn(array $extra) => '?' . http_build_query(array_merge(['from' => $from, 'to' => $to], $extra));
$n         = static fn($x) => number_format((float) $x, 0, ',', '.');

if (!empty($_GET['pdf'])) {
    require_once __DIR__ . '/../includes/StatsPdf.php';
    $period = $from === $to ? 'Giorno ' . date('d/m/Y', strtotime($from)) : 'Dal ' . date('d/m/Y', strtotime($from)) . ' al ' . date('d/m/Y', strtotime($to));
    $pdf = new StatsPdf('Statistiche biglietti — ' . appName(), $period, UPGRADE_CONTACT, __DIR__ . '/../assets/brand/upgrade-logo.png');
    $pdf->AddPage();
    $tiles = [
        ['Biglietti emessi', $n($total), $from !== $to ? $elapsed . ' giorn' . ($elapsed === 1 ? 'o' : 'i') : 'nel giorno'],
        ['Media al giorno', number_format($avg, $avg < 10 ? 1 : 0, ',', '.'), 'biglietti'],
        ['Giorno con più biglietti', $bestDay ? $n($perDay[$bestDay]) : '—', $bestDay ? $weekdays[(int) date('w', strtotime($bestDay))] . ' ' . date('d/m/Y', strtotime($bestDay)) : 'nessun biglietto'],
        ['Attesa media', queueDuration($avgWait($waitTotal)), $waitTotal[1] ? 'su ' . $n($waitTotal[1]) . ' chiamate' : 'nessuna chiamata'],
    ];
    $pdf->tiles($tiles);
    if (count($buckets) > 1) {
        $pdf->section('Biglietti ' . ($grain === 'month' ? 'al mese' : 'al giorno'));
        $pdf->bars(array_map('array_sum', $buckets), $label(array_key_first($buckets)), $label(array_key_last($buckets)));
    }
    $pdf->section('Per reparto');
    if ($cols) {
        $pdf->table(
            [['Reparto', 70, 'L'], ['Biglietti', 25, 'R'], ['%', 18, 'R'], ['Media al giorno', 32, 'R'], ['Attesa media', 35, 'R']],
            array_map(fn($s) => [
                ['text' => $svcName($s) . ($s['active'] ? '' : ' (disattivato)'), 'dot' => $s['color']],
                $n($perService[(int) $s['id']] ?? 0),
                ($total ? round(($perService[(int) $s['id']] ?? 0) * 100 / $total) : 0) . '%',
                number_format($from > $today ? 0 : ($perService[(int) $s['id']] ?? 0) / $elapsed, 1, ',', '.'),
                queueDuration($avgWait($waitService[(int) $s['id']] ?? null)),
            ], $cols),
            ['Totale', $n($total), $total ? '100%' : '0%', number_format($avg, 1, ',', '.'), queueDuration($avgWait($waitTotal))]
        );
    } else {
        $pdf->note('Nessun biglietto in questo periodo.');
    }
    $pdf->section($grain === 'month' ? 'Mese per mese' : 'Giorno per giorno');
    $multi = count($cols) > 1;
    $svcW  = $multi ? min(22, 80 / count($cols)) : 0;
    $first = 180 - 28 - 32 - $svcW * ($multi ? count($cols) : 0);
    $tcols = [[$grain === 'month' ? 'Mese' : 'Giorno', $first, 'L']];
    if ($multi) foreach ($cols as $s) $tcols[] = [$s['letter'] ?: mb_substr($s['name'], 0, 6), $svcW, 'R'];
    $tcols[] = ['Totale', 28, 'R'];
    $tcols[] = ['Attesa media', 32, 'R'];
    $trows = [];
    foreach (array_reverse($buckets, true) as $k => $b) {
        $r = [$label($k, true)];
        if ($multi) foreach ($cols as $s) $r[] = $n($b[(int) $s['id']] ?? 0);
        $r[] = $n(array_sum($b));
        $r[] = queueDuration($avgWait($waitBucket[$k] ?? null));
        $trows[] = $r;
    }
    $foot = ['Totale'];
    if ($multi) foreach ($cols as $s) $foot[] = $n($perService[(int) $s['id']] ?? 0);
    $foot[] = $n($total);
    $foot[] = queueDuration($avgWait($waitTotal));
    $pdf->table($tcols, $trows, $foot);
    $pdf->note('Conta ogni biglietto preso al totem; il conteggio resta anche dopo gli azzeramenti. L\'attesa va dal biglietto alla sua prima chiamata ("Richiama" e "Precedente" non la cambiano); attese oltre 4 ore non sono contate.');
    $pdf->Output('D', 'statistiche_' . $from . '_' . $to . '.pdf');
    exit;
}

$pageTitle = 'Statistiche';
include __DIR__ . '/../includes/header.php';
?>
<style>
.qs-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:var(--space-lg); }
.qs-filters .btn { padding:6px 12px; font-size:.88rem; }
.qs-filters .active { background:var(--primary); color:#fff; border-color:var(--primary); }
.qs-filters form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-left:auto; }
.qs-filters input[type=date] { width:auto; }
.qs-tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:var(--space-md); margin-bottom:var(--space-lg); }
.qs-tile { background:#fff; border:1px solid var(--border-color); border-radius:12px; padding:16px 18px; }
.qs-tile .k { font-size:.85rem; color:var(--text-secondary); }
.qs-tile .v { font-size:2rem; font-weight:700; color:var(--text-primary); line-height:1.2; font-variant-numeric:tabular-nums; }
.qs-tile .s { font-size:.85rem; color:var(--text-secondary); }
.qs-chart { position:relative; height:220px; display:flex; align-items:flex-end; gap:2px; border-bottom:1px solid var(--border-color); padding-top:24px; }
.qs-chart .b { flex:1; min-width:3px; height:100%; display:flex; align-items:flex-end; position:relative; cursor:default; }
.qs-chart .b i { display:block; width:100%; background:var(--primary); border-radius:4px 4px 0 0; min-height:0; }
.qs-chart .b:hover i { background:var(--primary-dark); }
.qs-chart .b .tip { display:none; position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%); background:var(--text-primary); color:#fff; font-size:.8rem; padding:6px 9px; border-radius:6px; white-space:nowrap; z-index:2; pointer-events:none; }
.qs-chart .b:hover .tip { display:block; }
.qs-chart .grid { position:absolute; left:0; right:0; border-top:1px dashed var(--border-color); font-size:.72rem; color:var(--text-secondary); pointer-events:none; }
.qs-chart .grid span { position:absolute; top:-16px; left:0; }
.qs-axis { display:flex; justify-content:space-between; font-size:.78rem; color:var(--text-secondary); margin-top:6px; }
.qs-table { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
.qs-table th, .qs-table td { padding:8px 6px; border-bottom:1px solid var(--border-color); text-align:right; white-space:nowrap; }
.qs-table th:first-child, .qs-table td:first-child { text-align:left; }
.qs-table tr.zero td { color:var(--text-secondary); }
.qs-table tfoot td { font-weight:700; border-bottom:0; }
.qs-dot { display:inline-block; width:12px; height:12px; border-radius:50%; vertical-align:-1px; margin-right:6px; }
.qs-bar { height:10px; border-radius:0 4px 4px 0; min-width:2px; }
.qs-wrap { overflow-x:auto; }
</style>

<div class="page-header">
    <h1><i class="fas fa-chart-column"></i> Statistiche biglietti</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= h($qs(['pdf' => 1])) ?>" class="btn btn-primary"><i class="fas fa-file-pdf"></i> Scarica PDF</a>
        <a href="<?= h($qs(['csv' => 1])) ?>" class="btn btn-outline"><i class="fas fa-file-csv"></i> Scarica CSV</a>
    </div>
</div>

<div class="qs-filters">
    <?php foreach ($presets as $k => [$l]): ?>
        <a href="?p=<?= h($k) ?>" class="btn btn-outline <?= $preset === (string) $k ? 'active' : '' ?>"><?= h($l) ?></a>
    <?php endforeach; ?>
    <form method="GET">
        <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
        <span>–</span>
        <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
        <button class="btn btn-primary"><i class="fas fa-filter"></i> Mostra</button>
    </form>
</div>

<div class="qs-tiles">
    <div class="qs-tile"><div class="k">Biglietti emessi</div><div class="v"><?= $n($total) ?></div>
        <div class="s"><?= h(date('d/m/Y', strtotime($from))) ?><?= $from !== $to ? ' – ' . h(date('d/m/Y', strtotime($to))) : '' ?></div></div>
    <div class="qs-tile"><div class="k">Media al giorno</div><div class="v"><?= h(number_format($avg, $avg < 10 ? 1 : 0, ',', '.')) ?></div>
        <div class="s">su <?= $elapsed ?> giorn<?= $elapsed === 1 ? 'o' : 'i' ?></div></div>
    <div class="qs-tile"><div class="k">Giorno con più biglietti</div>
        <div class="v"><?= $bestDay ? $n($perDay[$bestDay]) : '—' ?></div>
        <div class="s"><?= $bestDay ? h($weekdays[(int) date('w', strtotime($bestDay))] . ' ' . date('d/m/Y', strtotime($bestDay))) : 'nessun biglietto' ?></div></div>
    <div class="qs-tile"><div class="k">Attesa media</div><div class="v"><?= h(queueDuration($avgWait($waitTotal))) ?></div>
        <div class="s"><?= $waitTotal[1] ? 'dal biglietto alla chiamata · ' . $n($waitTotal[1]) . ' chiamat' . ($waitTotal[1] === 1 ? 'o' : 'i') : 'nessun numero chiamato' ?></div></div>
    <?php if (count($cols) > 1): $top = array_search($maxSvc, $perService, true); ?>
    <div class="qs-tile"><div class="k">Reparto più richiesto</div>
        <div class="v" style="font-size:1.4rem;padding:6px 0"><span class="qs-dot" style="background:<?= h($byId[$top]['color']) ?>"></span><?= h($svcName($byId[$top])) ?></div>
        <div class="s"><?= $n($maxSvc) ?> biglietti · <?= $total ? round($maxSvc * 100 / $total) : 0 ?>%</div></div>
    <?php endif; ?>
</div>

<?php if (count($buckets) > 1): ?>
<div class="card">
    <div class="card-header"><h2><i class="fas fa-chart-column"></i> Biglietti <?= $grain === 'month' ? 'al mese' : 'al giorno' ?></h2></div>
    <div class="card-body">
        <div class="qs-chart" role="img" aria-label="Biglietti <?= $grain === 'month' ? 'al mese' : 'al giorno' ?>">
            <?php foreach ([0.5, 1] as $g): ?>
                <div class="grid" style="bottom:<?= $g * 196 ?>px"><span><?= $n(round($maxBucket * $g)) ?></span></div>
            <?php endforeach; ?>
            <?php foreach ($buckets as $k => $b): $sum = array_sum($b); ?>
                <div class="b">
                    <i style="height:<?= round($sum * 100 / $maxBucket, 2) ?>%"></i>
                    <span class="tip"><strong><?= h($label($k, true)) ?></strong>: <?= $n($sum) ?> bigliett<?= $sum === 1 ? 'o' : 'i' ?>
                        <?php if (count($cols) > 1) foreach ($cols as $s) if (!empty($b[(int) $s['id']])): ?><br><?= h($s['letter'] ?: $s['name']) ?>: <?= $n($b[(int) $s['id']]) ?><?php endif; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="qs-axis"><span><?= h($label(array_key_first($buckets))) ?></span><span><?= h($label(array_key_last($buckets))) ?></span></div>
    </div>
</div>
<?php endif; ?>

<div class="card mt-lg">
    <div class="card-header"><h2><i class="fas fa-layer-group"></i> Per reparto</h2></div>
    <div class="card-body qs-wrap">
        <?php if (!$cols): ?>
            <p class="text-muted">Nessun biglietto in questo periodo.</p>
        <?php else: ?>
        <table class="qs-table">
            <tr><th>Reparto</th><th>Biglietti</th><th>%</th><th>Media al giorno</th><th>Attesa media</th><th style="width:35%"></th></tr>
            <?php foreach ($cols as $s): $c = $perService[(int) $s['id']] ?? 0; ?>
                <tr>
                    <td><span class="qs-dot" style="background:<?= h($s['color']) ?>"></span><?= h($svcName($s)) ?><?= $s['active'] ? '' : ' <span class="text-muted">(disattivato)</span>' ?></td>
                    <td><?= $n($c) ?></td>
                    <td><?= $total ? round($c * 100 / $total) : 0 ?>%</td>
                    <td><?= h(number_format($from > $today ? 0 : $c / $elapsed, 1, ',', '.')) ?></td>
                    <td><?= h(queueDuration($avgWait($waitService[(int) $s['id']] ?? null))) ?></td>
                    <td><div class="qs-bar" style="width:<?= round($c * 100 / $maxSvc, 2) ?>%;background:<?= h($s['color']) ?>" title="<?= h($svcName($s) . ': ' . $c) ?>"></div></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-lg">
    <div class="card-header"><h2><i class="fas fa-table"></i> <?= $grain === 'month' ? 'Mese per mese' : 'Giorno per giorno' ?></h2></div>
    <div class="card-body qs-wrap">
        <table class="qs-table">
            <thead><tr><th><?= $grain === 'month' ? 'Mese' : 'Giorno' ?></th>
                <?php if (count($cols) > 1) foreach ($cols as $s): ?><th><span class="qs-dot" style="background:<?= h($s['color']) ?>"></span><?= h($s['letter'] ?: $s['name']) ?></th><?php endforeach; ?>
                <th>Totale</th><th>Attesa media</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($buckets, true) as $k => $b): $sum = array_sum($b); ?>
                <tr class="<?= $sum ? '' : 'zero' ?>"><td><?= h($label($k, true)) ?></td>
                    <?php if (count($cols) > 1) foreach ($cols as $s): ?><td><?= $n($b[(int) $s['id']] ?? 0) ?></td><?php endforeach; ?>
                    <td><strong><?= $n($sum) ?></strong></td>
                    <td><?= h(queueDuration($avgWait($waitBucket[$k] ?? null))) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td>Totale</td>
                <?php if (count($cols) > 1) foreach ($cols as $s): ?><td><?= $n($perService[(int) $s['id']] ?? 0) ?></td><?php endforeach; ?>
                <td><?= $n($total) ?></td><td><?= h(queueDuration($avgWait($waitTotal))) ?></td></tr></tfoot>
        </table>
        <p class="qa-note mt-md text-muted" style="font-size:.85rem">Conta ogni biglietto preso al totem. Il conteggio resta anche dopo l'azzeramento di mezzanotte o "Azzera la coda di oggi".
            L'<strong>attesa</strong> va dal momento in cui si prende il biglietto alla sua prima chiamata ("Richiama" e "Precedente" non la cambiano); attese oltre 4 ore non vengono contate.</p>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
