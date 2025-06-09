<?php
// Enable error reporting (remove or adjust for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (
    !isset($_SESSION['cust_id']) ||
    !isset($_SESSION['booking_data']) ||
    !isset($_SESSION['driver_data']) ||
    !isset($_SESSION['guarantor_data']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_POST['agree']) ||
    empty($_POST['signature_data'])
) {
    header("Location: /index.php");
    exit;
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/vendor/tecnickcom/tcpdf/tcpdf.php');
include '../connect.php';
include '../includes/header.php';

// Helper: save uploaded blob to temp file and return path
function blob_to_tempfile($blob, $prefix) {
    if (!$blob) return null;
    $tmpfname = tempnam(sys_get_temp_dir(), $prefix);
    file_put_contents($tmpfname, $blob);
    return $tmpfname;
}

// 1. Gather all session & form data
$cust_id = $_SESSION['cust_id'];
$booking = $_SESSION['booking_data'];
$driver = $_SESSION['driver_data'];
$guarantor = $_SESSION['guarantor_data'];

$car_id = $booking['car_id'];
$pickup_datetime = $booking['pickup_datetime'];
$return_datetime = $booking['return_datetime'];
$delivery_type = $booking['delivery_type'];

// 2. Fetch both daily_rate and hourly_rate from car
$stmt_car = $conn->prepare("SELECT daily_rate, hourly_rate FROM car WHERE car_id = ?");
$stmt_car->bind_param("i", $car_id);
$stmt_car->execute();
$result_car = $stmt_car->get_result();
$car_row = $result_car->fetch_assoc();
$daily_rate = $car_row['daily_rate'] ?? 0;
$hourly_rate = $car_row['hourly_rate'] ?? 0;
$stmt_car->close();

// 3. Calculate mixed daily + hourly duration
$pickup = new DateTime($pickup_datetime);
$return = new DateTime($return_datetime);
$interval = $pickup->diff($return);
$total_hours = ($interval->days * 24) + $interval->h + ($interval->i > 0 ? 1 : 0);

$day_count = floor($total_hours / 24);
$hour_count = $total_hours % 24;

$subtotal = ($day_count * $daily_rate) + ($hour_count * $hourly_rate);

// 4. Delivery fee & total
$delivery_fee = 0;
if ($delivery_type !== "self_pickup") {
    $delivery_fee = ($delivery_type === "delivery") ? 10.00 : 30.00;
}
$total_price = $subtotal + $delivery_fee;
$status = 'pending';

// 5. Insert driver info into driver table, get driver_id
$id_front_blob = isset($driver['driver_id_front']) && !empty($driver['driver_id_front']) && file_exists($driver['driver_id_front']) 
    ? file_get_contents($driver['driver_id_front'])
    : null;
$id_back_blob = isset($driver['driver_id_back']) && !empty($driver['driver_id_back']) && file_exists($driver['driver_id_back']) 
    ? file_get_contents($driver['driver_id_back'])
    : null;

$stmt_driver = $conn->prepare("INSERT INTO driver 
    (cust_id, full_name, phone_no, id_no, license_no, id_front_image, id_back_image, address, age) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt_driver->bind_param(
    "isssssssi",
    $cust_id,
    $driver['full_name'],
    $driver['phone_no'],
    $driver['id_no'],
    $driver['license_no'],
    $id_front_blob,
    $id_back_blob,
    $driver['address'],
    $driver['age']
);
if ($id_front_blob !== null) $stmt_driver->send_long_data(4, $id_front_blob);
if ($id_back_blob !== null) $stmt_driver->send_long_data(5, $id_back_blob);
$stmt_driver->execute();
if ($stmt_driver->error) die('Driver insert error: ' . $stmt_driver->error);
$driver_id = $stmt_driver->insert_id;
$stmt_driver->close();

// 6. Insert booking record, referencing driver_id
$stmt = $conn->prepare("INSERT INTO booking
    (cust_id, driver_id, car_id, pickup_datetime, return_datetime, day_count, hour_count, daily_rate, hourly_rate, total_price, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iiissiiddds",
    $cust_id,
    $driver_id,
    $car_id,
    $pickup_datetime,
    $return_datetime,
    $day_count,
    $hour_count,
    $daily_rate,
    $hourly_rate,
    $total_price,
    $status
);
$stmt->execute();
if ($stmt->error) die('Booking insert error: ' . $stmt->error);
$booking_id = $stmt->insert_id;
$stmt->close();

// 7. Insert service (delivery/pickup info)
if ($delivery_type !== "self_pickup") {
    $fee = ($delivery_type === "delivery") ? 10.00 : 30.00;
    $notes = $booking['notes'] ?? null;
    $stmt2 = $conn->prepare("INSERT INTO service (booking_id, service_type, fee, notes) VALUES (?, ?, ?, ?)");
    $stmt2->bind_param("isds", $booking_id, $delivery_type, $fee, $notes);
    $stmt2->execute();
    if ($stmt2->error) {
        die("Service insert error: " . $stmt2->error);
    }
    $stmt2->close();
}

// 8. Insert guarantor record
$guar_id_front_blob = isset($guarantor['guarantor_id_front']) && !empty($guarantor['guarantor_id_front']) && file_exists($guarantor['guarantor_id_front']) 
    ? file_get_contents($guarantor['guarantor_id_front'])
    : null;
$guar_id_back_blob = isset($guarantor['guarantor_id_back']) && !empty($guarantor['guarantor_id_back']) && file_exists($guarantor['guarantor_id_back']) 
    ? file_get_contents($guarantor['guarantor_id_back'])
    : null;
$stmt4 = $conn->prepare("INSERT INTO guarantor (cust_id, full_name, phone_no, id_no, id_front_image, id_back_image, relationship) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt4->bind_param(
    "issssss",
    $cust_id,
    $guarantor['guarantor_full_name'],
    $guarantor['guarantor_phone_no'],
    $guarantor['guarantor_id_no'],
    $guar_id_front_blob,
    $guar_id_back_blob,
    $guarantor['guarantor_relationship']
);
if ($guar_id_front_blob !== null) $stmt4->send_long_data(4, $guar_id_front_blob);
if ($guar_id_back_blob !== null) $stmt4->send_long_data(5, $guar_id_back_blob);
$stmt4->execute();
if ($stmt4->error) {
    die('Guarantor insert error: ' . $stmt4->error);
}
$guarantor_id = $stmt4->insert_id;
$stmt4->close();

// 9. Handle signature image (from base64)
$signature_data = $_POST['signature_data'];
$signature_binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signature_data));
$signature_dir = '../uploads/signatures/';
$signature_path = $signature_dir . uniqid('cust_sig_') . '.png';
if (!is_dir($signature_dir)) mkdir($signature_dir, 0777, true);
file_put_contents($signature_path, $signature_binary);

// 10. Prepare temp image files for PDF display (from session path or blob)
$driver_id_front_path = !empty($driver['driver_id_front']) && file_exists($driver['driver_id_front'])
    ? $driver['driver_id_front']
    : (isset($id_front_blob) && $id_front_blob ? blob_to_tempfile($id_front_blob, 'drfront_') : null);
$driver_id_back_path = !empty($driver['driver_id_back']) && file_exists($driver['driver_id_back'])
    ? $driver['driver_id_back']
    : (isset($id_back_blob) && $id_back_blob ? blob_to_tempfile($id_back_blob, 'drback_') : null);

$guar_id_front_path = !empty($guarantor['guarantor_id_front']) && file_exists($guarantor['guarantor_id_front'])
    ? $guarantor['guarantor_id_front']
    : (isset($guar_id_front_blob) && $guar_id_front_blob ? blob_to_tempfile($guar_id_front_blob, 'gfront_') : null);
$guar_id_back_path = !empty($guarantor['guarantor_id_back']) && file_exists($guarantor['guarantor_id_back'])
    ? $guarantor['guarantor_id_back']
    : (isset($guar_id_back_blob) && $guar_id_back_blob ? blob_to_tempfile($guar_id_back_blob, 'gback_') : null);

// 11. Generate agreement PDF with TCPDF using DRIVER and GUARANTOR details and images
$agreement_terms = "AGREEMENT OF VEHICLE USAGE BETWEEN BORROWER AND TIMELESS CAR RENTAL\n... (your terms here) ...";

$pdf = new TCPDF();
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 10, "AGREEMENT OF VEHICLE USAGE BETWEEN BORROWER AND TIMELESS CAR RENTAL", 0, 'C');
$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 7, $agreement_terms, 0, 'L');
$pdf->Ln(7);

