<?php
session_start();

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';


if (empty($_SESSION['booking_data'])) {
    header("Location: book_car.php");
    exit;
}

$errors = [];

/* Helpers */
function is_valid_phone($v){ return preg_match('/^01[0-9]-\d{7,8}$/', $v); }
function is_valid_ic($v){ return preg_match('/^\d{6}-\d{2}-\d{4}$/', $v); }

function detect_image_mime($path) {
    if (!is_file($path)) return false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) return $mime;
        }
    }
    if (function_exists('exif_imagetype')) {
        $type = @exif_imagetype($path);
        $map = [
            IMAGETYPE_JPEG=>'image/jpeg',
            IMAGETYPE_PNG =>'image/png',
            IMAGETYPE_GIF =>'image/gif',
            IMAGETYPE_WEBP=>'image/webp'
        ];
        if ($type !== false && isset($map[$type])) return $map[$type];
    }
    if (function_exists('getimagesize')) {
        $info = @getimagesize($path);
        if (!empty($info['mime'])) return $info['mime'];
    }
    return false;
}
function is_valid_image_upload($fileArr) {
    if (!isset($fileArr) || !isset($fileArr['error'])) return false;
    if ($fileArr['error'] !== UPLOAD_ERR_OK) return false;
    if ($fileArr['size'] > 5*1024*1024) return false;
    $mime = detect_image_mime($fileArr['tmp_name']);
    if ($mime === false) return false;
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    return in_array($mime, $allowed, true);
}

/* POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guarantor_full_name'])) {
    $full_name    = trim($_POST['guarantor_full_name']);
    $phone_no     = trim($_POST['guarantor_phone_no']);
    $id_no        = trim($_POST['guarantor_id_no']);
    $relationship = trim($_POST['guarantor_relationship']);

    if ($full_name === '') $errors[] = "Full Name is required.";
    if ($phone_no === '' || !is_valid_phone($phone_no))
        $errors[] = "Phone Number must follow 01X-XXXXXXX or 01X-XXXXXXXX.";
    if ($id_no === '' || !is_valid_ic($id_no))
        $errors[] = "ID Number must follow XXXXXX-XX-XXXX.";
    if ($relationship === '') $errors[] = "Relationship is required.";
    if (strlen($relationship) > 50) $errors[] = "Relationship too long (max 50).";

    $existing  = $_SESSION['guarantor_data'] ?? [];
    $frontPath = $existing['guarantor_id_front'] ?? '';
    $backPath  = $existing['guarantor_id_back'] ?? '';

    if (isset($_FILES['guarantor_id_front']) && $_FILES['guarantor_id_front']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (is_valid_image_upload($_FILES['guarantor_id_front'])) {
            $frontName = uniqid('g_idfront_', true).'_'.preg_replace('/[^A-Za-z0-9._-]/','_', $_FILES['guarantor_id_front']['name']);
            $frontTemp = sys_get_temp_dir().DIRECTORY_SEPARATOR.$frontName;
            if (!move_uploaded_file($_FILES['guarantor_id_front']['tmp_name'], $frontTemp)) {
                $errors[] = "Failed to store ID Front image.";
            } else {
                $frontPath = $frontTemp;
            }
        } else {
            $errors[] = "Invalid ID Front image (type/size).";
        }
    }
    if (!$frontPath) $errors[] = "ID Front Image is required.";

    if (isset($_FILES['guarantor_id_back']) && $_FILES['guarantor_id_back']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (is_valid_image_upload($_FILES['guarantor_id_back'])) {
            $backName = uniqid('g_idback_', true).'_'.preg_replace('/[^A-Za-z0-9._-]/','_', $_FILES['guarantor_id_back']['name']);
            $backTemp = sys_get_temp_dir().DIRECTORY_SEPARATOR.$backName;
            if (!move_uploaded_file($_FILES['guarantor_id_back']['tmp_name'], $backTemp)) {
                $errors[] = "Failed to store ID Back image.";
            } else {
                $backPath = $backTemp;
            }
        } else {
            $errors[] = "Invalid ID Back image (type/size).";
        }
    }
    if (!$backPath) $errors[] = "ID Back Image is required.";

    if (empty($errors)) {
        $_SESSION['guarantor_data'] = [
            'guarantor_full_name'    => $full_name,
            'guarantor_phone_no'     => $phone_no,
            'guarantor_id_no'        => $id_no,
            'guarantor_relationship' => $relationship,
            'guarantor_id_front'     => $frontPath,
            'guarantor_id_back'      => $backPath
        ];
        header("Location: review_booking.php");
        exit;
    }
}

/* Prefill */
$g_full  = $_SESSION['guarantor_data']['guarantor_full_name']    ?? '';
$g_phone = $_SESSION['guarantor_data']['guarantor_phone_no']     ?? '';
$g_ic    = $_SESSION['guarantor_data']['guarantor_id_no']        ?? '';
$g_rel   = $_SESSION['guarantor_data']['guarantor_relationship'] ?? '';

