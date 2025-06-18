<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// --- Refund processing ---
$refund_msg = '';
if (isset($_POST['process_refund_btn'])) {
    $refund_id = intval($_POST['refund_id']);
    $upd_sql = "UPDATE refunds SET refund_status='processed', processed_at=NOW() WHERE refund_id = $refund_id";
    if ($conn->query($upd_sql)) {
        $refund_msg = "Refund marked as processed!";
    } else {
        $refund_msg = "Failed to update refund: " . $conn->error;
    }
}

// --- Get payments list ---
$sql = "SELECT 
            p.payment_id,
            p.booking_id,
            p.payment_date,
            p.amount,
            p.payment_method,
            p.payment_status,
            b.pickup_datetime,
            b.return_datetime,
            b.status AS booking_status,
            b.cust_id,
            c.full_name AS customer_name,
            car.car_id,
            car.car_brand,
            car.car_model,
            car.plate_no
        FROM payment p
        LEFT JOIN booking b ON p.booking_id = b.booking_id
        LEFT JOIN customer c ON b.cust_id = c.cust_id
        LEFT JOIN car ON b.car_id = car.car_id
        ORDER BY p.payment_id DESC";
$result = $conn->query($sql);
?>
<?php include 'admin_header.php'; ?>

<style>
body {
    background: #f8f9fc;
    font-family: 'Inter', Arial, sans-serif;
}
.payments-header {
    margin: 32px auto 18px auto;
    max-width: 1200px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.payments-breadcrumb {
    font-size: 1em;
    color: #92a2b3;
    margin-bottom: 8px;
}
.payments-breadcrumb a {
    color: #6d87be;
    text-decoration: none;
    font-weight: 600;
}
.payments-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.payments-title {
    font-size: 2.05em;
    font-weight: 800;
    color: #232d3b;
    letter-spacing: 0.5px;
}
.refund-success { 
    background:#e0ffe0;
    color:#2b5c2b;
    padding:14px;
    margin:8px auto 18px auto;
    width:97%;
    text-align:center;
    border-radius:5px;
    font-size:1.1em;
    max-width:1200px;
    box-shadow:0 2px 8px #d7f5cf4d;
}
.payments-table-wrap {
    max-width:1200px;
    margin: 0 auto 40px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 2px 12px #e0e7ef55;
    overflow-x: auto;
    padding: 18px 14px 10px 14px;
}
.payments-table {
    border-collapse: collapse;
    width: 100%;
    min-width: 1080px;
    font-size:1.05em;
    background: #fff;
}
.payments-table th, .payments-table td {
    border-bottom: 1px solid #eef2fa;
    padding: 12px 10px;
    text-align: left;
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.payments-table th {
    background: #f8fafd;
    font-weight: 700;
    color: #2b5cbc;
    letter-spacing: 0.5px;
    position: sticky;
    top: 0;
    z-index: 2;
}
.payments-table tr:last-child td {
    border-bottom: none;
}
.status-paid { background:#e6fcf3;color:#2bbf5f;padding:3px 10px;border-radius:8px;}
.status-pending { background:#fff7e6;color:#e7a84b;padding:3px 10px;border-radius:8px;}
.status-failed { background:#ffeded;color:#e54848;padding:3px 10px;border-radius:8px;}
.status-cancelled { background:#faf3f3;color:#a14d4d;padding:3px 10px;border-radius:8px;}
.status-other { background:#eee;color:#888;padding:3px 10px;border-radius:8px;}
.booking-status-badge { padding:3px 10px; border-radius:8px; font-weight:bold; }
.booking-pending { background:#e6ecfc;color:#2959a0;}
.booking-confirmed { background:#e6fcf3;color:#2bbf5f;}
.booking-completed { background:#f4f4e3;color:#a89e1c;}
.booking-cancelled { background:#ffeded;color:#e54848;}
.refund-badge { padding:3px 10px;border-radius:8px;font-weight:bold; }
.refund-pending { background:#fff7e6;color:#e7a84b;}
.refund-processed { background:#e6fcf3;color:#2bbf5f;}
.refund-failed { background:#ffeded;color:#e54848;}
.refund-cancelled { background:#faf3f3;color:#a14d4d;}
.process-btn {
    background: #5cb85c;
    border: none;
    color: white;
    padding: 6px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
    font-size: 1em;
    transition: background 0.13s;
}
.process-btn:hover { background:#449d44; }
@media (max-width: 700px) {
    .payments-header, .payments-table-wrap { max-width: 99vw; padding-left:3px;padding-right:3px;}
    .payments-title { font-size: 1.1em;}
    .payments-table { font-size:0.97em; min-width:720px;}
}
</style>

<div class="payments-header">
    <div class="payments-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Payments
    </div>
    <div class="payments-title-row">
        <div class="payments-title">Payments & Refunds</div>
    </div>
</div>

<?php if (!empty($refund_msg)): ?>
    <div class="refund-success"><?= htmlspecialchars($refund_msg) ?></div>
<?php endif; ?>

<div class="payments-table-wrap">
<table class="payments-table">
    <thead>
        <tr>
            <th>Payment ID</th>
            <th>Booking ID</th>
            <th>Customer</th>
            <th>Car</th>
            <th>Plate</th>
            <th>Pickup</th>
            <th>Return</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Booking Status</th>
            <th>Refund Status</th>
            <th>Method</th>
            <th>Date</th>
            <th>Refund Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <?php
                    // Payment status badge
                    $status = strtolower($row['payment_status']);
                    $badge = '<span class="status-other">'.htmlspecialchars($row['payment_status']).'</span>';
                    if ($status == 'paid') $badge = '<span class="status-paid">Paid</span>';
                    else if ($status == 'pending') $badge = '<span class="status-pending">Pending</span>';
                    else if ($status == 'failed') $badge = '<span class="status-failed">Failed</span>';
                    else if ($status == 'cancelled') $badge = '<span class="status-cancelled">Cancelled</span>';

                    // Booking status badge
                    $booking_status = strtolower($row['booking_status']);
                    $booking_badge = '<span class="booking-status-badge booking-pending">'.htmlspecialchars($row['booking_status']).'</span>';
                    if ($booking_status == 'confirmed') $booking_badge = '<span class="booking-status-badge booking-confirmed">Confirmed</span>';
                    else if ($booking_status == 'completed') $booking_badge = '<span class="booking-status-badge booking-completed">Completed</span>';
                    else if ($booking_status == 'cancelled') $booking_badge = '<span class="booking-status-badge booking-cancelled">Cancelled</span>';

                    // REFUND: get refund row (if any) for this booking
                    $refund_row = null;
                    $refund_status_badge = "-";
                    $refund_action = "-";
                    if ($booking_status == 'cancelled' && !empty($row['booking_id'])) {
                        $refund_sql = "SELECT * FROM refunds WHERE booking_id = ".intval($row['booking_id'])." ORDER BY refund_id DESC LIMIT 1";
                        $refund_res = $conn->query($refund_sql);
                        if ($refund_res && $refund_res->num_rows > 0) {
                            $refund_row = $refund_res->fetch_assoc();
                            $rstatus = $refund_row['refund_status'];
                            $refund_status_badge = '<span class="refund-badge refund-' . htmlspecialchars($rstatus) . '">' . ucfirst($rstatus) . '</span>';
                            if ($rstatus === 'pending') {
                                // Allow admin to process
                                $refund_action = '<form method="post" action="payments.php" style="margin:0;">
                                    <input type="hidden" name="refund_id" value="'.intval($refund_row['refund_id']).'">
                                    <button type="submit" name="process_refund_btn" class="process-btn" onclick="return confirm(\'Mark refund as processed?\')">Process Refund</button>
                                </form>';
                            } elseif ($rstatus === 'processed') {
                                $refund_action = '<span style="color:#2b5c2b;">Processed</span>';
                            } elseif ($rstatus === 'failed') {
                                $refund_action = '<span style="color:#e54848;">Failed</span>';
                            } elseif ($rstatus === 'cancelled') {
                                $refund_action = '<span style="color:#a14d4d;">Cancelled</span>';
                            }
                        } else {
                            // No refund yet: create pending refund row
                            $ins_sql = "INSERT INTO refunds (booking_id, cust_id, amount, refund_status, created_at) 
                                        VALUES (" . intval($row['booking_id']) . ", " . intval($row['cust_id']) . ", " . floatval($row['amount']) . ", 'pending', NOW())";
                            $conn->query($ins_sql);
                            // After insert, show "Pending"
                            $refund_status_badge = '<span class="refund-badge refund-pending">Pending</span>';
                            // Get inserted refund_id
                            $new_refund_id = $conn->insert_id;
                            $refund_action = '<form method="post" action="payments.php" style="margin:0;">
                                <input type="hidden" name="refund_id" value="'.intval($new_refund_id).'">
                                <button type="submit" name="process_refund_btn" class="process-btn" onclick="return confirm(\'Mark refund as processed?\')">Process Refund</button>
                            </form>';
                        }
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['payment_id']) ?></td>
                    <td><?= htmlspecialchars($row['booking_id']) ?></td>
                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td><?= htmlspecialchars($row['car_brand'] . ' ' . $row['car_model']) ?></td>
                    <td><?= htmlspecialchars($row['plate_no']) ?></td>
                    <td><?= $row['pickup_datetime'] ? date('Y-m-d H:i', strtotime($row['pickup_datetime'])) : '-' ?></td>
                    <td><?= $row['return_datetime'] ? date('Y-m-d H:i', strtotime($row['return_datetime'])) : '-' ?></td>
                    <td>MYR <?= number_format($row['amount'] ?? 0, 2) ?></td>
                    <td><?= $badge ?></td>
                    <td><?= $booking_badge ?></td>
                    <td><?= $refund_status_badge ?></td>
                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                    <td><?= $row['payment_date'] ?></td>
                    <td><?= $refund_action ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="14" style="text-align:center;color:#888;">No payments found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>
<?php include '../includes/footer.php'; ?>