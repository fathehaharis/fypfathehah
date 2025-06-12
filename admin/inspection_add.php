<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$type = $_GET['type'] ?? 'pickup'; // 'pickup' or 'return'

if (!$booking_id || !in_array($type, ['pickup', 'return'])) {
    echo "Invalid request.";
    exit;
}

$upload_errors = [];
// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fuel_level = isset($_POST['fuel_level']) ? intval($_POST['fuel_level']) : null;
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : "";
    $date_check = isset($_POST['date_check']) ? $_POST['date_check'] : null;
    $mileage = isset($_POST['mileage']) ? intval($_POST['mileage']) : null;

    // Update fuel percent in booking
    if ($type == 'pickup') {
        $stmt = $conn->prepare("UPDATE booking SET pickup_fuel_percent = ?, pickup_mileage = ? WHERE booking_id = ?");
        $stmt->bind_param("iii", $fuel_level, $mileage, $booking_id);
        $stmt->execute();
        $stmt->close();
    } else if ($type == 'return') {
        $stmt = $conn->prepare("UPDATE booking SET return_fuel_percent = ?, return_mileage = ? WHERE booking_id = ?");
        $stmt->bind_param("iii", $fuel_level, $mileage, $booking_id);
        $stmt->execute();
        $stmt->close();
    }

    // Save photos (front, back, left, right, fuel)
    $imageFields = [
        'front_image' => 'front',
        'back_image' => 'back',
        'left_image' => 'left',
        'right_image' => 'right',
        'fuel_image' => 'fuel'
    ];

    foreach ($imageFields as $inputName => $imageType) {
        if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
                $imgData = file_get_contents($_FILES[$inputName]['tmp_name']);
                $stmt = $conn->prepare("INSERT INTO booking_image (booking_id, image_path, image_type, capture_type, uploaded_at, remarks, inspection_date) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                $captureType = $type; // 'pickup' or 'return'
                $null = NULL;
                $stmt->bind_param("ibssss", $booking_id, $null, $imageType, $captureType, $remarks, $date_check);
                $stmt->send_long_data(1, $imgData);
                $stmt->execute();
                $stmt->close();
            } else {
                $upload_errors[] = "Error uploading $imageType image.";
            }
        }
    }

    // Save additional images
    if (!empty($_FILES['additional_images']['name'][0])) {
        foreach ($_FILES['additional_images']['tmp_name'] as $i => $tmpName) {
            if ($_FILES['additional_images']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['additional_images']['error'][$i] === UPLOAD_ERR_OK) {
                    $imgData = file_get_contents($tmpName);
                    $stmt = $conn->prepare("INSERT INTO booking_image (booking_id, image_path, image_type, capture_type, uploaded_at, remarks, inspection_date) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                    $imageType = 'additional';
                    $captureType = $type;
                    $null = NULL;
                    $stmt->bind_param("ibssss", $booking_id, $null, $imageType, $captureType, $remarks, $date_check);
                    $stmt->send_long_data(1, $imgData);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $upload_errors[] = "Error uploading additional image #" . ($i+1) . ".";
                }
            }
        }
    }

    // Save signature if uploaded (optional)
    if (isset($_FILES['signature']) && $_FILES['signature']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['signature']['error'] === UPLOAD_ERR_OK) {
            $imgData = file_get_contents($_FILES['signature']['tmp_name']);
            // Example: Save to a booking_signature table, or update a signature field in booking
            // Uncomment/modify as needed:
            /*
            $stmt = $conn->prepare("UPDATE agreement_form SET cust_signature = ? WHERE booking_id = ?");
            $null = NULL;
            $stmt->bind_param("bi", $null, $booking_id);
            $stmt->send_long_data(0, $imgData);
            $stmt->execute();
            $stmt->close();
            */
        } else {
            $upload_errors[] = "Error uploading signature.";
        }
    }

    // Redirect or show success message
    if (empty($upload_errors)) {
        header("Location: inspection_success.php?booking_id=$booking_id&type=$type");
        exit;
    }
}

