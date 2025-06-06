<?php
include '../connect.php';

$agreement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$agreement_id) {
    http_response_code(400);
    echo "Missing agreement ID.";
    exit;
}

$stmt = $conn->prepare("SELECT agreement_file_path FROM agreement_form WHERE agreement_id = ?");
$stmt->bind_param("i", $agreement_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    http_response_code(404);
    echo "Agreement not found.";
    exit;
}

$stmt->bind_result($pdf_blob);
$stmt->fetch();
$stmt->close();

if (!$pdf_blob) {
    http_response_code(404);
    echo "Agreement PDF not found in database.";
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="agreement.pdf"');
header('Content-Length: ' . strlen($pdf_blob));
echo $pdf_blob;
exit;
?>