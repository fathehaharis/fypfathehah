<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['cust_id'])) {
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

$cust_id = (int)$_SESSION['cust_id'];

require_once '../connect.php';

/**
 * Send an error consistently (optionally JSON if client prefers).
 */
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

$agreement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($agreement_id <= 0) {
    send_error(400, 'Missing or invalid agreement ID.');
}

$sql = "SELECT agreement_file_path, cust_id FROM agreement_form WHERE agreement_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    send_error(500, 'Database prepare failed.');
}

if (!$stmt->bind_param("i", $agreement_id)) {
    send_error(500, 'Parameter binding failed.');
}
if (!$stmt->execute()) {
    $stmt->close();
    send_error(500, 'Query execution failed.');
}

$stmt->store_result();
$stmt->bind_result($pdf_blob, $owner_cust_id);

if (!$stmt->fetch()) {
    $stmt->close();
    send_error(404, 'Agreement not found.');
}
$stmt->close();

if ((int)$owner_cust_id !== $cust_id) {
    send_error(403, 'Forbidden.');
}

if ($pdf_blob === null || $pdf_blob === '') {
    send_error(404, 'No PDF stored.');
}

/* (Optional) Additional freshness or anti-CSRF style guard:
   if (empty($_SERVER['HTTP_SEC_FETCH_SITE']) || $_SERVER['HTTP_SEC_FETCH_SITE'] !== 'same-origin') {
       send_error(403, 'Cross-site request blocked.');
   }
*/

$download = (isset($_GET['download']) && $_GET['download'] === '1');
$length   = strlen($pdf_blob); // safe: binary length (strlen handles binary)

/* Security / caching headers */
header('Content-Type: application/pdf');
header('Content-Disposition: '.($download ? 'attachment' : 'inline').'; filename="agreement_'.$agreement_id.'.pdf"');
header('Content-Length: '.$length);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN'); // or DENY if you never embed PDFs in an internal iframe
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; sandbox;");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* Clear any existing output buffer to avoid corrupting the PDF */
while (ob_get_level() > 0) {
    ob_end_clean();
}

echo $pdf_blob;
exit;