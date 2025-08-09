<?php
declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
function verify_csrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) &&
        hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Upsert security deposit refund (reference_code = DEP-{booking_id})
 * Matches your refunds table schema.
 */
function upsert_deposit_refund(
    mysqli $conn,
    int $booking_id,
    int $cust_id,
    float $originalDeposit,
    float $deduction,
    float $refundable,
    string $damageDesc
): void {
    $refCode = 'DEP-' . $booking_id;

    // If forfeited (refundable 0) cancel any pending/failed deposit refund rows
    if ($refundable <= 0.00001) {
        $upd = $conn->prepare("
            UPDATE refunds
               SET refund_status='cancelled',
                   notes='Deposit forfeited',
                   user_unread=1
             WHERE booking_id=? AND reference_code=? AND refund_status IN ('pending','failed')
        ");
        $upd->bind_param('is', $booking_id, $refCode);
        $upd->execute();
        $upd->close();
        return;
    }

    $refundRate  = $originalDeposit > 0 ? $refundable / $originalDeposit : 0.0;
    $base_amount = $originalDeposit;
    $notes = $deduction > 0
        ? 'Security Deposit Refund after deduction RM '.number_format($deduction,2)
        : 'Security Deposit Refund';
    if ($damageDesc && $deduction > 0) {
        $notes .= ' - ' . mb_substr($damageDesc, 0, 100);
    }

    $sel = $conn->prepare("
        SELECT refund_id, refund_status
          FROM refunds
         WHERE booking_id=? AND reference_code=?
         LIMIT 1
    ");
    $sel->bind_param('is', $booking_id, $refCode);
    $sel->execute();
    $existing = $sel->get_result()->fetch_assoc();
    $sel->close();

    if ($existing) {
        if ($existing['refund_status'] === 'processed') {
            // Do not alter processed refund
            return;
        }
        $upd = $conn->prepare("
            UPDATE refunds
               SET amount=?,
                   refund_status='pending',
                   notes=?,
                   refund_rate=?,
                   base_amount=?,
                   user_unread=1
             WHERE refund_id=?
        ");
        $upd->bind_param('dsddi',
            $refundable,
            $notes,
            $refundRate,
            $base_amount,
            $existing['refund_id']
        );
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("
            INSERT INTO refunds
                (booking_id, cust_id, amount, refund_status, reference_code, notes,
                 refund_rate, base_amount, created_at, user_unread)
            VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), 1)
        ");
        $ins->bind_param('iisssdd',
            $booking_id,
            $cust_id,
            $refundable,
            $refCode,
            $notes,
            $refundRate,
            $base_amount
        );
        $ins->execute();
        $ins->close();
    }
}

/* Inputs */
$booking_id = isset($_GET['booking_id']) && ctype_digit($_GET['booking_id'])
    ? (int)$_GET['booking_id'] : 0;
$allowed_types = ['pickup','return'];
$type = isset($_GET['type']) && in_array(strtolower($_GET['type']), $allowed_types, true)
    ? strtolower($_GET['type']) : 'pickup';

if ($booking_id <= 0) {
    echo "Invalid booking ID.";
    exit;
}

/* Fetch booking (with deposit fields + customer for refunds) */
$stmt = $conn->prepare("
    SELECT b.*,
           c.car_brand, c.car_model, c.plate_no, c.mileage AS car_mileage, c.car_id,
           cust.cust_id
      FROM booking b
      JOIN car c ON b.car_id = c.car_id
 LEFT JOIN customer cust ON b.cust_id = cust.cust_id
     WHERE b.booking_id = ?
     LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo "Booking not found.";
    exit;
}

$car_id = (int)$booking['car_id'];
$current_car_mileage = (int)$booking['car_mileage'];
$cust_id = (int)($booking['cust_id'] ?? 0);

/* Slot definitions */
$slot_definitions = [
    'car_front'       => ['label'=>'Car Front',       'required'=>true],
    'car_back'        => ['label'=>'Car Back',        'required'=>true],
    'car_left'        => ['label'=>'Car Left Side',   'required'=>true],
    'car_right'       => ['label'=>'Car Right Side',  'required'=>true],
    'fuel_image'      => ['label'=>'Fuel Gauge',      'required'=>true],
    'additional_image'=> ['label'=>'Additional',      'required'=>false],
];

/* Existing images for this inspection type */
$imgStmt = $conn->prepare("
    SELECT booking_image_id, image_path, image_type, capture_type, inspection_date, uploaded_at
      FROM booking_image
     WHERE booking_id=? AND capture_type=?
     ORDER BY uploaded_at ASC
");
$imgStmt->bind_param("is", $booking_id, $type);
$imgStmt->execute();
$res = $imgStmt->get_result();
$images = $res->fetch_all(MYSQLI_ASSOC);
$imgStmt->close();

$slotImages = [];
foreach ($images as $im) {
    $slotImages[$im['image_type']] = $im;
}

/* Existing core fields */
if ($type === 'pickup') {
    $existing_mileage = (int)$booking['pickup_mileage'];
    $existing_fuel    = $booking['pickup_fuel_percent'] !== null ? (int)$booking['pickup_fuel_percent'] : 0;
    $existing_remarks = $booking['pickup_inspection_remarks'] ?? '';
} else {
    $existing_mileage = (int)$booking['return_mileage'];
    $existing_fuel    = $booking['return_fuel_percent'] !== null ? (int)$booking['return_fuel_percent'] : 0;
    $existing_remarks = $booking['return_inspection_remarks'] ?? '';
}

/* Existing deposit data (return only) */
$original_deposit      = (float)($booking['security_deposit'] ?? 0);
$current_deduction     = (float)($booking['security_deposit_deduction'] ?? 0);
$current_refund        = (float)($booking['security_deposit_refund'] ?? max($original_deposit - $current_deduction, 0));
$current_deposit_status= (string)($booking['deposit_status'] ?? 'held');
$current_damage_desc   = (string)($booking['deposit_damage_description'] ?? '');

/* Check if existing deposit refund is processed (lock editing damage if so) */
$deposit_locked = false;
$refund_row = null;
if ($type === 'return') {
    $refStmt = $conn->prepare("
        SELECT refund_id, refund_status
          FROM refunds
         WHERE booking_id=? AND reference_code=?
         LIMIT 1
    ");
    $refCode = 'DEP-' . $booking_id;
    $refStmt->bind_param('is', $booking_id, $refCode);
    $refStmt->execute();
    $refund_row = $refStmt->get_result()->fetch_assoc();
    $refStmt->close();
    if ($refund_row && $refund_row['refund_status'] === 'processed') {
        $deposit_locked = true; // cannot change deduction after processed
    }
}

/* Inspection date input default */
$inspection_date_display = $images ? ($images[0]['inspection_date'] ?? '') : '';
$default_inspection_dt_input = $inspection_date_display
    ? str_replace(' ', 'T', substr($inspection_date_display, 0, 16))
    : date('Y-m-d\TH:i');

$errors = [];
$success = null;

/* POST (Edit) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = "Invalid session token.";
    } else {
        $mileage = isset($_POST['mileage']) ? (int)$_POST['mileage'] : 0;
        $fuel_percent = isset($_POST['fuel_percent']) ? (int)$_POST['fuel_percent'] : -1;
        $inspection_date_raw = $_POST['inspection_date'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');

        if ($mileage < 0) $errors[] = "Mileage cannot be negative.";
        if ($fuel_percent < 0 || $fuel_percent > 100) $errors[] = "Fuel percent must be between 0 and 100.";
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $inspection_date_raw)) {
            $errors[] = "Invalid inspection date format.";
        }
        $inspection_date_sql = $inspection_date_raw
            ? str_replace('T',' ', $inspection_date_raw).":00"
            : date('Y-m-d H:i:s');

        if ($type === 'return' && !empty($booking['pickup_mileage']) && $mileage < (int)$booking['pickup_mileage']) {
            $errors[] = "Return mileage cannot be less than pickup mileage (".(int)$booking['pickup_mileage'].").";
        }

        /* Damage (only for return & if not locked) */
        $new_damage_amount = $current_deduction;
        $new_damage_desc   = $current_damage_desc;
        if ($type === 'return' && !$deposit_locked) {
            $damage_amount_raw = $_POST['damage_amount'] ?? (string)$current_deduction;
            $damage_desc_raw   = trim($_POST['damage_description'] ?? $current_damage_desc);

            if ($damage_amount_raw === '' || !is_numeric($damage_amount_raw)) {
                $errors[] = "Damage amount must be numeric.";
            } else {
                $new_damage_amount = round((float)$damage_amount_raw, 2);
                if ($new_damage_amount < 0) {
                    $errors[] = "Damage amount cannot be negative.";
                    $new_damage_amount = $current_deduction;
                }
                if ($new_damage_amount > $original_deposit) {
                    // Cap to deposit
                    $new_damage_amount = $original_deposit;
                }
                if ($new_damage_amount > 0 && $damage_desc_raw === '') {
                    $errors[] = "Provide damage description when damage amount > 0.";
                } else {
                    $new_damage_desc = $new_damage_amount > 0 ? $damage_desc_raw : '';
                }
            }
        }

        /* Files validation (only ensure required present if currently missing) */
        $allowed_mime = ['image/jpeg','image/png','image/jpg'];
        $max_size = 6 * 1024 * 1024;
        foreach ($slot_definitions as $key => $meta) {
            $filePresent = !empty($_FILES['images']['name'][$key]);
            $hasExisting = isset($slotImages[$key]);
            if ($meta['required'] && !$filePresent && !$hasExisting) {
                $errors[] = "Required image missing: ".$meta['label'];
            }
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                /* Update booking core */
                if ($type === 'pickup') {
                    $stmt = $conn->prepare("
                        UPDATE booking
                           SET pickup_mileage=?,
                               pickup_fuel_percent=?,
                               updated_at=NOW()
                         WHERE booking_id=?");
                    $stmt->bind_param('iii', $mileage, $fuel_percent, $booking_id);
                    $stmt->execute();
                    $stmt->close();

                    if (array_key_exists('pickup_inspection_remarks', $booking)) {
                        $stmt = $conn->prepare("
                            UPDATE booking
                               SET pickup_inspection_remarks=?,
                                   updated_at=NOW()
                             WHERE booking_id=?");
                        $stmt->bind_param('si', $remarks, $booking_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare("
                        UPDATE booking
                           SET return_mileage=?,
                               return_fuel_percent=?,
                               updated_at=NOW()
                         WHERE booking_id=?");
                    $stmt->bind_param('iii', $mileage, $fuel_percent, $booking_id);
                    $stmt->execute();
                    $stmt->close();

                    if (array_key_exists('return_inspection_remarks', $booking)) {
                        $stmt = $conn->prepare("
                            UPDATE booking
                               SET return_inspection_remarks=?,
                                   updated_at=NOW()
                             WHERE booking_id=?");
                        $stmt->bind_param('si', $remarks, $booking_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                /* Update car mileage if forward */
                if ($mileage > $current_car_mileage) {
                    $stmt = $conn->prepare("UPDATE car SET mileage=? WHERE car_id=?");
                    $stmt->bind_param('ii', $mileage, $car_id);
                    $stmt->execute();
                    $stmt->close();
                }

                /* Images replacement */
                foreach ($slot_definitions as $key => $meta) {
                    if (empty($_FILES['images']['name'][$key])) continue;

                    $err  = $_FILES['images']['error'][$key];
                    $mime = $_FILES['images']['type'][$key] ?? '';
                    $size = $_FILES['images']['size'][$key] ?? 0;
                    $tmp  = $_FILES['images']['tmp_name'][$key] ?? '';

                    if ($err !== UPLOAD_ERR_OK || !file_exists($tmp)) continue;
                    if (!in_array($mime, $allowed_mime, true)) continue;
                    if ($size <= 0 || $size > $max_size) continue;

                    $blob = file_get_contents($tmp);
                    if ($blob === false) continue;

                    if (isset($slotImages[$key])) {
                        $upd = $conn->prepare("
                            UPDATE booking_image
                               SET image_path=?,
                                   inspection_date=?,
                                   uploaded_at=NOW()
                             WHERE booking_image_id=?
                             LIMIT 1
                        ");
                        $id = (int)$slotImages[$key]['booking_image_id'];
                        $upd->bind_param('bsi', $blob, $inspection_date_sql, $id);
                        $upd->send_long_data(0, $blob);
                        $upd->execute();
                        $upd->close();
                    } else {
                        $ins = $conn->prepare("
                            INSERT INTO booking_image
                                (booking_id, image_path, image_type, capture_type, inspection_date, uploaded_at)
                            VALUES (?,?,?,?,?,NOW())
                        ");
                        $ins->bind_param('ibsss',
                            $booking_id,
                            $blob,
                            $key,
                            $type,
                            $inspection_date_sql
                        );
                        $ins->send_long_data(1, $blob);
                        $ins->execute();
                        $ins->close();
                    }
                }

                /* Deposit update if return & not locked */
                if ($type === 'return' && !$deposit_locked) {
                    $deduction  = $new_damage_amount;
                    $refundable = max($original_deposit - $deduction, 0);

                    $new_status = ($deduction >= $original_deposit && $original_deposit > 0)
                        ? 'forfeited'
                        : 'pending_refund';

                    $stmt = $conn->prepare("
                        UPDATE booking
                           SET security_deposit_deduction=?,
                               security_deposit_refund=?,
                               deposit_status=?,
                               deposit_damage_description=?,
                               deposit_last_adjusted_at=NOW(),
                               updated_at=NOW()
                         WHERE booking_id=?
                    ");
                    $stmt->bind_param('ddssi',
                        $deduction,
                        $refundable,
                        $new_status,
                        $new_damage_desc,
                        $booking_id
                    );
                    $stmt->execute();
                    $stmt->close();

                    if ($cust_id > 0) {
                        upsert_deposit_refund(
                            $conn,
                            $booking_id,
                            $cust_id,
                            $original_deposit,
                            $deduction,
                            $refundable,
                            $new_damage_desc
                        );
                    }
                }

                $conn->commit();
                $_SESSION['success'] = ucfirst($type)." inspection updated.";
                header("Location: inspection_add.php?booking_id=".$booking_id."&type=".$type);
                exit;

            } catch (Throwable $ex) {
                $conn->rollback();
                $errors[] = "Failed to update inspection: ".$ex->getMessage();
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
<title>Edit <?= e(ucfirst($type)) ?> Inspection #<?= e($booking_id) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body { background:#f5f7fb; font-family:Arial, sans-serif; margin:0; }
.edit-wrapper { max-width:1080px; margin:38px auto 60px; background:#fff; border-radius:16px; padding:36px 44px 42px; box-shadow:0 6px 28px rgba(80,102,143,.12); border:1px solid #eef1f6; position:relative; }
h1 { margin:0 0 8px; font-size:1.55em; color:#27345d; font-weight:800; }
.sub-note { font-size:.78em; color:#607089; margin-bottom:24px; }
.error-box { background:#fdeaea; color:#b22; padding:12px 18px; border-radius:9px; font-weight:600; margin:0 0 18px; font-size:.9em; }
.flex-row { display:flex; flex-wrap:wrap; gap:20px; margin-bottom:16px; }
.field { flex:1 1 200px; display:flex; flex-direction:column; }
.field label { font-weight:600; font-size:.8em; color:#2f3c5f; margin-bottom:6px; letter-spacing:.5px; }
.field input, .field select, .field textarea { border:1.5px solid #d4dae4; border-radius:8px; padding:9px 12px; font-size:.9em; background:#f9fbfe; }
.field textarea { resize:vertical; min-height:72px; }

.image-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px,1fr)); gap:22px; margin-top:10px; }
.image-slot { border:1.5px solid #d6dce6; border-radius:12px; padding:14px 14px 16px; background:#f8faff; position:relative; display:flex; flex-direction:column; }
.image-slot.required { border-color:#c5d2ff; }
.image-slot h4 { margin:0 0 10px; font-size:.68rem; font-weight:800; color:#2f3d6d; letter-spacing:.6px; text-transform:uppercase; line-height:1.1rem; }
.slot-required-tag { position:absolute; top:6px; right:6px; background:#ffefbd; color:#8a6100; font-size:.52rem; padding:3px 6px; border-radius:6px; font-weight:700; letter-spacing:.5px; }

.thumb-wrap { display:flex; gap:8px; }
.thumb-box { flex:1 1 50%; display:flex; flex-direction:column; gap:4px; }
.thumb-label { font-size:.55rem; font-weight:700; letter-spacing:.4px; color:#59657a; text-transform:uppercase; }
.thumb {
  width:100%; aspect-ratio:4/3; background:#eef2f8; border:1px solid #d4dbe6;
  border-radius:8px; display:flex; align-items:center; justify-content:center;
  overflow:hidden; position:relative; cursor:pointer; font-size:.55rem; color:#6a768a;
}
.thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.thumb.empty { color:#8893a3; cursor:default; }
.image-slot input[type=file] { font-size:.65rem; margin-top:8px; }
.small-hint { margin-top:6px; font-size:.55rem; color:#6c7a8e; line-height:1rem; }

.file-meta { font-size:.5rem; color:#546173; margin-top:2px; word-break:break-all; }
.clear-btn { display:inline-block; margin-top:4px; background:#eee; border:none; font-size:.55rem; padding:4px 8px; border-radius:6px; cursor:pointer; font-weight:600; color:#444; }
.clear-btn:hover { background:#ddd; }

.submit-row { margin-top:28px; display:flex; gap:14px; flex-wrap:wrap; }
.btn {
  border:none; cursor:pointer; font-weight:700; padding:13px 40px; border-radius:9px;
  font-size:.88em; letter-spacing:.4px; display:inline-block; text-decoration:none;
  transition:background .18s, transform .15s;
}
.btn-primary { background:linear-gradient(90deg,#4158d0 0%,#6d8be6 100%); color:#fff; }
.btn-primary:hover { background:linear-gradient(90deg,#2b5cbc 0%,#4158d0 100%); }
.btn-secondary { background:#e0e6ef; color:#28344f; }
.btn-secondary:hover { background:#cfd6e2; }
.btn:active { transform:translateY(1px); }

.damage-panel { margin-top:30px; padding:18px 20px; border:1.5px solid #d9e2ef; background:#f6f9fe; border-radius:14px; }
.damage-panel h3 { margin:0 0 12px; font-size:.95em; font-weight:800; color:#27375f; letter-spacing:.5px; }
.damage-grid { display:flex; flex-wrap:wrap; gap:16px; }
.damage-grid .field { flex:1 1 220px; }
.refund-summary { margin-top:12px; font-size:.7rem; color:#39506d; }
.refund-summary strong { color:#1d3355; }

.lock-note { background:#fff6d8; border:1px solid #f2d78a; padding:10px 14px; border-radius:8px; font-size:.65rem; font-weight:600; color:#8a6d00; margin-top:10px; }

.back-link {
  position:absolute; top:10px; right:16px;
  background:#e0e6ef; color:#24324d; text-decoration:none;
  padding:8px 16px; border-radius:8px; font-size:.7rem;
  font-weight:700; letter-spacing:.4px; transition:background .15s;
}
.back-link:hover { background:#cdd5e2; }

#imgModal { position:fixed; inset:0; background:rgba(18,27,45,.85); display:none; align-items:center; justify-content:center; z-index:9999; padding:40px 26px; }
#imgModal img { max-width:95vw; max-height:85vh; box-shadow:0 8px 28px rgba(0,0,0,.55); border-radius:10px; background:#fff; }
#imgModal .close-btn { position:absolute; top:18px; right:24px; background:#fff; border:none; padding:8px 14px; border-radius:6px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.25); font-size:.8em; }
#imgModal .close-btn:hover { background:#f0f3f9; }

@media (max-width:900px){
  .edit-wrapper { padding:30px 28px 42px; }
  .image-grid { grid-template-columns:repeat(auto-fill, minmax(170px,1fr)); }
  .thumb-wrap { flex-direction:column; }
  .thumb-box { width:100%; }
  .back-link { position:static; display:inline-block; margin-bottom:14px; }
}
</style>
</head>
<body>
<div class="edit-wrapper">
    <a class="back-link" href="inspection_add.php?booking_id=<?= e($booking_id) ?>&type=<?= e($type) ?>">&#8592; Back</a>
    <h1>Edit <?= e(ucfirst($type)) ?> Inspection (Booking #<?= e($booking_id) ?>)</h1>
    <div class="sub-note">
        Replace only the images you need. Required slots must retain an image.
        <?php if ($type === 'return'): ?>
            Damage deduction editing is provided below (if refund not processed).
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

        <div class="flex-row">
            <div class="field">
                <label>Mileage (km)</label>
                <input type="number" name="mileage" min="0" value="<?= e($existing_mileage) ?>" required>
            </div>
            <div class="field">
                <label>Fuel Percent (%)</label>
                <select name="fuel_percent" required>
                    <option value="">Select</option>
                    <?php foreach ([10,20,30,40,50,60,70,80,90,100] as $p): ?>
                        <option value="<?= $p ?>" <?= $p==$existing_fuel?'selected':'' ?>><?= $p ?>%</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Inspection Date</label>
                <input type="datetime-local" name="inspection_date" value="<?= e($default_inspection_dt_input) ?>" required>
            </div>
        </div>

        <div class="image-grid">
            <?php foreach ($slot_definitions as $key => $meta):
                $hasExisting = isset($slotImages[$key]);
                $imgData = $hasExisting ? $slotImages[$key] : null;
                $thumbSrc = $hasExisting
                    ? 'data:image/jpeg;base64,'.base64_encode($imgData['image_path'])
                    : '';
            ?>
            <div class="image-slot <?= $meta['required'] ? 'required' : '' ?>">
                <h4><?= e($meta['label']) ?></h4>
                <?php if ($meta['required']): ?><span class="slot-required-tag">REQUIRED</span><?php endif; ?>
                <div class="thumb-wrap">
                    <div class="thumb-box">
                        <div class="thumb-label">Existing</div>
                        <div class="thumb existing-thumb <?= $hasExisting?'':'empty' ?>"
                             data-full="<?= $hasExisting ? $thumbSrc : '' ?>"
                             title="<?= $hasExisting ? 'Click to enlarge' : 'No image' ?>">
                            <?php if ($hasExisting): ?>
                                <img src="<?= e($thumbSrc) ?>" alt="<?= e($meta['label']) ?>">
                            <?php else: ?>No Image<?php endif; ?>
                        </div>
                    </div>
                    <div class="thumb-box">
                        <div class="thumb-label">New</div>
                        <div class="thumb new-preview empty"
                             data-full=""
                             data-slot-preview="<?= e($key) ?>"
                             title="No new image selected">
                            Select File
                        </div>
                    </div>
                </div>
                <input type="file" name="images[<?= e($key) ?>]" accept="image/*" data-slot-input="<?= e($key) ?>">
                <div class="file-meta" data-file-meta="<?= e($key) ?>"></div>
                <button type="button" class="clear-btn" data-clear="<?= e($key) ?>" style="display:none;">Clear New</button>
                <div class="small-hint">
                    <?= $hasExisting
                        ? 'Leave blank to keep current image.'
                        : ($meta['required'] ? 'Upload required.' : 'Optional.') ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($type === 'return'): ?>
            <div class="damage-panel">
                <h3>Security Deposit Damage Deduction</h3>
                <?php if ($deposit_locked): ?>
                    <div class="lock-note">
                        Deposit refund already processed. Damage deduction is locked (view only).
                    </div>
                <?php endif; ?>
                <div class="damage-grid">
                    <div class="field" style="max-width:220px;">
                        <label>Original Deposit (RM)</label>
                        <input type="text" value="<?= number_format($original_deposit,2) ?>" disabled>
                    </div>
                    <div class="field" style="max-width:220px;">
                        <label>Current Deduction (RM)</label>
                        <input
                            type="number"
                            name="damage_amount"
                            id="damageAmount"
                            step="0.01" min="0"
                            value="<?= number_format($current_deduction,2,'.','') ?>"
                            <?= $deposit_locked ? 'disabled' : '' ?>
                        >
                    </div>
                    <div class="field" style="flex:1 1 300px;">
                        <label>Damage Description</label>
                        <textarea
                            name="damage_description"
                            id="damageDescription"
                            placeholder="Describe damage..."
                            <?= ($deposit_locked || $current_deduction == 0) ? 'disabled' : '' ?>
                        ><?= e($current_damage_desc) ?></textarea>
                    </div>
                    <div class="field" style="max-width:220px;">
                        <label>Current Refundable (RM)</label>
                        <input type="text" id="refundEstimate" value="<?= number_format($current_refund,2) ?>" disabled>
                    </div>
                    <div class="field" style="max-width:220px;">
                        <label>Deposit Status</label>
                        <input type="text" value="<?= e($current_deposit_status) ?>" disabled>
                    </div>
                </div>
                <div class="refund-summary" id="forfeitNote" style="<?= ($current_deduction >= $original_deposit && $original_deposit>0)?'':'display:none;' ?>">
                    <strong>Note:</strong> Deduction equals / exceeds deposit → deposit forfeited (no refund).
                </div>
                <?php if ($deposit_locked): ?>
                    <div style="margin-top:10px;font-size:.65rem;color:#5a6474;">
                        To adjust deposit after processing, create a manual adjustment via finance (if policy permits).
                    </div>
                <?php else: ?>
                    <div style="margin-top:10px;font-size:.65rem;color:#5a6474;">
                        Set deduction to 0 for full refund. Increasing will reduce refundable amount.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="field" style="margin-top:26px;">
            <label>Remarks (overall inspection)</label>
            <textarea name="remarks" placeholder="Update condition, damages, accessories, etc."><?= e($existing_remarks) ?></textarea>
        </div>

        <div class="submit-row">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="inspection_add.php?booking_id=<?= e($booking_id) ?>&type=<?= e($type) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<!-- Modal -->
<div id="imgModal">
    <button type="button" class="close-btn" id="closeModalBtn">Close (Esc)</button>
    <img id="modalImg" src="" alt="Full Image">
</div>

<script>
(function(){
  const MAX = 6 * 1024 * 1024;

  function bytesToKB(b){ return Math.round(b/1024); }

  function setPreview(slot, file){
    const preview = document.querySelector('[data-slot-preview="'+slot+'"]');
    const meta = document.querySelector('[data-file-meta="'+slot+'"]');
    const clearBtn = document.querySelector('[data-clear="'+slot+'"]');
    if (!preview) return;
    if (!file){
      preview.classList.add('empty');
      preview.innerHTML = 'Select File';
      preview.removeAttribute('data-full');
      preview.setAttribute('title','No new image selected');
      if (meta) meta.textContent = '';
      if (clearBtn) clearBtn.style.display = 'none';
      return;
    }
    const reader = new FileReader();
    reader.onload = function(ev){
      preview.classList.remove('empty');
      preview.innerHTML = '<img src="'+ev.target.result+'" alt="New Preview">';
      preview.setAttribute('data-full', ev.target.result);
      preview.setAttribute('title','Click to enlarge new image');
      if (meta) meta.textContent = file.name + ' (' + bytesToKB(file.size) + ' KB)';
      if (clearBtn) clearBtn.style.display = 'inline-block';
    };
    reader.readAsDataURL(file);
  }

  document.addEventListener('change', function(e){
    if (e.target.matches('input[type=file][data-slot-input]')) {
      const slot = e.target.getAttribute('data-slot-input');
      const file = e.target.files[0];
      if (!file){
        setPreview(slot, null);
        return;
      }
      if (file.size > MAX){
        alert('File exceeds 6MB limit.');
        e.target.value = '';
        setPreview(slot, null);
        return;
      }
      setPreview(slot, file);
    }
  });

  document.addEventListener('click', function(e){
    if (e.target.matches('[data-clear]')) {
      const slot = e.target.getAttribute('data-clear');
      const input = document.querySelector('input[data-slot-input="'+slot+'"]');
      if (input) {
        input.value = '';
        setPreview(slot, null);
      }
    }
  });

  /* Modal for thumbnails */
  const modal = document.getElementById('imgModal');
  const modalImg = document.getElementById('modalImg');
  const closeBtn = document.getElementById('closeModalBtn');

  function openModal(src){
    if (!src) return;
    modalImg.src = src;
    modal.style.display='flex';
  }
  function closeModal(){
    modal.style.display='none';
    modalImg.src='';
  }

  document.addEventListener('click', e=>{
    const thumb = e.target.closest('.thumb');
    if (thumb && !thumb.classList.contains('empty')) {
      const full = thumb.getAttribute('data-full');
      if (full) openModal(full);
    } else if (e.target === modal || e.target === closeBtn) {
      closeModal();
    }
  });
  document.addEventListener('keyup', e=>{
    if (e.key === 'Escape' && modal.style.display==='flex') closeModal();
  });

  /* Damage panel dynamic (return only) */
  const dmgInput = document.getElementById('damageAmount');
  const dmgDesc  = document.getElementById('damageDescription');
  const refundEst= document.getElementById('refundEstimate');
  const forfeit  = document.getElementById('forfeitNote');
  const origDepEl= document.querySelector('.damage-panel #origDeposit') || document.getElementById('origDeposit');
  if (dmgInput && refundEst && origDepEl) {
    const orig = parseFloat(origDepEl.textContent || '0');
    const locked = dmgInput.hasAttribute('disabled') && !dmgInput.value; // not perfect but okay
    function recalc(){
      if (dmgInput.disabled) return;
      let v = parseFloat(dmgInput.value||'0');
      if (isNaN(v) || v < 0) v = 0;
      if (v === 0){
        dmgDesc.disabled = true;
        dmgDesc.style.background = '#f0f3f7';
        dmgDesc.value = '';
        if (forfeit) forfeit.style.display='none';
      } else {
        if (!locked) {
          dmgDesc.disabled = false;
          dmgDesc.style.background = '#ffffff';
        }
      }
      if (v >= orig && orig > 0){
        refundEst.value = '0.00';
        if (forfeit) forfeit.style.display='block';
      } else {
        refundEst.value = (orig - v).toFixed(2);
        if (forfeit) forfeit.style.display='none';
      }
    }
    dmgInput.addEventListener('input', recalc);
    recalc();
  }
})();
</script>
<?php include '../includes/footer.php'; ?>
</body>
</html>