<?php
/*
  booking_submit.php
  Creates booking + guarantor + (optional) service + full agreement PDF (with images & signature) stored as LONGBLOB.
  (Admin signature placeholder removed as requested.)
*/

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['cust_id'])
    || empty($_SESSION['booking_data'])
    || empty($_SESSION['guarantor_data'])
    || empty($_POST['agree'])
    || empty($_POST['signature_data'])
) {
    header("Location: /index.php");
    exit;
}

$cust_id     = (int)$_SESSION['cust_id'];
$booking     = $_SESSION['booking_data'];
$guarSession = $_SESSION['guarantor_data'];

/* Normalize legacy keys */
if (empty($booking['delivery_location']) && !empty($booking['location_delivery'])) {
    $booking['delivery_location'] = $booking['location_delivery'];
}
if (empty($booking['return_location']) && !empty($booking['location_return'])) {
    $booking['return_location'] = $booking['location_return'];
}

$car_id            = (int)($booking['car_id'] ?? 0);
$pickup_datetime   = $booking['pickup_datetime'] ?? '';
$return_datetime   = $booking['return_datetime'] ?? '';
$delivery_type     = $booking['delivery_type'] ?? 'self_pickup';
$delivery_location = trim($booking['delivery_location'] ?? '');
$return_location   = trim($booking['return_location'] ?? '');

include '../connect.php';

