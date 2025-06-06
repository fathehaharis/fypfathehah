<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

// Validate and fetch car details
$car_id = isset($_GET['car_id']) ? intval($_GET['car_id']) : 0;
if ($car_id <= 0) {
    echo "<div class='no-cars'>Invalid car selection.</div>";
    include '../includes/footer.php';
    exit;
}

// Fetch car details
$sql = "
    SELECT c.*, 
        COALESCE(main_img.car_image_id, any_img.car_image_id) AS car_image_id
    FROM car c
    LEFT JOIN (
        SELECT car_id, MIN(car_image_id) AS car_image_id
        FROM car_image
        WHERE image_type = 'main'
        GROUP BY car_id
    ) main_img ON c.car_id = main_img.car_id
    LEFT JOIN (
        SELECT car_id, MIN(car_image_id) AS car_image_id
        FROM car_image
        GROUP BY car_id
    ) any_img ON c.car_id = any_img.car_id
    WHERE c.car_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $car_id);
$stmt->execute();
$result = $stmt->get_result();
$car = $result->fetch_assoc();

if (!$car) {
    echo "<div class='no-cars'>Car not found.</div>";
    include '../includes/footer.php';
    exit;
}
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.car-detail-container {
    max-width: 600px;
    margin: 40px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 28px 32px 24px 32px;
}
.car-detail-img {
    width: 100%;
    height: 180px;
    object-fit: contain;
    border-radius: 10px;
    background: #f7fafd;
    margin-bottom: 18px;
}
.car-detail-title {
    font-size: 1.35em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 4px;
}
.car-detail-row { margin-bottom: 14px; }
.car-detail-label { color: #555; width: 130px; display: inline-block; font-weight: 600;}
.car-detail-value { color: #222; }
.form-section {
    margin-top: 30px;
}
.next-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
}
.next-btn:hover {
    background: #234c96;
}
</style>

<div class="car-detail-container">
    <h2 class="car-detail-title"><?= htmlspecialchars($car['car_brand'] . ' ' . $car['car_model']) ?></h2>
    <img class="car-detail-img"
         src="<?= !empty($car['car_image_id']) ? "get_car_image.php?car_image_id=" . $car['car_image_id'] : '/assets/images/viva_elite.png' ?>"
         alt="Car image"
         onerror="this.src='/assets/images/viva_elite.png'">
    <div class="car-detail-row"><span class="car-detail-label">Plate No:</span> <span class="car-detail-value"><?= htmlspecialchars($car['plate_no']) ?></span></div>
    <div class="car-detail-row"><span class="car-detail-label">Year:</span> <span class="car-detail-value"><?= htmlspecialchars($car['year']) ?></span></div>
    <div class="car-detail-row"><span class="car-detail-label">Color:</span> <span class="car-detail-value"><?= htmlspecialchars($car['color']) ?></span></div>
    <div class="car-detail-row"><span class="car-detail-label">Transmission:</span> <span class="car-detail-value"><?= htmlspecialchars($car['transmission']) ?></span></div>
    <div class="car-detail-row"><span class="car-detail-label">Seat Capacity:</span> <span class="car-detail-value"><?= htmlspecialchars($car['seat_capacity']) ?></span></div>
    <div class="car-detail-row"><span class="car-detail-label">Mileage:</span> <span class="car-detail-value"><?= htmlspecialchars($car['mileage']) ?> km</span></div>
    <div class="car-detail-row"><span class="car-detail-label">Daily Rate:</span> <span class="car-detail-value">RM <?= number_format($car['daily_rate'], 2) ?></span></div>

    <form class="form-section" action="booking_driver.php" method="POST">
        <input type="hidden" name="car_id" value="<?= $car['car_id'] ?>">

        <!-- Pickup Date & Time -->
        <div class="car-detail-row">
            <span class="car-detail-label">Pickup Date:</span>
            <input type="date" name="pickup_date" required>
            <span class="car-detail-label" style="width:90px;">Time:</span>
            <select name="pickup_time" required>
                <?php
                // Generate time options in 30-minute intervals (00:00 - 23:30)
                for ($h = 0; $h < 24; $h++) {
                    for ($m = 0; $m < 60; $m += 30) {
                        $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
                        $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
                        echo "<option value=\"$hour:$minute\">$hour:$minute</option>";
                    }
                }
                ?>
            </select>
        </div>

        <!-- Return Date & Time -->
        <div class="car-detail-row">
            <span class="car-detail-label">Return Date:</span>
            <input type="date" name="return_date" required>
            <span class="car-detail-label" style="width:90px;">Time:</span>
            <select name="return_time" required>
                <?php
                for ($h = 0; $h < 24; $h++) {
                    for ($m = 0; $m < 60; $m += 30) {
                        $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
                        $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
                        echo "<option value=\"$hour:$minute\">$hour:$minute</option>";
                    }
                }
                ?>
            </select>
        </div>

        <!-- Service Type -->
        <div class="car-detail-row">
            <span class="car-detail-label">Service:</span>
            <select name="delivery_type" required>
                <option value="self_pickup">Pick up myself (FREE)</option>
                <option value="delivery">Deliver car to me (+RM10)</option>
                <option value="pickup_and_return">Deliver &amp; return pickup (+RM30)</option>
            </select>
        </div>
        <div style="margin-top: 28px; text-align: right;">
            <button type="submit" class="next-btn">Next</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>