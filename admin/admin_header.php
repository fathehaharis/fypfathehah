<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<title>Admin TimeLess Car Rental </title>
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
    gap: 20px;
    margin-right: 40px;
  }

  /* Generic small round / square action buttons */
  .icon-btn, .bank-btn {
    background:#fff;
    color:#2f377d;
    border:none;
    border-radius:10px;
    min-width:40px;
    height:40px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.05rem;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
    box-shadow:0 1px 5px rgba(60,60,60,0.10);
    transition:background .18s, color .18s, transform .15s;
    position:relative;
  }
  .bank-btn:hover,
  .icon-btn:hover {
    background:#ffd600;
    color:#2f377d;
  }
  .bank-btn:active,
  .icon-btn:active { transform:scale(.94); }

  /* Optional small badge (e.g., count of active banks) */
  .bank-btn .badge {
    position:absolute;
    top:4px;
    right:6px;
    background:#ff4d4f;
    color:#fff;
    font-size:.55rem;
    padding:2px 5px;
    border-radius:10px;
    font-weight:700;
    line-height:1;
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
    min-width: 220px;
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
    font-size: .95em;
    transition: background .15s;
  }
  .profile-menu a:hover {
    background: #f1f3fa;
  }
  .profile-menu .logout-link {
    color: #c62828;
    font-weight: 600;
    border-top: 1px solid #eee;
  }
  .profile-menu .section-label {
    font-size: .60rem;
    letter-spacing: .6px;
    text-transform: uppercase;
    font-weight:700;
    padding:10px 20px 4px;
    color:#5c6b87;
    background:#f7f9fc;
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
        <div class="section-label">Account</div>
        <a href="profile.php">Profile</a>
        <a href="admin_dashboard.php">Dashboard</a>
        <div class="section-label">Finance</div>
        <a href="company_banks.php">Company Banks</a>
        <div class="section-label">Session</div>
        <a href="logout.php" class="logout-link">Logout</a>
      </div>
    </div>
  </div>
</header>