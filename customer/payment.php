<?php
session_start();
include '../connect.php';
include '../includes/header.php';

// 1. Get booking info from session
$booking = $_SESSION['booking_data'] ?? [];
$driver = $_SESSION['driver_data'] ?? [];
$cust_email = $driver['driver_email'] ?? '';
$cust_name = $driver['driver_full_name'] ?? '';
$total = $booking['total_price'] ?? 0;

if (!$booking || !$driver || $total <= 0) {
    echo "<h2>Booking data missing.</h2>";
    exit;
}

// 2. When user submits, create Billplz bill & redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Billplz API credentials
    $api_key = 'YOUR_BILLPLZ_API_KEY'; // replace with your Billplz secret key
    $collection_id = 'YOUR_COLLECTION_ID'; // replace with your Billplz collection ID

    $bill_data = [
        'collection_id' => $collection_id,
        'email' => $cust_email,
        'name' => $cust_name,
        'amount' => intval($total * 100), // in cents
        'callback_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/customer/booking_receipt.php',
        'redirect_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/customer/booking_receipt.php',
        'description' => 'Car Rental Payment (Booking ID: ' . ($_SESSION['booking_id'] ?? 'N/A') . ')',
    ];

    // Create Billplz bill
    $ch = curl_init('https://www.billplz.com/api/v3/bills');
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_USERPWD, $api_key . ":");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bill_data));
    curl_setopt($ch, CURLOPT_POST, true);

    $response = curl_exec($ch);
    $result = json_decode($response, true);
    curl_close($ch);

    if (!empty($result['url'])) {
        header('Location: ' . $result['url']);
        exit;
    } else {
        echo "<div style='color:red'>Payment gateway error. Please try again later.</div>";
    }
}
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.payment-container {
    max-width: 500px;
    margin: 48px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.10);
    padding: 38px 38px 32px 38px;
    text-align: center;
}
.payment-title {
    font-size: 1.4em;
    font-weight: bold;
    color: #2f377d;
    margin-bottom: 20px;
}
.payment-amount {
    font-size: 2em;
    font-weight: 700;
    color: #3244c5;
    margin: 24px 0;
}
.pay-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 16px 42px;
    border-radius: 7px;
    font-size: 1.14em;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.18s;
}
.pay-btn:hover { background: #234c96; }
</style>

<div class="payment-container">
    <div class="payment-title">Pay for Your Booking</div>
    <div class="payment-amount">RM <?= number_format($total,2) ?></div>
    <form method="post">
        <button type="submit" class="pay-btn">Pay Now with Billplz</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>