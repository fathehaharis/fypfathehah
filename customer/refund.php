<?php
/**************************************************************
 * refund.php (CUSTOMER PORTAL)
 *
 * Shows all refunds (deposit + rental) belonging to the logged‑in customer.
 *
 * UPDATE:
 *  - Booking link now points to view_booking.php?booking_id=... (changed from ?id=)
 *
 * Key Features:
 *  - Lists refund rows (reference_code: DEP-{booking_id} or RENTAL-{booking_id})
 *  - Filters: search (booking ID / reference / notes fragment), status
 *  - Pagination
 *  - Badge highlighting unread newly processed refunds (user_unread=1)
 *  - Masked payout account number (last 4 digits)
 *  - Link to booking view page
 *  - Link to PDF receipt only when refund_status='processed'
 *  - Marks processed refunds as read (user_unread=0) after page load
 **************************************************************/
declare(strict_types=1);
session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['cust_id'])) {
    header('Location: login.php');
    exit;
}

$custId = (int)$_SESSION['cust_id'];

/* -------------- Helpers -------------- */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function toAmount($v): float {
    if (is_numeric($v)) return (float)$v;
    if (is_string($v)) {
        $c = preg_replace('/[^0-9.\-]/','', $v);
        return $c === '' ? 0.0 : (float)$c;
    }
    return 0.0;
}
function nf($v, int $d=2): string { return number_format(toAmount($v), $d); }
function maskAcct(?string $acct): string {
    $acct = preg_replace('/\D+/','', (string)$acct);
    if ($acct === '') return '-';
    return strlen($acct) <= 4 ? $acct : str_repeat('•', max(0, strlen($acct)-4)).substr($acct,-4);
}

