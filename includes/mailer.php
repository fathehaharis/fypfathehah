<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Send an email via SMTP (PHPMailer).
 * Returns [bool success, string errorMessage]
 *
 * Configure via environment variables (recommended):
 *   SMTP_HOST, SMTP_PORT, SMTP_SECURE (tls|ssl|''), SMTP_USERNAME, SMTP_PASSWORD,
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME, SMTP_REPLY_TO, SMTP_REPLY_TO_NAME
 *
 * For Gmail:
 * - SMTP_HOST=smtp.gmail.com
 * - SMTP_PORT=587
 * - SMTP_SECURE=tls
 * - SMTP_USERNAME=your_gmail@gmail.com
 * - SMTP_PASSWORD=your_app_password   (not your login password)
 * - SMTP_FROM_EMAIL=your_gmail@gmail.com (Gmail requires From to match authenticated user)
 */
function send_mail_smtp(string $toEmail, string $toName, string $subject, string $html, string $altText = ''): array
{
    $host     = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $port     = (int)(getenv('SMTP_PORT') ?: 587);
    $secure   = getenv('SMTP_SECURE') ?: 'tls';
    $username = getenv('SMTP_USERNAME') ?: 'fathehaharis69@gmail.com';
    $password = getenv('SMTP_PASSWORD') ?: 'cuel ijeu lzqv vsgv';
    $fromEmail= getenv('SMTP_FROM_EMAIL') ?: $username; // Gmail requires from == username
    $fromName = getenv('SMTP_FROM_NAME') ?: 'TimeLess Car Rental';
    $replyTo  = getenv('SMTP_REPLY_TO') ?: $fromEmail;
    $replyNm  = getenv('SMTP_REPLY_TO_NAME') ?: $fromName;

    if (!class_exists(PHPMailer::class)) {
        return [false, 'PHPMailer not installed. Run: composer require phpmailer/phpmailer'];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        if ($secure) $mail->SMTPSecure = $secure; // tls or ssl
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($replyTo, $replyNm);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $altText !== '' ? $altText : strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html));

        $mail->send();
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}