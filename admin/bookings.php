<?php
require_once '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$per_page = 10;
$page = isset($_GET['page']) && ctype_digit($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$now = date('Y-m-d H:i:s');

$whereClauses = [];
$params = [];
$types = '';

if ($search !== '') {
    $pattern = '%'.$search.'%';
    $whereClauses[] = "("
        ."CAST(b.booking_id AS CHAR) LIKE ? OR "
        ."car.car_model LIKE ? OR "
        ."car.car_brand LIKE ? OR "
        ."car.plate_no LIKE ? OR "
        ."DATE_FORMAT(b.pickup_datetime, '%Y-%m-%d %H:%i:%s') LIKE ? OR "
        ."DATE_FORMAT(b.return_datetime, '%Y-%m-%d %H:%i:%s') LIKE ? OR "
        ."IFNULL(p.payment_status,'') LIKE ? OR "
        ."b.status LIKE ?"
        .")";
    for ($i=0;$i<8;$i++) {
        $params[] = $pattern;
        $types .= 's';
    }
}

$whereSQL = $whereClauses ? ('WHERE '.implode(' AND ', $whereClauses)) : '';

/*
 * Count bookings
 */
$countSql = "
    SELECT COUNT(*) AS total
    FROM booking b
    JOIN car ON b.car_id = car.car_id
    LEFT JOIN (
        SELECT booking_id,
               MAX(payment_status) AS payment_status,
               SUM(amount) AS amount_total
        FROM payment
        GROUP BY booking_id
    ) p ON p.booking_id = b.booking_id
    $whereSQL
";
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countRes = $countStmt->get_result();
$total = ($countRes && ($row = $countRes->fetch_assoc())) ? (int)$row['total'] : 0;
$countStmt->close();
$total_pages = max(1, (int)ceil($total / $per_page));

/*
 * Primary image derivation (choose first by sort_order then id)
 */
$imageDerive = "
    SELECT ci.car_id, ci.car_image_id, ci.version
    FROM car_image ci
    JOIN (
        SELECT car_id,
               MIN(CONCAT(LPAD(sort_order,6,'0'), LPAD(car_image_id,10,'0'))) AS min_key
        FROM car_image
        GROUP BY car_id
    ) x ON x.car_id = ci.car_id
       AND CONCAT(LPAD(ci.sort_order,6,'0'), LPAD(ci.car_image_id,10,'0')) = x.min_key
";

/*
 * Main data query
 */
$dataSql = "
    SELECT
        b.booking_id,
        b.car_id,
        b.pickup_datetime,
        b.return_datetime,
        b.status AS booking_status,
        b.total_price,
        car.car_model,
        car.car_brand,
        car.plate_no,
        p.payment_status,
        p.amount_total,
        img.car_image_id,
        img.version AS image_version
    FROM booking b
    JOIN car ON b.car_id = car.car_id
    LEFT JOIN ($imageDerive) img ON img.car_id = car.car_id
    LEFT JOIN (
        SELECT booking_id,
               MAX(payment_status) AS payment_status,
               SUM(amount) AS amount_total
        FROM payment
        GROUP BY booking_id
    ) p ON p.booking_id = b.booking_id
    $whereSQL
    ORDER BY b.booking_id DESC
    LIMIT ? OFFSET ?
";

$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) {
    die("Query prepare failed: ".$conn->error);
}

if ($types) {
    $fullTypes = $types.'ii';
    $paramsWithLimit = $params;
    $paramsWithLimit[] = $per_page;
    $paramsWithLimit[] = $offset;
    $dataStmt->bind_param($fullTypes, ...$paramsWithLimit);
} else {
    $dataStmt->bind_param('ii', $per_page, $offset);
}
$dataStmt->execute();
if ($dataStmt->errno) {
    die("Execute failed: ".$dataStmt->error);
}
$result = $dataStmt->get_result();

