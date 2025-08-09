<?php
// Simple image streaming endpoint for thumbnails/full images in details page.
// URL: car_image.php?id=CAR_IMAGE_ID&v=VERSION
// (v is just for cache busting; not strictly required)
require_once '../connect.php';
session_start();
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    exit;
}
$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit;
}
$stmt = $conn->prepare("SELECT image_blob, version FROM car_image WHERE car_image_id=? LIMIT 1");
$stmt->bind_param("i",$id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res?->fetch_assoc();
$stmt->close();
if (!$row) {
    header("Content-Type: image/gif");
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    exit;
}
$data = $row['image_blob'];
// Basic type sniff
if (strncmp($data, "\xFF\xD8", 2) === 0) {
    header("Content-Type: image/jpeg");
} elseif (strncmp($data, "\x89\x50", 2) === 0) {
    header("Content-Type: image/png");
} elseif (strncmp($data, "RI", 2) === 0) { // simplistic check for webp or others not perfect
    header("Content-Type: image/webp");
} else {
    header("Content-Type: application/octet-stream");
}
header("Cache-Control: public, max-age=3600");
if (isset($_GET['v'])) {
    header('ETag: "carimg-'.$id.'-v'.intval($_GET['v']).'"');
}
echo $data;