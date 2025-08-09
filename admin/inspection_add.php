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

/*
 * inspection_add.php
 * Features:
 *  - Pickup / Return inspection creation (read-only if already filled)
 *  - Preview thumbnails before upload
 *  - Edit Inspection button (links to edit_inspection.php)
 *  - Return inspection includes Damage Assessment to deduct from security deposit
 *  - On Return: computes deduction + refundable amount, updates booking deposit fields
 *  - Creates / updates / cancels a deposit refund row in refunds table (reference_code = 'DEP-{booking_id}')
 *  - refunds table schema (as provided):
 *        refund_id, booking_id, cust_id, amount, refund_status (pending|processed|failed|cancelled),
 *        reference_code, created_at, processed_at, notes, user_unread, refund_rate, base_amount
 *
 * Required new booking columns (run once if not present):
 *  ALTER TABLE booking
 *    ADD COLUMN security_deposit_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER security_deposit,
 *    ADD COLUMN security_deposit_refund DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER security_deposit_deduction,
 *    ADD COLUMN deposit_status ENUM('held','pending_refund','refunded','forfeited') NOT NULL DEFAULT 'held' AFTER security_deposit_refund,
 *    ADD COLUMN deposit_last_adjusted_at DATETIME NULL AFTER deposit_status,
 *    ADD COLUMN deposit_damage_description TEXT NULL AFTER deposit_last_adjusted_at;
 */

function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/* CSRF */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verify_csrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Upsert security deposit refund record using refunds table.
 *
 * Identifies the deposit refund row by reference_code = 'DEP-{booking_id}'.
 * If refundable = 0 => cancels any pending/failed row (deposit forfeited).
 *
 * @param mysqli $conn
 * @param int    $booking_id
 * @param int    $cust_id
 * @param float  $originalDeposit
 * @param float  $deduction
 * @param float  $refundable
 * @param string $damageDesc
 */
function upsert_deposit_refund(mysqli $conn, int $booking_id, int $cust_id, float $originalDeposit, float $deduction, float $refundable, string $damageDesc): void
{
    $refCode = 'DEP-' . $booking_id;

    // If no refundable amount (forfeited), cancel existing pending/failed deposit refund rows
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

    // Compute fraction refunded
    $refundRate = $originalDeposit > 0 ? $refundable / $originalDeposit : 0.0;
    $base_amount = $originalDeposit; // use deposit as base
    $notes = $deduction > 0
        ? 'Security Deposit Refund after deduction RM '.number_format($deduction,2)
        : 'Security Deposit Refund';

    if ($damageDesc && $deduction > 0) {
        $notes .= ' - ' . mb_substr($damageDesc, 0, 100);
    }

    // Check for existing row
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
        // If already processed, do not alter (protect integrity); else update
        if ($existing['refund_status'] === 'processed') {
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
        $upd->bind_param('dsddi', $refundable, $notes, $refundRate, $base_amount, $existing['refund_id']);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("
            INSERT INTO refunds (booking_id, cust_id, amount, refund_status, reference_code, notes,
                                 refund_rate, base_amount, created_at, user_unread)
            VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), 1)
        ");
        $ins->bind_param('iisssdd', $booking_id, $cust_id, $refundable, $refCode, $notes, $refundRate, $base_amount);
        $ins->execute();
        $ins->close();
    }
}

/* Input params */
$booking_id = isset($_GET['booking_id']) && ctype_digit($_GET['booking_id'])
    ? (int)$_GET['booking_id'] : 0;

$allowed_capture = ['pickup','return'];
$type = isset($_GET['type']) && in_array(strtolower($_GET['type']), $allowed_capture, true)
    ? strtolower($_GET['type']) : 'pickup';

if ($booking_id <= 0) {
    echo "<p>Invalid booking ID.</p>";
    include '../includes/footer.php';
    exit;
}

