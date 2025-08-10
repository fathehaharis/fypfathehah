<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Autoload PHPMailer via Composer
require '../vendor/autoload.php';

include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['staff_id'])) {
    header("Location: delivery_staff_login.php");
    exit;
}

$staff_id = $_SESSION['staff_id'];
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($booking_id < 1) die('Invalid Booking ID');

// Get booking, car, customer
$sql = "
SELECT 
    b.*, 
    c.car_brand, c.car_model, c.plate_no,
    cu.full_name AS customer_name, cu.phone_no AS customer_phone, cu.email AS customer_email, cu.address AS customer_address
FROM booking b
LEFT JOIN car c ON b.car_id = c.car_id
LEFT JOIN customer cu ON b.cust_id = cu.cust_id
WHERE b.booking_id = ?
LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$booking) die("Booking not found.");

// Get service rows
$service_rows = [];
$service_stmt = $conn->prepare("SELECT * FROM service WHERE booking_id = ?");
$service_stmt->bind_param("i", $booking_id);
$service_stmt->execute();
$res = $service_stmt->get_result();
while ($row = $res->fetch_assoc()) $service_rows[] = $row;
$service_stmt->close();

// Get guarantor (if exists)
$guarantor = null;
$gstmt = $conn->prepare("
    SELECT g.* 
    FROM agreement_form af
    JOIN guarantor g ON af.guarantor_id = g.guarantor_id
    WHERE af.booking_id = ?
    LIMIT 1");
$gstmt->bind_param("i", $booking_id);
$gstmt->execute();
$gres = $gstmt->get_result();
if ($gres && $gres->num_rows) $guarantor = $gres->fetch_assoc();
$gstmt->close();

// Handle service status update and notify customer
$status_updated = false;
$email_sent = false;
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'], $_POST['service_id'])) {
    $service_id = intval($_POST['service_id']);
    $new_status = $_POST['status'];
    $allowed_statuses = ['pending', 'out_for_delivery', 'delivered'];
    if (in_array($new_status, $allowed_statuses)) {
        // Update only if this service belongs to the logged-in staff
        $stmt = $conn->prepare("UPDATE service SET status = ? WHERE service_id = ? AND staff_id = ?");
        $stmt->bind_param("sii", $new_status, $service_id, $staff_id);
        if ($stmt->execute()) {
            $status_updated = true;

            // Send email notification to customer using PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'fathehaharis69@gmail.com'; // Your SMTP username
                $mail->Password   = 'cuel ijeu lzqv vsgv';      // Your SMTP password or app password
                $mail->SMTPSecure = 'tls';
                $mail->Port       = 587;

                $mail->setFrom('no-reply@timelesscarrental.com', 'Timeless Car Rental');
                $mail->addAddress($booking['customer_email'], $booking['customer_name']);
                $mail->isHTML(true);

                $status_text = ucwords(str_replace('_', ' ', $new_status));
                $body = "
                    <h2>Service Status Update</h2>
                    <p>Dear {$booking['customer_name']},</p>
                    <p>The status of your <strong>{$service_id}</strong> service for booking <b>#{$booking_id}</b> has been updated to: <strong style='font-size:1.2em;'>{$status_text}</strong>.</p>
                    <p>If you have any questions, please contact us.<br>Thank you for using Timeless Car Rental!</p>
                ";
                $mail->Subject = "Service Status for Booking #{$booking_id} Updated";
                $mail->Body    = $body;
                $mail->AltBody = "Service status for booking #{$booking_id} updated to: {$status_text}";

                $mail->send();
                $email_sent = true;
            } catch (Exception $e) {
                $error_msg = "Status updated, but email could not be sent. Mailer Error: " . htmlspecialchars($mail->ErrorInfo);
            }
        } else {
            $error_msg = "Failed to update service status.";
        }
        $stmt->close();
        // Refresh service rows after update
        $service_rows = [];
        $service_stmt = $conn->prepare("SELECT * FROM service WHERE booking_id = ?");
        $service_stmt->bind_param("i", $booking_id);
        $service_stmt->execute();
        $res = $service_stmt->get_result();
        while ($row = $res->fetch_assoc()) $service_rows[] = $row;
        $service_stmt->close();
    } else {
        $error_msg = "Invalid status.";
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
        .details-container { max-width: 900px; margin:40px auto 0 auto; background:#fff; border-radius:12px; box-shadow:0 2px 15px #e0e7ef44; padding:38px 32px; }
        .section-title { font-size:1.35em; font-weight:700; color:#2b5cbc; margin-top:32px;}
        .details-table { width:100%; border-collapse:collapse; margin-top:14px;}
        .details-table td, .details-table th { padding:10px 8px; border-bottom:1px solid #eee; }
        .details-table th { background:#f7fafd; color:#234c96; font-weight:700;}
        .details-table td.title { color:#234c96; font-weight:700; width:180px;}
        .details-table tr:last-child td { border-bottom:none;}
        .guarantor-box { background:#f9f8f7; border-radius:7px; padding:12px 17px; margin:10px 0;}
        .service-list { margin-top:10px;}
        .service-row { background:#f7fafd; border-radius:7px; padding:12px 17px; margin:8px 0;}
        .status-form select, .status-form button {
            padding: 7px 10px;
            border-radius: 7px;
            border: 1.5px solid #b5bee5;
            font-size: 1em;
            background: #f7fafd;
            margin-right: 5px;
        }
        .status-form button {
            background: #2b5cbc;
            color: #fff;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.14s;
        }
        .status-form button:hover {
            background: #243570;
        }
        .msg-success { color: #219150; margin-bottom: 16px;}
        .msg-error { color: #d42d2d; margin-bottom: 16px;}
        @media (max-width:700px) {
            .details-container { padding:14px 4vw;}
            .details-table td.title { width:110px;}
        }
    </style>
</head>
<body>
<div class="details-container">
    <h2>Booking Details #<?= htmlspecialchars($booking['booking_id']) ?></h2>
    <?php if ($status_updated): ?>
        <div class="msg-success">
            Service status updated successfully.
            <?php if ($email_sent): ?>
                Email notification sent to customer.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="msg-error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>
    <div class="section-title">Customer Info</div>
    <table class="details-table">
        <tr>
            <td class="title">Name</td>
            <td><?= htmlspecialchars($booking['customer_name']) ?></td>
        </tr>
        <tr>
            <td class="title">Phone</td>
            <td><?= htmlspecialchars($booking['customer_phone']) ?></td>
        </tr>
        <tr>
            <td class="title">Email</td>
            <td><?= htmlspecialchars($booking['customer_email']) ?></td>
        </tr>
        <tr>
            <td class="title">Address</td>
            <td><?= htmlspecialchars($booking['customer_address']) ?></td>
        </tr>
    </table>

    <?php if ($guarantor): ?>
    <div class="section-title">Guarantor Info</div>
    <div class="guarantor-box">
        <strong>Name:</strong> <?= htmlspecialchars($guarantor['full_name']) ?><br>
        <strong>Phone:</strong> <?= htmlspecialchars($guarantor['phone_no']) ?><br>
        <strong>Relationship:</strong> <?= htmlspecialchars($guarantor['relationship']) ?>
    </div>
    <?php endif; ?>

    <div class="section-title">Car Info</div>
    <table class="details-table">
        <tr>
            <td class="title">Brand & Model</td>
            <td><?= htmlspecialchars($booking['car_brand'] . ' ' . $booking['car_model']) ?></td>
        </tr>
        <tr>
            <td class="title">Plate No</td>
            <td><?= htmlspecialchars($booking['plate_no']) ?></td>
        </tr>
    </table>

    <div class="section-title">Booking Details</div>
    <table class="details-table">
        <tr>
            <td class="title">Pickup Date</td>
            <td><?= $booking['pickup_datetime'] ? date('d/m/Y H:i', strtotime($booking['pickup_datetime'])) : '-' ?></td>
        </tr>
        <tr>
            <td class="title">Return Date</td>
            <td><?= $booking['return_datetime'] ? date('d/m/Y H:i', strtotime($booking['return_datetime'])) : '-' ?></td>
        </tr>
        <tr>
            <td class="title">Day Count</td>
            <td><?= htmlspecialchars($booking['day_count']) ?></td>
        </tr>
        <tr>
            <td class="title">Status</td>
            <td><?= htmlspecialchars($booking['status']) ?></td>
        </tr>
        <tr>
            <td class="title">Total Price</td>
            <td>RM <?= number_format($booking['total_price'], 2) ?></td>
        </tr>
        <tr>
            <td class="title">Security Deposit</td>
            <td>RM <?= number_format($booking['security_deposit'], 2) ?></td>
        </tr>
    </table>

    <div class="section-title">Service Assignment</div>
    <div class="service-list">
        <?php foreach ($service_rows as $s): ?>
        <div class="service-row">
            <form method="post" class="status-form" style="display:inline;">
                <input type="hidden" name="service_id" value="<?= $s['service_id'] ?>">
                <strong>Type:</strong> <?= htmlspecialchars($s['service_type']) ?><br>
                <strong>Status:</strong>
                <select name="status">
                    <option value="pending" <?= $s['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="out_for_delivery" <?= $s['status'] == 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                    <option value="delivered" <?= $s['status'] == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                </select>
                <button type="submit" name="update_status">Update</button>
            </form>
            <br>
            <strong>Fee:</strong> RM <?= number_format($s['fee'], 2) ?><br>
            <?php if ($s['delivery_location']): ?><strong>Delivery Location:</strong> <?= htmlspecialchars($s['delivery_location']) ?><br><?php endif; ?>
            <?php if ($s['return_location']): ?><strong>Return Location:</strong> <?= htmlspecialchars($s['return_location']) ?><br><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:40px;">
        <a href="javascript:history.back()" style="color:#2b5cbc;text-decoration:underline;">&larr; Back to Dashboard</a>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
</body>
</html>