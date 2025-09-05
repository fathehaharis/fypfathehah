<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tests/test_setup.php';
require_once __DIR__ . '/../../register_core.php';

function t_cleanup(mysqli $db, string $username, string $email): void {
    $st = $db->prepare("DELETE FROM customer WHERE username=? OR email=?");
    $st->bind_param("ss", $username, $email); $st->execute(); $st->close();
}
function label(bool $accepted): string { return $accepted ? 'accepted' : 'rejected'; }
function print_line(string $name, bool $pass, string $detail=''): void {
    echo $name . ': ' . ($pass ? 'PASSED' : 'FAILED') . ($detail ? " ($detail)" : '') . PHP_EOL;
}

$db = getTestDB();
$results = [];

// Seed one existing account to trigger duplicate checks
$seedU = 'fathehaharis';
$seedE = 'fathehaharis@gmail.com';
t_cleanup($db, $seedU, $seedE);
$seed = register_core($db, [
    'email' => $seedE,
    'username' => $seedU,
    'phone_no' => '01112345678',
    'password' => 'Customer123!',
    'confirm'  => 'Customer123!',
    'full_name'=> 'Seed User'
]);

echo "\nResults for Registration Core Tests (Verbose)\n";
echo "---------------------------------------------\n";

// TC-R1 — Valid registration (expect accepted)
$u1='fathehaharis1'; $e1='fathehaharis1@gmail.com'; t_cleanup($db,$u1,$e1);
$r = register_core($db, [
    'email'=>$e1,'username'=>$u1,'phone_no'=>'01112345678',
    'password'=>'Customer123!','confirm'=>'Customer123!','full_name'=>'Test User'
]);
$accepted = (bool)($r['ok'] ?? false);
print_line('TC-R1 — Valid registration', $accepted === true, 'expected=accepted, got='.label($accepted));
if ($accepted) {
    $row = $db->query("SELECT password FROM customer WHERE cust_id=".(int)$r['cust_id'])->fetch_assoc();
    $hashed = isset($row['password']) && str_starts_with((string)$row['password'], '$2y$');
    print_line('TC-R1a — Password hashed', $hashed, $hashed ? 'bcrypt detected' : 'not bcrypt');
}

// TC-R2 — Invalid email (expect rejected)
$r = register_core($db, [
    'email' => 'bad@domain', 'username'=>'u2', 'phone_no'=>'01112345678',
    'password'=>'Customer123!','confirm'=>'Customer123!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['email']);
print_line('TC-R2 — Invalid email', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R3 — Duplicate username (expect rejected)
$r = register_core($db, [
    'email' => 'unique1@example.com', 'username'=>$seedU, 'phone_no'=>'01112345678',
    'password'=>'Customer123!','confirm'=>'Customer123!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['username']);
print_line('TC-R3 — Duplicate username', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R4 — Duplicate email (expect rejected)
$r = register_core($db, [
    'email' => $seedE, 'username'=>'unique_user', 'phone_no'=>'01112345678',
    'password'=>'Customer123!','confirm'=>'Customer123!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['email']);
print_line('TC-R4 — Duplicate email', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R5 — Phone too short (expect rejected)
$r = register_core($db, [
    'email'=>'short@example.com','username'=>'shortp','phone_no'=>'019882211',
    'password'=>'Customer123!','confirm'=>'Customer123!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['phone_no']);
print_line('TC-R5 — Phone too short', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R6 — Phone prefix not 01 (expect rejected)
$r = register_core($db, [
    'email'=>'non01@example.com','username'=>'non01','phone_no'=>'0212345678',
    'password'=>'Customer123!','confirm'=>'Customer123!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['phone_no']);
print_line('TC-R6 — Phone prefix not 01', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R7 — No uppercase (expect rejected)
$r = register_core($db, [
    'email'=>'noupcase@example.com','username'=>'noupcase','phone_no'=>'01112345678',
    'password'=>'customer123!','confirm'=>'customer123!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['password']);
print_line('TC-R7 — No uppercase', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R8 — No digit (expect rejected)
$r = register_core($db, [
    'email'=>'nodigit@example.com','username'=>'nodigit','phone_no'=>'01112345678',
    'password'=>'Customer!!!','confirm'=>'Customer!!!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['password']);
print_line('TC-R8 — No digit', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R9 — Too short (expect rejected)
$r = register_core($db, [
    'email'=>'tooshort@example.com','username'=>'tooshort','phone_no'=>'01112345678',
    'password'=>'Cust12!','confirm'=>'Cust12!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['password']);
print_line('TC-R9 — Too short', $rejected, 'expected=rejected, got='.label(!$rejected));

// TC-R10 — Mismatch (expect rejected)
$r = register_core($db, [
    'email'=>'mismatch@example.com','username'=>'mismatch','phone_no'=>'01112345678',
    'password'=>'Customer123!','confirm'=>'Customer12!'
]);
$rejected = !($r['ok'] ?? false) && isset($r['errors']['confirm']);
print_line('TC-R10 — Mismatch', $rejected, 'expected=rejected, got='.label(!$rejected));

// Cleanup seeds
t_cleanup($db, $u1, $e1);
t_cleanup($db, $seedU, $seedE);