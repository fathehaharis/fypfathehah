<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$type = isset($_GET['type']) && in_array(strtolower($_GET['type']), ['pickup', 'return']) ? strtolower($_GET['type']) : 'pickup';

if (!$booking_id) {
    echo "<p>Invalid booking ID.</p>";
    include '../includes/footer.php';
    exit;
}

// Fetch car info for display and mileage
$stmt = $conn->prepare("
    SELECT b.*, c.car_brand, c.car_model, c.plate_no, c.mileage as car_mileage, c.car_id
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    WHERE b.booking_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo "<p>Booking not found.</p>";
    include '../includes/footer.php';
    exit;
}

$current_car_mileage = (int)$booking['car_mileage'];
$car_id = (int)$booking['car_id'];

// Fetch inspection images for this type (pickup/return)
$stmt = $conn->prepare("SELECT * FROM booking_image WHERE booking_id = ? AND capture_type = ?");
$stmt->bind_param("is", $booking_id, $type);
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$is_filled = false;
if ($type === 'pickup') {
    $is_filled = !empty($booking['pickup_mileage']) && !empty($booking['pickup_fuel_percent']) && !empty($booking['pickup_datetime']) && count($images) > 0;
} else {
    $is_filled = !empty($booking['return_mileage']) && !empty($booking['return_fuel_percent']) && !empty($booking['return_datetime']) && count($images) > 0;
}

// Handle form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Mileage, Fuel, Date ---
    $mileage = intval($_POST['mileage'] ?? 0);
    $fuel_percent = intval($_POST['fuel_percent'] ?? 0);
    $inspection_date = $_POST['inspection_date'] ?? date('Y-m-d H:i:s');
    $remarks = trim($_POST['remarks'] ?? '');

    if (!empty($inspection_date)) {
        // Convert from HTML datetime-local to Y-m-d H:i:s
        $inspection_date_sql = str_replace('T', ' ', $inspection_date);
    } else {
        $inspection_date_sql = date('Y-m-d H:i:s');
    }

    if ($type == 'pickup') {
        $booking_update_sql = "UPDATE booking SET pickup_mileage=?, pickup_fuel_percent=?, pickup_datetime=? WHERE booking_id=?";
    } else {
        $booking_update_sql = "UPDATE booking SET return_mileage=?, return_fuel_percent=?, return_datetime=? WHERE booking_id=?";
    }
    $stmt = $conn->prepare($booking_update_sql);
    $stmt->bind_param('iisi', $mileage, $fuel_percent, $inspection_date_sql, $booking_id);
    $stmt->execute();
    $stmt->close();

    // Update car mileage as well
    $stmt = $conn->prepare("UPDATE car SET mileage=? WHERE car_id=?");
    $stmt->bind_param('ii', $mileage, $car_id);
    $stmt->execute();
    $stmt->close();

    // --- Image Uploads ---
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    $image_types = [
        'car_front',
        'car_back',
        'car_left',
        'car_right',
        'fuel_image',
        'additional_image'
    ];
    $uploaded = 0;
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['name'] as $key => $name) {
            $tmp_name = $_FILES['images']['tmp_name'][$key];
            $type_img = $_FILES['images']['type'][$key];
            $error = $_FILES['images']['error'][$key];
            $image_type = $_POST['image_type'][$key] ?? '';

            if ($error == 0 && in_array($type_img, $allowed_types) && in_array($image_type, $image_types)) {
                $img_content = file_get_contents($tmp_name);
                $image_blob = $img_content;

                // Only add remarks to the first image (if any provided)
                $insert_remarks = ($uploaded == 0) ? $remarks : "";

                $stmt = $conn->prepare(
                    "INSERT INTO booking_image (booking_id, image_path, image_type, capture_type, inspection_date, uploaded_at, remarks)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)"
                );
                $stmt->bind_param(
                    "ibssss",
                    $booking_id,
                    $image_blob,
                    $image_type,
                    $type,
                    $inspection_date_sql,
                    $insert_remarks
                );
                $stmt->send_long_data(1, $image_blob);
                $stmt->execute();
                $stmt->close();
                $uploaded++;
            } else {
                $errors[] = "Invalid file type or selection for image #" . ($key + 1);
            }
        }
    }

    if (!$errors) {
        $_SESSION['success'] = ucfirst($type) . " inspection data saved successfully.";
        header("Location: inspection_add.php?booking_id=" . $booking_id . "&type=" . $type);
        exit;
    }
}

