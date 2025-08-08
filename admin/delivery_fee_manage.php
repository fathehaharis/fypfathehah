<?php
/**
 * Single Admin Page: View + Set Delivery / Pickup Fee AND Recalculate Booking Total
 *
 * Formula (minimal model):
 *   booking.total_price = (day_count * daily_rate) + security_deposit + IFNULL(delivery_fee, 0)
 *
 * Assumptions:
 *   - Only ONE delivery-related service row per booking (service_type 'delivery' OR 'pickup_and_return').
 *   - Tables:
 *       booking(booking_id, day_count, daily_rate, security_deposit, total_price, status, car_id, cust_id, ...)
 *       service(service_id, booking_id, service_type, fee, status, delivery_location, return_location, ...)
 *       car(car_id, car_brand, car_model, plate_no, ...)
 *       customer(cust_id, full_name, ...)
 *   - Admin auth = $_SESSION['is_admin'] (replace with your real guard).
 *
 * If you added triggers already, this page still works — the UPDATE on service will
 * fire the trigger. We also explicitly update booking.total_price (harmless double sync).
 */

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// ------------------------------------------------------------------
// AUTH GUARD (Replace with your own logic)
// ------------------------------------------------------------------
if (empty($_SESSION['is_admin'])) {
    $_SESSION['is_admin'] = 1; // TEMP FORCE
    http_response_code(403);
    echo "Forbidden";
    exit;
}

require '../connect.php';

// Utility: secure fetch of number input
function normalize_fee(?string $raw): ?float {
    if ($raw === null || $raw === '') return null;
    if (!is_numeric($raw)) return null;
    return round((float)$raw, 2);
}

