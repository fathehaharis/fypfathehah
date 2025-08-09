<?php
require_once '../connect.php';
session_start();

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$errors = [];
$admin_id = (int)$_SESSION['admin_id'];

function posted($key) {
    return isset($_POST[$key]) ? trim($_POST[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_car'])) {

    $plate_no      = strtoupper(posted('plate_no'));
    $car_brand     = posted('car_brand');
    $car_model     = posted('car_model');
    $year          = (int)posted('year');
    $color         = posted('color');
    $transmission  = strtolower(posted('transmission')); // store lowercase
    $seat_capacity = (int)posted('seat_capacity');
    $mileage       = (int)posted('mileage');
    $daily_rate    = (float)posted('daily_rate');
    $status_input  = strtolower(posted('status'));
    $status        = ($status_input === 'available') ? 'available' : 'unavailable';

    // Documents (blobs)
    $grant_blob = null; $roadtax_blob = null; $covernote_blob = null;
    $docInputs = [
        'car_grant_path'     => &$grant_blob,
        'car_roadtax_path'   => &$roadtax_blob,
        'car_covernote_path' => &$covernote_blob
    ];
    foreach ($docInputs as $field => &$var) {
        if (!empty($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE) {
            $err = $_FILES[$field]['error'];
            if ($err === UPLOAD_ERR_OK) {
                if ($_FILES[$field]['size'] > 15 * 1024 * 1024) {
                    $errors[] = "$field exceeds 15MB limit.";
                } else {
                    $data = file_get_contents($_FILES[$field]['tmp_name']);
                    if ($data === false) {
                        $errors[] = "Failed reading uploaded file for $field.";
                    } else {
                        $var = $data;
                    }
                }
            } else {
                $errors[] = "$field upload error code $err.";
            }
        }
    }
    unset($var);

    // Image
    $image_blob = null;
    if (!empty($_FILES['car_image']) && $_FILES['car_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $ie = $_FILES['car_image']['error'];
        if ($ie === UPLOAD_ERR_OK) {
            if ($_FILES['car_image']['size'] > 5 * 1024 * 1024) {
                $errors[] = "Image exceeds 5MB limit.";
            } else {
                $imgData = file_get_contents($_FILES['car_image']['tmp_name']);
                if ($imgData === false) {
                    $errors[] = "Failed to read image file.";
                } else {
                    $image_blob = $imgData;
                }
            }
        } else {
            $errors[] = "Image upload error code $ie.";
        }
    }

    // Validation
    $nowY = (int)date('Y');
    if ($plate_no === '' || !preg_match('/^[A-Z0-9 ]{2,}$/', $plate_no)) $errors[] = "Plate number required (alphanumeric).";
    if ($car_brand === '') $errors[] = "Brand required.";
    if ($car_model === '') $errors[] = "Model required.";
    if ($year < 1980 || $year > $nowY + 1) $errors[] = "Year must be between 1980 and ".($nowY+1).".";
    if ($color === '') $errors[] = "Color required.";
    if (!in_array($transmission, ['automatic','manual'], true)) $errors[] = "Transmission must be Automatic or Manual.";
    if ($seat_capacity < 1 || $seat_capacity > 50) $errors[] = "Seat capacity must be 1 - 50.";
    if ($mileage < 0) $errors[] = "Mileage cannot be negative.";
    if ($daily_rate < 0) $errors[] = "Daily rate cannot be negative.";
    if (!in_array($status, ['available','unavailable'], true)) $errors[] = "Invalid status.";

    // Unique plate
    if (!$errors) {
        $stmt = $conn->prepare("SELECT car_id FROM car WHERE plate_no=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $plate_no);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dup) $errors[] = "Plate number already exists.";
        } else {
            $errors[] = "DB prepare error (plate check).";
        }
    }

    if (!$errors) {
        $sql = "INSERT INTO car
            (plate_no, car_brand, car_model, year, color, mileage,
             transmission, seat_capacity, status, daily_rate,
             car_grant_blob, car_roadtax_blob, car_covernote_blob, admin_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errors[] = "Prepare failed: ".$conn->error;
        } else {
            // Types: s s s i s i s i s d b b b i
            $types = "sssisisisdbbbi";
            $grant_param = $grant_blob;
            $roadtax_param = $roadtax_blob;
            $cover_param  = $covernote_blob;

            $stmt->bind_param(
                $types,
                $plate_no, $car_brand, $car_model, $year, $color, $mileage,
                $transmission, $seat_capacity, $status, $daily_rate,
                $grant_param, $roadtax_param, $cover_param, $admin_id
            );
            if ($grant_blob !== null)     $stmt->send_long_data(10, $grant_blob);
            if ($roadtax_blob !== null)   $stmt->send_long_data(11, $roadtax_blob);
            if ($covernote_blob !== null) $stmt->send_long_data(12, $covernote_blob);

            if ($stmt->execute()) {
                $new_car_id = $stmt->insert_id;
                $stmt->close();

                if ($image_blob !== null) {
                    $imgSql = "INSERT INTO car_image
                        (car_id, image_type, image_blob, uploaded_at, sort_order, version)
                        VALUES ( ?,'main', ?, NOW(), 0, 0 )";
                    $imgStmt = $conn->prepare($imgSql);
                    if ($imgStmt) {
                        $null = null;
                        $imgStmt->bind_param("ib", $new_car_id, $null);
                        $imgStmt->send_long_data(1, $image_blob);
                        if (!$imgStmt->execute()) {
                            $errors[] = "Image save failed: ".$imgStmt->error;
                        }
                        $imgStmt->close();
                        $conn->query("UPDATE car SET images_version=images_version+1 WHERE car_id=".$new_car_id);
                    } else {
                        $errors[] = "Image prepare failed: ".$conn->error;
                    }
                }

                if (!$errors) {
                    header("Location: car_details.php?id=".$new_car_id."&add=success");
                    exit;
                }

            } else {
                $errors[] = "Insert failed: ".$stmt->error;
                $stmt->close();
            }
        }
    }
}

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add New Car</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
.add-car-container {
    max-width: 760px;
    margin: 36px auto 50px;
    background:#fff;
    border-radius:16px;
    box-shadow:0 4px 18px -4px rgba(0,0,0,0.08);
    padding:38px 40px 30px;
}
.add-car-title {
    font-size:1.9em;
    font-weight:800;
    color:#1f3455;
    letter-spacing:.4px;
    margin:0 0 18px;
}
.add-car-form label {
    font-weight:600;
    color:#314f75;
    margin-bottom:6px;
    display:block;
    font-size:.7rem;
    letter-spacing:.5px;
    text-transform:uppercase;
}
.add-car-form input[type=text],
.add-car-form input[type=number],
.add-car-form select {
    width:100%;
    padding:9px 12px;
    margin-bottom:18px;
    border:1.2px solid #cfd8ef;
    border-radius:9px;
    font-size:.95em;
    background:#f8fbfe;
}
.add-car-form input[type=file] {
    margin-bottom:14px;
    font-size:.85em;
}
.add-car-form button {
    background:#304cc3;
    color:#fff;
    border:none;
    border-radius:10px;
    padding:12px 26px;
    font-size:.95em;
    font-weight:600;
    cursor:pointer;
    box-shadow:0 2px 6px rgba(48,76,195,0.20);
}
.add-car-form button:hover { background:#1f358f; }
.btn-row {
    display:flex;
    gap:14px;
    margin-top:6px;
    flex-wrap:wrap;
}
.btn-secondary {
    background:#f3f6fd;
    color:#1f3b87;
    padding:12px 24px;
    border:1px solid #c5d3ee;
    border-radius:10px;
    font-weight:600;
    font-size:.95em;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    box-shadow:0 2px 6px rgba(0,0,0,0.04);
}
.btn-secondary:hover {
    background:#e4edfa;
}
.error-list {
    background:#fdecec;
    border:1px solid #f3bcbc;
    color:#a42525;
    padding:14px 16px;
    border-radius:10px;
    margin:0 0 20px;
    font-size:.85em;
}
.note { font-size:.65rem; color:#5b6a80; margin:-6px 0 14px; }
.preview-wrapper { display:none; margin:8px 0 20px; }
.preview-box {
    width:260px;
    height:170px;
    border:1.5px solid #d6dfea;
    border-radius:14px;
    background:#f0f5fa;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    position:relative;
    box-shadow:0 2px 8px -2px rgba(0,0,0,0.07);
}
.preview-box img { width:100%; height:100%; object-fit:cover; display:block; }
.preview-actions { margin-top:6px; display:flex; gap:10px; }
.preview-actions button {
    background:#eef3ff;
    color:#284c9e;
    padding:6px 14px;
    font-size:.75em;
    border:1px solid #c6d2f0;
    border-radius:8px;
    cursor:pointer;
    font-weight:600;
}
.preview-actions button:hover { background:#dbe8ff; }
.inline-error {
    color:#c62828;
    font-size:.75em;
    margin:-10px 0 12px;
    font-weight:600;
}
.doc-file-names { font-size:.65rem; color:#4e5d73; margin:-8px 0 14px; }
@media (max-width:760px) {
    .add-car-container { padding:28px 24px 26px; }
    .preview-box { width:100%; height:220px; }
}
</style>
</head>
<body>
<div class="add-car-container">
    <div class="add-car-title">Add New Car</div>

    <?php if ($errors): ?>
        <div class="error-list">
            <strong>Please fix the following:</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="add-car-form" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="add_car" value="1">

        <label for="plate_no">Plate Number</label>
        <input type="text" name="plate_no" id="plate_no"
               value="<?= htmlspecialchars($_POST['plate_no'] ?? '') ?>" required>

        <label for="car_brand">Brand</label>
        <input type="text" name="car_brand" id="car_brand"
               value="<?= htmlspecialchars($_POST['car_brand'] ?? '') ?>" required>

        <label for="car_model">Model</label>
        <input type="text" name="car_model" id="car_model"
               value="<?= htmlspecialchars($_POST['car_model'] ?? '') ?>" required>

        <label for="year">Year</label>
        <input type="number" name="year" id="year" min="1980" max="<?= date('Y')+1 ?>"
               value="<?= htmlspecialchars($_POST['year'] ?? '') ?>" required>

        <label for="color">Color</label>
        <input type="text" name="color" id="color"
               value="<?= htmlspecialchars($_POST['color'] ?? '') ?>" required>

        <label for="transmission">Transmission</label>
        <select name="transmission" id="transmission" required>
            <option value="" disabled <?= empty($_POST['transmission'])?'selected':''; ?>>Select...</option>
            <option value="Automatic" <?= (($_POST['transmission'] ?? '')==='Automatic')?'selected':''; ?>>Automatic</option>
            <option value="Manual" <?= (($_POST['transmission'] ?? '')==='Manual')?'selected':''; ?>>Manual</option>
        </select>

        <label for="seat_capacity">Seat Capacity</label>
        <input type="number" name="seat_capacity" id="seat_capacity" min="1" max="50"
               value="<?= htmlspecialchars($_POST['seat_capacity'] ?? '') ?>" required>

        <label for="mileage">Mileage (km)</label>
        <input type="number" name="mileage" id="mileage" min="0"
               value="<?= htmlspecialchars($_POST['mileage'] ?? '0') ?>" required>

        <label for="daily_rate">Daily Rate (RM)</label>
        <input type="number" step="0.01" name="daily_rate" id="daily_rate" min="0"
               value="<?= htmlspecialchars($_POST['daily_rate'] ?? '') ?>" required>

        <label for="status">Status</label>
        <select name="status" id="status" required>
            <option value="" disabled <?= empty($_POST['status'])?'selected':''; ?>>Select...</option>
            <option value="available" <?= (($_POST['status'] ?? '')==='available')?'selected':''; ?>>Available</option>
            <option value="unavailable" <?= (($_POST['status'] ?? '')==='unavailable')?'selected':''; ?>>Not Available</option>
        </select>

        <label for="car_image">Primary Image (JPG / PNG / WEBP / GIF, max 5MB)</label>
        <input type="file" name="car_image" id="car_image" accept="image/*">
        <div id="imageError" class="inline-error" style="display:none;"></div>
        <div class="preview-wrapper" id="imagePreviewWrapper">
            <div class="preview-box">
                <img id="imagePreview" alt="Preview">
            </div>
            <div class="preview-actions">
                <button type="button" id="clearImageBtn">Remove Image</button>
            </div>
        </div>
        <div class="note">Optional. You can add/replace later.</div>

        <label for="car_grant_path">Grant Document (PDF / Image, max 15MB)</label>
        <input type="file" name="car_grant_path" id="car_grant_path" accept="application/pdf,image/*">

        <label for="car_roadtax_path">Roadtax Document (PDF / Image, max 15MB)</label>
        <input type="file" name="car_roadtax_path" id="car_roadtax_path" accept="application/pdf,image/*">

        <label for="car_covernote_path">Covernote Document (PDF / Image, max 15MB)</label>
        <input type="file" name="car_covernote_path" id="car_covernote_path" accept="application/pdf,image/*">

        <div class="doc-file-names" id="docFileNames"></div>

        <div class="btn-row">
            <button type="submit">Add Car</button>
            <a href="cars.php" class="btn-secondary">Back</a>
        </div>
    </form>
</div>

<script>
(function() {
    const imageInput = document.getElementById('car_image');
    const previewWrapper = document.getElementById('imagePreviewWrapper');
    const previewImg = document.getElementById('imagePreview');
    const clearBtn = document.getElementById('clearImageBtn');
    const imageError = document.getElementById('imageError');
    const MAX_IMG = 5 * 1024 * 1024; // 5MB

    function resetImage() {
        imageInput.value = '';
        previewWrapper.style.display = 'none';
        previewImg.src = '';
        imageError.style.display = 'none';
        imageError.textContent = '';
    }

    imageInput.addEventListener('change', () => {
        imageError.style.display = 'none';
        imageError.textContent = '';
        const file = imageInput.files && imageInput.files[0];
        if (!file) {
            resetImage();
            return;
        }
        if (!file.type.startsWith('image/')) {
            imageError.textContent = 'Selected file is not an image.';
            imageError.style.display = 'block';
            resetImage();
            return;
        }
        if (file.size > MAX_IMG) {
            imageError.textContent = 'Image exceeds 5MB limit.';
            imageError.style.display = 'block';
            resetImage();
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            previewImg.src = e.target.result;
            previewWrapper.style.display = 'block';
        };
        reader.readAsDataURL(file);
    });

    clearBtn.addEventListener('click', () => {
        resetImage();
    });

    // Document file name preview (optional)
    const docInputs = ['car_grant_path','car_roadtax_path','car_covernote_path'];
    const docFileNames = document.getElementById('docFileNames');
    docInputs.forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('change', () => {
            const selected = docInputs
                .map(fid => {
                    const fEl = document.getElementById(fid);
                    if (fEl.files && fEl.files[0]) return fEl.files[0].name;
                    return null;
                })
                .filter(Boolean);
            docFileNames.textContent = selected.length
                ? 'Selected docs: ' + selected.join(', ')
                : '';
        });
    });
})();
</script>
<?php include '../includes/footer.php'; ?>
</body>
</html>