include 'admin_header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background: #f8f9fb; }
.inspection-card {
    max-width: 680px;
    margin: 38px auto 32px auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 6px 28px #d3d8ef44;
    padding: 36px 42px 32px 42px;
    border: 1px solid #f1f2f8;
}
.inspection-title {
    font-size: 1.25em;
    font-weight: 800;
    color: #2d397c;
    margin-bottom: 16px;
    letter-spacing: 0.5px;
}
.inspection-label {
    font-weight: 600;
    color: #364269;
    margin-bottom: 6px;
    display: block;
    font-size: 1.07em;
}
.inspection-fields-row {
    display: flex;
    flex-wrap: wrap;
    gap: 19px;
    margin-bottom: 17px;
    align-items: flex-end;
}
.inspection-field {
    flex: 1 1 130px;
    min-width: 120px;
    display: flex;
    flex-direction: column;
}
.inspection-field input,
.inspection-field select,
.inspection-field textarea {
    border: 1.5px solid #d6d9e4;
    border-radius: 7px;
    padding: 7px 12px;
    font-size: 1.07em;
    background: #f8fafe;
    margin-bottom: 0;
}
.inspection-field textarea { min-height: 36px; resize: vertical; }
.imageFields-container { margin-bottom: 24px; }
.add-more-btn {
    background: #f5f7fe;
    color: #2b5cbc;
    border: 1.5px dashed #b5bee5;
    border-radius: 8px;
    padding: 8px 22px;
    font-weight: 600;
    font-size: 1.03em;
    margin-top: 7px;
    margin-bottom: 6px;
    cursor: pointer;
    transition: background 0.13s, border 0.13s;
}
.add-more-btn:hover { background: #eaf1ff; border-color: #6d8be6; }
.remove-image-btn {
    background: #f8d6d6;
    color: #c00;
    border: none;
    font-size: 1.25em;
    font-weight: bold;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    align-self: flex-start;
    margin-left: 12px;
    margin-top: 16px;
    cursor: pointer;
    transition: background 0.14s;
    display: flex;
    justify-content: center;
    align-items: center;
}
.remove-image-btn:hover { background: #f55959; color: #fff; }
.imageField { align-items: flex-end; }
.submit-btn {
    background: linear-gradient(90deg, #4158d0 0%, #6d8be6 100%);
    color: #fff;
    border: none;
    padding: 13px 48px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1.13em;
    margin-top: 18px;
    box-shadow: 0 2px 10px #b5bee550;
    cursor: pointer;
    transition: background 0.18s;
    display: block;
    margin-left: auto;
}
.submit-btn:hover { background: linear-gradient(90deg, #2b5cbc 0%, #4158d0 100%); }
.error-messages, .success-messages {
    border-radius: 7px;
    padding: 10px 18px;
    margin-bottom: 17px;
    font-weight: 500;
    font-size: 1.08em;
}
.error-messages { background: #fdeaea; color: #b22; }
.success-messages { background: #eafdeb; color: #218c3b; }
@media (max-width: 800px) {
    .inspection-card { padding: 16px 5vw; }
    .inspection-fields-row { flex-direction: column; gap: 8px; }
}
/* Details view */
.details-label { font-weight:700; color:#2d397c; }
.details-value { margin-bottom: 10px; }
.pickup-images-row { display:flex; gap:16px; flex-wrap:wrap; margin-top: 18px; }
.pickup-image-box {
    border:1.5px solid #d6d9e4;
    border-radius:8px;
    padding:12px 14px;
    background:#f8faff;
    text-align:center;
    min-width:110px;
    max-width:110px;
}
.pickup-image-label { font-size:0.97em; color:#444; margin-top:8px; }
</style>
<div class="inspection-card">
    <div class="inspection-title">
        <?= ucfirst($type) ?> Inspection for <?= htmlspecialchars($booking['car_brand'] . ' ' . $booking['car_model']) ?> (<?= htmlspecialchars($booking['plate_no']) ?>)
    </div>
    <?php if (!empty($errors)): ?>
        <div class="error-messages">
            <?php foreach ($errors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($is_filled): ?>
        <!-- DETAILS VIEW (Show only) -->
        <div>
            <div class="details-label">Mileage (km):</div>
            <div class="details-value"><?= htmlspecialchars($type === 'pickup' ? $booking['pickup_mileage'] : $booking['return_mileage']) ?></div>
            <div class="details-label">Fuel Percent (%):</div>
            <div class="details-value"><?= htmlspecialchars($type === 'pickup' ? $booking['pickup_fuel_percent'] : $booking['return_fuel_percent']) ?></div>
            <div class="details-label">Inspection Date:</div>
            <div class="details-value"><?= htmlspecialchars($type === 'pickup' ? $booking['pickup_datetime'] : $booking['return_datetime']) ?></div>
            <?php
                $first_remark = '';
                foreach ($images as $img) {
                    if (!empty($img['remarks'])) {
                        $first_remark = $img['remarks'];
                        break;
                    }
                }
            ?>
            <?php if($first_remark): ?>
                <div class="details-label">Remarks:</div>
                <div class="details-value"><?= nl2br(htmlspecialchars($first_remark)) ?></div>
            <?php endif; ?>
            <div class="details-label" style="margin-top:18px;">Booking Images (Car Condition / Documents)</div>
            <div class="pickup-images-row">
                <?php foreach ($images as $img): ?>
                    <div class="pickup-image-box">
                        <img src="data:image/jpeg;base64,<?= base64_encode($img['image_path']) ?>" alt="Booking Image" style="width:85px;height:65px;object-fit:cover;border-radius:5px;border:1px solid #dfe4ee;background:#f8fafd;">
                        <div style="font-size:1.1em; color:#4d5990; margin-bottom:6px;">Booking Image</div>
                        <div class="pickup-image-label"><?= htmlspecialchars($img['image_type']) ?><br><?= htmlspecialchars($img['capture_type']) ?></div>
                        <?php if(!empty($img['remarks'])): ?>
                            <div style="font-size:0.95em; color:#42a161; margin-top:2px;">
                                <?= htmlspecialchars($img['remarks']) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size:0.9em; color:#889;"> <?= htmlspecialchars($img['uploaded_at']) ?> </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- FORM (inspection date only in main row) -->
        <form method="post" enctype="multipart/form-data" class="inspection-form" id="inspectionForm" autocomplete="off">
            <div class="inspection-fields-row">
                <div class="inspection-field">
                    <label class="inspection-label">Mileage (km)</label>
                    <input type="number" name="mileage" min="0" value="<?= htmlspecialchars($current_car_mileage) ?>" required>
                </div>
                <div class="inspection-field">
                    <label class="inspection-label">Fuel Percent (%)</label>
                    <select name="fuel_percent" required>
                        <option value="">Select</option>
                        <option value="20">20%</option>
                        <option value="30">30%</option>
                        <option value="40">40%</option>
                        <option value="60">60%</option>
                        <option value="70">70%</option>
                        <option value="80">80%</option>
                        <option value="90">90%</option>
                        <option value="100">100%</option>
                    </select>
                </div>
                <div class="inspection-field">
                    <label class="inspection-label">Inspection Date</label>
                    <input type="datetime-local" name="inspection_date" value="<?= date('Y-m-d\TH:i') ?>" required>
                </div>
            </div>
            <div class="imageFields-container" id="imageFields">
                <div class="inspection-fields-row imageField">
                    <div class="inspection-field" style="flex:1.5">
                        <label class="inspection-label">Inspection Image</label>
                        <input type="file" name="images[]" accept="image/*" required>
                    </div>
                    <div class="inspection-field">
                        <label class="inspection-label">Image Type</label>
                        <select name="image_type[]">
                            <option value="car_front">Car Front</option>
                            <option value="car_back">Car Back</option>
                            <option value="car_left">Car Left Side</option>
                            <option value="car_right">Car Right Side</option>
                            <option value="fuel_image">Fuel Image</option>
                            <option value="additional_image">Additional Image</option>
                        </select>
                    </div>
                    <button type="button" class="remove-image-btn" onclick="removeImageField(this)" title="Remove this image">&times;</button>
                </div>
            </div>
            <button type="button" class="add-more-btn" onclick="addImageField()">+ Add More Images</button>
            <div class="inspection-field" style="margin-top:18px;">
                <label class="inspection-label">Remarks <span style="font-weight:400;color:#aaa;">(optional, add once only)</span></label>
                <textarea name="remarks" placeholder="Describe damage, condition, etc." rows="3"></textarea>
            </div>
            <button type="submit" class="submit-btn">Submit Inspection</button>
        </form>
    <?php endif; ?>
</div>
<script>
function addImageField() {
    var container = document.createElement('div');
    container.className = "inspection-fields-row imageField";
    container.innerHTML = `
        <div class="inspection-field" style="flex:1.5">
            <label class="inspection-label">Inspection Image</label>
            <input type="file" name="images[]" accept="image/*" required>
        </div>
        <div class="inspection-field">
            <label class="inspection-label">Image Type</label>
            <select name="image_type[]">
                <option value="car_front">Car Front</option>
                <option value="car_back">Car Back</option>
                <option value="car_left">Car Left Side</option>
                <option value="car_right">Car Right Side</option>
                <option value="fuel_image">Fuel Image</option>
                <option value="additional_image">Additional Image</option>
            </select>
        </div>
        <button type="button" class="remove-image-btn" onclick="removeImageField(this)" title="Remove this image">&times;</button>
    `;
    document.getElementById('imageFields').appendChild(container);
}
function removeImageField(btn) {
    var field = btn.closest('.imageField');
    if (document.querySelectorAll('.imageField').length > 1) {
        field.parentNode.removeChild(field);
    }
}
</script>
<?php include '../includes/footer.php'; ?>