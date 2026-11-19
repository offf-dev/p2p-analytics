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

function require_auth(): void
{
    // Auth is optional: when APP_PASSWORD_HASH isn't set (dev/local), skip the check.
    if (!getenv('APP_PASSWORD_HASH')) return;
    if (empty($_SESSION['auth'])) {
        json_err('Unauthorized', 401);
    }
}