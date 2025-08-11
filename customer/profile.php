<?php
session_start();
if (empty($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

require '../connect.php';
include '../includes/header.php';

$cust_id = (int)$_SESSION['cust_id'];
$notice  = isset($_GET['notice']) ? trim($_GET['notice']) : '';
$action_msg = '';
$action_err = '';

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
    if ($len === 10 || $len === 11) {
        return substr($digits,0,3) . '-' . substr($digits,3);
    }
    return $digits;
}

/* ---------- Fetch full profile (no large blobs) ---------- */
$stmt = $conn->prepare(
    "SELECT full_name, username, email, phone_no, id_no, address, age,
            id_front_image IS NOT NULL AND id_front_image <> ''       AS has_id_front,
            id_back_image IS NOT NULL AND id_back_image <> ''         AS has_id_back,
            license_front_image IS NOT NULL AND license_front_image <> '' AS has_license_front,
            license_back_image IS NOT NULL AND license_back_image <> ''  AS has_license_back,
            images_version
     FROM customer
     WHERE cust_id=? LIMIT 1"
);
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<p style='padding:20px;'>User not found.</p>";
    include '../includes/footer.php';
    exit;
}

/* ---------- Prepare Display Values ---------- */
$display_phone  = format_phone_display($user['phone_no']);
$display_id     = format_nric_display($user['id_no']);
$imagesVersion  = (int)($user['images_version'] ?? 0);
$vParam         = '&v=' . $imagesVersion;

/* ---------- HTML / CSS ---------- */
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.profile-wrapper {
    max-width: 880px;
    margin: 38px auto 70px;
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 34px;
}
@media (max-width: 950px) { .profile-wrapper { grid-template-columns: 1fr; } }
.card {
    background:#fff;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(40,55,95,0.10);
    padding:26px 30px 30px;
    position:relative;
}
.section-title { font-size:1.24em;font-weight:700;color:#2f377d;margin:0 0 18px; }
.notice-box, .msg-box { padding:10px 14px;border-radius:10px;font-size:.8em;margin:0 0 16px; }
.notice-box { background:#e9eef9;border:1px solid #cfd8ec;color:#2f3d62; }
.msg-box.success { background:#e4f6eb;border:1px solid #bde6cc;color:#1f6d36; }
.msg-box.error { background:#ffe2e2;border:1px solid #f5b5b5;color:#8c1f1f; }
.profile-table { width:100%;border-collapse:collapse;font-size:.9em; }
.profile-table th { text-align:left;width:170px;padding:8px 6px 6px;vertical-align:top;color:#334467;font-weight:600;background:#f5f7fb;border-right:1px solid #e1e7f1; }
.profile-table td { padding:8px 12px 6px;background:#fafbfe;color:#222; }
.edit-btn {
    width:100%;display:block;text-align:center;text-decoration:none;background:#2e7bbd;color:#fff;padding:12px 0;margin-top:18px;border-radius:9px;font-weight:600;font-size:.92em;transition:.18s;
}
.edit-btn:hover { background:#1e5c8d; }
.small-note { font-size:.7em;color:#6b7489;margin-top:6px; }
.image-grid { display:grid;grid-template-columns: repeat(auto-fill, minmax(140px,1fr));gap:12px;margin-top:6px; }
.image-thumb { background:#f3f6fb;border:1px solid #d9e2f1;border-radius:10px;padding:6px;text-align:center;font-size:.65em;color:#445067; }
.image-thumb img { width:100%;height:90px;object-fit:cover;border-radius:6px; }
</style>

<div class="profile-wrapper">

    <!-- Left: Profile Details -->
    <div class="card">
        <div class="section-title">My Profile</div>

        <?php if ($notice): ?>
            <div class="notice-box"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <?php if ($action_msg): ?>
            <div class="msg-box success"><?= htmlspecialchars($action_msg) ?></div>
        <?php elseif ($action_err): ?>
            <div class="msg-box error"><?= htmlspecialchars($action_err) ?></div>
        <?php endif; ?>

        <table class="profile-table" style="margin-top:16px;">
            <tr><th>Full Name</th><td><?= htmlspecialchars($user['full_name']) ?></td></tr>
            <tr><th>Username</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
            <tr><th>Email</th><td><?= htmlspecialchars($user['email']) ?></td></tr>
            <tr><th>Phone</th><td><?= htmlspecialchars($display_phone) ?></td></tr>
            <tr><th>NRIC / ID</th><td><?= htmlspecialchars($display_id) ?></td></tr>
            <tr><th>Age</th><td><?= (int)$user['age'] ?></td></tr>
            <tr><th>Address</th><td><?= nl2br(htmlspecialchars($user['address'])) ?></td></tr>
        </table>

        <a class="edit-btn" href="edit_profile.php">Edit Profile</a>
    </div>

    <!-- Right: Identity Images -->
    <div class="card action-panel">
        <div class="section-title">Identity Documents</div>

        <div class="image-grid">
            <div class="image-thumb">
                <strong>ID Front</strong>
                <?php if ($user['has_id_front']): ?>
                    <img src="get_id_image.php?type=front&cust_id=<?= $cust_id . $vParam ?>" alt="ID Front">
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
            <div class="image-thumb">
                <strong>ID Back</strong>
                <?php if ($user['has_id_back']): ?>
                    <img src="get_id_image.php?type=back&cust_id=<?= $cust_id . $vParam ?>" alt="ID Back">
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
            <div class="image-thumb">
                <strong>License Front</strong>
                <?php if ($user['has_license_front']): ?>
                    <img src="get_id_image.php?type=license_front&cust_id=<?= $cust_id . $vParam ?>" alt="License Front">
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
            <div class="image-thumb">
                <strong>License Back</strong>
                <?php if ($user['has_license_back']): ?>
                    <img src="get_id_image.php?type=license_back&cust_id=<?= $cust_id . $vParam ?>" alt="License Back">
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="small-note" style="margin-top:10px;">
            Make sure all details & images are correct before booking.
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>