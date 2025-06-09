<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

// Ensure driver data exists before proceeding
if (!isset($_SESSION['driver_id'])) {
    header("Location: booking_driver.php");
    exit;
}

$errors = [];
// On POST, save guarantor info and image file paths to session, then redirect to review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guarantor_full_name'])) {
    $_SESSION['guarantor_data'] = [
        'guarantor_full_name'      => $_POST['guarantor_full_name'] ?? '',
        'guarantor_phone_no'       => $_POST['guarantor_phone_no'] ?? '',
        'guarantor_id_no'          => $_POST['guarantor_id_no'] ?? '',
        'guarantor_relationship'   => $_POST['guarantor_relationship'] ?? '',
    ];

    // Handle file uploads for ID images (store temp file paths in session)
    if (isset($_FILES['guarantor_id_front']) && $_FILES['guarantor_id_front']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_front']['tmp_name'];
        $name = uniqid('g_idfront_') . '_' . basename($_FILES['guarantor_id_front']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_front'] = $dest;
    } else {
        $errors[] = "ID Front Image is required.";
    }
    if (isset($_FILES['guarantor_id_back']) && $_FILES['guarantor_id_back']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_back']['tmp_name'];
        $name = uniqid('g_idback_') . '_' . basename($_FILES['guarantor_id_back']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_back'] = $dest;
    } else {
        $errors[] = "ID Back Image is required.";
    }
    // Only redirect if no errors
    if (empty($errors)) {
        header("Location: review_booking.php");
        exit;
    }
}

// Prefill values from session if available
$guarantor_full_name    = $_SESSION['guarantor_data']['guarantor_full_name'] ?? '';
$guarantor_phone_no     = $_SESSION['guarantor_data']['guarantor_phone_no'] ?? '';
$guarantor_id_no        = $_SESSION['guarantor_data']['guarantor_id_no'] ?? '';
$guarantor_relationship = $_SESSION['guarantor_data']['guarantor_relationship'] ?? '';
$car_id = $_SESSION['booking_data']['car_id'] ?? '';

// Only include HTML/output after all possible redirects
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
input[type="text"], input[type="file"], select {
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
    <div class="form-title">Guarantor's Details</div>
    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form action="booking_guarantor.php" method="POST" enctype="multipart/form-data">
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
            <a href="booking_driver.php?car_id=<?= htmlspecialchars($car_id) ?>" class="back-btn">Back</a>
            <button type="submit" class="next-btn">Next</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>