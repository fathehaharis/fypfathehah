<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../connect.php';

if (!isset($_GET['car_image_id'])) {
    http_response_code(400);
    exit("No car_image_id provided");
}

$id = intval($_GET['car_image_id']);
$stmt = $conn->prepare("SELECT image_path FROM car_image WHERE car_image_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();
$stmt->bind_result($img);

if ($stmt->num_rows > 0 && $stmt->fetch() && !empty($img)) {
    // Optional: Save to disk for debugging
    // file_put_contents("debug_output_image.bin", $img);

    // Detect content type
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