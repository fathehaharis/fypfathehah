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
function badgeClass(string $status): string {
    return match($status) {
        'verified'                         => 'badge-ok',
        'pending','pending_reverification' => 'badge-warn',
        'rejected'                         => 'badge-error',
        default                            => 'badge-neutral'
    };
}

/* ---------- Handle Submit for Verification POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_for_verification'])) {
    $checkStmt = $conn->prepare(
        "SELECT profile_status,
                (id_front_image IS NOT NULL AND id_front_image <> '')             AS has_front,
                (id_back_image IS NOT NULL AND id_back_image <> '')               AS has_back,
                (license_front_image IS NOT NULL AND license_front_image <> '')   AS has_lfront,
                (license_back_image IS NOT NULL AND license_back_image <> '')     AS has_lback,
                (selfie_with_id_image IS NOT NULL AND selfie_with_id_image <> '') AS has_selfie
         FROM customer WHERE cust_id=? LIMIT 1"
    );
    $checkStmt->bind_param("i", $cust_id);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();
    $row = $checkRes->fetch_assoc();
    $checkStmt->close();

    if (!$row) {
        $action_err = "Profile not found.";
    } else {
        $currentStatus = $row['profile_status'];
        if (!in_array($currentStatus, ['unsubmitted','rejected'], true)) {
            $action_err = "Profile cannot be submitted in its current state.";
        } else {
            $missing = [];
            if (!$row['has_front'])   $missing[] = "ID Front Image";
            if (!$row['has_back'])    $missing[] = "ID Back Image";
            if (!$row['has_lfront'])  $missing[] = "License Front Image";
            if (!$row['has_lback'])   $missing[] = "License Back Image";
            if (!$row['has_selfie'])  $missing[] = "Selfie with ID Image";
            if ($missing) {
                $action_err = "Please upload required images before submission: " . implode(', ', $missing);
            } else {
                $upd = $conn->prepare(
                    "UPDATE customer
                     SET profile_status='pending',
                         profile_rejection_reason=NULL,
                         profile_status_updated_at=NOW()
                     WHERE cust_id=? LIMIT 1"
                );
                $upd->bind_param("i", $cust_id);
                if ($upd->execute()) {
                    $action_msg = "Profile submitted for verification.";
                } else {
                    $action_err = "Submission failed: " . $upd->error;
                }
                $upd->close();
            }
        }
    }
}

/* ---------- Fetch full profile (no large blobs) ---------- */
$stmt = $conn->prepare(
    "SELECT full_name, username, email, phone_no, id_no, address, age,
            profile_status, profile_rejection_reason,
            DATE_FORMAT(profile_status_updated_at,'%Y-%m-%d %H:%i:%s') AS status_updated_at,
            (id_front_image IS NOT NULL AND id_front_image <> '')             AS has_id_front,
            (id_back_image IS NOT NULL AND id_back_image <> '')               AS has_id_back,
            (license_front_image IS NOT NULL AND license_front_image <> '')   AS has_license_front,
            (license_back_image IS NOT NULL AND license_back_image <> '')     AS has_license_back,
            (selfie_with_id_image IS NOT NULL AND selfie_with_id_image <> '') AS has_selfie,
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

/* ---------- Check for active/approved bookings to lock editing ---------- */
$hasActiveBooking = false;
$ab = $conn->prepare(
    "SELECT EXISTS(
         SELECT 1
           FROM booking
          WHERE cust_id = ?
            AND status IN ('confirmed','approved','upcoming')
            AND (return_datetime IS NULL OR return_datetime >= NOW())
     ) AS has_active"
);
$ab->bind_param("i", $cust_id);
$ab->execute();
$ab->bind_result($has_active_val);
$ab->fetch();
$ab->close();
$hasActiveBooking = (bool)$has_active_val;

