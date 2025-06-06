<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

// Ensure booking_data exists
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['car_id'])) {
    // Collect data and save in session for next steps
    $car_id = intval($_POST['car_id']);
    $pickup_date = $_POST['pickup_date'];
    $pickup_time = $_POST['pickup_time'];
    $return_date = $_POST['return_date'];
    $return_time = $_POST['return_time'];
    $delivery_type = $_POST['delivery_type'];
    $pickup_datetime = $pickup_date . ' ' . $pickup_time . ':00';
    $return_datetime = $return_date . ' ' . $return_time . ':00';

    $_SESSION['booking_data'] = [
        'car_id' => $car_id,
        'pickup_datetime' => $pickup_datetime,
        'return_datetime' => $return_datetime,
        'delivery_type' => $delivery_type
    ];
} elseif (!isset($_SESSION['booking_data'])) {
    header("Location: dashboard.php");
    exit;
}

// Pre-fill from customer table if possible
$cust_id = $_SESSION['cust_id'];
$stmt = $conn->prepare("SELECT full_name, phone_no, email, license_no, id_no, address, age FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($full_name, $phone_no, $email, $license_no, $id_no, $address, $age);
$stmt->fetch();
$stmt->close();
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.form-section {
    max-width: 600px;
    margin: 40px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 28px 32px 24px 32px;
}
.form-title {
    font-size: 1.25em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 20px;
}
.input-row {
    margin-bottom: 16px;
}
.input-label {
    display: block;
    color: #555;
    font-weight: 600;
    margin-bottom: 4px;
}
input[type="text"], input[type="email"], input[type="file"], input[type="number"], select {
    width: 100%;
    padding: 7px 8px;
    border-radius: 5px;
    border: 1px solid #d9d9d9;
    margin-bottom: 2px;
    font-size: 1em;
}
input[type="file"] {padding: 4px 0;}
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
.next-btn:hover {background: #234c96;}
</style>

<div class="form-section">
    <div class="form-title">Driver's Details (Customer)</div>
    <form action="booking_guarantor.php" method="POST" enctype="multipart/form-data">
        <div class="input-row">
            <label class="input-label">Full Name</label>
            <input type="text" name="driver_full_name" value="<?= htmlspecialchars($full_name ?? '') ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Phone Number</label>
            <input type="text" name="driver_phone_no" value="<?= htmlspecialchars($phone_no ?? '') ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Email</label>
            <input type="email" name="driver_email" value="<?= htmlspecialchars($email ?? '') ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Number</label>
            <input type="text" name="driver_id_no" value="<?= htmlspecialchars($id_no ?? '') ?>">
        </div>
        <div class="input-row">
            <label class="input-label">ID Front Image</label>
            <input type="file" name="driver_id_front" accept="image/*">
        </div>
        <div class="input-row">
            <label class="input-label">ID Back Image</label>
            <input type="file" name="driver_id_back" accept="image/*">
        </div>
        <div class="input-row">
            <label class="input-label">License Number</label>
            <input type="text" name="driver_license_no" value="<?= htmlspecialchars($license_no ?? '') ?>">
        </div>
        <div class="input-row">
            <label class="input-label">Address</label>
            <input type="text" name="driver_address" value="<?= htmlspecialchars($address ?? '') ?>">
        </div>
        <div class="input-row">
            <label class="input-label">Age</label>
            <input type="number" name="driver_age" min="18" value="<?= htmlspecialchars($age ?? '') ?>">
        </div>
        <div style="margin-top: 28px; text-align: right;">
            <button type="submit" class="next-btn">Next</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>