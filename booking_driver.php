<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

// Store booking data from previous form (book_car.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pickup_date']) && isset($_POST['pickup_time'])) {
    $pickup_date = $_POST['pickup_date'] ?? '';
    $pickup_time = $_POST['pickup_time'] ?? '';
    $return_date = $_POST['return_date'] ?? '';
    $return_time = $_POST['return_time'] ?? '';
    $pickup_datetime = $pickup_date . ' ' . $pickup_time . ':00';
    $return_datetime = $return_date . ' ' . $return_time . ':00';

    // Compute notes for review page compatibility (delivery/return locations)
    $delivery_type_post = $_POST['delivery_type'] ?? '';
    $notes = '';
    if ($delivery_type_post === 'delivery' || $delivery_type_post === 'pickup_and_return') {
        $delivery_loc = trim($_POST['location_delivery'] ?? '');
        $return_loc = trim($_POST['location_return'] ?? '');
        if ($delivery_loc !== '') {
            $notes = $delivery_loc;
        }
        if ($delivery_type_post === 'pickup_and_return' && $return_loc !== '') {
            $notes .= ($notes !== '' ? ' | ' : '') . 'Return: ' . $return_loc;
        }
    }

    $_SESSION['booking_data'] = [
        'car_id'            => $_POST['car_id'] ?? '',
        'pickup_datetime'   => $pickup_datetime,
        'return_datetime'   => $return_datetime,
        'delivery_type'     => $_POST['delivery_type'] ?? '',
        'location_delivery' => $_POST['location_delivery'] ?? '',
        'location_return'   => $_POST['location_return'] ?? '',
        'notes'             => $notes,
    ];
}

