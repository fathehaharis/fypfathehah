<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

// Ensure previous (driver) data exists
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['driver_full_name'])) {
    // Save driver details from previous form to session
    $_SESSION['driver_data'] = [
        'driver_full_name'   => $_POST['driver_full_name'] ?? '',
        'driver_phone_no'    => $_POST['driver_phone_no'] ?? '',
        'driver_email'       => $_POST['driver_email'] ?? '',
        'driver_id_no'       => $_POST['driver_id_no'] ?? '',
        'driver_license_no'  => $_POST['driver_license_no'] ?? '',
        'driver_address'     => $_POST['driver_address'] ?? '',
        'driver_age'         => $_POST['driver_age'] ?? '',
    ];
    // Handle uploaded images (store in session temporarily as file paths)
    if (isset($_FILES['driver_id_front']) && $_FILES['driver_id_front']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['driver_id_front']['tmp_name'];
        $name = uniqid('idfront_') . '_' . basename($_FILES['driver_id_front']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['driver_data']['driver_id_front'] = $dest;
    }
    if (isset($_FILES['driver_id_back']) && $_FILES['driver_id_back']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['driver_id_back']['tmp_name'];
        $name = uniqid('idback_') . '_' . basename($_FILES['driver_id_back']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['driver_data']['driver_id_back'] = $dest;
    }
} elseif (!isset($_SESSION['driver_data'])) {
    header("Location: booking_driver.php");
    exit;
}

// When coming from review_booking or after submit, save POST to session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guarantor_full_name'])) {
    $_SESSION['guarantor_data'] = [
        'guarantor_full_name' => $_POST['guarantor_full_name'] ?? '',
        'guarantor_phone_no' => $_POST['guarantor_phone_no'] ?? '',
        'guarantor_id_no' => $_POST['guarantor_id_no'] ?? '',
        'guarantor_relationship' => $_POST['guarantor_relationship'] ?? '',
    ];
    // handle file uploads for ID images if needed
}

// Prefill variables from session if exists
$guarantor_full_name = $_SESSION['guarantor_data']['guarantor_full_name'] ?? '';
$guarantor_phone_no = $_SESSION['guarantor_data']['guarantor_phone_no'] ?? '';
$guarantor_id_no = $_SESSION['guarantor_data']['guarantor_id_no'] ?? '';
$guarantor_relationship = $_SESSION['guarantor_data']['guarantor_relationship'] ?? '';
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
input[type="text"], input[type="email"], input[type="file"], select {
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
}
.back-btn:hover {
    background: #bbb;
}
.btn-row {
    margin-top: 28px;
    text-align: right;
}
</style>

<div class="form-section">
    <div class="form-title">Guarantor's Details</div>
    <form action="review_booking.php" method="POST" enctype="multipart/form-data">
        <div class="input-row">
            <label class="input-label">Full Name<span class="required-star">*</span></label>
            <input type="text" name="guarantor_full_name" value="<?= htmlspecialchars($guarantor_full_name) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Phone Number<span class="required-star">*</span></label>
            <input type="text" name="guarantor_phone_no" value="<?= htmlspecialchars($guarantor_phone_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Number<span class="required-star">*</span></label>
            <input type="text" name="guarantor_id_no" value="<?= htmlspecialchars($guarantor_id_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Front Image<span class="required-star">*</span></label>
            <input type="file" name="guarantor_id_front" accept="image/*" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Back Image<span class="required-star">*</span></label>
            <input type="file" name="guarantor_id_back" accept="image/*" required>
        </div>
        <div class="input-row">
            <label class="input-label">Relationship<span class="required-star">*</span></label>
            <input type="text" name="guarantor_relationship" value="<?= htmlspecialchars($guarantor_relationship) ?>" required>
        </div>
        <div class="btn-row">
            <button type="button" class="back-btn" onclick="window.history.back();">Back</button>
            <button type="submit" class="next-btn">Next</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>