<?php
declare(strict_types=1);
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../connect.php';

if (empty($_SESSION['cust_id']) && empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo "Not authenticated.";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo "Method not allowed.";
    exit;
}

function send_error(int $code, string $message): void {
    http_response_code($code);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}

$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
if ($payment_id <= 0) {
    send_error(400, 'Missing or invalid payment ID.');
}

$sql = "SELECT p.receipt_pdf, b.cust_id
        FROM payment p
        JOIN booking b ON p.booking_id = b.booking_id
        WHERE p.payment_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) send_error(500, 'Database prepare failed.');
if (!$stmt->bind_param("i", $payment_id)) send_error(500, 'Parameter binding failed.');
if (!$stmt->execute()) {
    $stmt->close();
    send_error(500, 'Query execution failed.');
}

$stmt->store_result();
$stmt->bind_result($pdf_blob, $owner_cust_id);

if (!$stmt->fetch()) {
    $stmt->close();
    send_error(404, 'Receipt not found.');
}
$stmt->close();

// Only allow the customer who paid or an admin
if (empty($_SESSION['admin_id']) && (int)$owner_cust_id !== (int)($_SESSION['cust_id'] ?? 0)) {
    send_error(403, 'Forbidden.');
}

if ($pdf_blob === null || $pdf_blob === '') {
    send_error(404, 'No PDF stored for this payment.');
}

$download = (isset($_GET['download']) && $_GET['download'] === '1');
$length   = strlen($pdf_blob);

header('Content-Type: application/pdf');
header('Content-Disposition: '.($download ? 'attachment' : 'inline').'; filename="payment_receipt_'.$payment_id.'.pdf"');
header('Content-Length: '.$length);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; sandbox;");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

while (ob_get_level() > 0) {
    ob_end_clean();
}

echo $pdf_blob;
exit;