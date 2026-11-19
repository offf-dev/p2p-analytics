<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_auth();

$date = $_GET['date'] ?? '';
$currencyParam = strtoupper(trim((string)($_GET['currency'] ?? '')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_err('date required (YYYY-MM-DD)', 422);
}

$currencies = $currencyParam ? [$currencyParam] : ['USD', 'THB'];
$allowed = ['USD', 'THB', 'EUR'];
foreach ($currencies as $c) {
    if (!in_array($c, $allowed, true)) {
        json_err("currency {$c} not supported", 422);
    }
}

$result = [];
foreach ($currencies as $cur) {
    $result[$cur] = get_or_fetch_rate($date, $cur);
}

json_out(['date' => $date, 'rates' => $result]);

function get_or_fetch_rate(string $date, string $currency): ?array
{
    $stmt = db()->prepare('SELECT rate_uah, rate_date FROM nbu_rates WHERE rate_date = ? AND currency = ?');
    $stmt->execute([$date, $currency]);
    $cached = $stmt->fetch();
    if ($cached) {
        return [
            'rate_uah' => (float)$cached['rate_uah'],
            'rate_date' => $cached['rate_date'],
            'source' => 'cache',
        ];
    }

    $fetched = fetch_nbu($date, $currency);
    if (!$fetched) {
        return null;
    }

    $ins = db()->prepare(
        'INSERT IGNORE INTO nbu_rates (rate_date, currency, rate_uah) VALUES (?, ?, ?)'
    );
    $ins->execute([$fetched['rate_date'], $currency, $fetched['rate_uah']]);

    return $fetched + ['source' => 'nbu'];
}

function fetch_nbu(string $date, string $currency): ?array
{
    $yyyymmdd = str_replace('-', '', $date);
    $url = "https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode={$currency}&date={$yyyymmdd}&json";

    $ctx = stream_context_create(['http' => ['timeout' => 7, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) { return null; }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data[0]['rate'])) {
        return null;
    }
    $row = $data[0];
    $exchangeDate = $row['exchangedate'] ?? null;
    $rateDate = $date;
    if ($exchangeDate && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $exchangeDate, $m)) {
        $rateDate = "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return [
        'rate_uah' => (float)$row['rate'],
        'rate_date' => $rateDate,
    ];
}