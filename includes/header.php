<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>TimeLess Car Rental System</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
  <link rel="stylesheet" href="/assets/css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Montserrat:700,400&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Montserrat','Segoe UI',Arial,sans-serif; }
    .header-bar{background:linear-gradient(90deg,#2f377d,#3c4cb8);color:#fff;padding:22px 0 18px;
      box-shadow:0 2px 10px rgba(60,60,60,.10);display:flex;justify-content:space-between;align-items:center;}
    .header-title-logo{display:flex;align-items:center;gap:16px;margin-left:40px;}
    .header-title-logo img{height:38px;width:auto;filter:drop-shadow(0 2px 8px rgba(0,0,0,.07));}
    .site-title{font-size:1.8em;font-weight:700;margin:0;}
    .header-icons{display:flex;align-items:center;gap:26px;margin-right:40px;}
    .header-icon-btn{background:none;border:none;color:#fff;font-size:1.5em;cursor:pointer;position:relative;
      padding:0;margin:0 2px;display:flex;align-items:center;transition:color .18s;}
    .header-icon-btn:hover{color:#ffd600;}
    .notification-dot{position:absolute;top:.1em;right:.1em;background:#e54848;color:#fff;border-radius:50%;
      width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:.75em;font-weight:700;
      border:2px solid #fff;z-index:2;}
    .notif-dropdown{position:relative;display:inline-block;}
    .notif-menu{display:none;position:absolute;right:-10px;top:40px;background:#fff;min-width:320px;max-width:380px;
      box-shadow:0 4px 18px rgba(44,60,102,.15);border-radius:10px;overflow:hidden;z-index:120;}
    .notif-dropdown.active .notif-menu{display:block;}
    .notif-menu-header{background:#f1f3fa;color:#2f377d;font-weight:600;padding:10px 18px;border-bottom:1px solid #eee;}
    .notif-menu-list{max-height:340px;overflow-y:auto;padding:0;margin:0;list-style:none;}
    .notif-item{padding:12px 16px;border-bottom:1px solid #eee;font-size:.95em;color:#233;background:#fff;
      line-height:1.35em;display:flex;gap:10px;}
    .notif-item:last-child{border-bottom:none;}
    .notif-empty{padding:24px 18px;color:#aaa;font-size:.95em;text-align:center;}
    .notif-icon{font-size:1.2em;width:1.6em;text-align:center;flex-shrink:0;}
    .notif-approved{color:#188a2f;font-weight:600;}
    .notif-rejected,.notif-error{color:#d52d2d;font-weight:600;}
    .notif-info{color:#2f377d;font-weight:600;}
    .notif-warning{color:#d08800;font-weight:600;}
    .profile-dropdown{position:relative;display:inline-block;}
    .profile-btn{background:#fff;color:#2f377d;border-radius:50%;width:38px;height:38px;display:flex;align-items:center;
      justify-content:center;font-weight:bold;font-size:1.25em;border:none;cursor:pointer;
      box-shadow:0 1px 5px rgba(60,60,60,.10);transition:background .18s,color .18s;}
    .profile-btn:hover{background:#ffd600;color:#2f377d;}
    .profile-menu{display:none;position:absolute;right:0;top:48px;background:#fff;min-width:160px;
      box-shadow:0 4px 18px rgba(44,60,102,.15);border-radius:10px;overflow:hidden;z-index:100;}
    .profile-dropdown.active .profile-menu{display:block;}
    .profile-menu a{color:#2f377d;padding:13px 20px;text-decoration:none;display:block;font-size:1.04em;transition:background .15s;}
    .profile-menu a:hover{background:#f1f3fa;}
    .logout-link{color:#c62828;font-weight:600;border-top:1px solid #eee;}
    @media(max-width:700px){
      .header-bar{padding:14px 0 10px;}
      .header-title-logo{margin-left:14px;}
      .site-title{font-size:1.2em;}
      .header-icons{margin-right:14px;gap:16px;}
      .notif-menu{right:-18px;}
      .profile-menu{right:-18px;}
    }
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const profileDropdown = document.querySelector('.profile-dropdown');
      if (profileDropdown){
        profileDropdown.querySelector('.profile-btn').addEventListener('click', e=>{
          e.stopPropagation();
          profileDropdown.classList.toggle('active');
        });
        document.addEventListener('click', ()=>profileDropdown.classList.remove('active'));
      }
      const notifDropdown = document.querySelector('.notif-dropdown');
      if (notifDropdown){
        notifDropdown.querySelector('.header-icon-btn').addEventListener('click', e=>{
          e.stopPropagation();
          notifDropdown.classList.toggle('active');
        });
        document.addEventListener('click', ()=>notifDropdown.classList.remove('active'));
      }
    });
  </script>
</head>
<body>
<header class="header-bar">
  <div class="header-title-logo">
    <img src="/assets/images/TimeLess_logo.png" alt="Logo">
    <span class="site-title">TimeLess Car Rental</span>
  </div>
  <div class="header-icons">
    <?php
    $notif_items = [];
    $notif_count = 0;

    if (isset($_SESSION['cust_id'])) {
        include_once $_SERVER['DOCUMENT_ROOT'].'/connect.php';
        $cid = (int)$_SESSION['cust_id'];

        $bookingIdsToMark = [];
        $refundIdsToMark = [];
        $depositBookingIdsToMark = [];
        $serviceIdsToMark = [];
        $markProfileStatus = false;

        /* 1. Booking approved/rejected */
        $sql = "SELECT b.booking_id, b.status, c.car_brand, c.car_model
                FROM booking b
                LEFT JOIN car c ON b.car_id = c.car_id
                WHERE b.cust_id = $cid
                  AND b.status IN ('approved','rejected')
                  AND b.notified = 0";
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                $approved = ($r['status']==='approved');
                $notif_items[] = [
                    'type'=> $approved ? 'booking_approved':'booking_rejected',
                    'icon'=> $approved ? '✅':'❌',
                    'priority'=>10,
                    'message'=> ($approved
                      ? "Booking #{$r['booking_id']} approved for ".htmlspecialchars($r['car_brand'].' '.$r['car_model'])
                      : "Booking #{$r['booking_id']} rejected for ".htmlspecialchars($r['car_brand'].' '.$r['car_model']))
                ];
                $bookingIdsToMark[] = (int)$r['booking_id'];
            }
            $res->free();
        }

        /* 2. Profile status (verified / rejected) */
        $sql = "SELECT profile_status, profile_rejection_reason, profile_status_notified
                FROM customer
                WHERE cust_id = $cid LIMIT 1";
        if ($res = $conn->query($sql)) {
            if ($row = $res->fetch_assoc()) {
                if (in_array($row['profile_status'], ['verified','rejected'], true)
                    && (int)$row['profile_status_notified'] === 0) {
                    if ($row['profile_status'] === 'verified') {
                        $notif_items[] = [
                            'type'=>'profile_verified',
                            'icon'=>'🆗',
                            'priority'=>20,
                            'message'=>"Your profile has been verified."
                        ];
                    } else {
                        $reason = trim((string)$row['profile_rejection_reason']);
                        $notif_items[] = [
                            'type'=>'profile_rejected',
                            'icon'=>'⚠️',
                            'priority'=>25,
                            'message'=>"Your profile was rejected".($reason ? ": ".htmlspecialchars($reason) : ".")
                        ];
                    }
                    $markProfileStatus = true;
                }
            }
            $res->free();
        }

        /* 3. Refund lifecycle (pending, processed, failed) */
        $sql = "SELECT refund_id, amount, refund_status
                FROM refunds
                WHERE cust_id = $cid
                  AND user_unread = 1
                  AND refund_status IN ('pending','processed','failed')
                ORDER BY refund_id DESC";
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                switch ($r['refund_status']) {
                    case 'pending':
                        $notif_items[] = [
                            'type'=>'refund_pending',
                            'icon'=>'⏳',
                            'priority'=>28,
                            'message'=>"Refund #{$r['refund_id']} (RM ".number_format($r['amount'],2).") is being processed."
                        ];
                        break;
                    case 'processed':
                        $notif_items[] = [
                            'type'=>'refund_processed',
                            'icon'=>'💸',
                            'priority'=>30,
                            'message'=>"Refund #{$r['refund_id']} of RM ".number_format($r['amount'],2)." has been processed."
                        ];
                        break;
                    case 'failed':
                        $notif_items[] = [
                            'type'=>'refund_failed',
                            'icon'=>'❗',
                            'priority'=>35,
                            'message'=>"Refund #{$r['refund_id']} failed. Please contact support."
                        ];
                        break;
                }
                $refundIdsToMark[] = (int)$r['refund_id'];
            }
            $res->free();
        }

        /* 4. Deposit status (refunded / forfeited) */
        $sql = "SELECT booking_id, deposit_status
                FROM booking
                WHERE cust_id = $cid
                  AND deposit_status IN ('refunded','forfeited')
                  AND deposit_notified = 0";
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                if ($r['deposit_status'] === 'refunded') {
                    $notif_items[] = [
                        'type'=>'deposit_refunded',
                        'icon'=>'💰',
                        'priority'=>40,
                        'message'=>"Security deposit for Booking #{$r['booking_id']} has been refunded."
                    ];
                } else {
                    $notif_items[] = [
                        'type'=>'deposit_forfeited',
                        'icon'=>'🚫',
                        'priority'=>45,
                        'message'=>"Security deposit for Booking #{$r['booking_id']} was forfeited."
                    ];
                }
                $depositBookingIdsToMark[] = (int)$r['booking_id'];
            }
            $res->free();
        }

        /* 5. Service out_for_delivery */
        $sql = "SELECT s.service_id, s.service_type, s.booking_id
                FROM service s
                INNER JOIN booking b ON s.booking_id = b.booking_id
                WHERE b.cust_id = $cid
                  AND s.status = 'out_for_delivery'
                  AND s.notified = 0";
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                $label = ($r['service_type']==='pickup_and_return')
                    ? 'Pickup & Return service'
                    : ucfirst(str_replace('_',' ',$r['service_type'])).' service';
                $notif_items[] = [
                    'type'=>'service_out_for_delivery',
                    'icon'=>'🚗',
                    'priority'=>50,
                    'message'=>"$label for Booking #{$r['booking_id']} is out for delivery."
                ];
                $serviceIdsToMark[] = (int)$r['service_id'];
            }
            $res->free();
        }

        /* Sort by priority */
        usort($notif_items, fn($a,$b)=> $b['priority'] <=> $a['priority']);
        $notif_count = count($notif_items);

        /* Mark as read / notified */
        if ($bookingIdsToMark) {
            $ids = implode(',', array_map('intval',$bookingIdsToMark));
            $conn->query("UPDATE booking SET notified = 1 WHERE booking_id IN ($ids)");
        }
        if ($markProfileStatus) {
            $conn->query("UPDATE customer SET profile_status_notified = 1 WHERE cust_id = $cid");
        }
        if ($refundIdsToMark) {
            $ids = implode(',', array_map('intval',$refundIdsToMark));
            $conn->query("UPDATE refunds SET user_unread = 0 WHERE refund_id IN ($ids)");
        }
        if ($depositBookingIdsToMark) {
            $ids = implode(',', array_map('intval',$depositBookingIdsToMark));
            $conn->query("UPDATE booking SET deposit_notified = 1 WHERE booking_id IN ($ids)");
        }
        if ($serviceIdsToMark) {
            $ids = implode(',', array_map('intval',$serviceIdsToMark));
            $conn->query("UPDATE service SET notified = 1 WHERE service_id IN ($ids)");
        }
    }
    ?>
    <div class="notif-dropdown">
      <button class="header-icon-btn" title="Notifications" aria-label="Notifications">
        <span>🔔</span>
        <?php if ($notif_count > 0): ?>
          <span class="notification-dot"><?= $notif_count ?></span>
        <?php endif; ?>
      </button>
      <div class="notif-menu">
        <div class="notif-menu-header">Notifications</div>
        <ul class="notif-menu-list">
          <?php if ($notif_count === 0): ?>
            <div class="notif-empty">No new notifications.</div>
          <?php else: ?>
            <?php foreach ($notif_items as $n): ?>
              <?php
                $cls = 'notif-info';
                if (str_contains($n['type'],'approved') || str_contains($n['type'],'verified')) $cls='notif-approved';
                elseif (str_contains($n['type'],'rejected') || str_contains($n['type'],'failed') || str_contains($n['type'],'forfeited')) $cls='notif-rejected';
                elseif (str_contains($n['type'],'pending')) $cls='notif-warning';
              ?>
              <li class="notif-item">
                <span class="notif-icon"><?= htmlspecialchars($n['icon']) ?></span>
                <span class="<?= $cls ?>"><?= $n['message'] ?></span>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="profile-dropdown">
      <button class="profile-btn" title="Profile"><span>👤</span></button>
      <div class="profile-menu">
        <a href="/customer/profile.php">Profile</a>
        <a href="/customer/bookings.php">My Bookings</a>
        <a href="/customer/dashboard.php">Dashboard</a>
        <a href="/customer/logout.php" class="logout-link">Logout</a>
      </div>
    </div>
  </div>
</header>