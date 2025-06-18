<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Read filter parameters
$plate_no = isset($_GET['plate_no']) ? trim($_GET['plate_no']) : '';
$vehicle = isset($_GET['vehicle']) ? trim($_GET['vehicle']) : '';

// Build filter query
$where = "WHERE 1=1";
if ($plate_no !== '') $where .= " AND c.plate_no LIKE '%" . $conn->real_escape_string($plate_no) . "%'";
if ($vehicle !== '') $where .= " AND c.car_model LIKE '%" . $conn->real_escape_string($vehicle) . "%'";

// Fetch all cars (add pagination if needed)
$sql = "SELECT * FROM car c $where ORDER BY c.car_id DESC";
$result = $conn->query($sql);
$cars = [];
while ($row = $result->fetch_assoc()) {
    $cars[] = $row;
}

function getCarImage($conn, $car_id) {
    $imgSql = "SELECT image_path FROM car_image WHERE car_id = $car_id LIMIT 1";
    $imgRes = $conn->query($imgSql);
    if ($imgRes && $imgRow = $imgRes->fetch_assoc()) {
        return 'data:image/jpeg;base64,' . base64_encode($imgRow['image_path']);
    } else {
        return 'https://via.placeholder.com/90x60?text=No+Image';
    }
}
?>
<?php include 'admin_header.php'; ?>

<style>
/* --- FILTER MODAL --- */
.car-filter-modal-bg {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(50,60,80,0.18);
    z-index: 10001;
}
.car-filter-modal-bg.active {
    display: block;
}
.car-filter-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    width: 350px;
    max-width: 95vw;
    background: #fff;
    border-radius: 11px;
    box-shadow: 0 6px 36px #2229a022;
    padding: 30px 24px 20px 24px;
    transform: translate(-50%,-50%);
    z-index: 11000;
    display: flex;
    flex-direction: column;
    gap: 14px;
    animation: modalFadeIn 0.14s;
}
@keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
.car-filter-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 7px;
}
.car-filter-modal-title {
    font-size: 1.18em;
    font-weight: 700;
    color: #232a38;
}
.car-filter-modal-close {
    background: none;
    border: none;
    font-size: 1.5em;
    color: #444;
    cursor: pointer;
    padding: 0 4px;
    font-weight: 400;
    line-height: 1;
}
.car-filter-modal form label {
    font-size: 1em;
    font-weight: 600;
    color: #232d3b;
    margin-bottom: 3px;
}
.car-filter-modal form input {
    width: 100%;
    padding: 8px 11px;
    border: 1.5px solid #c9d4ec;
    border-radius: 7px;
    font-size: 1em;
    margin-bottom: 12px;
    background: #f8fafd;
    color: #222;
    outline: none;
}
.car-filter-modal form .filter-btn-row {
    display: flex; gap: 10px; margin-top: 6px;
}
.car-filter-modal form button {
    padding: 8px 18px;
    border-radius: 7px;
    font-size: 1.03em;
    font-weight: 600;
    border: none;
    cursor: pointer;
}
.car-filter-modal form .filter-btn-row .submit-btn {
    background: #304cc3;
    color: #fff;
}
.car-filter-modal form .filter-btn-row .reset-btn {
    background: #eceffe;
    color: #25336d;
}

