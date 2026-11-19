<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$authRequired = (bool)$config['password_hash'];
$authed = !$authRequired || !empty($_SESSION['auth']);

json_out([
    'auth_required' => $authRequired,
    'authenticated' => $authed,
]);