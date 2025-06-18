<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

if (!isset($_GET['driver_id']) || !is_numeric($_GET['driver_id'])) {
    http_response_code(400);
    exit('Invalid driver ID.');
}

$driver_id = intval($_GET['driver_id']);
$type = isset($_GET['type']) && $_GET['type'] === 'back' ? 'id_back_image' : 'id_front_image';

// Fetch the image from the database
$stmt = $conn->prepare("SELECT $type FROM driver WHERE driver_id = ?");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    http_response_code(404);
    exit('Image not found.');
}

$stmt->bind_result($img);
$stmt->fetch();
$stmt->close();

if (empty($img)) {
    http_response_code(404);
    exit('Image not found.');
}

// Try to detect image type (fallback to jpeg)
$imageInfo = @getimagesizefromstring($img);
$mime = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';

header("Content-Type: $mime");
echo $img;
exit;
?>