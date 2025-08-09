<?php
/**
 * Admin view of a single customer profile
 * Allows verification actions: Verify / Reject / Move to Re-Verification
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

/* CSRF */
if (empty($_SESSION['csrf_token_admin'])) {
    $_SESSION['csrf_token_admin'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token_admin'];

$flashMsg = '';
$flashErr = '';

function esc($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function badgeClass($status){
    return match($status){
        'verified'=>'badge-ok',
        'pending','pending_reverification'=>'badge-warn',
        'rejected'=>'badge-error',
        default=>'badge-neutral'
    };
}
$statusLabels = [
    'unsubmitted'=>'Not Submitted',
    'pending'=>'Pending Verification',
    'verified'=>'Verified',
    'rejected'=>'Rejected',
    'pending_reverification'=>'Pending Re-Verification'
];
/* Formatting helpers */
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

/* Handle POST actions */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action_type'])) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $flashErr = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action_type'];
        $cid    = (int)($_POST['cust_id'] ?? 0);
        if ($cid !== $cust_id) {
            $flashErr = 'Customer mismatch.';
        } else {
            $stmt = $conn->prepare("SELECT profile_status FROM customer WHERE cust_id=? LIMIT 1");
            $stmt->bind_param('i',$cid);
            $stmt->execute();
            $stmt->bind_result($curStatus);
            $found = $stmt->fetch();
            $stmt->close();

            if (!$found) {
                $flashErr = 'Customer not found.';
            } else {
                if ($action==='verify') {
                    if (!in_array($curStatus,['pending','pending_reverification','rejected','unsubmitted'],true)) {
                        $flashErr='Cannot verify from current status.';
                    } else {
                        $u=$conn->prepare("UPDATE customer SET profile_status='verified', profile_rejection_reason=NULL, profile_status_updated_at=NOW() WHERE cust_id=? LIMIT 1");
                        $u->bind_param('i',$cid);
                        $ok = $u->execute();
                        if ($ok) {
                            $flashMsg = "Customer verified.";
                        } else {
                            $flashErr = "Failed to update.";
                        }
                        $u->close();
                    }
                } elseif ($action==='reject') {
                    $reason=trim($_POST['rejection_reason']??'');
                    if (mb_strlen($reason)<4) {
                        $flashErr='Rejection reason too short.';
                    } else {
                        $u=$conn->prepare("UPDATE customer SET profile_status='rejected', profile_rejection_reason=?, profile_status_updated_at=NOW() WHERE cust_id=? LIMIT 1");
                        $u->bind_param('si',$reason,$cid);
                        $ok = $u->execute();
                        if ($ok) {
                            $flashMsg = "Customer rejected.";
                        } else {
                            $flashErr = "Failed to reject.";
                        }
                        $u->close();
                    }
                } elseif ($action==='reverify') {
                    if ($curStatus!=='verified') {
                        $flashErr='Only verified profiles can go to re-verification.';
                    } else {
                        $u=$conn->prepare("UPDATE customer SET profile_status='pending_reverification', profile_status_updated_at=NOW() WHERE cust_id=? LIMIT 1");
                        $u->bind_param('i',$cid);
                        $ok = $u->execute();
                        if ($ok) {
                            $flashMsg = "Moved to re-verification.";
                        } else {
                            $flashErr = "Failed to update.";
                        }
                        $u->close();
                    }
                } else {
                    $flashErr='Unknown action.';
                }
            }
        }
    }
}

