<?php
/**
 * Admin view of a single customer profile
 * Adds verification actions (Verify / Reject) and shows selfie_with_id image
 * Uses images_version for cache-busting (&v=images_version)
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

/* CSRF */
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];

/* Helpers */
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
function status_label($s){
    return match($s){
        'unsubmitted'            => 'Not Submitted',
        'pending'                => 'Pending Verification',
        'verified'               => 'Verified',
        'rejected'               => 'Rejected',
        'pending_reverification' => 'Pending Re-Verification',
        'suspended'              => 'Suspended',
        default                  => 'Unknown'
    };
}
function status_class($s){
    return match($s){
        'verified'               => 'badge-ok',
        'pending','pending_reverification' => 'badge-warn',
        'rejected','suspended'   => 'badge-error',
        default                  => 'badge-neutral'
    };
}

$msg_ok = '';
$msg_err = '';

/* Handle POST actions: verify / reject */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token  = $_POST['csrf'] ?? '';

    if (!hash_equals($csrf, $token)) {
        $msg_err = 'Invalid request. Please refresh and try again.';
    } else {
        // Fetch current status and image presence for validation
        $check = $conn->prepare(
            "SELECT profile_status,
                    (id_front_image IS NOT NULL AND id_front_image <> '')             AS has_front,
                    (id_back_image IS NOT NULL AND id_back_image <> '')               AS has_back,
                    (license_front_image IS NOT NULL AND license_front_image <> '')   AS has_lfront,
                    (license_back_image IS NOT NULL AND license_back_image <> '')     AS has_lback,
                    (selfie_with_id_image IS NOT NULL AND selfie_with_id_image <> '') AS has_selfie
             FROM customer WHERE cust_id=? LIMIT 1"
        );
        $check->bind_param('i',$cust_id);
        $check->execute();
        $r = $check->get_result();
        $state = $r->fetch_assoc();
        $check->close();

        if (!$state) {
            $msg_err = 'Customer not found.';
        } else {
            $cur = $state['profile_status'];

            if ($action === 'verify') {
                // Only allow verify from pending states
                if (!in_array($cur, ['pending','pending_reverification'], true)) {
                    $msg_err = 'Cannot verify from current status.';
                } else {
                    // Require all images present before verification
                    $missing = [];
                    if (!$state['has_front'])  $missing[] = 'ID Front';
                    if (!$state['has_back'])   $missing[] = 'ID Back';
                    if (!$state['has_lfront']) $missing[] = 'License Front';
                    if (!$state['has_lback'])  $missing[] = 'License Back';
                    if (!$state['has_selfie']) $missing[] = 'Selfie with ID';
                    if ($missing) {
                        $msg_err = 'Cannot verify. Missing: ' . esc(implode(', ', $missing));
                    } else {
                        $upd = $conn->prepare("UPDATE customer
                                               SET profile_status='verified',
                                                   profile_rejection_reason=NULL,
                                                   profile_status_updated_at=NOW()
                                               WHERE cust_id=? LIMIT 1");
                        $upd->bind_param('i',$cust_id);
                        if ($upd->execute()) {
                            $msg_ok = 'Profile verified successfully.';
                        } else {
                            $msg_err = 'Verify failed: '.$upd->error;
                        }
                        $upd->close();
                    }
                }
            } elseif ($action === 'reject') {
                if (!in_array($cur, ['pending','pending_reverification'], true)) {
                    $msg_err = 'Cannot reject from current status.';
                } else {
                    $reason = trim((string)($_POST['reason'] ?? ''));
                    if ($reason === '') {
                        $msg_err = 'Please provide a rejection reason.';
                    } else {
                        // Cap to 255 per DDL
                        if (function_exists('mb_substr')) {
                            $reason = mb_substr($reason, 0, 255, 'UTF-8');
                        } else {
                            $reason = substr($reason, 0, 255);
                        }
                        $upd = $conn->prepare("UPDATE customer
                                               SET profile_status='rejected',
                                                   profile_rejection_reason=?,
                                                   profile_status_updated_at=NOW()
                                               WHERE cust_id=? LIMIT 1");
                        $upd->bind_param('si',$reason,$cust_id);
                        if ($upd->execute()) {
                            $msg_ok = 'Profile rejected.';
                        } else {
                            $msg_err = 'Reject failed: '.$upd->error;
                        }
                        $upd->close();
                    }
                }
            } else {
                $msg_err = 'Unknown action.';
            }
        }
    }
}

/* Fetch fresh record (no full blobs needed, use presence flags + version) */
$stmt = $conn->prepare(
 "SELECT cust_id, full_name, username, email, phone_no, id_no, address, age,
         profile_status, profile_rejection_reason,
         DATE_FORMAT(profile_status_updated_at,'%Y-%m-%d %H:%i:%s') AS updated_at,
         (id_front_image IS NOT NULL AND id_front_image <> '')             AS has_id_front_image,
         (id_back_image IS NOT NULL AND id_back_image <> '')               AS has_id_back_image,
         (license_front_image IS NOT NULL AND license_front_image <> '')   AS has_license_front_image,
         (license_back_image IS NOT NULL AND license_back_image <> '')     AS has_license_back_image,
         (selfie_with_id_image IS NOT NULL AND selfie_with_id_image <> '') AS has_selfie_with_id_image,
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
  max-width:820px; /* a bit wider to fit actions */
}

.section-title{font-size:1.2rem;font-weight:800;color:#2f377d;margin:0 0 18px;}
.profile-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:8px;}
.profile-table th{width:160px;text-align:left;padding:8px 8px 6px;background:#f5f7fb;color:#334467;font-weight:600;border-right:1px solid #e1e7f1;vertical-align:top;}
.profile-table td{padding:8px 12px 6px;background:#fafbfe;}
.status-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0;}
.badge{display:inline-block;padding:5px 11px 6px;font-size:.74rem;border-radius:20px;font-weight:700;letter-spacing:.4px;}
.badge-ok{background:#e4f8ea;color:#1c6a34;}
.badge-warn{background:#fff7db;color:#7a5d00;}
.badge-error{background:#ffe2e2;color:#902121;}
.badge-neutral{background:#e7ebf2;color:#33415c;}
.msg{padding:10px 12px;border-radius:10px;font-size:.82rem;margin:0 0 14px;}
.msg.ok{background:#e4f6eb;border:1px solid #bde6cc;color:#1f6d36;}
.msg.err{background:#ffe2e2;border:1px solid #f5b5b5;color:#8c1f1f;}
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
.actions{
  margin-top:18px; padding:14px; border-radius:12px; background:#f5f7fd; border:1px solid #dbe3fb;
}
.actions h4{margin:0 0 10px; font-size:.95rem; color:#2f377d;}
.actions .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.btn{
  display:inline-block; border:none; cursor:pointer; font-weight:700; border-radius:8px; padding:10px 14px; font-size:.85rem;
}
.btn-verify{background:#1e9154;color:#fff;}
.btn-verify:hover{background:#167244;}
.btn-reject{background:#c23b2a;color:#fff;}
.btn-reject:hover{background:#9f2f22;}
.reason{
  min-width:280px; max-width:100%; flex:1 1 320px; border:1px solid #cfd8ec; border-radius:8px; padding:8px 10px; font-size:.85rem;
  background:#fff;
}
.help{font-size:.72rem;color:#5f6b86;margin-top:6px;}
</style>

<div class="profile-wrapper">
  <div class="card">
    <a class="back-link" href="customers.php">&larr; Back to Customers</a>
    <div class="section-title">Customer Profile <span class="version-tag">Img Ver <?= (int)$cust['images_version'] ?></span></div>

    <?php if ($msg_ok): ?><div class="msg ok"><?= esc($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="msg err"><?= esc($msg_err) ?></div><?php endif; ?>

    <div class="status-row">
      <span class="badge <?= esc(status_class($cust['profile_status'])) ?>"><?= esc(status_label($cust['profile_status'])) ?></span>
      <?php if(!empty($cust['updated_at'])): ?>
        <span class="small-note">Updated: <?= esc($cust['updated_at']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cust['profile_rejection_reason'])): ?>
        <span class="small-note" style="color:#8a2d2d;">Reason: <?= esc($cust['profile_rejection_reason']) ?></span>
      <?php endif; ?>
    </div>

    <table class="profile-table">
      <tr><th>Full Name</th><td><?= esc($cust['full_name']) ?></td></tr>
      <tr><th>Username</th><td><?= esc($cust['username']) ?></td></tr>
      <tr><th>Email</th><td><?= esc($cust['email']) ?></td></tr>
      <tr><th>Phone</th><td><?= esc($displayPhone) ?></td></tr>
      <tr><th>NRIC / ID</th><td><?= esc($displayId) ?></td></tr>
      <tr><th>Age</th><td><?= (int)$cust['age'] ?></td></tr>
      <tr><th>Address</th><td><?= nl2br(esc($cust['address'])) ?></td></tr>
    </table>

    <?php
      $allowActions = in_array($cust['profile_status'], ['pending','pending_reverification'], true);
    ?>
    <div class="actions">
      <h4>Verification Actions</h4>
      <?php if ($allowActions): ?>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <button type="submit" name="action" value="verify" class="btn btn-verify">Verify Profile</button>
          <span class="help">Verify only if details match and documents are clear and valid.</span>
        </form>

        <form method="post" class="row" style="margin-top:10px;">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <textarea class="reason" name="reason" rows="2" placeholder="Rejection reason (required)"></textarea>
          <button type="submit" name="action" value="reject" class="btn btn-reject">Reject Profile</button>
        </form>
        <div class="help">Required documents: ID Front, ID Back, License Front, License Back, Selfie with ID.</div>
      <?php else: ?>
        <div class="help">Actions are available only when status is Pending or Pending Re-Verification.</div>
      <?php endif; ?>
    </div>

    <div class="section-title" style="margin-top:30px;font-size:1rem;">Identity Documents</div>
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
          <img src="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=back<?= $verParam ?>" alt="ID Back">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
      <div class="image-thumb">
        License Front
        <?php if($cust['has_license_front_image']): ?>
          <a class="full-link" target="_blank" href="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=license_front<?= $verParam ?>">Open</a>
          <img src="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=license_front<?= $verParam ?>" alt="License Front">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
      <div class="image-thumb">
        License Back
        <?php if($cust['has_license_back_image']): ?>
          <a class="full-link" target="_blank" href="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=license_back<?= $verParam ?>">Open</a>
          <img src="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=license_back<?= $verParam ?>" alt="License Back">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
      <div class="image-thumb">
        Selfie with ID
        <?php if($cust['has_selfie_with_id_image']): ?>
          <a class="full-link" target="_blank" href="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=selfie_with_id<?= $verParam ?>">Open</a>
          <img src="../customer/get_id_image.php?cust_id=<?= $cust_id ?>&type=selfie_with_id<?= $verParam ?>" alt="Selfie with ID">
        <?php else: ?><div class="no-img">No Image</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>