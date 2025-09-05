<?php
declare(strict_types=1);

/**
 * Core registration logic compatible with your customer table.
 */
function rc_validate_phone_my(string $phone): bool {
    $digits = preg_replace('/\D+/', '', $phone);
    return (bool)preg_match('/^01\d{8,9}$/', $digits);
}
function rc_password_requirements(string $pw): array {
    $err = [];
    if (strlen($pw) < 8) $err['length']='Min 8 chars.';
    if (!preg_match('/[A-Z]/',$pw)) $err['upper']='Require uppercase.';
    if (!preg_match('/[a-z]/',$pw)) $err['lower']='Require lowercase.';
    if (!preg_match('/\d/',$pw))   $err['digit']='Require digit.';
    if (!preg_match('/[^A-Za-z0-9]/',$pw)) $err['special']='Require special.';
    return ['ok'=>empty($err),'errors'=>$err];
}
function register_core(mysqli $db, array $in): array {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $username = trim((string)($in['username'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($in['phone_no'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $confirm  = (string)($in['confirm'] ?? '');
    $fullName = trim((string)($in['full_name'] ?? $username));

    $errors = [];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']='Invalid email format.';
    if ($username === '' || strlen($username) < 3) $errors['username']='Username must be at least 3 characters.';
    if (!rc_validate_phone_my($phone)) $errors['phone_no']='Phone must be 10 or 11 digits starting with 01.';
    if ($password === '') $errors['password']='Password required.';
    else {
        $pw = rc_password_requirements($password);
        if (!$pw['ok']) $errors['password'] = implode(' ', array_values($pw['errors']));
    }
    if ($password !== $confirm) $errors['confirm']='Passwords do not match.';
    if (!empty($errors)) return ['ok'=>false,'errors'=>$errors];

    // Uniqueness checks (aligned with UNIQUE indexes in DDL)
    $st=$db->prepare("SELECT 1 FROM customer WHERE username=? LIMIT 1"); $st->bind_param("s",$username); $st->execute(); $st->store_result();
    if ($st->num_rows>0){ $st->close(); return ['ok'=>false,'errors'=>['username'=>'Username already exists.']]; } $st->close();

    $st=$db->prepare("SELECT 1 FROM customer WHERE email=? LIMIT 1"); $st->bind_param("s",$email); $st->execute(); $st->store_result();
    if ($st->num_rows>0){ $st->close(); return ['ok'=>false,'errors'=>['email'=>'Email already registered.']]; } $st->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = $db->prepare("INSERT INTO customer (full_name, phone_no, email, username, password) VALUES (?, ?, ?, ?, ?)");
    $st->bind_param("sssss", $fullName, $phone, $email, $username, $hash);
    $st->execute();
    return ['ok'=>true, 'cust_id'=>(int)$db->insert_id];
}