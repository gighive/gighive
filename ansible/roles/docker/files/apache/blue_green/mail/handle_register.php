<?php
// handle_register.php
session_start();
require 'config.php';      // DB credentials, mailer settings
require 'vendor/autoload.php';  // for PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Collect & validate
$email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$password = $_POST['password'] ?? '';

if (! $email || strlen($password) < 8) {
    die('Invalid input. Make sure your email is valid and password ≥ 8 chars.');
}

// 2. Hash password
$hash = password_hash($password, PASSWORD_DEFAULT);

// 3. Generate a 32-char activation token
$token = bin2hex(random_bytes(16));

// 4. Insert into DB
try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);
    $stmt = $pdo->prepare(
      "INSERT INTO users (email, password_hash, is_active, activation_token)
       VALUES (:email, :hash, 0, :token)"
    );
    $stmt->execute([
      ':email'  => $email,
      ':hash'   => $hash,
      ':token'  => $token,
    ]);
} catch (PDOException $e) {
    // if duplicate email, you’ll get an error here
    die('Registration error: ' . $e->getMessage());
}

// 5. Send verification email
$mail = new PHPMailer(true);
try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    // Recipients
    $mail->setFrom('no-reply@gighive.io', 'GigHive');
    $mail->addAddress($email);

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Please verify your email for GigHive';
    $verifyLink = SITE_URL . '/activate.php?token=' . $token;
    $mail->Body    = "
      <p>Hi there,</p>
      <p>Thanks for registering your GigHive site. Please click the link below to verify your email address:</p>
      <p><a href=\"$verifyLink\">Activate your account</a></p>
      <p>If that link doesn’t work, paste this URL into your browser:</p>
      <p>$verifyLink</p>
    ";

    $mail->send();
    echo 'Registration successful! Check your email to activate your account.';
} catch (Exception $e) {
    // On mail failure, you might want to delete the user row or flag it
    echo "Could not send verification email. Mailer Error: {$mail->ErrorInfo}";
}

