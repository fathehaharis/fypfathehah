<?php
include '../connect.php';

$type = $_GET['type'] ?? '';
$value = trim($_GET['value'] ?? '');

if ($type === 'username') {
    $stmt = $conn->prepare("SELECT cust_id FROM customer WHERE username = ?");
} elseif ($type === 'email') {
    $stmt = $conn->prepare("SELECT cust_id FROM customer WHERE email = ?");
} else {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt->bind_param("s", $value);
$stmt->execute();
$stmt->store_result();
$exists = $stmt->num_rows > 0;
$stmt->close();

header('Content-Type: application/json');
echo json_encode(['exists' => $exists]);