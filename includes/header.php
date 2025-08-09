<?php
// (Existing code) Make sure session + DB connection happen somewhere before usage
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TimeLess Car Rental System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:700,400&display=swap" rel="stylesheet">
    <style>
      body { font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif; }
      .header-bar {
        background: linear-gradient(90deg, #2f377d 0%, #3c4cb8 100%);
        color: #fff;
        padding: 22px 0 18px 0;
        box-shadow: 0 2px 10px rgba(60,60,60,0.10);
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .header-title-logo {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-left: 40px;
      }
      .header-title-logo img {
        height: 38px;
        width: auto;
        display: inline-block;
        vertical-align: middle;
        filter: drop-shadow(0 2px 8px rgba(0,0,0,0.07));
      }
      .header-bar .site-title {
        font-size: 1.8em;
        font-weight: 700;
        letter-spacing: 1px;
        margin: 0;
        vertical-align: middle;
        display: inline-block;
      }
      .header-icons {
        display: flex;
        align-items: center;
        gap: 26px;
        margin-right: 40px;
      }
      .header-icon-btn {
        background: none;
        border: none;
        color: #fff;
        font-size: 1.5em;
        cursor: pointer;
        padding: 0;
        margin: 0 2px;
        display: flex;
        align-items: center;
        transition: color 0.18s;
        position: relative;
      }
      .header-icon-btn:hover {
        color: #ffd600;
      }
      .notification-dot {
        position: absolute;
        top: 0.1em;
        right: 0.1em;
        background: #e54848;
        color: #fff;
        border-radius: 50%;
        width: 14px;
        height: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8em;
        font-weight: bold;
        border: 2px solid #fff;
        z-index: 2;
      }
      .notif-dropdown {
        position: relative;
        display: inline-block;
      }
      .notif-menu {
        display: none;
        position: absolute;
        right: -10px;
        top: 40px;
        background: #fff;
        min-width: 275px;
        max-width: 340px;
        box-shadow: 0 4px 18px rgba(44,60,102,0.15);
        border-radius: 10px;
        overflow: hidden;
        z-index: 120;
      }
      .notif-dropdown.active .notif-menu {
        display: block;
      }
      .notif-menu-header {
        background: #f1f3fa;
        color: #2f377d;
        font-weight: 600;
        padding: 10px 18px;
        border-bottom: 1px solid #eee;
      }
      .notif-menu-list {
        max-height: 280px;
        overflow-y: auto;
        padding: 0;
        margin: 0;
        list-style: none;
      }
      .notif-item {
        padding: 13px 18px;
        border-bottom: 1px solid #eee;
        font-size: 1em;
        color: #233;
        background: #fff;
      }
      .notif-item:last-child {
        border-bottom: none;
      }
      .notif-approved {
        color: #188a2f;
        font-weight: 600;
      }
      .notif-rejected {
        color: #d52d2d;
        font-weight: 600;
      }
      .notif-empty {
        padding: 24px 18px;
        color: #aaa;
        font-size: 0.97em;
        text-align: center;
      }
      .profile-dropdown {
        position: relative;
        display: inline-block;
      }
      .profile-btn {
        background: #fff;
        color: #2f377d;
        border-radius: 50%;
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.25em;
        border: none;
        cursor: pointer;
        box-shadow: 0 1px 5px rgba(60,60,60,0.10);
        transition: background 0.18s, color 0.18s;
      }
      .profile-btn:hover {
        background: #ffd600;
        color: #2f377d;
      }
      .profile-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 48px;
        background: #fff;
        min-width: 160px;
        box-shadow: 0 4px 18px rgba(44,60,102,0.15);
        border-radius: 10px;
        overflow: hidden;
        z-index: 100;
      }
      .profile-dropdown.active .profile-menu {
        display: block;
      }
      .profile-menu a {
        color: #2f377d;
        padding: 13px 20px;
        text-decoration: none;
        display: block;
        font-size: 1.04em;
        transition: background 0.15s;
      }
      .profile-menu a:hover {
        background: #f1f3fa;
      }
      .profile-menu .logout-link {
        color: #c62828;
        font-weight: 600;
        border-top: 1px solid #eee;
      }
      /* ADDED: small badge for refund count */
      .menu-badge {                /* ADDED */
        background:#e54848;        /* ADDED */
        color:#fff;                /* ADDED */
        padding:2px 6px;           /* ADDED */
        font-size:11px;            /* ADDED */
        border-radius:10px;        /* ADDED */
        margin-left:8px;           /* ADDED */
        font-weight:600;           /* ADDED */
        line-height:1;             /* ADDED */
        display:inline-block;      /* ADDED */
      }                            /* ADDED */
      @media (max-width: 700px) {
        .header-bar {
          padding: 14px 0 10px 0;
        }
        .header-title-logo {
          margin-left: 14px;
        }
        .header-bar .site-title {
          font-size: 1.2em;
        }
        .header-icons {
          margin-right: 14px;
          gap: 16px;
        }
        .notif-menu {
          right: -18px;
        }
        .profile-menu {
          right: -18px;
        }
      }
    </style>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        // Profile dropdown
        const profileDropdown = document.querySelector('.profile-dropdown');
        if (profileDropdown) {
          const btn = profileDropdown.querySelector('.profile-btn');
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('active');
          });
          document.addEventListener('click', function () {
            profileDropdown.classList.remove('active');
          });
        }

        // Notification dropdown
        const notifDropdown = document.querySelector('.notif-dropdown');
        if (notifDropdown) {
          const btn = notifDropdown.querySelector('.header-icon-btn');
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            notifDropdown.classList.toggle('active');
          });
          document.addEventListener('click', function () {
            notifDropdown.classList.remove('active');
          });
        }
      });
    </script>
