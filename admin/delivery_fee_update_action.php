<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// TODO: Replace with your real admin auth
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

if (empty($_POST['booking_id']) || !ctype_digit($_POST['booking_id'])) {
    die("Invalid booking_id.");
}
$booking_id = (int)$_POST['booking_id'];
$raw_fee    = isset($_POST['delivery_fee']) && $_POST['delivery_fee'] !== '' ? trim($_POST['delivery_fee']) : null;
$autoApprove = !empty($_POST['auto_approve']);

if ($raw_fee !== null && !is_numeric($raw_fee)) {
    die("Fee must be numeric.");
}
$fee = ($raw_fee === null) ? null : round((float)$raw_fee, 2);
if ($fee !== null && $fee < 0) {
    die("Fee cannot be negative.");
}

require '../connect.php';

// 1. Fetch booking (for re-calc) & existing delivery service row
$stmt = $conn->prepare("
    SELECT b.booking_id, b.day_count, b.daily_rate, b.security_deposit, b.total_price, b.status,
           s.service_id, s.service_type, s.fee AS current_fee
    FROM booking b
    LEFT JOIN service s
      ON s.booking_id = b.booking_id
     AND s.service_type IN ('delivery','pickup_and_return')
    WHERE b.booking_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$res = $stmt->get_result();
$booking = $res->fetch_assoc();
$stmt->close();

if (!$booking) {
    die("Booking not found.");
}

// 2. Recalc base total
$base_total = ($booking['day_count'] * $booking['daily_rate']) + $booking['security_deposit'];
$new_total  = $base_total + ($fee !== null ? $fee : 0.00);

// 3. Transaction: upsert service row & update booking total
$conn->begin_transaction();
try {
    if ($booking['service_id']) {
        // Existing row
        $upd = $conn->prepare("UPDATE service SET fee = ?, updated_at = NOW() WHERE service_id = ?");
        // If removing fee, set to NULL
        if ($fee === null) {
            $null = null;
            $upd = $conn->prepare("UPDATE service SET fee = NULL, updated_at = NOW() WHERE service_id = ?");
            $upd->bind_param("i", $booking['service_id']);
        } else {
            $upd->bind_param("di", $fee, $booking['service_id']);
        }
        $upd->execute();
        if ($upd->error) throw new Exception("Service update failed: ".$upd->error);
        $upd->close();
    } else {
        // No row: create one if fee provided, else skip
        if ($fee !== null) {
            $serv_type = 'delivery';
            $status    = 'pending'; // or 'approved' depending on your internal logic
            $ins = $conn->prepare("
                INSERT INTO service (booking_id, service_type, fee, status, created_at)
                VALUES (?,?,?,?,NOW())
            ");
            $ins->bind_param("isds", $booking_id, $serv_type, $fee, $status);
            $ins->execute();
            if ($ins->error) throw new Exception("Service insert failed: ".$ins->error);
            $ins->close();
        }
    }

    // 4. Update booking total_price (and optional status)
    if ($autoApprove) {
        $bUpd = $conn->prepare("UPDATE booking SET total_price = ?, status='approved' WHERE booking_id = ?");
        $bUpd->bind_param("di", $new_total, $booking_id);
    } else {
        $bUpd = $conn->prepare("UPDATE booking SET total_price = ? WHERE booking_id = ?");
        $bUpd->bind_param("di", $new_total, $booking_id);
    }
    $bUpd->execute();
    if ($bUpd->error) throw new Exception("Booking update failed: ".$bUpd->error);
    $bUpd->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    die("Update failed: ".$e->getMessage());
}

// 5. Redirect back to edit page (or admin booking view)
header("Location: delivery_fee_edit.php?booking_id=".$booking_id."&updated=1");
exit;