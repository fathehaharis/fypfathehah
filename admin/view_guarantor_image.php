<?php
// view_guarantor_image.php
// Usage: view_guarantor_image.php?type=front&driver_id=31

include '../connect.php';

// Optional: Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_GET['type'], $_GET['driver_id'])) {
    http_response_code(400);
    exit('Missing parameters.');
}

$type = $_GET['type'] === 'back' ? 'id_back_image' : 'id_front_image';
$driver_id = intval($_GET['driver_id']);

$stmt = $conn->prepare("SELECT $type FROM guarantor WHERE driver_id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('Database error: ' . $conn->error);
}
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->bind_result($image);
    $stmt->fetch();

    if ($image) {
        // If fileinfo is available, use it. Otherwise, fallback to JPEG.
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($image);
            if (!$mime) $mime = 'image/jpeg';
            header("Content-Type: $mime");
        } else {
            header("Content-Type: image/jpeg");
        }
        echo $image;
        exit;
    } else {
        http_response_code(404);
        exit('Image not found in database.');
    }
} else {
    http_response_code(404);
    exit('Guarantor not found.');
}
?>