/* -------------- Input (Filters / Pagination) -------------- */
$q       = trim($_GET['q'] ?? '');
$status  = trim($_GET['status'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;

/* Build WHERE */
$where  = ["r.cust_id = ?"];
$types  = 'i';
$params = [$custId];

if ($q !== '') {
    if (ctype_digit($q)) {
        $id = (int)$q;
        $where[] = "(r.booking_id = ? OR r.refund_id = ? OR r.reference_code LIKE ? OR r.notes LIKE ?)";
        $types  .= 'iiss';
        $params[] = $id;
        $params[] = $id;
        $params[] = '%'.$q.'%';
        $params[] = '%'.$q.'%';
    } else {
        $where[] = "(r.reference_code LIKE ? OR r.notes LIKE ?)";
        $types  .= 'ss';
        $params[] = '%'.$q.'%';
        $params[] = '%'.$q.'%';
    }
}

if ($status !== '') {
    $where[] = "r.refund_status = ?";
    $types  .= 's';
    $params[] = $status;
}

$whereSql = 'WHERE '.implode(' AND ', $where);

/* -------------- Count total -------------- */
$countSql = "SELECT COUNT(*) AS total
             FROM refunds r
             $whereSql";
$cntStmt = $conn->prepare($countSql);
$cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int)$cntStmt->get_result()->fetch_assoc()['total'];
$cntStmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* -------------- Fetch rows -------------- */
$sql = "SELECT r.refund_id,
               r.booking_id,
               r.amount,
               r.refund_status,
               r.reference_code,
               r.notes,
               r.processed_at,
               r.created_at,
               r.payout_bank_name,
               r.payout_bank_account_no,
               r.user_unread
        FROM refunds r
        $whereSql
        ORDER BY r.refund_id DESC
        LIMIT ? OFFSET ?";

$typesData = $types . 'ii';
$paramsData = $params;
$paramsData[] = $perPage;
$paramsData[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($typesData, ...$paramsData);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) $rows[] = $row;
$stmt->close();

/* -------------- Mark processed unread as read -------------- */
if ($rows) {
    $idsToMark = [];
    foreach ($rows as $r) {
        if ($r['refund_status'] === 'processed' && (int)$r['user_unread'] === 1) {
            $idsToMark[] = (int)$r['refund_id'];
        }
    }
    if ($idsToMark) {
        $in = implode(',', array_fill(0, count($idsToMark), '?'));
        $markSql = "UPDATE refunds SET user_unread=0 WHERE cust_id=? AND refund_id IN ($in)";
        $markTypes = 'i' . str_repeat('i', count($idsToMark));
        $markParams = array_merge([$custId], $idsToMark);
        $markStmt = $conn->prepare($markSql);
        $markStmt->bind_param($markTypes, ...$markParams);
        $markStmt->execute();
        $markStmt->close();
    }
}

/* -------------- URL builder for pagination -------------- */
function buildPageLink(int $p): string {
    $qs = $_GET;
    $qs['page'] = $p;
    return 'refund.php?'.http_build_query($qs);
}

/* -------------- Include header (adjust path) -------------- */
include '../includes/customer_header.php'; // Adjust if different
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Refunds</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body { background:#f5f7fb; margin:0; font-family:Arial,Helvetica,sans-serif; }
.wrapper { max-width:1180px; margin:36px auto 80px; background:#fff; padding:34px 40px 54px;
  border-radius:20px; box-shadow:0 6px 30px rgba(40,60,120,.08); }
h1 { margin:0 0 24px; font-size:1.9rem; color:#1e3c60; }
.filter-bar { display:flex; flex-wrap:wrap; gap:12px; margin:0 0 22px; }
.filter-bar form { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
.filter-bar label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#2b3f5f; display:block; margin:0 0 6px; }
.filter-bar input[type=text],
.filter-bar select {
  padding:10px 12px; border:1.5px solid #c8d2e1; border-radius:8px; font-size:.78rem; background:#fafcff;
}
.filter-bar button {
  background:#2d5fd6; color:#fff; border:none; padding:11px 20px; border-radius:8px;
  font-size:.72rem; font-weight:700; cursor:pointer; letter-spacing:.5px; box-shadow:0 2px 8px rgba(0,0,0,.10);
}
.filter-bar button:hover { background:#1f4fab; }
.filter-bar a.reset-link { font-size:.66rem; color:#566b86; text-decoration:none; font-weight:600; margin-left:4px; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:.72rem; min-width:1080px; }
th, td { padding:12px 12px; border-bottom:1px solid #e2e8f1; text-align:left; vertical-align:top; }
th { background:#f1f5fb; font-weight:700; font-size:.58rem; letter-spacing:.5px; text-transform:uppercase; color:#2d4f7a; position:sticky; top:0; z-index:2; }
.badge { display:inline-block; padding:5px 9px; border-radius:8px; font-size:.55rem; font-weight:700; letter-spacing:.45px; }
.badge.pending { background:#fff3cd; color:#a07200; }
.badge.processed { background:#e6fcf3; color:#176a42; }
.badge.new { background:#2d5fd6; color:#fff; margin-left:6px; }
.ref-type { font-size:.55rem; font-weight:700; letter-spacing:.45px; padding:4px 8px; border-radius:6px; }
.ref-type.deposit { background:#ffe7cc; color:#a24d00; }
.ref-type.rental  { background:#e0e8ff; color:#274aa8; }
.actions a {
  display:inline-block; text-decoration:none; font-weight:600; font-size:.6rem;
  color:#2d5fd6; padding:4px 0;
}
.actions a:hover { text-decoration:underline; }
.notes { font-size:.6rem; color:#596c82; line-height:1.05rem; white-space:pre-line; }
.pagination { margin:30px 0 0; display:flex; gap:6px; flex-wrap:wrap; }
.pagination a, .pagination span {
  padding:7px 12px; border:1px solid #c9d3e0; border-radius:7px; font-size:.62rem;
  text-decoration:none; color:#1f3554; font-weight:600; background:#fff;
}
.pagination span.current { background:#2d5fd6; color:#fff; border-color:#2d5fd6; }
.pagination a:hover { background:#eef3fa; }
.empty { text-align:center; padding:46px 0; font-weight:600; color:#65768b; }
.small { font-size:.55rem; color:#5c6d80; }
.mask { font-family:monospace; letter-spacing:1px; }
</style>
</head>
<body>
<?php include '../includes/header.php'; ?>
<div class="wrapper">
    <h1>My Refunds</h1>

    <div class="filter-bar">
        <form method="get">
            <div>
                <label for="q">Search</label>
                <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Booking / Reference / Notes">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All</option>
                    <option value="pending"   <?= $status==='pending'?'selected':''; ?>>Pending</option>
                    <option value="processed" <?= $status==='processed'?'selected':''; ?>>Processed</option>
                </select>
            </div>
            <div style="align-self:flex-end;">
                <button type="submit">Filter</button>
                <a class="reset-link" href="refund.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Booking</th>
                    <th>Amount (RM)</th>
                    <th>Status</th>
                    <th>Payout Bank</th>
                    <th>Payout Account</th>
                    <th>Created</th>
                    <th>Processed</th>
                    <th>Notes / Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="11" class="empty">No refund records found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r):
                    $type = (strpos($r['reference_code'],'DEP-') === 0) ? 'deposit' : 'rental';
                    $statusBadge = '<span class="badge '.h($r['refund_status']).'">'.h(strtoupper($r['refund_status'])).'</span>';
                    if ($r['refund_status']==='processed' && (int)$r['user_unread']===1) {
                        $statusBadge .= ' <span class="badge new">NEW</span>';
                    }
                    $payoutAcct = maskAcct($r['payout_bank_account_no'] ?? '');
                    $payoutBank = $r['payout_bank_name'] ?: '-';
                    $notes = $r['notes'] ?: '';
                    $receiptLink = '';
                    if ($r['refund_status']==='processed') {
                        $receiptLink = '<a href="/admin/refund_receipt.php?id='.h($r['refund_id']).'" target="_blank">View Receipt</a>';
                    }
                    ?>
                    <tr>
                        <td><?= h($r['refund_id']) ?></td>
                        <td><?= h($r['reference_code']) ?></td>
                        <td>
                            <span class="ref-type <?= h($type) ?>"><?= h(strtoupper($type)) ?></span>
                        </td>
                        <td>
                            <?= h($r['booking_id']) ?><br>
                            <a class="small" style="color:#2d5fd6;text-decoration:none;font-weight:600;"
                               href="view_booking.php?booking_id=<?= h($r['booking_id']) ?>">Booking</a>
                        </td>
                        <td>RM <?= nf($r['amount']) ?></td>
                        <td><?= $statusBadge ?></td>
                        <td><?= h($payoutBank) ?></td>
                        <td class="mask"><?= h($payoutAcct) ?></td>
                        <td><?= h(substr($r['created_at'],0,16)) ?></td>
                        <td><?= $r['processed_at'] ? h(substr($r['processed_at'],0,16)) : '—' ?></td>
                        <td class="actions">
                            <?php if ($notes): ?>
                                <div class="notes"><?= h($notes) ?></div>
                            <?php endif; ?>
                            <?php if ($receiptLink): ?>
                                <div><?= $receiptLink ?></div>
                            <?php elseif ($r['refund_status']==='pending'): ?>
                                <span class="small" style="color:#8a6d00;">Awaiting processing</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= h(buildPageLink(1)) ?>">&laquo; First</a>
                <a href="<?= h(buildPageLink($page-1)) ?>">&lsaquo; Prev</a>
            <?php endif;
            $start = max(1, $page - 3);
            $end   = min($totalPages, $page + 3);
            for ($p=$start; $p <= $end; $p++):
                if ($p === $page): ?>
                    <span class="current"><?= h($p) ?></span>
                <?php else: ?>
                    <a href="<?= h(buildPageLink($p)) ?>"><?= h($p) ?></a>
                <?php endif;
            endfor;
            if ($page < $totalPages): ?>
                <a href="<?= h(buildPageLink($page+1)) ?>">Next &rsaquo;</a>
                <a href="<?= h(buildPageLink($totalPages)) ?>">Last &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
<?php
include '../includes/footer.php';
?>
</body>
</html>