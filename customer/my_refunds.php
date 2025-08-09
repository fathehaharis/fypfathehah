<?php
session_start();

// Require login
if (!isset($_SESSION['cust_id'])) {
    header("Location: /customer/login.php");
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'].'/connect.php';

$custId = (int)$_SESSION['cust_id'];

// Fetch refunds for this customer
$stmt = $conn->prepare("
    SELECT refund_id, booking_id, refund_status, amount, created_at, processed_at, notes
    FROM refunds
    WHERE cust_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $custId);
$stmt->execute();
$refunds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Simple (optional) label formatting
function labelStatus(string $s): string {
    switch ($s) {
        case 'pending':   return 'Pending';
        case 'processed': return 'Processed';
        case 'failed':    return 'Failed';
        case 'cancelled': return 'Cancelled';
        default:          return htmlspecialchars($s);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Refunds</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
    body { font-family: Arial, sans-serif; background:#f5f7fa; margin:0; padding:0; }
    .wrap { max-width:900px; margin:40px auto; background:#fff; padding:24px 26px 36px; border-radius:12px; box-shadow:0 4px 14px rgba(0,0,0,0.05); }
    h1 { margin:0 0 20px; font-size:24px; color:#2f377d; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; font-size:14px; border-bottom:1px solid #e6e9ef; text-align:left; }
    th { background:#eef1f7; color:#2f377d; font-weight:600; }
    tr:last-child td { border-bottom:none; }
    .status { font-weight:600; }
    .st-pending { color:#ff9800; }
    .st-processed { color:#2e7d32; }
    .st-failed { color:#d32f2f; }
    .st-cancelled { color:#616161; }
    a.booking-link { color:#1a54b3; text-decoration:none; }
    a.booking-link:hover { text-decoration:underline; }
    .empty { padding:28px 0 10px; color:#666; }
    @media (max-width:680px){
        th, td { font-size:12px; padding:8px 8px; }
        h1 { font-size:20px; }
    }
</style>
</head>
<body>
<?php /* If you have a shared header, include it here: include $_SERVER['DOCUMENT_ROOT'].'/header.php'; */ ?>
<div class="wrap">
    <h1>My Refunds</h1>

    <?php if (!$refunds): ?>
        <div class="empty">You have no refunds.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Refund #</th>
                    <th>Booking</th>
                    <th>Status</th>
                    <th>Amount (RM)</th>
                    <th>Created</th>
                    <th>Processed</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($refunds as $r): ?>
                <?php
                  $statusClass = 'st-'.htmlspecialchars($r['refund_status']);
                  $label = labelStatus($r['refund_status']);
                ?>
                <tr>
                    <td>#<?= (int)$r['refund_id'] ?></td>
                    <td><a class="booking-link" href="/customer/booking_details.php?id=<?= (int)$r['booking_id'] ?>">#<?= (int)$r['booking_id'] ?></a></td>
                    <td class="status <?= $statusClass ?>"><?= $label ?></td>
                    <td><?= number_format((float)$r['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                    <td><?= $r['processed_at'] ? htmlspecialchars($r['processed_at']) : '-' ?></td>
                    <td><?= $r['notes'] ? htmlspecialchars($r['notes']) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>