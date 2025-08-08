<?php
session_start();

/*
  Serves only guarantor temporary images stored in $_SESSION['guarantor_data'].
  Customer (driver) images should be fetched from DB via get_id_image.php now.
*/

$type = $_GET['type'] ?? '';

$allowed = [
    'guarantor_id_front' => ['array' => 'guarantor_data', 'key' => 'guarantor_id_front'],
    'guarantor_id_back'  => ['array' => 'guarantor_data', 'key' => 'guarantor_id_back'],
];

if (!isset($allowed[$type])) {
    http_response_code(404);
    exit('Invalid image type');
}

$sessionArrayName = $allowed[$type]['array'];
$sessionKey       = $allowed[$type]['key'];

$sessionArray = $_SESSION[$sessionArrayName] ?? [];
$path = $sessionArray[$sessionKey] ?? '';

if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('Image not found');
}

/* SECURITY CHECK:
   Ensure the file is inside the system temp directory (avoid arbitrary file reads).
*/
$tempDirReal = realpath(sys_get_temp_dir());
$fileReal    = realpath($path);

if ($tempDirReal === false || $fileReal === false || strpos($fileReal, $tempDirReal) !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

/* MIME detection without finfo (since your environment lacks it) */
$mime = 'application/octet-stream';
if (function_exists('exif_imagetype')) {
    $imgType = @exif_imagetype($path);
    $map = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_GIF  => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];
    if ($imgType && isset($map[$imgType])) {
        $mime = $map[$imgType];
    }
} elseif (function_exists('getimagesize')) {
    $info = @getimagesize($path);
    if (!empty($info['mime'])) {
        $mime = $info['mime'];
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($path);