// Fetch booking for reference (optional)
$stmt = $conn->prepare("
    SELECT b.booking_id, c.car_brand, c.car_model, c.plate_no
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    WHERE b.booking_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch driver signature from agreement_form (as BASE64 or path, adjust as needed)
$stmt = $conn->prepare("SELECT cust_signature FROM agreement_form WHERE booking_id = ? LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$stmt->bind_result($cust_signature);
$stmt->fetch();
$stmt->close();

include 'admin_header.php';
?>
<style>
.inspection-section {
    background: #f8fafd;
    border-radius: 12px;
    padding: 22px 20px 22px 20px;
    margin-bottom: 20px;
    border: 1px solid #e6eaf1;
    max-width: 520px;
    margin-left: auto;
    margin-right: auto;
}
.inspection-photos-block {
    border-radius: 10px;
    border: 1px solid #e0e5f2;
    background: #fff;
    padding: 15px 20px 18px 20px;
    margin-bottom: 10px;
}
.inspection-photos-title {
    font-weight: 600;
    color: #5069b2;
    margin-bottom: 14px;
    font-size: 1.05em;
}
.inspection-photos-row {
    display: flex;
    gap: 18px;
    margin-bottom: 7px;
    flex-wrap: wrap;
}
.inspection-photo-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 80px;
    flex: 1;
}
.inspection-photo-label {
    font-size: 0.96em;
    color: #444;
    margin-bottom: 2px;
}
.inspection-photo-upload-btn {
    background: #e8eafd;
    color: #333;
    border: none;
    border-radius: 6px;
    font-size: 0.97em;
    font-weight: 600;
    padding: 7px 18px;
    margin-top: 2px;
    margin-bottom: 5px;
    cursor: pointer;
    transition: background 0.15s;
}
.inspection-photo-upload-btn:hover {
    background: #c3cdf3;
}
.inspection-additional-row {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-top: 5px;
}
.inspection-add-btn {
    background: #4158d0;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    padding: 7px 18px;
    font-size: .99em;
    cursor: pointer;
    transition: background 0.14s;
}
.inspection-add-btn:hover {
    background: #233c96;
}
.inspection-form-row {
    display: flex;
    gap: 14px;
    margin-top: 19px;
    margin-bottom: 10px;
}
.inspection-date-mileage {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 9px;
}
.inspection-fuel-block {
    flex: 1.2;
    background: #fff;
    border-radius: 10px;
    padding: 15px 15px 16px 15px;
    border: 1px solid #e6eaf1;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.inspection-fuel-slider {
    width: 100%;
    margin: 8px 0 3px 0;
}
.inspection-form-remarks {
    width: 100%;
    min-height: 56px;
    border-radius: 7px;
    border: 1px solid #cad6e6;
    font-size: 1.05em;
    padding: 9px 14px;
    margin-bottom: 15px;
    resize: vertical;
}
.inspection-signature-row {
    margin-top: 8px;
    display: flex;
    gap: 16px;
    align-items: center;
    margin-bottom: 13px;
}
.inspection-signature-row input[type="file"],
.inspection-signature-row button {
    margin-right: 12px;
}
.inspection-form-submit {
    background: #3c4cb8;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 12px 34px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
    margin-bottom: 4px;
}
.inspection-form-submit:hover {
    background: #233c96;
}
.inspection-photo-filename {
    font-size: 0.96em;
    color: #555;
    margin-top: 4px;
    margin-bottom: 2px;
    word-break: break-all;
}
.inspection-photo-preview {
    display: block;
    border: 1px solid #ccc;
    background: #fafafa;
    border-radius: 6px;
    margin-top: 5px;
    max-width: 80px;
    max-height: 60px;
}
@media (max-width: 700px) {
    .inspection-section { padding: 13px 2vw 8px 2vw; }
    .inspection-photo-col img { width: 66px; height: 50px; }
    .inspection-form-row { flex-direction: column; gap: 8px; }
    .inspection-fuel-block { margin-top: 8px;}
}
</style>
<div class="inspection-section">
    <?php if (!empty($upload_errors)): ?>
    <div style="color:#d22;font-size:1.01em;margin-bottom:16px;">
        <?php foreach ($upload_errors as $err) echo htmlspecialchars($err) . '<br>'; ?>
    </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <div class="inspection-photos-block">
            <div class="inspection-photos-title">Photos</div>
            <div class="inspection-photos-row">
                <?php
                $photo_fields = [
                    'front_image' => 'Front Image',
                    'back_image' => 'Back Image',
                    'left_image' => 'Left Image',
                    'right_image' => 'Right Image',
                    'fuel_image' => 'Fuel Image'
                ];
                foreach ($photo_fields as $field => $label): ?>
                <div class="inspection-photo-col">
                    <label class="inspection-photo-label" for="<?= $field ?>"><?= $label ?></label>
                    <input type="file" name="<?= $field ?>" id="<?= $field ?>" accept="image/*" style="display:none;" onchange="updatePhotoLabel(this)">
                    <button type="button" class="inspection-photo-upload-btn" onclick="document.getElementById('<?= $field ?>').click()">Upload</button>
                    <span class="inspection-photo-filename" id="<?= $field ?>_name"></span>
                    <img class="inspection-photo-preview" id="<?= $field ?>_preview" style="display:none;" />
                </div>
                <?php endforeach; ?>
            </div>
            <div class="inspection-additional-row" style="margin-top:8px;">
                <span style="font-size:0.97em;color:#888;">Additional Images</span>
                <button type="button" class="inspection-add-btn" onclick="addAdditionalImage()">+ Add new</button>
                <div id="additional-images"></div>
            </div>
        </div>
        <div class="inspection-form-row">
            <div class="inspection-date-mileage">
                <label style="font-weight:500;margin-bottom:2px;">Date Check</label>
                <input type="datetime-local" name="date_check" style="padding:8px 12px;border-radius:6px;border:1px solid #bcd;" required>
                <label style="font-weight:500;margin:13px 0 2px 0;">Mileage</label>
                <input type="number" name="mileage" class="inspection-form-remarks" style="width:100px;" placeholder="Mileage" min="0" step="1" required>
            </div>
            <div class="inspection-fuel-block">
                <div style="font-weight:500;">FUEL</div>
                <input type="range" min="0" max="100" value="0" name="fuel_level" class="inspection-fuel-slider" oninput="fuelValueOutput.value = this.value + '%'">
                <output name="fuelValueOutput" style="font-size:1.09em;margin-bottom:7px;">0%</output>
            </div>
        </div>
        <label style="font-weight:500;margin:9px 0 2px 0;">Remarks</label>
        <textarea name="remarks" class="inspection-form-remarks" placeholder="Remarks"></textarea>
        <label style="font-weight:500;margin:8px 0 2px 0;">Customer/Driver Signature</label>
        <div class="inspection-signature-row">
            <?php if (!empty($cust_signature)): ?>
                <img src="data:image/png;base64,<?= base64_encode($cust_signature) ?>" alt="Driver Signature" style="max-height:64px;max-width:200px;border:1px solid #ccc;background:#fff;border-radius:5px;margin-right:16px;">
            <?php else: ?>
                <span style="color:#d22;font-size:0.97em;margin-right:16px;">No signature found in agreement form.</span>
            <?php endif; ?>
            <input type="file" name="signature" id="signature" accept="image/*" style="display:none;" onchange="updatePhotoLabel(this)">
            <button type="button" class="inspection-photo-upload-btn" onclick="document.getElementById('signature').click()">Upload Signature</button>
            <span class="inspection-photo-filename" id="signature_name"></span>
            <img class="inspection-photo-preview" id="signature_preview" style="display:none;" />
        </div>
        <button type="submit" class="inspection-form-submit">Submit</button>
    </form>
</div>
<script>
function addAdditionalImage() {
    var div = document.createElement("div");
    var fieldId = 'additional_' + (document.querySelectorAll('#additional-images input[type="file"]').length + 1);
    div.innerHTML = '<input type="file" name="additional_images[]" id="' + fieldId + '" accept="image/*" style="margin-top:6px;" onchange="updatePhotoLabel(this)">' +
        '<span class="inspection-photo-filename" id="' + fieldId + '_name"></span>' +
        '<img class="inspection-photo-preview" id="' + fieldId + '_preview" style="display:none;" />';
    document.getElementById('additional-images').appendChild(div);
}
function updatePhotoLabel(input) {
    if (input.files && input.files[0]) {
        // Show file name
        var nameSpan = document.getElementById(input.id + "_name");
        if (nameSpan) nameSpan.textContent = input.files[0].name;
        // Show preview
        var previewImg = document.getElementById(input.id + "_preview");
        if (previewImg) {
            previewImg.src = URL.createObjectURL(input.files[0]);
            previewImg.style.display = 'block';
        }
        // Change button text (for main upload fields)
        var btn = input.nextElementSibling;
        if (btn && btn.classList.contains('inspection-photo-upload-btn')) {
            btn.textContent = "Change";
        }
    }
}
</script>
<?php include '../includes/footer.php'; ?>