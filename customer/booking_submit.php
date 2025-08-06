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

// 5. Security Deposit (fixed at RM100)
$security_deposit = 100.00;

// 6. Grand total includes security deposit
$total_price = $subtotal + $delivery_fee + $security_deposit;
$status = 'waiting_verification';

// 7. Insert driver info into driver table, get driver_id
$id_front_blob = isset($driver['id_front']) && !empty($driver['id_front']) && file_exists($driver['id_front']) 
    ? file_get_contents($driver['id_front'])
    : null;
$id_back_blob = isset($driver['id_back']) && !empty($driver['id_back']) && file_exists($driver['id_back']) 
    ? file_get_contents($driver['id_back'])
    : null;

$stmt_driver = $conn->prepare("INSERT INTO driver 
    (cust_id, full_name, phone_no, id_no, id_front_image, id_back_image, address, age) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt_driver->bind_param(
    "isssssssi",
    $cust_id,
    $driver['full_name'],
    $driver['phone_no'],
    $driver['id_no'],
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

// 8. Insert booking record, referencing driver_id
$stmt = $conn->prepare("INSERT INTO booking
    (cust_id, driver_id, car_id, pickup_datetime, return_datetime, day_count, hour_count, daily_rate, hourly_rate, total_price, security_deposit, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iiissiidddds",
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
    $security_deposit,
    $status
);
$stmt->execute();
if ($stmt->error) die('Booking insert error: ' . $stmt->error);
$booking_id = $stmt->insert_id;
$stmt->close();

// 9. Insert service (delivery/pickup info)
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

// 10. Insert guarantor record
$guar_id_front_blob = isset($guarantor['guarantor_id_front']) && !empty($guarantor['guarantor_id_front']) && file_exists($guarantor['guarantor_id_front']) 
    ? file_get_contents($guarantor['guarantor_id_front'])
    : null;
$guar_id_back_blob = isset($guarantor['guarantor_id_back']) && !empty($guarantor['guarantor_id_back']) && file_exists($guarantor['guarantor_id_back']) 
    ? file_get_contents($guarantor['guarantor_id_back'])
    : null;
$stmt4 = $conn->prepare("INSERT INTO guarantor (driver_id, full_name, phone_no, id_no, id_front_image, id_back_image, relationship) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt4->bind_param(
    "issssss",
    $driver_id,
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

// 11. Handle signature image (from base64)
$signature_data = $_POST['signature_data'];
$signature_binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signature_data));
$signature_dir = '../uploads/signatures/';
$signature_path = $signature_dir . uniqid('cust_sig_') . '.png';
if (!is_dir($signature_dir)) mkdir($signature_dir, 0777, true);
file_put_contents($signature_path, $signature_binary);

// 12. Prepare temp image files for PDF display (from session path or blob)
$driver_id_front_path = !empty($driver['id_front']) && file_exists($driver['id_front'])
    ? $driver['id_front']
    : (isset($id_front_blob) && $id_front_blob ? blob_to_tempfile($id_front_blob, 'drfront_') : null);

$driver_id_back_path = !empty($driver['id_back']) && file_exists($driver['id_back'])
    ? $driver['id_back']
    : (isset($id_back_blob) && $id_back_blob ? blob_to_tempfile($id_back_blob, 'drback_') : null);

$guar_id_front_path = !empty($guarantor['guarantor_id_front']) && file_exists($guarantor['guarantor_id_front'])
    ? $guarantor['guarantor_id_front']
    : (isset($guar_id_front_blob) && $guar_id_front_blob ? blob_to_tempfile($guar_id_front_blob, 'gfront_') : null);
$guar_id_back_path = !empty($guarantor['guarantor_id_back']) && file_exists($guarantor['guarantor_id_back'])
    ? $guarantor['guarantor_id_back']
    : (isset($guar_id_back_blob) && $guar_id_back_blob ? blob_to_tempfile($guar_id_back_blob, 'gback_') : null);

// 13. Generate agreement PDF with TCPDF using DRIVER and GUARANTOR details and images
$agreement_terms = <<<EOT
AGREEMENT OF VEHICLE USAGE BETWEEN BORROWER AND TIMELESS CAR RENTAL

TimeLess Car Rental is a brand operated by TimeLess Car Rental. Attached herewith are the terms and conditions that shall be between the BORROWER of the vehicle and TimeLess Car Rental. When the agreement is signed, the BORROWER has acknowledged that he/she has read, understood and agreed to the terms and conditions.

IT IS HEREBY AGREED AS FOLLOWS:
1. The consolation loan agreed for this vehicle is as per agreed in the quotation. No claim will be made by the BORROWER if the vehicle is returned earlier than the promised expiry date and time.
2. TimeLess Car Rental reserves the right to claim additional consolation if the vehicle is returned late after the expiry of the LOAN as above. For vehicles with a daily rate of less than RM300, the value claimed is RM25/hour. For vehicles with a daily rate of more than RM300, the claimed value is RM60/hour. TimeLess Car Rental also has the right to exercise discretion in determining the level of demand for this clause (2).
3. Any loan extension must be notified to TimeLess Car Rental at least 3 hours in advance, subject to the availability of the vehicle. BORROWER agrees to all terms and conditions agreed in this agreement for the duration of the extension period. Only ONE (1) EXTENSION is allowed. For the next loan extension, the BORROWER must be present at the TimeLess Car Rental office with the vehicle for approval.
4. This vehicle is not used for any kind of criminal activities or offences under the laws of Malaysia.
5. THE BORROWER is fully responsible in the event of misuse of this vehicle such as being involved in any kind of criminal activities or offences under the laws of Malaysia.
6. It is a responsibility for the BORROWERS to pay all summonses, compounds, or fines within the LOAN tenure as above.
7. TimeLess Car Rental reserves the right to claim any compensation in the event of any damage/accident/replacement of spare parts without TimeLess Car Rental's consent involving the vehicle during the LOAN period as above.
8. If the vehicle is not found/lost/severely damaged after the LOAN period of this vehicle as above, the BORROWER is responsible for all costs and claims incurred by TimeLess Car Rental involving this vehicle.
9. TimeLess Car Rental reserves the right to request/keep a copy of the Identity Card/Driver's License/Student Card/Employee Card or any supporting documents of this vehicle BORROWER/GUARANTOR for the purpose of further action related to all processes related to the above vehicle rental.
10. THE BORROWER will be responsible for any issues arising for the duration of the loan if such issues arise after the return of the deposit. TimeLess Car Rental reserves the right to make claims against borrowers to resolve such issues.
11. The vehicle is provided with full fuel. THE BORROWER is responsible for returning the vehicle in a state of full oil. Failure will entitle TimeLess Car Rental to claim RM100 for refuelling and service charges.
12. TimeLess Car Rental reserves the right to contact the Guarantor given by the BORROWER for the purpose of resolving any issues arising in relation to the vehicle loan if the BORROWER is unable to resolve the arising issues. This is in line with the consent that has been given by the GUARANTOR.
13. Only BORROWERs are allowed to drive the above vehicles. Third parties are not allowed to drive the above vehicles.
14. The BORROWER acknowledges that TimeLess Car Rental does not provide any personal accident and damage/loss of property insurance to the BORROWER. The BORROWER is solely responsible for the personal safety and property of the BORROWER.
15. THE BORROWER AND GUARANTOR testified and acknowledge that the TimeLess Car Rental REPRESENTATIVE had shown that each compartment in the parts of the vehicle was empty/did not contain any prohibited goods in violation of Malaysian laws. The BORROWER and GUARANTOR release TimeLess Car Rental and its representatives from any legal claims if any prohibited items are found by the authorities, in the vehicle for the entire period of use of the vehicle by the BORROWER and GUARANTOR.
16. The agreed security deposit is as per agreed in the quotation. The security deposit is taken for wagering purposes for any breach of conditions and/or driving outside the stated destination and/or payment of part of the summons issue by the BORROWER during the loan tenure. The security deposit will be refunded (either in full or the balance after deduction if an issue arises) to the BORROWER on/after FIVE(5) WORKING DAYS after the vehicle has been returned to TimeLess Car Rental.
EOT;

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
$pdf->MultiCell(0, 7, "Driver License Images (Front & Back):", 0, 'L');
$pdf->SetFont('helvetica', '', 10);
if (!empty($driver_id_front_path) && file_exists($driver_id_front_path)) {
    $pdf->Image($driver_id_front_path, $pdf->GetX(), $pdf->GetY(), 60, 35, '', '', '', false, 300);
    $pdf->Ln(37);
} else {
    $pdf->MultiCell(0, 7, "Front License not available.", 0, 'L');
}
if (!empty($driver_id_back_path) && file_exists($driver_id_back_path)) {
    $pdf->Image($driver_id_back_path, $pdf->GetX(), $pdf->GetY(), 60, 35, '', '', '', false, 300);
    $pdf->Ln(37);
} else {
    $pdf->MultiCell(0, 7, "Back License not available.", 0, 'L');
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
$pdf->MultiCell(0, 7, "Driver Signature:", 0, 'L');
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

// 14. Insert into agreement_form (store PDF and signature as LONGBLOB)
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

// 15. Unset sessions
unset($_SESSION['booking_data'], $_SESSION['driver_data'], $_SESSION['guarantor_data']);

// 16. Clean up temp image files (avoid deleting if original, only temp files)
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
    <table class="review-table" style="margin: 0 auto 18px auto; font-size:1.1em;">
        <tr>
            <th style="text-align:left;">Subtotal</th>
            <td style="text-align:right;">RM <?= number_format($subtotal,2) ?></td>
        </tr>
        <tr>
            <th style="text-align:left;">Delivery Fee</th>
            <td style="text-align:right;">RM <?= number_format($delivery_fee,2) ?></td>
        </tr>
        <tr>
            <th style="text-align:left;">Security Deposit</th>
            <td style="text-align:right;">RM <?= number_format($security_deposit,2) ?></td>
        </tr>
        <tr>
            <th style="text-align:left;">Total Amount</th>
            <td style="text-align:right; font-weight:bold; color:#203090;">RM <?= number_format($total_price,2) ?></td>
        </tr>
    </table>
    <p style="margin-top:18px; color:#3c4cb8;">
        Your booking is pending admin approval.<br>
        You will be notified when it is approved and can proceed to payment at that time.
    </p>
    <p style="margin-top:18px;">
        <a href="download_agreement.php?id=<?= $agreement_id ?>" target="_blank">Download Agreement PDF</a>
    </p>
</div>

<?php include '../includes/footer.php'; ?>