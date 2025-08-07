<?php
session_start();

header('Cache-Control: no-store');

$type = $_GET['type'] ?? '';
$path = '';

switch ($type) {
    case 'driver_id_front':
        $path = $_SESSION['driver_data']['id_front'] ?? '';
        break;
    case 'driver_id_back':
        $path = $_SESSION['driver_data']['id_back'] ?? '';
        break;
    case 'driver_license_front':
        $path = $_SESSION['driver_data']['license_front'] ?? '';
        break;
    case 'driver_license_back':
        $path = $_SESSION['driver_data']['license_back'] ?? '';
        break;
    case 'guarantor_id_front':
        $path = $_SESSION['guarantor_data']['guarantor_id_front'] ?? '';
        break;
    case 'guarantor_id_back':
        $path = $_SESSION['guarantor_data']['guarantor_id_back'] ?? '';
        break;
    default:
        http_response_code(400);
        echo 'Invalid type';
        exit;
}

if (!$path || !is_file($path) || !file_exists($path)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$mime = mime_content_type($path);
if (strpos($mime, 'image/') !== 0) {
    $mime = 'image/jpeg';
}
header('Content-Type: ' . $mime);
readfile($path);