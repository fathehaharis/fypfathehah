<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

$cust_id = $_SESSION['cust_id'];

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize & get input
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_no = trim($_POST['phone_no'] ?? '');
    $id_no = trim($_POST['id_no'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $age = intval($_POST['age'] ?? 0);

    // Prepare dynamic query
    $fields = [
        'full_name' => $full_name,
        'username' => $username,
        'email' => $email,
        'phone_no' => $phone_no,
        'id_no' => $id_no,
        'address' => $address,
        'age' => $age
    ];
    $types = '';
    $params = [];
    $sets = [];
    foreach ($fields as $k => $v) {
        $sets[] = "$k=?";
        $types .= is_int($v) ? 'i' : 's';
        $params[] = $v;
    }

    $blobIndexes = [];
    // Handle ID front image
    if (isset($_FILES['id_front_image']) && $_FILES['id_front_image']['error'] === UPLOAD_ERR_OK) {
        $sets[] = "id_front_image=?";
        $types .= "s";
        $params[] = file_get_contents($_FILES['id_front_image']['tmp_name']);
        $blobIndexes[] = count($params) - 1;
    }

    // Handle ID back image
    if (isset($_FILES['id_back_image']) && $_FILES['id_back_image']['error'] === UPLOAD_ERR_OK) {
        $sets[] = "id_back_image=?";
        $types .= "s";
        $params[] = file_get_contents($_FILES['id_back_image']['tmp_name']);
        $blobIndexes[] = count($params) - 1;
    }

    // Handle License front image
    if (isset($_FILES['license_front_image']) && $_FILES['license_front_image']['error'] === UPLOAD_ERR_OK) {
        $sets[] = "license_front_image=?";
        $types .= "s";
        $params[] = file_get_contents($_FILES['license_front_image']['tmp_name']);
        $blobIndexes[] = count($params) - 1;
    }

    // Handle License back image
    if (isset($_FILES['license_back_image']) && $_FILES['license_back_image']['error'] === UPLOAD_ERR_OK) {
        $sets[] = "license_back_image=?";
        $types .= "s";
        $params[] = file_get_contents($_FILES['license_back_image']['tmp_name']);
        $blobIndexes[] = count($params) - 1;
    }

    $sets_sql = implode(', ', $sets);
    $sql = "UPDATE customer SET $sets_sql WHERE cust_id=?";
    $types .= "i";
    $params[] = $cust_id;

    $stmt = $conn->prepare($sql);

    // bind parameters dynamically
    $bind_params = [];
    $bind_params[] = $types;
    foreach ($params as $k => $v) {
        $bind_params[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_params);

    // Send blobs (if any)
    foreach ($blobIndexes as $i) {
        $stmt->send_long_data($i, $params[$i]);
    }

    if ($stmt->execute()) {
        $success = true;
    } else {
        $error = "Update failed: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch current data
$stmt = $conn->prepare("SELECT full_name, phone_no, email, username, id_no, id_front_image, id_back_image, license_front_image, license_back_image, address, age FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<p>User not found.</p>";
    include '../includes/footer.php';
    exit;
}
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.edit-container {
    max-width: 540px;
    margin: 40px auto 60px auto;
    background: #fff;
    padding: 36px 38px 32px 38px;
    border-radius: 13px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.09);
}
.edit-title {
    font-size: 1.28em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 18px;
    text-align: center;
}
.edit-form label {
    display: block;
    margin-top: 14px;
    margin-bottom: 6px;
    font-weight: 600;
    color: #3c4cb8;
}
.edit-form input[type="text"],
.edit-form input[type="email"],
.edit-form input[type="number"],
.edit-form textarea {
    width: 100%;
    padding: 9px;
    border: 1px solid #cdd3e3;
    border-radius: 6px;
    font-size: 1em;
    background: #f9fafc;
    margin-bottom: 2px;
}
.edit-form input[type="file"] {
    font-size: 0.98em;
}
.edit-form button {
    margin-top: 22px;
    width: 100%;
    padding: 12px 0;
    background: #3c4cb8;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
}
.edit-form button:hover {
    background: #234c96;
}
.success-msg {
    color: #219150;
    padding: 9px 0 0 0;
    font-weight: 600;
    text-align: center;
}
.error-msg {
    color: #d42d2d;
    padding: 9px 0 0 0;
    font-weight: 600;
    text-align: center;
}
.back-btn {
    width: 100%;
    margin-top: 18px;
    background: #c2c7d6;
    color: #2f377d;
    border: none;
    padding: 11px 0;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    display: block;
    text-align: center;
    text-decoration: none;
    transition: background 0.18s, color 0.18s;
}
.back-btn:hover {
    background: #b4bac9;
    color: #162040;
}
.id-image-preview {
    max-width: 130px;
    max-height: 90px;
    display: block;
    margin: 8px 0;
    border-radius: 7px;
    border: 1px solid #e1e1e1;
    background: #f7fafd;
}
.note {
    color: #888; font-size: 0.97em;
}
</style>

<div class="edit-container">
    <div class="edit-title">Edit My Profile</div>
    <?php if ($success): ?>
        <div class="success-msg">Profile updated successfully!</div>
    <?php elseif ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form class="edit-form" action="" method="post" enctype="multipart/form-data" id="profileForm">
        <label for="full_name">Full Name</label>
        <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>

        <label for="username">Username</label>
        <input type="text" name="username" id="username" value="<?= htmlspecialchars($user['username']) ?>" required>

        <label for="email">Email</label>
        <input type="email" name="email" id="email" value="<?= htmlspecialchars($user['email']) ?>" required>

        <label for="phone_no">Phone No</label>
        <input type="text" name="phone_no" id="phone_no" value="<?= htmlspecialchars($user['phone_no']) ?>" required>

        <label for="id_no">ID No</label>
        <input type="text" name="id_no" id="id_no" value="<?= htmlspecialchars($user['id_no']) ?>" maxlength="12" pattern="\d*" required>
        
        <label for="age">Age</label>
        <input type="number" min="0" name="age" id="age" value="<?= htmlspecialchars($user['age']) ?>" required readonly style="background:#e4e7ee;">

        <label for="id_front_image">ID Front Image</label>
        <?php if (!empty($user['id_front_image'])): ?>
            <img class="id-image-preview" id="front-current-preview" src="get_id_image.php?type=front&cust_id=<?= $cust_id ?>" alt="Current ID Front">
        <?php endif; ?>
        <input type="file" name="id_front_image" id="id_front_image" accept="image/*" onchange="previewImage(this, 'front-preview')">
        <img class="id-image-preview" id="front-preview" style="display:none;">
        <span class="note">Leave blank to keep current image.</span>

        <label for="id_back_image">ID Back Image</label>
        <?php if (!empty($user['id_back_image'])): ?>
            <img class="id-image-preview" id="back-current-preview" src="get_id_image.php?type=back&cust_id=<?= $cust_id ?>" alt="Current ID Back">
        <?php endif; ?>
        <input type="file" name="id_back_image" id="id_back_image" accept="image/*" onchange="previewImage(this, 'back-preview')">
        <img class="id-image-preview" id="back-preview" style="display:none;">
        <span class="note">Leave blank to keep current image.</span>

        <label for="license_front_image">License Front Image</label>
        <?php if (!empty($user['license_front_image'])): ?>
            <img class="id-image-preview" id="license-front-current-preview" src="get_id_image.php?type=license_front&cust_id=<?= $cust_id ?>" alt="Current License Front">
        <?php endif; ?>
        <input type="file" name="license_front_image" id="license_front_image" accept="image/*" onchange="previewImage(this, 'license-front-preview')">
        <img class="id-image-preview" id="license-front-preview" style="display:none;">
        <span class="note">Leave blank to keep current image.</span>

        <label for="license_back_image">License Back Image</label>
        <?php if (!empty($user['license_back_image'])): ?>
            <img class="id-image-preview" id="license-back-current-preview" src="get_id_image.php?type=license_back&cust_id=<?= $cust_id ?>" alt="Current License Back">
        <?php endif; ?>
        <input type="file" name="license_back_image" id="license_back_image" accept="image/*" onchange="previewImage(this, 'license-back-preview')">
        <img class="id-image-preview" id="license-back-preview" style="display:none;">
        <span class="note">Leave blank to keep current image.</span>

        <label for="address">Address</label>
        <textarea name="address" id="address" required><?= htmlspecialchars($user['address']) ?></textarea>

        <button type="submit">Save Changes</button>
    </form>
    <button class="back-btn" onclick="window.location.href='profile.php'">Back</button>
</div>

<script>
// Auto-calculate age from ID No (Malaysian IC: YYMMDD-XX-XXXX)
document.getElementById('id_no').addEventListener('input', function() {
    let ic = this.value;
    if(ic.length >= 2) {
        let now = new Date();
        let yearPrefix = parseInt(ic.substring(0,2),10);
        let fullYear = yearPrefix <= (now.getFullYear()%100) ? 2000+yearPrefix : 1900+yearPrefix;
        let age = now.getFullYear() - fullYear;
        document.getElementById('age').value = age > 0 ? age : '';
    } else {
        document.getElementById('age').value = '';
    }
});

// Image preview before upload
function previewImage(input, previewId) {
    let preview = document.getElementById(previewId);
    if(input.files && input.files[0]) {
        let reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = '';
        preview.style.display = 'none';
    }
}
</script>
<?php include '../includes/footer.php'; ?>