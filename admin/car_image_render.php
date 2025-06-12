<?php
include '../connect.php';
$car_id = $_GET['id'] ?? '';
if ($car_id) {
    $stmt = $conn->prepare("SELECT image_path FROM car_image WHERE car_id = ? ORDER BY uploaded_at DESC, car_image_id DESC LIMIT 1");
    $stmt->bind_param("i", $car_id);
    $stmt->execute();
    $stmt->bind_result($img);
    if ($stmt->fetch() && $img) {
        header("Content-Type: image/jpeg");
        echo $img;
        exit;
    }
}
header("Content-Type: image/png");
readfile("../assets/images/car_default.png");