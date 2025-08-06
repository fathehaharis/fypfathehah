<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

// 1. Store booking data from previous form (book_car.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pickup_date']) && isset($_POST['pickup_time'])) {
    $pickup_date = $_POST['pickup_date'] ?? '';
    $pickup_time = $_POST['pickup_time'] ?? '';
    $return_date = $_POST['return_date'] ?? '';
    $return_time = $_POST['return_time'] ?? '';
    $pickup_datetime = $pickup_date . ' ' . $pickup_time . ':00';
    $return_datetime = $return_date . ' ' . $return_time . ':00';

    $_SESSION['booking_data'] = [
        'car_id'         => $_POST['car_id'] ?? '',
        'pickup_datetime'=> $pickup_datetime,
        'return_datetime'=> $return_datetime,
        'delivery_type'  => $_POST['delivery_type'] ?? '',
        'notes'          => $_POST['notes'] ?? '',
    ];
}

// Handle driver form submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['driver_full_name'])) {
    $errors = [];
    $full_name = trim($_POST['driver_full_name']);
    $phone_no = trim($_POST['driver_phone_no']);
    $id_no = trim($_POST['driver_id_no']);
    $address = trim($_POST['driver_address']);
    $age = trim($_POST['driver_age']);

    $using_own_info = isset($_POST['use_customer_info']) && $_POST['use_customer_info'] == 'yes';

    if ($full_name === '') $errors[] = "Full Name is required.";
    if ($phone_no === '' || !preg_match('/^01[0-9]-\d{7,8}$/', $phone_no)) $errors[] = "Phone Number is required and must be in the format 01X-XXXXXXX or 01X-XXXXXXXX.";
    if ($id_no === '' || !preg_match('/^\d{6}-\d{2}-\d{4}$/', $id_no)) $errors[] = "ID Number is required and must be in the format XXXXXX-XX-XXXX.";
    if (!$using_own_info) {
        if (empty($_FILES['driver_id_front']) || $_FILES['driver_id_front']['error'] !== UPLOAD_ERR_OK) $errors[] = "ID Front Image is required.";
        if (empty($_FILES['driver_id_back']) || $_FILES['driver_id_back']['error'] !== UPLOAD_ERR_OK) $errors[] = "ID Back Image is required.";
        if (empty($_FILES['driver_license_front']) || $_FILES['driver_license_front']['error'] !== UPLOAD_ERR_OK) $errors[] = "License Front Image is required.";
        if (empty($_FILES['driver_license_back']) || $_FILES['driver_license_back']['error'] !== UPLOAD_ERR_OK) $errors[] = "License Back Image is required.";
    }
    if ($address === '') $errors[] = "Address is required.";
    if ($age === '' || !is_numeric($age) || $age < 18) $errors[] = "Valid Age (18+) is required.";

    // Save form values to session so user doesn't have to retype on error or back
    $_SESSION['driver_data'] = [
        'full_name'   => $full_name,
        'phone_no'    => $phone_no,
        'id_no'       => $id_no,
        'address'     => $address,
        'age'         => $age,
        'use_customer_info' => $using_own_info ? 'yes' : 'no',
    ];

    // Handle file uploads (save temp file paths in session)
    if (empty($errors)) {
        if (!$using_own_info) {
            $front_tmp = $_FILES['driver_id_front']['tmp_name'];
            $front_name = uniqid('d_idfront_') . '_' . basename($_FILES['driver_id_front']['name']);
            $front_dest = sys_get_temp_dir() . '/' . $front_name;
            move_uploaded_file($front_tmp, $front_dest);

            $back_tmp = $_FILES['driver_id_back']['tmp_name'];
            $back_name = uniqid('d_idback_') . '_' . basename($_FILES['driver_id_back']['name']);
            $back_dest = sys_get_temp_dir() . '/' . $back_name;
            move_uploaded_file($back_tmp, $back_dest);

            $license_front_tmp = $_FILES['driver_license_front']['tmp_name'];
            $license_front_name = uniqid('d_licensefront_') . '_' . basename($_FILES['driver_license_front']['name']);
            $license_front_dest = sys_get_temp_dir() . '/' . $license_front_name;
            move_uploaded_file($license_front_tmp, $license_front_dest);

            $license_back_tmp = $_FILES['driver_license_back']['tmp_name'];
            $license_back_name = uniqid('d_licenseback_') . '_' . basename($_FILES['driver_license_back']['name']);
            $license_back_dest = sys_get_temp_dir() . '/' . $license_back_name;
            move_uploaded_file($license_back_tmp, $license_back_dest);

            $_SESSION['driver_data']['id_front'] = $front_dest;
            $_SESSION['driver_data']['id_back'] = $back_dest;
            $_SESSION['driver_data']['license_front'] = $license_front_dest;
            $_SESSION['driver_data']['license_back'] = $license_back_dest;
        }

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
$use_customer_info = $driver_data['use_customer_info'] ?? 'yes';

// Fetch customer data (for display only)
$cust_id = $_SESSION['cust_id'];
$stmt = $conn->prepare("SELECT full_name, phone_no, id_no, address, age, id_front_image, id_back_image, license_front_image, license_back_image FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($c_full_name, $c_phone_no, $c_id_no, $c_address, $c_age, $c_id_front_image, $c_id_back_image, $c_license_front_image, $c_license_back_image);
$stmt->fetch();
$stmt->close();

$car_id = $_SESSION['booking_data']['car_id'] ?? '';

// For driver form prefill
if ($use_customer_info === 'no') {
    // Prefill with previous values if available
    $full_name = $driver_data['full_name'] ?? '';
    $phone_no = $driver_data['phone_no'] ?? '';
    $id_no = $driver_data['id_no'] ?? '';
    $address = $driver_data['address'] ?? '';
    $age = $driver_data['age'] ?? '';
} else {
    // Use customer data
    $full_name = $c_full_name ?? '';
    $phone_no = $c_phone_no ?? '';
    $id_no = $c_id_no ?? '';
    $address = $c_address ?? '';
    $age = $c_age ?? '';
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.form-section {
    max-width: 650px;
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
    margin-bottom: 18px;
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
.toggle-row {
    margin-bottom: 18px;
    text-align: left;
}
.toggle-btn {
    appearance: none;
    border: 1px solid #3c4cb8;
    background: #fff;
    color: #3c4cb8;
    padding: 8px 20px;
    border-radius: 7px;
    font-weight: bold;
    cursor: pointer;
    margin-right: 10px;
    transition: background 0.18s, color 0.18s;
}
.toggle-btn.selected,
.toggle-btn:checked {
    background: #3c4cb8;
    color: #fff;
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
.img-label {
    color: #888;
    font-size: 0.98em;
    margin-bottom: 3px;
    display: block;
}
</style>
<script>
function calculateAgeFromIC(icNo) {
    if (!icNo || icNo.length < 6) return '';
    // Get YYMMDD
    const y = parseInt(icNo.substring(0,2), 10);
    const m = parseInt(icNo.substring(2,4), 10);
    const d = parseInt(icNo.substring(4,6), 10);
    if(isNaN(y) || isNaN(m) || isNaN(d)) return '';

    // Assume 1900s or 2000s
    const currentYear = new Date().getFullYear() % 100;
    let fullYear = y + (y > currentYear ? 1900 : 2000);
    const birthDate = new Date(fullYear, m - 1, d);

    // Calculate age
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const mDiff = today.getMonth() - birthDate.getMonth();
    if (mDiff < 0 || (mDiff === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }
    return age;
}

// Image preview for file inputs
function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = '';
        preview.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Phone number auto-format: 01X-XXXXXXX or 01X-XXXXXXXX
    const phoneInput = document.querySelector('input[name="driver_phone_no"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 3) value = value.slice(0, 3) + '-' + value.slice(3, 11);
            this.value = value.slice(0, 12);
        });
    }

    // IC auto-format: XXXXXX-XX-XXXX
    const icInput = document.querySelector('input[name="driver_id_no"]');
    if (icInput) {
        icInput.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 6) value = value.slice(0, 6) + '-' + value.slice(6);
            if (value.length > 9) value = value.slice(0, 9) + '-' + value.slice(9, 13);
            this.value = value.slice(0, 14);
        });
    }

    // IC => Age auto-calc
    const ageInput = document.querySelector('input[name="driver_age"]');
    if (icInput && ageInput) {
        icInput.addEventListener('input', function() {
            const age = calculateAgeFromIC(icInput.value);
            if (age && age > 0) {
                ageInput.value = age;
            }
        });
    }

    // Toggle logic
    const useOwnBtn = document.getElementById('use-own-info-btn');
    const useOtherBtn = document.getElementById('use-other-info-btn');
    const customerSection = document.getElementById('customer-image-section');
    const driverFormFields = document.querySelectorAll('.driver-field, .driver-upload-row, .license-upload-row');
    function updateToggle() {
        if (useOwnBtn.checked) {
            useOwnBtn.classList.add('selected');
            useOtherBtn.classList.remove('selected');
            customerSection.style.display = '';
            driverFormFields.forEach(row => row.style.display = 'none');
        } else {
            useOwnBtn.classList.remove('selected');
            useOtherBtn.classList.add('selected');
            customerSection.style.display = 'none';
            driverFormFields.forEach(row => row.style.display = '');
            // Do NOT clear driver form fields here! Let PHP prefill with session data.
            // Clear previews as before
            const previewIds = [
                'preview_driver_id_front',
                'preview_driver_id_back',
                'preview_driver_license_front',
                'preview_driver_license_back'
            ];
            previewIds.forEach(function(id){
                let img = document.getElementById(id);
                if(img){
                    img.src = '';
                    img.style.display = 'none';
                }
            });
        }
    }
    useOwnBtn.addEventListener('change', updateToggle);
    useOtherBtn.addEventListener('change', updateToggle);
    updateToggle();
});
</script>