include '../includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Guarantor Details | Timeless Car Rental</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background:#eceef4; }
.gu-wrapper {
    max-width:780px;
    margin:40px auto 70px;
    background:#fff;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(40,55,95,0.10);
    padding:34px 42px 40px;
}
.section-title {
    font-size:1.32em;
    font-weight:700;
    color:#2f377d;
    margin:0 0 22px;
    letter-spacing:.5px;
}
.error-box {
    background:#ffe2e2;
    border:1px solid #d95353;
    color:#962222;
    padding:12px 16px;
    border-radius:8px;
    margin:0 0 18px;
    font-size:.82em;
}
.form-row {
    margin-bottom:16px;
}
.form-label {
    display:block;
    font-weight:600;
    color:#2d3d66;
    margin-bottom:4px;
    font-size:.9em;
}
.required-star { color:#c62828; margin-left:4px; font-weight:700;}
input[type="text"], input[type="file"], textarea {
    width:100%;
    padding:8px 10px;
    border:1px solid #d5dae5;
    border-radius:7px;
    font-size:.95em;
    background:#fff;
}
input[type="file"] { padding:5px 6px; }
.preview-section {
    display:flex;
    gap:22px;
    flex-wrap:wrap;
    margin-top:8px;
}
.preview-box {
    width:150px;
}
.preview-title {
    font-size:.68em;
    font-weight:700;
    letter-spacing:.6px;
    color:#4a5573;
    margin:0 0 4px;
    text-transform:uppercase;
}
.img-preview {
    max-width:140px;
    max-height:100px;
    border:1px solid #d9dfea;
    border-radius:8px;
    background:#f6f9fd;
    object-fit:cover;
    display:block;
}
.note {
    font-size:.7em;
    color:#777;
    margin-top:4px;
    line-height:1.3em;
}
.badge {
    display:inline-block;
    font-size:.6em;
    padding:3px 7px;
    border-radius:10px;
    background:#7d8bb8;
    color:#fff;
    margin-left:6px;
    letter-spacing:.5px;
}
.badge-new { background:#3c4cb8; }
.btn-row {
    margin-top:30px;
    display:flex;
    justify-content:flex-end;
    gap:12px;
    flex-wrap:wrap;
}
.next-btn, .back-btn {
    border:none;
    cursor:pointer;
    font-weight:600;
    font-size:.95em;
    border-radius:8px;
    padding:12px 26px;
    transition:.18s;
    text-decoration:none;
    display:inline-block;
}
.next-btn { background:#3c4cb8; color:#fff; }
.next-btn:hover { background:#234c96; }
.back-btn { background:#d1d5de; color:#222; }
.back-btn:hover { background:#bfc5ce; }
.inline-hint {
    font-size:.72em;
    color:#6b7487;
    margin-top:4px;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
    function autoFormatPhone(inp){
        inp.addEventListener('input', function(){
            let v = this.value.replace(/[^\d]/g,'');
            if (v.length > 3) v = v.slice(0,3)+'-'+v.slice(3,11);
            this.value = v.slice(0,12);
        });
    }
    function autoFormatIC(inp){
        inp.addEventListener('input', function(){
            let v = this.value.replace(/[^\d]/g,'');
            if (v.length>6) v = v.slice(0,6)+'-'+v.slice(6);
            if (v.length>9) v = v.slice(0,9)+'-'+v.slice(9,13);
            this.value = v.slice(0,14);
        });
    }
    const phoneField = document.querySelector('input[name="guarantor_phone_no"]');
    const icField    = document.querySelector('input[name="guarantor_id_no"]');
    if (phoneField) autoFormatPhone(phoneField);
    if (icField) autoFormatIC(icField);

    function bindLivePreview(fileInputSel, imgSel){
        const f = document.querySelector(fileInputSel);
        const img = document.querySelector(imgSel);
        if (!f || !img) return;
        f.addEventListener('change', function(){
            if (this.files && this.files[0]) {
                const file = this.files[0];
                if (!file.type.match(/^image\//)) {
                    alert('Selected file is not an image.');
                    this.value='';
                    img.style.display='none';
                    return;
                }
                if (file.size > 5*1024*1024) {
                    alert('Image too large (max 5MB).');
                    this.value='';
                    img.style.display='none';
                    return;
                }
                const reader = new FileReader();
                reader.onload = e => {
                    img.src = e.target.result;
                    img.style.display='block';
                };
                reader.readAsDataURL(file);
            } else {
                img.src='';
                img.style.display='none';
            }
        });
    }
    bindLivePreview('input[name="guarantor_id_front"]','#live_front');
    bindLivePreview('input[name="guarantor_id_back"]','#live_back');
});
</script>
</head>
<body>

<div class="gu-wrapper">
    <div class="section-title">Guarantor Details</div>

    <?php if (!empty($errors)): ?>
        <div class="error-box">
            <?php foreach($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" autocomplete="off">
        <div class="form-row">
            <label class="form-label">Full Name<span class="required-star">*</span></label>
            <input type="text" name="guarantor_full_name" value="<?= htmlspecialchars($g_full) ?>" required>
        </div>
        <div class="form-row">
            <label class="form-label">Phone Number<span class="required-star">*</span></label>
            <input type="text"
                   name="guarantor_phone_no"
                   value="<?= htmlspecialchars($g_phone) ?>"
                   pattern="^01[0-9]-\d{7,8}$"
                   maxlength="12"
                   placeholder="01X-XXXXXXX"
                   required>
            <div class="inline-hint">Format: 01X-XXXXXXX or 01X-XXXXXXXX</div>
        </div>
        <div class="form-row">
            <label class="form-label">ID Number<span class="required-star">*</span></label>
            <input type="text"
                   name="guarantor_id_no"
                   value="<?= htmlspecialchars($g_ic) ?>"
                   pattern="^\d{6}-\d{2}-\d{4}$"
                   maxlength="14"
                   placeholder="XXXXXX-XX-XXXX"
                   required>
        </div>

        <div class="form-row">
            <label class="form-label">ID Front Image<span class="required-star">*</span></label>
            <div class="preview-section">
                <div class="preview-box">
                    <div class="preview-title">Live Preview <span class="badge badge-new">NEW</span></div>
                    <img id="live_front" class="img-preview" style="display:none;" alt="Live Front Preview">
                </div>
            </div>
            <input type="file" name="guarantor_id_front" accept="image/*" <?= empty($_SESSION['guarantor_data']['guarantor_id_front'])?'required':''; ?>>
            <div class="inline-hint">Max size 5MB. Allowed: JPG, PNG, GIF, WEBP.</div>
        </div>

        <div class="form-row">
            <label class="form-label">ID Back Image<span class="required-star">*</span></label>
            <div class="preview-section">
                <div class="preview-box">
                    <div class="preview-title">Live Preview <span class="badge badge-new">NEW</span></div>
                    <img id="live_back" class="img-preview" style="display:none;" alt="Live Back Preview">
                </div>
            </div>
            <input type="file" name="guarantor_id_back" accept="image/*" <?= empty($_SESSION['guarantor_data']['guarantor_id_back'])?'required':''; ?>>
            <div class="inline-hint">Max size 5MB.</div>
        </div>

        <div class="form-row">
            <label class="form-label">Relationship<span class="required-star">*</span></label>
            <input type="text" name="guarantor_relationship" value="<?= htmlspecialchars($g_rel) ?>" maxlength="50" required>
        </div>

        <div class="btn-row">
            <a href="booking_driver.php" class="back-btn">Back (Driver)</a>
            <button type="submit" class="next-btn">Next (Review)</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>