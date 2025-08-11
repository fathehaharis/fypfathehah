<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

require '../connect.php';
include '../includes/header.php';

$cust_id = (int)$_SESSION['cust_id'];

$success          = false;
$error            = '';
$updatedImages    = [];

/* CONFIG */
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/* ---------- Helper: Detect MIME ---------- */
function detectMimeType(string $path): string {
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($path);
        if ($m) return $m;
    }
    if (function_exists('finfo_open')) {
        $f = @finfo_open(FILEINFO_MIME_TYPE);
        if ($f) {
            $m = @finfo_file($f, $path);
            @finfo_close($f);
            if ($m) return $m;
        }
    }
    if (function_exists('exif_imagetype')) {
        $type = @exif_imagetype($path);
        $map = [
            IMAGETYPE_GIF  => 'image/gif',
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
            IMAGETYPE_BMP  => 'image/bmp',
            IMAGETYPE_WBMP => 'image/vnd.wap.wbmp'
        ];
        if ($type && isset($map[$type])) return $map[$type];
    }
    if (function_exists('getimagesize')) {
        $info = @getimagesize($path);
        if (!empty($info['mime'])) return $info['mime'];
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match($ext) {
        'jpg','jpeg' => 'image/jpeg',
        'png'        => 'image/png',
        'gif'        => 'image/gif',
        'webp'       => 'image/webp',
        'bmp'        => 'image/bmp',
        default      => 'application/octet-stream',
    };
}

/* ---------- Formatting Helpers ---------- */
function format_nric_display(?string $digits): string {
    $digits = preg_replace('/\D+/', '', $digits ?? '');
    if (strlen($digits) !== 12) return $digits;
    return substr($digits,0,6) . '-' . substr($digits,6,2) . '-' . substr($digits,8,4);
}
function format_phone_display(?string $digits): string {
    $digits = preg_replace('/\D+/', '', $digits ?? '');
    if (strpos($digits,'01') !== 0) return $digits;
    $len = strlen($digits);
    if ($len === 10 || $len === 11)
        return substr($digits,0,3) . '-' . substr($digits,3);
    return $digits;
}

/* ---------- Fetch original record BEFORE POST ---------- */
$stmt = $conn->prepare(
    "SELECT full_name, phone_no, email, username, id_no,
            id_front_image, id_back_image, license_front_image, license_back_image,
            address, age, images_version
     FROM customer
     WHERE cust_id=? LIMIT 1"
);
$stmt->bind_param("i",$cust_id);
$stmt->execute();
$res = $stmt->get_result();
$originalUser = $res->fetch_assoc();
$stmt->close();

if (!$originalUser) {
    echo "<p>User not found.</p>";
    include '../includes/footer.php';
    exit;
}

$currentImagesVersion   = (int)$originalUser['images_version'];

