<?php
/*
 * car_details.php (Read-Only)
 * Features:
 *  - Shows basic car info
 *  - Primary image (first by sort_order,id)
 *  - Document download links (grant / roadtax / covernote)
 *  - Links to edit page & back to list
 */

require_once '../connect.php';
session_start();

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

if (empty($_GET['id']) || !ctype_digit($_GET['id'])) {
    echo "<div style='padding:40px;text-align:center;color:#b33;'>Invalid car ID.</div>";
    exit;
}
$car_id = (int)$_GET['id'];

function statusBadge(string $s): array {
    return (strtolower($s)==='available') ? ['Available',''] : ['Not Available',' not-available'];
}

/* Fetch car (blob presence via IS NOT NULL + length check) */
$sqlCar = "
    SELECT car_id, car_brand, car_model, year, color, mileage, plate_no,
           transmission, seat_capacity, status, daily_rate, images_version,
           (car_grant_blob     IS NOT NULL AND OCTET_LENGTH(car_grant_blob)     > 0) AS has_grant,
           (car_roadtax_blob   IS NOT NULL AND OCTET_LENGTH(car_roadtax_blob)   > 0) AS has_roadtax,
           (car_covernote_blob IS NOT NULL AND OCTET_LENGTH(car_covernote_blob) > 0) AS has_covernote
    FROM car
    WHERE car_id=? LIMIT 1
";
$stmt = $conn->prepare($sqlCar);
if (!$stmt) {
    error_log("CAR_DETAILS prepare fail: ".$conn->error);
    echo "<div style='padding:40px;text-align:center;color:#b33;'>System error.</div>";
    exit;
}
$stmt->bind_param('i', $car_id);
$stmt->execute();
$res = $stmt->get_result();
$car = $res?->fetch_assoc();
$stmt->close();

if (!$car) {
    echo "<div style='padding:40px;text-align:center;color:#b33;'>Car not found.</div>";
    exit;
}