// Driver section
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 7, "Driver Name: {$driver['full_name']}", 0, 'L');
$pdf->MultiCell(0, 7, "Driver Phone: {$driver['phone_no']}", 0, 'L');
$pdf->MultiCell(0, 7, "Driver ID No: {$driver['id_no']}", 0, 'L');

// Driver ID Images
$pdf->SetFont('helvetica', 'B', 10);
$pdf->MultiCell(0, 7, "Driver ID Images (Front & Back):", 0, 'L');
$pdf->SetFont('helvetica', '', 10);
if (!empty($driver_id_front_path) && file_exists($driver_id_front_path)) {
    $pdf->Image($driver_id_front_path, $pdf->GetX(), $pdf->GetY(), 60, 35, '', '', '', false, 300);
    $pdf->Ln(37);
} else {
    $pdf->MultiCell(0, 7, "Front ID not available.", 0, 'L');
}
if (!empty($driver_id_back_path) && file_exists($driver_id_back_path)) {
    $pdf->Image($driver_id_back_path, $pdf->GetX(), $pdf->GetY(), 60, 35, '', '', '', false, 300);
    $pdf->Ln(37);
} else {
    $pdf->MultiCell(0, 7, "Back ID not available.", 0, 'L');
}

// Guarantor section
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 7, "Guarantor Name: {$guarantor['guarantor_full_name']}", 0, 'L');
$pdf->MultiCell(0, 7, "Guarantor Phone: {$guarantor['guarantor_phone_no']}", 0, 'L');
$pdf->MultiCell(0, 7, "Guarantor ID No: {$guarantor['guarantor_id_no']}", 0, 'L');

