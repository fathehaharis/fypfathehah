<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

// Validate & fetch car details
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

// Fetch existing bookings for this car (not cancelled), where booking dates are in the future or ongoing
$today = date("Y-m-d");
$booking_sql = "
    SELECT 
        pickup_datetime, return_datetime
    FROM booking
    WHERE car_id = ?
      AND status != 'cancelled'
      AND DATE(return_datetime) >= ?
";
$booking_stmt = $conn->prepare($booking_sql);
$booking_stmt->bind_param("is", $car_id, $today);
$booking_stmt->execute();
$booking_result = $booking_stmt->get_result();
$unavailable_ranges = [];
while ($row = $booking_result->fetch_assoc()) {
    $unavailable_ranges[] = [
        'start' => date('Y-m-d', strtotime($row['pickup_datetime'])),
        'end'   => date('Y-m-d', strtotime($row['return_datetime']))
    ];
}
$booking_stmt->close();

// Pre-fill form data from session if available
$booking_data = $_SESSION['booking_data'] ?? [];
$pickup_date  = isset($booking_data['pickup_datetime']) ? substr($booking_data['pickup_datetime'], 0, 10) : '';
$pickup_time  = isset($booking_data['pickup_datetime']) ? substr($booking_data['pickup_datetime'], 11, 5) : '';
$return_date  = isset($booking_data['return_datetime']) ? substr($booking_data['return_datetime'], 0, 10) : '';
$return_time  = isset($booking_data['return_datetime']) ? substr($booking_data['return_datetime'], 11, 5) : '';
$delivery_type = $booking_data['delivery_type'] ?? '';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background: #f7f8fa;}
.car-detail-outer {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: calc(100vh - 110px);
    width: 100%;
}
.car-detail-card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.09);
    width: 520px;
    margin: 48px 0;
    padding: 0;
}
.car-detail-title {
    font-size: 1.3em;
    font-weight: 700;
    color: #2f377d;
    margin: 0;
    padding: 32px 0 8px 0;
    text-align: left;
    padding-left: 36px;
}
.car-img-bg {
    background: #f2f5fa;
    border-radius: 14px;
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 0 32px 18px 32px;
    padding: 20px 0 10px 0;
}
.car-detail-img {
    width: 290px;
    max-width: 100%;
    height: 120px;
    object-fit: contain;
    border-radius: 10px;
    background: none;
    display: block;
}
.car-detail-table {
    width: 92%;
    font-size: 1.06em;
    margin: 0 auto 20px auto;
    border-collapse: separate;
    border-spacing: 0 6px;
}
.car-detail-table th, .car-detail-table td {
    text-align: left;
    vertical-align: top;
    padding: 2px 12px 2px 0;
    font-weight: 600;
    color: #363636;
}
.car-detail-table th {
    color: #262b56;
    width: 140px;
    font-weight: 700;
    letter-spacing: 0.2px;
}
.car-detail-table td {
    font-weight: 400;
    color: #202020;
}
.form-table {
    width: 92%;
    margin: 10px auto 0 auto;
    font-size: 1.06em;
    border-collapse: separate;
    border-spacing: 0 10px;
}
.form-table th {
    width: 140px;
    color: #262b56;
    font-weight: 700;
    text-align: left;
    vertical-align: middle;
    padding-right: 10px;
}
.form-table td {
    padding-bottom: 3px;
}
input[type="date"], select, input[type="time"] {
    padding: 7px 10px;
    border-radius: 7px;
    border: 1px solid #bfc8e6;
    font-size: 1em;
    background: #f9fafd;
    margin-right: 8px;
}
input[type="date"]:invalid {
    color: #aaa;
}
.form-btn-row {
    width: 92%;
    margin: 24px auto 0 auto;
    display: flex;
    flex-direction: row;
    justify-content: flex-end;
    gap: 14px;
}
.next-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 13px 43px;
    border-radius: 11px;
    font-size: 1.17em;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.18s;
}
.next-btn:hover {
    background: #234c96;
}
.back-btn {
    background: #e6eaff;
    color: #2f377d;
    border: none;
    padding: 13px 43px;
    border-radius: 11px;
    font-size: 1.08em;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.18s;
    text-decoration: none;
    text-align: center;
    display: inline-block;
}
.back-btn:hover {
    background: #cfd8fa;
    color: #172043;
}
@media (max-width: 700px) {
    .car-detail-card { width:98vw; min-width: unset; }
    .car-detail-title { font-size: 1.05em; padding-left: 4vw;}
    .car-img-bg { margin: 0 2vw 10px 2vw; padding: 7px 0 0 0; }
    .car-detail-img { width: 90vw; height: 90px;}
    .car-detail-table th, .form-table th { width: 36vw;}
    .form-btn-row { flex-direction: column; gap: 8px;}
    .next-btn, .back-btn { width: 100%; margin: 0; }
}
</style>
<script>
const unavailableRanges = <?= json_encode($unavailable_ranges) ?>;

