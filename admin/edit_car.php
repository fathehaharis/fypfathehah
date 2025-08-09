<?php
/*
 * edit_car.php (delete functionality removed)
 *
 * Features kept:
 *   - Update core car fields
 *   - Upload / replace primary image (car_image table)
 *   - Upload / replace documents (car_grant_blob, car_roadtax_blob, car_covernote_blob)
 *   - Cache busting with version params
 *
 * Removed:
 *   - Delete Car POST handler
 *   - Delete Car UI section
 */

require_once '../connect.php';
session_start();

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

if (empty($_GET['id']) || !ctype_digit($_GET['id'])) {
    http_response_code(400);
    echo "Invalid car id.";
    exit;
}
$car_id = (int)$_GET['id'];

$statusOptions = ['available','unavailable'];
$transmissions = ['automatic','manual'];
$feedback      = '';
$errorMsg      = '';
$docErrors     = [];
$docFeedbacks  = [];

/* ---------- Helpers ---------- */
function statusBadgeLabel(string $s): array {
    return strtolower($s)==='available' ? ['Available',''] : ['Not Available',' not-available'];
}

function detectImageMime(string $path, string $origName=''): string {
    $fh = @fopen($path,'rb');
    $head = $fh ? fread($fh,16) : '';
    if ($fh) fclose($fh);
    if (strncmp($head, "\xFF\xD8\xFF", 3)===0) return 'image/jpeg';
    if (strncmp($head, "\x89PNG\x0D\x0A\x1A\x0A", 8)===0) return 'image/png';
    if (strncmp($head, "GIF87a", 6)===0 || strncmp($head, "GIF89a", 6)===0) return 'image/gif';
    if (substr($head,0,4)==='RIFF' && substr($head,8,4)==='WEBP') return 'image/webp';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    return match($ext) {
        'jpg','jpeg' => 'image/jpeg',
        'png'        => 'image/png',
        'gif'        => 'image/gif',
        'webp'       => 'image/webp',
        default      => 'application/octet-stream'
    };
}