// Handle customer-as-driver form submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_full_name'])) {
    $errors = [];
    $full_name = trim($_POST['customer_full_name']);
    $phone_no = trim($_POST['customer_phone_no']);
    $id_no = trim($_POST['customer_id_no']);
    $address = trim($_POST['customer_address']);
    $age = trim($_POST['customer_age']);

    // Fetch customer image info for comparison
    $cust_id = $_SESSION['cust_id'];
    $stmt = $conn->prepare("SELECT id_front_image, id_back_image, license_front_image, license_back_image FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $cust_id);
    $stmt->execute();
    $stmt->bind_result($c_id_front_image, $c_id_back_image, $c_license_front_image, $c_license_back_image);
    $stmt->fetch();
    $stmt->close();

    // Validate fields
    if ($full_name === '') $errors[] = "Full Name is required.";
    if ($phone_no === '' || !preg_match('/^01[0-9]-\d{7,8}$/', $phone_no)) $errors[] = "Phone Number is required and must be in the format 01X-XXXXXXX or 01X-XXXXXXXX.";
    if ($id_no === '' || !preg_match('/^\d{6}-\d{2}-\d{4}$/', $id_no)) $errors[] = "ID Number is required and must be in the format XXXXXX-XX-XXXX.";
    if ($address === '') $errors[] = "Address is required.";
    if ($age === '' || !is_numeric($age) || $age < 18) $errors[] = "Valid Age (18+) is required.";

    // Only require upload if no image exists (either session or database)
    if (
        (empty($_FILES['customer_id_front']) || $_FILES['customer_id_front']['error'] !== UPLOAD_ERR_OK)
        && empty($_SESSION['customer_data']['id_front'])
        && empty($c_id_front_image)
    ) {
        $errors[] = "ID Front Image is required.";
    }
    if (
        (empty($_FILES['customer_id_back']) || $_FILES['customer_id_back']['error'] !== UPLOAD_ERR_OK)
        && empty($_SESSION['customer_data']['id_back'])
        && empty($c_id_back_image)
    ) {
        $errors[] = "ID Back Image is required.";
    }
    if (
        (empty($_FILES['customer_license_front']) || $_FILES['customer_license_front']['error'] !== UPLOAD_ERR_OK)
        && empty($_SESSION['customer_data']['license_front'])
        && empty($c_license_front_image)
    ) {
        $errors[] = "License Front Image is required.";
    }
    if (
        (empty($_FILES['customer_license_back']) || $_FILES['customer_license_back']['error'] !== UPLOAD_ERR_OK)
        && empty($_SESSION['customer_data']['license_back'])
        && empty($c_license_back_image)
    ) {
        $errors[] = "License Back Image is required.";
    }

    // Save form values to session so user doesn't have to retype on error or back
    $_SESSION['customer_data']['full_name']   = $full_name;
    $_SESSION['customer_data']['phone_no']    = $phone_no;
    $_SESSION['customer_data']['id_no']       = $id_no;
    $_SESSION['customer_data']['address']     = $address;
    $_SESSION['customer_data']['age']         = $age;

    // Handle file uploads (save temp file paths in session)
    if (empty($errors)) {
        // For ID Front
        if (!empty($_FILES['customer_id_front']) && $_FILES['customer_id_front']['error'] === UPLOAD_ERR_OK) {
            $front_tmp = $_FILES['customer_id_front']['tmp_name'];
            $front_name = uniqid('c_idfront_') . '_' . basename($_FILES['customer_id_front']['name']);
            $front_dest = sys_get_temp_dir() . '/' . $front_name;
            move_uploaded_file($front_tmp, $front_dest);
            $_SESSION['customer_data']['id_front'] = $front_dest;
        }
        // For ID Back
        if (!empty($_FILES['customer_id_back']) && $_FILES['customer_id_back']['error'] === UPLOAD_ERR_OK) {
            $back_tmp = $_FILES['customer_id_back']['tmp_name'];
            $back_name = uniqid('c_idback_') . '_' . basename($_FILES['customer_id_back']['name']);
            $back_dest = sys_get_temp_dir() . '/' . $back_name;
            move_uploaded_file($back_tmp, $back_dest);
            $_SESSION['customer_data']['id_back'] = $back_dest;
        }
        // For License Front
        if (!empty($_FILES['customer_license_front']) && $_FILES['customer_license_front']['error'] === UPLOAD_ERR_OK) {
            $license_front_tmp = $_FILES['customer_license_front']['tmp_name'];
            $license_front_name = uniqid('c_licensefront_') . '_' . basename($_FILES['customer_license_front']['name']);
            $license_front_dest = sys_get_temp_dir() . '/' . $license_front_name;
            move_uploaded_file($license_front_tmp, $license_front_dest);
            $_SESSION['customer_data']['license_front'] = $license_front_dest;
        }
        // For License Back
        if (!empty($_FILES['customer_license_back']) && $_FILES['customer_license_back']['error'] === UPLOAD_ERR_OK) {
            $license_back_tmp = $_FILES['customer_license_back']['tmp_name'];
            $license_back_name = uniqid('c_licenseback_') . '_' . basename($_FILES['customer_license_back']['name']);
            $license_back_dest = sys_get_temp_dir() . '/' . $license_back_name;
            move_uploaded_file($license_back_tmp, $license_back_dest);
            $_SESSION['customer_data']['license_back'] = $license_back_dest;
        }
        // Fallbacks for images if not set in session
        if (empty($_SESSION['customer_data']['id_front'])) $_SESSION['customer_data']['id_front'] = $c_id_front_image;
        if (empty($_SESSION['customer_data']['id_back'])) $_SESSION['customer_data']['id_back'] = $c_id_back_image;
        if (empty($_SESSION['customer_data']['license_front'])) $_SESSION['customer_data']['license_front'] = $c_license_front_image;
        if (empty($_SESSION['customer_data']['license_back'])) $_SESSION['customer_data']['license_back'] = $c_license_back_image;
    }

    // Mark as "driver complete"
    if (empty($errors)) {
        // Mirror data into driver_data for downstream pages
        $_SESSION['driver_data'] = [
            'full_name'     => $_SESSION['customer_data']['full_name'] ?? '',
            'phone_no'      => $_SESSION['customer_data']['phone_no'] ?? '',
            'id_no'         => $_SESSION['customer_data']['id_no'] ?? '',
            'license_no'    => $_SESSION['customer_data']['license_no'] ?? '',
            'address'       => $_SESSION['customer_data']['address'] ?? '',
            'age'           => $_SESSION['customer_data']['age'] ?? '',
            'id_front'      => $_SESSION['customer_data']['id_front'] ?? '',
            'id_back'       => $_SESSION['customer_data']['id_back'] ?? '',
            'license_front' => $_SESSION['customer_data']['license_front'] ?? '',
            'license_back'  => $_SESSION['customer_data']['license_back'] ?? '',
        ];

        $_SESSION['customer_driver_complete'] = true;
        header("Location: booking_guarantor.php");
        exit;
    } else {
        $_SESSION['customer_driver_errors'] = $errors;
        header("Location: booking_driver.php");
        exit;
    }
}