/* Fetch primary image only (id + version) */
$stmt = $conn->prepare("
    SELECT car_image_id, version
    FROM car_image
    WHERE car_id=?
    ORDER BY sort_order ASC, car_image_id ASC
    LIMIT 1
");
$stmt->bind_param('i', $car_id);
$stmt->execute();
$primaryImage = $stmt->get_result()->fetch_assoc();
$stmt->close();

[$statusLabel, $statusClass] = statusBadge($car['status']);

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Car Details</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root {
    --brand-blue:#304cc3;
    --border:#e4e8f3;
    --bg-light:#f7fafc;
    --text-dark:#1f3455;
    --text-mid:#5874a8;
}
body { font-family: system-ui, Arial, sans-serif; background:#f1f5fa; margin:0; }
.car-details-container { max-width: 980px; margin: 40px auto 50px; background:#fff; border-radius:16px; box-shadow:0 4px 16px -4px rgba(0,0,0,.08); padding:40px 42px 48px; }
.header-row { display:flex; flex-wrap:wrap; justify-content:space-between; gap:20px; }
.title-block h1 { margin:0; font-size:2.15em; font-weight:800; color:var(--text-dark); letter-spacing:.5px; }
.title-block .plate { font-size:1.02em; color:var(--text-mid); font-weight:600; margin-top:6px; }
.status-badge { display:inline-block; margin-top:14px; padding:6px 22px; font-size:0.9em; font-weight:700; letter-spacing:.25px; border-radius:18px; background:#e6fcf3; color:#2bbf5f; }
.status-badge.not-available { background:#ffeded; color:#e54848; }
.actions { display:flex; gap:12px; align-items:flex-start; }
.actions a { text-decoration:none; background:var(--brand-blue); color:#fff; padding:10px 20px; border-radius:9px; font-weight:600; font-size:0.9em; box-shadow:0 2px 6px rgba(48,76,195,0.15); transition:background .15s; }
.actions a:hover { background:#20398e; }
.details-table { width:100%; border-collapse:collapse; margin:34px 0 10px; }
.details-table td { padding:12px 16px; border-bottom:1px solid #eef2f7; vertical-align:top; font-size:0.95em; }
.details-table tr:last-child td { border-bottom:none; }
.details-table b { color:#20324f; font-weight:600; }
.section-title { margin:40px 0 16px; font-size:1.15em; font-weight:700; letter-spacing:.3px; color:var(--brand-blue); }
.primary-image-box { width:300px; height:200px; border:1.5px solid var(--border); border-radius:14px; background:var(--bg-light); box-shadow:0 2px 10px -3px rgba(0,0,0,.08); display:flex; align-items:center; justify-content:center; overflow:hidden; }
.primary-image-box img { width:100%; height:100%; object-fit:cover; cursor:pointer; display:block; }
.empty-note { color:#888; font-size:0.9em; }
.doc-grid { display:flex; flex-wrap:wrap; gap:30px; font-size:0.9em; }
.doc-item b { display:block; margin-bottom:6px; color:#20324f; font-weight:600; font-size:0.75rem; letter-spacing:.5px; text-transform:uppercase; }
.doc-item a { color:#1a53b8; text-decoration:none; font-weight:600; font-size:0.9em; }
.doc-item a:hover { text-decoration:underline; }
.back-links { margin-top:46px; font-size:0.9em; }
.back-links a { color:var(--brand-blue); text-decoration:none; font-weight:600; margin-right:18px; }
.back-links a:hover { text-decoration:underline; }
@media (max-width:780px) {
    .car-details-container { padding:32px 26px 40px; }
    .primary-image-box { width:100%; height:240px; }
    .details-table td { padding:10px 12px; }
}
</style>
</head>
<body>
<div class="car-details-container">

    <div class="header-row">
        <div class="title-block">
            <h1><?= strtoupper(htmlspecialchars($car['car_brand'])) ?> <?= htmlspecialchars($car['car_model']) ?></h1>
            <div class="plate"><?= htmlspecialchars($car['plate_no']) ?></div>
            <span class="status-badge<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
        </div>
        <div class="actions">
            <a href="edit_car.php?id=<?= $car_id ?>">Edit Car</a>
            <a href="cars.php">Back to List</a>
        </div>
    </div>

    <table class="details-table">
        <tr>
            <td><b>Brand</b></td><td><?= htmlspecialchars($car['car_brand']) ?></td>
            <td><b>Model</b></td><td><?= htmlspecialchars($car['car_model']) ?></td>
        </tr>
        <tr>
            <td><b>Year</b></td><td><?= (int)$car['year'] ?></td>
            <td><b>Color</b></td><td><?= htmlspecialchars($car['color']) ?></td>
        </tr>
        <tr>
            <td><b>Transmission</b></td><td><?= htmlspecialchars(ucfirst($car['transmission'])) ?></td>
            <td><b>Seat Capacity</b></td><td><?= (int)$car['seat_capacity'] ?></td>
        </tr>
        <tr>
            <td><b>Mileage (km)</b></td><td><?= (int)$car['mileage'] ?></td>
            <td><b>Daily Rate (RM)</b></td><td><?= number_format((float)$car['daily_rate'], 2) ?></td>
        </tr>
    </table>

    <div class="section-title">Primary Image</div>
    <?php if ($primaryImage): ?>
        <div class="primary-image-box">
            <img src="car_image.php?id=<?= (int)$primaryImage['car_image_id'] ?>&v=<?= (int)$primaryImage['version'] ?>"
                 alt="Car image" onclick="window.open(this.src,'_blank')">
        </div>
    <?php else: ?>
        <div class="empty-note">No image uploaded.</div>
    <?php endif; ?>

    <div class="section-title">Documents</div>
    <div class="doc-grid">
        <div class="doc-item">
            <b>Grant</b>
            <?php if ($car['has_grant']): ?>
                <a href="download_doc.php?car_id=<?= $car_id ?>&field=car_grant_blob&name=Grant&v=<?= strtotime($car['updated_at']) ?>" target="_blank">Download</a>
            <?php else: ?>
                <span class="empty-note">None</span>
            <?php endif; ?>
        </div>
        <div class="doc-item">
            <b>Roadtax</b>
            <?php if ($car['has_roadtax']): ?>
                <a href="download_doc.php?car_id=<?= $car_id ?>&field=car_roadtax_blob&name=Roadtax&v=<?= strtotime($car['updated_at']) ?>" target="_blank">Download</a>
            <?php else: ?>
                <span class="empty-note">None</span>
            <?php endif; ?>
        </div>
        <div class="doc-item">
            <b>Covernote</b>
            <?php if ($car['has_covernote']): ?>
                <a href="download_doc.php?car_id=<?= $car_id ?>&field=car_covernote_blob&name=Covernote&v=<?= strtotime($car['updated_at']) ?>" target="_blank">Download</a>
            <?php else: ?>
                <span class="empty-note">None</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="back-links">
        <a href="edit_car.php?id=<?= $car_id ?>">Edit Car</a>
        <a href="cars.php">Back to List</a>
    </div>

</div>
<?php include '../includes/footer.php'; ?>
</body>
</html>