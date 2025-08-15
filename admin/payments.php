<?php
/**************************************************************
 * payments.php (ADMIN)
 * (Updated: Added links to deposit / rental refund receipts)
 *
 * NOTE (Update):
 *   The "Receipt" column now shows:
 *     - Payment receipt (existing)
 *     - Deposit Refund Receipt (if deposit refund processed)
 *     - Rental Refund Receipt (if rental refund processed)
 *
 *   To avoid loading large BLOBs for every row, we just show links
 *   when the refund status is 'processed'. If you want to only show
 *   links when a receipt blob actually exists, you can modify the
 *   SELECT to include IF(dr.refund_receipt_blob IS NULL,0,1) flags.
 **************************************************************/
declare(strict_types=1);
session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

/* ---------- CSRF Token (for creating deposit refund row) ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ---------- Helper Functions ---------- */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function toAmount($v): float {
    if (is_int($v) || is_float($v)) return (float)$v;
    if (is_string($v)) {
        $trim = trim($v);
        if ($trim === '') return 0.0;
        $clean = preg_replace('/[^0-9.\-]/', '', $trim);
        if ($clean === '' || $clean === '.' || $clean === '-' || $clean === '-.') return 0.0;
        return (float)$clean;
    }
    return 0.0;
}
function nf($v, int $dec=2): string { return number_format(toAmount($v), $dec); }

$flash = '';
$error = '';

/* =========================================================
 * POST: ONLY Create Deposit Refund Row
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $error = 'Invalid session token.';
    } elseif (isset($_POST['refund_action']) && $_POST['refund_action'] === 'create_deposit_refund') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        if ($booking_id <= 0) {
            $error = 'Invalid booking.';
        } else {
            $bq = $conn->prepare("
                SELECT booking_id, cust_id,
                       security_deposit_refund,
                       deposit_status,
                       security_deposit,
                       security_deposit_deduction
                  FROM booking
                 WHERE booking_id=? LIMIT 1
            ");
            $bq->bind_param('i', $booking_id);
            $bq->execute();
            $bk = $bq->get_result()->fetch_assoc();
            $bq->close();

            if (!$bk) {
                $error = 'Booking not found.';
            } else {
                $depRefCode = 'DEP-'.$booking_id;

                $rx = $conn->prepare("
                    SELECT refund_id FROM refunds
                     WHERE booking_id=? AND reference_code=? LIMIT 1
                ");
                $rx->bind_param('is', $booking_id, $depRefCode);
                $rx->execute();
                $existing = $rx->get_result()->fetch_assoc();
                $rx->close();

                if (($bk['deposit_status'] ?? '') !== 'pending_refund') {
                    $error = 'Deposit not in pending_refund state.';
                } elseif (toAmount($bk['security_deposit_refund']) <= 0) {
                    $error = 'No refundable deposit amount.';
                } elseif ($existing) {
                    $error = 'Deposit refund row already exists.';
                } else {
                    $amount = toAmount($bk['security_deposit_refund']);
                    $ded    = toAmount($bk['security_deposit_deduction']);
                    $base   = toAmount($bk['security_deposit']);
                    $rate   = $base > 0 ? $amount / $base : 0;
                    $notes  = $ded > 0
                        ? 'Deposit refund after deduction RM '.nf($ded)
                        : 'Deposit refund';

                    $ins = $conn->prepare("
                        INSERT INTO refunds
                          (booking_id, cust_id, amount, refund_status, reference_code, notes,
                           refund_rate, base_amount, created_at)
                        VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, NOW())
                    ");
                    $ins->bind_param(
                        'iidssdd',
                        $booking_id,
                        $bk['cust_id'],
                        $amount,
                        $depRefCode,
                        $notes,
                        $rate,
                        $base
                    );
                    if ($ins->execute()) {
                        $flash = 'Deposit refund row created.';
                    } else {
                        $error = 'Failed to create deposit refund: '.$conn->error;
                    }
                    $ins->close();
                }
            }
        }
    }
}

/* =========================================================
 * GET Filters (Search / Pagination)
 * ========================================================= */
