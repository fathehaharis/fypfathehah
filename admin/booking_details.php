<?php
declare(strict_types=1);
/*
 * booking_details.php (Admin)
 * Features:
 *  - Security Deposit Summary (damage deduction & refund integration)
 *  - Improved Inspection Images (grouping, filtering, modal navigation)
 *  - Back button links to bookings.php
 *  - Unified modal gallery (inspection + ID/license/guarantor)
 *  - Agreement Download button (ALWAYS visible section with clear status)
 *  - SMTP email to customer on APPROVE/REJECT (includes reason on reject)
 */

ini_set('display_errors', 1); // Disable in production
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

/* ================= SMTP (PHPMailer) helper ================= */
require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send email via SMTP (PHPMailer).
 * Configure via environment variables (recommended):
 *   SMTP_HOST, SMTP_PORT, SMTP_SECURE (tls|ssl|''), SMTP_USERNAME, SMTP_PASSWORD,
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME, SMTP_REPLY_TO, SMTP_REPLY_TO_NAME
 *
 * For Gmail:
 * - SMTP_HOST=smtp.gmail.com
 * - SMTP_PORT=587
 * - SMTP_SECURE=tls
 * - SMTP_USERNAME=your_gmail@gmail.com
 * - SMTP_PASSWORD=your_app_password (not your login password)
 * - SMTP_FROM_EMAIL=your_gmail@gmail.com (Gmail requires From to match authenticated user)
 */
function send_mail_smtp(string $toEmail, string $toName, string $subject, string $html, string $altText = ''): array {
    $host     = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $port     = (int)(getenv('SMTP_PORT') ?: 587);
    $secure   = getenv('SMTP_SECURE') ?: 'tls';
    $username = getenv('SMTP_USERNAME') ?: 'fathehaharis69@gmail.com';
    $password = getenv('SMTP_PASSWORD') ?: 'cuel ijeu lzqv vsgv';
    $fromEmail= getenv('SMTP_FROM_EMAIL') ?: $username; // Gmail requires from == username
    $fromName = getenv('SMTP_FROM_NAME') ?: 'TimeLess Car Rental';
    $replyTo  = getenv('SMTP_REPLY_TO') ?: $fromEmail;
    $replyNm  = getenv('SMTP_REPLY_TO_NAME') ?: $fromName;

    if (!class_exists(PHPMailer::class)) {
        return [false, 'PHPMailer not installed. Run: composer require phpmailer/phpmailer'];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        if ($secure) $mail->SMTPSecure = $secure; // tls or ssl
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($replyTo, $replyNm);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $altText !== '' ? $altText : strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html));

        $mail->send();
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/* ================= Utilities ================= */
function e($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}
function imgTag(?string $blob, string $alt, string $cls='img-thumb'): string {
    if ($blob === null || $blob === '') {
        return '<div class="img-missing">No Image</div>';
    }
    return '<img src="data:image/jpeg;base64,'.base64_encode($blob).'" alt="'.e($alt).'" class="'.$cls.'" data-full="data:image/jpeg;base64,'.base64_encode($blob).'" loading="lazy">';
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* Booking ID */
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    echo "<p>Invalid booking ID.</p>";
    include '../includes/footer.php';
    exit;
}
$booking_id = (int)$_GET['id'];

/* ================= Fetch booking EARLY (for email too) ================= */
$sql = "
SELECT
    b.booking_id, b.cust_id, b.car_id,
    b.pickup_datetime, b.return_datetime,
    b.day_count, b.daily_rate, b.total_price,
    b.security_deposit,
    b.security_deposit_deduction,
    b.security_deposit_refund,
    b.deposit_status,
    b.deposit_last_adjusted_at,
    b.deposit_damage_description,
    b.pickup_mileage, b.return_mileage,
    b.pickup_fuel_percent, b.return_fuel_percent,
    b.status, b.rejection_reason, b.cancellation_reason,
    b.created_at, b.confirmed_at, b.approved_at,
    c.car_brand, c.car_model, c.daily_rate AS car_daily_rate,
    c.plate_no, c.year, c.color, c.mileage AS car_mileage_snapshot,
    c.transmission, c.seat_capacity,
    cust.full_name AS customer_name,
    cust.phone_no AS customer_phone,
    cust.email AS customer_email,
    cust.id_no AS customer_id_no,
    cust.id_front_image AS cust_id_front_image,
    cust.id_back_image AS cust_id_back_image,
    cust.license_front_image AS cust_license_front_image,
    cust.license_back_image AS cust_license_back_image,
    af.guarantor_id
FROM booking b
JOIN car c ON b.car_id = c.car_id
LEFT JOIN customer cust ON b.cust_id = cust.cust_id
LEFT JOIN agreement_form af ON af.booking_id = b.booking_id
WHERE b.booking_id = ?
LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo "<p>Booking not found.</p>";
    include '../includes/footer.php';
    exit;
}