/* Fetch fresh record (no full blobs needed, use presence flags + version) */
$stmt = $conn->prepare(
 "SELECT cust_id, full_name, username, email, phone_no, id_no, address, age,
         profile_status, profile_rejection_reason,
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
$status       = $cust['profile_status'];
$statusLabel  = $statusLabels[$status] ?? ucfirst($status);
$imgVersion   = (int)($cust['images_version'] ?? 0);
$verParam     = '&v=' . $imgVersion;

include 'admin_header.php';
?>
<style>
.profile-wrapper{max-width:1000px;margin:40px auto 80px;padding:0 18px;display:grid;grid-template-columns:1fr 320px;gap:34px;}
@media (max-width:1000px){.profile-wrapper{grid-template-columns:1fr;}}
.card{background:#fff;border-radius:18px;box-shadow:0 4px 18px rgba(40,55,95,0.12);padding:28px 32px 34px;position:relative;}
.section-title{font-size:1.2rem;font-weight:800;color:#2f377d;margin:0 0 18px;}
.badge{display:inline-block;padding:6px 14px;font-size:.65rem;border-radius:20px;font-weight:600;letter-spacing:.5px;}
.badge-ok{background:#e4f8ea;color:#1c6a34;}
.badge-warn{background:#fff7db;color:#7a5d00;}
.badge-error{background:#ffe2e2;color:#902121;}
.badge-neutral{background:#e7ebf2;color:#33415c;}
.status-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
.msg-box{padding:10px 14px;border-radius:10px;font-size:.75rem;margin:0 0 14px;}
.msg-ok{background:#e4f6eb;border:1px solid #bde6cc;color:#1f6d36;}
.msg-err{background:#ffe2e2;border:1px solid #f5b5b5;color:#8c1f1f;}
.profile-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:8px;}
.profile-table th{width:160px;text-align:left;padding:8px 8px 6px;background:#f5f7fb;color:#334467;font-weight:600;border-right:1px solid #e1e7f1;vertical-align:top;}
.profile-table td{padding:8px 12px 6px;background:#fafbfe;}
.rejection-box{margin-top:14px;background:#ffe9e9;border:1px solid #ffc7c7;padding:10px 12px;border-radius:10px;font-size:.7rem;color:#7d2020;}
.image-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;margin-top:8px;}
.image-thumb{background:#f3f6fb;border:1px solid #d9e2f1;border-radius:10px;padding:8px 8px 10px;text-align:center;font-size:.62rem;color:#445067;font-weight:600;position:relative;}
.image-thumb img{width:100%;height:100px;object-fit:cover;border-radius:6px;margin-top:6px;}
.no-img{height:100px;display:flex;align-items:center;justify-content:center;background:#eef2f7;border:1px dashed #cbd6e2;border-radius:6px;color:#6b7489;font-size:.6rem;margin-top:6px;}
.image-thumb a.full-link{position:absolute;top:6px;right:6px;font-size:.55rem;text-decoration:none;background:#2e7bbd;color:#fff;padding:3px 6px;border-radius:6px;font-weight:600;opacity:.9;}
.image-thumb a.full-link:hover{opacity:1;background:#1d5986;}
.action-panel form{margin:0;}
.btn{border:none;padding:12px 16px;border-radius:10px;font-weight:700;font-size:.7rem;cursor:pointer;letter-spacing:.5px;display:inline-block;margin:4px 6px 0 0;}
.btn-verify{background:#1f7a3d;color:#fff;}
.btn-verify:hover{background:#15562a;}
.btn-reject{background:#c93838;color:#fff;}
.btn-reject:hover{background:#942525;}
.btn-reverify{background:#d38a14;color:#fff;}
.btn-reverify:hover{background:#9f6509;}
.reject-reason{width:100%;min-height:90px;resize:vertical;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.75rem;font-family:inherit;margin-top:10px;}
.small-note{font-size:.6rem;color:#6b7489;margin-top:6px;}
.back-link{display:inline-block;margin:0 0 18px;text-decoration:none;font-size:.75rem;font-weight:600;color:#2b5cbc;}
.back-link:hover{text-decoration:underline;}
.version-tag{font-size:.55rem;background:#e1e7f3;color:#2d3d59;padding:4px 6px;border-radius:6px;margin-left:6px;font-weight:600;letter-spacing:.5px;}
</style>

<div class="profile-wrapper">
  <div class="card">
    <a class="back-link" href="customers.php">&larr; Back to Customers</a>
    <div class="section-title">Customer Profile</div>

    <?php if($flashMsg): ?><div class="msg-box msg-ok"><?= esc($flashMsg) ?></div><?php endif; ?>
    <?php if($flashErr): ?><div class="msg-box msg-err"><?= esc($flashErr) ?></div><?php endif; ?>

    <div class="status-row">
      <span class="badge <?= badgeClass($status) ?>"><?= esc($statusLabel) ?></span>
      <?php if(!empty($cust['updated_at'])): ?>
        <span style="font-size:.6rem;color:#6a7489;">Updated: <?= esc($cust['updated_at']) ?></span>
      <?php endif; ?>
      <span class="version-tag">Img Ver: <?= $imgVersion ?></span>
    </div>

    <?php if($status==='rejected' && $cust['profile_rejection_reason']): ?>
      <div class="rejection-box">
        <strong>Rejection Reason:</strong><br><?= nl2br(esc($cust['profile_rejection_reason'])) ?>
      </div>
    <?php elseif($status==='pending'): ?>
      <div class="rejection-box" style="background:#fff9e1;border-color:#f6e2a6;color:#6a5300;">
        Awaiting admin review.
      </div>
    <?php elseif($status==='pending_reverification'): ?>
      <div class="rejection-box" style="background:#fff9e1;border-color:#f6e2a6;color:#6a5300;">
        Changes submitted. Re-verification needed.
      </div>
    <?php endif; ?>

    <table class="profile-table">
      <tr><th>Full Name</th><td><?= esc($cust['full_name']) ?></td></tr>
      <tr><th>Username</th><td><?= esc($cust['username']) ?></td></tr>
      <tr><th>Email</th><td><?= esc($cust['email']) ?></td></tr>
      <tr><th>Phone</th><td><?= esc($displayPhone) ?></td></tr>
      <tr><th>NRIC / ID</th><td><?= esc($displayId) ?></td></tr>
      <tr><th>Age</th><td><?= (int)$cust['age'] ?></td></tr>
      <tr><th>Address</th><td><?= nl2br(esc($cust['address'])) ?></td></tr>
      <tr><th>Status</th><td><span class="badge <?= badgeClass($status) ?>"><?= esc($statusLabel) ?></span></td></tr>
    </table>

    <div class="section-title" style="margin-top:30px;font-size:1rem;">Identity Documents
      <span class="version-tag">Version <?= $imgVersion ?></span>
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

  <div class="card action-panel">
    <div class="section-title" style="font-size:1rem;">Admin Actions</div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
      <input type="hidden" name="cust_id" value="<?= $cust_id ?>">
      <div>
        <button name="action_type" value="verify" class="btn btn-verify"
          <?= $status==='verified' ? 'disabled style="opacity:.5;"':''; ?>>Verify</button>
        <button type="button" id="rejectToggle" class="btn btn-reject">Reject</button>
        <button name="action_type" value="reverify" class="btn btn-reverify"
          <?= $status!=='verified' ? 'disabled style="opacity:.5;"':''; ?>>Move to Re-Verification</button>
      </div>
      <div id="rejectBox" style="display:none;">
        <textarea class="reject-reason" name="rejection_reason" placeholder="Rejection reason (min 4 chars)"></textarea>
        <div class="small-note">Visible to the customer.</div>
        <button name="action_type" value="reject" class="btn btn-reject">Confirm Reject</button>
        <button type="button" id="rejectCancel" class="btn" style="background:#d0d7e4;color:#233;">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
const rejectToggle=document.getElementById('rejectToggle');
const rejectBox=document.getElementById('rejectBox');
const rejectCancel=document.getElementById('rejectCancel');
rejectToggle?.addEventListener('click',()=>{rejectBox.style.display='block';});
rejectCancel?.addEventListener('click',()=>{rejectBox.style.display='none';});
</script>

<?php include '../includes/footer.php'; ?>