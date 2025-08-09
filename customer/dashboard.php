<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

require '../connect.php';
require '../includes/header.php';

$cust_id = (int)$_SESSION['cust_id'];

/* Fetch profile */
$profileSql = "
    SELECT 
        username,
        full_name,
        email,
        phone_no,
        id_no,
        address,
        age,
        id_front_image,
        id_back_image,
        license_front_image,
        license_back_image
    FROM customer
    WHERE cust_id = ?
    LIMIT 1
";
$stmt = $conn->prepare($profileSql);
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$customer_name = $profile['username'] ?? 'Customer';

/* Profile completeness */
$requiredFields = [
    'full_name' => 'Full Name',
    'email' => 'Email',
    'phone_no' => 'Phone Number',
    'id_no' => 'ID Number',
    'address' => 'Address',
    'age' => 'Age'
];
$requiredImages = [
    'id_front_image'      => 'ID Front Image',
    'id_back_image'       => 'ID Back Image',
    'license_front_image' => 'License Front Image',
    'license_back_image'  => 'License Back Image'
];

$missing = [];
foreach ($requiredFields as $k => $label) {
    if (!isset($profile[$k]) || trim((string)$profile[$k]) === '') {
        $missing[] = $label;
    }
}
foreach ($requiredImages as $k => $label) {
    if (empty($profile[$k])) {
        $missing[] = $label;
    }
}

$totalRequired     = count($requiredFields) + count($requiredImages);
$completed         = $totalRequired - count($missing);
$completionPercent = $totalRequired > 0 ? (int)round(($completed / $totalRequired) * 100) : 100;
$showProfileNotice = $completionPercent < 100;

/*
  Cars (only available). 
  We select a representative image_id with priority:
   1. image_type='main'
   2. lowest sort_order
   3. lowest car_image_id
*/
$carSql = "
    SELECT
        c.car_id,
        c.car_brand,
        c.car_model,
        c.daily_rate,
        c.images_version,
        (
            SELECT ci.car_image_id
            FROM car_image ci
            WHERE ci.car_id = c.car_id
            ORDER BY (ci.image_type = 'main') DESC,
                     ci.sort_order ASC,
                     ci.car_image_id ASC
            LIMIT 1
        ) AS car_image_id
    FROM car c
    WHERE c.status = 'available'
    ORDER BY c.car_brand, c.car_model, c.car_id
