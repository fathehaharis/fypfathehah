<?php
declare(strict_types=1);

/**
 * Test DB connector tailored to your DDL.
 * Defaults to DB timelesscarrental_test.
 * Override with environment variables if needed.
 */
function getTestDB(): mysqli {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $name = getenv('DB_NAME') ?: 'timelesscarrental_test';
    $port = (int)(getenv('DB_PORT') ?: 3306);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');

    // Align to MYT for date math in tests that rely on day boundaries
    $db->query("SET time_zone = '+08:00'");

    return $db;
}