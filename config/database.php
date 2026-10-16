<?php

/*
 * AIraut database connection
 *
 * Replace the four placeholder values below with the exact database
 * details shown in Hostinger hPanel before testing the website.
 * Do not use your local MySQL credentials here.
 */

$host = 'REPLACE_WITH_HOSTINGER_DB_HOST';
$dbname = 'REPLACE_WITH_HOSTINGER_DB_NAME';
$username = 'REPLACE_WITH_HOSTINGER_DB_USERNAME';
$password = 'REPLACE_WITH_NEW_DATABASE_PASSWORD';

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