/* ================= POST Handling (approve / fee / reject) WITH EMAIL ================= */
$action_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $action_error = "Invalid session token. Please refresh.";
    } else {
        $action = $_POST['booking_action'] ?? '';
        if (in_array($action, ['save_fee','approve'], true)) {

            // Determine if there's a delivery-related service row
            $svcSel = $conn->prepare("
                SELECT service_id
                FROM service
                WHERE booking_id=? AND service_type IN ('delivery','pickup_and_return')
                ORDER BY service_id DESC LIMIT 1
            ");
            $svcSel->bind_param('i', $booking_id);
            $svcSel->execute();
            $deliveryRow = $svcSel->get_result()->fetch_assoc();
            $svcSel->close();

            $hasDeliveryType = (bool)$deliveryRow;

            if ($action === 'save_fee') {
                if (!$hasDeliveryType) {
                    $action_error = "Cannot save fee: self pickup (no fee).";
                } else {
                    $feeRaw = $_POST['service_fee'] ?? '';
                    if ($feeRaw === '' || !is_numeric($feeRaw)) {
                        $action_error = "Delivery / pickup fee must be numeric.";
                    } else {
                        $fee = number_format((float)$feeRaw, 2, '.', '');
                        if ((float)$fee < 0) {
                            $action_error = "Fee cannot be negative.";
                        } else {
                            $upd = $conn->prepare("UPDATE service SET fee=? WHERE service_id=?");
                            $upd->bind_param('di', $fee, $deliveryRow['service_id']);
                            $upd->execute();
                            $upd->close();
                            $_SESSION['flash_message'] = "Service fee saved.";
                            header("Location: booking_details.php?id=".$booking_id);
                            exit;
                        }
                    }
                }
            } elseif ($action === 'approve') {
                if ($hasDeliveryType) {
                    $feeRaw = $_POST['service_fee'] ?? '';
                    if ($feeRaw === '' || !is_numeric($feeRaw)) {
                        $action_error = "Delivery / pickup fee must be numeric before approval.";
                    } else {
                        $fee = number_format((float)$feeRaw, 2, '.', '');
                        if ((float)$fee < 0) {
                            $action_error = "Fee cannot be negative.";
                        } else {
                            $upd = $conn->prepare("UPDATE service SET fee=? WHERE service_id=?");
                            $upd->bind_param('di', $fee, $deliveryRow['service_id']);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                }
                if (!$action_error) {
                    $stmt = $conn->prepare("UPDATE booking SET status='approved', approved_at=NOW() WHERE booking_id=? AND status IN ('pending','waiting_verification')");
                    $stmt->bind_param('i', $booking_id);
                    $stmt->execute();
                    $changed = $stmt->affected_rows;
                    $stmt->close();

                    // Send APPROVED email if status changed
                    if ($changed) {
                        $toEmail = (string)($booking['customer_email'] ?? '');
                        $toName  = (string)($booking['customer_name'] ?? 'Customer');
                        if ($toEmail !== '') {
                            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $url    = $scheme . '://' . $host . '/index.php?id=' . $booking_id;

                            $pickup = $booking['pickup_datetime'] ? date('d M Y, g:i A', strtotime($booking['pickup_datetime'])) : '-';
                            $return = $booking['return_datetime'] ? date('d M Y, g:i A', strtotime($booking['return_datetime'])) : '-';
                            $car    = trim(($booking['car_brand'] ?? '') . ' ' . ($booking['car_model'] ?? ''));

                            $subject = "Your Booking #{$booking_id} is Approved – Next Step: Payment";
                            $html = "
                                <div style='font-family:Arial,sans-serif;font-size:14px;color:#222'>
                                  <p>Hi ".e($toName).",</p>
                                  <p>Good news! Your booking <strong>#{$booking_id}</strong> has been <strong>approved</strong>.</p>
                                  <p>Please log in to complete payment and view your booking details:</p>
                                  <p><a href='".e($url)."' target='_blank' style='color:#1a54b3'>View Booking</a></p>
                                  <hr style='border:none;border-top:1px solid #ddd'>
                                  <p>
                                    <strong>Car:</strong> ".e($car)."<br>
                                    <strong>Pickup:</strong> ".e($pickup)."<br>
                                    <strong>Return:</strong> ".e($return)."
                                  </p>
                                  <p style='color:#555'>Thank you for choosing TimeLess Car Rental.</p>
                                </div>
                            ";
                            [$ok, $err] = send_mail_smtp($toEmail, $toName, $subject, $html);
                            $_SESSION['flash_message'] = $ok
                                ? "Booking approved (Pending Payment). Customer notified via email."
                                : "Booking approved (Pending Payment). Email not sent: ".$err;
                        } else {
                            $_SESSION['flash_message'] = "Booking approved (Pending Payment). No customer email on record.";
                        }
                    } else {
                        $_SESSION['flash_message'] = "Cannot approve: not in pending state.";
                    }

                    header("Location: booking_details.php?id=".$booking_id);
                    exit;
                }
            }

        } elseif ($action === 'reject') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') {
                $action_error = "Rejection reason required.";
            } else {
                $stmt = $conn->prepare("UPDATE booking SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE booking_id=? AND status IN ('pending','waiting_verification')");
                $stmt->bind_param('si', $reason, $booking_id);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed) {
                    // Send REJECTED email
                    $toEmail = (string)($booking['customer_email'] ?? '');
                    $toName  = (string)($booking['customer_name'] ?? 'Customer');
                    if ($toEmail !== '') {
                        $pickup = $booking['pickup_datetime'] ? date('d M Y, g:i A', strtotime($booking['pickup_datetime'])) : '-';
                            $car    = trim(($booking['car_brand'] ?? '') . ' ' . ($booking['car_model'] ?? ''));

                        $subject = "Your Booking #{$booking_id} was Rejected";
                        $html = "
                            <div style='font-family:Arial,sans-serif;font-size:14px;color:#222'>
                              <p>Hi ".e($toName).",</p>
                              <p>We’re sorry to inform you that your booking <strong>#{$booking_id}</strong> has been <strong>rejected</strong>.</p>
                              <p><strong>Reason:</strong> ".nl2br(e($reason))."</p>
                              <hr style='border:none;border-top:1px solid #ddd'>
                              <p>
                                <strong>Car:</strong> ".e($car)."<br>
                                <strong>Original Pickup:</strong> ".e($pickup)."
                              </p>
                              <p style='color:#555'>If you have questions, please reply to this email or contact support.</p>
                            </div>
                        ";
                        [$ok, $err] = send_mail_smtp($toEmail, $toName, $subject, $html);
                        $_SESSION['flash_message'] = $ok
                            ? "Booking rejected. Customer notified via email."
                            : "Booking rejected. Email not sent: ".$err;
                    } else {
                        $_SESSION['flash_message'] = "Booking rejected. No customer email on record.";
                    }
                } else {
                    $_SESSION['flash_message'] = "Cannot reject: not in pending state.";
                }

                header("Location: booking_details.php?id=".$booking_id);
                exit;
            }
        }
    }
}