/* Fetch booking & customer id for refunds */
$stmt = $conn->prepare("
    SELECT b.*, c.car_brand, c.car_model, c.plate_no, c.mileage AS car_mileage, c.car_id,
           cust.cust_id
      FROM booking b
      JOIN car c   ON b.car_id = c.car_id
 LEFT JOIN customer cust ON b.cust_id = cust.cust_id
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

$car_id = (int)$booking['car_id'];
$current_car_mileage = (int)$booking['car_mileage'];
$cust_id = (int)($booking['cust_id'] ?? 0);

/* Existing images for chosen type */
$imgStmt = $conn->prepare("
    SELECT booking_image_id, image_path, image_type, capture_type, uploaded_at, inspection_date
      FROM booking_image
     WHERE booking_id=? AND capture_type=?
     ORDER BY uploaded_at ASC
");
$imgStmt->bind_param("is", $booking_id, $type);
$imgStmt->execute();
$images = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$imgStmt->close();

/* Determine if inspection already filled */
if ($type === 'pickup') {
    $is_filled = !empty($booking['pickup_mileage'])
        && $booking['pickup_fuel_percent'] !== null
        && !empty($booking['pickup_datetime'])
        && count($images) > 0;
    $booking_level_remarks = $booking['pickup_inspection_remarks'] ?? '';
} else {
    $is_filled = !empty($booking['return_mileage'])
        && $booking['return_fuel_percent'] !== null
        && !empty($booking['return_datetime'])
        && count($images) > 0;
    $booking_level_remarks = $booking['return_inspection_remarks'] ?? '';
}

$inspection_date_display = $images ? ($images[0]['inspection_date'] ?? '') : '';

/* Slots */
$slot_definitions = [
    'car_front'       => ['label'=>'Car Front',       'required'=>true],
    'car_back'        => ['label'=>'Car Back',        'required'=>true],
    'car_left'        => ['label'=>'Car Left Side',   'required'=>true],
    'car_right'       => ['label'=>'Car Right Side',  'required'=>true],
    'fuel_image'      => ['label'=>'Fuel Gauge',      'required'=>true],
    'additional_image'=> ['label'=>'Additional',      'required'=>false],
];

$errors = [];
$damage_amount = 0.00;
$damage_description = '';

/* Handle POST (create only, not edits) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_filled) {
    if (!verify_csrf()) {
        $errors[] = "Invalid session token.";
    } else {
        $mileage = isset($_POST['mileage']) ? (int)$_POST['mileage'] : 0;
        $fuel_percent = isset($_POST['fuel_percent']) ? (int)$_POST['fuel_percent'] : -1;
        $inspection_date_raw = $_POST['inspection_date'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');

        /* Damage logic only for return */
        if ($type === 'return') {
            $damage_amount_raw = $_POST['damage_amount'] ?? '0';
            $damage_description = trim($_POST['damage_description'] ?? '');

            if ($damage_amount_raw === '' || !is_numeric($damage_amount_raw)) {
                $errors[] = "Damage amount must be numeric.";
            } else {
                $damage_amount = round((float)$damage_amount_raw, 2);
                if ($damage_amount < 0) {
                    $errors[] = "Damage amount cannot be negative.";
                    $damage_amount = 0.00;
                }
                $originalDeposit = (float)$booking['security_deposit'];
                if ($damage_amount > $originalDeposit) {
                    // Cap to deposit
                    $damage_amount = $originalDeposit;
                }
                if ($damage_amount > 0 && $damage_description === '') {
                    $errors[] = "Provide a damage description when damage amount > 0.";
                }
                if ($damage_amount === 0) {
                    $damage_description = '';
                }
            }
        }

        /* Basic validations */
        if ($mileage < 0) $errors[] = "Mileage cannot be negative.";
        if ($fuel_percent < 0 || $fuel_percent > 100) $errors[] = "Fuel percent must be between 0 and 100.";
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $inspection_date_raw)) {
            $errors[] = "Invalid inspection date format.";
        }
        $inspection_date_sql = $inspection_date_raw
            ? str_replace('T',' ',$inspection_date_raw).":00"
            : date('Y-m-d H:i:s');

        if ($type === 'return'
            && !empty($booking['pickup_mileage'])
            && $mileage < (int)$booking['pickup_mileage']
        ) {
            $errors[] = "Return mileage cannot be less than pickup mileage (".(int)$booking['pickup_mileage'].").";
        }

        /* Files */
        $allowed_mime = ['image/jpeg','image/png','image/jpg'];
        $max_size = 6 * 1024 * 1024;
        $images_to_insert = [];

        foreach ($slot_definitions as $key => $meta) {
            $filePresent = !empty($_FILES['images']['name'][$key]);
            if ($meta['required'] && !$filePresent) {
                $errors[] = "Missing required image: ".$meta['label'];
                continue;
            }
            if ($filePresent) {
                $err  = $_FILES['images']['error'][$key];
                $mime = $_FILES['images']['type'][$key] ?? '';
                $size = $_FILES['images']['size'][$key] ?? 0;
                $tmp  = $_FILES['images']['tmp_name'][$key] ?? '';
                if ($err !== UPLOAD_ERR_OK) { $errors[] = "Upload error for ".$meta['label']."."; continue; }
                if (!in_array($mime, $allowed_mime, true)) { $errors[] = $meta['label']." has unsupported file type."; continue; }
                if ($size <= 0 || $size > $max_size) { $errors[] = $meta['label']." exceeds 6MB limit."; continue; }
                $blob = file_get_contents($tmp);
                if ($blob === false) { $errors[] = "Failed to read file for ".$meta['label']."."; continue; }
                $images_to_insert[] = [
                    'blob'       => $blob,
                    'image_type' => $key
                ];
            }
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                /* Update booking core fields */
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

                    if ($mileage > $current_car_mileage) {
                        $stmt = $conn->prepare("UPDATE car SET mileage=? WHERE car_id=?");
                        $stmt->bind_param('ii', $mileage, $car_id);
                        $stmt->execute();
                        $stmt->close();
                    }

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

                    if ($mileage > $current_car_mileage) {
                        $stmt = $conn->prepare("UPDATE car SET mileage=? WHERE car_id=?");
                        $stmt->bind_param('ii', $mileage, $car_id);
                        $stmt->execute();
                        $stmt->close();
                    }

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

                /* Insert inspection images */
                $imgInsert = $conn->prepare("
                    INSERT INTO booking_image
                        (booking_id, image_path, image_type, capture_type, inspection_date, uploaded_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                foreach ($images_to_insert as $imgData) {
                    $blob = $imgData['blob'];
                    $img_type = $imgData['image_type'];
                    $imgInsert->bind_param(
                        'ibsss',
                        $booking_id,
                        $blob,
                        $img_type,
                        $type,
                        $inspection_date_sql
                    );
                    $imgInsert->send_long_data(1, $blob);
                    $imgInsert->execute();
                }
                $imgInsert->close();

                /* Deposit logic for return */
                if ($type === 'return') {
                    $originalDeposit = (float)$booking['security_deposit'];
                    $deduction  = $damage_amount;
                    $refundable = max($originalDeposit - $deduction, 0);

                    $depositStatus = ($deduction >= $originalDeposit && $originalDeposit > 0)
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
                    $stmt->bind_param('ddssi', $deduction, $refundable, $depositStatus, $damage_description, $booking_id);
                    $stmt->execute();
                    $stmt->close();

                    if ($cust_id > 0) {
                        upsert_deposit_refund($conn, $booking_id, $cust_id, $originalDeposit, $deduction, $refundable, $damage_description);
                    }
                }

                $conn->commit();

                $_SESSION['success'] = ucfirst($type) . " inspection saved.";
                header("Location: inspection_add.php?booking_id=".$booking_id."&type=".$type);
                exit;

            } catch (Throwable $ex) {
                $conn->rollback();
                $errors[] = "Failed to save inspection: " . $ex->getMessage();
            }
        }
    }
}