// Handle POST (update / create fee)
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id = isset($_POST['booking_id']) && ctype_digit($_POST['booking_id'])
        ? (int)$_POST['booking_id'] : 0;
    if ($booking_id <= 0) {
        $flash = ['type'=>'error','msg'=>'Invalid booking ID.'];
    } else {
        $fee      = normalize_fee($_POST['delivery_fee'] ?? null); // null means remove / reset
        $svc_type = ($_POST['service_type'] ?? 'delivery');
        if (!in_array($svc_type, ['delivery','pickup_and_return'], true)) {
            $svc_type = 'delivery';
        }
        $auto_approve = !empty($_POST['auto_approve']);
        $set_service_status = $_POST['service_status'] ?? 'pending';
        if (!in_array($set_service_status, ['pending','approved','inactive'], true)) {
            $set_service_status = 'pending';
        }

        // Wrap in transaction
        $conn->begin_transaction();
        try {
            // Fetch booking row
            $stmt = $conn->prepare("
                SELECT booking_id, day_count, daily_rate, security_deposit, total_price, status
                FROM booking WHERE booking_id = ? FOR UPDATE
            ");
            $stmt->bind_param("i", $booking_id);
            $stmt->execute();
            $bookingRes = $stmt->get_result();
            $booking = $bookingRes->fetch_assoc();
            $stmt->close();

            if (!$booking) {
                throw new Exception("Booking not found.");
            }

            // Find existing delivery/pickup service row (any one)
            $svcStmt = $conn->prepare("
                SELECT service_id, service_type, fee, status
                FROM service
                WHERE booking_id = ? AND service_type IN ('delivery','pickup_and_return')
                ORDER BY service_id ASC
                LIMIT 1
            ");
            $svcStmt->bind_param("i", $booking_id);
            $svcStmt->execute();
            $svcRes = $svcStmt->get_result();
            $service = $svcRes->fetch_assoc();
            $svcStmt->close();

            $base_total = ($booking['day_count'] * $booking['daily_rate']) + $booking['security_deposit'];

            // Upsert service row
            if ($service) {
                // If service_type changed by admin selection, optionally update it
                $updateSql = "UPDATE service SET service_type = ?, fee = ?, status = ?, updated_at = NOW() WHERE service_id = ?";
                if ($fee === null) {
                    // Setting fee NULL
                    $updateSql = "UPDATE service SET service_type = ?, fee = NULL, status = ?, updated_at = NOW() WHERE service_id = ?";
                    $upd = $conn->prepare($updateSql);
                    $upd->bind_param("ssi", $svc_type, $set_service_status, $service['service_id']);
                } else {
                    $upd = $conn->prepare($updateSql);
                    $upd->bind_param("sdsi", $svc_type, $fee, $set_service_status, $service['service_id']);
                }
                $upd->execute();
                if ($upd->error) throw new Exception("Service update failed: ".$upd->error);
                $upd->close();
            } else {
                if ($fee !== null) {
                    // Create a new service row only if a fee is provided.
                    $ins = $conn->prepare("
                        INSERT INTO service (booking_id, service_type, fee, status, created_at)
                        VALUES (?,?,?,?, NOW())
                    ");
                    $ins->bind_param("isds", $booking_id, $svc_type, $fee, $set_service_status);
                    $ins->execute();
                    if ($ins->error) throw new Exception("Service insert failed: ".$ins->error);
                    $ins->close();
                }
            }

            // Determine final delivery fee (if fee was removed => null or 0)
            $delivery_fee = $fee ?? 0.0;

            // Recalculate total
            $new_total = $base_total + $delivery_fee;

            if ($auto_approve) {
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
            $flash = ['type'=>'success','msg'=>'Delivery fee & total updated successfully.'];
            // Redirect (PRG pattern) to avoid form resubmission
            header("Location: ".$_SERVER['PHP_SELF']."?booking_id=".$booking_id."&ok=1");
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $flash = ['type'=>'error','msg'=>$e->getMessage()];
        }
    }
}

// Display mode
$booking_id = 0;
if (isset($_GET['booking_id']) && ctype_digit($_GET['booking_id'])) {
    $booking_id = (int)$_GET['booking_id'];
}

$booking = null;
$service = null;
$customer = null;
$car = null;
$base_total = 0;
$expected_total = 0;

if ($booking_id > 0) {
    // Fetch booking + car + customer
    $stmt = $conn->prepare("
        SELECT b.*, c.car_brand, c.car_model, c.plate_no,
               cust.full_name AS customer_name
        FROM booking b
        JOIN car c ON b.car_id = c.car_id
        JOIN customer cust ON b.cust_id = cust.cust_id
        WHERE b.booking_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if ($booking) {
        $base_total = ($booking['day_count'] * $booking['daily_rate']) + $booking['security_deposit'];

        // Service row
        $svcStmt = $conn->prepare("
            SELECT service_id, service_type, fee, status, delivery_location, return_location
            FROM service
            WHERE booking_id = ?
              AND service_type IN ('delivery','pickup_and_return')
            ORDER BY service_id ASC
            LIMIT 1
        ");
        $svcStmt->bind_param("i", $booking_id);
        $svcStmt->execute();
        $svcRes = $svcStmt->get_result();
        $service = $svcRes->fetch_assoc();
        $svcStmt->close();

        $delivery_fee = $service ? ($service['fee'] ?? null) : null;
        $expected_total = $base_total + ($delivery_fee !== null ? (float)$delivery_fee : 0);
    }
}

// Simple flash from redirect
if (isset($_GET['ok']) && $_GET['ok'] == '1' && !$flash) {
    $flash = ['type'=>'success','msg'=>'Updated successfully.'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin | Delivery Fee & Total</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<style>
:root {
  --c-bg:#f5f7fb; --c-card:#ffffff; --c-border:#d9e2ef; --c-text:#1f2f49;
  --c-accent:#3c4cb8; --c-accent-hover:#273887; --c-warn:#b85600; --c-ok:#217a39;
  --c-badge:#46558a; --radius:14px;
  font-family: system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
}
body { margin:0; background:var(--c-bg); color:var(--c-text); }
.wrapper { max-width:860px; margin:40px auto 70px; background:var(--c-card); padding:34px 42px 46px; border-radius:var(--radius); box-shadow:0 6px 26px -3px rgba(25,38,70,.15); }
h1 { margin:0 0 14px; font-size:1.55em; letter-spacing:.5px; }
.badge { display:inline-block; background:var(--c-badge); color:#fff; font-size:.68em; font-weight:700; padding:4px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:.6px; margin-left:6px; }
section { margin-top:30px; }
table.meta { width:100%; border-collapse:collapse; font-size:.9em; margin-top:12px; }
.meta th, .meta td { text-align:left; padding:9px 12px; border-bottom:1px solid var(--c-border); vertical-align:top; }
.meta th { width:230px; font-weight:600; background:#f1f4fa; letter-spacing:.4px; }
.status-ok { color:var(--c-ok); font-weight:600; font-size:.85em; }
.status-mismatch { color:var(--c-warn); font-weight:600; font-size:.85em; }
.flash { padding:14px 18px; border-radius:10px; margin-bottom:18px; font-size:.9em; }
.flash.success { background:#e9f8ec; color:#1f6d34; border:1px solid #c9ebd1; }
.flash.error { background:#ffe9e7; color:#aa1f1f; border:1px solid #ffc7c1; }
form.fee-form { margin-top:10px; }
form.fee-form .row { margin-bottom:18px; }
label { display:block; font-weight:600; font-size:.78em; letter-spacing:.6px; margin-bottom:6px; text-transform:uppercase; color:#2b3f65; }
input[type=number], select, input[type=text] {
  padding:10px 12px; border:1px solid var(--c-border); border-radius:9px; font-size:.95em; width:240px;
  background:#fff; box-sizing:border-box;
}
input[type=checkbox] { transform:translateY(1px); }
.note { font-size:.7em; color:#65758f; margin-top:4px; line-height:1.35em; }
.actions { display:flex; flex-wrap:wrap; gap:14px; margin-top:6px; }
button, .btn {
  border:none; cursor:pointer; background:var(--c-accent); color:#fff; font-weight:600;
  padding:12px 26px; border-radius:10px; font-size:.9em; letter-spacing:.4px; text-decoration:none;
  display:inline-flex; align-items:center; gap:6px; transition:.18s;
}
button:hover, .btn:hover { background:var(--c-accent-hover); }
.btn.outline { background:#edf1fb; color:var(--c-accent); }
.btn.outline:hover { background:#dee5f7; }
.inline-warning { color:var(--c-warn); font-size:.8em; font-weight:600; }
.divider { height:1px; background:var(--c-border); margin:34px 0 18px; }
.badge-service { background:#ffc557; color:#724800; }
.code-inline { font-family:monospace; background:#f1f4fa; padding:2px 6px; border-radius:6px; font-size:.85em; }
.empty { color:#7a889d; font-style:italic; }
</style>
</head>
<body>
<div class="wrapper">
    <h1>Delivery / Pickup Fee Manager <span class="badge">Admin</span></h1>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" style="margin-bottom:26px;">
        <label for="booking_id" style="display:inline-block; margin-right:10px;">Load Booking ID</label>
        <input type="number" min="1" name="booking_id" id="booking_id" value="<?= $booking_id ?: '' ?>" style="width:140px;">
        <button type="submit">Load</button>
    </form>

    <?php if (!$booking && $booking_id): ?>
        <div class="flash error">Booking #<?= htmlspecialchars($booking_id) ?> not found.</div>
    <?php elseif ($booking): ?>
        <section>
            <h2 style="margin:0 0 8px;font-size:1.1em;">Booking Overview</h2>
            <table class="meta">
                <tr><th>Booking ID</th><td>#<?= htmlspecialchars($booking['booking_id']) ?></td></tr>
                <tr><th>Customer</th><td><?= htmlspecialchars($booking['customer_name'] ?? 'N/A') ?></td></tr>
                <tr><th>Car</th><td><?= htmlspecialchars($booking['car_brand'].' '.$booking['car_model']) ?> (<?= htmlspecialchars($booking['plate_no']) ?>)</td></tr>
                <tr><th>Status</th><td><?= htmlspecialchars($booking['status']) ?></td></tr>
                <tr><th>Day Count</th><td><?= (int)$booking['day_count'] ?></td></tr>
                <tr><th>Daily Rate (RM)</th><td><?= number_format($booking['daily_rate'],2) ?></td></tr>
                <tr><th>Security Deposit (RM)</th><td><?= number_format($booking['security_deposit'],2) ?></td></tr>
                <tr><th>Base Total (RM)</th><td><?= number_format($base_total,2) ?></td></tr>
                <tr><th>Delivery Service Type</th>
                    <td>
                        <?php if ($service): ?>
                            <span class="badge-service" style="background:#ffe6b0;color:#6d4c00;">
                                <?= htmlspecialchars($service['service_type']) ?>
                            </span>
                        <?php else: ?>
                            <span class="empty">None (Self Pickup)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>Current Delivery Fee (RM)</th>
                    <td>
                        <?php if ($service && $service['fee'] !== null): ?>
                            <?= number_format($service['fee'],2) ?>
                        <?php elseif ($service): ?>
                            <span class="empty">(Null / Not Set)</span>
                        <?php else: ?>
                            <span class="empty">N/A</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>Expected Total (RM)</th><td><?= number_format($expected_total,2) ?></td></tr>
                <tr><th>Stored Total (RM)</th>
                    <td>
                        <?= number_format($booking['total_price'],2) ?>
                        <?php if (abs($booking['total_price'] - $expected_total) < 0.01): ?>
                            <span class="status-ok">OK</span>
                        <?php else: ?>
                            <span class="status-mismatch">Mismatch</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($service && $service['delivery_location']): ?>
                    <tr><th>Delivery Location</th><td><?= htmlspecialchars($service['delivery_location']) ?></td></tr>
                <?php endif; ?>
                <?php if ($service && $service['return_location']): ?>
                    <tr><th>Return Location</th><td><?= htmlspecialchars($service['return_location']) ?></td></tr>
                <?php endif; ?>
            </table>
        </section>

        <div class="divider"></div>

        <section>
            <h2 style="margin:0 0 14px;font-size:1.05em;">Set / Update Delivery or Pickup Fee</h2>
            <form method="post" class="fee-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                <div class="row">
                    <label for="service_type">Service Type</label>
                    <select name="service_type" id="service_type">
                        <option value="delivery" <?= ($service['service_type'] ?? '')==='delivery'?'selected':''; ?>>delivery</option>
                        <option value="pickup_and_return" <?= ($service['service_type'] ?? '')==='pickup_and_return'?'selected':''; ?>>pickup_and_return</option>
                    </select>
                    <div class="note">If no existing row and you set a fee, this service row will be created.</div>
                </div>
                <div class="row">
                    <label for="delivery_fee">Delivery / Pickup Fee (RM)</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="delivery_fee"
                        id="delivery_fee"
                        value="<?= ($service && $service['fee'] !== null) ? htmlspecialchars(number_format($service['fee'],2,'.','')) : '' ?>"
                        placeholder="e.g. 80.00">
                    <div class="note">
                        Leave blank to remove fee (total becomes base). Setting a number recalculates total immediately.
                    </div>
                </div>
                <div class="row">
                    <label for="service_status">Service Row Status</label>
                    <select name="service_status" id="service_status">
                        <?php
                        $svcStatus = $service['status'] ?? 'pending';
                        foreach (['pending','approved','inactive'] as $opt) {
                            $sel = $svcStatus === $opt ? 'selected' : '';
                            echo "<option value=\"$opt\" $sel>$opt</option>";
                        }
                        ?>
                    </select>
                    <div class="note">Purely informational for now; customer Pay button logic only needs fee + approved booking.</div>
                </div>
                <div class="row">
                    <label>
                        <input type="checkbox" name="auto_approve" value="1" <?= $booking['status']==='approved'?'checked':''; ?>>
                        Set / keep booking status as 'approved'
                    </label>
                    <div class="note">
                        If checked and booking not yet approved, status will become approved after update.
                    </div>
                </div>

                <div class="actions">
                    <button type="submit">Save & Recalculate</button>
                    <a class="btn outline" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">Clear</a>
                </div>
            </form>
        </section>
    <?php elseif (!$booking_id): ?>
        <div style="margin-top:10px;color:#5a6881;font-size:.9em;">
            Enter a Booking ID above to view and modify its delivery fee.
        </div>
    <?php endif; ?>

    <div style="margin-top:50px;font-size:.7em;color:#7a8599;line-height:1.4em;">
        Notes:
        <ul style="margin:6px 0 0 18px;padding:0;">
            <li>If DB triggers exist they will also adjust the total (double-safe).</li>
            <li>Only one delivery/pickup service row is considered here.</li>
            <li>Changing day_count/daily_rate/security_deposit elsewhere will not recalc unless you also adjust fee or have a booking trigger.</li>
        </ul>
    </div>
</div>
</body>
</html>