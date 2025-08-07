<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

// Ensure booking data exists before proceeding (optional: depends on your flow)
if (!isset($_SESSION['booking_data'])) {
    header("Location: book_car.php");
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
        'cust_id'                  => $_SESSION['cust_id'], // associate with customer!
    ];

    // Validate phone and IC format
    if (!preg_match('/^01[0-9]-\d{7,8}$/', $_POST['guarantor_phone_no'])) {
        $errors[] = "Phone Number must be in the format 01X-XXXXXXX or 01X-XXXXXXXX.";
    }
    if (!preg_match('/^\d{6}-\d{2}-\d{4}$/', $_POST['guarantor_id_no'])) {
        $errors[] = "ID Number must be in the format XXXXXX-XX-XXXX.";
    }

    // Handle file uploads for ID images (store temp file paths in session)
    // Only overwrite if new file is uploaded, otherwise keep old (if exists)
    if (isset($_FILES['guarantor_id_front']) && $_FILES['guarantor_id_front']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_front']['tmp_name'];
        $name = uniqid('g_idfront_') . '_' . basename($_FILES['guarantor_id_front']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_front'] = $dest;
    } elseif (!empty($_SESSION['guarantor_data']['guarantor_id_front']) && file_exists($_SESSION['guarantor_data']['guarantor_id_front'])) {
        // keep previous upload
    } else {
        $errors[] = "ID Front Image is required.";
    }

    if (isset($_FILES['guarantor_id_back']) && $_FILES['guarantor_id_back']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_back']['tmp_name'];
        $name = uniqid('g_idback_') . '_' . basename($_FILES['guarantor_id_back']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_back'] = $dest;
    } elseif (!empty($_SESSION['guarantor_data']['guarantor_id_back']) && file_exists($_SESSION['guarantor_data']['guarantor_id_back'])) {
        // keep previous upload
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
$car_id                 = $_SESSION['booking_data']['car_id'] ?? '';

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
.img-preview {
    max-width: 130px;
    max-height: 90px;
    border-radius: 7px;
    border: 1px solid #e1e1e1;
    background: #f7fafd;
    display: block;
    margin-bottom: 6px;
}
.note {
    color: #888;
    font-size: 0.97em;
}
</style>
<script>
function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Phone number auto-format: 01X-XXXXXXX or 01X-XXXXXXXX
    const phoneInput = document.querySelector('input[name="guarantor_phone_no"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 3) value = value.slice(0, 3) + '-' + value.slice(3, 11);
            this.value = value.slice(0, 12);
        });
    }
    // IC auto-format: XXXXXX-XX-XXXX
    const icInput = document.querySelector('input[name="guarantor_id_no"]');
    if (icInput) {
        icInput.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 6) value = value.slice(0, 6) + '-' + value.slice(6);
            if (value.length > 9) value = value.slice(0, 9) + '-' + value.slice(9, 13);
            this.value = value.slice(0, 14);
        });
    }
});
</script>

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
            <input type="text" name="guarantor_phone_no" pattern="^01[0-9]-\d{7,8}$" maxlength="12" placeholder="01X-XXXXXXX" value="<?= htmlspecialchars($guarantor_phone_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Number<span class="required-star">*</span></label>
            <input type="text" name="guarantor_id_no" pattern="^\d{6}-\d{2}-\d{4}$" maxlength="14" placeholder="XXXXXX-XX-XXXX" value="<?= htmlspecialchars($guarantor_id_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Front Image<span class="required-star">*</span></label>
            <?php if (!empty($_SESSION['guarantor_data']['guarantor_id_front']) && file_exists($_SESSION['guarantor_data']['guarantor_id_front'])): ?>
                <img src="data:image/jpeg;base64,<?= base64_encode(file_get_contents($_SESSION['guarantor_data']['guarantor_id_front'])) ?>" class="img-preview" id="preview_guarantor_id_front" alt="Guarantor ID Front">
                <span class="note">File already uploaded. Upload a new one to replace.</span>
            <?php else: ?>
                <img class="img-preview" id="preview_guarantor_id_front" style="display:none;">
            <?php endif; ?>
            <input type="file" name="guarantor_id_front" accept="image/*" onchange="previewImage(this, 'preview_guarantor_id_front')" <?= empty($_SESSION['guarantor_data']['guarantor_id_front']) ? 'required' : '' ?>>
        </div>
        <div class="input-row">
            <label class="input-label">ID Back Image<span class="required-star">*</span></label>
            <?php if (!empty($_SESSION['guarantor_data']['guarantor_id_back']) && file_exists($_SESSION['guarantor_data']['guarantor_id_back'])): ?>
                <img src="data:image/jpeg;base64,<?= base64_encode(file_get_contents($_SESSION['guarantor_data']['guarantor_id_back'])) ?>" class="img-preview" id="preview_guarantor_id_back" alt="Guarantor ID Back">
                <span class="note">File already uploaded. Upload a new one to replace.</span>
            <?php else: ?>
                <img class="img-preview" id="preview_guarantor_id_back" style="display:none;">
            <?php endif; ?>
            <input type="file" name="guarantor_id_back" accept="image/*" onchange="previewImage(this, 'preview_guarantor_id_back')" <?= empty($_SESSION['guarantor_data']['guarantor_id_back']) ? 'required' : '' ?>>
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