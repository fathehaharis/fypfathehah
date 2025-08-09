<?php
/*
 * download_doc.php (Analyzer-friendly updated version)
 *
 * Supports both blob columns (car_*_blob) and path columns (car_*_path).
 * Safe handling, explicit null checks to satisfy static analyzers.
 */

require_once '../connect.php';

/* ---------- Helper Polyfills / Utilities ---------- */
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        $len = strlen($needle);
        if ($len === 0) return true;
        return substr($haystack, -$len) === $needle;
    }
}

/**
 * Detect MIME from first bytes of a string (binary).
 */
function detect_mime_from_bytes(string $data): string {
    if (strncmp($data, "%PDF", 4) === 0) return 'application/pdf';
    if (strncmp($data, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($data, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) return 'image/png';
    if (strncmp($data, "GIF87a", 6) === 0 || strncmp($data, "GIF89a", 6) === 0) return 'image/gif';
    if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') return 'image/webp';
    return 'application/octet-stream';
}

/**
 * Detect MIME from a file path. Returns a string always.
 */
function detect_mime_from_path(string $path): string {
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f) {
            $m = finfo_file($f, $path);
            finfo_close($f);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }
    }
    $data = @file_get_contents($path, false, null, 0, 64);
    if (is_string($data) && $data !== '') {
        return detect_mime_from_bytes($data);
    }
    return 'application/octet-stream';
}

/* ---------- Input Validation ---------- */
if (!isset($_GET['car_id'], $_GET['field'])) {
    http_response_code(400);
    exit('Bad request.');
}

$car_id_raw = $_GET['car_id'];
if (!ctype_digit($car_id_raw)) {
    http_response_code(400);
    exit('Invalid car_id.');
}
$car_id = (int)$car_id_raw;

$field_raw = strtolower(trim((string)$_GET['field']));
$name_raw  = isset($_GET['name']) ? (string)$_GET['name'] : '';

$logicalMap = [
    'car_grant'         => ['car_grant_blob', 'car_grant_path'],
    'car_roadtax'       => ['car_roadtax_blob', 'car_roadtax_path'],
    'car_covernote'     => ['car_covernote_blob', 'car_covernote_path'],
    // Allow direct column references:
    'car_grant_blob'     => ['car_grant_blob'],
    'car_grant_path'     => ['car_grant_path'],
    'car_roadtax_blob'   => ['car_roadtax_blob'],
    'car_roadtax_path'   => ['car_roadtax_path'],
    'car_covernote_blob' => ['car_covernote_blob'],
    'car_covernote_path' => ['car_covernote_path'],
];

if (!isset($logicalMap[$field_raw])) {
    http_response_code(400);
    exit('Field not allowed.');
}

$candidates = $logicalMap[$field_raw];

/* ---------- Column Discovery ---------- */
$availableColumns = [];
$colResult = $conn->query("SHOW COLUMNS FROM `car`");
if ($colResult instanceof mysqli_result) {
    while ($col = $colResult->fetch_assoc()) {
        $availableColumns[$col['Field']] = true;
    }
    $colResult->free();
}

$chosenColumn = null;
foreach ($candidates as $cand) {
    if (isset($availableColumns[$cand])) {
        $chosenColumn = $cand;
        break;
    }
}

if ($chosenColumn === null) {
    http_response_code(500);
    exit('Configured column not found.');
}

/* ---------- Fetch Raw Value ---------- */
$stmt = $conn->prepare("SELECT `$chosenColumn` FROM `car` WHERE car_id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('DB prepare failed.');
}
$stmt->bind_param('i', $car_id);
$stmt->execute();
$stmt->bind_result($rawValue); // $rawValue can be string|null
$stmt->fetch();
$stmt->close();

if (!is_string($rawValue) || $rawValue === '') {
    http_response_code(404);
    exit('File not found.');
}

/* ---------- Determine Storage Type Safely ---------- */
$isBlob = str_ends_with($chosenColumn, '_blob');
$isPath = str_ends_with($chosenColumn, '_path');

if (!$isBlob && !$isPath) {
    http_response_code(500);
    exit('Unsupported storage mode.');
}

/* Initialize container variables for analyzer clarity */
$fileData = '';      // string for blob data
$filePath = '';      // string for path
$mime     = 'application/octet-stream';

if ($isBlob) {
    // $rawValue is the binary data (string)
    $fileData = $rawValue;
    $mime = detect_mime_from_bytes($fileData);
} else {
    // $rawValue is a file path
    $filePathCandidate = $rawValue;

    // Base directory: adjust if you store elsewhere
    $allowedBaseDir = realpath(dirname(__DIR__) . '/uploads');
    if ($allowedBaseDir === false || !is_dir($allowedBaseDir)) {
        $allowedBaseDir = realpath(dirname(__DIR__, 1)); // fallback to project root
    }
    if ($allowedBaseDir === false) {
        http_response_code(500);
        exit('Storage base not resolved.');
    }

    $resolved = realpath($filePathCandidate);
    if ($resolved === false || !is_file($resolved)) {
        http_response_code(404);
        exit('File missing.');
    }
    // Containment check (prevents traversal)
    if (strpos($resolved, $allowedBaseDir) !== 0) {
        http_response_code(403);
        exit('Access denied.');
    }
    $filePath = $resolved;
    $mime = detect_mime_from_path($filePath);
}

/* ---------- Extension Guess ---------- */
$ext = '';
switch ($mime) {
    case 'application/pdf': $ext = '.pdf'; break;
    case 'image/jpeg':      $ext = '.jpg'; break;
    case 'image/png':       $ext = '.png'; break;
    case 'image/gif':       $ext = '.gif'; break;
    case 'image/webp':      $ext = '.webp'; break;
}

/* ---------- Filename Sanitization ---------- */
$baseName = $name_raw !== '' ? $name_raw : $field_raw;
$baseName = preg_replace('/[^a-z0-9\.\-_]+/i', '_', $baseName);
$baseName = trim($baseName, '._');
if ($baseName === '') $baseName = 'document';
$filename = $baseName . $ext;

$downloadForced = isset($_GET['download']) && $_GET['download'] === '1';
$inlineTypes = ['application/pdf','image/jpeg','image/png','image/gif','image/webp'];
$disposition = (!$downloadForced && in_array($mime, $inlineTypes, true)) ? 'inline' : 'attachment';

/* ---------- Output (no null strings) ---------- */
if (ob_get_level()) {
    @ob_end_clean();
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Cache-Control: private, max-age=3600, must-revalidate');
header('Pragma: public');
header('Expires: ' . gmdate('D, d M Y H:i:s', time()+3600) . ' GMT');

if ($isBlob) {
    // Safe: $fileData is a string
    header('Content-Length: ' . strlen($fileData));
    echo $fileData;
} else {
    // Safe: $filePath is a valid file path string
    $size = filesize($filePath);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    $fp = fopen($filePath, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit('Read error.');
    }
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) break;
        echo $chunk;
    }
    fclose($fp);
}

exit;
?>