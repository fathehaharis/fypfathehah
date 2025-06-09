<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

// Handle driver form submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['driver_full_name'])) {
    $errors = [];
    $full_name = trim($_POST['driver_full_name']);
    $phone_no = trim($_POST['driver_phone_no']);
    $id_no = trim($_POST['driver_id_no']);
    $license_no = trim($_POST['driver_license_no']);
    $address = trim($_POST['driver_address']);
    $age = trim($_POST['driver_age']);

    if ($full_name === '') $errors[] = "Full Name is required.";
    if ($phone_no === '') $errors[] = "Phone Number is required.";
    if ($id_no === '') $errors[] = "ID Number is required.";
    if (empty($_FILES['driver_id_front']) || $_FILES['driver_id_front']['error'] !== UPLOAD_ERR_OK) $errors[] = "ID Front Image is required.";
    if (empty($_FILES['driver_id_back']) || $_FILES['driver_id_back']['error'] !== UPLOAD_ERR_OK) $errors[] = "ID Back Image is required.";
    if ($license_no === '') $errors[] = "License Number is required.";
    if ($address === '') $errors[] = "Address is required.";
    if ($age === '' || !is_numeric($age) || $age < 18) $errors[] = "Valid Age (18+) is required.";

    // Save form values to session so user doesn't have to retype on error or back
    $_SESSION['driver_data'] = [
        'full_name'   => $full_name,
        'phone_no'    => $phone_no,
        'id_no'       => $id_no,
        'license_no'  => $license_no,
        'address'     => $address,
        'age'         => $age,
    ];

    // Handle file uploads (save temp file paths in session)
    if (empty($errors)) {
        $front_tmp = $_FILES['driver_id_front']['tmp_name'];
        $front_name = uniqid('d_idfront_') . '_' . basename($_FILES['driver_id_front']['name']);
        $front_dest = sys_get_temp_dir() . '/' . $front_name;
        move_uploaded_file($front_tmp, $front_dest);

        $back_tmp = $_FILES['driver_id_back']['tmp_name'];
        $back_name = uniqid('d_idback_') . '_' . basename($_FILES['driver_id_back']['name']);
        $back_dest = sys_get_temp_dir() . '/' . $back_name;
        move_uploaded_file($back_tmp, $back_dest);

        $_SESSION['driver_data']['id_front'] = $front_dest;
        $_SESSION['driver_data']['id_back'] = $back_dest;

        // Mark as "driver complete"
        $_SESSION['driver_id'] = true;

        header("Location: booking_guarantor.php");
        exit;
    } else {
        $_SESSION['driver_errors'] = $errors;
        header("Location: booking_driver.php");
        exit;
    }
}

// Errors, if any
$errors = $_SESSION['driver_errors'] ?? [];
unset($_SESSION['driver_errors']);

// Always pre-fill from session if data exists (allows "back" from guarantor to retain values)
$driver_data = $_SESSION['driver_data'] ?? [];
$full_name = $driver_data['full_name'] ?? '';
$phone_no = $driver_data['phone_no'] ?? '';
$id_no = $driver_data['id_no'] ?? '';
$license_no = $driver_data['license_no'] ?? '';
$address = $driver_data['address'] ?? '';
$age = $driver_data['age'] ?? '';
$car_id = $_SESSION['booking_data']['car_id'] ?? '';

include '../includes/header.php';
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
.required-star {
    color: #c62828;
    margin-left: 3px;
    font-weight: bold;
    font-size: 1.1em;
}
input[type="text"], input[type="file"], input[type="number"], select {
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
    margin-left: 8px;
}
.next-btn:hover {background: #234c96;}
.back-btn {
    background: #ccc;
    color: #222;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    text-decoration: none;
    display: inline-block;
}
.back-btn:hover {
    background: #bbb;
}
.btn-row {
    margin-top: 28px;
    text-align: right;
}
.error-message {
    background: #ffe0e0;
    color: #a80000;
    border: 1px solid #a80000;
    padding: 10px;
    margin-bottom: 15px;
    border-radius: 5px;
}
</style>

<div class="form-section">
    <div class="form-title">Driver's Details</div>
    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form action="booking_driver.php" method="POST" enctype="multipart/form-data" autocomplete="off">
        <div class="input-row">
            <label class="input-label">Full Name<span class="required-star">*</span></label>
            <input type="text" name="driver_full_name" value="<?= htmlspecialchars($full_name) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Phone Number<span class="required-star">*</span></label>
            <input type="text" name="driver_phone_no" value="<?= htmlspecialchars($phone_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Number<span class="required-star">*</span></label>
            <input type="text" name="driver_id_no" value="<?= htmlspecialchars($id_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Front Image<span class="required-star">*</span></label>
            <input type="file" name="driver_id_front" accept="image/*" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Back Image<span class="required-star">*</span></label>
            <input type="file" name="driver_id_back" accept="image/*" required>
        </div>
        <div class="input-row">
            <label class="input-label">License Number<span class="required-star">*</span></label>
            <input type="text" name="driver_license_no" value="<?= htmlspecialchars($license_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Address<span class="required-star">*</span></label>
            <input type="text" name="driver_address" value="<?= htmlspecialchars($address) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Age<span class="required-star">*</span></label>
            <input type="number" name="driver_age" min="18" value="<?= htmlspecialchars($age) ?>" required>
        </div>
        <div class="btn-row">
            <a href="book_car.php?car_id=<?= htmlspecialchars($car_id) ?>" class="back-btn">Back</a>
            <button type="submit" class="next-btn">Next</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>