/* ================= Car primary image ================= */
$car_image_blob = null;
$imgStmt = $conn->prepare("
    SELECT image_blob
    FROM car_image
    WHERE car_id=?
    ORDER BY sort_order ASC, car_image_id ASC
    LIMIT 1
");
$imgStmt->bind_param('i', $booking['car_id']);
$imgStmt->execute();
$imgStmt->bind_result($car_image_blob);
$imgStmt->fetch();
$imgStmt->close();

/* ================= Guarantor ================= */
$guarantor = null;
if (!empty($booking['guarantor_id'])) {
    $gStmt = $conn->prepare("
        SELECT full_name, phone_no, id_no, relationship,
               id_front_image, id_back_image
        FROM guarantor
        WHERE guarantor_id=? LIMIT 1
    ");
    $gStmt->bind_param('i', $booking['guarantor_id']);
    $gStmt->execute();
    $guarantor = $gStmt->get_result()->fetch_assoc();
    $gStmt->close();
}

/* ================= Services ================= */
$svcStmt = $conn->prepare("
    SELECT service_id, service_type, fee, status, delivery_location, return_location
    FROM service
    WHERE booking_id=?
    ORDER BY service_id DESC
");
$svcStmt->bind_param('i', $booking_id);
$svcStmt->execute();
$services = $svcStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$svcStmt->close();

/* Primary service identification */
$primaryService = null;
foreach ($services as $s) {
    if (in_array($s['service_type'], ['delivery','pickup_and_return','self_pickup'], true)) {
        $primaryService = $s;
        break;
    }
}
$delivery_type_display = 'Pickup Myself (Free)';
$delivery_location = '';
$return_location = '';
$delivery_fee = 0.00;
$current_delivery_fee_raw = '';
$service_mode = 'self_pickup';

if ($primaryService) {
    $service_mode = $primaryService['service_type'];
    if ($service_mode === 'delivery') {
        $delivery_type_display = 'Delivery (Drop-off Only)';
        $delivery_location = $primaryService['delivery_location'] ?? '';
        $delivery_fee = (float)$primaryService['fee'];
        $current_delivery_fee_raw = $delivery_fee > 0 ? number_format($delivery_fee,2,'.','') : '';
    } elseif ($service_mode === 'pickup_and_return') {
        $delivery_type_display = 'Pickup & Return';
        $delivery_location = $primaryService['delivery_location'] ?? '';
        $return_location = $primaryService['return_location'] ?? '';
        $delivery_fee = (float)$primaryService['fee'];
        $current_delivery_fee_raw = $delivery_fee > 0 ? number_format($delivery_fee,2,'.','') : '';
    } elseif ($service_mode === 'self_pickup') {
        $delivery_type_display = 'Pickup Myself (Free)';
        $delivery_fee = 0.00;
    }
}

/* Other services total */
$other_services_total = 0.00;
foreach ($services as $s) {
    if ($primaryService && $s['service_id'] === $primaryService['service_id']) continue;
    $other_services_total += (float)$s['fee'];
}

/* ================= Agreement (ensure visible state) ================= */
$agreement_id = null;
$agrStmt = $conn->prepare("SELECT agreement_id FROM agreement_form WHERE booking_id=? LIMIT 1");
$agrStmt->bind_param('i', $booking_id);
$agrStmt->execute();
$agrStmt->bind_result($agreement_id);
$agrStmt->fetch();
$agrStmt->close();
$agreement_download_link = $agreement_id ? "download_agreement.php?id=".urlencode((string)$agreement_id) : null;

/* ================= Inspection images ================= */
$imgStmt = $conn->prepare("
    SELECT booking_image_id, image_path, image_type, capture_type, uploaded_at, inspection_date
    FROM booking_image
    WHERE booking_id=?
    ORDER BY booking_image_id ASC
");
$imgStmt->bind_param('i', $booking_id);
$imgStmt->execute();
$booking_imgs = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$imgStmt->close();

/* Grouping */
$requiredTypes = ['car_front','car_back','car_left','car_right','fuel_image'];
$pickupImages = [];
$returnImages = [];
foreach ($booking_imgs as $bi) {
    $ct = strtolower($bi['capture_type'] ?? '');
    if ($ct === 'pickup') $pickupImages[] = $bi;
    elseif ($ct === 'return') $returnImages[] = $bi;
}
$pickup_filled = !empty($booking['pickup_mileage']) &&
                 $booking['pickup_fuel_percent'] !== null &&
                 !empty($booking['pickup_datetime']) &&
                 !empty($pickupImages);
$return_inspection_done = !empty($booking['return_mileage']) &&
                          $booking['return_fuel_percent'] !== null &&
                          !empty($booking['return_datetime']) &&
                          !empty($returnImages);

function missingTypes(array $images, array $required): array {
    $present = [];
    foreach ($images as $im) $present[] = strtolower($im['image_type']);
    return array_values(array_diff($required, $present));
}
$pickupMissing = missingTypes($pickupImages, $requiredTypes);
$returnMissing = missingTypes($returnImages, $requiredTypes);

$imageTypeLabel = [
    'car_front' => 'Front',
    'car_back' => 'Back',
    'car_left' => 'Left Side',
    'car_right' => 'Right Side',
    'fuel_image' => 'Fuel Gauge',
    'additional_image' => 'Additional'
];

/* ================= Pricing / totals ================= */
$day_count = (int)$booking['day_count'];
$daily_rate = (float)($booking['daily_rate'] ?? $booking['car_daily_rate'] ?? 0);
$security_deposit = (float)$booking['security_deposit'];
$rental_subtotal = $day_count * $daily_rate;

if ($booking['total_price'] !== null) {
    $stored_total = (float)$booking['total_price'];
    $computed_core_total = $rental_subtotal + $security_deposit + $delivery_fee;
    $total_price_final = $stored_total;
} else {
    $computed_core_total = $rental_subtotal + $security_deposit + $delivery_fee;
    $stored_total = null;
    $total_price_final = $computed_core_total;
}
$grand_plus_other = $total_price_final + $other_services_total;

/* ================= Status & flash ================= */
$status = strtolower((string)$booking['status']);
$flash_message = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message']);

$displayLabel = match($status) {
    'pending', 'waiting_verification' => 'Pending Approval',
    'approved' => 'Pending Payment',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'rejected' => 'Rejected',
    default => ucwords(str_replace('_',' ', (string)$status)),
};

/* ================= Deposit summary ================= */
$dep_original = $security_deposit;
$dep_deduction = (float)($booking['security_deposit_deduction'] ?? 0);
$dep_refund = (float)($booking['security_deposit_refund'] ?? max($dep_original - $dep_deduction, 0));
$dep_status = (string)($booking['deposit_status'] ?? 'held');
$dep_damage_desc = (string)($booking['deposit_damage_description'] ?? '');
$dep_status_label = match($dep_status) {
    'held' => 'Held',
    'pending_refund' => 'Pending Refund',
    'refunded' => 'Refunded',
    'forfeited' => 'Forfeited',
    default => ucfirst(str_replace('_',' ', $dep_status))
};

/* Deposit refund row */
$deposit_refund_row = null;
$refCode = 'DEP-' . $booking_id;
$refStmt = $conn->prepare("
    SELECT refund_id, amount, refund_status, notes, processed_at, created_at
    FROM refunds
    WHERE booking_id=? AND reference_code=?
    LIMIT 1
");
$refStmt->bind_param('is', $booking_id, $refCode);
$refStmt->execute();
$deposit_refund_row = $refStmt->get_result()->fetch_assoc();
$refStmt->close();

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Booking #<?= e((string)$booking_id) ?> - Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
/* Only key styles shown; adjust or merge with your global CSS */
body { background:#eceef4; font-family:'Inter',Arial,sans-serif; margin:0; }
.review-section { max-width:1150px; margin:40px auto 70px; background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(44,60,102,.09); padding:36px 44px; }
.review-title { font-size:1.6em; font-weight:800; color:#28306f; margin:0 0 24px; }
.flash-msg { background:#e6fcf3; color:#218c6d; padding:12px 20px; border-radius:9px; font-weight:600; margin:0 0 22px; }
.error-msg { background:#ffeded; color:#b62f2f; padding:12px 20px; border-radius:9px; font-weight:600; margin:0 0 22px; }
.section-label { margin:32px 0 14px; font-weight:800; font-size:1.05em; color:#3c4764; letter-spacing:.5px; }
.review-table { width:100%; border-collapse:collapse; margin-bottom:24px; font-size:.94em; }
.review-table th { background:#f3f5f9; padding:10px 14px; font-weight:600; width:210px; color:#33415d; border-bottom:1px solid #e3e7ef; text-align:left; }
.review-table td { padding:10px 14px; border-bottom:1px solid #eef1f6; color:#2a324a; }
.total { font-weight:700; color:#1d2c6b; }
.car-img-thumb { width:180px; height:110px; object-fit:cover; border-radius:8px; border:1px solid #d9dfea; background:#f2f4f9; display:block; margin-bottom:10px; }
.action-form-row { display:flex; flex-wrap:wrap; gap:14px; margin:0 0 28px; background:#f5f8fc; border:1px solid #e2e9f3; border-radius:12px; padding:18px 22px; }
.action-btn { padding:12px 30px; border-radius:9px; border:none; color:#fff; font-size:.9em; font-weight:700; cursor:pointer; transition:background .18s; display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
.action-btn.secondary { background:#677489; } .action-btn.secondary:hover { background:#536071; }
.action-btn.approve { background:#23c960; } .action-btn.approve:hover { background:#1ea753; }
.action-btn.reject { background:#e54848; } .action-btn.reject:hover { background:#b83232; }
.rejection-reason-input { padding:10px 16px; border:1.5px solid #d4deed; font-size:.85em; border-radius:8px; display:none; }
.confirm-reject-btn { display:none; }
.delivery-fee-panel { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-top:8px; }
.readonly-box { background:#f0f3f9; border:1.5px solid #d8e0ec; padding:10px 14px; border-radius:8px; font-size:.8em; font-weight:700; color:#2d3952; min-width:210px; }
.notice { font-size:.68em; color:#6e7c8a; margin-top:4px; line-height:1.3em; }
.badge-small { background:#eef2f7; padding:3px 7px; border-radius:6px; font-size:.6rem; font-weight:600; }
.img-grid { display:flex; flex-wrap:wrap; gap:14px; }
.img-thumb { width:120px; height:80px; object-fit:cover; border-radius:6px; border:1px solid #cfd6e4; cursor:pointer; background:#f7f9fc; transition:box-shadow .18s, transform .18s; }
.img-thumb:hover { box-shadow:0 4px 14px rgba(0,0,0,.18); transform:translateY(-2px); }
.img-missing { width:120px; height:80px; border:1px dashed #cfd6e4; border-radius:6px; font-size:.65rem; display:flex; align-items:center; justify-content:center; color:#7d8a9b; }
.deposit-status-label { font-weight:600; padding:4px 10px; border-radius:6px; font-size:.65rem; text-transform:uppercase; }
.deposit-held { background:#eef2f7; color:#445064; }
.deposit-pending_refund { background:#fff6d8; color:#9d7a00; }
.deposit-refunded { background:#e4fae8; color:#14773f; }
.deposit-forfeited { background:#ffe3e3; color:#b32828; }
.inspection-wrapper { margin-top:6px; }
.filter-bar { display:flex; gap:10px; flex-wrap:wrap; margin:6px 0 18px; }
.filter-btn { border:1px solid #cfd6e2; background:#f0f4f9; color:#2d3a53; padding:8px 16px; border-radius:8px; font-size:.7rem; font-weight:600; cursor:pointer; }
.filter-btn.active { background:#2d57d3; color:#fff; border-color:#2d57d3; }
.inspection-groups { display:flex; flex-direction:column; gap:30px; }
.inspection-group { background:#f7f9fc; border:1px solid #e1e6ef; border-radius:12px; padding:18px 20px 22px; }
.image-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:16px; }
.image-card { border:1px solid #d5dce7; background:#fff; border-radius:10px; padding:8px 8px 10px; display:flex; flex-direction:column; gap:6px; position:relative; }
.image-card img { width:100%; height:96px; object-fit:cover; border-radius:6px; border:1px solid #d0d7e3; cursor:pointer; background:#f0f4f9; }
.image-stamp { position:absolute; top:6px; left:6px; background:rgba(0,0,0,.55); color:#fff; font-size:.55rem; padding:3px 6px; border-radius:6px; font-weight:600; }
.image-type-label { font-size:.62rem; font-weight:700; color:#2f4165; }
.image-meta { font-size:.6rem; line-height:.95rem; color:#465065; }
.status-label { padding:6px 16px; border-radius:18px; font-weight:700; font-size:.68rem; text-transform:uppercase; }
.status-pending_approval { background:#fff6d8; color:#b28a00; }
.status-pending_payment { background:#ffe9d8; color:#b25900; }
.status-confirmed { background:#e5f1ff; color:#0a52a1; }
.status-completed { background:#e4fae8; color:#14773f; }
.status-cancelled,.status-rejected { background:#ffe3e3; color:#c03636; }
.back-btn { background:#cfd6e4; color:#23304d; border:none; padding:13px 38px; border-radius:9px; font-size:.95em; font-weight:700; display:block; margin:40px auto 6px; text-align:center; text-decoration:none; }
.back-btn:hover { background:#b8c2d4; }
.agreement-bar { display:flex; gap:14px; flex-wrap:wrap; align-items:center; background:#f4f7fb; border:1px solid #d9e2ee; padding:14px 18px; border-radius:10px; margin:4px 0 20px; }
.agreement-status { font-size:.7rem; font-weight:600; letter-spacing:.4px; padding:6px 12px; border-radius:20px; background:#eef2f8; color:#31405d; }
.agreement-status.missing { background:#ffe6e6; color:#a63a3a; }
.agreement-status.available { background:#e2f9e8; color:#18723d; }
.download-agreement-btn { background:#4158d0; color:#fff; padding:10px 20px; border:none; border-radius:8px; font-size:.72rem; font-weight:700; letter-spacing:.5px; text-decoration:none; display:inline-block; }
.download-agreement-btn:hover { background:#2f47b9; }
#imgModal { position:fixed; inset:0; background:rgba(16,26,44,.85); display:none; align-items:center; justify-content:center; z-index:9999; padding:40px 26px; }
#imgModal img { max-width:90vw; max-height:80vh; box-shadow:0 10px 28px rgba(0,0,0,.55); border-radius:10px; background:#fff; }
#imgModal .close-btn, #imgModal .nav-btn { position:absolute; background:#ffffff; border:none; padding:10px 16px; border-radius:8px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.25); font-size:.75rem; }
#imgModal .close-btn { top:14px; right:20px; }
#imgModal .nav-btn { top:50%; transform:translateY(-50%); }
#imgModal .prev-btn { left:18px; }
#imgModal .next-btn { right:18px; }
.modal-caption { margin-top:12px; font-size:.7rem; color:#eef3f8; text-align:center; max-width:86vw; line-height:1.2rem; font-weight:500; }
.modal-caption span { color:#ffdf6e; font-weight:600; }
@media (max-width:900px){
  .review-section{padding:30px 26px 48px;}
  .image-cards{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}
  .agreement-bar{flex-direction:column; align-items:flex-start;}
}
@media (max-width:560px){
  .image-cards{grid-template-columns:repeat(auto-fill,minmax(110px,1fr));}
}
</style>
</head>
<body>
<div class="review-section">
    <div class="review-title">Booking Details #<?= e((string)$booking_id) ?></div>

    <?php if ($flash_message): ?><div class="flash-msg"><?= e($flash_message) ?></div><?php endif; ?>
    <?php if ($action_error): ?><div class="error-msg"><?= e($action_error) ?></div><?php endif; ?>

    <?php if (in_array($status, ['pending','waiting_verification'], true)): ?>
        <form method="post" class="action-form-row" autocomplete="off" id="feeForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <div style="flex:1 1 100%;font-weight:700;color:#36415c;">Service</div>
            <div class="delivery-fee-panel" style="flex:1 1 100%;">
                <div>
                    <label>Service Type</label>
                    <div class="readonly-box"><?= e($delivery_type_display) ?></div>
                </div>
                <?php if ($service_mode === 'delivery' || $service_mode === 'pickup_and_return'): ?>
                    <div>
                        <label>Drop-off Location</label>
                        <div class="readonly-box"><?= e($delivery_location ?: '-') ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($service_mode === 'pickup_and_return'): ?>
                    <div>
                        <label>Pickup (Return) Location</label>
                        <div class="readonly-box"><?= e($return_location ?: '-') ?></div>
                    </div>
                <?php endif; ?>
                <div>
                    <label for="service_fee">Fee (RM)</label>
                    <input type="number" step="0.01" min="0" name="service_fee" id="service_fee"
                           value="<?= e($current_delivery_fee_raw) ?>"
                           placeholder="0.00"
                           <?= ($service_mode === 'delivery' || $service_mode === 'pickup_and_return') ? '' : 'disabled' ?>>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" name="booking_action" value="save_fee" class="action-btn secondary"
                        <?= ($service_mode === 'delivery' || $service_mode === 'pickup_and_return') ? '' : 'disabled' ?>>Save Fee</button>
                    <button type="submit" name="booking_action" value="approve" class="action-btn approve">Approve</button>
                    <button type="button" id="showRejectBtn" class="action-btn reject">Reject</button>
                    <input type="text" name="rejection_reason" id="rejectionReason" class="rejection-reason-input" placeholder="Enter rejection reason...">
                    <button type="submit" name="booking_action" value="reject" id="confirmRejectBtn" class="action-btn reject confirm-reject-btn">Confirm Reject</button>
                </div>
                <div style="flex:1 1 100%;margin-top:4px;">
                    <span class="notice">Self Pickup: approve directly (no fee). Delivery / Pickup & Return: set fee first.</span>
                </div>
            </div>
        </form>
    <?php elseif ($status === 'approved'): ?>
        <div class="action-form-row" style="justify-content:space-between;align-items:center;">
            <div style="font-weight:600;color:#344050;">Status: Pending Payment (awaiting customer payment)</div>
        </div>
    <?php elseif (!in_array($status, ['cancelled','rejected','completed'], true)): ?>
        <div class="section-label" style="margin-top:0;">E-Inspection</div>
        <div class="delivery-fee-panel" style="margin-bottom:12px;">
            <a class="readonly-box" style="text-decoration:none;cursor:pointer;background:<?= $pickup_filled ? '#def6e6':'#f3f5f9'; ?>;"
               href="inspection_add.php?booking_id=<?= $booking_id ?>&type=pickup">
               <?= $pickup_filled ? 'Pickup Completed':'Pickup Inspection' ?>
            </a>
            <a class="readonly-box" style="text-decoration:none;cursor:pointer;background:<?= $return_inspection_done ? '#def6e6':'#f3f5f9'; ?>;"
               href="inspection_add.php?booking_id=<?= $booking_id ?>&type=return">
               <?= $return_inspection_done ? 'Return Completed':'Return Inspection' ?>
            </a>
        </div>
    <?php endif; ?>

    <!-- Agreement Download Section (always visible) -->
    <div class="agreement-bar">
        <div class="agreement-status <?= $agreement_download_link ? 'available' : 'missing' ?>">
            <?= $agreement_download_link ? 'Agreement Available' : 'Agreement Missing' ?>
        </div>
        <?php if ($agreement_download_link): ?>
            <a href="<?= e($agreement_download_link) ?>" target="_blank" class="download-agreement-btn">
                Download Agreement
            </a>
        <?php else: ?>
            <div style="font-size:.7rem;color:#6a7485;font-weight:600;">
                No agreement record found. Generate/upload agreement in the Agreement Form section first.
            </div>
        <?php endif; ?>
    </div>

    <div class="section-label">Car & Booking</div>
    <table class="review-table">
        <tr>
            <th>Car</th>
            <td>
                <?php if ($car_image_blob): ?>
                    <img class="car-img-thumb" src="data:image/jpeg;base64,<?= base64_encode($car_image_blob) ?>" alt="Car">
                <?php else: ?>
                    <img class="car-img-thumb" src="/assets/images/no-car.png" alt="No Image">
                <?php endif; ?>
                <?= e($booking['car_brand'].' '.$booking['car_model']) ?>
            </td>
        </tr>
        <tr><th>Day Count</th><td><?= $day_count ?> day(s)</td></tr>
        <tr><th>Daily Rate (Snapshot)</th><td>RM <?= number_format($daily_rate,2) ?></td></tr>
        <tr><th>Pickup</th><td><?= e($booking['pickup_datetime']) ?></td></tr>
        <tr><th>Return</th><td><?= e($booking['return_datetime']) ?></td></tr>
        <tr><th>Service Type</th><td><?= e($delivery_type_display) ?></td></tr>
        <?php if ($service_mode === 'delivery' || $service_mode === 'pickup_and_return'): ?>
            <tr><th>Drop-off Location</th><td><?= e($delivery_location ?: '-') ?></td></tr>
        <?php endif; ?>
        <?php if ($service_mode === 'pickup_and_return'): ?>
            <tr><th>Pickup (Return) Location</th><td><?= e($return_location ?: '-') ?></td></tr>
        <?php endif; ?>
        <tr><th>Service Fee</th><td>RM <?= number_format($delivery_fee,2) ?></td></tr>
        <tr><th>Rental Subtotal</th><td>RM <?= number_format($rental_subtotal,2) ?></td></tr>
        <tr><th>Security Deposit</th><td>RM <?= number_format($security_deposit,2) ?></td></tr>
        <tr><th>Core Total (Rent+Deposit+Service Fee)</th><td>RM <?= number_format($computed_core_total,2) ?></td></tr>
        <?php if ($other_services_total > 0): ?>
            <tr><th>Other Services (Add-on)</th><td>RM <?= number_format($other_services_total,2) ?></td></tr>
        <?php endif; ?>
        <tr>
            <th class="total"><?= $other_services_total > 0 ? 'Grand Total (Incl. Add-ons)' : 'Grand Total' ?></th>
            <td class="total">RM <?= number_format($other_services_total > 0 ? $grand_plus_other : $computed_core_total, 2) ?></td>
        </tr>
        <?php if (isset($stored_total) && $stored_total !== null && abs($stored_total - $computed_core_total) > 0.009): ?>
            <tr>
                <th>Stored Total (DB)</th>
                <td style="color:#c03636;font-weight:600;">
                    RM <?= number_format($stored_total,2) ?>
                    <div style="font-size:.7rem;color:#666;margin-top:4px;">Differs from current computed total.</div>
                </td>
            </tr>
        <?php elseif (isset($stored_total) && $stored_total !== null): ?>
            <tr><th>Stored Total (DB)</th><td>RM <?= number_format($stored_total,2) ?></td></tr>
        <?php endif; ?>
        <tr>
            <th>Status</th>
            <td>
                <?php
                  $cls = match($status) {
                      'pending','waiting_verification' => 'status-pending_approval',
                      'approved' => 'status-pending_payment',
                      'confirmed' => 'status-confirmed',
                      'completed' => 'status-completed',
                      'cancelled' => 'status-cancelled',
                      'rejected' => 'status-rejected',
                      default => 'status-pending_approval',
                  };
                ?>
                <span class="status-label <?= e($cls) ?>"><?= e($displayLabel) ?></span>
            </td>
        </tr>
        <?php if ($status === 'rejected' && !empty($booking['rejection_reason'])): ?>
            <tr><th>Rejection Reason</th><td style="color:#c03636;font-weight:600;"><?= e($booking['rejection_reason']) ?></td></tr>
        <?php endif; ?>
        <?php if ($status === 'cancelled' && !empty($booking['cancellation_reason'])): ?>
            <tr><th>Cancellation Reason</th><td style="color:#c03636;"><?= e($booking['cancellation_reason']) ?></td></tr>
        <?php endif; ?>
        <tr><th>Created At</th><td><?= e($booking['created_at']) ?></td></tr>
        <?php if (!empty($booking['approved_at'])): ?><tr><th>Approved At</th><td><?= e($booking['approved_at']) ?></td></tr><?php endif; ?>
        <?php if (!empty($booking['confirmed_at'])): ?><tr><th>Confirmed At</th><td><?= e($booking['confirmed_at']) ?></td></tr><?php endif; ?>
    </table>

    <!-- Security Deposit Summary -->
    <div class="section-label">Security Deposit</div>
    <table class="review-table">
        <tr><th>Original Deposit</th><td>RM <?= number_format($dep_original,2) ?></td></tr>
        <tr><th>Damage Deduction</th><td>RM <?= number_format($dep_deduction,2) ?></td></tr>
        <tr><th>Refundable</th><td>RM <?= number_format($dep_refund,2) ?></td></tr>
        <tr>
            <th>Deposit Status</th>
            <td>
                <?php
                  $depCls = 'deposit-held';
                  if ($dep_status === 'pending_refund') $depCls = 'deposit-pending_refund';
                  elseif ($dep_status === 'refunded') $depCls = 'deposit-refunded';
                  elseif ($dep_status === 'forfeited') $depCls = 'deposit-forfeited';
                ?>
                <span class="deposit-status-label <?= e($depCls) ?>"><?= e($dep_status_label) ?></span>
                <?php if (!empty($booking['deposit_last_adjusted_at'])): ?>
                    <div style="font-size:.65rem;color:#566273;margin-top:4px;">Last Adjusted: <?= e($booking['deposit_last_adjusted_at']) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($dep_deduction > 0 && $dep_damage_desc): ?>
            <tr><th>Damage Description</th><td><?= nl2br(e($dep_damage_desc)) ?></td></tr>
        <?php endif; ?>
        <?php if ($deposit_refund_row): ?>
            <tr>
                <th>Refund Record</th>
                <td>
                    Amount: RM <?= number_format((float)$deposit_refund_row['amount'],2) ?>
                    | Status: <?= e($deposit_refund_row['refund_status']) ?>
                    <?php if (!empty($deposit_refund_row['notes'])): ?>
                        <div style="font-size:.7rem;color:#57657b;margin-top:4px;"><?= e($deposit_refund_row['notes']) ?></div>
                    <?php endif; ?>
                    <div style="font-size:.65rem;color:#4c5a69;margin-top:2px;">
                        Created: <?= e($deposit_refund_row['created_at']) ?>
                        <?php if ($deposit_refund_row['processed_at']): ?>
                            | Processed: <?= e($deposit_refund_row['processed_at']) ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endif; ?>
    </table>

    <!-- Customer -->
    <div class="section-label">Customer</div>
    <table class="review-table">
        <tr><th>Name</th><td><?= e($booking['customer_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= e($booking['customer_phone']) ?></td></tr>
        <tr><th>Email</th><td><?= e($booking['customer_email']) ?></td></tr>
        <tr><th>ID No</th><td><?= e($booking['customer_id_no']) ?></td></tr>
        <tr><th>ID Images</th><td>
            <div class="img-grid">
                <?= imgTag($booking['cust_id_front_image'] ?? null, 'Customer ID Front') ?>
                <?= imgTag($booking['cust_id_back_image'] ?? null, 'Customer ID Back') ?>
            </div>
        </td></tr>
        <tr><th>License Images</th><td>
            <div class="img-grid">
                <?= imgTag($booking['cust_license_front_image'] ?? null, 'License Front') ?>
                <?= imgTag($booking['cust_license_back_image'] ?? null, 'License Back') ?>
            </div>
        </td></tr>
    </table>

    <!-- Guarantor -->
    <?php if ($guarantor): ?>
        <div class="section-label">Guarantor</div>
        <table class="review-table">
            <tr><th>Name</th><td><?= e($guarantor['full_name']) ?></td></tr>
            <tr><th>Phone</th><td><?= e($guarantor['phone_no']) ?></td></tr>
            <tr><th>ID No</th><td><?= e($guarantor['id_no']) ?></td></tr>
            <tr><th>Relationship</th><td><?= e($guarantor['relationship']) ?></td></tr>
            <tr><th>ID Images</th><td>
                <div class="img-grid">
                    <?= imgTag($guarantor['id_front_image'] ?? null, 'Guarantor ID Front') ?>
                    <?= imgTag($guarantor['id_back_image'] ?? null, 'Guarantor ID Back') ?>
                </div>
            </td></tr>
        </table>
    <?php endif; ?>

    <!-- Services -->
    <?php if (!empty($services)): ?>
        <div class="section-label">All Services</div>
        <table class="review-table">
            <tr><th>Type</th><th style="width:120px;">Fee (RM)</th><th>Location Info</th></tr>
            <?php foreach ($services as $s): ?>
                <tr>
                    <td>
                        <?php
                          $stype = $s['service_type'];
                          $label = match($stype) {
                              'delivery' => 'Delivery (Drop-off Only)',
                              'pickup_and_return' => 'Pickup & Return',
                              'self_pickup' => 'Pickup Myself (Free)',
                              default => ucwords(str_replace('_',' ', $stype))
                          };
                          echo e($label);
                        ?>
                        <?php if (in_array($s['service_type'], ['delivery','pickup_and_return'], true)): ?>
                            <span class="badge-small">Core</span>
                        <?php elseif ($s['service_type'] === 'self_pickup'): ?>
                            <span class="badge-small" style="background:#e4fae8;color:#14773f;">Free</span>
                        <?php else: ?>
                            <span class="badge-small" style="background:#f0eefa;color:#5a468c;">Addon</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;"><?= number_format((float)$s['fee'],2) ?></td>
                    <td>
                        <?php
                          $locParts = [];
                          if (!empty($s['delivery_location'])) $locParts[] = "Drop-off: ".$s['delivery_location'];
                          if (!empty($s['return_location']))   $locParts[] = "Pickup: ".$s['return_location'];
                          echo e($locParts ? implode(' | ', $locParts) : '-');
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <!-- Inspection Images -->
    <div class="section-label" id="inspection-images-anchor">Inspection Images</div>
    <div class="inspection-wrapper">
        <div class="filter-bar">
            <button type="button" class="filter-btn active" data-filter="all">All</button>
            <button type="button" class="filter-btn" data-filter="pickup">Pickup</button>
            <button type="button" class="filter-btn" data-filter="return">Return</button>
        </div>

        <?php if (empty($pickupImages) && empty($returnImages)): ?>
            <div class="notice">No inspection images have been uploaded yet.</div>
        <?php else: ?>
            <div class="inspection-groups">
                <!-- Pickup Group -->
                <div class="inspection-group" data-group="pickup">
                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 12px;">
                        <div style="font-weight:800;color:#2d3a5f;font-size:1em;display:flex;align-items:center;gap:10px;">
                            Pickup Inspection
                            <?php
                              $badgeClass = $pickup_filled
                                  ? (count($pickupMissing) === 0 ? 'badge-complete' : 'badge-partial')
                                  : 'badge-incomplete';
                              $badgeText = $pickup_filled
                                  ? (count($pickupMissing) === 0 ? 'Complete' : 'Partial')
                                  : 'Incomplete';
                            ?>
                            <span style="padding:4px 10px;border-radius:20px;font-size:.55rem;font-weight:700;letter-spacing:.5px;"
                                  class="<?= e($badgeClass) ?>"><?= e($badgeText) ?></span>
                        </div>
                        <?php if ($pickup_filled): ?>
                            <div style="font-size:.6rem;color:#4d5a6d;font-weight:600;">
                                Mileage: <?= e($booking['pickup_mileage'] ?? '-') ?> |
                                Fuel: <?= e($booking['pickup_fuel_percent'] ?? '-') ?>%
                            </div>
                        <?php endif; ?>
                        <?php if (count($pickupMissing) > 0): ?>
                            <div style="font-size:.62rem;color:#a03b3b;font-weight:600;">
                                Missing: <?= e(implode(', ', array_map(fn($t)=>$imageTypeLabel[$t]??$t, $pickupMissing))) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($pickupImages)): ?>
                        <div class="image-cards">
                            <?php foreach ($pickupImages as $idx => $im):
                                $itype = strtolower($im['image_type']);
                                $label = $imageTypeLabel[$itype] ?? ucfirst(str_replace('_',' ',$itype));
                                $capDate = $im['inspection_date'] ? date('Y-m-d H:i', strtotime($im['inspection_date'])) : '';
                                $uploaded = date('Y-m-d H:i', strtotime($im['uploaded_at']));
                                $caption = 'Pickup • '.$label.($capDate?' • Inspected '.$capDate:'').' • Uploaded '.$uploaded;
                                $dataIndex = "pickup-".$idx;
                            ?>
                            <div class="image-card">
                                <div class="image-stamp">P</div>
                                <img src="data:image/jpeg;base64,<?= base64_encode($im['image_path']) ?>"
                                     alt="<?= e('Pickup - '.$label) ?>"
                                     data-full="data:image/jpeg;base64,<?= base64_encode($im['image_path']) ?>"
                                     data-caption="<?= e($caption) ?>"
                                     data-index="<?= e($dataIndex) ?>">
                                <div class="image-type-label"><?= e($label) ?></div>
                                <div class="image-meta">
                                    <?php if ($capDate): ?><div>Inspected: <?= e($capDate) ?></div><?php endif; ?>
                                    <div>Uploaded: <?= e($uploaded) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="notice">No pickup images.</div>
                    <?php endif; ?>
                </div>

                <!-- Return Group -->
                <div class="inspection-group" data-group="return">
                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 12px;">
                        <div style="font-weight:800;color:#2d3a5f;font-size:1em;display:flex;align-items:center;gap:10px;">
                            Return Inspection
                            <?php
                              $badgeClassR = $return_inspection_done
                                  ? (count($returnMissing) === 0 ? 'badge-complete' : 'badge-partial')
                                  : 'badge-incomplete';
                              $badgeTextR = $return_inspection_done
                                  ? (count($returnMissing) === 0 ? 'Complete' : 'Partial')
                                  : 'Incomplete';
                            ?>
                            <span style="padding:4px 10px;border-radius:20px;font-size:.55rem;font-weight:700;letter-spacing:.5px;"
                                  class="<?= e($badgeClassR) ?>"><?= e($badgeTextR) ?></span>
                        </div>
                        <?php if ($return_inspection_done): ?>
                            <div style="font-size:.6rem;color:#4d5a6d;font-weight:600;">
                                Mileage: <?= e($booking['return_mileage'] ?? '-') ?> |
                                Fuel: <?= e($booking['return_fuel_percent'] ?? '-') ?>%
                            </div>
                        <?php endif; ?>
                        <?php if (count($returnMissing) > 0): ?>
                            <div style="font-size:.62rem;color:#a03b3b;font-weight:600;">
                                Missing: <?= e(implode(', ', array_map(fn($t)=>$imageTypeLabel[$t]??$t, $returnMissing))) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($returnImages)): ?>
                        <div class="image-cards">
                            <?php foreach ($returnImages as $idx => $im):
                                $itype = strtolower($im['image_type']);
                                $label = $imageTypeLabel[$itype] ?? ucfirst(str_replace('_',' ',$itype));
                                $capDate = $im['inspection_date'] ? date('Y-m-d H:i', strtotime($im['inspection_date'])) : '';
                                $uploaded = date('Y-m-d H:i', strtotime($im['uploaded_at']));
                                $caption = 'Return • '.$label.($capDate?' • Inspected '.$capDate:'').' • Uploaded '.$uploaded;
                                $dataIndex = "return-".$idx;
                            ?>
                            <div class="image-card">
                                <div class="image-stamp" style="background:rgba(24,90,180,.65);">R</div>
                                <img src="data:image/jpeg;base64,<?= base64_encode($im['image_path']) ?>"
                                     alt="<?= e('Return - '.$label) ?>"
                                     data-full="data:image/jpeg;base64,<?= base64_encode($im['image_path']) ?>"
                                     data-caption="<?= e($caption) ?>"
                                     data-index="<?= e($dataIndex) ?>">
                                <div class="image-type-label"><?= e($label) ?></div>
                                <div class="image-meta">
                                    <?php if ($capDate): ?><div>Inspected: <?= e($capDate) ?></div><?php endif; ?>
                                    <div>Uploaded: <?= e($uploaded) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="notice">No return images.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="notice" style="margin-top:18px;">
        Click ANY thumbnail (car, ID, license, guarantor, inspection) to view full size. Use arrow keys or buttons to navigate.
    </div>

    <a class="back-btn" href="bookings.php">Back to Bookings</a>
</div>

<!-- Modal -->
<div id="imgModal">
    <button type="button" class="close-btn" id="closeModalBtn">Close (Esc)</button>
    <button type="button" class="nav-btn prev-btn nav-prev" id="prevImgBtn">&#10094; Prev</button>
    <button type="button" class="nav-btn next-btn nav-next" id="nextImgBtn">Next &#10095;</button>
    <div style="display:flex;flex-direction:column;align-items:center;">
        <img id="modalImg" src="" alt="Full Image">
        <div class="modal-caption" id="modalCaption"></div>
    </div>
</div>

<script>
(function(){
  // Reject toggle
  const showRejectBtn = document.getElementById('showRejectBtn');
  if (showRejectBtn){
    const rejectInput = document.getElementById('rejectionReason');
    const confirmBtn = document.getElementById('confirmRejectBtn');
    showRejectBtn.addEventListener('click', ()=>{
      showRejectBtn.style.display='none';
      rejectInput.style.display='inline-block';
      confirmBtn.style.display='inline-flex';
      rejectInput.focus();
    });
    confirmBtn.addEventListener('click', e=>{
      if(!rejectInput.value.trim()){
        e.preventDefault();
        rejectInput.style.borderColor='#e54848';
        rejectInput.focus();
      }
    });
    rejectInput.addEventListener('input', ()=> rejectInput.style.borderColor='#d4deed');
    const form = document.getElementById('feeForm');
    if(form){
      form.addEventListener('submit', ev=>{
        const btnVal = ev.submitter ? ev.submitter.value : '';
        const feeEl = document.getElementById('service_fee');
        if((btnVal==='approve' || btnVal==='save_fee') && feeEl && !feeEl.disabled){
          const v = feeEl.value.trim();
          if(v==='' || isNaN(v)){
            alert('Please enter a numeric fee.');
            ev.preventDefault();
          }
        }
      });
    }
  }

  // Filter groups
  document.querySelectorAll('.filter-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      const val = btn.getAttribute('data-filter');
      document.querySelectorAll('.inspection-group').forEach(g=>{
        g.style.display = (val==='all' || g.getAttribute('data-group')===val) ? '' : 'none';
      });
    });
  });

  // Build gallery
  const modal = document.getElementById('imgModal');
  const modalImg = document.getElementById('modalImg');
  const modalCaption = document.getElementById('modalCaption');
  const closeBtn = document.getElementById('closeModalBtn');
  const prevBtn = document.getElementById('prevImgBtn');
  const nextBtn = document.getElementById('nextImgBtn');
  let gallery = [];
  let current = -1;

  function buildGallery(){
    gallery = [];
    let idx=0;
    // Inspection images
    document.querySelectorAll('.image-card img').forEach(img=>{
      const src = img.getAttribute('data-full') || img.src;
      const caption = img.getAttribute('data-caption') || img.alt || 'Inspection Image';
      const marker = 'ins-'+idx;
      img.dataset.galleryIndex = marker;
      gallery.push({src, caption, marker});
      idx++;
    });
    // Other doc thumbnails (car, id, license, guarantor)
    document.querySelectorAll('.img-grid .img-thumb, .car-img-thumb').forEach(img=>{
      if (img.dataset.galleryIndex) return;
      if (!(img instanceof HTMLImageElement)) return;
      const src = img.getAttribute('data-full') || img.src;
      const caption = img.alt || 'Image';
      const marker = 'doc-'+idx;
      img.dataset.galleryIndex = marker;
      gallery.push({src, caption, marker});
      idx++;
    });
  }
  buildGallery();

  function openByIndex(i){
    if(i<0 || i>=gallery.length) return;
    current=i;
    const item=gallery[i];
    modalImg.src=item.src;
    modalCaption.innerHTML='<span>'+(i+1)+' / '+gallery.length+'</span> &nbsp;'+item.caption;
    modal.style.display='flex';
  }
  function openByMarker(m){
    const i=gallery.findIndex(g=>g.marker===m);
    if(i!==-1) openByIndex(i);
  }
  function closeModal(){
    modal.style.display='none';
    modalImg.src='';
    current=-1;
  }
  function next(){
    if(current===-1) return;
    openByIndex((current+1)%gallery.length);
  }
  function prev(){
    if(current===-1) return;
    openByIndex((current-1+gallery.length)%gallery.length);
  }

  document.addEventListener('click', e=>{
    const img = e.target.closest('img[data-gallery-index], .image-card img');
    if (img && img.dataset.galleryIndex){
      openByMarker(img.dataset.galleryIndex);
      return;
    }
    if (e.target===modal || e.target===closeBtn) closeModal();
    else if (e.target===prevBtn) prev();
    else if (e.target===nextBtn) next();
  });

  document.addEventListener('keyup', e=>{
    if (modal.style.display==='flex'){
      if (e.key==='Escape') closeModal();
      if (e.key==='ArrowRight') next();
      if (e.key==='ArrowLeft') prev();
    }
  });
})();
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>