$q            = trim($_GET['q'] ?? '');
$depFilter    = trim($_GET['deposit_status'] ?? '');
$showAction   = ($_GET['show'] ?? '') === 'actionable';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 5;

$where  = [];
$params = [];
$types  = '';

/* Search 'q' */
if ($q !== '') {
    $like = '%'.$q.'%';
    if (ctype_digit($q)) {
        $idVal = (int)$q;
        $where[] = "(p.payment_id = ? OR p.booking_id = ? OR c.full_name LIKE ? OR car.plate_no LIKE ?)";
        $types  .= 'iiss';
        $params[] = $idVal;
        $params[] = $idVal;
        $params[] = $like;
        $params[] = $like;
    } else {
        $where[] = "(c.full_name LIKE ? OR car.plate_no LIKE ? OR car.car_brand LIKE ? OR car.car_model LIKE ?)";
        $types  .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
}

/* Deposit status filter */
if ($depFilter !== '') {
    $where[] = "b.deposit_status = ?";
    $types  .= 's';
    $params[] = $depFilter;
}

/* Actionable filter */
if ($showAction) {
    $where[] = "(
        (b.deposit_status='pending_refund' AND dr.refund_id IS NULL)
        OR (dr.refund_status='pending')
        OR (rr.refund_status='pending')
    )";
}

$whereSQL = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* =========================================================
 * COUNT for Pagination
 * ========================================================= */
$countSQL = "
SELECT COUNT(*) AS total
FROM payment p
LEFT JOIN booking b ON p.booking_id = b.booking_id
LEFT JOIN customer c ON b.cust_id = c.cust_id
LEFT JOIN car ON b.car_id = car.car_id
LEFT JOIN refunds dr
       ON dr.booking_id = p.booking_id
      AND dr.reference_code = CONCAT('DEP-', p.booking_id)
LEFT JOIN refunds rr
       ON rr.booking_id = p.booking_id
      AND rr.reference_code = CONCAT('RENTAL-', p.booking_id)
$whereSQL
";

$countStmt = $conn->prepare($countSQL);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* =========================================================
 * MAIN DATA QUERY
 * (No BLOBs selected to keep listing light)
 * ========================================================= */
$dataSQL = "
SELECT
    p.payment_id,
    p.booking_id,
    p.payment_date,
    p.amount,
    p.payment_method,
    p.payment_status,
    b.status AS booking_status,
    b.deposit_status,
    b.security_deposit,
    b.security_deposit_deduction,
    b.security_deposit_refund,
    b.cust_id,
    c.full_name AS customer_name,
    car.car_brand,
    car.car_model,
    car.plate_no,
    dr.refund_id AS dep_refund_id,
    dr.amount    AS dep_refund_amount,
    dr.refund_status AS dep_refund_status,
    dr.processed_at AS dep_processed_at,
    dr.created_at   AS dep_created_at,
    rr.refund_id AS rental_refund_id,
    rr.amount    AS rental_refund_amount,
    rr.refund_status AS rental_refund_status,
    rr.base_amount AS rental_refund_base,
    rr.refund_rate AS rental_refund_rate,
    rr.processed_at AS rental_refund_processed,
    rr.created_at   AS rental_refund_created
FROM payment p
LEFT JOIN booking b ON p.booking_id = b.booking_id
LEFT JOIN customer c ON b.cust_id = c.cust_id
LEFT JOIN car ON b.car_id = car.car_id
LEFT JOIN refunds dr
       ON dr.booking_id = p.booking_id
      AND dr.reference_code = CONCAT('DEP-', p.booking_id)
LEFT JOIN refunds rr
       ON rr.booking_id = p.booking_id
      AND rr.reference_code = CONCAT('RENTAL-', p.booking_id)
$whereSQL
ORDER BY p.payment_id DESC
LIMIT ? OFFSET ?
";

