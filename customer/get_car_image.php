<?php
// Public/customer image endpoint.
// Security considerations:
//  - If you want to hide images of unavailable cars, join to car and check status='available'.
//  - For now, we just serve if the ID exists.

require '../connect.php';

$car_image_id = (int)($_GET['car_image_id'] ?? 0);
if ($car_image_id <= 0) {
    http_response_code(400);
    exit;
}

$sql = "SELECT ci.image_blob
        FROM car_image ci
        WHERE ci.car_image_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $car_image_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    $stmt->close();
    // 1x1 transparent PNG fallback
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADpQG5Cr8L0gAAAABJRU5ErkJggg==');
    exit;
}
$stmt->bind_result($blob);
$stmt->fetch();
$stmt->close();

if (!$blob) {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADpQG5Cr8L0gAAAABJRU5ErkJggg==');
    exit;
}

// Assume JPEG (you can store mime if needed)
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=31536000, immutable');
echo $blob;