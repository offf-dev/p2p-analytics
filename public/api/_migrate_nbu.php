<?php

// Разовая миграция: для каждой уникальной даты в deals подтягиваем НБУ-курсы USD и THB.
// Требует авторизации. После прогона можно удалить.

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_auth();

set_time_limit(600);

$stmt = db()->query('SELECT DISTINCT deal_date FROM deals ORDER BY deal_date');
$dates = $stmt->fetchAll();
$log = [];
foreach ($dates as $d) {
    $date = $d['deal_date'];
    $usd = ensure_nbu_rate($date, 'USD');
    $thb = ensure_nbu_rate($date, 'THB');
    $log[] = ['date' => $date, 'USD' => $usd, 'THB' => $thb];
}
json_out(['total_dates' => count($log), 'details' => $log]);