$dataStmt = $conn->prepare($dataSQL);
if ($types !== '') {
    $bindTypes = $types.'ii';
    $paramsWithLimit = $params;
    $paramsWithLimit[] = $perPage;
    $paramsWithLimit[] = $offset;
    $dataStmt->bind_param($bindTypes, ...$paramsWithLimit);
} else {
    $dataStmt->bind_param('ii', $perPage, $offset);
}
$dataStmt->execute();
$result = $dataStmt->get_result();

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payments & Deposit/Rental Refunds</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { background:#f5f7fb; font-family:'Inter', Arial, sans-serif; margin:0; }
.wrapper { max-width:1380px; margin:34px auto 70px; background:#fff; padding:26px 30px 46px;
  border-radius:18px; box-shadow:0 6px 30px rgba(40,60,120,.08); }
.page-title { margin:0 0 20px; font-size:1.8em; font-weight:800; color:#1f2f40; letter-spacing:.5px; }
.msg { padding:12px 18px; border-radius:10px; font-weight:600; margin:0 0 20px; }
.msg.flash { background:#e6fcf3; color:#176a42; }
.msg.error { background:#ffeded; color:#b33b3b; }
.filter-bar { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 18px; align-items:flex-end; }
.filter-bar form { display:flex; flex-wrap:wrap; gap:10px; }
.filter-bar input[type=text],
.filter-bar select {
  padding:7px 10px; border:1px solid #c9d3e0; border-radius:7px; font-size:.75rem;
}
.filter-bar button {
  background:#2d5fd6; color:#fff; border:none; padding:8px 16px;
  font-size:.7rem; font-weight:700; border-radius:8px; cursor:pointer;
  letter-spacing:.4px; box-shadow:0 2px 6px rgba(0,0,0,.12);
}
.filter-bar button:hover { background:#1f4fab; }
.filter-bar a.reset-link {
  font-size:.68rem; text-decoration:none; color:#5a6575; margin-left:4px;
}
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:.74rem; min-width:1450px; }
th, td { padding:10px 10px; border-bottom:1px solid #e3e9f2; text-align:left; vertical-align:top; }
th { background:#f0f4fa; font-weight:700; font-size:.6rem; letter-spacing:.45px; text-transform:uppercase; color:#2b4d7a; position:sticky; top:0; z-index:2; }
.badge { display:inline-block; padding:4px 8px; border-radius:8px; font-size:.52rem; font-weight:700; letter-spacing:.4px; }
.pay-paid { background:#e6fcf3; color:#1b8b54; }
.pay-pending { background:#fff6dc; color:#a87600; }
.pay-unpaid { background:#ffe6e6; color:#b94141; }
.pay-generic { background:#dde4ed; color:#425165; }
.book-status { background:#e4ecfa; color:#1c4c92; }
.book-cancelled { background:#ffe6e6; color:#b93d3d; }
.book-completed { background:#e6fcf3; color:#1b8b54; }
.book-pending { background:#fff6dc; color:#a87600; }
.inline-sub { font-size:.56rem; line-height:.85rem; color:#344863; }
.small-note { font-size:.52rem; color:#687789; margin-top:4px; }
.action-form { display:inline-block; margin:4px 4px 0 0; }
.action-form button {
    background:#6f42c1; border:none; color:#fff; font-weight:700;
    padding:6px 11px; border-radius:7px; font-size:.52rem; cursor:pointer;
    letter-spacing:.4px; box-shadow:0 2px 6px rgba(0,0,0,.12);
}
.action-form button:hover { background:#5933a1; }
.action-form button.process { background:#2d5fd6; }
.action-form button.process:hover { background:#1f4fab; }
tr.highlight-create { background:linear-gradient(90deg,#fffbe6,#fff4c2); }
tr.highlight-process { background:linear-gradient(90deg,#eaf3ff,#d6e9ff); }
tr.highlight-rental-process { background:linear-gradient(90deg,#d9ffe6,#b3ffc9); }
.legend { font-size:.6rem; margin:0 0 12px; display:flex; gap:14px; flex-wrap:wrap; }
.legend span { display:inline-flex; align-items:center; gap:6px; }
.legend em { width:18px; height:12px; border-radius:3px; display:inline-block; }
.legend .l-create { background:#ffe9a8; }
.legend .l-process { background:#b9d8ff; }
.legend .l-rental-process { background:#b3ffc9; }
.pagination { margin:22px 0 0; display:flex; gap:6px; flex-wrap:wrap; }
.pagination a, .pagination span {
  padding:6px 11px; border:1px solid #c9d3e0; border-radius:6px; font-size:.62rem;
  text-decoration:none; color:#1f3554; font-weight:600;
}
.pagination span.current { background:#2d5fd6; color:#fff; border-color:#2d5fd6; }
.pagination a:hover { background:#eef3fa; }
.report-breadcrumb { font-size: 1em; color: #92a2b3; margin-bottom: 10px; }
.report-breadcrumb a { color: #2b5cbc; text-decoration: none; font-weight: 700; }
.report-breadcrumb .inactive { color: #92a2b3; font-weight: 400; text-decoration: none; }
.report-breadcrumb .active { color: #254d84; font-weight: 600; }
.receipt-links a {
    display:block;
    text-decoration:none;
    color:#2d5fd6;
    font-weight:600;
    font-size:.62rem;
    margin:2px 0;
}
.receipt-links a:hover { text-decoration:underline; }
</style>
</head>
<body>
<div class="wrapper">
    <div class="report-breadcrumb">
        <a href="admin_dashboard.php" class="active">Dashboard</a>
        <span> / </span>
        <span class="inactive">Payments</span>
    </div>
    <h1 class="page-title">Payments & Deposit/Rental Refunds</h1>

    <?php if ($flash): ?><div class="msg flash"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg error"><?= e($error) ?></div><?php endif; ?>

    <div class="filter-bar">
        <form method="get">
            <div>
                <label style="display:block;font-size:.55rem;color:#516072;font-weight:600;letter-spacing:.4px;">Search</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Booking/Payment ID, Customer, Plate, Brand">
            </div>
            <div>
                <label style="display:block;font-size:.55rem;color:#516072;font-weight:600;letter-spacing:.4px;">Deposit Status</label>
                <select name="deposit_status">
                    <option value="">All</option>
                    <?php
                    $statuses = ['held','pending_refund','refunded','forfeited'];
                    foreach ($statuses as $st) {
                        $sel = $depFilter === $st ? 'selected' : '';
                        echo '<option value="'.e($st).'" '.$sel.'>'.e($st).'</option>';
                    }
                    ?>
                </select>
            </div>
            <div style="padding-top:18px;">
                <label style="font-size:.55rem;color:#516072;font-weight:600;letter-spacing:.4px;display:block;">Action Needed</label>
                <input type="checkbox" id="showAct" name="show" value="actionable" <?= $showAction ? 'checked' : '' ?>>
                <label for="showAct" style="font-size:.6rem;color:#344863;">Only actionable</label>
            </div>
            <div style="align-self:flex-end;">
                <button type="submit">Filter</button>
                <a class="reset-link" href="payments.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="legend">
        <span><em class="l-process"></em> Pending deposit refund row</span>
        <span><em class="l-rental-process"></em> Pending rental refund row</span>
        <span>Total: <?= (int)$total ?> | Page <?= $page ?> / <?= $totalPages ?></span>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Payment ID</th>
                <th>Booking</th>
                <th>Customer</th>
                <th>Car</th>
                <th>Amount (RM)</th>
                <th>Payment Status</th>
                <th>Booking Status</th>
                <th>Deposit Refund</th>
                <th>Rental Refund</th>
                <th>Method / Paid Date</th>
                <th>Receipt(s)</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                        $bookingId = (int)$row['booking_id'];

                        /* Payment badge */
                        $ps = strtolower((string)$row['payment_status']);
                        $pClass = match($ps) {
                            'paid'    => 'pay-paid',
                            'pending' => 'pay-pending',
                            'unpaid'  => 'pay-unpaid',
                            default   => 'pay-generic'
                        };
                        $paymentBadge = '<span class="badge '.$pClass.'">'.e(ucfirst($ps ?: '-')).'</span>';

                        /* Booking badge */
                        $bs = strtolower((string)$row['booking_status']);
                        $bClass = match($bs) {
                            'completed' => 'book-completed',
                            'cancelled' => 'book-cancelled',
                            'pending','waiting_verification','approved' => 'book-pending',
                            default => 'book-status'
                        };
                        $bookingBadge = '<span class="badge '.$bClass.'">'.e(ucfirst($bs ?: '-')).'</span>';

                        /* Deposit details */
                        $depStatus = $row['deposit_status'] ?? 'held';
                        $depAmount = toAmount($row['security_deposit']);
                        $depDed    = toAmount($row['security_deposit_deduction']);
                        $depRefund = toAmount(
                            $row['security_deposit_refund'] !== null && $row['security_deposit_refund'] !== ''
                                ? $row['security_deposit_refund']
                                : max($depAmount - $depDed, 0)
                        );

                        $depRefId     = $row['dep_refund_id'];
                        $depRefStatus = $row['dep_refund_status'];
                        $depRefAmount = toAmount($row['dep_refund_amount']);
                        $depProcessed = $row['dep_processed_at'] ?? '';

                        /* Rental refund details */
                        $rentalRefId     = $row['rental_refund_id'];
                        $rentalRefStatus = $row['rental_refund_status'];
                        $rentalRefAmount = toAmount($row['rental_refund_amount']);
                        $rentalRefBase   = toAmount($row['rental_refund_base']);
                        $rentalRefRate   = toAmount($row['rental_refund_rate']);
                        $rentalRefProcessed = $row['rental_refund_processed'] ?? '';
                        $rentalRefCreated   = $row['rental_refund_created'] ?? '';

                        /* Determine row highlight class */
                        $rowClass = '';
                        if ($depStatus === 'pending_refund' && !$depRefId) {
                            $rowClass = 'highlight-create';
                        } elseif ($depStatus === 'pending_refund' && $depRefStatus === 'pending') {
                            $rowClass = 'highlight-process';
                        }
                        if ($rentalRefId && $rentalRefStatus === 'pending') {
                            $rowClass = 'highlight-rental-process';
                        }

                        /* Deposit cell */
                        $depositCell  = '<div class="inline-sub"><strong>Status:</strong> '.e($depStatus).'</div>';
                        $depositCell .= '<div class="inline-sub">Original: RM '.nf($depAmount).'</div>';
                        $depositCell .= '<div class="inline-sub">Deduction: RM '.nf($depDed).'</div>';
                        $depositCell .= '<div class="inline-sub">Refundable: RM '.nf($depRefund).'</div>';

                        if ($depStatus === 'pending_refund' && $depRefund > 0) {
                            if (!$depRefId) {
                                $depositCell .= '
                                  <form class="action-form" method="post">
                                    <input type="hidden" name="csrf_token" value="'.$csrf.'">
                                    <input type="hidden" name="booking_id" value="'.$bookingId.'">
                                    <button type="submit" name="refund_action" value="create_deposit_refund">Create Refund Row</button>
                                  </form>';
                            } elseif ($depRefStatus === 'pending') {
                                $depositCell .= '<div class="small-note">Pending Row (RM '.nf($depRefAmount).')</div>';
                                $depositCell .= '
                                  <div class="action-form">
                                    <a href="process_refund.php?type=deposit&booking_id='.$bookingId.'">
                                      <button type="button" class="process">Process Refund</button>
                                    </a>
                                  </div>';
                            } else {
                                $depositCell .= '<div class="small-note">Refund '.e($depRefStatus).'</div>';
                            }
                        } elseif ($depStatus === 'refunded') {
                            $depositCell .= '<div class="small-note">Deposit refunded.</div>';
                            if ($depProcessed) {
                                $depositCell .= '<div class="small-note">Processed: '.e(substr($depProcessed,0,16)).'</div>';
                            }
                        } elseif ($depStatus === 'forfeited') {
                            $depositCell .= '<div class="small-note">Deposit forfeited.</div>';
                        }

                        /* Rental refund cell */
                        $rentalRefundCell = '';
                        if ($rentalRefId) {
                            $rentalRefundCell .= '<div class="inline-sub"><strong>Amount:</strong> RM '.nf($rentalRefAmount).'</div>';
                            $rentalRefundCell .= '<div class="inline-sub">Base: RM '.nf($rentalRefBase).', Rate: '.nf($rentalRefRate*100,0).'%</div>';
                            $rentalRefundCell .= '<div class="inline-sub">Status: '.e($rentalRefStatus).'</div>';
                            if ($rentalRefStatus === 'pending') {
                                $rentalRefundCell .= '
                                  <div class="action-form">
                                    <a href="process_refund.php?type=rental&refund_id='.(int)$rentalRefId.'">
                                      <button type="button" class="process">Process Rental Refund</button>
                                    </a>
                                  </div>';
                            } elseif ($rentalRefStatus === 'processed') {
                                $rentalRefundCell .= '<div class="small-note">Refund processed.</div>';
                                if ($rentalRefProcessed) {
                                    $rentalRefundCell .= '<div class="small-note">Processed: '.e(substr($rentalRefProcessed,0,16)).'</div>';
                                }
                            }
                        } else {
                            $rentalRefundCell .= '<div class="small-note">-</div>';
                        }

                        /* Receipt links column */
                        $receiptLinks = '<div class="receipt-links">';
                        if (!empty($row['payment_id'])) {
                            $receiptLinks .= '<a href="/customer/payment_receipt_blob.php?payment_id='.e($row['payment_id']).'" target="_blank">Payment Receipt</a>';
                        }
                        if ($depRefId && $depRefStatus === 'processed') {
                            $receiptLinks .= '<a href="refund_receipt.php?id='.e($depRefId).'" target="_blank">Deposit Refund Receipt</a>';
                        }
                        if ($rentalRefId && $rentalRefStatus === 'processed') {
                            $receiptLinks .= '<a href="refund_receipt.php?id='.e($rentalRefId).'" target="_blank">Rental Refund Receipt</a>';
                        }
                        if ($receiptLinks === '<div class="receipt-links">') {
                            $receiptLinks .= '<span style="color:#b2b2b2;">-</span>';
                        }
                        $receiptLinks .= '</div>';
                    ?>
                    <tr class="<?= e($rowClass) ?>">
                        <td><?= e($row['payment_id']) ?></td>
                        <td>
                            <?= e($bookingId) ?><br>
                            <a href="booking_details.php?id=<?= $bookingId ?>"
                               style="font-size:.55rem;color:#2d5fd6;font-weight:600;text-decoration:none;">View</a>
                        </td>
                        <td><?= e($row['customer_name'] ?? '-') ?></td>
                        <td><?= e(($row['car_brand'] ?? '').' '.($row['car_model'] ?? '')) ?><br>
                            <span style="font-size:.55rem;color:#516072;"><?= e($row['plate_no'] ?? '-') ?></span>
                        </td>
                        <td>RM <?= nf($row['amount']) ?></td>
                        <td><?= $paymentBadge ?></td>
                        <td><?= $bookingBadge ?></td>
                        <td><?= $depositCell ?></td>
                        <td><?= $rentalRefundCell ?></td>
                        <td>
                            <?= e($row['payment_method'] ?? '-') ?><br>
                            <span style="font-size:.65em;color:#516072;">
                                <?= e($row['payment_date'] ?? '-') ?>
                            </span>
                        </td>
                        <td><?= $receiptLinks ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="11" style="text-align:center;color:#667688;font-weight:600;padding:26px 0;">No records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $qs = $_GET;
            unset($qs['page']);
            $base = 'payments.php';
            $buildLink = function(int $p) use ($qs,$base) {
                $qs['page'] = $p;
                return $base.'?'.http_build_query($qs);
            };

            if ($page > 1) {
                echo '<a href="'.e($buildLink(1)).'">&laquo; First</a>';
                echo '<a href="'.e($buildLink($page-1)).'">&lsaquo; Prev</a>';
            }

            $start = max(1, $page - 3);
            $end   = min($totalPages, $page + 3);
            for ($p=$start; $p <= $end; $p++) {
                if ($p === $page) {
                    echo '<span class="current">'.e($p).'</span>';
                } else {
                    echo '<a href="'.e($buildLink($p)).'">'.e($p).'</a>';
                }
            }

            if ($page < $totalPages) {
                echo '<a href="'.e($buildLink($page+1)).'">Next &rsaquo;</a>';
                echo '<a href="'.e($buildLink($totalPages)).'">Last &raquo;</a>';
            }
            ?>
        </div>
    <?php endif; ?>

</div>
<?php
$dataStmt->close();
include '../includes/footer.php';
?>
</body>
</html>