$bookings = [];
while ($row = $result->fetch_assoc()) {
    if (!empty($row['car_image_id'])) {
        $row['car_image_url'] = "car_image.php?id=".$row['car_image_id']."&v=".$row['image_version'];
    } else {
        $row['car_image_url'] = "https://via.placeholder.com/90x60?text=No+Image";
    }

    $statusLower = strtolower($row['booking_status']);

    // Tab status mapping (new semantics)
    if (in_array($statusLower, ['pending','waiting_verification'], true)) {
        $tab_status = 'pending_approval';
    } elseif ($statusLower === 'approved') {
        $tab_status = 'pending_payment';
    } elseif (in_array($statusLower, ['cancelled','rejected'], true)) {
        $tab_status = 'cancelled';
    } elseif ($statusLower === 'completed') {
        $tab_status = 'completed';
    } else {
        // For confirmed / others decide by time
        if ($row['pickup_datetime'] > $now) {
            $tab_status = 'upcoming';
        } elseif ($row['pickup_datetime'] <= $now && $row['return_datetime'] >= $now) {
            $tab_status = 'ongoing';
        } else {
            $tab_status = 'other';
        }
    }
    $row['tab_status'] = $tab_status;

    // Final total fallback
    $row['final_total'] = isset($row['total_price']) && $row['total_price'] !== null
        ? (float)$row['total_price']
        : (float)($row['amount_total'] ?? 0);

    $bookings[] = $row;
}
$dataStmt->close();

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bookings</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body {
    background:#f8f9fc;
    font-family: 'Inter', Arial, sans-serif;
    margin:0;
}
.bookings-header {
    margin:30px auto 10px;
    max-width:1000px;
    display:flex;
    flex-direction:column;
    gap:16px;
}
.bookings-breadcrumb { font-size:.98em; color:#92a2b3; margin-bottom:8px; }
.bookings-breadcrumb a { color:#6d87be; text-decoration:none; font-weight:600; }
.bookings-title-row { display:flex; align-items:center; justify-content:space-between; }
.bookings-title { font-size:2em; font-weight:800; color:#232d3b; letter-spacing:.5px; }
.bookings-tabs-row { display:flex; gap:17px; margin-top:8px; border-bottom:1.5px solid #e7eaf4; flex-wrap:wrap; }
.bookings-tabs-row .bookings-tab {
    padding:0 0 7px;
    font-size:1.10em;
    font-weight:500;
    color:#202a35;
    background:none;
    border:none;
    cursor:pointer;
    transition:color .12s;
    border-bottom:2.5px solid transparent;
}
.bookings-tabs-row .bookings-tab.active {
    color:#233ca0;
    border-bottom:2.5px solid #293e9b;
    font-weight:700;
}
.bookings-total-row { display:flex; align-items:center; gap:10px; margin:16px 0 12px; flex-wrap:wrap; }
.bookings-total-badge {
    background:#fff;
    color:#232d3b;
    border-radius:9px;
    padding:7px 13px;
    font-size:1.06em;
    font-weight:600;
    box-shadow:0 1.5px 5px #e5e9ef77;
    display:flex;
    align-items:center;
    gap:7px;
    letter-spacing:.7px;
}
.bookings-search-bar {
    background:#fff;
    border-radius:13px;
    box-shadow:0 1px 7px #e2e9f6a8;
    padding:8px 19px;
    display:flex;
    align-items:center;
    gap:10px;
    min-width:230px;
}
.bookings-search-input {
    padding:8px 10px;
    border-radius:8px;
    border:1.5px solid #d1d9ed;
    background:#f9fbff;
    font-size:1.05em;
    transition:border .13s;
    width:230px;
}
.bookings-search-input:focus { border-color:#4156c7; outline:none; }
.booking-card-list {
    display:flex;
    flex-direction:column;
    gap:18px;
    max-width:1000px;
    margin:0 auto 38px;
}
.booking-card-link { text-decoration:none; color:inherit; display:block; }
.booking-card {
    display:flex;
    align-items:center;
    background:#fff;
    border:1.5px solid #e6eef4;
    border-radius:12px;
    box-shadow:0 2px 8px #e0e7ef22;
    padding:20px 28px;
    transition:box-shadow .13s, border .13s, transform .13s;
    cursor:pointer;
}
.booking-card:hover {
    border:1.5px solid #b0c7e9;
    box-shadow:0 4px 18px #d0e3fa22;
    transform:translateY(-2px) scale(1.012);
}
.booking-card-img {
    width:90px; height:60px; object-fit:cover; border-radius:7px;
    background:#f5f7fa; margin-right:24px; border:1px solid #e4e8f3;
}
.booking-card-content { flex:2 1 160px; display:flex; flex-direction:column; gap:4px; }
.booking-card-title { font-weight:700; font-size:1.15em; letter-spacing:.5px; color:#222; }
.booking-card-plate { color:#6e7c91; font-size:1.03em; font-weight:500; letter-spacing:.5px; }
.booking-card-datetime {
    display:flex; flex-direction:column; gap:1.5px;
    font-size:1.01em; color:#334055; font-weight:500;
    margin-left:24px; min-width:185px;
}
.booking-card-payment { min-width:150px; margin-left:30px; }
.booking-payment-badge {
    background:#e6fcf3; color:#2bbf5f;
    font-size:.82em; padding:6px 14px; border-radius:16px;
    font-weight:700; letter-spacing:.3px; display:inline-block; margin-bottom:2px;
    text-transform:uppercase;
}
.booking-payment-badge.pending { background:#fff6db; color:#c29518; }
.booking-payment-badge.unpaid,
.booking-payment-badge.failed { background:#ffeded; color:#e54848; }
.booking-payment-badge.cancelled { background:#faf3f3; color:#a14d4d; }
.booking-payment-badge.completed { background:#e6f7ff; color:#1d5e92; }
.booking-payment-badge.paid { background:#dff9ee; color:#15884f; }
.booking-card-total {
    min-width:110px; text-align:right;
    font-size:1.13em; color:#1e2349; font-weight:800; letter-spacing:1px; margin-left:20px;
}
.pagination {
    display:flex; gap:8px; justify-content:center; margin:24px 0;
    flex-wrap:wrap;
}
.pagination a, .pagination span {
    padding:7px 15px; border-radius:8px; background:#fff; color:#2b5cbc;
    text-decoration:none; font-weight:700; border:1.5px solid #e4e8f3;
    font-size:1.13em; transition:background .12s, color .12s;
}
.pagination a:hover { background:#2b5cbc; color:#fff; }
.pagination .current {
    background:#2b5cbc; color:#fff; border-color:#2b5cbc; pointer-events:none;
}
@media (max-width: 700px) {
    .booking-card-list, .bookings-header { max-width:96vw; }
    .booking-card {
        flex-direction:column; align-items:flex-start; gap:10px;
        padding:16px 14px;
    }
    .booking-card-img { margin-bottom:7px; margin-right:0; }
    .booking-card-datetime, .booking-card-total, .booking-card-payment { margin-left:0; }
}
</style>
</head>
<body>

<div class="bookings-header">
    <div class="bookings-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Bookings
    </div>
    <div class="bookings-title-row">
        <div class="bookings-title">Bookings</div>
    </div>
    <div class="bookings-tabs-row">
        <button class="bookings-tab active" data-tab="all">All</button>
        <button class="bookings-tab" data-tab="pending_approval">Pending Approval</button>
        <button class="bookings-tab" data-tab="pending_payment">Pending Payment</button>
        <button class="bookings-tab" data-tab="upcoming">Upcoming</button>
        <button class="bookings-tab" data-tab="ongoing">Ongoing</button>
        <button class="bookings-tab" data-tab="cancelled">Cancelled</button>
        <button class="bookings-tab" data-tab="completed">Completed</button>
    </div>
    <div class="bookings-total-row">
        <div class="bookings-total-badge">
            Showing <b id="total-count"><?= count($bookings) ?></b><?= $search !== '' ? ' (Filtered)' : '' ?>
        </div>
        <form class="bookings-search-bar" method="get" action="bookings.php" autocomplete="off">
            <input type="text"
                   name="search"
                   id="searchInput"
                   class="bookings-search-input"
                   placeholder="Search booking, car, plate, date..."
                   value="<?= htmlspecialchars($search) ?>">
        </form>
    </div>
</div>

<div class="booking-card-list" id="bookingCardsList">
<?php if (!$bookings): ?>
    <div style="text-align:center;color:#888;font-size:1.15em;">No bookings found.</div>
<?php else: ?>
    <?php foreach ($bookings as $b): ?>
        <?php
          $pickupFmt = $b['pickup_datetime'] ? date('d M Y, g:i A', strtotime($b['pickup_datetime'])) : '-';
          $returnFmt = $b['return_datetime'] ? date('d M Y, g:i A', strtotime($b['return_datetime'])) : '-';
          $searchIndex = strtolower(
              $b['booking_id'].' '.
              $b['car_model'].' '.
              $b['car_brand'].' '.
              $b['plate_no'].' '.
              $pickupFmt.' '.
              $returnFmt.' '.
              ($b['payment_status'] ?? '').' '.
              $b['booking_status']
          );
          $bookStatus = strtolower($b['booking_status']);
          $payStatus  = strtolower($b['payment_status'] ?? '');
        ?>
        <a href="booking_details.php?id=<?= (int)$b['booking_id'] ?>"
           class="booking-card-link"
           data-status="<?= htmlspecialchars($b['tab_status']) ?>"
           data-search="<?= htmlspecialchars($searchIndex) ?>">
            <div class="booking-card">
                <img class="booking-card-img"
                     src="<?= htmlspecialchars($b['car_image_url']) ?>"
                     alt="Car image">
                <div class="booking-card-content">
                    <span class="booking-card-title">
                        <?= strtoupper(htmlspecialchars($b['car_brand'])) ?>
                        <?= htmlspecialchars($b['car_model']) ?>
                    </span>
                    <span class="booking-card-plate"><?= htmlspecialchars($b['plate_no']) ?></span>
                </div>
                <div class="booking-card-datetime">
                    <span><b>Pickup:</b> <?= $pickupFmt ?></span>
                    <span><b>Return:</b> <?= $returnFmt ?></span>
                </div>
                <div class="booking-card-payment">
                    <?php
                        if (in_array($bookStatus, ['cancelled','rejected'], true)) {
                            echo '<span class="booking-payment-badge cancelled">Cancelled</span>';
                        } elseif (in_array($bookStatus, ['pending','waiting_verification'], true)) {
                            echo '<span class="booking-payment-badge pending">Pending Approval</span>';
                        } elseif ($bookStatus === 'approved') {
                            echo '<span class="booking-payment-badge pending">Pending Payment</span>';
                        } elseif ($bookStatus === 'completed') {
                            echo '<span class="booking-payment-badge completed">Completed</span>';
                        } elseif ($bookStatus === 'confirmed') {
                            // Confirmed usually implies paid
                            echo '<span class="booking-payment-badge paid">Paid / Confirmed</span>';
                        } else {
                            // Fallback using payment_status if something else
                            if ($payStatus === 'paid') {
                                echo '<span class="booking-payment-badge paid">Paid</span>';
                            } elseif ($payStatus === 'pending') {
                                echo '<span class="booking-payment-badge pending">Payment Pending</span>';
                            } else {
                                echo '<span class="booking-payment-badge unpaid">Unpaid</span>';
                            }
                        }
                    ?>
                </div>
                <div class="booking-card-total">
                    RM <?= number_format($b['final_total'], 2) ?>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php
        $qSearch = $search !== '' ? '&search='.urlencode($search) : '';
        if ($page > 1): ?>
            <a href="?page=1<?= $qSearch ?>">&laquo; First</a>
            <a href="?page=<?= $page-1 . $qSearch ?>">&lt; Prev</a>
        <?php endif; ?>
        <?php
        $range = 2;
        for ($p = max(1,$page-$range); $p <= min($total_pages,$page+$range); $p++):
            if ($p == $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="?page=<?= $p . $qSearch ?>"><?= $p ?></a>
            <?php endif;
        endfor;
        if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 . $qSearch ?>">Next &gt;</a>
            <a href="?page=<?= $total_pages . $qSearch ?>">Last &raquo;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function updateVisibleCount() {
    const visible = [...document.querySelectorAll('.booking-card-link')]
        .filter(a => a.style.display !== 'none').length;
    document.getElementById('total-count').textContent = visible;
}
function applyFilters() {
    const activeTab = document.querySelector('.bookings-tab.active').dataset.tab;
    const term = document.getElementById('searchInput').value.toLowerCase().trim();
    document.querySelectorAll('.booking-card-link').forEach(link => {
        const s = link.dataset.status;
        const idx = link.dataset.search;
        const matchTab = activeTab === 'all' ? true : (s === activeTab);
        const matchTerm = term === '' || idx.includes(term);
        link.style.display = (matchTab && matchTerm) ? '' : 'none';
    });
    updateVisibleCount();
}
document.querySelectorAll('.bookings-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.bookings-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyFilters();
    });
});
document.getElementById('searchInput').addEventListener('input', applyFilters);
window.addEventListener('DOMContentLoaded', applyFilters);
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>