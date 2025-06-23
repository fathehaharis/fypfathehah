<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div style='padding:40px;text-align:center;color:#b33;'>Invalid car ID.</div>";
    exit;
}
$car_id = intval($_GET['id']);

// Fetch car data
$car_sql = "SELECT * FROM car WHERE car_id = $car_id";
$car_result = $conn->query($car_sql);
if (!$car_result || !$car_result->num_rows) {
    echo "<div style='padding:40px;text-align:center;color:#b33;'>Car not found.</div>";
    exit;
}
$car = $car_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_car'])) {
    $plate_no     = trim($_POST['plate_no']);
    $car_brand    = trim($_POST['car_brand']);
    $car_model    = trim($_POST['car_model']);
    $year         = intval($_POST['year']);
    $color        = trim($_POST['color']);
    $transmission = trim($_POST['transmission']);
    $seat_capacity= intval($_POST['seat_capacity']);
    $mileage      = intval($_POST['mileage']);
    $daily_rate   = floatval($_POST['daily_rate']);
    $hourly_rate  = floatval($_POST['hourly_rate']);
    $status       = trim($_POST['status']);

    // Basic validation (can be expanded)
    if ($plate_no && $car_brand && $car_model && $year && $color && $transmission && $seat_capacity && $status) {
        $stmt = $conn->prepare("UPDATE car SET plate_no=?, car_brand=?, car_model=?, year=?, color=?, transmission=?, seat_capacity=?, mileage=?, daily_rate=?, hourly_rate=?, status=? WHERE car_id=?");
        $stmt->bind_param("sssissiidssi", $plate_no, $car_brand, $car_model, $year, $color, $transmission, $seat_capacity, $mileage, $daily_rate, $hourly_rate, $status, $car_id);
        $stmt->execute();
        $stmt->close();
        header("Location: car_details.php?id=$car_id&update=success");
        exit;
    } else {
        $error = "Please fill in all required fields.";
    }
}
?>
<?php include 'admin_header.php'; ?>

<style>
.edit-car-container {
    max-width: 700px;
    margin: 35px auto 25px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 16px #e0e7ef33;
    padding: 32px 34px 22px 34px;
}
.edit-car-title {
    font-size: 1.7em;
    font-weight: 700;
    color: #1f3455;
    letter-spacing: 0.5px;
    margin-bottom: 15px;
}
.edit-car-form label {
    font-weight: 600;
    color: #344e88;
    margin-bottom: 5px;
    display: block;
}
.edit-car-form input, .edit-car-form select {
    width: 100%;
    padding: 8px 10px;
    margin-bottom: 18px;
    border: 1.2px solid #cfd8ef;
    border-radius: 5px;
    font-size: 1em;
    background: #f8fafc;
}
.edit-car-form button {
    background: #304cc3;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 10px 26px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
}
.edit-car-form button:hover {
    background: #1b2d70;
}
.error-msg {
    color: #e54848;
    margin-bottom: 15px;
    font-weight: 600;
}
@media (max-width: 700px) {
    .edit-car-container { padding: 14px 4vw 18px 4vw;}
}
</style>

<div class="edit-car-container">
    <div class="edit-car-title">Edit Car</div>
    <?php if (!empty($error)): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" class="edit-car-form" autocomplete="off">
        <label for="plate_no">Plate Number</label>
        <input type="text" name="plate_no" id="plate_no" value="<?= htmlspecialchars($car['plate_no']) ?>" required>

        <label for="car_brand">Brand</label>
        <input type="text" name="car_brand" id="car_brand" value="<?= htmlspecialchars($car['car_brand']) ?>" required>

        <label for="car_model">Model</label>
        <input type="text" name="car_model" id="car_model" value="<?= htmlspecialchars($car['car_model']) ?>" required>

        <label for="year">Year</label>
        <input type="number" min="1980" max="<?= date('Y')+1 ?>" name="year" id="year" value="<?= htmlspecialchars($car['year']) ?>" required>

        <label for="color">Color</label>
        <input type="text" name="color" id="color" value="<?= htmlspecialchars($car['color']) ?>" required>

        <label for="transmission">Transmission</label>
        <select name="transmission" id="transmission" required>
            <option value="Automatic" <?= $car['transmission']=='Automatic'?'selected':'' ?>>Automatic</option>
            <option value="Manual" <?= $car['transmission']=='Manual'?'selected':'' ?>>Manual</option>
        </select>

        <label for="seat_capacity">Seat Capacity</label>
        <input type="number" name="seat_capacity" id="seat_capacity" value="<?= htmlspecialchars($car['seat_capacity']) ?>" min="2" max="15" required>

        <label for="mileage">Mileage (km)</label>
        <input type="number" name="mileage" id="mileage" value="<?= htmlspecialchars($car['mileage']) ?>" min="0" required>

        <label for="daily_rate">Daily Rate (RM)</label>
        <input type="number" step="0.01" name="daily_rate" id="daily_rate" value="<?= htmlspecialchars($car['daily_rate']) ?>" min="0" required>

        <label for="hourly_rate">Hourly Rate (RM)</label>
        <input type="number" step="0.01" name="hourly_rate" id="hourly_rate" value="<?= htmlspecialchars($car['hourly_rate']) ?>" min="0" required>

        <label for="status">Status</label>
        <select name="status" id="status" required>
            <option value="available" <?= $car['status']=='available'?'selected':'' ?>>Available</option>
            <option value="not available" <?= $car['status']=='not available'?'selected':'' ?>>Not Available</option>
        </select>

        <button type="submit" name="update_car">Update Car</button>
    </form>
</div>
<?php include '../includes/footer.php'; ?>