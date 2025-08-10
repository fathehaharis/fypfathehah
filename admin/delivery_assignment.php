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

$flash = $error = "";

/* --- Date filter input --- */
$selected_date = isset($_GET['pickup_date']) ? trim($_GET['pickup_date']) : '';
$date_sql = '';
$date_param = '';
if ($selected_date) {
    $date_sql = " AND DATE(b.pickup_datetime) = ?";
    $date_param = $selected_date;
}

/* --- Input: search & pagination --- */
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

/* --- Count query --- */
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

/* --- Active staff list --- */
$staff = [];
$res = $conn->query("SELECT staff_id, full_name FROM delivery_staff WHERE status='active' ORDER BY full_name");
while ($row = $res->fetch_assoc()) {
    $staff[(int)$row['staff_id']] = $row['full_name'];
}
$res->close();

/* --- Data query (with date filter if set) --- */
$data_sql = "
    SELECT s.service_id, s.booking_id, s.service_type, s.fee, s.staff_id, s.status,
           s.delivery_location, s.return_location,
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

/* --- Flash via GET --- */
if (isset($_GET['flash'])) {
    $flash = h($_GET['flash']);
}

/* --- Staff assignment --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = "Invalid session token.";
    } else {
        $service_id = (int)($_POST['service_id'] ?? 0);
        $staff_id = ($_POST['staff_id'] !== "") ? (int)$_POST['staff_id'] : null;

        $chk = $conn->prepare("
            SELECT 1
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
        $valid = $chk->get_result()->fetch_row();
        $chk->close();

        if (!$valid) {
            $error = "Cannot assign: booking not (confirmed + deposit held + paid).";
        } else {
            if ($staff_id === null) {
                $stmt = $conn->prepare("UPDATE service SET staff_id = NULL WHERE service_id = ?");
                $stmt->bind_param("i", $service_id);
            } else {
                $stmt = $conn->prepare("UPDATE service SET staff_id = ? WHERE service_id = ?");
                $stmt->bind_param("ii", $staff_id, $service_id);
            }
            $stmt->execute();
            $stmt->close();
            if (!$error) {
                $flash = "Service #$service_id updated.";
                $searchPersist = urlencode($_GET['search'] ?? $_POST['search'] ?? "");
                $datePersist = urlencode($_GET['pickup_date'] ?? $_POST['pickup_date'] ?? "");
                header("Location: delivery_assignment.php?flash=" . urlencode($flash) . "&search=" . $searchPersist . "&pickup_date=" . $datePersist);
                exit;
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
.flash{background:#e6f9ed;border:1px solid #b7e9c7;padding:10px 14px;border-radius:8px;color:#1d6a3b;font-size:0.9rem;margin-bottom:12px;}
.error{background:#ffe8e5;border:1px solid #f4b7ae;color:#952c21;}
.filter-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
.filter-row input[type="date"]{padding:8px 11px;border:1px solid #b7c4d6;border-radius:8px;background:#fbfdff;}
.filter-row input[type="text"]{padding:8px 11px;border:1px solid #b7c4d6;border-radius:8px;background:#fbfdff;}
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
.unassigned{color:#9a3d1d;font-size:0.65rem;font-weight:700;margin-left:6px;}
select.staff-select{padding:5px 7px;border:1px solid #b8c5d6;border-radius:6px;background:#f8fbfe;font-size:0.75rem;max-width:160px;}
button.save-btn{padding:5px 10px;background:#2b5cbc;color:#fff;border:none;border-radius:6px;font-size:0.68rem;font-weight:600;cursor:pointer;}
button.save-btn:hover{background:#224b9a;}
.pagination{display:flex;gap:6px;flex-wrap:wrap;margin-top:18px;}
.pagination a, .pagination span{padding:6px 10px;background:#fff;border:1px solid #d3dde9;border-radius:6px;font-size:0.72rem;text-decoration:none;color:#2b5cbc;font-weight:600;}
.pagination a:hover{background:#2b5cbc;color:#fff;}
.pagination .current{background:#2b5cbc;color:#fff;border-color:#2b5cbc;pointer-events:none;}
.loc-cell{white-space:normal;line-height:1.2;max-width:220px;}
.report-breadcrumb {
    font-size: 1em;
    color: #92a2b3;
    margin-bottom: 10px;
}
.report-breadcrumb a {
    color: #2b5cbc;
    text-decoration: none;
    font-weight: 700;
}
.report-breadcrumb .inactive {
    color: #92a2b3;
    font-weight: 400;
    text-decoration: none;
}
.report-breadcrumb .active {
    color: #254d84;
    font-weight: 600;
    text-decoration: none;
}
@media (max-width:900px){
  .assign th, .assign td{font-size:0.74rem;padding:7px 8px;}
  .filter-row input[type=text]{min-width:180px;}
}
</style>
<div class="wrap">
    <!-- Breadcrumb Navigation -->
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
        </div>
    </div>

    <div class="table-wrap">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
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
                    <th>Status</th>
                    <th>Assign Staff</th>
                    <th>Save</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$services): ?>
                <tr><td colspan="12" style="text-align:center;color:#777;">No services match your filter.</td></tr>
            <?php else: ?>
                <?php foreach ($services as $row): ?>
                    <tr>
                        <td><?= (int)$row['service_id'] ?></td>
                        <td><?= h(ucwords(str_replace('_',' ', $row['service_type']))) ?></td>
                        <td><?= fmtFee($row['fee']) ?></td>
                        <td><?= h($row['booking_id']) ?></td>
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
                            <?php
                            $st = $row['status'];
                            $cls = 'sb-' . $st;
                            echo '<span class="status-badge '.h($cls).'">'.h(ucwords(str_replace('_',' ', $st))).'</span>';
                            if (empty($row['staff_id'])) {
                                echo '<span class="unassigned">UNASSIGNED</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <select name="staff_id" class="staff-select">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($staff as $sid => $sname): ?>
                                    <option value="<?= $sid ?>" <?= ($row['staff_id'] == $sid) ? 'selected':''; ?>>
                                        <?= h($sname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="text-align:center;">
                            <button type="submit" class="save-btn" name="update_service" value="1">Save</button>
                            <input type="hidden" name="service_id" value="<?= (int)$row['service_id'] ?>">
                            <input type="hidden" name="search" value="<?= h($search) ?>">
                            <input type="hidden" name="pickup_date" value="<?= h($selected_date) ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </form>
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