/* ---------- Prepare Display Values ---------- */
$display_phone  = format_phone_display($user['phone_no']);
$display_id     = format_nric_display($user['id_no']);
$status         = $user['profile_status'];
$rejectionReason= $user['profile_rejection_reason'];
$statusUpdated  = $user['status_updated_at'] ?? '';
$imagesVersion  = (int)($user['images_version'] ?? 0);
$vParam         = '&v=' . $imagesVersion;

$statusLabel = match($status) {
    'unsubmitted'           => 'Not Submitted',
    'pending'               => 'Pending Verification',
    'verified'              => 'Verified',
    'rejected'              => 'Rejected',
    'pending_reverification'=> 'Pending Re-Verification',
    default                 => 'Unknown'
};

$canSubmitForVerification = in_array($status, ['unsubmitted','rejected'], true);
$showSubmitButton = $canSubmitForVerification;

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
.status-row { display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px; }
.badge { display:inline-block;padding:5px 11px 6px;font-size:.74em;border-radius:20px;font-weight:600;letter-spacing:.5px; }
.badge-ok { background:#e4f8ea;color:#1c6a34; }
.badge-warn { background:#fff7db;color:#7a5d00; }
.badge-error { background:#ffe2e2;color:#902121; }
.badge-neutral { background:#e7ebf2;color:#33415c; }
.notice-box, .msg-box { padding:10px 14px;border-radius:10px;font-size:.8em;margin:0 0 16px; }
.notice-box { background:#e9eef9;border:1px solid #cfd8ec;color:#2f3d62; }
.msg-box.success { background:#e4f6eb;border:1px solid #bde6cc;color:#1f6d36; }
.msg-box.error { background:#ffe2e2;border:1px solid #f5b5b5;color:#8c1f1f; }
.profile-table { width:100%;border-collapse:collapse;font-size:.9em; }
.profile-table th { text-align:left;width:170px;padding:8px 6px 6px;vertical-align:top;color:#334467;font-weight:600;background:#f5f7fb;border-right:1px solid #e1e7f1; }
.profile-table td { padding:8px 12px 6px;background:#fafbfe;color:#222; }
.action-panel form { margin:0; }
.submit-btn {
    width:100%;border:none;background:#3c4cb8;color:#fff;padding:13px 0;border-radius:9px;font-weight:600;font-size:.92em;cursor:pointer;margin-top:8px;transition:.18s background;
}
.submit-btn:hover { background:#234c96; }
.submit-btn:disabled { background:#c3c8d4;color:#526077;cursor:not-allowed; }
.edit-btn {
    width:100%;display:block;text-align:center;text-decoration:none;background:#2e7bbd;color:#fff;padding:12px 0;margin-top:18px;border-radius:9px;font-weight:600;font-size:.92em;transition:.18s;
}
.edit-btn:hover { background:#1e5c8d; }
.edit-btn.disabled { background:#9aa6b7; cursor:not-allowed; pointer-events:none; }
.rejection-box {
    margin-top:14px;background:#ffe9e9;border:1px solid #ffc7c7;padding:10px 12px;border-radius:10px;font-size:.78em;color:#7d2020;
}
.small-note { font-size:.7em;color:#6b7489;margin-top:6px; }
.image-grid { display:grid;grid-template-columns: repeat(auto-fill, minmax(140px,1fr));gap:12px;margin-top:6px; }
.image-thumb { background:#f3f6fb;border:1px solid #d9e2f1;border-radius:10px;padding:6px;text-align:center;font-size:.65em;color:#445067; }
.image-thumb img { width:100%;height:90px;object-fit:cover;border-radius:6px; }
.image-thumb a { display:block; text-decoration:none; color:inherit; }

/* Lightbox */
.lb-backdrop {
    position:fixed; inset:0; background:rgba(14,23,43,.75);
    display:none; align-items:center; justify-content:center; z-index:9999;
}
.lb-backdrop.show { display:flex; }
.lb-content { max-width:92vw; max-height:92vh; }
.lb-img { max-width:100%; max-height:92vh; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.35); }
.lb-close {
    position:fixed; top:14px; right:18px; background:rgba(255,255,255,.9); border-radius:999px; padding:8px 12px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.2);
}
.pending-note {
    font-size:.7em;margin-top:10px;padding:8px 10px;border-radius:8px;background:#fff9e1;border:1px solid #f6e2a6;color:#6a5300;
}
.lock-note {
    font-size:.75em; color:#7a2636; background:#fdeef0; border:1px solid #f6c9d0; padding:8px 10px; border-radius:8px; margin-top:10px;
}
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

        <div class="status-row">
            <span class="badge <?= badgeClass($status) ?>"><?= htmlspecialchars($statusLabel) ?></span>
            <?php if ($statusUpdated): ?>
                <span style="font-size:.68em;color:#6a7489;">Updated: <?= htmlspecialchars($statusUpdated) ?></span>
            <?php endif; ?>
            <span style="font-size:.62em;color:#6a7489;">Img Ver: <?= $imagesVersion ?></span>
        </div>

        <?php if ($status === 'rejected' && $rejectionReason): ?>
            <div class="rejection-box">
                <strong>Rejection Reason:</strong><br><?= nl2br(htmlspecialchars($rejectionReason)) ?>
            </div>
        <?php elseif ($status === 'pending_reverification'): ?>
            <div class="pending-note">
                Your changes are under re-verification. Bookings are temporarily blocked until approval.
            </div>
        <?php elseif ($status === 'pending'): ?>
            <div class="pending-note">
                Your profile is awaiting admin review. You will be notified once approved.
            </div>
        <?php endif; ?>

        <table class="profile-table" style="margin-top:16px;">
            <tr><th>Full Name</th><td><?= htmlspecialchars($user['full_name']) ?></td></tr>
            <tr><th>Username</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
            <tr><th>Email</th><td><?= htmlspecialchars($user['email']) ?></td></tr>
            <tr><th>Phone</th><td><?= htmlspecialchars($display_phone) ?></td></tr>
            <tr><th>NRIC / ID</th><td><?= htmlspecialchars($display_id) ?></td></tr>
            <tr><th>Age</th><td><?= (int)$user['age'] ?></td></tr>
            <tr><th>Address</th><td><?= nl2br(htmlspecialchars($user['address'])) ?></td></tr>
            <tr>
                <th>Verification Status</th>
                <td>
                    <span class="badge <?= badgeClass($status) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    <?php if ($status === 'verified'): ?>
                        <div class="small-note">All booking features available.</div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <?php if ($hasActiveBooking): ?>
            <a class="edit-btn disabled" href="#" aria-disabled="true" title="Editing disabled due to an upcoming/ongoing booking">Edit Profile</a>
            <div class="lock-note">Editing is temporarily disabled because you have an approved/ongoing booking. You can edit your profile again after the booking ends.</div>
        <?php else: ?>
            <a class="edit-btn" href="edit_profile.php">Edit Profile</a>
        <?php endif; ?>
    </div>

    <!-- Right: Verification / Images -->
    <div class="card action-panel">
        <div class="section-title">Identity Documents</div>

        <div class="image-grid">
            <div class="image-thumb">
                <strong>ID Front</strong>
                <?php if ($user['has_id_front']): ?>
                    <?php $src = "get_id_image.php?type=front&cust_id={$cust_id}{$vParam}"; ?>
                    <a href="<?= htmlspecialchars($src) ?>" class="img-link" data-fullsrc="<?= htmlspecialchars($src) ?>" title="ID Front">
                        <img src="<?= htmlspecialchars($src) ?>" alt="ID Front">
                    </a>
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
            <div class="image-thumb">
                <strong>ID Back</strong>
                <?php if ($user['has_id_back']): ?>
                    <?php $src = "get_id_image.php?type=back&cust_id={$cust_id}{$vParam}"; ?>
                    <a href="<?= htmlspecialchars($src) ?>" class="img-link" data-fullsrc="<?= htmlspecialchars($src) ?>" title="ID Back">
                        <img src="<?= htmlspecialchars($src) ?>" alt="ID Back">
                    </a>
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
            <div class="image-thumb">
                <strong>License Front</strong>
                <?php if ($user['has_license_front']): ?>
                    <?php $src = "get_id_image.php?type=license_front&cust_id={$cust_id}{$vParam}"; ?>
                    <a href="<?= htmlspecialchars($src) ?>" class="img-link" data-fullsrc="<?= htmlspecialchars($src) ?>" title="License Front">
                        <img src="<?= htmlspecialchars($src) ?>" alt="License Front">
                    </a>
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
            <div class="image-thumb">
                <strong>License Back</strong>
                <?php if ($user['has_license_back']): ?>
                    <?php $src = "get_id_image.php?type=license_back&cust_id={$cust_id}{$vParam}"; ?>
                    <a href="<?= htmlspecialchars($src) ?>" class="img-link" data-fullsrc="<?= htmlspecialchars($src) ?>" title="License Back">
                        <img src="<?= htmlspecialchars($src) ?>" alt="License Back">
                    </a>
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
            <div class="image-thumb">
                <strong>Selfie with ID</strong>
                <?php if (!empty($user['has_selfie'])): ?>
                    <?php $src = "get_id_image.php?type=selfie_with_id&cust_id={$cust_id}{$vParam}"; ?>
                    <a href="<?= htmlspecialchars($src) ?>" class="img-link" data-fullsrc="<?= htmlspecialchars($src) ?>" title="Selfie with ID">
                        <img src="<?= htmlspecialchars($src) ?>" alt="Selfie with ID">
                    </a>
                <?php else: ?>
                    <div style="padding:14px 4px;">No Image</div>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" style="margin-top:22px;">
            <input type="hidden" name="submit_for_verification" value="1">
            <button type="submit"
                    class="submit-btn"
                    <?= $showSubmitButton ? '' : 'disabled' ?>>
                <?= $status === 'rejected' ? 'Resubmit for Verification' : 'Submit for Verification' ?>
            </button>
        </form>

        <?php if (!$showSubmitButton): ?>
            <div class="small-note" style="margin-top:10px;">
                <?php if ($status === 'pending'): ?>
                    Awaiting admin approval.
                <?php elseif ($status === 'pending_reverification'): ?>
                    Awaiting re-verification.
                <?php elseif ($status === 'verified'): ?>
                    Already verified.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="small-note" style="margin-top:10px;">
                Make sure all details & images are correct before submitting.
            </div>
        <?php endif; ?>
        <div class="small-note" style="margin-top:10px;">
            Make sure all details & images are correct before booking.
        </div>
    </div>
</div>

<!-- Lightbox HTML -->
<div class="lb-backdrop" id="lbBackdrop" aria-hidden="true">
    <div class="lb-close" id="lbClose" role="button" tabindex="0">Close ✕</div>
    <div class="lb-content">
        <img class="lb-img" id="lbImg" src="" alt="">
    </div>
</div>

<script>
(function(){
    const backdrop = document.getElementById('lbBackdrop');
    const imgEl = document.getElementById('lbImg');
    const btnClose = document.getElementById('lbClose');

    function openLB(src, alt) {
        imgEl.src = src;
        imgEl.alt = alt || '';
        backdrop.classList.add('show');
        backdrop.setAttribute('aria-hidden', 'false');
    }
    function closeLB() {
        backdrop.classList.remove('show');
        backdrop.setAttribute('aria-hidden', 'true');
        imgEl.src = '';
        imgEl.alt = '';
    }

    document.querySelectorAll('.img-link').forEach(function(a){
        a.addEventListener('click', function(e){
            e.preventDefault();
            const src = a.getAttribute('data-fullsrc') || a.getAttribute('href');
            const alt = (a.getAttribute('title') || a.querySelector('img')?.alt || '').trim();
            openLB(src, alt);
        });
    });

    backdrop.addEventListener('click', function(e){
        if (e.target === backdrop) closeLB();
    });
    btnClose.addEventListener('click', closeLB);
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeLB();
    });
})();
</script>

<?php include '../includes/footer.php'; ?>