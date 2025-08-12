<?php
include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDT($dt){ return $dt ? date('d/m/Y H:i', strtotime($dt)) : '-'; }
function fmtFee($fee){ return $fee === null || $fee === '' ? '-' : number_format((float)$fee, 2); }

/**
 * Check time overlaps between new events and a staff member's existing events.
 * Each event is treated as a window [start, start + durationMins).
 * Returns ['conflicts' => array of messages, 'details' => array] if conflicts exist.
 */
function checkStaffTimeConflicts(mysqli $conn, int $staffId, int $currentServiceId, array $newEvents, int $durationMins = 60): array
{
    $sql = "
        SELECT s.service_id, b.booking_id, b.pickup_datetime, b.return_datetime
        FROM service s
        JOIN booking b ON s.booking_id = b.booking_id
        WHERE s.staff_id = ?
          AND s.service_id <> ?
          AND (b.pickup_datetime IS NOT NULL OR b.return_datetime IS NOT NULL)
        ORDER BY s.service_id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $staffId, $currentServiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $conflicts = [];
    $details = [];

    foreach ($newEvents as $ne) {
        if (empty($ne['datetime'])) continue;
        $newStart = new DateTime($ne['datetime']);
        $newEnd   = (clone $newStart)->modify("+{$durationMins} minutes");
        $newLabel = $ne['label'];

        foreach ($existing as $ex) {
            if (!empty($ex['pickup_datetime'])) {
                $exStart = new DateTime($ex['pickup_datetime']);
                $exEnd   = (clone $exStart)->modify("+{$durationMins} minutes");
                $overlap = ($newStart < $exEnd) && ($exStart < $newEnd);
                if ($overlap) {
                    $conflicts[] = "Conflicts with Booking #{$ex['booking_id']} pickup at ".fmtDT($ex['pickup_datetime'])." (busy until ".fmtDT($exEnd->format('Y-m-d H:i:s')).").";
                    $details[] = [
                        'newEvent' => $newLabel,
                        'newStart' => $newStart->format('Y-m-d H:i:s'),
                        'suggest'  => $exEnd->format('Y-m-d H:i:s'),
                        'with'     => "booking {$ex['booking_id']} pickup",
                    ];
                }
            }
            if (!empty($ex['return_datetime'])) {
                $exStart = new DateTime($ex['return_datetime']);
                $exEnd   = (clone $exStart)->modify("+{$durationMins} minutes");
                $overlap = ($newStart < $exEnd) && ($exStart < $newEnd);
                if ($overlap) {
                    $conflicts[] = "Conflicts with Booking #{$ex['booking_id']} return at ".fmtDT($ex['return_datetime'])." (busy until ".fmtDT($exEnd->format('Y-m-d H:i:s')).").";
                    $details[] = [
                        'newEvent' => $newLabel,
                        'newStart' => $newStart->format('Y-m-d H:i:s'),
                        'suggest'  => $exEnd->format('Y-m-d H:i:s'),
                        'with'     => "booking {$ex['booking_id']} return",
                    ];
                }
            }
        }
    }

    return ['conflicts' => $conflicts, 'details' => $details];
}

$flash = $error = "";

/* Date filter input */
$selected_date = isset($_GET['pickup_date']) ? trim($_GET['pickup_date']) : '';
$date_sql = '';
$date_param = '';
if ($selected_date) {
    $date_sql = " AND DATE(b.pickup_datetime) = ?";
    $date_param = $selected_date;
}

/* Input: search & pagination */
$search = trim($_GET['search'] ?? ($_POST['search'] ?? ''));
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$baseCondition = "b.status = 'confirmed' AND b.deposit_status = 'held' AND EXISTS (SELECT 1 FROM payment p WHERE p.booking_id = b.booking_id AND p.payment_status = 'paid')";
$where = "WHERE $baseCondition";
$params = [];
$types = '';
if ($search !== '') {
    $where .= " AND (s.service_type LIKE ? OR cu.username LIKE ? OR c.car_brand LIKE ? OR c.car_model LIKE ? OR b.booking_id LIKE ?)";
    $like = "%$search%";
    for ($i=0;$i<5;$i++){ $params[] = $like; $types .= 's'; }
}
if ($selected_date) {
    $where .= $date_sql;
    $params[] = $date_param;
    $types .= 's';
}

