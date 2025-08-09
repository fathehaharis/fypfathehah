<?php
// Streams a car image blob by car_image_id (primary use in listing)
// URL: car_thumb.php?id=123&v=images_version (v used only for cache busting)
// SECURITY: optional check admin session if backend only. If customers need thumbnails, remove admin gate but add auth logic.

require_once '../connect.php';
session_start();

// If thumbnails should only show to admins, keep this:
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$imageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($imageId <= 0) {
    http_response_code(400);
    exit('Bad id');
}

$stmt = $conn->prepare("SELECT image_blob FROM car_image WHERE car_image_id=? LIMIT 1");
$stmt->bind_param("i", $imageId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    // Tiny 1x1 transparent GIF fallback
    header("Content-Type: image/gif");
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    exit;
}

// Basic content type assumption. If you stored original mime, use it.
$blob = $row['image_blob'];
if (!$blob) {
    header("Content-Type: image/gif");
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    exit;
}

// Simple heuristic: Check first 2 bytes for JPEG/PNG
if (strncmp($blob, "\xFF\xD8", 2) === 0) {
    header("Content-Type: image/jpeg");
} elseif (strncmp($blob, "\x89\x50", 2) === 0) {
    header("Content-Type: image/png");
} else {
    header("Content-Type: application/octet-stream");
}

header("Cache-Control: public, max-age=3600");
if (isset($_GET['v'])) {
    // Weak ETag using version
    header('ETag: "carimg-'.$imageId.'-v'.preg_replace('/[^0-9]/','',$_GET['v']).'"');
}
echo $blob;