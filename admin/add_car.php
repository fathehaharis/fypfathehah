<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$error = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_car'])) {
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
    $admin_id     = $_SESSION['admin_id'];

    // Document upload handling
    $car_grant_path = null;
    $car_roadtax_path = null;
    $car_covernote_path = null;

    if (isset($_FILES['car_grant_path']) && $_FILES['car_grant_path']['error'] === UPLOAD_ERR_OK) {
        $car_grant_path = file_get_contents($_FILES['car_grant_path']['tmp_name']);
    }
    if (isset($_FILES['car_roadtax_path']) && $_FILES['car_roadtax_path']['error'] === UPLOAD_ERR_OK) {
        $car_roadtax_path = file_get_contents($_FILES['car_roadtax_path']['tmp_name']);
    }
    if (isset($_FILES['car_covernote_path']) && $_FILES['car_covernote_path']['error'] === UPLOAD_ERR_OK) {
        $car_covernote_path = file_get_contents($_FILES['car_covernote_path']['tmp_name']);
    }

    // Image upload handling
    $car_image_data = null;
    $image_type = "main";
    if (isset($_FILES['car_image']) && $_FILES['car_image']['error'] === UPLOAD_ERR_OK) {
        $car_image_data = file_get_contents($_FILES['car_image']['tmp_name']);
    }

    // Basic validation
    if ($plate_no && $car_brand && $car_model && $year && $color && $transmission && $seat_capacity && $status) {
        $stmt = $conn->prepare("INSERT INTO car 
            (plate_no, car_brand, car_model, year, color, transmission, seat_capacity, mileage, daily_rate, hourly_rate, status, car_grant_path, car_roadtax_path, car_covernote_path, admin_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "sssissiidsssssi",
            $plate_no, $car_brand, $car_model, $year, $color, $transmission, $seat_capacity, $mileage, $daily_rate, $hourly_rate, $status,
            $car_grant_path, $car_roadtax_path, $car_covernote_path, $admin_id
        );
        if ($stmt->execute()) {
            $success = true;
            $new_car_id = $stmt->insert_id;
            $stmt->close();

            // Insert car image if uploaded
            if ($car_image_data) {
                $img_stmt = $conn->prepare("INSERT INTO car_image (car_id, image_type, image_path, uploaded_at) VALUES (?, ?, ?, NOW())");
                $img_stmt->bind_param("iss", $new_car_id, $image_type, $car_image_data);
                $img_stmt->send_long_data(2, $car_image_data);
                $img_stmt->execute();
                $img_stmt->close();
            }

            header("Location: car_details.php?id=$new_car_id&add=success");
            exit;
        } else {
            $error = "Database error: " . $stmt->error;
            $stmt->close();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}
?>
<?php include 'admin_header.php'; ?>

<style>
.add-car-container {
    max-width: 700px;
    margin: 35px auto 25px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 16px #e0e7ef33;
    padding: 32px 34px 22px 34px;
}
.add-car-title {
    font-size: 1.7em;
    font-weight: 700;
    color: #1f3455;
    letter-spacing: 0.5px;
    margin-bottom: 15px;
}
.add-car-form label {
    font-weight: 600;
    color: #344e88;
    margin-bottom: 5px;
    display: block;
}
.add-car-form input, .add-car-form select {
    width: 100%;
    padding: 8px 10px;
    margin-bottom: 18px;
    border: 1.2px solid #cfd8ef;
    border-radius: 5px;
    font-size: 1em;
    background: #f8fafc;
}
.add-car-form button {
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
.add-car-form button:hover {
    background: #1b2d70;
}
.error-msg {
    color: #e54848;
    margin-bottom: 15px;
    font-weight: 600;
}
@media (max-width: 700px) {
    .add-car-container { padding: 14px 4vw 18px 4vw;}
}
</style>

<div class="add-car-container">
    <div class="add-car-title">Add New Car</div>
    <?php if (!empty($error)): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" class="add-car-form" autocomplete="off" enctype="multipart/form-data">
        <label for="plate_no">Plate Number</label>
        <input type="text" name="plate_no" id="plate_no" value="<?= isset($_POST['plate_no']) ? htmlspecialchars($_POST['plate_no']) : '' ?>" required>

        <label for="car_brand">Brand</label>
        <input type="text" name="car_brand" id="car_brand" value="<?= isset($_POST['car_brand']) ? htmlspecialchars($_POST['car_brand']) : '' ?>" required>

        <label for="car_model">Model</label>
        <input type="text" name="car_model" id="car_model" value="<?= isset($_POST['car_model']) ? htmlspecialchars($_POST['car_model']) : '' ?>" required>

        <label for="year">Year</label>
        <input type="number" min="1980" max="<?= date('Y')+1 ?>" name="year" id="year" value="<?= isset($_POST['year']) ? htmlspecialchars($_POST['year']) : '' ?>" required>

        <label for="color">Color</label>
        <input type="text" name="color" id="color" value="<?= isset($_POST['color']) ? htmlspecialchars($_POST['color']) : '' ?>" required>

        <label for="transmission">Transmission</label>
        <select name="transmission" id="transmission" required>
            <option value="" disabled <?= !isset($_POST['transmission']) ? 'selected' : '' ?>>Select...</option>
            <option value="Automatic" <?= (isset($_POST['transmission']) && $_POST['transmission']=='Automatic')?'selected':'' ?>>Automatic</option>
            <option value="Manual" <?= (isset($_POST['transmission']) && $_POST['transmission']=='Manual')?'selected':'' ?>>Manual</option>
        </select>

        <label for="seat_capacity">Seat Capacity</label>
        <input type="number" name="seat_capacity" id="seat_capacity" value="<?= isset($_POST['seat_capacity']) ? htmlspecialchars($_POST['seat_capacity']) : '' ?>" min="2" max="15" required>

        <label for="mileage">Mileage (km)</label>
        <input type="number" name="mileage" id="mileage" value="<?= isset($_POST['mileage']) ? htmlspecialchars($_POST['mileage']) : '' ?>" min="0" required>

        <label for="daily_rate">Daily Rate (RM)</label>
        <input type="number" step="0.01" name="daily_rate" id="daily_rate" value="<?= isset($_POST['daily_rate']) ? htmlspecialchars($_POST['daily_rate']) : '' ?>" min="0" required>

        <label for="hourly_rate">Hourly Rate (RM)</label>
        <input type="number" step="0.01" name="hourly_rate" id="hourly_rate" value="<?= isset($_POST['hourly_rate']) ? htmlspecialchars($_POST['hourly_rate']) : '8.00' ?>" min="0" required>

        <label for="status">Status</label>
        <select name="status" id="status" required>
            <option value="" disabled <?= !isset($_POST['status']) ? 'selected' : '' ?>>Select...</option>
            <option value="available" <?= (isset($_POST['status']) && $_POST['status']=='available')?'selected':'' ?>>Available</option>
            <option value="rented" <?= (isset($_POST['status']) && $_POST['status']=='rented')?'selected':'' ?>>Rented</option>
            <option value="maintenance" <?= (isset($_POST['status']) && $_POST['status']=='maintenance')?'selected':'' ?>>Maintenance</option>
        </select>

        <label for="car_image">Car Image (jpg/jpeg/png)</label>
        <input type="file" name="car_image" id="car_image" accept="image/*">

        <label for="car_grant_path">Grant Document (pdf/image)</label>
        <input type="file" name="car_grant_path" id="car_grant_path" accept="application/pdf,image/*">

        <label for="car_roadtax_path">Roadtax Document (pdf/image)</label>
        <input type="file" name="car_roadtax_path" id="car_roadtax_path" accept="application/pdf,image/*">

        <label for="car_covernote_path">Covernote Document (pdf/image)</label>
        <input type="file" name="car_covernote_path" id="car_covernote_path" accept="application/pdf,image/*">

        <button type="submit" name="add_car">Add Car</button>
    </form>
</div>
<?php include '../includes/footer.php'; ?>