</head>
<body>
  <header class="header-bar">
    <div class="header-title-logo">
      <img src="/assets/images/TimeLess_logo.png" alt="Logo" />
      <span class="site-title">TimeLess Car Rental</span>
    </div>
    <div class="header-icons">
      <!-- Notification Icon & Dropdown -->
      <?php
      // Notification logic (for customer)
      $notif_count = 0;
      $notif_items = [];
      if (isset($_SESSION['cust_id'])) {
          include_once $_SERVER['DOCUMENT_ROOT'].'/connect.php';
          $cid = intval($_SESSION['cust_id']);
          $notif_q = "SELECT b.booking_id, b.status, b.car_id, c.car_brand, c.car_model
                      FROM booking b
                      LEFT JOIN car c ON b.car_id = c.car_id
                      WHERE b.cust_id = $cid AND b.status IN ('approved','rejected') AND b.notified = 0";
          $notif_res = $conn->query($notif_q);
          while ($notif_res && $row = $notif_res->fetch_assoc()) {
              $notif_items[] = $row;
              $notif_count++;
          }

          // ADDED: get refund pending count (badge). 
          // If you later add a user_unread column use: WHERE cust_id=$cid AND user_unread=1
          $refund_count = 0; // ADDED
          $ref_q = "SELECT COUNT(*) AS c FROM refunds WHERE cust_id = $cid AND refund_status IN ('pending')"; // ADDED
          if ($ref_res = $conn->query($ref_q)) { // ADDED
              $rrow = $ref_res->fetch_assoc();   // ADDED
              $refund_count = (int)$rrow['c'];   // ADDED
          }                                      // ADDED
      } else {
          $refund_count = 0; // ADDED
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
            <?php if (!empty($notif_items)): ?>
              <?php foreach ($notif_items as $n): ?>
                <li class="notif-item">
                  <?php if ($n['status'] === 'approved'): ?>
                    <span class="notif-approved">Booking Approved</span> for <b><?= htmlspecialchars($n['car_brand'].' '.$n['car_model']) ?></b> (Booking #<?= $n['booking_id'] ?>)
                  <?php elseif ($n['status'] === 'rejected'): ?>
                    <span class="notif-rejected">Booking Rejected</span> for <b><?= htmlspecialchars($n['car_brand'].' '.$n['car_model']) ?></b> (Booking #<?= $n['booking_id'] ?>)
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="notif-empty">No new notifications.</div>
            <?php endif; ?>
          </ul>
        </div>
      </div>
      <div class="profile-dropdown">
        <button class="profile-btn" title="Profile">
          <span>👤</span>
        </button>
        <div class="profile-menu">
          <a href="/customer/profile.php">Profile</a>
          <a href="/customer/bookings.php">My Bookings</a>
          <a href="/customer/dashboard.php">Dashboard</a>
          <!-- ADDED: Refunds link with optional badge -->
          <a href="/customer/my_refunds.php">
            Refunds
            <?php if (!empty($refund_count)): ?>
              <span class="menu-badge"><?= $refund_count ?></span>
            <?php endif; ?>
          </a>
          <!-- END ADDED -->
          <a href="/customer/logout.php" class="logout-link">Logout</a>
        </div>
      </div>
    </div>
  </header>