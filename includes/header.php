<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TimeLess Car Rental System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
      }
      .header-icon-btn:hover {
        color: #ffd600;
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
        .profile-menu {
          right: -18px;
        }
      }
    </style>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
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
      <button class="header-icon-btn" title="Notifications">
        <span>🔔</span>
      </button>
      <div class="profile-dropdown">
        <button class="profile-btn" title="Profile">
          <span>👤</span>
        </button>
        <div class="profile-menu">
          <a href="/customer/profile.php">Profile</a>
          <a href="/customer/logout.php" class="logout-link">Logout</a>
        </div>
      </div>
    </div>
  </header>