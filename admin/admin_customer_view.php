<?php
/**
 * Admin view of a single customer profile
 * NO verification actions (Verify / Reject / Move to Re-Verification)
 * Just a read-only view of customer details and documents.
 * Adds image version cache-busting (&v=images_version)
 */

session_start();
include '../connect.php';

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}
date_default_timezone_set('Asia/Kuala_Lumpur');

$cust_id = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
if ($cust_id <= 0) {
    echo "Missing customer ID.";
    exit;
}

/* Formatting helpers */
function esc($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function format_nric_display($digits){
    $digits=preg_replace('/\D+/','',$digits??'');
    if(strlen($digits)!==12) return $digits;
    return substr($digits,0,6).'-'.substr($digits,6,2).'-'.substr($digits,8,4);
}
function format_phone_display($digits){
    $digits=preg_replace('/\D+/','',$digits??'');
    if(strpos($digits,'01')!==0) return $digits;
    $len=strlen($digits);
    if($len===10||$len===11) return substr($digits,0,3).'-'.substr($digits,3);
    return $digits;
}

/* Fetch fresh record (no full blobs needed, use presence flags + version) */
$stmt = $conn->prepare(
 "SELECT cust_id, full_name, username, email, phone_no, id_no, address, age,
         DATE_FORMAT(profile_status_updated_at,'%Y-%m-%d %H:%i:%s') AS updated_at,
         (id_front_image IS NOT NULL AND id_front_image <> '')       AS has_id_front_image,
         (id_back_image IS NOT NULL AND id_back_image <> '')         AS has_id_back_image,
         (license_front_image IS NOT NULL AND license_front_image <> '') AS has_license_front_image,
         (license_back_image IS NOT NULL AND license_back_image <> '')  AS has_license_back_image,
         images_version
  FROM customer WHERE cust_id=? LIMIT 1"
);
$stmt->bind_param('i',$cust_id);
$stmt->execute();
$res = $stmt->get_result();
$cust = $res->fetch_assoc();
$stmt->close();

if (!$cust) {
    echo "Customer not found.";
    exit;
}

$displayPhone = format_phone_display($cust['phone_no']);
$displayId    = format_nric_display($cust['id_no']);
$imgVersion   = (int)($cust['images_version'] ?? 0);
$verParam     = '&v=' . $imgVersion;

include 'admin_header.php';
?>
<style>
/* Center the content/card on the page */
.profile-wrapper{
  max-width:1000px;
  margin:0 auto 80px;
  padding:0 18px;
  display:flex;
  justify-content:center; /* horizontally center child */
}

.card{
  background:#fff;
  border-radius:18px;
  box-shadow:0 4px 18px rgba(40,55,95,0.12);
  padding:28px 32px 34px;
  position:relative;
  width:100%;
  max-width:720px; /* control card width while keeping it centered */
}

.section-title{font-size:1.2rem;font-weight:800;color:#2f377d;margin:0 0 18px;}
.profile-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:8px;}
.profile-table th{width:160px;text-align:left;padding:8px 8px 6px;background:#f5f7fb;color:#334467;font-weight:600;border-right:1px solid #e1e7f1;vertical-align:top;}
.profile-table td{padding:8px 12px 6px;background:#fafbfe;}
.image-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;margin-top:8px;}
.image-thumb{background:#f3f6fb;border:1px solid #d9e2f1;border-radius:10px;padding:8px 8px 10px;text-align:center;font-size:.62rem;color:#445067;font-weight:600;position:relative;}
.image-thumb img{width:100%;height:100px;object-fit:cover;border-radius:6px;margin-top:6px;}
.no-img{height:100px;display:flex;align-items:center;justify-content:center;background:#eef2f7;border:1px dashed #cbd6e2;border-radius:6px;color:#6b7489;font-size:.6rem;margin-top:6px;}
.image-thumb a.full-link{position:absolute;top:6px;right:6px;font-size:.55rem;text-decoration:none;background:#2e7bbd;color:#fff;padding:3px 6px;border-radius:6px;font-weight:600;opacity:.9;}
.image-thumb a.full-link:hover{opacity:1;background:#1d5986;}
.small-note{font-size:.6rem;color:#6b7489;margin-top:6px;}
.back-link{display:inline-block;margin:0 0 18px;text-decoration:none;font-size:.75rem;font-weight:600;color:#2b5cbc;}
.back-link:hover{text-decoration:underline;}
.version-tag{font-size:.55rem;background:#e1e7f3;color:#2d3d59;padding:4px 6px;border-radius:6px;margin-left:6px;font-weight:600;letter-spacing:.5px;}
</style>

<div class="profile-wrapper">
  <div class="card">
    <a class="back-link" href="customers.php">&larr; Back to Customers</a>
    <div class="section-title">Customer Profile</div>

    <?php if(!empty($cust['updated_at'])): ?>
      <div class="small-note">Last updated: <?= esc($cust['updated_at']) ?></div>
    <?php endif; ?>

    <table class="profile-table">
      <tr><th>Full Name</th><td><?= esc($cust['full_name']) ?></td></tr>
      <tr><th>Username</th><td><?= esc($cust['username']) ?></td></tr>
      <tr><th>Email</th><td><?= esc($cust['email']) ?></td></tr>
      <tr><th>Phone</th><td><?= esc($displayPhone) ?></td></tr>
      <tr><th>NRIC / ID</th><td><?= esc($displayId) ?></td></tr>
      <tr><th>Age</th><td><?= (int)$cust['age'] ?></td></tr>
      <tr><th>Address</th><td><?= nl2br(esc($cust['address'])) ?></td></tr>
    </table>

    <div class="section-title" style="margin-top:30px;font-size:1rem;">Identity Documents
    </div>
    <div class="image-grid">
      <div class="image-thumb">
        ID Front
        <?php if($cust['has_id_front_image']): ?>
          <a class="full-link" target="_blank" href="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=front<?= $verParam ?>">Open</a>
          <img src="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=front<?= $verParam ?>" alt="ID Front">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
      <div class="image-thumb">
        ID Back
        <?php if($cust['has_id_back_image']): ?>
          <a class="full-link" target="_blank" href="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=back<?= $verParam ?>">Open</a>
          <img src="../customer/get_id_image.php?type=back&cust_id=<?= $cust_id . $verParam ?>" alt="ID Back">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
      <div class="image-thumb">
        License Front
        <?php if($cust['has_license_front_image']): ?>
          <a class="full-link" target="_blank" href="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=license_front<?= $verParam ?>">Open</a>
          <img src="../customer/get_id_image.php?type=license_front&cust_id=<?= $cust_id . $verParam ?>" alt="License Front">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
      <div class="image-thumb">
        License Back
        <?php if($cust['has_license_back_image']): ?>
          <a class="full-link" target="_blank" href="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=license_back<?= $verParam ?>">Open</a>
          <img src="../customer/get_id_image.php?type=license_back&cust_id=<?= $cust_id . $verParam ?>" alt="License Back">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>