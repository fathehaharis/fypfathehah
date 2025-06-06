<?php
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

require_once('../vendor/tecnickcom/tcpdf/tcpdf.php');
include '../connect.php';
include '../includes/header.php';

// Helper: convert image file to blob (for LONGBLOB fields)
function file_to_blob($path) {
    return ($path && file_exists($path)) ? file_get_contents($path) : null;
}

// 1. Customer (driver) info
$cust_id = $_SESSION['cust_id'];
$booking = $_SESSION['booking_data'];
$driver = $_SESSION['driver_data'];
$guarantor = $_SESSION['guarantor_data'];

$car_id = $booking['car_id'];
$pickup_datetime = $booking['pickup_datetime'];
$return_datetime = $booking['return_datetime'];
$delivery_type = $booking['delivery_type'];
$booking_duration = $booking['booking_duration'] ?? 1;

// Fetch car daily_rate from the database
$stmt_car = $conn->prepare("SELECT daily_rate FROM car WHERE car_id = ?");
$stmt_car->bind_param("i", $car_id);
$stmt_car->execute();
$result_car = $stmt_car->get_result();
$car_row = $result_car->fetch_assoc();
$car_price = $car_row['daily_rate'] ?? 0;

// Calculate delivery fee
$delivery_fee = 0;
if ($delivery_type !== "self_pickup") {
    $delivery_fee = ($delivery_type === "delivery") ? 10.00 : 30.00;
}

// Calculate total price
$total_price = ($car_price * $booking_duration) + $delivery_fee;
$status = 'pending';

// 2. Update customer (driver) info in customer table
$stmt_cust = $conn->prepare("UPDATE customer SET full_name=?, phone_no=?, email=?, id_no=?, license_no=?, passport_no=?, id_front_image=?, id_back_image=?, address=?, country=?, age=? WHERE cust_id=?");
$id_front_blob = file_to_blob($driver['driver_id_front'] ?? null);
$id_back_blob = file_to_blob($driver['driver_id_back'] ?? null);
$stmt_cust->bind_param(
    "ssssssssssii",
    $driver['driver_full_name'],
    $driver['driver_phone_no'],
    $driver['driver_email'],
    $driver['driver_id_no'],
    $driver['driver_license_no'],
    $driver['driver_passport_no'],
    $id_front_blob,
    $id_back_blob,
    $driver['driver_address'],
    $driver['driver_country'],
    $driver['driver_age'],
    $cust_id
);
$stmt_cust->send_long_data(6, $id_front_blob);
$stmt_cust->send_long_data(7, $id_back_blob);
$stmt_cust->execute();

// 3. Insert booking record
$stmt = $conn->prepare("INSERT INTO booking (cust_id, car_id, pickup_datetime, return_datetime, booking_duration, total_price, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iissids", $cust_id, $car_id, $pickup_datetime, $return_datetime, $booking_duration, $total_price, $status);
$stmt->execute();
$booking_id = $stmt->insert_id;

// Insert service (delivery/pickup info) HERE:
if ($delivery_type !== "self_pickup") {
    $fee = ($delivery_type === "delivery") ? 10.00 : 30.00;
    $notes = null; // Or set this to a string if you want to save a note
    $stmt2 = $conn->prepare("INSERT INTO service (booking_id, service_type, fee, notes) VALUES (?, ?, ?, ?)");
    $stmt2->bind_param("isds", $booking_id, $delivery_type, $fee, $notes);
    $stmt2->execute();
    if ($stmt2->error) {
        die("Service insert error: " . $stmt2->error);
    }
}

// 5. Insert guarantor record
$guar_id_front_blob = file_to_blob($guarantor['guarantor_id_front'] ?? null);
$guar_id_back_blob = file_to_blob($guarantor['guarantor_id_back'] ?? null);
$stmt4 = $conn->prepare("INSERT INTO guarantor (cust_id, full_name, phone_no, id_no, id_front_image, id_back_image, relationship) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt4->bind_param(
    "issssss",
    $cust_id,
    $guarantor['full_name'],
    $guarantor['phone_no'],
    $guarantor['id_no'],
    $guar_id_front_blob,
    $guar_id_back_blob,
    $guarantor['relationship']
);
$stmt4->send_long_data(4, $guar_id_front_blob);
$stmt4->send_long_data(5, $guar_id_back_blob);
$stmt4->execute();
$guarantor_id = $stmt4->insert_id;

// 6. Handle signature image (from base64)
$signature_data = $_POST['signature_data'];
$signature_binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signature_data));
$signature_path = '../uploads/signatures/' . uniqid('cust_sig_') . '.png';
if (!is_dir(dirname($signature_path))) mkdir(dirname($signature_path), 0777, true);
file_put_contents($signature_path, $signature_binary);

// 7. Generate agreement PDF with TCPDF
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

// Fetch names for PDF
$stmt_cust_name = $conn->prepare("SELECT full_name FROM customer WHERE cust_id=?");
$stmt_cust_name->bind_param("i", $cust_id);
$stmt_cust_name->execute();
$result_cust = $stmt_cust_name->get_result();
$customer_row = $result_cust->fetch_assoc();
$customer_name = $customer_row['full_name'] ?? '';

$pdf = new TCPDF();
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 10, "AGREEMENT OF VEHICLE USAGE BETWEEN BORROWER AND TIMELESS CAR RENTAL", 0, 'C');
$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 7, $agreement_terms, 0, 'L');
$pdf->Ln(7);
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 7, "Customer Name: {$customer_name}", 0, 'L');
$pdf->MultiCell(0, 7, "Guarantor Name: {$guarantor['full_name']}", 0, 'L');
$pdf->MultiCell(0, 7, "Pickup: $pickup_datetime | Return: $return_datetime", 0, 'L');
$pdf->Ln(7);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->MultiCell(0, 7, "Customer Signature:", 0, 'L');
$pdf->Image($signature_path, $pdf->GetX(), $pdf->GetY(), 60, 30, 'PNG');
$pdf->Ln(35);

$pdf_path = 'C:/xampp/htdocs/fypfathehah/uploads/agreements/' . uniqid('agreement_') . '.pdf';
if (!is_dir(dirname($pdf_path))) mkdir(dirname($pdf_path), 0777, true);
$pdf_dir = dirname($pdf_path);
if (!is_writable($pdf_dir)) {
    die('Directory is not writable: ' . realpath($pdf_dir));
} else {
    // Optional: Show a success message for debugging
    // echo 'Directory is writable: ' . realpath($pdf_dir);
}


$pdf->Output($pdf_path, 'F');

// 8. Insert into agreement_form (store PDF and signature as LONGBLOB)
$admin_id = null; // NULL at this stage
$agreement_file = file_get_contents($pdf_path); // For LONGBLOB field

$stmt5 = $conn->prepare("INSERT INTO agreement_form (booking_id, customer_id, guarantor_id, admin_id, agreement_file_path, cust_signature) VALUES (?, ?, ?, ?, ?, ?)");
$stmt5->send_long_data(4, $agreement_file);
$stmt5->send_long_data(5, $signature_binary);
$stmt5->bind_param("iiiiss", $booking_id, $cust_id, $guarantor_id, $admin_id, $agreement_file, $signature_binary);
$stmt5->execute();

// 9. Unset sessions
unset($_SESSION['booking_data'], $_SESSION['driver_data'], $_SESSION['guarantor_data']);

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
        <a href="<?= htmlspecialchars(str_replace('../', '/', $pdf_path)) ?>" target="_blank">Download Agreement PDF</a>
    </p>
</div>

<?php include '../includes/footer.php'; ?>