/* ---------- Process POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name_raw = trim($_POST['full_name'] ?? '');
    $username_raw  = trim($_POST['username'] ?? '');
    $email_raw     = trim($_POST['email'] ?? '');
    $phone_raw     = trim($_POST['phone_no'] ?? '');
    $id_raw        = trim($_POST['id_no'] ?? '');
    $address_raw   = trim($_POST['address'] ?? '');
    $age_form      = (int)($_POST['age'] ?? 0);

    $email        = strtolower($email_raw);
    $phone_digits = preg_replace('/\D+/', '', $phone_raw);
    $id_digits    = preg_replace('/\D+/', '', $id_raw);

    // Basic validation
    if ($full_name_raw==='' || $username_raw==='' || $email==='' ||
        $phone_digits==='' || $id_digits==='' || $address_raw==='') {
        $error = "All fields are required.";
    }
    if (!$error && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    }
    if (!$error && !preg_match('/^01\d{8,9}$/', $phone_digits)) {
        $error = "Invalid Malaysian mobile number (must start with 01 and be 10 or 11 digits).";
    }
    if (!$error && (!ctype_digit($id_digits) || strlen($id_digits)!==12)) {
        $error = "Invalid NRIC / ID (must be exactly 12 digits).";
    }

    // Derive age from NRIC (automatic)
    $age = $age_form;
    if (!$error && strlen($id_digits) === 12) {
        $yy = substr($id_digits,0,2);
        $mm = substr($id_digits,2,2);
        $dd = substr($id_digits,4,2);
        $currentYY = (int)date('y');
        $century   = ((int)$yy <= $currentYY) ? '20' : '19';
        $yearFull  = (int)($century.$yy);
        if (checkdate((int)$mm,(int)$dd,$yearFull)) {
            $calcAge = (int)date('Y') - $yearFull;
            $bdayThisYear = DateTime::createFromFormat('Y-m-d', date('Y')."-{$mm}-{$dd}");
            if ($bdayThisYear && new DateTime() < $bdayThisYear) $calcAge--;
            if ($calcAge >= 0 && $calcAge < 130) $age = $calcAge;
        }
    }

    if (!$error) {

        // Fields to update
        $fields = [
            'full_name' => $full_name_raw,
            'username'  => $username_raw,
            'email'     => $email,
            'phone_no'  => $phone_digits,
            'id_no'     => $id_digits,
            'address'   => $address_raw,
            'age'       => $age
        ];

        $types = '';
        $params = [];
        $sets = [];

        foreach ($fields as $k => $v) {
            $sets[] = "$k=?";
            $types .= is_int($v) ? 'i' : 's';
            $params[] = $v;
        }

        // Handle images + detect if any replaced
        $ALLOWED = ['image/jpeg','image/png','image/webp','image/gif'];
        $imageInputs = [
            'id_front_image'      => 'id_front_image',
            'id_back_image'       => 'id_back_image',
            'license_front_image' => 'license_front_image',
            'license_back_image'  => 'license_back_image'
        ];

        foreach ($imageInputs as $input => $col) {
            if (!isset($_FILES[$input]) || $_FILES[$input]['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $errCode = $_FILES[$input]['error'];
            if ($errCode !== UPLOAD_ERR_OK) {
                $error = match($errCode) {
                    UPLOAD_ERR_INI_SIZE   => "$input exceeds server upload_max_filesize.",
                    UPLOAD_ERR_FORM_SIZE  => "$input exceeds MAX_FILE_SIZE form limit.",
                    UPLOAD_ERR_PARTIAL    => "$input partially uploaded.",
                    UPLOAD_ERR_NO_TMP_DIR => "Missing temp folder for $input.",
                    UPLOAD_ERR_CANT_WRITE => "Failed to write $input to disk.",
                    UPLOAD_ERR_EXTENSION  => "PHP extension stopped $input upload.",
                    default               => "Unknown upload error ($errCode) for $input."
                };
                break;
            }
            $tmp  = $_FILES[$input]['tmp_name'];
            $size = @filesize($tmp);
            if ($size === false || $size <= 0) {
                $error = "Failed to read $input.";
                break;
            }
            if ($size > MAX_IMAGE_BYTES) {
                $error = "$input exceeds " . (MAX_IMAGE_BYTES/1024/1024) . "MB limit.";
                break;
            }
            $mime = detectMimeType($tmp);
            if (!in_array($mime, $ALLOWED, true)) {
                $error = "$input unsupported type ($mime).";
                break;
            }
            $blob = @file_get_contents($tmp);
            if ($blob === false) {
                $error = "Could not read $input.";
                break;
            }
            $sets[] = "$col=?";
            $types .= 's';
            $params[] = $blob;
            $updatedImages[] = $input;
        }

        if (!$error) {
            // Version bump only if any image changed
            if ($updatedImages) {
                $sets[] = "images_version = images_version + 1";
                $sets[] = "images_updated_at = NOW()";
            }

            if (!$sets) {
                $error = "No changes detected.";
            } else {
                $sets_sql = implode(', ', $sets);
                $sql = "UPDATE customer SET $sets_sql WHERE cust_id=?";
                $types .= 'i';
                $params[] = $cust_id;

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $bind = [];
                    $bind[] = $types;
                    foreach ($params as $i => $v) { $bind[] = &$params[$i]; }
                    call_user_func_array([$stmt,'bind_param'],$bind);
                    if ($stmt->execute()) {
                        $success = true;
                    } else {
                        $error = "Update failed: ".$stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Prepare failed: ".$conn->error;
                }
            }
        }
    }

    // Re-fetch
    $stmt = $conn->prepare(
        "SELECT full_name, phone_no, email, username, id_no,
                id_front_image, id_back_image, license_front_image, license_back_image,
                address, age, images_version
         FROM customer WHERE cust_id=? LIMIT 1"
    );
    $stmt->bind_param("i",$cust_id);
    $stmt->execute();
    $r2 = $stmt->get_result();
    $newUser = $r2->fetch_assoc();
    $stmt->close();
    if ($newUser) {
        $originalUser = $newUser;
        $currentImagesVersion = (int)$originalUser['images_version'];
    }
}

/* ---------- Display values ---------- */
$display_phone = format_phone_display($originalUser['phone_no']);
$display_id    = format_nric_display($originalUser['id_no']);

