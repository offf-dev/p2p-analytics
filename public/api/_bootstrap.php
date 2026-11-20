<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$config = require __DIR__ . '/config.php';

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $status = 400): void
{
    json_out(['error' => $message], $status);
}

function read_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $config;
        $c = $config['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

const ALLOWED_DIRECTIONS = ['UAH_USDT', 'USDT_THB'];
const ALLOWED_EXCHANGES = ['Binance', 'Bybit', 'BingX'];

function fetch_nbu_from_source(string $date, string $currency): ?array
{
    $yyyymmdd = str_replace('-', '', $date);
    $url = "https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode={$currency}&date={$yyyymmdd}&json";
    $ctx = stream_context_create(['http' => ['timeout' => 7, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data[0]['rate'])) return null;
    $row = $data[0];
    $rateDate = $date;
    if (!empty($row['exchangedate']) && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $row['exchangedate'], $m)) {
        $rateDate = "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return ['rate_uah' => (float)$row['rate'], 'rate_date' => $rateDate];
}

function ensure_nbu_rate(string $date, string $currency): ?float
{
    $stmt = db()->prepare('SELECT rate_uah FROM nbu_rates WHERE rate_date = ? AND currency = ?');
    $stmt->execute([$date, $currency]);
    $row = $stmt->fetch();
    if ($row) return (float)$row['rate_uah'];

    $fetched = fetch_nbu_from_source($date, $currency);
    if (!$fetched) return null;

    $ins = db()->prepare('INSERT IGNORE INTO nbu_rates (rate_date, currency, rate_uah) VALUES (?, ?, ?)');
    $ins->execute([$fetched['rate_date'], $currency, $fetched['rate_uah']]);
    return (float)$fetched['rate_uah'];
}

function require_auth(): void
{
    // Auth is optional: when APP_PASSWORD_HASH isn't set (dev/local), skip the check.
    if (!getenv('APP_PASSWORD_HASH')) return;
    if (empty($_SESSION['auth'])) {
        json_err('Unauthorized', 401);
    }
}