/* --- PAGE HEADER & CARD STYLES --- */
.fleet-header {
    margin: 30px auto 10px auto;
    max-width: 1000px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.fleet-breadcrumb {
    font-size: 0.98em;
    color: #92a2b3;
    margin-bottom: 8px;
}
.fleet-breadcrumb a {
    color: #6d87be;
    text-decoration: none;
    font-weight: 600;
}
.fleet-page-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.fleet-page-title {
    font-size: 2em;
    font-weight: 800;
    color: #232d3b;
    letter-spacing: 0.5px;
}
.fleet-add-btn {
    padding: 10px 22px;
    background: #3d50b1;
    color: #fff;
    border: none;
    border-radius: 9px;
    font-size: 1.09em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.12s;
    box-shadow: 0 2px 6px #e6eef9;
}
.fleet-add-btn:hover {
    background: #283e7e;
}
.fleet-tabs-row {
    display: flex;
    gap: 17px;
    margin-top: 8px;
    border-bottom: 1.5px solid #e7eaf4;
}
.fleet-tabs-row .fleet-tab {
    padding-bottom: 7px;
    font-size: 1.10em;
    font-weight: 500;
    color: #202a35;
    background: none;
    border: none;
    cursor: pointer;
    outline: none;
    transition: color 0.12s;
    border-bottom: 2.5px solid transparent;
}
.fleet-tabs-row .fleet-tab.active {
    color: #233ca0;
    border-bottom: 2.5px solid #293e9b;
    font-weight: 700;
}
.fleet-total-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 16px 0 12px 0;
}
.fleet-total-badge {
    background: #fff;
    color: #222;
    border-radius: 9px;
    padding: 7px 13px;
    font-size: 1.06em;
    font-weight: 600;
    box-shadow: 0 1.5px 5px #e5e9ef77;
    display: flex;
    align-items: center;
    gap: 7px;
}
.fleet-filter-btn {
    background: #f2f5fa;
    color: #3d50b1;
    border: none;
    border-radius: 8px;
    padding: 7px 17px;
    font-size: 1em;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    margin-left: 6px;
    transition: background 0.12s;
}
.fleet-filter-btn:hover {
    background: #e5e9f3;
}
.fleet-filter-btn i {
    font-size: 1.18em;
}
.car-card-list {
    display: flex;
    flex-direction: column;
    gap: 18px;
    max-width: 1000px;
    margin: 0 auto 38px auto;
}
.car-card {
    display: flex;
    align-items: center;
    background: #fff;
    border: 1.5px solid #e6eef4;
    border-radius: 12px;
    box-shadow: 0 2px 8px #e0e7ef22;
    padding: 20px 28px;
    transition: box-shadow 0.13s, border 0.13s, transform 0.13s;
    cursor: pointer;
}
.car-card:hover {
    border: 1.5px solid #b0c7e9;
    box-shadow: 0 4px 18px #d0e3fa22;
    transform: translateY(-2px) scale(1.012);
}
.car-card-img {
    width: 90px;
    height: 60px;
    object-fit: cover;
    border-radius: 7px;
    background: #f5f7fa;
    margin-right: 24px;
    border: 1px solid #e4e8f3;
}
.car-card-content {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.car-card-title {
    font-weight: 700;
    font-size: 1.16em;
    letter-spacing: 0.5px;
    color: #222;
}
.car-card-plate {
    color: #6e7c91;
    font-size: 1.03em;
    font-weight: 500;
    letter-spacing: 0.5px;
}
.car-card-status {
    background: #e6fcf3;
    color: #2bbf5f;
    font-size: 0.95em;
    padding: 4px 18px;
    border-radius: 15px;
    font-weight: 700;
    letter-spacing: 0.3px;
    margin-left: auto;
}
.car-card-status.rented {
    background: #ffeded;
    color: #e54848;
}
.car-card-status.maintenance {
    background: #fff7e6;
    color: #e7a84b;
}
@media (max-width: 700px) {
    .car-card-list, .fleet-header { max-width: 98vw; }
    .car-card { flex-direction: column; align-items: flex-start; gap: 10px; padding: 16px 12px; }
    .car-card-img { margin-bottom: 7px; margin-right: 0;}
}
</style>

<div class="fleet-header">
    <div class="fleet-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Fleet
    </div>
    <div class="fleet-page-title-row">
        <div class="fleet-page-title">Fleet</div>
        <a href="add_car.php"><button class="fleet-add-btn">+ Add</button></a>
    </div>
    <div class="fleet-tabs-row">
        <button class="fleet-tab active" disabled>All</button>
    </div>
    <div class="fleet-total-row">
        <div class="fleet-total-badge">
            Total <b><?= count($cars) ?></b>
        </div>
        <button class="fleet-filter-btn" onclick="openCarFilterModal()">
            <i class="fa fa-filter"></i> Filter
        </button>
    </div>
</div>

<!-- Filter Modal -->
<div id="carFilterModalBg" class="car-filter-modal-bg" onclick="closeCarFilterModal(event)">
    <div class="car-filter-modal" onclick="event.stopPropagation();">
        <div class="car-filter-modal-header">
            <span class="car-filter-modal-title">Filter</span>
            <button class="car-filter-modal-close" onclick="closeCarFilterModal(event)">&times;</button>
        </div>
        <form method="get" action="cars.php" autocomplete="off">
            <label for="plate_no">Plate Number</label>
            <input type="text" id="plate_no" name="plate_no" placeholder="Plate Number" value="<?= htmlspecialchars($plate_no) ?>">

            <label for="vehicle">Vehicle</label>
            <input type="text" id="vehicle" name="vehicle" placeholder="Vehicle" value="<?= htmlspecialchars($vehicle) ?>">

            <div class="filter-btn-row">
                <button type="submit" class="submit-btn">Filter</button>
                <a href="cars.php"><button type="button" class="reset-btn">Reset</button></a>
            </div>
        </form>
    </div>
</div>

<script>
function openCarFilterModal() {
    document.getElementById('carFilterModalBg').classList.add('active');
}
function closeCarFilterModal(e) {
    document.getElementById('carFilterModalBg').classList.remove('active');
}
</script>

<!-- Car Cards List -->
<div class="car-card-list">
<?php if (empty($cars)): ?>
    <div style="text-align:center;color:#888;font-size:1.15em;">No cars found.</div>
<?php else: ?>
    <?php foreach ($cars as $car): ?>
        <div class="car-card" onclick="window.location='car_details.php?id=<?= $car['car_id'] ?>'">
            <img class="car-card-img" src="<?= htmlspecialchars(getCarImage($conn, $car['car_id'])) ?>" alt="Car image">
            <div class="car-card-content">
                <span class="car-card-title"><?= strtoupper(htmlspecialchars($car['car_brand'])) ?> <?= htmlspecialchars($car['car_model']) ?></span>
                <span class="car-card-plate"><?= htmlspecialchars($car['plate_no']) ?></span>
            </div>
            <?php if ($car['status'] == 'available'): ?>
                <span class="car-card-status">Available</span>
            <?php elseif ($car['status'] == 'rented'): ?>
                <span class="car-card-status rented">Rented</span>
            <?php elseif ($car['status'] == 'maintenance'): ?>
                <span class="car-card-status maintenance">Maintenance</span>
            <?php else: ?>
                <span class="car-card-status"><?= htmlspecialchars($car['status']) ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<?php include '../includes/footer.php'; ?>