// Errors, if any
$errors = $_SESSION['customer_driver_errors'] ?? [];
unset($_SESSION['customer_driver_errors']);

// Prefill from session if data exists
$customer_data = $_SESSION['customer_data'] ?? [];

// Fetch customer data (for autofill only)
$cust_id = $_SESSION['cust_id'];
$stmt = $conn->prepare("SELECT full_name, phone_no, id_no, address, age, id_front_image, id_back_image, license_front_image, license_back_image FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($c_full_name, $c_phone_no, $c_id_no, $c_address, $c_age, $c_id_front_image, $c_id_back_image, $c_license_front_image, $c_license_back_image);
$stmt->fetch();
$stmt->close();

$car_id = $_SESSION['booking_data']['car_id'] ?? '';

// For editable form prefill
$full_name = $customer_data['full_name'] ?? $c_full_name ?? '';
$phone_no = $customer_data['phone_no'] ?? $c_phone_no ?? '';
$id_no = $customer_data['id_no'] ?? $c_id_no ?? '';
$address = $customer_data['address'] ?? $c_address ?? '';
$age = $customer_data['age'] ?? $c_age ?? '';
$id_front_image = $customer_data['id_front'] ?? $c_id_front_image;
$id_back_image = $customer_data['id_back'] ?? $c_id_back_image;
$license_front_image = $customer_data['license_front'] ?? $c_license_front_image;
$license_back_image = $customer_data['license_back'] ?? $c_license_back_image;

// Helper for image src
function getImageSrc($image, $type, $cust_id) {
    if (!$image) return '';
    if (strpos($image, sys_get_temp_dir()) === 0) {
        // Temp uploaded image: use local file
        return $image;
    }
    // Otherwise, treat as DB image and use get_id_image.php
    return "get_id_image.php?type=$type&cust_id=$cust_id";
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
    // Phone number auto-format
    const phoneInput = document.getElementById('customer_phone_no');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 3) value = value.slice(0, 3) + '-' + value.slice(3, 11);
            this.value = value.slice(0, 12);
        });
    }
    // IC auto-format
    const icInput = document.getElementById('customer_id_no');
    if (icInput) {
        icInput.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 6) value = value.slice(0, 6) + '-' + value.slice(6);
            if (value.length > 9) value = value.slice(0, 9) + '-' + value.slice(9, 13);
            this.value = value.slice(0, 14);
        });
    }
    // IC => Age auto-calc
    const ageInput = document.getElementById('customer_age');
    if (icInput && ageInput) {
        icInput.addEventListener('input', function() {
            const icNo = icInput.value.replace(/[^\d]/g, '');
            if (icNo.length === 12) {
                const y = parseInt(icNo.substring(0,2), 10);
                const m = parseInt(icNo.substring(2,4), 10);
                const d = parseInt(icNo.substring(4,6), 10);
                const currentYear = new Date().getFullYear() % 100;
                let fullYear = y + (y > currentYear ? 1900 : 2000);
                const birthDate = new Date(fullYear, m - 1, d);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const mDiff = today.getMonth() - birthDate.getMonth();
                if (mDiff < 0 || (mDiff === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                if (age > 0) ageInput.value = age;
            }
        });
    }
});
</script>