// Helper: returns true if dateString is within any unavailable range
function isDateUnavailable(dateString) {
    if (!dateString) return false;
    const d = new Date(dateString);
    for (const range of unavailableRanges) {
        const start = new Date(range.start);
        const end = new Date(range.end);
        if (d >= start && d <= end) return true;
    }
    return false;
}

window.addEventListener('DOMContentLoaded', function() {
    // Disable unavailable dates in the pickup and return date pickers
    const pickupDateInput = document.querySelector('input[name="pickup_date"]');
    const returnDateInput = document.querySelector('input[name="return_date"]');
    
    function validateDate(input) {
        input.addEventListener('change', function() {
            if (isDateUnavailable(this.value)) {
                alert("This date is not available for booking. Please choose another date.");
                this.value = '';
            }
        });
    }

    validateDate(pickupDateInput);
    validateDate(returnDateInput);

    // Set min date as today for both pickers
    const today = new Date().toISOString().split('T')[0];
    pickupDateInput.setAttribute('min', today);
    returnDateInput.setAttribute('min', today);
});
</script>

<div class="car-detail-outer">
    <div class="car-detail-card">
        <div class="car-detail-title"><?= htmlspecialchars($car['car_brand'] . ' ' . $car['car_model']) ?></div>
        <div class="car-img-bg">
            <img class="car-detail-img"
                src="<?= !empty($car['car_image_id']) ? "get_car_image.php?car_image_id=" . $car['car_image_id'] : '/assets/images/viva_elite.png' ?>"
                alt="Car image"
                onerror="this.src='/assets/images/viva_elite.png'">
        </div>

        <table class="car-detail-table">
            <tr><th>Plate No:</th>       <td><?= htmlspecialchars($car['plate_no']) ?></td></tr>
            <tr><th>Year:</th>           <td><?= htmlspecialchars($car['year']) ?></td></tr>
            <tr><th>Color:</th>          <td><?= htmlspecialchars($car['color']) ?></td></tr>
            <tr><th>Transmission:</th>   <td><?= htmlspecialchars($car['transmission']) ?></td></tr>
            <tr><th>Seat Capacity:</th>  <td><?= htmlspecialchars($car['seat_capacity']) ?></td></tr>
            <tr><th>Mileage:</th>        <td><?= htmlspecialchars($car['mileage']) ?> km</td></tr>
            <tr><th>Daily Rate:</th>     <td>RM <?= number_format($car['daily_rate'], 2) ?></td></tr>
        </table>

        <form action="booking_driver.php" method="POST" style="margin-bottom:0;">
            <input type="hidden" name="car_id" value="<?= $car['car_id'] ?>">

            <table class="form-table">
                <tr>
                    <th>Pickup Date:</th>
                    <td>
                        <input type="date" name="pickup_date" value="<?= htmlspecialchars($pickup_date) ?>" required>
                        <span style="margin-left:10px;margin-right:2px;">Time:</span>
                        <select name="pickup_time" required>
                            <?php
                            for ($h = 0; $h < 24; $h++) {
                                for ($m = 0; $m < 60; $m += 30) {
                                    $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
                                    $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
                                    $val = "$hour:$minute";
                                    $selected = ($pickup_time === $val) ? 'selected' : '';
                                    echo "<option value=\"$val\" $selected>$val</option>";
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Return Date:</th>
                    <td>
                        <input type="date" name="return_date" value="<?= htmlspecialchars($return_date) ?>" required>
                        <span style="margin-left:10px;margin-right:2px;">Time:</span>
                        <select name="return_time" required>
                            <?php
                            for ($h = 0; $h < 24; $h++) {
                                for ($m = 0; $m < 60; $m += 30) {
                                    $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
                                    $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
                                    $val = "$hour:$minute";
                                    $selected = ($return_time === $val) ? 'selected' : '';
                                    echo "<option value=\"$val\" $selected>$val</option>";
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Service:</th>
                    <td>
                        <select name="delivery_type" required>
                            <option value="self_pickup" <?= $delivery_type === "self_pickup" ? "selected" : "" ?>>Pick up myself (FREE)</option>
                            <option value="delivery" <?= $delivery_type === "delivery" ? "selected" : "" ?>>Deliver car to me (+RM10)</option>
                            <option value="pickup_and_return" <?= $delivery_type === "pickup_and_return" ? "selected" : "" ?>>Deliver &amp; return pickup (+RM30)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <div class="form-btn-row">
                <a href="dashboard.php" class="back-btn">Back</a>
                <button type="submit" class="next-btn">Next</button>
            </div>
        </form>
    </div>
</div>
<?php include '../includes/footer.php'; ?>