// Cache buster = images_version
$imgBust = '&v=' . $currentImagesVersion;
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.edit-container { max-width:760px;margin:40px auto 70px;background:#fff;padding:36px 40px 40px;border-radius:14px;box-shadow:0 4px 18px rgba(44,60,102,0.09); }
.edit-title { font-size:1.34em;font-weight:700;color:#2f377d;margin-bottom:18px;text-align:center; }
.edit-form label { display:block;margin-top:16px;margin-bottom:6px;font-weight:600;color:#3c4cb8;font-size:.94em; }
.edit-form input[type="text"], .edit-form input[type="email"], .edit-form input[type="number"], .edit-form textarea {
    width:100%;padding:9px 10px;border:1px solid #cdd3e3;border-radius:6px;font-size:.98em;background:#f9fafc;margin-bottom:2px;
}
.edit-form input[disabled], .edit-form textarea[disabled] { background:#eef0f3 !important; }
.edit-form input[type="file"] { font-size:.86em;margin-top:4px; }
.flex-docs { display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:18px;margin-top:6px; }
.doc-block { background:#f7f9fc;border:1px solid #dfe5ef;padding:10px 10px 14px;border-radius:10px;position:relative;font-size:.7em;color:#445067; }
.doc-block h4 { margin:0 0 6px;font-size:.9em;color:#2f4372;font-weight:600; }
.current-img, .new-preview { width:100%;height:110px;object-fit:cover;border:1px solid #d6dde8;border-radius:8px;background:#fff;display:block; }
.new-preview { display:none; }
.note { color:#777;font-size:.68em;margin-top:4px;display:block; }
.inline-warn { font-size:.68em;color:#b04100;margin-top:4px;display:none; }
.edit-form button {
    margin-top:30px;width:100%;padding:13px 0;background:#3c4cb8;color:#fff;border:none;border-radius:9px;font-size:1.05em;font-weight:600;cursor:pointer;transition:background .18s;
}
.edit-form button:hover { background:#234c96; }
.edit-form button:disabled { background:#9ca4ba;cursor:not-allowed; }
.success-msg,.error-msg { padding:10px 0 0;font-weight:600;text-align:center;font-size:.9em; }
.success-msg { color:#1f6d36; }
.error-msg { color:#8d2323; }
.back-btn { width:100%;margin-top:22px;background:#c2c7d6;color:#2f377d;border:none;padding:11px 0;border-radius:9px;font-size:1.02em;font-weight:600;cursor:pointer;display:block;text-align:center;text-decoration:none;transition:.18s; }
.back-btn:hover { background:#b4bac9;color:#162040; }
.section-sub { font-size:.78em;color:#5d6c85;margin-top:2px; }
.separator { margin-top:28px;height:1px;background:#e4e8f1;border:none; }
</style>

<div class="edit-container">
    <div class="edit-title">Edit My Profile</div>

    <?php if ($success && !$error): ?>
        <div class="success-msg">
            Profile updated successfully!
            <?php if ($updatedImages): ?><br><span style="font-size:.8em;">Images updated: <?= htmlspecialchars(implode(', ', $updatedImages)) ?> (version <?= $currentImagesVersion ?>)</span><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form class="edit-form" action="" method="post" enctype="multipart/form-data" autocomplete="off">
        <label for="full_name">Full Name</label>
        <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($originalUser['full_name']) ?>" required>

        <label for="username">Username</label>
        <input type="text" name="username" id="username" value="<?= htmlspecialchars($originalUser['username']) ?>" required>

        <label for="email">Email</label>
        <input type="email" name="email" id="email" value="<?= htmlspecialchars($originalUser['email']) ?>" required>

        <label for="phone_no">Phone No (Mobile)</label>
        <input type="text" name="phone_no" id="phone_no" value="<?= htmlspecialchars($display_phone) ?>" required>

        <label for="id_no">NRIC / ID No</label>
        <input type="text" name="id_no" id="id_no" value="<?= htmlspecialchars($display_id) ?>" maxlength="14" required>

        <label for="age">Age</label>
        <input type="number" min="0" name="age" id="age" value="<?= htmlspecialchars($originalUser['age']) ?>" readonly style="background:#e4e7ee;">

        <hr class="separator">

        <label>Identity & License Documents (Version <?= $currentImagesVersion ?>)</label>
        <div class="section-sub">You can update your documents at any time.</div>

        <div class="flex-docs">
            <!-- ID Front -->
            <div class="doc-block">
                <h4>ID Front</h4>
                <?php if (!empty($originalUser['id_front_image'])): ?>
                    <img src="get_id_image.php?type=front&cust_id=<?= $cust_id . $imgBust ?>" class="current-img" alt="Current ID Front">
                <?php else: ?>
                    <div style="height:110px;border:1px dashed #bcc6d6;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;font-size:.75em;color:#768099;">No Image</div>
                <?php endif; ?>
                <input type="file" name="id_front_image" id="id_front_image" accept="image/*">
                <img id="preview_id_front_image" class="new-preview" alt="New ID Front Preview">
                <span class="note">Max 5MB (jpg/png/webp/gif)</span>
                <div id="msg_id_front_image" class="inline-warn"></div>
            </div>
            <!-- ID Back -->
            <div class="doc-block">
                <h4>ID Back</h4>
                <?php if (!empty($originalUser['id_back_image'])): ?>
                    <img src="get_id_image.php?type=back&cust_id=<?= $cust_id . $imgBust ?>" class="current-img" alt="Current ID Back">
                <?php else: ?>
                    <div style="height:110px;border:1px dashed #bcc6d6;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;font-size:.75em;color:#768099;">No Image</div>
                <?php endif; ?>
                <input type="file" name="id_back_image" id="id_back_image" accept="image/*">
                <img id="preview_id_back_image" class="new-preview" alt="New ID Back Preview">
                <span class="note">Max 5MB</span>
                <div id="msg_id_back_image" class="inline-warn"></div>
            </div>
            <!-- License Front -->
            <div class="doc-block">
                <h4>License Front</h4>
                <?php if (!empty($originalUser['license_front_image'])): ?>
                    <img src="get_id_image.php?type=license_front&cust_id=<?= $cust_id . $imgBust ?>" class="current-img" alt="License Front">
                <?php else: ?>
                    <div style="height:110px;border:1px dashed #bcc6d6;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;font-size:.75em;color:#768099;">No Image</div>
                <?php endif; ?>
                <input type="file" name="license_front_image" id="license_front_image" accept="image/*">
                <img id="preview_license_front_image" class="new-preview" alt="New License Front Preview">
                <span class="note">Max 5MB</span>
                <div id="msg_license_front_image" class="inline-warn"></div>
            </div>
            <!-- License Back -->
            <div class="doc-block">
                <h4>License Back</h4>
                <?php if (!empty($originalUser['license_back_image'])): ?>
                    <img src="get_id_image.php?type=license_back&cust_id=<?= $cust_id . $imgBust ?>" class="current-img" alt="Current License Back">
                <?php else: ?>
                    <div style="height:110px;border:1px dashed #bcc6d6;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;font-size:.75em;color:#768099;">No Image</div>
                <?php endif; ?>
                <input type="file" name="license_back_image" id="license_back_image" accept="image/*">
                <img id="preview_license_back_image" class="new-preview" alt="New License Back Preview">
                <span class="note">Max 5MB</span>
                <div id="msg_license_back_image" class="inline-warn"></div>
            </div>
        </div>

        <hr class="separator">

        <label for="address">Address</label>
        <textarea name="address" id="address" required><?= htmlspecialchars($originalUser['address']) ?></textarea>

        <button type="submit">Save Changes</button>
    </form>

    <button class="back-btn" onclick="window.location.href='profile.php'">Back</button>
</div>

<script>
// ---------- Formatting (NRIC & Phone) ----------
function formatNRIC(v){
  const d=v.replace(/\D+/g,'').slice(0,12);
  if(d.length<=6) return d;
  if(d.length<=8) return d.slice(0,6)+'-'+d.slice(6);
  return d.slice(0,6)+'-'+d.slice(6,8)+'-'+d.slice(8);
}
function formatMYMobile(v){
  let d=v.replace(/\D+/g,'').slice(0,11);
  if(!d.startsWith('01')) return d;
  if(d.length<=3) return d;
  return d.slice(0,3)+'-'+d.slice(3);
}

// Auto-calculate age from NRIC
function calculateAgeFromNRIC(nric) {
    const digits = nric.replace(/\D+/g,'');
    if (digits.length < 6) return '';
    let yy = parseInt(digits.slice(0,2), 10);
    let mm = parseInt(digits.slice(2,4), 10);
    let dd = parseInt(digits.slice(4,6), 10);
    let today = new Date();

    let currentYY = parseInt(today.getFullYear().toString().slice(-2));
    let century = (yy <= currentYY) ? 2000 : 1900;
    let birthYear = century + yy;

    // Validate month and day
    if (isNaN(mm) || mm < 1 || mm > 12) return '';
    if (isNaN(dd) || dd < 1 || dd > 31) return '';

    let birthDate = new Date(birthYear, mm - 1, dd);
    if (isNaN(birthDate.getTime())) return '';

    let age = today.getFullYear() - birthYear;
    if (
        today.getMonth() < birthDate.getMonth() ||
        (today.getMonth() === birthDate.getMonth() && today.getDate() < birthDate.getDate())
    ) {
        age--;
    }
    return (age >= 0 && age < 130) ? age : '';
}

const idInput=document.getElementById('id_no');
const phoneInput=document.getElementById('phone_no');
const ageInput=document.getElementById('age');
if (idInput) {
  idInput.addEventListener('input',function() {
    idInput.value = formatNRIC(idInput.value);
    if (ageInput) {
      ageInput.value = calculateAgeFromNRIC(idInput.value);
    }
  });
  // On page load, set age if ID exists
  if (ageInput) ageInput.value = calculateAgeFromNRIC(idInput.value);
}
if (phoneInput) {
  phoneInput.addEventListener('input',()=>{ phoneInput.value=formatMYMobile(phoneInput.value); });
  phoneInput.value=formatMYMobile(phoneInput.value);
}

// ---------- Image Preview Logic ----------
const MAX_BYTES = <?= MAX_IMAGE_BYTES ?>;
const ALLOWED   = ['image/jpeg','image/png','image/webp','image/gif'];

function setupPreview(inputId, previewId, msgId){
    const input  = document.getElementById(inputId);
    const img    = document.getElementById(previewId);
    const msg    = document.getElementById(msgId);
    if (!input || input.disabled) return;
    input.addEventListener('change', () => {
        if (msg){ msg.style.display='none'; msg.textContent=''; }
        if (img){ img.style.display='none'; img.removeAttribute('src'); }

        if (!input.files || !input.files[0]) return;
        const file = input.files[0];

        if (!ALLOWED.includes(file.type)) {
            if (msg){ msg.textContent='Unsupported file type.'; msg.style.display='block'; }
            input.value='';
            return;
        }
        if (file.size > MAX_BYTES) {
            if (msg){ msg.textContent='File too large (>5MB).'; msg.style.display='block'; }
            input.value='';
            return;
        }

        const reader = new FileReader();
        reader.onload = e => {
            if (img){
                img.src = e.target.result;
                img.style.display='block';
            }
        };
        reader.readAsDataURL(file);
    });
}

setupPreview('id_front_image','preview_id_front_image','msg_id_front_image');
setupPreview('id_back_image','preview_id_back_image','msg_id_back_image');
setupPreview('license_front_image','preview_license_front_image','msg_license_front_image');
setupPreview('license_back_image','preview_license_back_image','msg_license_back_image');
</script>

<?php include '../includes/footer.php'; ?>