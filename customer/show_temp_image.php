<?php
session_start();

$type = $_GET['type'] ?? '';
$allowed_types = [
    'driver_id_front',
    'driver_id_back',
    'driver_license_front',
    'driver_license_back',
    'guarantor_id_front',
    'guarantor_id_back'
];

if (!in_array($type, $allowed_types)) {
    http_response_code(404);
    exit('Invalid image type');
}

// Map type to session key
$session_map = [
    'driver_id_front' => $_SESSION['driver_data']['id_front'] ?? null,
    'driver_id_back' => $_SESSION['driver_data']['id_back'] ?? null,
    'driver_license_front' => $_SESSION['driver_data']['license_front'] ?? null,
    'driver_license_back' => $_SESSION['driver_data']['license_back'] ?? null,
    'guarantor_id_front' => $_SESSION['guarantor_data']['guarantor_id_front'] ?? null,
    'guarantor_id_back' => $_SESSION['guarantor_data']['guarantor_id_back'] ?? null
];

$image_path = $session_map[$type] ?? null;

if ($image_path && file_exists($image_path)) {
    $info = getimagesize($image_path);
    if ($info) {
        header('Content-Type: ' . $info['mime']);
    } else {
        header('Content-Type: image/jpeg');
    }
    readfile($image_path);
    exit;
} else {
    http_response_code(404);
    exit('Image not found');
}
?>