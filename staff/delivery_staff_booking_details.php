<?php
declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['staff_id'])) {
    header("Location: delivery_staff_login.php");
    exit;
}

$staff_id   = (int)$_SESSION['staff_id'];
$booking_id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($booking_id < 1) {
    echo "Invalid Booking ID";
    include '../includes/footer.php';
    exit;
}

function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

// CSRF
if (empty($_SESSION['staff_csrf'])) {
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['staff_csrf'];

// Authorization: ensure this staff is assigned to this booking
$authStmt = $conn->prepare("
    SELECT service_id
    FROM service
    WHERE booking_id = ? AND staff_id = ?
    LIMIT 1
");
$authStmt->bind_param("ii", $booking_id, $staff_id);
$authStmt->execute();
$authRow = $authStmt->get_result()->fetch_assoc();
$authStmt->close();
if (!$authRow) {
    http_response_code(403);
    echo "Access denied. You are not assigned to this booking.";
    include '../includes/footer.php';
    exit;
}

/* Fetch staff contact (ADDED) */
$staff_phone = null;
$staff_name  = null;
$stfStmt = $conn->prepare("SELECT full_name, phone_number FROM delivery_staff WHERE staff_id = ? LIMIT 1");
$stfStmt->bind_param("i", $staff_id);
$stfStmt->execute();
$stfStmt->bind_result($staff_name, $staff_phone);
$stfStmt->fetch();
$stfStmt->close();
$staff_phone = trim((string)$staff_phone);
$staff_name  = trim((string)$staff_name);

// Booking + minimal customer + car (with color) for verification (+ customer email for notifications)
$bkStmt = $conn->prepare("
SELECT 
    b.booking_id, b.cust_id, b.car_id,
    b.pickup_datetime, b.return_datetime, b.day_count,
    c.car_brand, c.car_model, c.color AS car_color,
    cu.full_name AS customer_name, cu.phone_no AS customer_phone,
    cu.id_no AS customer_id_no, cu.email AS customer_email,
    cu.id_front_image AS cust_id_front_image,
    cu.id_back_image AS cust_id_back_image,
    cu.license_front_image AS cust_license_front_image,
    cu.license_back_image AS cust_license_back_image
FROM booking b
LEFT JOIN car c ON b.car_id = c.car_id
LEFT JOIN customer cu ON b.cust_id = cu.cust_id
WHERE b.booking_id = ?
LIMIT 1
");
$bkStmt->bind_param("i", $booking_id);
$bkStmt->execute();
$booking = $bkStmt->get_result()->fetch_assoc();
$bkStmt->close();

if (!$booking) {
    echo "Booking not found.";
    include '../includes/footer.php';
    exit;
}

$customerEmail = trim((string)($booking['customer_email'] ?? ''));

// All service rows for this booking (to show status and allow Out for Delivery only)
$services = [];
$svcStmt = $conn->prepare("SELECT * FROM service WHERE booking_id = ? ORDER BY service_id DESC");
$svcStmt->bind_param("i", $booking_id);
$svcStmt->execute();
$svcRes = $svcStmt->get_result();
while ($row = $svcRes->fetch_assoc()) $services[] = $row;
$svcStmt->close();

// Inspection images (for gallery)
$booking_imgs = [];
$imgQ = $conn->prepare("
    SELECT booking_image_id, image_path, image_type, capture_type, uploaded_at, inspection_date
    FROM booking_image
    WHERE booking_id = ?
    ORDER BY booking_image_id ASC
");
$imgQ->bind_param("i", $booking_id);
$imgQ->execute();
$imgRes = $imgQ->get_result();
while ($r = $imgRes->fetch_assoc()) $booking_imgs[] = $r;
$imgQ->close();

$pickupImages = array_values(array_filter($booking_imgs, fn($im) => strtolower($im['capture_type'] ?? '') === 'pickup'));
$returnImages = array_values(array_filter($booking_imgs, fn($im) => strtolower($im['capture_type'] ?? '') === 'return'));

/**
 * Send "Out for Delivery" notification email to customer via SMTP (PHPMailer).
 *
 * Added parameters: $staffPhone, $staffName to include direct staff contact information.
 *
 * Returns [bool success, string message]
 */
function notify_out_for_delivery(
    string $toEmail,
    array $booking,
    array $serviceRow,
    ?string $staffPhone,
    ?string $staffName
): array
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, "Customer email is invalid or missing; skipped email notification."];
    }

    // Try to load PHPMailer (Composer autoload or manual include)
    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        } else {
            @require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
            @require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
            @require_once __DIR__ . '/../PHPMailer/src/Exception.php';
        }
    }

    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        return [false, "Mailer library not available; skipped email notification."];
    }

    $bookingId     = (int)($booking['booking_id'] ?? 0);
    $car           = trim(($booking['car_brand'] ?? '') . ' ' . ($booking['car_model'] ?? ''));
    $pickupDT      = !empty($booking['pickup_datetime']) ? date('d/m/Y H:i', strtotime($booking['pickup_datetime'])) : '-';
    $customerName  = (string)($booking['customer_name'] ?? 'Customer');
    $cleanStaffPhone = preg_replace('/\D+/', '', (string)$staffPhone);
    $displayStaffPhone = $cleanStaffPhone ?: '0199590828'; // fallback to company number
    $displayStaffName  = $staffName ?: 'Delivery Staff';

    $subject = "Your car is out for delivery (Booking #$bookingId)";
    $html = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333'>
            <h2 style='margin:0 0 10px;'>Out for Delivery</h2>
            <p>Hi ".htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8').",</p>
            <p>Your car delivery is now on the way.</p>
            <table cellpadding='6' cellspacing='0' style='border-collapse:collapse'>
                <tr><td><strong>Booking ID</strong></td><td>#{$bookingId}</td></tr>
                <tr><td><strong>Car</strong></td><td>".htmlspecialchars($car, ENT_QUOTES, 'UTF-8')."</td></tr>
                <tr><td><strong>Scheduled Delivery Time</strong></td><td>{$pickupDT}</td></tr>
                <tr><td><strong>Delivery Staff</strong></td><td>".htmlspecialchars($displayStaffName, ENT_QUOTES, 'UTF-8')."</td></tr>
                <tr><td><strong>Staff Contact</strong></td><td>".htmlspecialchars($displayStaffPhone, ENT_QUOTES, 'UTF-8')."</td></tr>
            </table>
            <p>If you have any questions or issues regarding the delivery, please contact the staff directly at 
               <strong>".htmlspecialchars($displayStaffPhone, ENT_QUOTES, 'UTF-8')."</strong>.</p>
            <p>Thank you for choosing Timeless Car Rental.</p>
            <p style='color:#666;font-size:12px;'>If you cannot reach the staff, you may contact Timeless Car Rental at 0199590828.</p>
            <p style='color:#666;font-size:12px;'>This is an automated message. Please do not reply to this email.</p>
        </div>
    ";

    $alt = "Out for Delivery\n\n"
         . "Booking ID: #$bookingId\n"
         . "Car: $car\n"
         . "Scheduled Delivery Time: $pickupDT\n"
         . "Delivery Staff: $displayStaffName\n"
         . "Staff Contact: $displayStaffPhone\n\n"
         . "If you have any issues, call the staff above or Timeless Car Rental at 0199590828.\n"
         . "Thank you for choosing Timeless Car Rental.";

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // SMTP settings (replace with secure config or environment variables)
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'fathehaharis69@gmail.com';
        $mail->Password   = 'cuel ijeu lzqv vsgv'; // Consider moving to env/config
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('no-reply@timelesscarrental.com', 'Timeless Car Rental');
        $mail->addAddress($toEmail, $customerName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $alt;

        $mail->send();
        return [true, "Email notification sent to customer."];
    } catch (\Throwable $e) {
        return [false, "Status updated but email notification failed."];
    }
}