$display_remarks = $is_filled ? $booking_level_remarks : '';

include 'admin_header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background:#f8f9fb; }
.inspection-card { max-width:1060px; margin:38px auto 40px; background:#fff; border-radius:16px; box-shadow:0 6px 28px #d3d8ef44; padding:36px 42px 34px; border:1px solid #f1f2f8; position:relative;}
.back-link {
  position:absolute; top:10px; right:16px;
  background:#e0e6ef; color:#24324d; text-decoration:none;
  padding:8px 16px; border-radius:8px; font-size:.7rem;
  font-weight:700; letter-spacing:.4px; transition:background .15s;
}
.back-link:hover { background:#cdd5e2; }
.inspection-title { font-size:1.32em; font-weight:800; color:#2d397c; margin:0 0 20px; letter-spacing:.5px; }
.section-sub { font-size:.8em; color:#5d6a85; margin:-6px 0 18px; }
.edit-btn {
  background:#355adf; color:#fff; text-decoration:none; padding:10px 22px;
  border-radius:8px; font-size:.72rem; font-weight:700; letter-spacing:.5px;
  display:inline-block; margin:8px 0 16px; transition:background .18s;
}
.edit-btn:hover { background:#254abf; }
.flex-row { display:flex; flex-wrap:wrap; gap:18px; margin-bottom:18px; }
.field { flex:1 1 180px; display:flex; flex-direction:column; }
.field label { font-weight:600; font-size:.83em; margin-bottom:6px; color:#394569; letter-spacing:.4px; }
.field input, .field select, .field textarea {
  border:1.5px solid #d6d9e4; border-radius:7px; padding:8px 12px; font-size:.92em; background:#f8fafe;
}
.field textarea { resize:vertical; min-height:72px; }

.image-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px,1fr)); gap:20px; margin-top:8px; }
.image-slot { border:1.5px solid #d6d9e4; border-radius:12px; padding:14px 14px 16px; background:#f9fbfe; position:relative; display:flex; flex-direction:column; }
.image-slot.required { border-color:#c5d2ff; }
.image-slot h4 { margin:0 0 10px; font-size:.68rem; font-weight:800; color:#2f3d6d; letter-spacing:.6px; text-transform:uppercase; line-height:1.1rem; }
.slot-required-tag { position:absolute; top:6px; right:6px; background:#ffefbd; color:#8a6100; font-size:.52rem; padding:3px 6px; border-radius:6px; font-weight:700; letter-spacing:.5px; }
.image-slot input[type=file] { font-size:.65rem; }

.preview-box {
  margin-top:10px; width:100%; aspect-ratio:4/3;
  background:#eef2f8; border:1px solid #d4dbe6; border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  font-size:.6rem; color:#6a768a; overflow:hidden; position:relative;
  cursor:pointer;
}
.preview-box img { width:100%; height:100%; object-fit:cover; display:none; }
.preview-box.has-image img { display:block; }
.preview-box .placeholder-text { padding:4px 6px; text-align:center; }
.preview-filename { margin-top:6px; font-size:.55rem; color:#58657c; word-break:break-all; line-height:.9rem; min-height:1rem; }

.pickup-images-row { display:flex; gap:18px; flex-wrap:wrap; margin-top:18px; }
.pickup-image-box { border:1.5px solid #d6d9e4; border-radius:8px; padding:10px 12px; background:#f8faff; text-align:center; width:130px; }
.pickup-image-box img { width:100%; height:78px; object-fit:cover; border-radius:6px; border:1px solid #dfe4ee; background:#fff; cursor:pointer; }
.pickup-image-label { font-size:.7rem; color:#4d5990; margin-top:6px; font-weight:600; letter-spacing:.4px; }

.flash-success { background:#eafdeb; color:#218c3b; padding:12px 18px; border-radius:8px; font-weight:600; margin:0 0 18px; }
.error-box { background:#fdeaea; color:#b22; padding:12px 18px; border-radius:8px; font-weight:600; margin:0 0 18px; font-size:.9em; }

.submit-btn {
  background:linear-gradient(90deg,#4158d0 0%,#6d8be6 100%);
  color:#fff; border:none; padding:14px 46px; border-radius:9px;
  font-weight:700; font-size:.95em; margin-top:26px; box-shadow:0 3px 12px #b5bee555;
  cursor:pointer; transition:background .18s;
}
.submit-btn:hover { background:linear-gradient(90deg,#2b5cbc 0%,#4158d0 100%); }

.damage-panel h4 { font-weight:800; }

@media (max-width:920px){
  .inspection-card { padding:28px 24px 36px; }
  .image-grid { grid-template-columns:repeat(auto-fill, minmax(150px,1fr)); gap:16px; }
  .back-link { position:static; display:inline-block; margin-bottom:14px; }
}
#imgModal { position:fixed; inset:0; background:rgba(18,27,45,.85); display:none; align-items:center; justify-content:center; z-index:9999; padding:40px 26px; }
#imgModal img { max-width:95vw; max-height:85vh; box-shadow:0 8px 28px rgba(0,0,0,.55); border-radius:10px; background:#fff; }
#imgModal .close-btn { position:absolute; top:18px; right:24px; background:#fff; border:none; padding:8px 14px; border-radius:6px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.25); font-size:.8em; }
#imgModal .close-btn:hover { background:#f0f3f9; }
</style>

<div class="inspection-card">
    <a class="back-link" href="booking_details.php?id=<?= e($booking_id) ?>">&#8592; Back to Booking</a>

    <div class="inspection-title">
        <?= ucfirst($type) ?> Inspection &mdash; <?= e($booking['car_brand'].' '.$booking['car_model']) ?> (<?= e($booking['plate_no']) ?>)
    </div>
    <div class="section-sub">
        Required images: Front, Back, Left, Right, Fuel Gauge. Click previews to enlarge.
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="flash-success"><?= e($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($is_filled): ?>
        <a href="edit_inspection.php?booking_id=<?= e($booking_id) ?>&type=<?= e($type) ?>" class="edit-btn">
            Edit Inspection
        </a>

        <div style="font-weight:700;color:#2d397c;margin-top:12px;">Mileage (km):</div>
        <div><?= e($type === 'pickup' ? $booking['pickup_mileage'] : $booking['return_mileage']) ?></div>

        <div style="font-weight:700;color:#2d397c;margin-top:12px;">Fuel Percent (%):</div>
        <div><?= e($type === 'pickup' ? $booking['pickup_fuel_percent'] : $booking['return_fuel_percent']) ?></div>

        <div style="font-weight:700;color:#2d397c;margin-top:12px;">Inspection Date:</div>
        <div><?= $inspection_date_display ? e($inspection_date_display) : '-' ?></div>

        <?php if ($display_remarks): ?>
            <div style="font-weight:700;color:#2d397c;margin-top:12px;">Remarks:</div>
            <div><?= nl2br(e($display_remarks)) ?></div>
        <?php endif; ?>

        <?php if ($type === 'return' && isset($booking['security_deposit'])): ?>
            <div style="font-weight:700;color:#2d397c;margin-top:18px;">Security Deposit Summary</div>
            <div style="font-size:.8rem;line-height:1.1rem;margin-top:4px;">
                Original: RM <?= number_format((float)$booking['security_deposit'],2) ?><br>
                Deduction: RM <?= number_format((float)($booking['security_deposit_deduction'] ?? 0),2) ?><br>
                Refundable: RM <?= number_format((float)($booking['security_deposit_refund'] ?? max(((float)$booking['security_deposit']) - (float)($booking['security_deposit_deduction'] ?? 0),0)),2) ?><br>
                Status: <?= e($booking['deposit_status'] ?? 'held') ?><br>
                <?php if (!empty($booking['deposit_damage_description'])): ?>
                    Damage: <?= nl2br(e($booking['deposit_damage_description'])) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="font-weight:700;color:#2d397c;margin-top:14px;">Images</div>
        <div class="pickup-images-row">
            <?php foreach ($images as $img): ?>
                <div class="pickup-image-box">
                    <img
                        src="data:image/jpeg;base64,<?= base64_encode($img['image_path']) ?>"
                        alt="Inspection Image"
                        data-full="data:image/jpeg;base64,<?= base64_encode($img['image_path']) ?>">
                    <div class="pickup-image-label">
                        <?= e($img['image_type']) ?><br><?= e($img['capture_type']) ?>
                    </div>
                    <div style="font-size:.55rem;color:#889;margin-top:4px;"><?= e($img['uploaded_at']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="flex-row">
                <div class="field">
                    <label>Mileage (km)</label>
                    <input type="number" name="mileage" min="0" value="<?= (int)$current_car_mileage ?>" required>
                </div>
                <div class="field">
                    <label>Fuel Percent (%)</label>
                    <select name="fuel_percent" required>
                        <option value="">Select</option>
                        <?php foreach ([10,20,30,40,50,60,70,80,90,100] as $p): ?>
                            <option value="<?= $p ?>"><?= $p ?>%</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Inspection Date</label>
                    <input type="datetime-local" name="inspection_date" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
                </div>
            </div>

            <div class="image-grid">
                <?php foreach ($slot_definitions as $key => $meta): ?>
                    <div class="image-slot <?= $meta['required'] ? 'required' : '' ?>">
                        <h4><?= e($meta['label']) ?></h4>
                        <?php if ($meta['required']): ?>
                            <span class="slot-required-tag">REQUIRED</span>
                        <?php endif; ?>
                        <input
                            type="file"
                            name="images[<?= e($key) ?>]"
                            accept="image/*"
                            <?= $meta['required'] ? 'required' : '' ?>
                            data-slot="<?= e($key) ?>"
                        >
                        <div class="preview-box" data-preview-box="<?= e($key) ?>" title="Click to enlarge">
                            <span class="placeholder-text">No Image</span>
                            <img alt="<?= e($meta['label']) ?>">
                        </div>
                        <div class="preview-filename" data-filename="<?= e($key) ?>"></div>
                        <div style="margin-top:6px;font-size:.52rem;color:#77849b;line-height:1.05rem;">
                            <?= $meta['required'] ? 'Must provide this view.' : 'Optional view.' ?><br>
                            JPG / PNG up to 6MB.
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($type === 'return'): ?>
                <!-- Damage Assessment Panel -->
                <div class="damage-panel" style="margin-top:26px;padding:16px 18px;border:1.5px solid #d7dfec;border-radius:12px;background:#f6f9fe;">
                    <h4 style="margin:0 0 10px;font-size:.9em;font-weight:800;color:#2d3c65;letter-spacing:.5px;">Security Deposit Damage Assessment</h4>
                    <div style="font-size:.7rem;color:#4d5a72;margin-bottom:10px;">
                        Original Security Deposit: RM <strong id="origDeposit"><?= number_format((float)$booking['security_deposit'],2) ?></strong>
                    </div>
                    <div class="flex-row" style="gap:14px;">
                        <div class="field" style="max-width:200px;">
                            <label style="font-size:.72rem;">Damage Amount (RM)</label>
                            <input type="number" name="damage_amount" id="damageAmount" step="0.01" min="0" value="0.00" style="font-size:.82rem;">
                        </div>
                        <div class="field" style="flex:1 1 auto;">
                            <label style="font-size:.72rem;">Damage Description (only if amount > 0)</label>
                            <textarea name="damage_description" id="damageDescription" placeholder="Describe damage..." style="font-size:.75rem;min-height:60px;" disabled></textarea>
                        </div>
                    </div>
                    <div style="margin-top:12px;font-size:.68rem;color:#39506d;">
                        Refund Estimate: RM <span id="refundEstimate"><?= number_format((float)$booking['security_deposit'],2) ?></span>
                        <span id="forfeitNote" style="display:none;color:#b63030;font-weight:600;margin-left:8px;">(Deposit Fully Forfeited)</span>
                    </div>
                    <div style="margin-top:6px;font-size:.62rem;color:#65748b;line-height:1.1rem;">
                        Set damage amount to 0 for full refund. Any positive amount deducts from the deposit. If amount equals or exceeds deposit, the deposit is fully forfeited.
                    </div>
                </div>
            <?php endif; ?>

            <div class="field" style="margin-top:24px;">
                <label>Remarks (overall inspection)</label>
                <textarea name="remarks" placeholder="Describe condition, damages, accessories, cleanliness, etc."></textarea>
            </div>

            <button type="submit" class="submit-btn">Submit Inspection</button>
        </form>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="imgModal">
    <button type="button" class="close-btn" id="closeModalBtn">Close (Esc)</button>
    <img id="modalImg" src="" alt="Full Image">
</div>

<script>
/* Image previews */
document.addEventListener('change', function(e){
    if (e.target.matches('input[type=file][data-slot]')) {
        const file = e.target.files[0];
        const slot = e.target.getAttribute('data-slot');
        const box = document.querySelector('[data-preview-box="'+slot+'"]');
        const img = box ? box.querySelector('img') : null;
        const placeholder = box ? box.querySelector('.placeholder-text') : null;
        const fnLabel = document.querySelector('[data-filename="'+slot+'"]');
        const max = 6 * 1024 * 1024;

        if (!file) {
            if (box) {
                box.classList.remove('has-image');
                box.removeAttribute('data-full');
                if (placeholder) placeholder.style.display='block';
                if (img) img.style.display='none';
            }
            if (fnLabel) fnLabel.textContent = '';
            return;
        }
        if (file.size > max) {
            alert('File exceeds 6MB limit.');
            e.target.value = '';
            if (box) {
                box.classList.remove('has-image');
                box.removeAttribute('data-full');
                if (placeholder) placeholder.style.display='block';
                if (img) img.style.display='none';
            }
            if (fnLabel) fnLabel.textContent = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function(ev){
            if (box && img) {
                img.src = ev.target.result;
                box.classList.add('has-image');
                box.setAttribute('data-full', ev.target.result);
                if (placeholder) placeholder.style.display='none';
                img.style.display='block';
            }
        };
        reader.readAsDataURL(file);

        if (fnLabel) {
            fnLabel.textContent = file.name + ' (' + Math.round(file.size/1024) + ' KB)';
        }
    }
});

/* Modal viewer */
(function(){
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
        if (e.target.matches('.pickup-image-box img')) {
            openModal(e.target.getAttribute('data-full') || e.target.src);
        } else if (e.target.closest('.preview-box')) {
            const box = e.target.closest('.preview-box');
            const full = box.getAttribute('data-full');
            if (full) openModal(full);
        } else if (e.target === modal || e.target === closeBtn) {
            closeModal();
        }
    });

    document.addEventListener('keyup', e=>{
        if (e.key === 'Escape' && modal.style.display==='flex') {
            closeModal();
        }
    });
})();

/* Damage panel (return only) */
document.addEventListener('DOMContentLoaded', function(){
    const damAmt = document.getElementById('damageAmount');
    const damDesc = document.getElementById('damageDescription');
    const refundEst = document.getElementById('refundEstimate');
    const forfeitNote = document.getElementById('forfeitNote');
    const origEl = document.getElementById('origDeposit');
    if (!damAmt || !origEl) return;
    const orig = parseFloat(origEl.textContent || '0');

    function recalc(){
        let v = parseFloat(damAmt.value||'0');
        if (isNaN(v) || v < 0) v = 0;
        if (v === 0){
            damDesc.disabled = true;
            damDesc.style.background = '#f0f3f7';
            damDesc.value = '';
            forfeitNote.style.display = 'none';
        } else {
            damDesc.disabled = false;
            damDesc.style.background = '#ffffff';
        }
        if (v >= orig){
            refundEst.textContent = '0.00';
            forfeitNote.style.display = 'inline';
        } else {
            refundEst.textContent = (orig - v).toFixed(2);
            forfeitNote.style.display = 'none';
        }
    }
    damAmt.addEventListener('input', recalc);
    recalc();
});
</script>

<?php include '../includes/footer.php'; ?>