";
$carResult = $conn->query($carSql);
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
/* (Retain your CSS – only minimal changes added for accessibility) */
.welcome-banner {
    max-width: 1200px;
    margin: 35px auto -10px auto;
    padding: 0 16px;
}
.welcome-banner-inner {
    display: flex;
    align-items: center;
    background: linear-gradient(90deg, #fcfff7 85%, #ffec8b 100%);
    border-radius: 22px;
    padding: 22px 36px 22px 22px;
    box-shadow: 0 2px 8px rgba(44,60,102,0.04);
    font-size: 2em;
    font-weight: 800;
    color: #2761cf;
    letter-spacing: 0.01em;
}
.welcome-banner-emoji {
    font-size: 1.3em;
    margin-right: 18px;
    animation: wave-hand 2.2s infinite;
    display: inline-block;
    transform-origin: 70% 70%;
}
@keyframes wave-hand {
    0% { transform: rotate(0deg);}
    10% { transform: rotate(16deg);}
    20% { transform: rotate(-6deg);}
    30% { transform: rotate(16deg);}
    40% { transform: rotate(-4deg);}
    50% { transform: rotate(12deg);}
    60% { transform: rotate(0deg);}
    100% { transform: rotate(0deg);}
}
/* Profile notice */
.profile-notice-wrapper {
    max-width: 1200px;
    margin: 28px auto 0 auto;
    padding: 0 16px;
}
.profile-notice {
    position: relative;
    background: #fff9e6;
    border: 1px solid #ffe4a3;
    border-radius: 18px;
    padding: 22px 26px 26px;
    box-shadow: 0 4px 14px rgba(60,44,0,0.05);
    font-size: 0.95em;
    color: #5a4300;
}
.profile-notice h3 {
    margin: 0 0 10px;
    font-size: 1.15em;
    color: #8a5a00;
    display: flex;
    gap: 8px;
    align-items: center;
}
.profile-progress {
    margin: 12px 0 14px;
    background: #fff;
    border: 1px solid #ffd783;
    border-radius: 8px;
    height: 14px;
    position: relative;
    overflow: hidden;
}
.profile-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #ffce4f, #ffb347);
    transition: width .4s;
}
.profile-missing-list {
    margin: 6px 0 0;
    padding-left: 18px;
    column-count: 2;
    column-gap: 28px;
}
.profile-missing-list li {
    margin: 2px 0;
    break-inside: avoid;
}
.profile-update-btn {
    display: inline-block;
    margin-top: 14px;
    background: #ffb347;
    color: #4a3200;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9em;
    text-decoration: none;
    border: 1px solid #ffac2e;
    box-shadow: 0 2px 4px rgba(0,0,0,0.07);
    transition: background .2s;
}
.profile-update-btn:hover { background: #ffbe63; }
.profile-dismiss {
    position: absolute;
    top: 10px;
    right: 14px;
    background: none;
    border: none;
    color: #b88600;
    font-size: 1.1em;
    cursor: pointer;
    padding: 4px 6px;
    line-height: 1;
    border-radius: 6px;
    transition: background .18s;
}
.profile-dismiss:hover { background: rgba(255,193,7,0.22); }

/* Cars */
.cars-container {
    max-width: 1200px;
    margin: 40px auto 0 auto;
    padding: 0 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 32px;
    justify-content: flex-start;
}
.car-card {
    width: 270px;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 22px 18px 18px;
    transition: box-shadow 0.2s;
    margin-bottom: 24px;
}
.car-card:hover { box-shadow: 0 8px 28px rgba(44,60,102,0.13); }
.car-img {
    width: 100%;
    height: 140px;
    object-fit: contain;
    margin-bottom: 12px;
    border-radius: 7px;
    background: #f7fafd;
}
.car-title {
    font-size: 1.13em;
    font-weight: 700;
    margin-bottom: 4px;
    color: #2f377d;
    text-align: center;
}
.car-rate {
    font-size: 1em;
    color: #3c4cb8;
    margin-bottom: 10px;
    font-weight: 600;
}
.book-btn {
    margin-top: 10px;
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 10px 24px;
    border-radius: 7px;
    font-size: 1em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    text-decoration: none;
    display: inline-block;
}
.book-btn:hover { background: #234c96; }
.book-btn.disabled {
    background: #9aa9c9;
    cursor: not-allowed;
    pointer-events: none;
}
.no-cars {
    text-align: center;
    margin-top: 80px;
    color: #c62828;
    font-size: 1.18em;
}

/* Pickup location banner */
.pickup-location-banner {
    max-width: 1200px;
    margin: 20px auto 0 auto;
    padding: 16px 25px;
    background: linear-gradient(90deg, #e3f0ff 80%, #fff6d1 100%);
    border-radius: 18px;
    font-size: 1.15em;
    color: #21408a;
    font-weight: 500;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    box-shadow: 0 2px 10px rgba(33,64,138,0.09);
}
.pickup-location-icon { font-size: 2em; margin-top: 2px; }
.pickup-address {
    color: #1a2d6c;
    font-weight: 600;
    display: inline-block;
    margin-top: 2px;
    margin-bottom: 2px;
}
.pickup-map-link {
    margin-left: 8px;
    color: #2278d4;
    text-decoration: underline;
    font-weight: 500;
}
.pickup-map-link:hover { color: #234c96; }
.pickup-note {
    display: block;
    font-size: 0.97em;
    color: #555;
    margin-top: 4px;
}

/* Responsive */
@media (max-width: 680px) {
    .welcome-banner-inner {
        font-size: 1.55em;
        flex-direction: row;
        padding: 18px 24px;
    }
    .profile-missing-list { column-count: 1; }
    .car-card { width: calc(50% - 24px); }
}
@media (max-width: 470px) {
    .car-card { width: 100%; }
}
</style>

<div class="welcome-banner">
    <div class="welcome-banner-inner">
        <span class="welcome-banner-emoji">👋</span>
        Welcome, <?= htmlspecialchars($customer_name) ?>!
    </div>
</div>

<?php if ($showProfileNotice): ?>
<div class="profile-notice-wrapper" id="profileNotice">
    <div class="profile-notice" role="alert" aria-live="polite">
        <button class="profile-dismiss" type="button" onclick="dismissProfileNotice()" aria-label="Dismiss notice">&times;</button>
        <h3>📝 Complete Your Profile (<?= $completionPercent ?>%)</h3>
        <p style="margin:2px 0 8px;">
            Please complete your profile before making a booking to ensure fast verification & accurate agreement generation.
        </p>
        <div class="profile-progress" aria-label="Profile completion progress" role="progressbar"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $completionPercent ?>">
            <div class="profile-progress-bar" style="width: <?= $completionPercent ?>%;"></div>
        </div>
        <p style="margin:0 0 6px;font-weight:600;">Missing:</p>
        <ul class="profile-missing-list">
            <?php foreach ($missing as $m): ?>
                <li><?= htmlspecialchars($m) ?></li>
            <?php endforeach; ?>
        </ul>
        <a href="profile.php" class="profile-update-btn">Update Profile Now</a>
    </div>
</div>
<script>
function dismissProfileNotice() {
    const el = document.getElementById('profileNotice');
    if (!el) return;
    el.style.transition = 'opacity .35s';
    el.style.opacity = '0';
    setTimeout(()=> el.remove(), 380);
}
</script>
<?php endif; ?>

<div class="pickup-location-banner">
    <span class="pickup-location-icon">📍</span>
    <span>
      <strong>Self-Pickup Location:</strong><br>
      <span class="pickup-address">
        DT 1564, JALAN BUKIT TAMBUN PERDANA 21,<br>
        TAMAN BUKIT TAMBUN PERDANA,<br>
        76100 DURIAN TUNGGAL, MELAKA
      </span><br>
      <span class="pickup-note">
        You may collect your booked car directly from our rental location above.
        <a href="https://maps.google.com/?q=DT+1564,+JALAN+BUKIT+TAMBUN+PERDANA+21,+TAMAN+BUKIT+TAMBUN+PERDANA,+76100+DURIAN+TUNGGAL,+MELAKA"
           target="_blank" class="pickup-map-link" rel="noopener">View on Google Maps</a>
      </span>
    </span>
</div>

<div class="cars-container">
<?php if ($carResult && $carResult->num_rows > 0): ?>
    <?php while ($car = $carResult->fetch_assoc()): 
        $imgSrc = $car['car_image_id']
            ? "get_car_image.php?car_image_id=".(int)$car['car_image_id']."&v=".(int)$car['images_version']
            : "/assets/images/viva_elite.png";
        $alt = $car['car_brand'].' '.$car['car_model'];
        $canBook = $completionPercent === 100; // Gate booking optionally
    ?>
        <div class="car-card">
            <img class="car-img"
                 src="<?= htmlspecialchars($imgSrc) ?>"
                 alt="<?= htmlspecialchars($alt) ?>"
                 onerror="this.src='/assets/images/viva_elite.png'">
            <div class="car-title"><?= htmlspecialchars($alt) ?></div>
            <div class="car-rate">RM <?= number_format((float)$car['daily_rate'], 2) ?> / day</div>
            <a class="book-btn<?= $canBook ? '' : ' disabled' ?>"
               href="<?= $canBook ? 'book_car.php?car_id='.(int)$car['car_id'] : '#' ?>"
               <?= $canBook ? '' : 'aria-disabled="true"' ?>>
               <?= $canBook ? 'Book Now' : 'Complete Profile First' ?>
            </a>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="no-cars">No cars are currently available. Please check back later!</div>
<?php endif; ?>
</div>

<?php require '../includes/footer.php'; ?>