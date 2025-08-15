<?php
declare(strict_types=1);
session_start();
require_once '../connect.php';

/*
 * refund_receipt.php
 * Streams a stored refund receipt PDF to the browser.
 *
 * Query parameters:
 *   id=REFUND_ID          (required, integer)
 *   download=1            (optional) forces download instead of inline view
 *   debug=1               (admin only) returns JSON metadata instead of PDF
 *
 * Security / Access:
 *   Admin: may view any processed refund's receipt.
 *   Customer: may view only their own processed refund receipt.
 *
 * Responses:
 *   200 PDF (or JSON when debug)
 *   400 Invalid/missing id
 *   401 Not authenticated (if you add auth check here – currently not needed if session always set)
 *   403 Forbidden (not owner & not admin)
 *   404 Not found / No receipt
 *   409 Refund not processed
 *   500 Internal error (rare)
 */

$isAdmin = !empty($_SESSION['admin_id']);
$custId  = $_SESSION['cust_id'] ?? null;

$refund_id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($refund_id < 1) {
    http_response_code(400);
    echo 'Invalid id';
    exit;
}

$debug = $isAdmin && isset($_GET['debug']);

$sql = "SELECT refund_id,
               cust_id,
               reference_code,
               refund_status,
               refund_receipt_mime,
               refund_receipt_blob,
               refund_receipt_uploaded_at,
               processed_at
          FROM refunds
         WHERE refund_id = ?
         LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo 'Prepare failed';
    exit;
}
$stmt->bind_param('i', $refund_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo 'Refund not found';
    exit;
}

$ownerCust = (int)$row['cust_id'];

// Authorization
if (!$isAdmin && $ownerCust !== (int)($custId ?? 0)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Must be processed
if ($row['refund_status'] !== 'processed') {
    http_response_code(409);
    echo 'Refund not processed';
    exit;
}

// Blob present?
$blob = $row['refund_receipt_blob'] ?? null;
if ($blob === null || $blob === '') {
    http_response_code(404);
    echo 'No receipt';
    exit;
}

// Debug mode (no binary output)
if ($debug) {
    $firstBytes = substr($blob, 0, 8);
    $isBase64 = false;
    // Heuristic: if it looks like base64 and decodes to %PDF
    if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', substr($blob, 0, 100)) && !str_contains($blob, '%PDF-')) {
        $test = base64_decode($blob, true);
        if ($test !== false && str_starts_with($test, '%PDF-')) {
            $isBase64 = true;
        }
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'refund_id'    => $row['refund_id'],
        'reference'    => $row['reference_code'],
        'status'       => $row['refund_status'],
        'processed_at' => $row['processed_at'],
        'uploaded_at'  => $row['refund_receipt_uploaded_at'],
        'blob_length'  => strlen($blob),
        'stored_mime'  => $row['refund_receipt_mime'],
        'first8'       => bin2hex($firstBytes),
        'looks_base64' => $isBase64
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/* Base64 recovery (only if previous code accidentally stored base64 string).
 * Uncomment if you suspect some old rows are base64 encoded.
 *
 * if (!str_starts_with($blob, '%PDF-') && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $blob)) {
 *     $decoded = base64_decode($blob, true);
 *     if ($decoded !== false && str_starts_with($decoded, '%PDF-')) {
 *         $blob = $decoded;
 *     }
 * }
 */

// Sanitize filename
$refCode = $row['reference_code'] ?: ('refund_'.$refund_id);
$filenameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $refCode);
if ($filenameBase === '') {
    $filenameBase = 'refund_'.$refund_id;
}
$filename = $filenameBase . '.pdf';

// Force PDF mime unless you store other types (we trust only known safe types)
$storedMime = strtolower(trim((string)$row['refund_receipt_mime']));
$allowedMimes = ['application/pdf']; // extend if needed
$mime = in_array($storedMime, $allowedMimes, true) ? $storedMime : 'application/pdf';

$download = isset($_GET['download']) && $_GET['download'] === '1';

// Clear output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Disable compression (helps accurate Content-Length)
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', '0');
}

// ETag / Last-Modified (allows browser conditional requests without caching sensitive content)
$etag = '"' . sha1($refund_id . ':' . $row['refund_receipt_uploaded_at'] . ':' . strlen($blob)) . '"';
$lastModified = gmdate('D, d M Y H:i:s', strtotime($row['refund_receipt_uploaded_at'] ?? $row['processed_at'] ?? 'now')) . ' GMT';

// Basic conditional GET support (not strictly required)
if (
    (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
    (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= strtotime($lastModified))
) {
    header('HTTP/1.1 304 Not Modified');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    exit;
}

/* Range support (optional)
 * If you want to allow partial content (resume), uncomment and implement.
 * For most admin PDFs, not necessary.
 */

// Headers
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($blob));
header('Accept-Ranges: none');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Referrer-Policy: no-referrer");
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);

// Stream (for moderate blob sizes echo is fine; for huge, consider chunking)
echo $blob;
exit;