// Guarantor ID Images
$pdf->SetFont('helvetica', 'B', 10);
$pdf->MultiCell(0, 7, "Guarantor ID Images (Front & Back):", 0, 'L');
$pdf->SetFont('helvetica', '', 10);
if (!empty($guar_id_front_path) && file_exists($guar_id_front_path)) {
    $pdf->Image($guar_id_front_path, $pdf->GetX(), $pdf->GetY(), 60, 35, '', '', '', false, 300);
    $pdf->Ln(37);
} else {
    $pdf->MultiCell(0, 7, "Front ID not available.", 0, 'L');
}
if (!empty($guar_id_back_path) && file_exists($guar_id_back_path)) {
    $pdf->Image($guar_id_back_path, $pdf->GetX(), $pdf->GetY(), 60, 35, '', '', '', false, 300);
    $pdf->Ln(37);
} else {
    $pdf->MultiCell(0, 7, "Back ID not available.", 0, 'L');
}

// Signature
$pdf->Ln(7);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->MultiCell(0, 7, "Customer Signature:", 0, 'L');
$pdf->Image($signature_path, $pdf->GetX(), $pdf->GetY(), 60, 30, 'PNG');
$pdf->Ln(35);

// Save PDF
$pdf_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/agreements/';
if (!is_dir($pdf_dir)) mkdir($pdf_dir, 0777, true);
$relative_path = '/uploads/agreements/' . uniqid('agreement_') . '.pdf';
$pdf_path = $_SERVER['DOCUMENT_ROOT'] . $relative_path;
$pdf->Output($pdf_path, 'F');
if (!file_exists($pdf_path)) {
    die('PDF was not created: ' . $pdf_path);
}

// 12. Insert into agreement_form (store PDF and signature as LONGBLOB)
$admin_id = null; // NULL at this stage
$agreement_file = file_get_contents($pdf_path);

$stmt5 = $conn->prepare("INSERT INTO agreement_form (booking_id, customer_id, guarantor_id, admin_id, agreement_file_path, cust_signature) VALUES (?, ?, ?, ?, ?, ?)");
$stmt5->bind_param("iiiibb", $booking_id, $cust_id, $guarantor_id, $admin_id, $agreement_file, $signature_binary);
if ($agreement_file !== null) $stmt5->send_long_data(4, $agreement_file);
if ($signature_binary !== null) $stmt5->send_long_data(5, $signature_binary);
$stmt5->execute();
if ($stmt5->error) {
    die('Agreement form insert error: ' . $stmt5->error);
}
$agreement_id = $stmt5->insert_id;
$stmt5->close();

// 13. Unset sessions
unset($_SESSION['booking_data'], $_SESSION['driver_data'], $_SESSION['guarantor_data']);

// 14. Clean up temp image files (avoid deleting if original, only temp files)
if (isset($driver['driver_id_front']) && strpos($driver['driver_id_front'], sys_get_temp_dir()) === 0) @unlink($driver['driver_id_front']);
if (isset($driver['driver_id_back']) && strpos($driver['driver_id_back'], sys_get_temp_dir()) === 0) @unlink($driver['driver_id_back']);
if (isset($guarantor['guarantor_id_front']) && strpos($guarantor['guarantor_id_front'], sys_get_temp_dir()) === 0) @unlink($guarantor['guarantor_id_front']);
if (isset($guarantor['guarantor_id_back']) && strpos($guarantor['guarantor_id_back'], sys_get_temp_dir()) === 0) @unlink($guarantor['guarantor_id_back']);
@unlink($driver_id_front_path);
@unlink($driver_id_back_path);
@unlink($guar_id_front_path);
@unlink($guar_id_back_path);
@unlink($signature_path);
@unlink($pdf_path);
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.confirmation-container {
    max-width: 600px;
    margin: 60px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 36px 42px 30px 42px;
    text-align: center;
}
.next-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    margin-top: 26px;
}
.next-btn:hover {background: #234c96;}
</style>

<div class="confirmation-container">
    <h2>Booking Submitted!</h2>
    <p>Your booking and agreement have been recorded.<br>
    Please proceed to payment to confirm your reservation.</p>
    <form action="payment.php" method="post">
        <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id) ?>">
        <button type="submit" class="next-btn">Proceed to Payment</button>
    </form>
    <p style="margin-top:18px;">
        <a href="download_agreement.php?id=<?= $agreement_id ?>" target="_blank">Download Agreement PDF</a>
    </p>
</div>

<?php include '../includes/footer.php'; ?>