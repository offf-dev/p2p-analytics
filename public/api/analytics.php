<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_auth();

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

if (!$from || !$to) {
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        json_err('month required (YYYY-MM) or from/to pair (YYYY-MM-DD)', 422);
    }
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    json_err('from/to must be YYYY-MM-DD', 422);
}
if ($from > $to) {
    json_err('from must be <= to', 422);
}

$stmt = db()->prepare('SELECT * FROM deals WHERE deal_date BETWEEN ? AND ? ORDER BY deal_date, deal_time, id');
$stmt->execute([$from, $to]);
$deals = $stmt->fetchAll();

$sumUahSpent = 0.0;
$sumUsdtBought = 0.0;
$sumUsdtSold = 0.0;
$sumThbReceived = 0.0;

$byExchange = [];
foreach (ALLOWED_EXCHANGES as $ex) {
    $byExchange[$ex] = [
        'buy_count' => 0, 'sell_count' => 0,
        'uah_spent' => 0.0, 'usdt_bought' => 0.0,
        'usdt_sold' => 0.0, 'thb_received' => 0.0,
    ];
}

foreach ($deals as $d) {
    $from_amt = (float)$d['amount_from'];
    $to_amt = (float)$d['amount_to'];
    $ex = $d['exchange'];
    if ($d['direction'] === 'UAH_USDT') {
        $sumUahSpent += $from_amt;
        $sumUsdtBought += $to_amt;
        $byExchange[$ex]['buy_count']++;
        $byExchange[$ex]['uah_spent'] += $from_amt;
        $byExchange[$ex]['usdt_bought'] += $to_amt;
    } elseif ($d['direction'] === 'USDT_THB') {
        $sumUsdtSold += $from_amt;
        $sumThbReceived += $to_amt;
        $byExchange[$ex]['sell_count']++;
        $byExchange[$ex]['usdt_sold'] += $from_amt;
        $byExchange[$ex]['thb_received'] += $to_amt;
    }
}

$avgBuyRate = $sumUsdtBought > 0 ? $sumUahSpent / $sumUsdtBought : null;
$avgSellRate = $sumUsdtSold > 0 ? $sumThbReceived / $sumUsdtSold : null;

$effectiveUahPerThb = null;
$utilizedUsdt = 0.0;
$uahUtilized = 0.0;
$thbUtilized = 0.0;
if ($sumUahSpent > 0 && $sumThbReceived > 0 && $sumUsdtBought > 0 && $sumUsdtSold > 0) {
    $utilizedUsdt = min($sumUsdtBought, $sumUsdtSold);
    $uahUtilized = $utilizedUsdt * $avgBuyRate;
    $thbUtilized = $utilizedUsdt * $avgSellRate;
    if ($thbUtilized > 0) {
        $effectiveUahPerThb = $uahUtilized / $thbUtilized;
    }
}

// NBU averages
$nbuAvgUahPerThb = null;   // средний THB/UAH за дни всех сделок (для hero сравнения)
$nbuAvgBuyUsdUah = null;   // средний USD/UAH за дни покупок UAH→USDT
$nbuAvgSellUsdThb = null;  // средний кросс USD/THB за дни продаж USDT→THB
if ($deals) {
    $thbSum = 0.0; $thbN = 0;
    $buyUsdSum = 0.0; $buyN = 0;
    $sellCrossSum = 0.0; $sellN = 0;
    foreach ($deals as $d) {
        $rThb = nbu_rate_cached($d['deal_date'], 'THB');
        $rUsd = nbu_rate_cached($d['deal_date'], 'USD');
        if ($rThb !== null) { $thbSum += $rThb; $thbN++; }
        if ($d['direction'] === 'UAH_USDT' && $rUsd !== null) {
            $buyUsdSum += $rUsd; $buyN++;
        }
        if ($d['direction'] === 'USDT_THB' && $rUsd !== null && $rThb !== null && $rThb > 0) {
            $sellCrossSum += $rUsd / $rThb; $sellN++;
        }
    }
    if ($thbN > 0) { $nbuAvgUahPerThb = $thbSum / $thbN; }
    if ($buyN > 0) { $nbuAvgBuyUsdUah = $buyUsdSum / $buyN; }
    if ($sellN > 0) { $nbuAvgSellUsdThb = $sellCrossSum / $sellN; }
}

$savingsPerThb = null;
$savingsTotalUah = null;
$alternativeUahCost = null;
if ($effectiveUahPerThb !== null && $nbuAvgUahPerThb !== null && $thbUtilized > 0) {
    $savingsPerThb = $nbuAvgUahPerThb - $effectiveUahPerThb;
    // Сколько бы UAH отдал за те же THB по курсу НБУ
    $alternativeUahCost = $nbuAvgUahPerThb * $thbUtilized;
    $savingsTotalUah = $alternativeUahCost - $uahUtilized;
}

json_out([
    'from' => $from,
    'to' => $to,
    'deals_count' => count($deals),
    'totals' => [
        'uah_spent' => $sumUahSpent,
        'usdt_bought' => $sumUsdtBought,
        'usdt_sold' => $sumUsdtSold,
        'thb_received' => $sumThbReceived,
        'usdt_balance' => $sumUsdtBought - $sumUsdtSold,
    ],
    'average_rates' => [
        'buy_uah_per_usdt' => $avgBuyRate,
        'sell_thb_per_usdt' => $avgSellRate,
        'effective_uah_per_thb' => $effectiveUahPerThb,
        'nbu_avg_uah_per_thb' => $nbuAvgUahPerThb,
        'nbu_avg_buy_usd_uah' => $nbuAvgBuyUsdUah,
        'nbu_avg_sell_usd_thb' => $nbuAvgSellUsdThb,
        'savings_uah_per_thb' => $savingsPerThb,
        'savings_total_uah' => $savingsTotalUah,
    ],
    'savings' => [
        'utilized_usdt' => $utilizedUsdt,
        'uah_actual' => $uahUtilized,
        'uah_alternative_nbu' => $alternativeUahCost,
        'thb_utilized' => $thbUtilized,
        'total_uah' => $savingsTotalUah,
        'percent' => ($alternativeUahCost !== null && $alternativeUahCost > 0)
            ? ($savingsTotalUah / $alternativeUahCost * 100) : null,
    ],
    'by_exchange' => $byExchange,
]);

function nbu_rate_cached(string $date, string $currency): ?float
{
    static $cache = [];
    $key = "{$date}|{$currency}";
    if (array_key_exists($key, $cache)) { return $cache[$key]; }

    $stmt = db()->prepare('SELECT rate_uah FROM nbu_rates WHERE rate_date = ? AND currency = ?');
    $stmt->execute([$date, $currency]);
    $row = $stmt->fetch();
    if ($row) { return $cache[$key] = (float)$row['rate_uah']; }

    $stmt = db()->prepare(
        'SELECT rate_uah FROM nbu_rates
         WHERE currency = ? AND rate_date <= ?
         ORDER BY rate_date DESC LIMIT 1'
    );
    $stmt->execute([$currency, $date]);
    $row = $stmt->fetch();
    return $cache[$key] = $row ? (float)$row['rate_uah'] : null;
}