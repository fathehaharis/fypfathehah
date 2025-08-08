<?php
session_start();
if (empty($_SESSION['cust_id'])) { header("Location: /index.php"); exit; }
$cust_id = (int)$_SESSION['cust_id'];

require '../connect.php';

$stmt = $conn->prepare("SELECT full_name, phone_no, email, id_no, address, age,
       id_front_image, id_back_image, license_front_image, license_back_image,
       profile_status, profile_rejection_reason
  FROM customer WHERE cust_id=? LIMIT 1");
$stmt->bind_param("i",$cust_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) { header("Location: profile.php?msg=".urlencode("User not found")); exit; }

$requiredFields = ['full_name','phone_no','email','id_no','address','age'];
$requiredImages = ['id_front_image','id_back_image','license_front_image','license_back_image'];

$missing = [];
foreach ($requiredFields as $f) {
    if (empty(trim((string)$user[$f]))) $missing[] = $f;
}
foreach ($requiredImages as $f) {
    if (empty($user[$f])) $missing[] = $f;
}

if ($missing) {
    header("Location: profile.php?msg=".urlencode("Cannot submit. Missing: ".implode(', ',$missing)));
    exit;
}

$currentStatus = $user['profile_status'];

if (in_array($currentStatus, ['pending','verified'], true)) {
    // Already submitted or approved; no need to re-submit unless user changed and triggered re-verification.
    header("Location: profile.php?msg=".urlencode("Profile already $currentStatus."));
    exit;
}

$newStatus = $currentStatus === 'pending_reverification' ? 'pending_reverification' : 'pending';

$upd = $conn->prepare("UPDATE customer
   SET profile_status=?, profile_status_updated_at=NOW(), profile_rejection_reason=NULL
   WHERE cust_id=?");
$upd->bind_param("si",$newStatus,$cust_id);
$upd->execute();
$upd->close();

header("Location: profile.php?msg=".urlencode("Profile submitted for verification."));
exit;