<div class="form-section">
    <div class="form-title">Your Details (Customer)</div>
    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form id="customer-driver-form" action="booking_driver.php" method="POST" enctype="multipart/form-data" autocomplete="off">
        <div class="input-row">
            <label class="input-label">Full Name<span class="required-star">*</span></label>
            <input type="text" name="customer_full_name" id="customer_full_name" value="<?= htmlspecialchars($full_name) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Phone Number<span class="required-star">*</span></label>
            <input type="text" name="customer_phone_no" id="customer_phone_no" pattern="^01[0-9]-\d{7,8}$" maxlength="12" placeholder="01X-XXXXXXX" value="<?= htmlspecialchars($phone_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Number<span class="required-star">*</span></label>
            <input type="text" name="customer_id_no" id="customer_id_no" pattern="^\d{6}-\d{2}-\d{4}$" maxlength="14" placeholder="XXXXXX-XX-XXXX" value="<?= htmlspecialchars($id_no) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Address<span class="required-star">*</span></label>
            <input type="text" name="customer_address" id="customer_address" value="<?= htmlspecialchars($address) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">Age<span class="required-star">*</span></label>
            <input type="number" name="customer_age" id="customer_age" min="18" value="<?= htmlspecialchars($age) ?>" required>
        </div>
        <div class="input-row">
            <label class="input-label">ID Front Image<span class="required-star">*</span></label>
            <?php if ($id_front_image): ?>
                <img class="img-preview" src="<?= htmlspecialchars(getImageSrc($id_front_image, 'front', $cust_id)) ?>" id="preview_customer_id_front" style="margin-bottom:10px;">
            <?php endif; ?>
            <input type="file" name="customer_id_front" accept="image/*" onchange="previewImage(this, 'preview_customer_id_front')">
        </div>
        <div class="input-row">
            <label class="input-label">ID Back Image<span class="required-star">*</span></label>
            <?php if ($id_back_image): ?>
                <img class="img-preview" src="<?= htmlspecialchars(getImageSrc($id_back_image, 'back', $cust_id)) ?>" id="preview_customer_id_back" style="margin-bottom:10px;">
            <?php endif; ?>
            <input type="file" name="customer_id_back" accept="image/*" onchange="previewImage(this, 'preview_customer_id_back')">
        </div>
        <div class="input-row">
            <label class="input-label">License Front Image<span class="required-star">*</span></label>
            <?php if ($license_front_image): ?>
                <img class="img-preview" src="<?= htmlspecialchars(getImageSrc($license_front_image, 'license_front', $cust_id)) ?>" id="preview_customer_license_front" style="margin-bottom:10px;">
            <?php endif; ?>
            <input type="file" name="customer_license_front" accept="image/*" onchange="previewImage(this, 'preview_customer_license_front')">
        </div>
        <div class="input-row">
            <label class="input-label">License Back Image<span class="required-star">*</span></label>
            <?php if ($license_back_image): ?>
                <img class="img-preview" src="<?= htmlspecialchars(getImageSrc($license_back_image, 'license_back', $cust_id)) ?>" id="preview_customer_license_back" style="margin-bottom:10px;">
            <?php endif; ?>
            <input type="file" name="customer_license_back" accept="image/*" onchange="previewImage(this, 'preview_customer_license_back')">
        </div>
        <div class="btn-row">
            <a href="book_car.php?car_id=<?= htmlspecialchars($car_id) ?>" class="back-btn">Back</a>
            <button type="submit" class="next-btn">Next</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>