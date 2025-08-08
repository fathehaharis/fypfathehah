<?php
// ADMIN: Edit / Add Delivery Service Fee for a booking
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// TODO: Replace with your real admin auth check
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

if (empty($_GET['booking_id']) || !ctype_digit($_GET['booking_id'])) {
    die("Missing or invalid booking_id.");
}
$booking_id = (int)$_GET['booking_id'];

require '../connect.php';

// Fetch booking + delivery service (if exists)
$stmt = $conn->prepare("
    SELECT 
        b.booking_id,
        b.cust_id,
        b.day_count,
        b.daily_rate,
        b.security_deposit,
        b.total_price,
        b.status,
        c.car_brand,
        c.car_model,
        s.service_id,
        s.service_type,
        s.fee AS delivery_fee,
        s.status AS service_status,
        s.delivery_location,
        s.return_location
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    LEFT JOIN service s
      ON s.booking_id = b.booking_id
     AND s.service_type IN ('delivery','pickup_and_return')
    WHERE b.booking_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    die("Booking not found.");
}

$base_total = ($data['day_count'] * $data['daily_rate']) + $data['security_deposit'];
$current_delivery_fee = $data['delivery_fee'];
$expected_total = $base_total + ($current_delivery_fee !== null ? $current_delivery_fee : 0);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin: Delivery Fee Edit</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; background:#f2f5fa; }
.wrapper { max-width:780px; margin:40px auto 70px; background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(40,55,95,.12); padding:34px 40px 42px; }
h1 { font-size:1.5em; margin:0 0 18px; color:#22325e; }
.meta { font-size:.9em; color:#45526d; margin-bottom:22px; }
table.summary { width:100%; border-collapse:collapse; margin-bottom:28px; font-size:.9em; }
.summary th, .summary td { text-align:left; padding:8px 10px; border-bottom:1px solid #e5e9f2; vertical-align:top; }
.summary th { width:220px; font-weight:600; color:#2c3e63; background:#f5f8fc; }
.notice-mismatch { color:#b85600; font-weight:600; font-size:.85em; }
.badge { display:inline-block; padding:3px 8px; font-size:.65em; font-weight:700; letter-spacing:.5px; border-radius:12px; background:#3c4cb8; color:#fff; text-transform:uppercase; margin-left:6px; }
.badge-pending { background:#b8860b; }
form .row { margin-bottom:18px; }
label { display:block; font-weight:600; font-size:.85em; letter-spacing:.4px; color:#253457; margin-bottom:6px; }
input[type='number'] { width:180px; padding:8px 10px; border:1px solid #cfd6e2; border-radius:8px; font-size:1em; }
.actions { margin-top:26px; display:flex; gap:14px; flex-wrap:wrap; }
button, .btn-link { border:none; cursor:pointer; background:#3c4cb8; color:#fff; font-weight:600; padding:12px 24px; border-radius:8px; font-size:.95em; text-decoration:none; transition:.18s; }
button:hover, .btn-link:hover { background:#234c96; }
.btn-secondary { background:#d0d5df; color:#1d2d4c; }
.btn-secondary:hover { background:#bcc3cf; }
.inline-hint { font-size:.7em; color:#667691; margin-top:4px; }
.status-ok { color:#227a34; font-weight:600; }
fieldset { border:1px solid #d9e2ef; padding:18px 20px 20px; border-radius:12px; }
legend { padding:0 10px; font-size:.85em; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:#32456d; }
</style>
</head>
<body>
<div class="wrapper">
    <h1>Delivery / Pickup Fee <span class="badge">Admin</span></h1>
    <div class="meta">
        Booking #<?= htmlspecialchars($data['booking_id']) ?> |
        Car: <?= htmlspecialchars($data['car_brand'].' '.$data['car_model']) ?> |
        Status: <strong><?= htmlspecialchars($data['status']) ?></strong>
        <?php if (!empty($data['service_type'])): ?>
            | Service Type: <?= htmlspecialchars($data['service_type']) ?>
        <?php else: ?>
            | Service Type: <em>Self Pickup / None</em>
        <?php endif; ?>
    </div>

    <table class="summary">
        <tr><th>Days</th><td><?= (int)$data['day_count'] ?></td></tr>
        <tr><th>Daily Rate</th><td>RM <?= number_format($data['daily_rate'],2) ?></td></tr>
        <tr><th>Security Deposit</th><td>RM <?= number_format($data['security_deposit'],2) ?></td></tr>
        <tr><th>Current Delivery Fee</th>
            <td>
                <?php if ($current_delivery_fee !== null): ?>
                    RM <?= number_format($current_delivery_fee,2) ?>
                <?php else: ?>
                    <span style="color:#888;">(None)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><th>Base Total (days*daily + deposit)</th><td>RM <?= number_format($base_total,2) ?></td></tr>
        <tr><th>Expected Total (Base + Fee)</th><td>RM <?= number_format($expected_total,2) ?></td></tr>
        <tr><th>Stored Booking Total</th>
            <td>
                RM <?= number_format($data['total_price'],2) ?>
                <?php if (abs($data['total_price'] - $expected_total) > 0.01): ?>
                    <span class="notice-mismatch">Mismatch</span>
                <?php else: ?>
                    <span class="status-ok">OK</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php if (!empty($data['delivery_location'])): ?>
            <tr><th>Delivery Location</th><td><?= htmlspecialchars($data['delivery_location']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($data['return_location'])): ?>
            <tr><th>Return Pickup Location</th><td><?= htmlspecialchars($data['return_location']) ?></td></tr>
        <?php endif; ?>
    </table>

    <fieldset>
        <legend>Set / Update Delivery Fee</legend>
        <?php if (empty($data['service_type'])): ?>
            <p style="color:#b40d0d;font-weight:600;">No delivery/pickup service is attached. You can create one below.</p>
        <?php endif; ?>
        <form method="POST" action="delivery_fee_update_action.php" onsubmit="return confirm('Apply this fee update?');">
            <input type="hidden" name="booking_id" value="<?= (int)$data['booking_id'] ?>">
            <div class="row">
                <label for="delivery_fee">Delivery / Pickup Fee (RM)</label>
                <input type="number" step="0.01" min="0" name="delivery_fee" id="delivery_fee"
                       value="<?= $current_delivery_fee !== null ? htmlspecialchars(number_format($current_delivery_fee,2,'.','')) : '' ?>"
                       placeholder="e.g. 80.00">
                <div class="inline-hint">
                    Leave blank to remove fee (will recalc booking total back to base).
                    If no service row exists, one will be created with service_type=delivery.
                </div>
            </div>
            <div class="row">
                <label><input type="checkbox" name="auto_approve" value="1"
                    <?= $data['status']==='approved'?'checked':''; ?>> Set/keep booking status as 'approved'</label>
                <div class="inline-hint">If unchecked, status is left unchanged.</div>
            </div>
            <div class="actions">
                <button type="submit">Save Fee & Recalculate</button>
                <a class="btn-secondary btn-link" href="booking_view_admin.php?booking_id=<?= (int)$data['booking_id'] ?>">Back</a>
            </div>
        </form>
    </fieldset>
</div>
</body>
</html>