// Handle POST: only allow setting Out for Delivery here (no other status)
$flash = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_ofd') {
    if (!hash_equals($_SESSION['staff_csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        $err = "Invalid session token. Please refresh.";
    } else {
        $service_id = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
        if ($service_id > 0) {
            // Validate: service belongs to this staff, is pending, and is delivery-related
            $chk = $conn->prepare("
                SELECT service_id, service_type, status
                FROM service
                WHERE service_id = ? AND staff_id = ? AND status = 'pending'
                LIMIT 1
            ");
            $chk->bind_param("ii", $service_id, $staff_id);
            $chk->execute();
            $svc = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($svc && in_array($svc['service_type'], ['delivery','pickup_and_return'], true)) {
                $upd = $conn->prepare("UPDATE service SET status='out_for_delivery' WHERE service_id=?");
                $upd->bind_param("i", $service_id);
                if ($upd->execute()) {
                    $flash = "Status updated to Out for Delivery.";

                    // Try sending email notification to customer (PASS staff contact now)
                    [$ok, $msg] = notify_out_for_delivery($customerEmail, $booking, $svc, $staff_phone, $staff_name);
                    $flash .= " " . $msg;
                } else {
                    $err = "Failed to update status.";
                }
                $upd->close();

                // refresh services after update
                $services = [];
                $svcStmt = $conn->prepare("SELECT * FROM service WHERE booking_id = ? ORDER BY service_id DESC");
                $svcStmt->bind_param("i", $booking_id);
                $svcStmt->execute();
                $svcRes = $svcStmt->get_result();
                while ($row = $svcRes->fetch_assoc()) $services[] = $row;
                $svcStmt->close();
            } else {
                $err = "Only pending Delivery or Pickup & Return services can be set to Out for Delivery.";
            }
        } else {
            $err = "Invalid service.";
        }
    }
}

include 'staff_header.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Booking Details</title>
    <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: #f6f7fb; margin:0;}
        .container { max-width: 1040px; margin:40px auto 60px; background:#fff; border-radius:12px; box-shadow:0 2px 15px #e0e7ef44; padding:32px 28px; }
        .title { font-size:1.4em; font-weight:800; color:#2b5cbc; margin:0 0 16px; }
        .msg-ok { background:#e6fcf3; color:#218c6d; padding:10px 16px; border-radius:9px; font-weight:600; margin:0 0 16px; }
        .msg-err { background:#ffeded; color:#b62f2f; padding:10px 16px; border-radius:9px; font-weight:600; margin:0 0 16px; }
        .section-title { font-size:1.05em; font-weight:800; color:#2d3a5f; margin:20px 0 8px; }
        .table { width:100%; border-collapse:collapse; }
        .table th { background:#f7fafd; color:#234c96; font-weight:700; text-align:left; width:220px; padding:10px; border-bottom:1px solid #eee; }
        .table td { padding:10px; border-bottom:1px solid #eee; }
        .pill { display:inline-block; padding:4px 10px; border-radius:999px; font-weight:700; font-size:.82em; }
        .pill-pending { background:#fff6d8; color:#b28a00; }
        .pill-ofd { background:#e5f1ff; color:#0a52a1; }
        .pill-delivered { background:#e4fae8; color:#14773f; }
        .service-card { background:#f7fafd; border:1.5px solid #e4e8f3; border-radius:8px; padding:12px 14px; margin:10px 0; }
        .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
        .btn { padding:8px 12px; border-radius:7px; background:#2b5cbc; color:#fff; border:none; font-weight:700; cursor:pointer; }
        .btn.gray { background:#f7fafd; color:#2b5cbc; border:1.5px solid #b5bee5; }
        .btn.gray:hover { background:#e7efff; }
        .gallery { display:flex; gap:12px; flex-wrap:wrap; }
        .thumb { width:150px; height:100px; object-fit:cover; border:1px solid #cfd6e4; border-radius:8px; background:#fff; cursor:pointer; }
        .note { font-size:.82em; color:#6a7485; }
        #imgModal { position:fixed; inset:0; background:rgba(16,26,44,.85); display:none; align-items:center; justify-content:center; z-index:9999; padding:40px 26px; }
        #imgModal img { max-width:90vw; max-height:80vh; box-shadow:0 10px 28px rgba(0,0,0,.55); border-radius:10px; background:#fff; }
        #imgModal .close-btn, #imgModal .nav-btn { position:absolute; background:#ffffff; border:none; padding:10px 16px; border-radius:8px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.25); font-size:.75rem; }
        #imgModal .close-btn { top:14px; right:20px; }
        #imgModal .nav-btn { top:50%; transform:translateY(-50%); }
        #imgModal .prev-btn { left:18px; }
        #imgModal .next-btn { right:18px; }
        .modal-caption { margin-top:12px; font-size:.7rem; color:#eef3f8; text-align:center; max-width:86vw; line-height:1.2rem; font-weight:500; }
        .modal-caption span { color:#ffdf6e; font-weight:600; }
    </style>
</head>
<body>
<div class="container">
    <div class="title">Booking #<?= e($booking_id) ?> — Delivery Staff</div>

    <?php if ($flash): ?><div class="msg-ok"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg-err"><?= e($err) ?></div><?php endif; ?>

    <div class="section-title">Customer Info</div>
    <table class="table">
        <tr><th>Name</th><td><?= e($booking['customer_name'] ?? '-') ?></td></tr>
        <tr><th>Phone</th><td><?= e($booking['customer_phone'] ?? '-') ?></td></tr>
        <tr><th>ID Number</th><td><?= e($booking['customer_id_no'] ?? '-') ?></td></tr>
    </table>

    <div class="section-title">Customer ID & License (Click to view)</div>
    <div class="gallery">
        <?php if (!empty($booking['cust_id_front_image'])): ?>
            <?php $src = 'data:image/jpeg;base64,'.base64_encode($booking['cust_id_front_image']); ?>
            <img class="thumb g" src="<?= $src ?>" data-full="<?= $src ?>" alt="Customer ID Front">
        <?php endif; ?>
        <?php if (!empty($booking['cust_id_back_image'])): ?>
            <?php $src = 'data:image/jpeg;base64,'.base64_encode($booking['cust_id_back_image']); ?>
            <img class="thumb g" src="<?= $src ?>" data-full="<?= $src ?>" alt="Customer ID Back">
        <?php endif; ?>
        <?php if (!empty($booking['cust_license_front_image'])): ?>
            <?php $src = 'data:image/jpeg;base64,'.base64_encode($booking['cust_license_front_image']); ?>
            <img class="thumb g" src="<?= $src ?>" data-full="<?= $src ?>" alt="License Front">
        <?php endif; ?>
        <?php if (!empty($booking['cust_license_back_image'])): ?>
            <?php $src = 'data:image/jpeg;base64,'.base64_encode($booking['cust_license_back_image']); ?>
            <img class="thumb g" src="<?= $src ?>" data-full="<?= $src ?>" alt="License Back">
        <?php endif; ?>
        <?php if (empty($booking['cust_id_front_image']) && empty($booking['cust_id_back_image']) && empty($booking['cust_license_front_image']) && empty($booking['cust_license_back_image'])): ?>
            <div class="note">No ID/License images available.</div>
        <?php endif; ?>
    </div>

    <div class="section-title">Car Info</div>
    <table class="table">
        <tr><th>Brand & Model</th><td><?= e(trim(($booking['car_brand'] ?? '').' '.($booking['car_model'] ?? ''))) ?></td></tr>
        <tr><th>Color</th><td><?= e($booking['car_color'] ?? '-') ?></td></tr>
    </table>

    <div class="section-title">Booking Schedule</div>
    <table class="table">
        <tr><th>Pickup</th><td><?= !empty($booking['pickup_datetime']) ? date('d/m/Y H:i', strtotime($booking['pickup_datetime'])) : '-' ?></td></tr>
        <tr><th>Return</th><td><?= !empty($booking['return_datetime']) ? date('d/m/Y H:i', strtotime($booking['return_datetime'])) : '-' ?></td></tr>
        <tr><th>Day Count</th><td><?= e((string)($booking['day_count'] ?? '-')) ?></td></tr>
    </table>

    <div class="section-title">Services</div>
    <?php if (empty($services)): ?>
        <div class="note">No service rows found.</div>
    <?php else: foreach ($services as $s): ?>
        <?php
            $badge = 'pill-pending'; $label = 'Pending';
            if ($s['status'] === 'out_for_delivery') { $badge = 'pill-ofd'; $label = 'Out for Delivery'; }
            elseif ($s['status'] === 'delivered') { $badge = 'pill-delivered'; $label = 'Delivered'; }
            $isDeliveryRelated = in_array($s['service_type'], ['delivery','pickup_and_return'], true);
        ?>
        <div class="service-card">
            <div><strong>Type:</strong> <?= e($s['service_type']) ?></div>
            <div><strong>Status:</strong> <span class="pill <?= $badge ?>"><?= e($label) ?></span></div>
            <?php if (!empty($s['delivery_location'])): ?>
                <div><strong>Delivery Location:</strong> <?= e($s['delivery_location']) ?></div>
            <?php endif; ?>
            <?php if (!empty($s['return_location'])): ?>
                <div><strong>Return Location:</strong> <?= e($s['return_location']) ?></div>
            <?php endif; ?>

            <div class="actions">
                <?php if ($isDeliveryRelated && $s['status'] === 'pending' && (int)$s['staff_id'] === $staff_id): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                        <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                        <button type="submit" name="action" value="set_ofd" class="btn">Set Out for Delivery</button>
                    </form>
                <?php endif; ?>
                <?php if ($isDeliveryRelated): ?>
                    <a class="btn gray" href="inspection_add.php?booking_id=<?= (int)$booking_id ?>&type=pickup">Pickup Inspection</a>
                    <?php if ($s['service_type'] === 'pickup_and_return'): ?>
                        <a class="btn gray" href="inspection_add.php?booking_id=<?= (int)$booking_id ?>&type=return">Return Inspection</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>

    <div class="section-title">Inspection Images</div>
    <div style="margin:8px 0;font-weight:700;">Pickup</div>
    <?php if (empty($pickupImages)): ?>
        <div class="note">No pickup images yet.</div>
    <?php else: ?>
        <div class="gallery">
            <?php foreach ($pickupImages as $im): $src = 'data:image/jpeg;base64,'.base64_encode($im['image_path']); ?>
                <img class="thumb g" src="<?= $src ?>" data-full="<?= $src ?>" alt="Pickup Image">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="margin:14px 0 6px;font-weight:700;">Return</div>
    <?php if (empty($returnImages)): ?>
        <div class="note">No return images yet.</div>
    <?php else: ?>
        <div class="gallery">
            <?php foreach ($returnImages as $im): $src = 'data:image/jpeg;base64,'.base64_encode($im['image_path']); ?>
                <img class="thumb g" src="<?= $src ?>" data-full="<?= $src ?>" alt="Return Image">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="margin-top:24px;">
        <a href="delivery_staff_dashboard.php" class="btn gray">&larr; Back to Dashboard</a>
    </div>
</div>

<!-- Modal -->
<div id="imgModal">
    <button type="button" class="close-btn" id="closeModalBtn">Close (Esc)</button>
    <button type="button" class="nav-btn prev-btn" id="prevImgBtn">&#10094; Prev</button>
    <button type="button" class="nav-btn next-btn" id="nextImgBtn">Next &#10095;</button>
    <div style="display:flex;flex-direction:column;align-items:center;">
        <img id="modalImg" src="" alt="Full Image">
        <div class="modal-caption" id="modalCaption"></div>
    </div>
</div>

<script>
(function(){
  const modal = document.getElementById('imgModal');
  const modalImg = document.getElementById('modalImg');
  const modalCaption = document.getElementById('modalCaption');
  const closeBtn = document.getElementById('closeModalBtn');
  const prevBtn = document.getElementById('prevImgBtn');
  const nextBtn = document.getElementById('nextImgBtn');
  let gallery = [];
  let current = -1;

  function build(){
    gallery = [];
    document.querySelectorAll('.g').forEach((img, i)=>{
      const src = img.getAttribute('data-full') || img.src;
      const caption = img.alt || 'Image';
      img.dataset.gi = String(i);
      gallery.push({src, caption});
    });
  }
  build();

  function openAt(i){
    if (i < 0 || i >= gallery.length) return;
    current = i;
    const item = gallery[i];
    modalImg.src = item.src;
    modalCaption.innerHTML = '<span>'+(i+1)+' / '+gallery.length+'</span> &nbsp;'+item.caption;
    modal.style.display='flex';
  }
  function close(){ modal.style.display='none'; modalImg.src=''; current=-1; }
  function next(){ if (current !== -1) openAt((current+1)%gallery.length); }
  function prev(){ if (current !== -1) openAt((current-1+gallery.length)%gallery.length); }

  document.addEventListener('click', (e)=>{
    const t = e.target;
    if (t.matches('.g')) {
      const i = parseInt(t.dataset.gi || '0', 10) || 0;
      openAt(i);
    } else if (t === closeBtn || t === modal) {
      close();
    } else if (t === nextBtn) {
      next();
    } else if (t === prevBtn) {
      prev();
    }
  });
  document.addEventListener('keyup', (e)=>{
    if (modal.style.display === 'flex'){
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowRight') next();
      if (e.key === 'ArrowLeft') prev();
    }
  });
})();
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>