<div class="form-section">
    <div class="form-title">Driver's Details</div>
    <div class="toggle-row">
        <label>
            <input type="radio" id="use-own-info-btn" name="use_customer_info" value="yes" <?= $use_customer_info === 'yes' ? 'checked' : '' ?> form="driver-form" class="toggle-btn">
            Use my own information (as customer)
        </label>
        <label style="margin-left:22px;">
            <input type="radio" id="use-other-info-btn" name="use_customer_info" value="no" <?= $use_customer_info === 'no' ? 'checked' : '' ?> form="driver-form" class="toggle-btn">
            Enter another driver's details
        </label>
    </div>
    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Customer image section (display only, hidden if entering another driver) -->
    <div id="customer-image-section" style="<?= $use_customer_info === 'no' ? 'display:none;' : '' ?>">
        <div class="input-row">
            <span class="img-label">ID Front Image</span>
            <?php if (!empty($c_id_front_image)): ?>
                <img class="img-preview" src="get_id_image.php?type=front&cust_id=<?= $cust_id ?>" alt="ID Front">
            <?php else: ?>
                <span class="img-label">No image uploaded</span>
            <?php endif; ?>
        </div>
        <div class="input-row">
            <span class="img-label">ID Back Image</span>
            <?php if (!empty($c_id_back_image)): ?>
                <img class="img-preview" src="get_id_image.php?type=back&cust_id=<?= $cust_id ?>" alt="ID Back">
            <?php else: ?>
                <span class="img-label">No image uploaded</span>
            <?php endif; ?>
        </div>
        <div class="input-row">
            <span class="img-label">License Front Image</span>
            <?php if (!empty($c_license_front_image)): ?>
                <img class="img-preview" src="get_id_image.php?type=license_front&cust_id=<?= $cust_id ?>" alt="License Front">
            <?php else: ?>
                <span class="img-label">No image uploaded</span>
            <?php endif; ?>
        </div>
        <div class="input-row">
            <span class="img-label">License Back Image</span>
            <?php if (!empty($c_license_back_image)): ?>
                <img class="img-preview" src="get_id_image.php?type=license_back&cust_id=<?= $cust_id ?>" alt="License Back">
            <?php else: ?>
                <span class="img-label">No image uploaded</span>
            <?php endif; ?>
        </div>
        <div class="input-row">
            <label class="input-label">Full Name</label>
            <input type="text" value="<?= htmlspecialchars($c_full_name) ?>" disabled>
        </div>
        <div class="input-row">
            <label class="input-label">Phone Number</label>
            <input type="text" value="<?= htmlspecialchars($c_phone_no) ?>" disabled>
        </div>
        <div class="input-row">
            <label class="input-label">ID Number</label>
            <input type="text" value="<?= htmlspecialchars($c_id_no) ?>" disabled>
        </div>
        <div class="input-row">
            <label class="input-label">Address</label>
            <input type="text" value="<?= htmlspecialchars($c_address) ?>" disabled>
        </div>
        <div class="input-row">
            <label class="input-label">Age</label>
            <input type="number" value="<?= htmlspecialchars($c_age) ?>" disabled>
        </div>
    </div>

    <form id="driver-form" action="booking_driver.php" method="POST" enctype="multipart/form-data" autocomplete="off">
        <div class="input-row driver-field" style="<?= $use_customer_info === 'yes' ? 'display:none;' : '' ?>">
            <label class="input-label">Full Name<span class="required-star">*</span></label>
            <input type="text" name="driver_full_name" value="<?= $use_customer_info === 'no' ? htmlspecialchars($full_name) : '' ?>" required>
        </div>
        <div class="input-row driver-field" style="<?= $use_customer_info === 'yes' ? 'display:none;' : '' ?>">
            <label class="input-label">Phone Number<span class="required-star">*</span></label>
            <input type="text" name="driver_phone_no" pattern="^01[0-9]-\d{7,8}$" maxlength="12" placeholder="01X-XXXXXXX" value="<?= $use_customer_info === 'no' ? htmlspecialchars($phone_no) : '' ?>" required>
        </div>
        <div class="input-row driver-field" style="<?= $use_customer_info === 'yes' ? 'display:none;' : '' ?>">
            <label class="input-label">ID Number<span class="required-star">*</span></label>
            <input type="text" name="driver_id_no" pattern="^\d{6}-\d{2}-\d{4}$" maxlength="14" placeholder="XXXXXX-XX-XXXX" value="<?= $use_customer_info === 'no' ? htmlspecialchars($id_no) : '' ?>" required>
        </div>
        <div class="input-row driver-upload-row" style="display:none;">
            <label class="input-label">ID Front Image<span class="required-star">*</span></label>
            <input type="file" name="driver_id_front" accept="image/*" onchange="previewImage(this, 'preview_driver_id_front')">
            <img id="preview_driver_id_front" class="img-preview" style="display:none;">
        </div>
        <div class="input-row driver-upload-row" style="display:none;">
            <label class="input-label">ID Back Image<span class="required-star">*</span></label>
            <input type="file" name="driver_id_back" accept="image/*" onchange="previewImage(this, 'preview_driver_id_back')">
            <img id="preview_driver_id_back" class="img-preview" style="display:none;">
        </div>
        <div class="input-row license-upload-row" style="display:none;">
            <label class="input-label">License Front Image<span class="required-star">*</span></label>
            <input type="file" name="driver_license_front" accept="image/*" onchange="previewImage(this, 'preview_driver_license_front')">
            <img id="preview_driver_license_front" class="img-preview" style="display:none;">
        </div>
        <div class="input-row license-upload-row" style="display:none;">
            <label class="input-label">License Back Image<span class="required-star">*</span></label>
            <input type="file" name="driver_license_back" accept="image/*" onchange="previewImage(this, 'preview_driver_license_back')">
            <img id="preview_driver_license_back" class="img-preview" style="display:none;">
        </div>
        <div class="input-row driver-field" style="<?= $use_customer_info === 'yes' ? 'display:none;' : '' ?>">
            <label class="input-label">Address<span class="required-star">*</span></label>
            <input type="text" name="driver_address" value="<?= $use_customer_info === 'no' ? htmlspecialchars($address) : '' ?>" required>
        </div>
        <div class="input-row driver-field" style="<?= $use_customer_info === 'yes' ? 'display:none;' : '' ?>">
            <label class="input-label">Age<span class="required-star">*</span></label>
            <input type="number" name="driver_age" min="18" value="<?= $use_customer_info === 'no' ? htmlspecialchars($age) : '' ?>" required>
        </div>
        <div class="btn-row">
            <a href="book_car.php?car_id=<?= htmlspecialchars($car_id) ?>" class="back-btn">Back</a>
            <button type="submit" class="next-btn">Next</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>