/* Count query */
$count_sql = "
    SELECT COUNT(*) AS total
    FROM service s
    JOIN booking b ON s.booking_id = b.booking_id
    JOIN car c ON b.car_id = c.car_id
    JOIN customer cu ON b.cust_id = cu.cust_id
    $where
";
$stmt = $conn->prepare($count_sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$total_pages = max(1, (int)ceil($total / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

/* Active staff list */
$staff = [];
$res = $conn->query("SELECT staff_id, full_name FROM delivery_staff WHERE status='active' ORDER BY full_name");
while ($row = $res->fetch_assoc()) {
    $staff[(int)$row['staff_id']] = $row['full_name'];
}
$res->close();

/* Data query */
$data_sql = "
    SELECT s.service_id, s.booking_id, s.service_type, s.fee, s.staff_id,
           s.delivery_location, s.return_location,
           s.pickup_status, s.pickup_status_at,
           s.return_status, s.return_status_at,
           s.status,
           b.pickup_datetime, b.return_datetime,
           cu.username AS customer_name,
           c.car_brand, c.car_model
    FROM service s
    JOIN booking b ON s.booking_id = b.booking_id
    JOIN car c ON b.car_id = c.car_id
    JOIN customer cu ON b.cust_id = cu.cust_id
    $where
    ORDER BY s.service_id DESC
    LIMIT ? OFFSET ?
";
if ($types) {
    $types2 = $types . "ii";
    $runParams = $params;
    $runParams[] = $per_page;
    $runParams[] = $offset;
    $stmt = $conn->prepare($data_sql);
    $stmt->bind_param($types2, ...$runParams);
} else {
    $stmt = $conn->prepare($data_sql);
    $stmt->bind_param("ii", $per_page, $offset);
}
$stmt->execute();
$resData = $stmt->get_result();
$services = $resData->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Flash via GET */
if (isset($_GET['flash'])) {
    $flash = h($_GET['flash']);
}

/* Staff assignment (per-row form) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = "Invalid session token.";
    } else {
        $service_id = (int)($_POST['service_id'] ?? 0);
        $new_staff_id = ($_POST['staff_id'] !== "") ? (int)$_POST['staff_id'] : null;

        $searchPersist = urlencode($_POST['search'] ?? "");
        $datePersist = urlencode($_POST['pickup_date'] ?? "");

        // Validate booking gates and fetch statuses + times
        $chk = $conn->prepare("
            SELECT s.service_type, s.status, s.pickup_status, s.return_status,
                   b.booking_id, b.pickup_datetime, b.return_datetime
            FROM service s
            JOIN booking b ON s.booking_id = b.booking_id
            WHERE s.service_id = ?
              AND b.status = 'confirmed'
              AND b.deposit_status = 'held'
              AND EXISTS (SELECT 1 FROM payment p WHERE p.booking_id = b.booking_id AND p.payment_status = 'paid')
            LIMIT 1
        ");
        $chk->bind_param("i", $service_id);
        $chk->execute();
        $svcRow = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$svcRow) {
            $error = "Cannot assign: booking not (confirmed + deposit held + paid).";
        } else {
            // NEW RULE: lock assignment if either pickup OR return leg is delivered
            $isLocked = ($svcRow['pickup_status'] === 'delivered' || $svcRow['return_status'] === 'delivered');

            if ($isLocked) {
                $error = "Assignment locked: one or more legs already delivered.";
            } else {
                if ($new_staff_id === null) {
                    // Unassign (allowed if not locked)
                    $stmt = $conn->prepare("UPDATE service SET staff_id = NULL WHERE service_id = ?");
                    $stmt->bind_param("i", $service_id);
                    $stmt->execute();
                    $stmt->close();
                    $flash = "Service #$service_id unassigned.";
                    header("Location: delivery_assignment.php?flash=" . urlencode($flash) . "&search=" . $searchPersist . "&pickup_date=" . $datePersist);
                    exit;
                } else {
                    // Conflict check for the new staff
                    $EVENT_WINDOW_MINS = 60;
                    $newEvents = [];
                    if (!empty($svcRow['pickup_datetime'])) {
                        $newEvents[] = ['label' => 'pickup', 'datetime' => $svcRow['pickup_datetime']];
                    }
                    if ($svcRow['service_type'] === 'pickup_and_return' && !empty($svcRow['return_datetime'])) {
                        $newEvents[] = ['label' => 'return', 'datetime' => $svcRow['return_datetime']];
                    }

                    if (!empty($newEvents)) {
                        $conf = checkStaffTimeConflicts($conn, $new_staff_id, $service_id, $newEvents, $EVENT_WINDOW_MINS);
                        if (!empty($conf['conflicts'])) {
                            $msg = "Assignment blocked: staff has overlapping tasks within {$EVENT_WINDOW_MINS} minutes window.\n";
                            foreach ($conf['conflicts'] as $c) {
                                $msg .= "• " . $c . "\n";
                            }
                            $suggestions = [];
                            foreach ($conf['details'] as $d) {
                                $key = $d['newEvent'];
                                if (!isset($suggestions[$key]) || strtotime($d['suggest']) > strtotime($suggestions[$key])) {
                                    $suggestions[$key] = $d['suggest'];
                                }
                            }
                            if (!empty($suggestions)) {
                                $msg .= "Earliest possible times:\n";
                                foreach ($suggestions as $label => $t) {
                                    $msg .= "• " . ucfirst($label) . ": " . fmtDT($t) . "\n";
                                }
                            }
                            $error = nl2br(h($msg));
                        }
                    }

                    if (!$error) {
                        $stmt = $conn->prepare("UPDATE service SET staff_id = ? WHERE service_id = ?");
                        $stmt->bind_param("ii", $new_staff_id, $service_id);
                        $stmt->execute();
                        $stmt->close();

                        $flash = "Service #$service_id assigned.";
                        header("Location: delivery_assignment.php?flash=" . urlencode($flash) . "&search=" . $searchPersist . "&pickup_date=" . $datePersist);
                        exit;
                    }
                }
            }
        }
    }
}

?>
<?php include 'admin_header.php'; ?>
<style>
body{background:#f5f7fa;font-family:system-ui,Arial,sans-serif;}
.wrap{max-width:1200px;margin:30px auto 60px;padding:0 18px;}
h1{font-size:1.25rem;margin:0 0 18px;color:#254d84;}
.simple-card{background:#fff;border:1px solid #dde5ef;border-radius:10px;padding:16px 18px;margin-bottom:22px;}
.flash{background:#e6f9ed;border:1px solid #b7e9c7;padding:10px 14px;border-radius:8px;color:#1d6a3b;font-size:0.9rem;margin-bottom:12px;white-space:pre-line;}
.error{background:#ffe8e5;border:1px solid #f4b7ae;color:#952c21;}
.filter-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
.filter-row input[type="date"], .filter-row input[type="text"]{padding:8px 11px;border:1px solid #b7c4d6;border-radius:8px;background:#fbfdff;}
.filter-row button{padding:8px 16px;background:#2b5cbc;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;}
.filter-row a.reset{color:#666;font-size:0.8rem;text-decoration:none;margin-left:4px;}
.filter-row a.reset:hover{text-decoration:underline;}
.table-wrap{overflow-x:auto;}
table.assign{width:100%;border-collapse:collapse;background:#fff;border:1px solid #d9e2ec;border-radius:10px;overflow:hidden;}
.assign th, .assign td{padding:10px 12px;border-bottom:1px solid #edf1f5;text-align:left;font-size:0.85rem;white-space:nowrap;}
.assign th{background:#f1f5fa;font-weight:600;font-size:0.72rem;letter-spacing:0.5px;color:#325d92;text-transform:uppercase;}
.assign tr:last-child td{border-bottom:none;}
.status-badge{padding:4px 8px;border-radius:14px;font-size:0.65rem;font-weight:600;letter-spacing:.5px;display:inline-block;}
.sb-pending{background:#ffeccc;color:#ab6a00;}
.sb-out_for_delivery{background:#dff2ff;color:#005d9f;}
.sb-delivered{background:#e0f9e6;color:#1b7a3c;}
.unassigned{color:#9a3d1d;font-size:0.65rem;font-weight:700;margin-left:6px;display:inline-block;}
select.staff-select{padding:5px 7px;border:1px solid #b8c5d6;border-radius:6px;background:#f8fbfe;font-size:0.75rem;max-width:180px;}
button.save-btn{padding:5px 10px;background:#2b5cbc;color:#fff;border:none;border-radius:6px;font-size:0.68rem;font-weight:600;cursor:pointer;}
button.save-btn[disabled]{opacity:0.6;cursor:not-allowed;}
button.save-btn:hover{background:#224b9a;}
.pagination{display:flex;gap:6px;flex-wrap:wrap;margin-top:18px;}
.pagination a, .pagination span{padding:6px 10px;background:#fff;border:1px solid #d3dde9;border-radius:6px;font-size:0.72rem;text-decoration:none;color:#2b5cbc;font-weight:600;}
.pagination a:hover{background:#2b5cbc;color:#fff;}
.pagination .current{background:#2b5cbc;color:#fff;border-color:#2b5cbc;pointer-events:none;}
.loc-cell{white-space:normal;line-height:1.2;max-width:220px;}
.status-time{display:block;font-size:.68rem;color:#6a7c90;margin-top:4px;}
.lock-note{font-size:.72rem;color:#687891;font-weight:700;margin-left:6px;}
.report-breadcrumb{font-size:1em;color:#92a2b3;margin-bottom:10px;}
.report-breadcrumb a{color:#2b5cbc;text-decoration:none;font-weight:700;}
.report-breadcrumb .inactive{color:#92a2b3;font-weight:400;text-decoration:none;}
.report-breadcrumb .active{color:#254d84;font-weight:600;text-decoration:none;}
@media (max-width:900px){
  .assign th, .assign td{font-size:0.74rem;padding:7px 8px;}
  .filter-row input[type=text]{min-width:180px;}
}
</style>

<div class="wrap">
    <div class="report-breadcrumb">
        <a href="admin_dashboard.php" class="active">Dashboard</a>
        <span> / </span>
        <span class="inactive">Delivery Assignment</span>
    </div>

    <h1>Delivery Assignment</h1>

    <?php if($flash): ?><div class="flash"><?= $flash ?></div><?php endif; ?>
    <?php if($error): ?><div class="flash error"><?= $error ?></div><?php endif; ?>

    <div class="simple-card">
        <form method="get" class="filter-row" autocomplete="off">
            <input type="text" name="search" placeholder="Search booking / user / car / service..." value="<?= h($search) ?>">
            <input type="date" name="pickup_date" value="<?= h($selected_date) ?>">
            <button type="submit">Filter</button>
            <?php if($search || $selected_date): ?>
                <a class="reset" href="delivery_assignment.php">Clear</a>
            <?php endif; ?>
        </form>
        <div style="margin-top:10px;font-size:0.7rem;color:#666;">
            Note: Staff are considered busy for 60 minutes starting at each pickup/return time. Overlaps within that window are blocked.
        </div>
    </div>

    <div class="table-wrap">
        <table class="assign">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Service</th>
                    <th>Fee (RM)</th>
                    <th>Booking</th>
                    <th>Customer</th>
                    <th>Car</th>
                    <th>Pickup</th>
                    <th>Return</th>
                    <th>Location(s)</th>
                    <th>Pickup Status</th>
                    <th>Return Status</th>
                    <th>Assign Staff</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$services): ?>
                <tr><td colspan="12" style="text-align:center;color:#777;">No services match your filter.</td></tr>
            <?php else: ?>
                <?php foreach ($services as $row): ?>
                    <?php
                        $pickupBadge = $row['pickup_status'] ? 'sb-'.$row['pickup_status'] : '';
                        $returnBadge = $row['return_status'] ? 'sb-'.$row['return_status'] : '';
                        // NEW RULE: lock if either leg delivered
                        $isLocked = ($row['pickup_status'] === 'delivered' || $row['return_status'] === 'delivered');
                    ?>
                    <tr>
                        <td><?= (int)$row['service_id'] ?></td>
                        <td><?= h(ucwords(str_replace('_',' ', $row['service_type']))) ?></td>
                        <td><?= fmtFee($row['fee']) ?></td>
                        <td>
                            <a href="booking_details.php?id=<?= (int)$row['booking_id'] ?>" style="color:#227be9;font-weight:700;text-decoration:underline;">
                                <?= (int)$row['booking_id'] ?>
                            </a>
                        </td>
                        <td><?= h($row['customer_name']) ?></td>
                        <td><?= h($row['car_brand'] . ' ' . $row['car_model']) ?></td>
                        <td><?= fmtDT($row['pickup_datetime']) ?></td>
                        <td><?= fmtDT($row['return_datetime']) ?></td>
                        <td class="loc-cell">
                            <?php
                            if ($row['service_type'] === 'pickup_and_return') {
                                echo "<strong>D:</strong> " . h($row['delivery_location']) . "<br><strong>R:</strong> " . h($row['return_location']);
                            } elseif ($row['service_type'] === 'delivery') {
                                echo h($row['delivery_location']);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($row['pickup_status']): ?>
                                <span class="status-badge <?= h($pickupBadge) ?>"><?= h(ucwords(str_replace('_',' ', $row['pickup_status']))) ?></span>
                                <?php if (!empty($row['pickup_status_at'])): ?>
                                    <span class="status-time"><?= h(fmtDT($row['pickup_status_at'])) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['return_status']): ?>
                                <span class="status-badge <?= h($returnBadge) ?>"><?= h(ucwords(str_replace('_',' ', $row['return_status']))) ?></span>
                                <?php if (!empty($row['return_status_at'])): ?>
                                    <span class="status-time"><?= h(fmtDT($row['return_status_at'])) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isLocked): ?>
                                <select class="staff-select" disabled>
                                    <option>
                                        <?= isset($row['staff_id'], $staff[(int)$row['staff_id']]) ? h($staff[(int)$row['staff_id']]) : '—' ?>
                                    </option>
                                </select>
                                <span class="lock-note">Leg delivered — assignment locked</span>
                            <?php else: ?>
                                <form method="post" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="service_id" value="<?= (int)$row['service_id'] ?>">
                                    <input type="hidden" name="search" value="<?= h($search) ?>">
                                    <input type="hidden" name="pickup_date" value="<?= h($selected_date) ?>">
                                    <select name="staff_id" class="staff-select">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach ($staff as $sid => $sname): ?>
                                            <option value="<?= $sid ?>" <?= ($row['staff_id'] == $sid) ? 'selected':''; ?>>
                                                <?= h($sname) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($row['staff_id'])): ?>
                                        <span class="unassigned">UNASSIGNED</span>
                                    <?php endif; ?>
                                    <button type="submit" class="save-btn" name="update_service" value="1">Save</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php
            $base = 'delivery_assignment.php?search=' . urlencode($search) . '&pickup_date=' . urlencode($selected_date);
            $range = 2;
            if ($page > 1){
                echo '<a href="'.$base.'&page=1">First</a>';
                echo '<a href="'.$base.'&page='.($page-1).'">Prev</a>';
            }
            for ($p = max(1,$page-$range); $p <= min($total_pages,$page+$range); $p++){
                if ($p == $page){
                    echo '<span class="current">'.$p.'</span>';
                } else {
                    echo '<a href="'.$base.'&page='.$p.'">'.$p.'</a>';
                }
            }
            if ($page < $total_pages){
                echo '<a href="'.$base.'&page='.($page+1).'">Next</a>';
                echo '<a href="'.$base.'&page='.$total_pages.'">Last</a>';
            }
            ?>
        </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>