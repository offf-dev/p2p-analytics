<?php

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'p2p_analytics',
        'user' => getenv('DB_USER') ?: 'p2p_user',
        'pass' => getenv('DB_PASS') ?: 'p2p_pass',
    ],
    'password_hash' => getenv('APP_PASSWORD_HASH') ?: '',
];