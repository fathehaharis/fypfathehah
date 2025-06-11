<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link href="https://fonts.googleapis.com/css?family=Montserrat:700,400&display=swap" rel="stylesheet">
<style>
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
    min-width: 200px;
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
    font-size: 1.05em;
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

<header class="header-bar">
  <div class="header-title-logo">
    <img src="/assets/images/TimeLess_logo.png" alt="Logo" />
    <span class="site-title">TimeLess Admin Panel</span>
  </div>
  <div class="header-icons">
    <div class="profile-dropdown">
      <button class="profile-btn" title="Admin Profile">
        <span>👤</span>
      </button>
      <div class="profile-menu">
        <a href="profile.php">Profile</a>
        <a href="admin_dashboard.php">Dashboard</a>
        <a href="logout.php" class="logout-link">Logout</a>
      </div>
    </div>
  </div>
</header>