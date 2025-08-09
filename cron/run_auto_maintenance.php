<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');
require __DIR__ . '/../connect.php';

const STATUSES_TO_CANCEL = ['approved']; // add 'pending' if you also want to cancel those
const ACTIVE_STATUSES    = ['pending','waiting_verification','approved','confirmed']; // statuses that keep the car reserved
const CANCELLATION_REASON = 'Auto-cancelled: no payment before pickup';
const GRACE_MINUTES = 0;

/*
 * COMPLETION CONFIG
 * COMPLETE_SOURCE_STATUSES: which statuses can transition to 'completed'.
 *   If you have an 'in_progress' or 'ongoing' status once the car is picked up, add it here.
 * COMPLETE_TARGET_STATUS: the status to set.
 * COMPLETE_PAYMENT_REQUIREMENT:
 *   'any'     -> any paid amount > 0
 *   'full'    -> paid_sum >= total_price
 *   'deposit' -> paid_sum >= security_deposit
 *   'percent' -> paid_sum >= total_price * COMPLETE_PAYMENT_PERCENT / 100
 * If you just want to complete regardless of payment, set to 'any' and ensure user always pays before pickup.
 */
const COMPLETE_SOURCE_STATUSES        = ['confirmed'];
const COMPLETE_TARGET_STATUS          = 'completed';
const COMPLETE_PAYMENT_REQUIREMENT    = 'full';    // 'any','full','deposit','percent'
const COMPLETE_PAYMENT_PERCENT        = 50;        // used only if above = 'percent'
const COMPLETE_LOG_MESSAGE            = 'Auto-completed after return time';

/* You can add 'completed' to a separate constant if you want to exclude it elsewhere; ACTIVE_STATUSES already excludes it. */

function logBookingAction(mysqli $c, int $bid, string $msg): void {
    if ($st = $c->prepare("INSERT INTO booking_log (booking_id, action) VALUES (?, ?)")) {
        $st->bind_param("is", $bid, $msg);
        $st->execute();
        $st->close();
    }
}

function placeholders(int $n): string {
    return implode(',', array_fill(0,$n,'?'));
}

/* ===================== AUTO-CANCEL UNPAID PAST PICKUP ===================== */
$stPh = placeholders(count(STATUSES_TO_CANCEL));
$sql = "
    SELECT b.booking_id, b.car_id
    FROM booking b
    LEFT JOIN (
        SELECT booking_id, SUM(amount) paid_sum
        FROM payment
        WHERE payment_status='paid'
        GROUP BY booking_id
    ) p ON p.booking_id=b.booking_id
    WHERE b.status IN ($stPh)
      AND (p.paid_sum IS NULL OR p.paid_sum=0)
      AND b.pickup_datetime < (NOW() - INTERVAL ? MINUTE)
";
$stmt = $conn->prepare($sql);
$types = str_repeat('s', count(STATUSES_TO_CANCEL)) . 'i';
$params = [...STATUSES_TO_CANCEL, GRACE_MINUTES];
$stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$list) {
    echo "[".date('Y-m-d H:i:s')."] No unpaid past-pickup bookings.\n";
} else {
    $activePh = placeholders(count(ACTIVE_STATUSES));
    foreach ($list as $row) {
        $bid = (int)$row['booking_id'];
        $car = (int)$row['car_id'];
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT status FROM booking WHERE booking_id=? LIMIT 1 FOR UPDATE");
            $lock->bind_param("i",$bid);
            $lock->execute();
            $cur = $lock->get_result()->fetch_assoc();
            $lock->close();
            if(!$cur || !in_array(strtolower($cur['status']), STATUSES_TO_CANCEL, true)){
                $conn->rollback(); continue;
            }
            // re-check payment
            $p = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM payment WHERE booking_id=? AND payment_status='paid' LIMIT 1");
            $p->bind_param("i",$bid);
            $p->execute();
            $sum = (float)($p->get_result()->fetch_assoc()['s'] ?? 0);
            $p->close();
            if($sum>0){ $conn->rollback(); continue; }

            $upd = $conn->prepare("UPDATE booking SET status='cancelled', cancellation_reason=?, updated_at=NOW() WHERE booking_id=? AND status IN ($stPh)");
            $types2 = 's' . 'i' . str_repeat('s', count(STATUSES_TO_CANCEL));
            $bind = [CANCELLATION_REASON, $bid, ...STATUSES_TO_CANCEL];
            $upd->bind_param($types2, ...$bind);
            $upd->execute();
            if($upd->affected_rows<1){ $upd->close(); $conn->rollback(); continue; }
            $upd->close();

            logBookingAction($conn,$bid,"Auto-cancelled (unpaid past pickup)");

            // release car?
            $carChk = $conn->prepare("
                SELECT 1 FROM booking
                WHERE car_id=? AND booking_id<>? AND status IN ($activePh)
                LIMIT 1
            ");
            $carChkTypes = 'ii'.str_repeat('s', count(ACTIVE_STATUSES));
            $carChk->bind_param($carChkTypes, $car, $bid, ...ACTIVE_STATUSES);
            $carChk->execute();
            $carChk->store_result();
            $busy = $carChk->num_rows>0;
            $carChk->close();

            if(!$busy){
                $rel = $conn->prepare("UPDATE car SET status='available' WHERE car_id=?");
                $rel->bind_param("i",$car);
                $rel->execute();
                $rel->close();
                logBookingAction($conn,$bid,"Car #$car released (auto-cancel).");
            }

            $conn->commit();
            echo "[".date('Y-m-d H:i:s')."] Booking #$bid cancelled.\n";
        } catch(Throwable $e){
            $conn->rollback();
            echo "[".date('Y-m-d H:i:s')."] Booking #$bid failed: ".$e->getMessage()."\n";
        }
    }
}

