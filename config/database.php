<?php

/*
 * AIraut database connection
 *
 * Replace the four placeholder values below with the exact database
 * details shown in Hostinger hPanel before testing the website.
 * Do not use your local MySQL credentials here.
 */

$host = 'srv1258.hstgr.io';
$dbname = 'u544072317_airaut_mvp';
$username = 'u544072317_airaut_app';
$password = 'Airaut@2026#databasepass';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(
    $host,
    $username,
    $password,
    $dbname
);

if ($conn->connect_error) {
    error_log('AIraut database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    exit('Service temporarily unavailable.');
}

if (!$conn->set_charset('utf8mb4')) {
    error_log('AIraut could not set the database character set.');
    http_response_code(500);
    exit('Service temporarily unavailable.');
}