function formatBytes(int $bytes): string {
    if ($bytes === 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $i = (int) floor(log($bytes,1024));
    return round($bytes / (1024 ** $i), $i===0?0:1).' '.$units[$i];
}

function docLink(int $car_id, string $column, ?int $len, string $label, ?string $updatedAt): string {
    if (!$len) return '<span class="none">None</span>';
    $ts = $updatedAt ? strtotime($updatedAt) : time();
    $url = "download_doc.php?car_id={$car_id}&field={$column}&name=".urlencode($label)."&u=".$ts;
    return '<a href="'.$url.'" target="_blank">'.htmlspecialchars($label).'</a> <span class="size-hint">('.formatBytes((int)$len).')</span>';
}

/* ---------- Fetch Car (avoid full blobs) ---------- */
$sqlCar = "
    SELECT car_id, car_brand, car_model, year, color, mileage, plate_no,
           transmission, seat_capacity, status, daily_rate,
           LENGTH(car_grant_blob)     AS grant_len,
           LENGTH(car_roadtax_blob)   AS roadtax_len,
           LENGTH(car_covernote_blob) AS covernote_len,
           updated_at,
           images_version
    FROM car
    WHERE car_id=? LIMIT 1";
$stmt = $conn->prepare($sqlCar);
if(!$stmt){
    error_log("CARDBG prepare fail: ".$conn->error);
    die("Car query failed.");
}
$stmt->bind_param("i",$car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$car) {
    http_response_code(404);
    echo "Car not found.";
    exit;
}

/* ---------- Fetch Primary Image Meta ---------- */
$stmt = $conn->prepare("
    SELECT car_image_id, version
    FROM car_image
    WHERE car_id=?
    ORDER BY sort_order ASC, car_image_id ASC
    LIMIT 1
");
$stmt->bind_param("i",$car_id);
$stmt->execute();
$primaryImage = $stmt->get_result()->fetch_assoc();
$stmt->close();

[$statusLabel,$statusClass] = statusBadgeLabel($car['status'] ?? 'available');

/* ---------- Update Core Car Fields ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_car'])) {
    $car_brand     = trim($_POST['car_brand'] ?? '');
    $car_model     = trim($_POST['car_model'] ?? '');
    $plate_no      = trim($_POST['plate_no'] ?? '');
    $year          = (int)($_POST['year'] ?? 0);
    $color         = trim($_POST['color'] ?? '');
    $mileage       = (int)($_POST['mileage'] ?? 0);
    $transmission  = strtolower(trim($_POST['transmission'] ?? ''));
    $seat_capacity = (int)($_POST['seat_capacity'] ?? 0);
    $daily_rate    = (float)($_POST['daily_rate'] ?? 0);
    $status        = strtolower(trim($_POST['status'] ?? ''));

    $nowY = (int)date('Y');
    if ($car_brand==='' || $car_model==='')               $errorMsg="Brand and Model are required.";
    elseif ($plate_no==='')                               $errorMsg="Plate number is required.";
    elseif ($year < 1980 || $year > $nowY+1)              $errorMsg="Year must be between 1980 and ".($nowY+1).".";
    elseif ($seat_capacity < 1 || $seat_capacity > 50)    $errorMsg="Seat capacity must be 1–50.";
    elseif ($mileage < 0)                                 $errorMsg="Mileage cannot be negative.";
    elseif ($daily_rate < 0)                              $errorMsg="Daily rate cannot be negative.";
    elseif (!in_array($transmission,$transmissions,true)) $errorMsg="Invalid transmission.";
    elseif (!in_array($status,$statusOptions,true))       $errorMsg="Invalid status.";

    if (!$errorMsg) {
        $stmt = $conn->prepare("SELECT car_id FROM car WHERE plate_no=? AND car_id<>? LIMIT 1");
        $stmt->bind_param("si",$plate_no,$car_id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup) {
            $errorMsg = "Plate number already in use by another car.";
        } else {
            $stmt = $conn->prepare("
                UPDATE car SET
                  car_brand=?, car_model=?, year=?, color=?, mileage=?, plate_no=?,
                  transmission=?, seat_capacity=?, status=?, daily_rate=?, updated_at=NOW()
                WHERE car_id=? LIMIT 1
            ");
            $stmt->bind_param(
                "ssisissisdi",
                $car_brand, $car_model, $year, $color, $mileage, $plate_no,
                $transmission, $seat_capacity, $status, $daily_rate, $car_id
            );
            if ($stmt->execute()) {
                $feedback .= "Car details updated. ";
                $stmt->close();
                $stmt = $conn->prepare($sqlCar);
                $stmt->bind_param("i",$car_id);
                $stmt->execute();
                $car = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                [$statusLabel,$statusClass] = statusBadgeLabel($car['status'] ?? 'available');
            } else {
                $errorMsg = "Failed to update car: ".$stmt->error;
                $stmt->close();
            }
        }
    }
}

/* ---------- Image Upload / Replace ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_image'])) {
    if (!isset($_FILES['car_image']) || $_FILES['car_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $errorMsg = "No image selected.";
    } else {
        $f = $_FILES['car_image'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = "Image upload error code ".$f['error'].".";
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $errorMsg = "Image too large (max 5MB).";
        } else {
            $mime = detectImageMime($f['tmp_name'], $f['name']);
            $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
            if (!in_array($mime,$allowed,true)) {
                $errorMsg = "Unsupported image type (detected: $mime).";
            } else {
                $imgData = file_get_contents($f['tmp_name']);
                if ($imgData === false) {
                    $errorMsg = "Failed to read uploaded image.";
                } else {
                    $stmt = $conn->prepare("
                        SELECT car_image_id FROM car_image
                        WHERE car_id=? ORDER BY sort_order ASC, car_image_id ASC LIMIT 1
                    ");
                    $stmt->bind_param("i",$car_id);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($existing) {
                        $null = NULL;
                        $stmt = $conn->prepare("
                            UPDATE car_image
                            SET image_blob=?, image_type='main', uploaded_at=NOW(), version=version+1
                            WHERE car_image_id=?
                        ");
                        $stmt->bind_param("bi",$null,$existing['car_image_id']);
                        $stmt->send_long_data(0,$imgData);
                        if (!$stmt->execute()) $errorMsg = "Image update failed: ".$stmt->error;
                        $stmt->close();
                    } else {
                        $null = NULL;
                        $stmt = $conn->prepare("
                            INSERT INTO car_image (car_id,image_type,image_blob,uploaded_at,sort_order,version)
                            VALUES ( ?,'main',?,NOW(),0,0 )
                        ");
                        $stmt->bind_param("ib",$car_id,$null);
                        $stmt->send_long_data(1,$imgData);
                        if (!$stmt->execute()) $errorMsg = "Image insert failed: ".$stmt->error;
                        $stmt->close();
                    }

                    if (!$errorMsg) {
                        $conn->query("UPDATE car SET images_version=images_version+1, updated_at=NOW() WHERE car_id=".$car_id);
                        $stmt = $conn->prepare("
                            SELECT car_image_id, version
                            FROM car_image
                            WHERE car_id=? ORDER BY sort_order ASC, car_image_id ASC LIMIT 1
                        ");
                        $stmt->bind_param("i",$car_id);
                        $stmt->execute();
                        $primaryImage = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        $feedback .= "Primary image updated. ";
                        header("Location: edit_car.php?id=".$car_id."&img=ok");
                        exit;
                    }
                }
            }
        }
    }
}

/* ---------- Document Upload ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_docs'])) {
    $docMap = [
        'grant_file'    => 'car_grant_blob',
        'roadtax_file'  => 'car_roadtax_blob',
        'covernote_file'=> 'car_covernote_blob'
    ];
    $sets=[];$bind=[];$types=''; $any=false;

    foreach ($docMap as $input => $column) {
        if (!isset($_FILES[$input]) || $_FILES[$input]['error'] === UPLOAD_ERR_NO_FILE) continue;
        $err = $_FILES[$input]['error'];
        if ($err !== UPLOAD_ERR_OK) { $docErrors[] = "$input error $err"; continue; }
        if ($_FILES[$input]['size'] > 15 * 1024 * 1024) { $docErrors[] = "$input too large"; continue; }
        $data = file_get_contents($_FILES[$input]['tmp_name']);
        if ($data === false) { $docErrors[] = "$input read failure"; continue; }
        $sets[]="$column=?"; $bind[]=$data; $types.='b'; $any=true;
        $docFeedbacks[] = match($input){
            'grant_file' => 'Grant',
            'roadtax_file' => 'Roadtax',
            'covernote_file' => 'Covernote',
            default => ucfirst($input)
        };
    }

    if ($any) {
        $sql = "UPDATE car SET ".implode(', ',$sets).", updated_at=NOW() WHERE car_id=?";
        $types.='i'; $bind[]=$car_id;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errorMsg = "Doc update prepare failed: ".$conn->error;
        } else {
            $stmt->bind_param($types, ...$bind);
            for ($i=0; $i<count($bind)-1; $i++) if ($types[$i]==='b') $stmt->send_long_data($i,$bind[$i]);
            if ($stmt->execute()) {
                if ($docFeedbacks) $feedback .= "Updated docs: ".implode(', ',$docFeedbacks).". ";
                $stmt->close();
                $stmt = $conn->prepare("
                    SELECT LENGTH(car_grant_blob) AS grant_len,
                           LENGTH(car_roadtax_blob) AS roadtax_len,
                           LENGTH(car_covernote_blob) AS covernote_len,
                           updated_at, images_version
                    FROM car WHERE car_id=? LIMIT 1
                ");
                $stmt->bind_param("i",$car_id);
                $stmt->execute();
                $fresh = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($fresh) {
                    $car['grant_len']     = $fresh['grant_len'];
                    $car['roadtax_len']   = $fresh['roadtax_len'];
                    $car['covernote_len'] = $fresh['covernote_len'];
                    $car['updated_at']    = $fresh['updated_at'];
                    $car['images_version']= $fresh['images_version'];
                }
                header("Location: edit_car.php?id=".$car_id."&docs=ok");
                exit;
            } else {
                $errorMsg = "Doc update failed: ".$stmt->error;
                $stmt->close();
            }
        }
    } else {
        if (!$docErrors) $errorMsg = "No documents selected.";
    }
}

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Car</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body { margin:0; font-family:system-ui,Arial,sans-serif; background:#f1f5fa; }
.page { max-width:1150px; margin:42px auto 80px; background:#fff; padding:46px 50px 60px; border-radius:22px; box-shadow:0 4px 18px -4px rgba(0,0,0,0.08); }
h1 { margin:0 0 26px; font-size:2.1em; font-weight:800; color:#1f3455; }
.status-badge { display:inline-block; margin-left:12px; padding:6px 18px; font-size:.78em; font-weight:700; border-radius:16px; background:#e6fcf3; color:#22984b; vertical-align:middle; }
.status-badge.not-available { background:#ffeded; color:#e54848; }
.feedback .ok, .feedback .err { padding:10px 14px; border-radius:9px; font-size:.9em; font-weight:600; margin:0 0 14px; }
.feedback .ok { background:#e6f9e9; border:1px solid #b5e5bc; color:#1f6a28; }
.feedback .err { background:#fdecec; border:1px solid #f6c1c1; color:#a61f1f; }
.form-grid { width:100%; border-collapse:collapse; margin-bottom:28px; }
.form-grid td { padding:9px 14px; vertical-align:top; }
.form-grid label { display:block; font-size:.62rem; font-weight:700; letter-spacing:.55px; text-transform:uppercase; margin-bottom:5px; color:#314f75; }
.form-grid input[type=text],
.form-grid input[type=number],
.form-grid select { width:100%; max-width:250px; padding:8px 11px; border:1px solid #ccd4e2; border-radius:8px; background:#f8fbfe; font-size:.9em; }
.actions { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:40px; }
.actions button, .actions a.btn { background:#304cc3; color:#fff; border:none; padding:12px 22px; border-radius:10px; font-weight:600; font-size:.9em; cursor:pointer; text-decoration:none; box-shadow:0 2px 6px rgba(48,76,195,0.18); }
.actions button:hover, .actions a.btn:hover { background:#1f3692; }
.section-title { margin:20px 0 14px; font-size:1.15em; font-weight:700; color:#304cc3; }
.primary-image-box { width:340px; height:220px; border:1.5px solid #e1e7f1; border-radius:16px; background:#f6f9fc; display:flex; align-items:center; justify-content:center; position:relative; overflow:hidden; margin-bottom:10px; box-shadow:0 2px 10px -3px rgba(0,0,0,.08); }
.primary-image-box img { width:100%; height:100%; object-fit:cover; display:block; cursor:pointer; }
.preview-empty { color:#777; font-size:.85em; }
.image-upload-form input[type=file] { font-size:.8em; margin-bottom:6px; }
.image-upload-form button { background:#304cc3; color:#fff; border:none; padding:8px 18px; border-radius:8px; font-weight:600; cursor:pointer; }
.image-upload-form button:hover { background:#1f3692; }
.image-note { font-size:.65rem; color:#4a5d78; margin-top:2px; }
.docs-table { width:100%; border-collapse:collapse; margin-top:6px; margin-bottom:12px; }
.docs-table th, .docs-table td { text-align:left; padding:8px 10px; font-size:.83em; }
.docs-table th { font-size:.62rem; text-transform:uppercase; letter-spacing:.55px; color:#39567f; }
.docs-table tr:nth-child(even) { background:#f5f8fc; }
.none { color:#888; font-style:italic; }
.size-hint { color:#5b6a80; font-size:.72em; }
.doc-upload-area { border:1px dashed #b9c4d6; padding:18px 20px 10px; border-radius:14px; background:#fafcff; margin:10px 0 34px; }
.doc-row { display:flex; flex-wrap:wrap; gap:22px; margin-bottom:14px; }
.doc-field { display:flex; flex-direction:column; min-width:220px; }
.doc-field label { font-size:.63rem; font-weight:700; letter-spacing:.55px; text-transform:uppercase; color:#314f75; margin-bottom:6px; }
.doc-field input[type=file] { font-size:.74rem; }
.doc-submit { display:flex; align-items:flex-end; }
.doc-submit button { background:#304cc3; color:#fff; border:none; padding:10px 20px; border-radius:10px; font-weight:600; cursor:pointer; }
.doc-submit button:hover { background:#1f3692; }
@media (max-width:860px) {
  .page { padding:38px 30px 54px; }
  .primary-image-box { width:100%; height:240px; }
}
</style>
</head>
<body>
<div class="page">
    <h1>
        Edit Car: <?= htmlspecialchars(strtoupper($car['car_brand'] ?? '')) ?>
        <?= htmlspecialchars($car['car_model'] ?? '') ?>
        <span class="status-badge<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
    </h1>

    <div class="feedback">
        <?php if ($feedback): ?><div class="ok"><?= htmlspecialchars(trim($feedback)) ?></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="err"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
        <?php if ($docErrors): ?>
            <div class="err"><?= htmlspecialchars("Document errors: ".implode("; ", $docErrors)) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['img']) && $_GET['img']==='ok' && !$errorMsg): ?>
            <div class="ok">Image upload complete.</div>
        <?php endif; ?>
        <?php if (isset($_GET['docs']) && $_GET['docs']==='ok' && !$errorMsg): ?>
            <div class="ok">Document upload complete.</div>
        <?php endif; ?>
    </div>

    <!-- Core Car Form -->
    <form method="post" novalidate>
        <input type="hidden" name="save_car" value="1">
        <table class="form-grid">
            <tr>
                <td>
                    <label>Brand</label>
                    <input type="text" name="car_brand" value="<?= htmlspecialchars($car['car_brand']) ?>" maxlength="50" required>
                </td>
                <td>
                    <label>Model</label>
                    <input type="text" name="car_model" value="<?= htmlspecialchars($car['car_model']) ?>" maxlength="50" required>
                </td>
                <td>
                    <label>Plate Number</label>
                    <input type="text" name="plate_no" value="<?= htmlspecialchars($car['plate_no']) ?>" maxlength="20" required>
                </td>
            </tr>
            <tr>
                <td>
                    <label>Year</label>
                    <input type="number" name="year" value="<?= (int)$car['year'] ?>" min="1980" max="<?= date('Y')+1 ?>" required>
                </td>
                <td>
                    <label>Color</label>
                    <input type="text" name="color" value="<?= htmlspecialchars($car['color']) ?>" maxlength="30">
                </td>
                <td>
                    <label>Transmission</label>
                    <select name="transmission" required>
                        <?php foreach ($transmissions as $t): ?>
                            <option value="<?= $t ?>" <?= strtolower($car['transmission'])===strtolower($t)?'selected':''; ?>>
                                <?= ucfirst($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td>
                    <label>Seat Capacity</label>
                    <input type="number" name="seat_capacity" value="<?= (int)$car['seat_capacity'] ?>" min="1" max="50" required>
                </td>
                <td>
                    <label>Mileage (km)</label>
                    <input type="number" name="mileage" value="<?= (int)$car['mileage'] ?>" min="0">
                </td>
                <td>
                    <label>Daily Rate (RM)</label>
                    <input type="number" step="0.01" name="daily_rate" value="<?= htmlspecialchars($car['daily_rate']) ?>" min="0" required>
                </td>
            </tr>
            <tr>
                <td>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($statusOptions as $s): ?>
                            <option value="<?= $s ?>" <?= strtolower($car['status'])===$s?'selected':''; ?>>
                                <?= $s==='available'?'Available':'Not Available' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td></td><td></td>
            </tr>
        </table>
        <div class="actions">
            <button type="submit">Save Changes</button>
            <a href="car_details.php?id=<?= $car_id ?>" class="btn">Back</a>
        </div>
    </form>

    <!-- Primary Image -->
    <div class="section-title">Primary Image</div>
    <div class="primary-image-box" id="primaryImageBox">
        <?php if ($primaryImage): ?>
            <img id="currentImage"
                 src="car_image.php?id=<?= (int)$primaryImage['car_image_id'] ?>&v=<?= (int)$primaryImage['version'] ?>"
                 alt="Primary Image"
                 title="Click to open full image"
                 onclick="window.open(this.src,'_blank')">
        <?php else: ?>
            <div class="preview-empty" id="noImageMsg">No primary image uploaded.</div>
        <?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" class="image-upload-form" id="imageUploadForm">
        <input type="hidden" name="upload_image" value="1">
        <input type="file" name="car_image" id="car_image" accept="image/*" required>
        <button type="submit">Upload / Replace Image</button>
        <div class="image-note" id="imagePreviewNote">Max 5MB. Accepted: JPG, PNG, GIF, WebP.</div>
    </form>

    <!-- Documents -->
    <div class="section-title">Documents</div>
    <table class="docs-table">
        <thead>
        <tr>
            <th style="width:160px;">Type</th>
            <th>Current</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>Grant</td>
            <td><?= docLink($car_id,'car_grant_blob',$car['grant_len'],'Grant',$car['updated_at']) ?></td>
        </tr>
        <tr>
            <td>Roadtax</td>
            <td><?= docLink($car_id,'car_roadtax_blob',$car['roadtax_len'],'Roadtax',$car['updated_at']) ?></td>
        </tr>
        <tr>
            <td>Covernote</td>
            <td><?= docLink($car_id,'car_covernote_blob',$car['covernote_len'],'Covernote',$car['updated_at']) ?></td>
        </tr>
        </tbody>
    </table>

    <form method="post" enctype="multipart/form-data" class="doc-upload-area">
        <input type="hidden" name="upload_docs" value="1">
        <div class="doc-row">
            <div class="doc-field">
                <label for="grant_file">Grant (PDF/Image)</label>
                <input type="file" name="grant_file" id="grant_file" accept="application/pdf,image/*">
            </div>
            <div class="doc-field">
                <label for="roadtax_file">Roadtax</label>
                <input type="file" name="roadtax_file" id="roadtax_file" accept="application/pdf,image/*">
            </div>
            <div class="doc-field">
                <label for="covernote_file">Covernote</label>
                <input type="file" name="covernote_file" id="covernote_file" accept="application/pdf,image/*">
            </div>
            <div class="doc-field doc-submit">
                <label>&nbsp;</label>
                <button type="submit">Upload / Replace Docs</button>
            </div>
        </div>
    </form>
</div>

<script>
const imageInput = document.getElementById('car_image');
const previewNote = document.getElementById('imagePreviewNote');
const primaryBox = document.getElementById('primaryImageBox');
const existingImg = document.getElementById('currentImage');
const noImageMsg = document.getElementById('noImageMsg');

if (imageInput) {
  imageInput.addEventListener('change', () => {
    previewNote.textContent = '';
    const file = imageInput.files && imageInput.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      previewNote.textContent = 'Selected file is not an image.';
      imageInput.value = '';
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      previewNote.textContent = 'Image exceeds 5MB limit.';
      imageInput.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = e => {
      let previewImg = document.getElementById('pendingPreviewImage');
      if (!previewImg) {
        previewImg = document.createElement('img');
        previewImg.id = 'pendingPreviewImage';
        Object.assign(previewImg.style, {
          position:'absolute', top:'0', left:'0',
          width:'100%', height:'100%', objectFit:'cover', opacity:'0.85'
        });
        primaryBox.appendChild(previewImg);
      }
      previewImg.src = e.target.result;
      previewImg.title = 'New image preview (not saved yet)';
      if (existingImg) existingImg.style.opacity = '0.35';
      if (noImageMsg) noImageMsg.style.display = 'none';
      previewNote.textContent = 'Preview shown. Click "Upload / Replace Image" to save.';
    };
    reader.readAsDataURL(file);
  });
}
</script>
</body>
</html>