<?php
include '../connect.php';

if (!isset($_GET['car_id']) || !isset($_GET['field']) || !isset($_GET['name'])) exit('Invalid request.');
$car_id = intval($_GET['car_id']);
$field = $_GET['field'];
$name = preg_replace('/[^a-z0-9\.\-_]/i', '_', $_GET['name']);

$allowed_fields = ['car_grant_path', 'car_roadtax_path', 'car_covernote_path'];
if (!in_array($field, $allowed_fields)) exit('Not allowed.');

$stmt = $conn->prepare("SELECT `$field` FROM car WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$stmt->bind_result($data);
$stmt->fetch();
$stmt->close();

if (!$data) exit('File not found.');

// Guess mime type by magic bytes
function detectMimeType($data) {
    if (substr($data, 0, 4) === "%PDF") return "application/pdf";
    if (substr($data, 0, 2) === "\xFF\xD8") return "image/jpeg";
    if (substr($data, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return "image/png";
    if (substr($data, 0, 6) === "GIF87a" || substr($data, 0, 6) === "GIF89a") return "image/gif";
    return "application/octet-stream";
}
$type = detectMimeType($data);

// Guess extension (optional)
$ext = '';
if ($type === 'application/pdf') $ext = '.pdf';
elseif ($type === 'image/jpeg') $ext = '.jpg';
elseif ($type === 'image/png') $ext = '.png';
elseif ($type === 'image/gif') $ext = '.gif';

header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="' . $name . $ext . '"');
header('Content-Length: ' . strlen($data));
echo $data;
exit;
?>