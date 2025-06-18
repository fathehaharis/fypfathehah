<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<p>Invalid customer ID.</p>";
    echo '<p><a href="customers.php">Back to Customers</a></p>';
    exit;
}

$cust_id = intval($_GET['id']);
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $phone_no  = trim($_POST['phone_no']);
    $email     = trim($_POST['email']);
    $username  = trim($_POST['username']);
    $address   = trim($_POST['address']);
    $age       = is_numeric($_POST['age']) ? intval($_POST['age']) : null;

    // Optionally, add validation here (e.g., required fields, valid email, etc.)

    $stmt = $conn->prepare("UPDATE customer SET full_name=?, phone_no=?, email=?, username=?, address=?, age=? WHERE cust_id=?");
    $stmt->bind_param("ssssssi", $full_name, $phone_no, $email, $username, $address, $age, $cust_id);
    if ($stmt->execute()) {
        $msg = "Customer updated successfully.";
    } else {
        $msg = "Failed to update customer.";
    }
    $stmt->close();
}

// Fetch customer data
$stmt = $conn->prepare("SELECT * FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result->fetch_assoc();
$stmt->close();

if (!$customer) {
    echo "<p>Customer not found.</p>";
    echo '<p><a href="customers.php">Back to Customers</a></p>';
    exit;
}
?>
<?php include 'admin_header.php'; ?>

<style>
.edit-form-container {
    max-width: 540px;
    margin: 38px auto 24px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 20px #e0e7ef88;
    padding: 32px 38px 22px 38px;
}
.edit-form-container h2 {
    color: #2b5cbc;
    font-weight: 800;
    margin-bottom: 18px;
}
.edit-form label {
    display: block;
    font-weight: 600;
    color: #2b5cbc;
    margin-top: 13px;
    margin-bottom: 5px;
}
.edit-form input[type="text"],
.edit-form input[type="email"],
.edit-form input[type="number"],
.edit-form textarea {
    width: 100%;
    padding: 9px 13px;
    border-radius: 8px;
    border: 1.5px solid #b5bee5;
    background: #f7fafd;
    font-size: 1.05em;
    margin-bottom: 3px;
}
.edit-form textarea {
    resize: vertical;
    min-height: 48px;
}
.edit-form button {
    margin-top: 18px;
    background: linear-gradient(90deg, #4158d0 0%, #6d8be6 100%);
    color: #fff;
    border: none;
    padding: 11px 30px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1.09em;
    box-shadow: 0 2px 6px #b5bee550;
    transition: background 0.18s;
    cursor: pointer;
}
.edit-form button:hover {
    background: linear-gradient(90deg, #2b5cbc 0%, #4158d0 100%);
}
.success-msg {
    background: #eafdeb;
    color: #218c3b;
    padding: 11px 16px;
    border-radius: 7px;
    font-weight: 500;
    margin-bottom: 14px;
    display: inline-block;
}
</style>

<div class="edit-form-container">
    <h2>Edit Customer</h2>
    <?php if ($msg): ?>
        <div class="success-msg"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <form class="edit-form" method="post" action="">
        <label for="full_name">Full Name *</label>
        <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($customer['full_name']) ?>" required>

        <label for="phone_no">Phone No *</label>
        <input type="text" id="phone_no" name="phone_no" value="<?= htmlspecialchars($customer['phone_no']) ?>" required>

        <label for="email">Email *</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($customer['email']) ?>" required>

        <label for="username">Username *</label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($customer['username']) ?>" required>

        <label for="address">Address</label>
        <textarea id="address" name="address"><?= htmlspecialchars($customer['address']) ?></textarea>

        <label for="age">Age</label>
        <input type="number" id="age" name="age" value="<?= htmlspecialchars($customer['age']) ?>" min="0" max="150">

        <button type="submit">Update Customer</button>
        <a href="customers.php" style="margin-left:16px;color:#2b5cbc;font-weight:600;text-decoration:underline;">Back to Customers</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>