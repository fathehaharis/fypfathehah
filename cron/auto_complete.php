<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');
require __DIR__ . '/../connect.php';

/*
 * Auto-complete bookings whose return time has passed AND payment requirement is satisfied.
 *
 * CONFIG
 */
const SOURCE_STATUSES       = ['confirmed','in_progress','ongoing']; // statuses that should transition to completed
const TARGET_STATUS         = 'completed';
const PAYMENT_REQUIREMENT   = 'full'; // 'any','full','deposit','percent'
const PAYMENT_PERCENT       = 50;     // used only if PAYMENT_REQUIREMENT='percent'
const LOG_ACTION_MESSAGE    = 'Auto-completed after return time';

/* Helper: logging */
function logBookingAction(mysqli $c, int $booking_id, string $msg): void {
    if ($st = $c->prepare("INSERT INTO booking_log (booking_id, action) VALUES (?, ?)")) {
        $st->bind_param("is", $booking_id, $msg);
        $st->execute();
        $st->close();
    }
}

function placeholders(int $n): string {
    return implode(',', array_fill(0,$n,'?'));
}

/*
 * Fetch candidate bookings that are past return time and still in SOURCE_STATUSES.
 * We also fetch total_price + security_deposit + paid_sum so we can decide in PHP.
 */
$statusPh = placeholders(count(SOURCE_STATUSES));

$sql = "
    SELECT b.booking_id,
           b.status,
           b.total_price,
           b.security_deposit,
           b.return_datetime,
           COALESCE(p.paid_sum,0) AS paid_sum
    FROM booking b
    LEFT JOIN (
        SELECT booking_id, SUM(amount) AS paid_sum
        FROM payment
        WHERE payment_status='paid'
        GROUP BY booking_id
    ) p ON p.booking_id = p.booking_id
    WHERE b.status IN ($statusPh)
      AND b.return_datetime < NOW()
";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('s', count(SOURCE_STATUSES)), ...SOURCE_STATUSES);
$stmt->execute();
$candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$candidates) {
    echo "[".date('Y-m-d H:i:s')."] No bookings to complete.\n";
    exit;
}

$updated = 0;

foreach ($candidates as $row) {
    $bid      = (int)$row['booking_id'];
    $paidSum  = (float)$row['paid_sum'];
    $total    = (float)$row['total_price'];
    $deposit  = (float)$row['security_deposit'];

    // Decide if payment requirement is met
    $meets = false;
    switch (PAYMENT_REQUIREMENT) {
        case 'any':
            $meets = $paidSum > 0;
            break;
        case 'deposit':
            $meets = $paidSum + 0.0001 >= $deposit;
            break;
        case 'full':
            $meets = $paidSum + 0.0001 >= $total;
            break;
        case 'percent':
            $threshold = $total * (PAYMENT_PERCENT / 100);
            $meets = $paidSum + 0.0001 >= $threshold;
            break;
        default:
            $meets = $paidSum > 0;
    }

    if (!$meets) {
        // Not paid enough -> skip; you might instead want to auto-cancel or flag
        continue;
    }

    // Transaction with row lock to avoid race
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare("SELECT status, total_price, security_deposit FROM booking WHERE booking_id=? LIMIT 1 FOR UPDATE");
        $lock->bind_param("i", $bid);
        $lock->execute();
        $current = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$current) { $conn->rollback(); continue; }
        if (!in_array(strtolower($current['status']), array_map('strtolower', SOURCE_STATUSES), true)) {
            $conn->rollback(); continue; // Status changed elsewhere.
        }

        // Recompute paid sum just in case of late payment
        $pay = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM payment WHERE booking_id=? AND payment_status='paid'");
        $pay->bind_param("i", $bid);
        $pay->execute();
        $paidNow = (float)($pay->get_result()->fetch_assoc()['s'] ?? 0);
        $pay->close();

        $stillMeets = false;
        switch (PAYMENT_REQUIREMENT) {
            case 'any':     $stillMeets = $paidNow > 0; break;
            case 'deposit': $stillMeets = $paidNow + 0.0001 >= (float)$current['security_deposit']; break;
            case 'full':    $stillMeets = $paidNow + 0.0001 >= (float)$current['total_price']; break;
            case 'percent':
                $threshold = (float)$current['total_price'] * (PAYMENT_PERCENT / 100);
                $stillMeets = $paidNow + 0.0001 >= $threshold;
                break;
        }
        if (!$stillMeets) {
            $conn->rollback(); continue;
        }

        $updateSql = "UPDATE booking SET status=?, updated_at=NOW() WHERE booking_id=? AND status IN ($statusPh)";
        $update = $conn->prepare($updateSql);
        $bindTypes = 's' . 'i' . str_repeat('s', count(SOURCE_STATUSES));
        $bindValues = [TARGET_STATUS, $bid, ...SOURCE_STATUSES];
        $update->bind_param($bindTypes, ...$bindValues);
        $update->execute();
        if ($update->affected_rows < 1) {
            $update->close();
            $conn->rollback();
            continue;
        }
        $update->close();

        logBookingAction($conn, $bid, LOG_ACTION_MESSAGE." (paid=".number_format($paidNow,2).")");

        $conn->commit();
        $updated++;
        echo "[".date('Y-m-d H:i:s')."] Booking #$bid -> completed.\n";

    } catch (Throwable $e) {
        $conn->rollback();
        echo "[".date('Y-m-d H:i:s')."] Booking #$bid failed: ".$e->getMessage()."\n";
    }
}

echo "[".date('Y-m-d H:i:s')."] Completed $updated booking(s).\n";