/* Car info */
if ($car_id <= 0) die("Invalid car id.");
$stmt = $conn->prepare("SELECT car_brand, car_model, daily_rate FROM car WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$car) die("Car not found.");
$daily_rate = (float)$car['daily_rate'];

/* Duration */
try {
    $pickupDT = new DateTime($pickup_datetime);
    $returnDT = new DateTime($return_datetime);
} catch(Throwable $e) {
    die("Invalid pickup/return datetime.");
}
if ($returnDT <= $pickupDT) die("Return must be after pickup.");

$days = isset($booking['booking_duration'])
    ? (int)$booking['booking_duration']
    : max(1, (int)$pickupDT->diff($returnDT)->days);
if ($days < 1) $days = 1;

/* Financials (provisional) */
$rental_subtotal = isset($booking['rental_subtotal'])
    ? (float)$booking['rental_subtotal']
    : $daily_rate * $days;

$security_deposit = isset($booking['security_deposit'])
    ? (float)$booking['security_deposit']
    : 100.00;

$provisional_total = isset($booking['provisional_total'])
    ? (float)$booking['provisional_total']
    : ($rental_subtotal + $security_deposit);

$total_price = $provisional_total; // stored now (no delivery fee yet)
$status = 'pending';

/* Guarantor image blobs from temp paths */
function readTempFile(?string $path): ?string {
    return ($path && file_exists($path)) ? file_get_contents($path) : null;
}
$g_front_blob = readTempFile($guarSession['guarantor_id_front'] ?? '');
$g_back_blob  = readTempFile($guarSession['guarantor_id_back'] ?? '');

/* Signature */
$signature_data   = $_POST['signature_data'];
$signature_binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i','',$signature_data));
if (!$signature_binary) die("Invalid signature data.");

/* Fetch full customer detail (including images) */
$custStmt = $conn->prepare("
    SELECT full_name, phone_no, email, id_no, address, age,
           id_front_image, id_back_image,
           license_front_image, license_back_image
    FROM customer
    WHERE cust_id = ?
    LIMIT 1
");
$custStmt->bind_param("i", $cust_id);
$custStmt->execute();
$custStmt->bind_result(
    $c_full_name, $c_phone, $c_email, $c_id_no, $c_address, $c_age,
    $c_id_front_img, $c_id_back_img, $c_license_front_img, $c_license_back_img
);
$custStmt->fetch();
$custStmt->close();

/* Build full PDF (store as LONGBLOB) */
$pdf_binary = null;
$terms_text = <<<EOT
AGREEMENT OF VEHICLE USAGE BETWEEN BORROWER AND TIMELESS CAR RENTAL

By signing, the BORROWER acknowledges reading, understanding and agreeing:

1. No refund for early return.
2. Late return extra charges (RM25/hr if daily rate < RM300; RM60/hr if >= RM300) possible.
3. One extension only; further requires inspection/approval.
4. No illegal use.
5. Borrower responsible for misuse/offences.
6. Borrower settles all summons/fines.
7. Damages / unauthorized repairs claimable.
8. Loss/severe damage costs borne by Borrower.
9. Company may retain copies of IDs/documents.
10. Borrower still liable for issues originating during rental discovered later.
11. Return with full fuel (refueling/service charge may apply).
12. Company may contact Guarantor if Borrower unresponsive.
13. Only the Borrower may drive the vehicle.
14. No personal accident/property insurance provided by company.
15. Compartments shown empty of prohibited items at handover.
16. Security deposit refundable (less deductions) within 5 working days after return.

Delivery Fee (if service selected) is not yet included unless admin already added it.
EOT;

$tcpdf_path = $_SERVER['DOCUMENT_ROOT'].'/vendor/tecnickcom/tcpdf/tcpdf.php';
if (file_exists($tcpdf_path)) {
    require_once $tcpdf_path;

    function blobToTempImg(?string $blob, string $prefix='img_'): ?string {
        if (!$blob) return null;
        $info = @getimagesizefromstring($blob);
        if (!$info) return null;
        $ext = match($info['mime'] ?? '') {
            'image/jpeg' => '.jpg',
            'image/png'  => '.png',
            'image/gif'  => '.gif',
            default      => '.bin'
        };
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        $final = $tmp.$ext;
        rename($tmp,$final);
        file_put_contents($final,$blob);
        return $final;
    }

    $sig_tmp = tempnam(sys_get_temp_dir(), 'sig_').'.png';
    file_put_contents($sig_tmp, $signature_binary);

    $cust_id_front_file  = blobToTempImg($c_id_front_img,'cidf_');
    $cust_id_back_file   = blobToTempImg($c_id_back_img,'cidb_');
    $cust_lic_front_file = blobToTempImg($c_license_front_img,'clif_');
    $cust_lic_back_file  = blobToTempImg($c_license_back_img,'clib_');

    $guar_id_front_file  = blobToTempImg($g_front_blob,'gidf_');
    $guar_id_back_file   = blobToTempImg($g_back_blob,'gidb_');

    $agreement_number = 'AGR-'.strtoupper(dechex(time())).'-P';
    $nowPrint = (new DateTime())->format('Y-m-d H:i:s');

    $delivery_type_label = ucwords(str_replace('_',' ',$delivery_type));
    $delivery_line = ($delivery_type === 'self_pickup')
        ? 'Self Pickup'
        : $delivery_type_label
          . ($delivery_location ? ' | Delivery: '.htmlspecialchars($delivery_location) : '')
          . ($delivery_type === 'pickup_and_return' && $return_location ? ' | Return Pickup: '.htmlspecialchars($return_location) : '');

    $financial_rows = (in_array($delivery_type,['delivery','pickup_and_return'],true))
        ? "<tr><td>Rental Subtotal</td><td style='text-align:right;'>RM ".number_format($rental_subtotal,2)."</td></tr>
           <tr><td>Security Deposit</td><td style='text-align:right;'>RM ".number_format($security_deposit,2)."</td></tr>
           <tr><td>Delivery Fee</td><td style='text-align:right;color:#b36a08;'>PENDING</td></tr>
           <tr><td><strong>Provisional Total (Excl Delivery)</strong></td><td style='text-align:right;'><strong>RM ".number_format($provisional_total,2)."</strong></td></tr>"
        : "<tr><td>Rental Subtotal</td><td style='text-align:right;'>RM ".number_format($rental_subtotal,2)."</td></tr>
           <tr><td>Security Deposit</td><td style='text-align:right;'>RM ".number_format($security_deposit,2)."</td></tr>
           <tr><td><strong>Total Payable</strong></td><td style='text-align:right;'><strong>RM ".number_format($provisional_total,2)."</strong></td></tr>";

    $pdf = new TCPDF();
    $pdf->SetCreator('Timeless Car Rental');
    $pdf->SetAuthor('Timeless Car Rental');
    $pdf->SetTitle('Vehicle Rental Agreement');
    $pdf->SetMargins(14,18,14);
    $pdf->AddPage();

    $pdf->SetFont('helvetica','B',15);
    $pdf->Cell(0,9,'VEHICLE RENTAL AGREEMENT',0,1,'C');
    $pdf->SetFont('helvetica','',9);
    $pdf->Cell(0,6,"Agreement #: {$agreement_number}",0,1,'R');
    $pdf->Cell(0,6,"Generated: {$nowPrint}",0,1,'R');
    $pdf->Ln(2);

    $pdf->writeHTML("
      <h4 style='font-weight:bold;'>1. Booking & Vehicle</h4>
      <table cellpadding='4' width='100%' style='font-size:9pt;'>
        <tr><td width='40%'>Car</td><td>".htmlspecialchars($car['car_brand'].' '.$car['car_model'])."</td></tr>
        <tr><td>Pickup</td><td>".$pickupDT->format('Y-m-d H:i')."</td></tr>
        <tr><td>Return</td><td>".$returnDT->format('Y-m-d H:i')."</td></tr>
        <tr><td>Duration (days)</td><td>{$days}</td></tr>
        <tr><td>Service / Delivery</td><td>{$delivery_line}</td></tr>
      </table>

      <h4 style='font-weight:bold;'>2. Borrower (Customer)</h4>
      <table cellpadding='4' width='100%' style='font-size:9pt;'>
        <tr><td width='40%'>Customer ID</td><td>{$cust_id}</td></tr>
        <tr><td>Full Name</td><td>".htmlspecialchars($c_full_name ?? '')."</td></tr>
        <tr><td>Email</td><td>".htmlspecialchars($c_email ?? '')."</td></tr>
        <tr><td>Phone</td><td>".htmlspecialchars($c_phone ?? '')."</td></tr>
        <tr><td>ID Number</td><td>".htmlspecialchars($c_id_no ?? '')."</td></tr>
        <tr><td>Address</td><td>".htmlspecialchars($c_address ?? '')."</td></tr>
        <tr><td>Age</td><td>".htmlspecialchars($c_age ?? '')."</td></tr>
      </table>
    ", true,false,true,false,'');

    /* Borrower image grid */
    $pdf->SetFont('helvetica','B',10);
    $pdf->Cell(0,6,'Borrower ID & License Images',0,1,'L');
    $pdf->SetFont('helvetica','',8);
    $imgW=55; $imgH=35; $gap=6;
    $baseX = $pdf->GetX();
    $baseY = $pdf->GetY();
    $col=0;
    $borrowerImgs = [
        ['label'=>'ID Front','file'=>$cust_id_front_file],
        ['label'=>'ID Back','file'=>$cust_id_back_file],
        ['label'=>'License Front','file'=>$cust_lic_front_file],
        ['label'=>'License Back','file'=>$cust_lic_back_file],
    ];
    foreach ($borrowerImgs as $bimg) {
        if ($pdf->GetY() + $imgH + 20 > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
            $pdf->AddPage();
            $baseX = $pdf->GetX();
            $baseY = $pdf->GetY();
            $col = 0;
        }
        $x = $baseX + ($col * ($imgW + $gap + 8));
        $y = $baseY;
        $pdf->SetXY($x,$y);
        $pdf->MultiCell($imgW,5,$bimg['label'],0,'C');
        $y += 7;
        if ($bimg['file'] && file_exists($bimg['file']) && filesize($bimg['file'])>0) {
            $pdf->Image($bimg['file'],$x,$y,$imgW,$imgH,'','', '',false,300);
            $pdf->Rect($x,$y,$imgW,$imgH);
        } else {
            $pdf->Rect($x,$y,$imgW,$imgH);
            $pdf->SetXY($x,$y+10);
            $pdf->MultiCell($imgW,5,'(No Image)',0,'C');
        }
        $col++;
        if ($col >= 3) {
            $col=0;
            $baseY += ($imgH + 18);
        }
    }
    if ($col!==0) {
        $baseY += ($imgH + 18);
        $col=0;
    }
    $pdf->SetY($baseY+4);

    /* Guarantor section */
    $pdf->writeHTML("
      <h4 style='font-weight:bold;'>3. Guarantor</h4>
      <table cellpadding='4' width='100%' style='font-size:9pt;'>
        <tr><td width='40%'>Full Name</td><td>".htmlspecialchars($guarSession['guarantor_full_name'])."</td></tr>
        <tr><td>Phone</td><td>".htmlspecialchars($guarSession['guarantor_phone_no'])."</td></tr>
        <tr><td>ID Number</td><td>".htmlspecialchars($guarSession['guarantor_id_no'])."</td></tr>
        <tr><td>Relationship</td><td>".htmlspecialchars($guarSession['guarantor_relationship'])."</td></tr>
      </table>
    ", true,false,true,false,'');

    /* Guarantor images */
    $pdf->SetFont('helvetica','B',10);
    $pdf->Cell(0,6,'Guarantor ID Images',0,1,'L');
    $pdf->SetFont('helvetica','',8);
    $gImages = [
        ['label'=>'Guarantor ID Front','file'=>$guar_id_front_file],
        ['label'=>'Guarantor ID Back','file'=>$guar_id_back_file],
    ];
    $col=0; $startX=$pdf->GetX(); $rowY=$pdf->GetY(); $colWidth=90;
    foreach ($gImages as $gim){
        if ($pdf->GetY() + $imgH + 20 > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
            $pdf->AddPage();
            $startX=$pdf->GetX(); $rowY=$pdf->GetY(); $col=0;
        }
        $x = $startX + ($col * $colWidth);
        $pdf->SetXY($x,$rowY);
        $pdf->MultiCell($colWidth,5,$gim['label'],0,'L');
        $yImg = $pdf->GetY()+2;
        if ($gim['file'] && file_exists($gim['file']) && filesize($gim['file'])>0) {
            $pdf->Image($gim['file'],$x,$yImg,$imgW,$imgH,'','', '',false,300);
            $pdf->Rect($x,$yImg,$imgW,$imgH);
        } else {
            $pdf->Rect($x,$yImg,$imgW,$imgH);
            $pdf->SetXY($x,$yImg+10);
            $pdf->MultiCell($colWidth,6,'(No Image / Empty)',0,'C');
        }
        $col++;
        if ($col===2) { $col=0; $rowY += ($imgH + 22); }
    }
    if ($col!==0) $rowY += ($imgH + 22);
    $pdf->SetY($rowY+4);

    /* Financial summary */
    $pdf->writeHTML("
      <h4 style='font-weight:bold;'>4. Financial Summary</h4>
      <table cellpadding='5' width='100%' style='font-size:9pt;'>
        {$financial_rows}
      </table>
    ", true,false,true,false,'');
    if (in_array($delivery_type,['delivery','pickup_and_return'],true)) {
        $pdf->SetFont('helvetica','I',7.5);
        $pdf->MultiCell(0,4,'(Delivery fee pending – final amount will change when set)',0,'L');
        $pdf->SetFont('helvetica','',9);
    }

    /* Terms */
    $pdf->AddPage();
    $pdf->SetFont('helvetica','B',11);
    $pdf->MultiCell(0,7,'5. Terms & Conditions',0,'L');
    $pdf->SetFont('helvetica','',8.3);
    $pdf->MultiCell(0,5,$terms_text,0,'L');

    /* Signature (admin signature placeholder REMOVED) */
    $pdf->Ln(6);
    $pdf->SetFont('helvetica','B',11);
    $pdf->MultiCell(0,7,'6. Signature',0,'L');
    $pdf->SetFont('helvetica','',9);
    $pdf->MultiCell(0,5,'Borrower acknowledges acceptance of all terms by the signature below.',0,'L');
    $pdf->Ln(3);
    $pdf->SetFont('helvetica','',8);
    $pdf->MultiCell(80,5,"Borrower Signature:",0,'L');
    if (file_exists($sig_tmp)) {
        $pdf->Image($sig_tmp,$pdf->GetX()+2,$pdf->GetY(),55,25,'PNG');
        $pdf->Ln(30);
    } else {
        $pdf->Ln(12);
        $pdf->MultiCell(0,5,'(Signature missing)',0,'L');
    }
    $pdf->MultiCell(0,5,htmlspecialchars($c_full_name ?? 'Borrower'),0,'L');

    // REMOVED ADMIN SIGNATURE PLACEHOLDER LINES HERE

    $pdf_binary = $pdf->Output('', 'S');

    // Clean temp
    foreach ([
        $sig_tmp,
        $cust_id_front_file,$cust_id_back_file,$cust_lic_front_file,$cust_lic_back_file,
        $guar_id_front_file,$guar_id_back_file
    ] as $tmp) {
        if ($tmp && file_exists($tmp)) @unlink($tmp);
    }
}

/* Transaction: guarantor + booking + service + agreement */
$conn->begin_transaction();
try {
    // Reuse guarantor if same ID
    $guarantor_id = null;
    if (!empty($guarSession['guarantor_id_no'])) {
        $chk = $conn->prepare("SELECT guarantor_id FROM guarantor WHERE cust_id=? AND id_no=? LIMIT 1");
        $chk->bind_param("is",$cust_id,$guarSession['guarantor_id_no']);
        $chk->execute();
        $chk->bind_result($existing_gid);
        $chk->fetch();
        $chk->close();
        if (!empty($existing_gid)) $guarantor_id = (int)$existing_gid;
    }
    if (!$guarantor_id) {
        $gStmt = $conn->prepare("
          INSERT INTO guarantor (cust_id, full_name, phone_no, id_no, id_front_image, id_back_image, relationship)
          VALUES (?,?,?,?,?,?,?)
        ");
        $front_for_bind = $g_front_blob;
        $back_for_bind  = $g_back_blob;
        $gStmt->bind_param(
            "isssbbs",
            $cust_id,
            $guarSession['guarantor_full_name'],
            $guarSession['guarantor_phone_no'],
            $guarSession['guarantor_id_no'],
            $front_for_bind,
            $back_for_bind,
            $guarSession['guarantor_relationship']
        );
        if ($g_front_blob !== null) $gStmt->send_long_data(4, $g_front_blob);
        if ($g_back_blob  !== null) $gStmt->send_long_data(5, $g_back_blob);
        $gStmt->execute();
        if ($gStmt->error) throw new Exception("Guarantor insert error: ".$gStmt->error);
        $guarantor_id = $gStmt->insert_id;
        $gStmt->close();
    }

    $bStmt = $conn->prepare("
        INSERT INTO booking
        (cust_id, car_id, pickup_datetime, return_datetime, day_count, daily_rate, total_price, security_deposit, status)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $bStmt->bind_param(
        "iissiidis",
        $cust_id,
        $car_id,
        $pickup_datetime,
        $return_datetime,
        $days,
        $daily_rate,
        $total_price,
        $security_deposit,
        $status
    );
    $bStmt->execute();
    if ($bStmt->error) throw new Exception("Booking insert error: ".$bStmt->error);
    $booking_id = $bStmt->insert_id;
    $bStmt->close();

    if (in_array($delivery_type,['delivery','pickup_and_return'],true)) {
        $servStmt = $conn->prepare("
            INSERT INTO service (booking_id, service_type, fee, staff_id, status, delivery_location, return_location)
            VALUES (?,?,?,?,?,?,?)
        ");
        $fee = null; $staff_id = null; $serv_status = 'pending';
        $servStmt->bind_param(
            "isdisss",
            $booking_id,
            $delivery_type,
            $fee,
            $staff_id,
            $serv_status,
            $delivery_location,
            $return_location
        );
        $servStmt->execute();
        if ($servStmt->error) throw new Exception("Service insert error: ".$servStmt->error);
        $servStmt->close();
    }

    $aStmt = $conn->prepare("
        INSERT INTO agreement_form (booking_id, cust_id, guarantor_id, agreement_file_path, cust_signature)
        VALUES (?,?,?,?,?)
    ");
    $pdf_for_bind = $pdf_binary;
    $sig_for_bind = $signature_binary;
    $aStmt->bind_param("iiibb",
        $booking_id,
        $cust_id,
        $guarantor_id,
        $pdf_for_bind,
        $sig_for_bind
    );
    if ($pdf_binary !== null)      $aStmt->send_long_data(3, $pdf_binary);
    if ($signature_binary !== null)$aStmt->send_long_data(4, $signature_binary);
    $aStmt->execute();
    if ($aStmt->error) throw new Exception("Agreement insert error: ".$aStmt->error);
    $agreement_id = $aStmt->insert_id;
    $aStmt->close();

    $conn->commit();
} catch(Throwable $e) {
    $conn->rollback();
    die("Booking submission failed: ".$e->getMessage());
}

/* Cleanup temp guarantor upload files */
if (!empty($guarSession['guarantor_id_front']) && file_exists($guarSession['guarantor_id_front'])) {
    @unlink($guarSession['guarantor_id_front']);
}
if (!empty($guarSession['guarantor_id_back']) && file_exists($guarSession['guarantor_id_back'])) {
    @unlink($guarSession['guarantor_id_back']);
}

unset($_SESSION['booking_data'], $_SESSION['guarantor_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Booking Submitted | Timeless Car Rental</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background:#eceef4; }
.confirmation-container {
    max-width:640px; margin:60px auto 90px; background:#fff;
    border-radius:14px; box-shadow:0 4px 18px rgba(40,55,95,0.10);
    padding:40px 48px 44px; text-align:center;
}
.confirmation-container h2 { margin:0 0 12px; font-size:1.65em; color:#2f377d; }
.subline { font-size:.92em; color:#526081; margin-bottom:26px; line-height:1.4em; }
.summary-table { width:100%; border-collapse:collapse; margin:0 0 16px; }
.summary-table th, .summary-table td { padding:8px 10px; font-size:.95em; }
.summary-table th {
    text-align:left; color:#32405f; font-weight:600; background:#f5f7fb;
    border-right:1px solid #e1e6f0; width:55%;
}
.summary-table td { text-align:right; background:#fafbfe; color:#243040; }
.total-row td {
    font-weight:700; font-size:1.05em; color:#1f317a;
    border-top:2px solid #c9d2e8; background:#f1f4fb;
}
.pending-fee { color:#b36a08; font-weight:600; }
.note { font-size:.78em; color:#6a7387; margin-top:12px; }
.action-links a {
    display:inline-block; margin:12px 8px 0; text-decoration:none;
    background:#3c4cb8; color:#fff; padding:12px 26px; border-radius:8px;
    font-size:.9em; font-weight:600; transition:.18s;
}
.action-links a:hover { background:#234c96; }
.back-link { background:#d1d5de !important; color:#222 !important; }
.back-link:hover { background:#bfc5ce !important; }
</style>
</head>
<body>
<?php include '../includes/header.php'; ?>

<div class="confirmation-container">
    <h2>Booking Submitted</h2>
    <div class="subline">
        Booking ID: <?= htmlspecialchars($booking_id) ?> stored (status: <strong><?= htmlspecialchars($status) ?></strong>).<br>
        <?php if (in_array($delivery_type,['delivery','pickup_and_return'])): ?>
            Delivery fee is <span class="pending-fee">pending</span>.
        <?php endif; ?>
    </div>
    <table class="summary-table">
        <tr><th>Car</th><td><?= htmlspecialchars($car['car_brand'].' '.$car['car_model']) ?></td></tr>
        <tr><th>Pickup</th><td><?= htmlspecialchars($pickupDT->format('Y-m-d H:i')) ?></td></tr>
        <tr><th>Return</th><td><?= htmlspecialchars($returnDT->format('Y-m-d H:i')) ?></td></tr>
        <tr><th>Duration (days)</th><td><?= $days ?></td></tr>
        <tr><th>Daily Rate</th><td>RM <?= number_format($daily_rate,2) ?></td></tr>
        <tr><th>Rental Subtotal</th><td>RM <?= number_format($rental_subtotal,2) ?></td></tr>
        <tr><th>Security Deposit</th><td>RM <?= number_format($security_deposit,2) ?></td></tr>
        <?php if (in_array($delivery_type,['delivery','pickup_and_return'])): ?>
            <tr><th>Delivery Fee</th><td class="pending-fee">Pending</td></tr>
            <tr class="total-row"><td colspan="2">Provisional Total (Excl Delivery): RM <?= number_format($provisional_total,2) ?></td></tr>
        <?php else: ?>
            <tr class="total-row"><td colspan="2">Total Payable: RM <?= number_format($provisional_total,2) ?></td></tr>
        <?php endif; ?>
    </table>
    <div class="note">
        Agreement PDF (with your images, guarantor images and signature) is stored.
    </div>
    <div class="action-links">
        <a href="dashboard.php" class="back-link">Dashboard</a>
        <a href="download_agreement.php?id=<?= (int)$agreement_id ?>" target="_blank">View Agreement PDF</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>