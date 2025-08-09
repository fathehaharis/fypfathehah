<?php
// Admin Cars Listing (Improved)
// - Safe filtering (prepared statements)
// - Avoids selecting large blobs
// - Pagination
// - Primary image served via car_thumb.php
// - Supports search across brand + model

require_once '../connect.php';
session_start();

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$plate_no = isset($_GET['plate_no']) ? trim($_GET['plate_no']) : '';
$vehicle  = isset($_GET['vehicle']) ? trim($_GET['vehicle']) : '';
$page     = isset($_GET['page']) && ctype_digit($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

$conditions = [];
$params = [];
$types  = '';

if ($plate_no !== '') {
    $conditions[] = "c.plate_no LIKE ?";
    $params[] = "%$plate_no%";
    $types .= 's';
}
if ($vehicle !== '') {
    // Search both brand and model
    $conditions[] = "(c.car_brand LIKE ? OR c.car_model LIKE ?)";
    $params[] = "%$vehicle%";
    $params[] = "%$vehicle%";
    $types .= 'ss';
}

$whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

// Count total (without blobs)
$countSql = "SELECT COUNT(*) AS total FROM car c $whereSql";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$countRes = $stmt->get_result()->fetch_assoc();
$stmt->close();
$totalRows = (int)$countRes['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Main select (choose needed columns only; compute primary image id)
$sql = "SELECT 
            c.car_id,
            c.car_brand,
            c.car_model,
            c.year,
            c.color,
            c.mileage,
            c.plate_no,
            c.transmission,
            c.seat_capacity,
            c.status,
            c.daily_rate,
            c.images_version,
            (
              SELECT ci.car_image_id
              FROM car_image ci
              WHERE ci.car_id = c.car_id
              ORDER BY ci.sort_order ASC, ci.car_image_id ASC
              LIMIT 1
            ) AS primary_image_id
        FROM car c
        $whereSql
        ORDER BY c.car_id DESC
        LIMIT ? OFFSET ?";

$paramsWithLimit = $params;
$typesWithLimit = $types . 'ii';
$paramsWithLimit[] = $perPage;
$paramsWithLimit[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
$stmt->execute();
$res = $stmt->get_result();
$cars = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Helper for status display
function displayStatusBadge(string $status): array {
    // Map any future statuses to visual style
    $s = strtolower($status);
    if ($s === 'available') {
        return ['Available', ''];
    }
    return ['Not Available', ' not-available'];
}

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fleet - Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<style>
/* (Keeping your existing CSS with minimal changes) */
<?php /* You can keep this in a separate file if preferred */ ?>
<?php /* START Original Inline CSS (trimmed only if redundant) */ ?>
/* --- FILTER MODAL --- */
.car-filter-modal-bg { display:none; position:fixed; inset:0; background:rgba(50,60,80,0.18); z-index:10001; }
.car-filter-modal-bg.active { display:block; }
.car-filter-modal { position:fixed; top:50%; left:50%; width:350px; max-width:95vw; background:#fff; border-radius:11px; box-shadow:0 6px 36px #2229a022; padding:30px 24px 20px; transform:translate(-50%,-50%); z-index:11000; display:flex; flex-direction:column; gap:14px; animation:modalFadeIn .14s; }
@keyframes modalFadeIn { from{opacity:0;} to{opacity:1;} }
.car-filter-modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:7px; }
.car-filter-modal-title { font-size:1.18em; font-weight:700; color:#232a38; }
.car-filter-modal-close { background:none; border:none; font-size:1.5em; color:#444; cursor:pointer; padding:0 4px; font-weight:400; line-height:1; }
.car-filter-modal form label { font-size:1em; font-weight:600; color:#232d3b; margin-bottom:3px; }
.car-filter-modal form input { width:100%; padding:8px 11px; border:1.5px solid #c9d4ec; border-radius:7px; font-size:1em; margin-bottom:12px; background:#f8fafd; color:#222; outline:none; }
.car-filter-modal form .filter-btn-row { display:flex; gap:10px; margin-top:6px; }
.car-filter-modal form button { padding:8px 18px; border-radius:7px; font-size:1.03em; font-weight:600; border:none; cursor:pointer; }
.car-filter-modal form .filter-btn-row .submit-btn { background:#304cc3; color:#fff; }
.car-filter-modal form .filter-btn-row .reset-btn { background:#eceffe; color:#25336d; }
/* Header / cards */
.fleet-header { margin:30px auto 10px; max-width:1000px; display:flex; flex-direction:column; gap:16px; }
.fleet-breadcrumb { font-size:0.98em; color:#92a2b3; margin-bottom:8px; }
.fleet-breadcrumb a { color:#6d87be; text-decoration:none; font-weight:600; }
.fleet-page-title-row { display:flex; align-items:center; justify-content:space-between; }
.fleet-page-title { font-size:2em; font-weight:800; color:#232d3b; letter-spacing:0.5px; }
.fleet-add-btn { padding:10px 22px; background:#3d50b1; color:#fff; border:none; border-radius:9px; font-size:1.09em; font-weight:600; cursor:pointer; transition:.12s background; box-shadow:0 2px 6px #e6eef9; }
.fleet-add-btn:hover { background:#283e7e; }
.fleet-tabs-row { display:flex; gap:17px; margin-top:8px; border-bottom:1.5px solid #e7eaf4; }
.fleet-tabs-row .fleet-tab { padding-bottom:7px; font-size:1.10em; font-weight:500; color:#202a35; background:none; border:none; cursor:pointer; outline:none; transition:color .12s; border-bottom:2.5px solid transparent; }
.fleet-tabs-row .fleet-tab.active { color:#233ca0; border-bottom:2.5px solid #293e9b; font-weight:700; }
.fleet-total-row { display:flex; align-items:center; gap:10px; margin:16px 0 12px; }
.fleet-total-badge { background:#fff; color:#222; border-radius:9px; padding:7px 13px; font-size:1.06em; font-weight:600; box-shadow:0 1.5px 5px #e5e9ef77; display:flex; align-items:center; gap:7px; }
.fleet-filter-btn { background:#f2f5fa; color:#3d50b1; border:none; border-radius:8px; padding:7px 17px; font-size:1em; font-weight:600; display:flex; align-items:center; gap:6px; cursor:pointer; margin-left:6px; transition:background .12s; }
.fleet-filter-btn:hover { background:#e5e9f3; }
.car-card-list { display:flex; flex-direction:column; gap:18px; max-width:1000px; margin:0 auto 38px; }
.car-card { display:flex; align-items:center; background:#fff; border:1.5px solid #e6eef4; border-radius:12px; box-shadow:0 2px 8px #e0e7ef22; padding:20px 28px; transition:box-shadow .13s,border .13s,transform .13s; cursor:pointer; }
.car-card:hover { border:1.5px solid #b0c7e9; box-shadow:0 4px 18px #d0e3fa22; transform:translateY(-2px) scale(1.012); }
.car-card-img { width:90px; height:60px; object-fit:cover; border-radius:7px; background:#f5f7fa; margin-right:24px; border:1px solid #e4e8f3; }
.car-card-content { flex:1 1 auto; display:flex; flex-direction:column; gap:5px; }
.car-card-title { font-weight:700; font-size:1.16em; letter-spacing:.5px; color:#222; }
.car-card-plate { color:#6e7c91; font-size:1.03em; font-weight:500; letter-spacing:.5px; }
.car-card-status { background:#e6fcf3; color:#2bbf5f; font-size:.95em; padding:4px 18px; border-radius:15px; font-weight:700; letter-spacing:.3px; margin-left:auto; }
.car-card-status.not-available { background:#ffeded; color:#e54848; }
@media (max-width:700px){
  .car-card-list, .fleet-header { max-width:98vw; }
  .car-card { flex-direction:column; align-items:flex-start; gap:10px; padding:16px 12px; }
  .car-card-img { margin-bottom:7px; margin-right:0; }
}
.pagination { display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:flex-start; margin:10px auto 40px; max-width:1000px; }
.pagination a, .pagination span { padding:6px 12px; border-radius:6px; background:#f2f5fa; color:#2f3b52; font-size:0.92em; font-weight:600; text-decoration:none; }
.pagination a:hover { background:#e2e7f0; }
.pagination .active { background:#304cc3; color:#fff; }
.pagination .disabled { opacity:.45; cursor:not-allowed; }
<?php /* END CSS */ ?>
</style>
</head>
<body>

<div class="fleet-header">
    <div class="fleet-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Fleet
    </div>
    <div class="fleet-page-title-row">
        <div class="fleet-page-title">Fleet</div>
        <a href="add_car.php"><button class="fleet-add-btn" type="button">+ Add</button></a>
    </div>
    <div class="fleet-tabs-row">
        <button class="fleet-tab active" disabled>All</button>
    </div>
    <div class="fleet-total-row">
        <div class="fleet-total-badge">
            Total <b><?= $totalRows ?></b>
        </div>
        <button class="fleet-filter-btn" type="button" onclick="openCarFilterModal()">
            <i class="fa fa-filter"></i> Filter
        </button>
    </div>
</div>

<!-- Filter Modal -->
<div id="carFilterModalBg" class="car-filter-modal-bg" onclick="closeCarFilterModal(event)">
    <div class="car-filter-modal" onclick="event.stopPropagation();">
        <div class="car-filter-modal-header">
            <span class="car-filter-modal-title">Filter</span>
            <button class="car-filter-modal-close" type="button" onclick="closeCarFilterModal(event)">&times;</button>
        </div>
        <form method="get" action="cars.php" autocomplete="off">
            <label for="plate_no">Plate Number</label>
            <input type="text" id="plate_no" name="plate_no" placeholder="Plate Number" value="<?= htmlspecialchars($plate_no) ?>">

            <label for="vehicle">Vehicle (Brand / Model)</label>
            <input type="text" id="vehicle" name="vehicle" placeholder="e.g. Toyota / Vios" value="<?= htmlspecialchars($vehicle) ?>">

            <div class="filter-btn-row">
                <button type="submit" class="submit-btn">Filter</button>
                <a href="cars.php" style="text-decoration:none;">
                    <button type="button" class="reset-btn">Reset</button>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Car Cards List -->
<div class="car-card-list">
<?php if (!$cars): ?>
    <div style="text-align:center;color:#888;font-size:1.05em;">No cars found.</div>
<?php else: ?>
    <?php foreach ($cars as $car): ?>
        <?php
            [$statusLabel, $statusClass] = displayStatusBadge($car['status']);
            $primaryImageId = $car['primary_image_id'];
            $imgSrc = $primaryImageId
                ? "car_thumb.php?id=".(int)$primaryImageId."&v=".$car['images_version']
                : "placeholder.svg";
        ?>
        <div class="car-card" onclick="window.location='car_details.php?id=<?= (int)$car['car_id'] ?>'">
            <img class="car-card-img" src="<?= htmlspecialchars($imgSrc) ?>" alt="Car image">
            <div class="car-card-content">
                <span class="car-card-title">
                    <?= strtoupper(htmlspecialchars($car['car_brand'])) ?> <?= htmlspecialchars($car['car_model']) ?>
                </span>
                <span class="car-card-plate"><?= htmlspecialchars($car['plate_no']) ?></span>
            </div>
            <span class="car-card-status<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php
    // Build base query string without page
    $qs = $_GET;
    unset($qs['page']);
    $baseQuery = http_build_query($qs);
    $baseUrl = 'cars.php' . ($baseQuery ? ('?' . $baseQuery . '&') : '?');

    $prevDisabled = $page <= 1;
    $nextDisabled = $page >= $totalPages;

    ?>
    <span class="<?= $prevDisabled ? 'disabled' : '' ?>">
        <?php if ($prevDisabled): ?>
            &laquo; Prev
        <?php else: ?>
            <a href="<?= $baseUrl . 'page=' . ($page - 1) ?>">&laquo; Prev</a>
        <?php endif; ?>
    </span>

    <?php
    // Show limited window
    $window = 5;
    $start = max(1, $page - 2);
    $end = min($totalPages, $start + $window - 1);
    if ($end - $start < $window - 1) {
        $start = max(1, $end - $window + 1);
    }
    if ($start > 1) {
        echo '<a href="'.$baseUrl.'page=1">1</a>';
        if ($start > 2) echo '<span>...</span>';
    }
    for ($p = $start; $p <= $end; $p++) {
        if ($p == $page) {
            echo '<span class="active">'.$p.'</span>';
        } else {
            echo '<a href="'.$baseUrl.'page='.$p.'">'.$p.'</a>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span>...</span>';
        echo '<a href="'.$baseUrl.'page='.$totalPages.'">'.$totalPages.'</a>';
    }
    ?>

    <span class="<?= $nextDisabled ? 'disabled' : '' ?>">
        <?php if ($nextDisabled): ?>
            Next &raquo;
        <?php else: ?>
            <a href="<?= $baseUrl . 'page=' . ($page + 1) ?>">Next &raquo;</a>
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<script>
function openCarFilterModal() {
    document.getElementById('carFilterModalBg').classList.add('active');
}
function closeCarFilterModal(e) {
    document.getElementById('carFilterModalBg').classList.remove('active');
}
</script>
<?php include '../includes/footer.php'; ?>
</body>
</html>