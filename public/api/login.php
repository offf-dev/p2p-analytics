<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$hash = $config['password_hash'];
if (!$hash) {
    json_out(['ok' => true, 'auth_disabled' => true]);
}

$data = read_json();
$password = (string)($data['password'] ?? '');

if (!$password || !password_verify($password, $hash)) {
    usleep(300_000);
    json_err('Invalid password', 401);
}

$_SESSION['auth'] = true;
json_out(['ok' => true]);