/* ===================== AUTO-COMPLETE PAST RETURN ===================== */
/*
 * We only complete if:
 *   - status in COMPLETE_SOURCE_STATUSES
 *   - return_datetime < NOW()
 *   - payment requirement satisfied
 */
$completePh = placeholders(count(COMPLETE_SOURCE_STATUSES));
$completeSql = "
    SELECT b.booking_id, b.car_id,
           b.total_price, b.security_deposit,
           COALESCE(p.paid_sum,0) AS paid_sum
    FROM booking b
    LEFT JOIN (
        SELECT booking_id, SUM(amount) paid_sum
        FROM payment
        WHERE payment_status='paid'
        GROUP BY booking_id
    ) p ON p.booking_id = b.booking_id
    WHERE b.status IN ($completePh)
      AND b.return_datetime < NOW()
";
$csTypes = str_repeat('s', count(COMPLETE_SOURCE_STATUSES));
$csStmt = $conn->prepare($completeSql);
$csStmt->bind_param($csTypes, ...COMPLETE_SOURCE_STATUSES);
$csStmt->execute();
$toComplete = $csStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$csStmt->close();

if ($toComplete) {
    $activePh2 = placeholders(count(ACTIVE_STATUSES)); // for car release after completion (ACTIVE_STATUSES excludes 'completed')
    foreach ($toComplete as $row) {
        $bid   = (int)$row['booking_id'];
        $carId = (int)$row['car_id'];
        $paid  = (float)$row['paid_sum'];
        $total = (float)$row['total_price'];
        $dep   = (float)$row['security_deposit'];

        // Evaluate payment requirement
        $meets = false;
        switch (COMPLETE_PAYMENT_REQUIREMENT) {
            case 'any':     $meets = $paid > 0; break;
            case 'deposit': $meets = $paid + 0.0001 >= $dep; break;
            case 'full':    $meets = $paid + 0.0001 >= $total; break;
            case 'percent':
                $threshold = $total * (COMPLETE_PAYMENT_PERCENT / 100);
                $meets = $paid + 0.0001 >= $threshold;
                break;
            default:        $meets = $paid > 0;
        }
        if (!$meets) {
            // Skip (could log if you want to see which ones are pending payment)
            continue;
        }

        $conn->begin_transaction();
        try {
            // Lock current row to avoid race
            $lock = $conn->prepare("SELECT status, total_price, security_deposit FROM booking WHERE booking_id=? LIMIT 1 FOR UPDATE");
            $lock->bind_param("i",$bid);
            $lock->execute();
            $cur = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$cur || !in_array(strtolower($cur['status']), array_map('strtolower', COMPLETE_SOURCE_STATUSES), true)) {
                $conn->rollback(); continue;
            }

            // Re-check payment sum (in case something changed moments ago)
            $pay = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM payment WHERE booking_id=? AND payment_status='paid'");
            $pay->bind_param("i",$bid);
            $pay->execute();
            $paidNow = (float)($pay->get_result()->fetch_assoc()['s'] ?? 0);
            $pay->close();

            $stillMeets = false;
            switch (COMPLETE_PAYMENT_REQUIREMENT) {
                case 'any':     $stillMeets = $paidNow > 0; break;
                case 'deposit': $stillMeets = $paidNow + 0.0001 >= (float)$cur['security_deposit']; break;
                case 'full':    $stillMeets = $paidNow + 0.0001 >= (float)$cur['total_price']; break;
                case 'percent':
                    $threshold2 = (float)$cur['total_price'] * (COMPLETE_PAYMENT_PERCENT / 100);
                    $stillMeets = $paidNow + 0.0001 >= $threshold2;
                    break;
                default:        $stillMeets = $paidNow > 0;
            }
            if (!$stillMeets) { $conn->rollback(); continue; }

            $up = $conn->prepare("UPDATE booking SET status=?, updated_at=NOW() WHERE booking_id=? AND status IN ($completePh)");
            $upTypes = 's' . 'i' . $csTypes;
            $upBind  = [COMPLETE_TARGET_STATUS, $bid, ...COMPLETE_SOURCE_STATUSES];
            $up->bind_param($upTypes, ...$upBind);
            $up->execute();
            if ($up->affected_rows < 1) { $up->close(); $conn->rollback(); continue; }
            $up->close();

            logBookingAction($conn, $bid, COMPLETE_LOG_MESSAGE . " (paid=" . number_format($paidNow,2) . ")");

            // Release car if no other active bookings
            $carChk = $conn->prepare("
                SELECT 1 FROM booking
                WHERE car_id=? AND status IN ($activePh2)
                LIMIT 1
            ");
            $carChkTypes2 = 'i' . str_repeat('s', count(ACTIVE_STATUSES));
            $carChk->bind_param($carChkTypes2, $carId, ...ACTIVE_STATUSES);
            $carChk->execute();
            $carChk->store_result();
            $stillBusy = $carChk->num_rows > 0;
            $carChk->close();

            if (!$stillBusy) {
                $rel = $conn->prepare("UPDATE car SET status='available' WHERE car_id=?");
                $rel->bind_param("i",$carId);
                $rel->execute();
                $rel->close();
                logBookingAction($conn, $bid, "Car #$carId released (completion).");
            }

            $conn->commit();
            echo "[".date('Y-m-d H:i:s')."] Booking #$bid completed.\n";
        } catch (Throwable $e) {
            $conn->rollback();
            echo "[".date('Y-m-d H:i:s')."] Complete fail #$bid: ".$e->getMessage()."\n";
        }
    }
} else {
    echo "[".date('Y-m-d H:i:s')."] No bookings to complete.\n";
}

echo "[".date('Y-m-d H:i:s')."] Maintenance cycle finished.\n";