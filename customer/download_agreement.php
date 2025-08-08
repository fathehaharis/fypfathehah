<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['cust_id'])) {
    http_response_code(401);
    echo "Not authenticated.";
    exit;
}
$cust_id = (int)$_SESSION['cust_id'];

require_once '../connect.php';

$agreement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($agreement_id <= 0) {
    http_response_code(400);
    echo "Missing or invalid agreement ID.";
    exit;
}

$sql = "SELECT agreement_file_path, cust_id FROM agreement_form WHERE agreement_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "DB prepare failed.";
    exit;
}
$stmt->bind_param("i", $agreement_id);
$stmt->execute();
$stmt->bind_result($pdf_blob, $owner_cust_id);
if (!$stmt->fetch()) {
    $stmt->close();
    http_response_code(404);
    echo "Agreement not found.";
    exit;
}
$stmt->close();

if ((int)$owner_cust_id !== $cust_id) {
    http_response_code(403);
    echo "Forbidden.";
    exit;
}
if ($pdf_blob === null || $pdf_blob === '') {
    http_response_code(404);
    echo "No PDF stored.";
    exit;
}

$download = (!empty($_GET['download']) && $_GET['download'] === '1');
$length   = mb_strlen($pdf_blob, '8bit');
header('Content-Type: application/pdf');
header('Content-Disposition: '.($download?'attachment':'inline').'; filename="agreement_'.$agreement_id.'.pdf"');
header('Content-Length: '.$length);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $pdf_blob;
exit;