<?php

require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo json_encode([
    'csrf_token' => csrf_token()
]);