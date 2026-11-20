<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':    handle_list(); break;
        case 'POST':   handle_create(); break;
        case 'PUT':
        case 'PATCH':  handle_update(); break;
        case 'DELETE': handle_delete(); break;
        default:       json_err('Method not allowed', 405);
    }
} catch (PDOException $e) {
    json_err('Database error: ' . $e->getMessage(), 500);
}

function handle_list(): void
{
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $direction = $_GET['direction'] ?? null;
    $exchange = $_GET['exchange'] ?? null;

    $sql = 'SELECT * FROM deals WHERE 1=1';
    $params = [];
    if ($from) { $sql .= ' AND deal_date >= ?'; $params[] = $from; }
    if ($to) { $sql .= ' AND deal_date <= ?'; $params[] = $to; }
    if ($direction && in_array($direction, ALLOWED_DIRECTIONS, true)) {
        $sql .= ' AND direction = ?'; $params[] = $direction;
    }
    if ($exchange && in_array($exchange, ALLOWED_EXCHANGES, true)) {
        $sql .= ' AND exchange = ?'; $params[] = $exchange;
    }
    $sql .= ' ORDER BY deal_date DESC, id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    json_out(['deals' => $stmt->fetchAll()]);
}

function validate_payload(array $data): array
{
    $errors = [];
    $deal_date = trim((string)($data['deal_date'] ?? ''));
    $direction = $data['direction'] ?? '';
    $exchange = $data['exchange'] ?? '';
    $amount_from = (float)($data['amount_from'] ?? 0);
    $amount_to = (float)($data['amount_to'] ?? 0);
    $declared_rate = isset($data['declared_rate']) && $data['declared_rate'] !== '' && $data['declared_rate'] !== null
        ? (float)$data['declared_rate'] : null;
    $counterparty = trim((string)($data['counterparty'] ?? ''));
    $note = trim((string)($data['note'] ?? ''));

    if (!$deal_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deal_date)) {
        $errors[] = 'deal_date is required (YYYY-MM-DD)';
    }
    if (!in_array($direction, ALLOWED_DIRECTIONS, true)) {
        $errors[] = 'direction must be one of: ' . implode(', ', ALLOWED_DIRECTIONS);
    }
    if (!in_array($exchange, ALLOWED_EXCHANGES, true)) {
        $errors[] = 'exchange must be one of: ' . implode(', ', ALLOWED_EXCHANGES);
    }
    if ($amount_from <= 0) { $errors[] = 'amount_from must be > 0'; }
    if ($amount_to <= 0) { $errors[] = 'amount_to must be > 0'; }

    if ($errors) { json_err(implode('; ', $errors), 422); }

    // Rate is normalized to "quote per 1 USDT":
    //   UAH→USDT: UAH per 1 USDT (amount_from / amount_to)
    //   USDT→THB: THB per 1 USDT (amount_to / amount_from)
    $rate = $direction === 'UAH_USDT'
        ? $amount_from / $amount_to
        : $amount_to / $amount_from;

    return [
        'deal_date' => $deal_date,
        'direction' => $direction,
        'exchange' => $exchange,
        'amount_from' => $amount_from,
        'amount_to' => $amount_to,
        'rate' => $rate,
        'declared_rate' => $declared_rate,
        'counterparty' => $counterparty ?: null,
        'note' => $note ?: null,
    ];
}

function handle_create(): void
{
    $p = validate_payload(read_json());
    $stmt = db()->prepare(
        'INSERT INTO deals (deal_date, direction, exchange, amount_from, amount_to, rate, declared_rate, counterparty, note)
         VALUES (:deal_date, :direction, :exchange, :amount_from, :amount_to, :rate, :declared_rate, :counterparty, :note)'
    );
    $stmt->execute($p);
    $id = (int)db()->lastInsertId();

    $row = db()->query("SELECT * FROM deals WHERE id = {$id}")->fetch();
    json_out(['deal' => $row], 201);
}

function handle_update(): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { json_err('id required', 422); }
    $p = validate_payload(read_json());
    $p['id'] = $id;
    $stmt = db()->prepare(
        'UPDATE deals SET
            deal_date = :deal_date,
            direction = :direction, exchange = :exchange,
            amount_from = :amount_from, amount_to = :amount_to, rate = :rate,
            declared_rate = :declared_rate,
            counterparty = :counterparty, note = :note
         WHERE id = :id'
    );
    $stmt->execute($p);
    $row = db()->query("SELECT * FROM deals WHERE id = {$id}")->fetch();
    if (!$row) { json_err('Deal not found', 404); }
    json_out(['deal' => $row]);
}

function handle_delete(): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { json_err('id required', 422); }
    $stmt = db()->prepare('DELETE FROM deals WHERE id = ?');
    $stmt->execute([$id]);
    json_out(['deleted' => $stmt->rowCount()]);
}