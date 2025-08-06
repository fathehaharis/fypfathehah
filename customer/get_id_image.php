<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../connect.php';

if (!isset($_GET['cust_id']) || !isset($_GET['type'])) {
    http_response_code(400);
    exit("Missing parameters.");
}

$cust_id = intval($_GET['cust_id']);
$type_param = $_GET['type'];

switch ($type_param) {
    case 'back':
        $column = 'id_back_image';
        break;
    case 'front':
        $column = 'id_front_image';
        break;
    case 'license_front':
        $column = 'license_front_image';
        break;
    case 'license_back':
        $column = 'license_back_image';
        break;
    default:
        http_response_code(400);
        exit("Invalid type.");
}

$stmt = $conn->prepare("SELECT $column FROM customer WHERE cust_id = ?");
if (!$stmt) {
    http_response_code(500);
    exit("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->store_result();
$stmt->bind_result($img);

if ($stmt->num_rows > 0 && $stmt->fetch() && !empty($img)) {
    // Detect content type by magic bytes
    if (substr($img, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
        header("Content-Type: image/png");
    } else if (substr($img, 0, 3) === "\xFF\xD8\xFF") {
        header("Content-Type: image/jpeg");
    } else {
        header("Content-Type: application/octet-stream");
    }
    header("Content-Length: " . strlen($img));
    echo $img;
} else {
    http_response_code(404);
    exit("No image found or image is empty");
}

$stmt->close();
?>