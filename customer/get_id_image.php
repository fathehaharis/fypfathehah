<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../connect.php';

$type   = $_GET['type']    ?? '';
$custId = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;

$map = [
    'front'          => 'id_front_image',
    'back'           => 'id_back_image',
    'license_front'  => 'license_front_image',
    'license_back'   => 'license_back_image',
];

if ($custId <= 0 || !isset($map[$type])) {
    http_response_code(400);
    exit;
}

$isAdmin    = !empty($_SESSION['admin_id']);
$isCustomer = !empty($_SESSION['cust_id']) && (int)$_SESSION['cust_id'] === $custId;
if (!$isAdmin && !$isCustomer) {
    http_response_code(403);
    outputPlaceholder();
    exit;
}

$col = $map[$type];
$stmt = $conn->prepare("SELECT $col FROM customer WHERE cust_id=? LIMIT 1");
$stmt->bind_param('i', $custId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    $stmt->close();
    outputPlaceholder();
    exit;
}
$stmt->bind_result($blob);
$stmt->fetch();
$stmt->close();

if ($blob === null || $blob === '') {
    outputPlaceholder();
    exit;
}

$mime = detectMimeFromBinary($blob);
$etag = '"' . md5($custId . '|' . $type . '|' . strlen($blob) . '|' . substr($blob,0,64)) . '"';

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    header('HTTP/1.1 304 Not Modified');
    exit;
}

header('Cache-Control: private, max-age=0, must-revalidate');
header('ETag: ' . $etag);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($blob));
echo $blob;
exit;

function outputPlaceholder(): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    echo $png;
}

function detectMimeFromBinary(string $b): string {
    if (str_starts_with($b, "\xFF\xD8")) return 'image/jpeg';
    if (str_starts_with($b, "\x89PNG"))  return 'image/png';
    if (str_starts_with($b, "GIF87a") || str_starts_with($b, "GIF89a")) return 'image/gif';
    if (str_starts_with($b, "RIFF") && substr($b,8,4)==='WEBP') return 'image/webp';
    if (str_starts_with($b, "BM")) return 'image/bmp';
    $p = ltrim(substr($b,0,200));
    if (stripos($p,'<svg') !== false) return 'image/svg+xml';
    return 'application/octet-stream';
}