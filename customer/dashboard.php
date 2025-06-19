<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

// Fetch customer name
$cust_id = $_SESSION['cust_id'];
$stmt = $conn->prepare("SELECT username FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($customer_name);
$stmt->fetch();
$stmt->close();

// Fetch all available cars
$sql = "
        SELECT c.car_id, c.car_brand, c.car_model, c.plate_no, c.daily_rate,
            COALESCE(main_img.car_image_id, any_img.car_image_id) AS car_image_id
        FROM car c
        LEFT JOIN (
            SELECT car_id, MIN(car_image_id) AS car_image_id
            FROM car_image
            WHERE image_type = 'main'
            GROUP BY car_id
        ) main_img ON c.car_id = main_img.car_id
        LEFT JOIN (
            SELECT car_id, MIN(car_image_id) AS car_image_id
            FROM car_image
            GROUP BY car_id
        ) any_img ON c.car_id = any_img.car_id
        WHERE c.status = 'available'
        ORDER BY c.car_brand, c.car_model
";
$result = $conn->query($sql);
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.welcome-msg {
    max-width: 1200px;
    margin: 35px auto -10px auto;
    padding: 0 16px;
    font-size: 1.23em;
    font-weight: 500;
    color: #2f377d;
    letter-spacing: 0.02em;
}
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
    padding: 22px 18px 18px 18px;
    transition: box-shadow 0.2s;
    margin-bottom: 24px;
}
.car-card:hover {
    box-shadow: 0 8px 28px rgba(44,60,102,0.13);
}
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
.car-plate {
    font-size: 1em;
    color: #555;
    margin-bottom: 4px;
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
.book-btn:hover {
    background: #234c96;
}
.no-cars {
    text-align: center;
    margin-top: 80px;
    color: #c62828;
    font-size: 1.18em;
}
</style>

<div class="welcome-msg">
    Hi, welcome <?= htmlspecialchars($customer_name) ?>!
</div>

<div class="cars-container">
<?php if ($result && $result->num_rows > 0): ?>
    <?php while ($car = $result->fetch_assoc()): ?>
        <div class="car-card">
            <?php if (!empty($car['car_image_id'])): ?>
                <img class="car-img"
                     src="get_car_image.php?car_image_id=<?= $car['car_image_id'] ?>"
                     alt="Car image"
                     onerror="this.src='/assets/images/viva_elite.png'">
            <?php else: ?>
                <img class="car-img"
                     src="/assets/images/viva_elite.png"
                     alt="No car image">
            <?php endif; ?>
            <div class="car-title"><?= htmlspecialchars($car['car_brand'] . ' ' . $car['car_model']) ?></div>
            <div class="car-plate">Plate: <?= htmlspecialchars($car['plate_no']) ?></div>
            <div class="car-rate">RM <?= number_format($car['daily_rate'], 2) ?> / day</div>
            <a class="book-btn" href="book_car.php?car_id=<?= $car['car_id'] ?>">Book Now</a>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="no-cars">No cars are currently available. Please check back later!</div>
<?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>