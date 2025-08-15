<?php
/**************************************************************
 * process_refund.php (ADMIN) – Refund Processing (No Txn Reference)
 *
 * This version removes all “Txn Reference” display/logic. Only
 * reference_code is shown (as before, but not duplicated).
 *
 * Flows:
 *   Deposit refund: ?type=deposit&booking_id=ID
 *   Rental refund : ?type=rental&refund_id=ID
 *
 * Snapshots:
 *   Destination bank (customer)
 *   Source bank (company) if columns/table exist
 *
 * Generates PDF receipt (stored in refunds) & emails customer.
 **************************************************************/
declare(strict_types=1);
session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

require_once __DIR__.'/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use Mpdf\Mpdf;

/* ---------- Helpers ---------- */
function h($v): string {
    if (is_array($v)) {
        $v = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } elseif (is_object($v)) {
        $v = method_exists($v,'__toString') ? (string)$v
             : json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function toAmount($v): float {
    if (is_int($v) || is_float($v)) return (float)$v;
    if (is_string($v)) {
        $t = trim($v);
        if ($t==='') return 0.0;
        $c = preg_replace('/[^0-9.\-]/','',$t);
        if ($c==='' || $c==='.' || $c==='-' || $c==='-.') return 0.0;
        return (float)$c;
    }
    return 0.0;
}
function nf($v, int $d=2): string { return number_format(toAmount($v), $d); }
function maskAcct(?string $acct): string {
    $acct = preg_replace('/\D+/', '', (string)$acct);
    if ($acct==='') return '-';
    return strlen($acct)<=4 ? $acct : str_repeat('•', strlen($acct)-4).substr($acct,-4);
}
function send_mail_smtp(string $toEmail, string $toName, string $subject, string $html): array {
    if (!$toEmail) return [true,''];
$host     = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $port     = (int)(getenv('SMTP_PORT') ?: 587);
    $secure   = getenv('SMTP_SECURE') ?: 'tls';
    $username = getenv('SMTP_USERNAME') ?: 'fathehaharis69@gmail.com';
    $password = getenv('SMTP_PASSWORD') ?: 'cuel ijeu lzqv vsgv';
    $fromEmail= getenv('SMTP_FROM_EMAIL') ?: $username;
    $fromName = getenv('SMTP_FROM_NAME') ?: 'TimeLess Car Rental';
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        if ($secure) $mail->SMTPSecure = $secure;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName ?: 'Customer');
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$html));
        $mail->send();
        return [true,''];
    } catch (Throwable $e) {
        return [false,$e->getMessage()];
    }
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_refund'])) {
    $_SESSION['csrf_refund'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_refund'];

/* ---------- Bank List ---------- */
$MALAYSIAN_BANKS = [
    'Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank',
    'Bank Islam','Bank Rakyat','Affin Bank','Alliance Bank','UOB Malaysia',
    'OCBC Bank','HSBC Bank Malaysia','Standard Chartered','Agrobank','Bank Muamalat',
    'MBSB Bank','Citi Malaysia'
];

/* ---------- Detect refunds columns ---------- */
$refundCols = [];
if ($rc = $conn->query("SHOW COLUMNS FROM refunds")) {
    while ($col = $rc->fetch_assoc()) $refundCols[strtolower($col['Field'])] = true;
    $rc->close();
}
$hasDestCols    = isset($refundCols['payout_bank_name'], $refundCols['payout_bank_account_no']);
$hasReceiptCols = isset($refundCols['refund_receipt_blob'], $refundCols['refund_receipt_mime'], $refundCols['refund_receipt_uploaded_at']);
$hasProcessedBy = isset($refundCols['processed_by_admin_id']);
$hasSourceCols  = isset($refundCols['payout_source_bank_id'], $refundCols['payout_source_bank_name'], $refundCols['payout_source_bank_account_no']);

/* ---------- Company bank accounts ---------- */
$companyBankTableExists = false;
$companyBanks = [];
if ($hasSourceCols) {
    if ($tres = $conn->query("SHOW TABLES LIKE 'company_bank_account'")) {
        $companyBankTableExists = $tres->num_rows > 0;
        $tres->close();
    }
    if ($companyBankTableExists) {
        $cb = $conn->query("SELECT company_bank_id, bank_name, account_holder, account_no
                            FROM company_bank_account
                            WHERE is_active=1
                            ORDER BY display_order, bank_name");
        if ($cb) { while ($r=$cb->fetch_assoc()) $companyBanks[]=$r; $cb->close(); }
    }
}

/* ---------- Input ---------- */
$type      = strtolower(trim($_GET['type'] ?? ''));
$bookingId = isset($_GET['booking_id']) && ctype_digit($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$refundId  = isset($_GET['refund_id']) && ctype_digit($_GET['refund_id']) ? (int)$_GET['refund_id'] : 0;
$isDepositType = ($type==='deposit');

$errors=[]; $flash='';
$refundRow=null; $booking=null; $customer=null;
$refCode=''; $modeLabel=''; $isProcessable=false;

/* ---------- Context loading ---------- */
if ($isDepositType) {
    if ($bookingId < 1) {
        $errors[]='Missing booking_id.';
    } else {
        $bst=$conn->prepare("SELECT * FROM booking WHERE booking_id=? LIMIT 1");
        $bst->bind_param('i',$bookingId);
        $bst->execute();
        $booking=$bst->get_result()->fetch_assoc();
        $bst->close();
        if (!$booking) {
            $errors[]='Booking not found.';
        } else {
            $refCode='DEP-'.$bookingId;
            $rst=$conn->prepare("SELECT * FROM refunds WHERE booking_id=? AND reference_code=? LIMIT 1");
            $rst->bind_param('is',$bookingId,$refCode);
            $rst->execute();
            $refundRow=$rst->get_result()->fetch_assoc();
            $rst->close();
            if (!$refundRow) {
                $errors[]='Deposit refund row not found.';
            } else {
                $isProcessable = ($refundRow['refund_status']==='pending');
                if (!$isProcessable && $refundRow['refund_status']!=='processed') $errors[]='Invalid refund status.';
                if ($refundRow['refund_status']==='pending' && (($booking['deposit_status'] ?? '')!=='pending_refund')) {
                    $errors[]='Booking deposit_status must be pending_refund.';
                }
            }
        }
    }
    $modeLabel='Deposit Refund';
} elseif ($type==='rental') {
    if ($refundId < 1) {
        $errors[]='Missing refund_id.';
    } else {
        $rst=$conn->prepare("SELECT * FROM refunds WHERE refund_id=? LIMIT 1");
        $rst->bind_param('i',$refundId);
        $rst->execute();
        $refundRow=$rst->get_result()->fetch_assoc();
        $rst->close();
        if (!$refundRow) {
            $errors[]='Rental refund row not found.';
        } else {
            $refCode=$refundRow['reference_code'];
            $bookingId=(int)$refundRow['booking_id'];
            if (strpos($refCode,'RENTAL-')!==0) $errors[]='Invalid rental reference code.';
            $bst=$conn->prepare("SELECT * FROM booking WHERE booking_id=? LIMIT 1");
            $bst->bind_param('i',$bookingId);
            $bst->execute();
            $booking=$bst->get_result()->fetch_assoc();
            $bst->close();
            if (!$booking) $errors[]='Related booking not found.';
            $isProcessable = ($refundRow['refund_status']==='pending');
            if (!$isProcessable && $refundRow['refund_status']!=='processed') $errors[]='Invalid refund status.';
        }
    }
    $modeLabel='Refund';
} else {
    $errors[]='Invalid type parameter.';
}

/* ---------- Customer ---------- */
if (!$errors && $booking && $refundRow) {
    $cst=$conn->prepare("SELECT cust_id, full_name, email, bank_name, bank_account_no
                         FROM customer WHERE cust_id=? LIMIT 1");
    $cst->bind_param('i',$booking['cust_id']);
    $cst->execute();
    $customer=$cst->get_result()->fetch_assoc();
    $cst->close();
    if (!$customer) $errors[]='Customer not found.';
}

/* ---------- POST (process) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['process_refund']) && !$errors) {
    if (!$isProcessable) {
        $errors[]='Refund already processed.';
    } elseif (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $errors[]='Invalid session token.';
    } else {
        $destSelect = $_POST['dest_bank_select'] ?? '';
        $otherBank  = trim($_POST['dest_bank_other'] ?? '');
        $custAcct   = preg_replace('/\D+/', '', $_POST['dest_bank_account'] ?? '');
        $finalDestBank = ($destSelect==='OTHER') ? $otherBank : $destSelect;

        if ($hasDestCols) {
            if ($finalDestBank==='' || $custAcct==='') {
                $errors[]='Customer bank name & account are required.';
            } else {
                if ($destSelect==='OTHER') {
                    if (!preg_match("/^[A-Za-z0-9 .,&'()\-]{2,100}$/",$finalDestBank)) $errors[]='Custom bank name invalid.';
                } elseif (!in_array($finalDestBank,$MALAYSIAN_BANKS,true)) {
                    $errors[]='Selected bank not in list.';
                }
                if (!$errors && !preg_match('/^\d{8,20}$/',$custAcct)) $errors[]='Customer account must be 8–20 digits.';
            }
        }

        $sourceBankRow = null;
        if ($hasSourceCols) {
            if (!$companyBankTableExists) {
                $errors[]='Company bank table missing.';
            } else {
                $sourceBankId = isset($_POST['source_bank_id']) && ctype_digit($_POST['source_bank_id'])
                                ? (int)$_POST['source_bank_id'] : 0;
                if ($sourceBankId <= 0) {
                    $errors[]='Please select the company (source) bank.';
                } else {
                    $sb=$conn->prepare("SELECT company_bank_id, bank_name, account_holder, account_no
                                        FROM company_bank_account
                                        WHERE company_bank_id=? AND is_active=1 LIMIT 1");
                    $sb->bind_param('i',$sourceBankId);
                    $sb->execute();
                    $sourceBankRow=$sb->get_result()->fetch_assoc();
                    $sb->close();
                    if (!$sourceBankRow) $errors[]='Selected company bank not found or inactive.';
                }
            }
        }

        if (!$errors) {
            $chk=$conn->prepare("SELECT refund_status FROM refunds WHERE refund_id=? LIMIT 1");
            $chk->bind_param('i',$refundRow['refund_id']);
            $chk->execute();
            $fresh=$chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$fresh || $fresh['refund_status']!=='pending') $errors[]='Refund no longer pending.';
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                $sql="UPDATE refunds SET refund_status='processed', processed_at=NOW(), user_unread=1";
                $bindTypes=''; $bindVals=[];

                if ($hasDestCols) {
                    $sql.=", payout_bank_name=?, payout_bank_account_no=?";
                    $bindTypes.='ss';
                    $bindVals[]=$finalDestBank;
                    $bindVals[]=$custAcct;
                }
                if ($hasSourceCols && $sourceBankRow) {
                    $sql.=", payout_source_bank_id=?, payout_source_bank_name=?, payout_source_bank_account_no=?";
                    $bindTypes.='iss';
                    $bindVals[]=$sourceBankRow['company_bank_id'];
                    $bindVals[]=$sourceBankRow['bank_name'];
                    $bindVals[]=$sourceBankRow['account_no'];
                }
                if ($hasProcessedBy) {
                    $sql.=", processed_by_admin_id=?";
                    $bindTypes.='i';
                    $bindVals[]=(int)$_SESSION['admin_id'];
                }
                $sql.=" WHERE refund_id=? LIMIT 1";
                $bindTypes.='i';
                $bindVals[]=$refundRow['refund_id'];

                $up=$conn->prepare($sql);
                $up->bind_param($bindTypes, ...$bindVals);
                $up->execute();
                $up->close();

                if ($isDepositType) {
                    $ub=$conn->prepare("UPDATE booking
                                           SET deposit_status='refunded',
                                               deposit_last_adjusted_at=NOW(),
                                               updated_at=NOW()
                                         WHERE booking_id=? LIMIT 1");
                    $ub->bind_param('i',$bookingId);
                    $ub->execute();
                    $ub->close();
                }

                $conn->commit();

                // Reload
                $rf=$conn->prepare("SELECT * FROM refunds WHERE refund_id=? LIMIT 1");
                $rf->bind_param('i',$refundRow['refund_id']);
                $rf->execute();
                $refundRow=$rf->get_result()->fetch_assoc();
                $rf->close();
                $isProcessable=false;

                // PDF
                if ($hasReceiptCols && class_exists(Mpdf::class)) {
                    $processedAt  = $refundRow['processed_at'] ?? date('Y-m-d H:i:s');
                    $destBankName = $hasDestCols ? ($refundRow['payout_bank_name'] ?? '') : ($customer['bank_name'] ?? '');
                    $destAcctMask = $hasDestCols ? maskAcct($refundRow['payout_bank_account_no'] ?? '') : maskAcct($customer['bank_account_no'] ?? '');
                    $srcBankName  = $hasSourceCols ? ($refundRow['payout_source_bank_name'] ?? '') : '';
                    $srcAcctMask  = $hasSourceCols ? maskAcct($refundRow['payout_source_bank_account_no'] ?? '') : '';

                    $companyName='TimeLess Car Rental';
                    $companyAddress='DT 1564, JALAN BUKIT TAMBUN PERDANA 21,TAMAN BUKIT TAMBUN PERDANA,76100 DURIAN TUNGGAL, MELAKA';
                    $companyPhone='+60-19-959 0928';
                    $companyEmail='support@timeless.my';
                    $brandingColor='#2d5fd6';

                    $html='
<style>
body { font-family:DejaVu Sans, Arial, sans-serif; font-size:12px; color:#222; }
h1 { font-size:20px; margin:0 0 8px; color:'.$brandingColor.'; text-align:center; }
.meta td { padding:4px 6px 4px 0; vertical-align:top; }
.meta td.label { font-weight:bold; width:160px; }
.divider { height:1px; background:#e0e4ea; margin:18px 0; }
.footer { margin-top:28px; font-size:10px; text-align:center; color:#555; }
</style>
<h1>Refund Receipt</h1>
<div style="text-align:center;font-size:11px">'.$companyName.'<br>'.$companyAddress.'<br>'.$companyPhone.' | '.$companyEmail.'</div>
<div class="divider"></div>
<table class="meta">
  <tr><td class="label">Refund Type:</td><td>'.h($modeLabel).'</td></tr>
  <tr><td class="label">Refund ID:</td><td>'.h($refundRow['refund_id']).'</td></tr>
  <tr><td class="label">Reference Code:</td><td>'.h($refundRow['reference_code']).'</td></tr>
  <tr><td class="label">Booking ID:</td><td>'.h($bookingId).'</td></tr>
  <tr><td class="label">Customer:</td><td>'.h($customer['full_name'] ?? '').' ('.h($customer['email'] ?? '').')</td></tr>
  <tr><td class="label">Amount (RM):</td><td>'.nf($refundRow['amount']).'</td></tr>
  <tr><td class="label">Processed At:</td><td>'.h($processedAt).'</td></tr>
  <tr><td class="label">Payout Bank:</td><td>'.h($destBankName ?: '-').'</td></tr>
  <tr><td class="label">Payout Account:</td><td>'.h($destAcctMask).'</td></tr>';
                    if ($srcBankName) {
                        $html.='<tr><td class="label">Source Bank:</td><td>'.h($srcBankName).'</td></tr>
                                <tr><td class="label">Source Account:</td><td>'.h($srcAcctMask).'</td></tr>';
                    }
                    $html.='
</table>
<div class="footer">System-generated receipt.</div>';

                    try {
                        $mpdf=new Mpdf(['mode'=>'utf-8','format'=>'A4']);
                        $mpdf->SetTitle('Refund Receipt '.$refundRow['reference_code']);
                        $mpdf->WriteHTML($html);
                        $pdfContent=$mpdf->Output('', 'S');

                        $upPdf=$conn->prepare("UPDATE refunds
                            SET refund_receipt_mime='application/pdf',
                                refund_receipt_blob=?,
                                refund_receipt_uploaded_at=NOW()
                            WHERE refund_id=? LIMIT 1");
                        $upPdf->bind_param('si',$pdfContent,$refundRow['refund_id']);
                        $upPdf->execute();
                        $upPdf->close();
                    } catch (Throwable $e) {
                        $err='PDF_FAIL: '.$e->getMessage();
                        $an=$conn->prepare("UPDATE refunds SET notes=CONCAT(IFNULL(notes,''),' || ',?) WHERE refund_id=? LIMIT 1");
                        $an->bind_param('si',$err,$refundRow['refund_id']);
                        $an->execute();
                        $an->close();
                    }
                }

                // Email
                $custEmail   = $customer['email'] ?? '';
                $custName    = $customer['full_name'] ?? 'Customer';
                $destBankName= $hasDestCols ? ($refundRow['payout_bank_name'] ?? '') : ($customer['bank_name'] ?? '');
                $destAcctMask= $hasDestCols ? maskAcct($refundRow['payout_bank_account_no'] ?? '') : maskAcct($customer['bank_account_no'] ?? '');
                $srcBankName = $hasSourceCols ? ($refundRow['payout_source_bank_name'] ?? '') : '';
                $srcAcctMask = $hasSourceCols ? maskAcct($refundRow['payout_source_bank_account_no'] ?? '') : '';
                $amtStr      = nf($refundRow['amount']);

                $subject=$modeLabel." Processed – Booking #{$bookingId}";
                $emailHtml="
<div style='font-family:Arial,sans-serif;font-size:14px;color:#222'>
  <p>Hi ".h($custName).",</p>
  <p>Your {$modeLabel} for booking <strong>#{$bookingId}</strong> has been processed.</p>
  <p>
    <strong>Reference:</strong> ".h($refundRow['reference_code'])."<br>
    <strong>Amount:</strong> RM ".h($amtStr)."<br>
    <strong>Payout Bank:</strong> ".h($destBankName ?: '-')."<br>
    <strong>Payout Account:</strong> ".h($destAcctMask)."<br>";
                if ($srcBankName) {
                    $emailHtml .= "<strong>Source Bank:</strong> ".h($srcBankName)." (Acct ".h($srcAcctMask).")<br>";
                }
                $emailHtml .="
  </p>
  <p style='color:#555'>Thank you for choosing TimeLess Car Rental.</p>
</div>";
                send_mail_smtp($custEmail,$custName,$subject,$emailHtml);

                $flash='Refund processed successfully.';
                header('Location: process_refund.php?type='.urlencode($type)
                      .($isDepositType ? '&booking_id='.$bookingId : '&refund_id='.$refundRow['refund_id'])
                      .'&flash='.urlencode($flash));
                exit;
            } catch (Throwable $ex) {
                $conn->rollback();
                $errors[]='Processing failed: '.$ex->getMessage();
            }
        }
    }
}

if (isset($_GET['flash'])) $flash=$_GET['flash'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($modeLabel ?: 'Refund') ?> Processing</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
<style>
body { font-family:Arial,Helvetica,sans-serif; background:#f4f6fa; margin:0; }
.wrapper { max-width:960px; margin:40px auto 70px; background:#fff; padding:34px 40px 50px;
  border-radius:18px; box-shadow:0 6px 28px -6px rgba(40,60,120,.12); }
h1 { margin:0 0 18px; font-size:1.55rem; color:#1e3c60; }
.section-title { margin:34px 0 12px; font-size:.9rem; font-weight:800; color:#274b74; letter-spacing:.5px; text-transform:uppercase; }
.kv-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.kv-table th { text-align:left; width:180px; padding:8px 0; color:#4a5a74; vertical-align:top; }
.kv-table td { padding:8px 0; }
.msg { padding:12px 16px; border-radius:10px; font-size:.8rem; font-weight:600; margin:0 0 18px;}
.msg.flash { background:#e6fcf3; color:#176a42; }
.msg.error { background:#ffe9e9; color:#b63a3a; }
.badge { display:inline-block; background:#fff6d8; color:#a87600; padding:4px 8px; border-radius:7px; font-size:.62rem; font-weight:700; letter-spacing:.4px; }
.badge.processed { background:#e6fcf3; color:#176a42; }
form .field { margin-bottom:18px; }
label { font-size:.65rem; font-weight:700; display:block; margin:0 0 5px; letter-spacing:.5px; color:#2b3f5f; text-transform:uppercase; }
select, input[type=text] { width:100%; padding:10px 12px; border:1.5px solid #c6cede; border-radius:8px; background:#fafcff; font-size:.82rem; }
select:focus, input[type=text]:focus { outline:none; border-color:#2d5fd6; background:#fff; }
.note { font-size:.62rem; color:#5d6d83; margin-top:4px; line-height:1.1rem; }
.actions { display:flex; gap:14px; flex-wrap:wrap; margin-top:14px; }
button { background:#2d5fd6; color:#fff; border:none; border-radius:8px; padding:12px 24px; font-size:.78rem; font-weight:700; cursor:pointer; letter-spacing:.4px; box-shadow:0 3px 10px rgba(0,0,0,.12); }
button:hover { filter:brightness(.93); }
.back-link { text-decoration:none; font-size:.75rem; color:#2d5fd6; font-weight:700; }
.processed-box { background:#e6fcf3; border:1px solid #b9e5cc; padding:14px 16px; font-size:.75rem; color:#176a42; border-radius:10px; margin:24px 0 10px; }
.receipt-link a { color:#2d5fd6; font-weight:700; font-size:.75rem; text-decoration:none; }
.receipt-link a:hover { text-decoration:underline; }
#other-bank-row { display:none; }
.inline-note { font-size:.65rem; color:#44556a; margin:4px 0 0; }
.warning-box { background:#fff7e0; border:1px solid #f2d28c; padding:10px 14px; border-radius:10px; font-size:.7rem; color:#7a5910; margin:14px 0 0;}
</style>
</head>
<body>
<div class="wrapper">
    <h1><?= h($modeLabel ?: 'Refund') ?> Processing</h1>
    <div style="margin-bottom:14px;"><a class="back-link" href="payments.php">&larr; Back to Payments</a></div>

    <?php if ($flash): ?><div class="msg flash"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="msg error"><?php foreach ($errors as $er) echo '<div>'.h($er).'</div>'; ?></div><?php endif; ?>

    <?php if ($refundRow): ?>
        <?php if ($refundRow['refund_status']==='processed'): ?>
            <div class="processed-box">
                Refund processed successfully.
                <?php if ($hasReceiptCols && !empty($refundRow['refund_receipt_blob'])): ?>
                    <div class="receipt-link" style="margin-top:6px;">
                        <a target="_blank" href="refund_receipt.php?id=<?= h($refundRow['refund_id']) ?>">View PDF</a>
                        &nbsp;|&nbsp;
                        <a target="_blank" href="refund_receipt.php?id=<?= h($refundRow['refund_id']) ?>&download=1">Download</a>
                    </div>
                <?php else: ?>
                    <div class="warning-box">Receipt missing (check notes for PDF_FAIL or regenerate).</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="section-title">Refund Summary</div>
        <table class="kv-table">
            <tr><th>Booking ID</th><td><?= h($bookingId) ?></td></tr>
            <tr><th>Reference Code</th><td><?= h($refCode) ?></td></tr>
            <tr><th>Refund Type</th><td><?= h($modeLabel) ?></td></tr>
            <tr><th>Status</th><td><?= $refundRow['refund_status']==='pending'
                    ? '<span class="badge">PENDING</span>'
                    : '<span class="badge processed">PROCESSED</span>' ?></td></tr>
            <tr><th>Amount</th><td>RM <?= nf($refundRow['amount']) ?></td></tr>
            <?php if (!empty($refundRow['processed_at'])): ?>
                <tr><th>Processed At</th><td><?= h($refundRow['processed_at']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($refundRow['payout_source_bank_name'])): ?>
                <tr><th>Source Bank</th><td><?= h($refundRow['payout_source_bank_name']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($refundRow['payout_source_bank_account_no'])): ?>
                <tr><th>Source Account</th><td><?= h(maskAcct($refundRow['payout_source_bank_account_no'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($refundRow['notes'])): ?>
                <tr><th>Notes</th><td><?= h($refundRow['notes']) ?></td></tr>
            <?php endif; ?>
        </table>

        <div class="section-title">Customer</div>
        <table class="kv-table">
            <tr><th>Name</th><td><?= h($customer['full_name'] ?? '-') ?></td></tr>
            <tr><th>Email</th><td><?= h($customer['email'] ?? '-') ?></td></tr>
            <tr><th>Stored Bank</th><td><?= h($customer['bank_name'] ?? '-') ?></td></tr>
            <tr><th>Stored Account (Full)</th><td><?= h($customer['bank_account_no'] ?? '-') ?></td></tr>
        </table>

        <?php if ($isProcessable && !$errors): ?>
            <form method="post" autocomplete="off" novalidate style="margin-top:20px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="process_refund" value="1">
                <?php if ($isDepositType): ?>
                    <input type="hidden" name="type" value="deposit">
                    <input type="hidden" name="booking_id" value="<?= h($bookingId) ?>">
                <?php else: ?>
                    <input type="hidden" name="type" value="rental">
                    <input type="hidden" name="refund_id" value="<?= h($refundRow['refund_id']) ?>">
                <?php endif; ?>

                <div class="section-title">Transfer Details (Destination)</div>
                <?php if ($hasDestCols): ?>
                    <div class="field">
                        <label for="dest_bank_select">Bank Name</label>
                        <select id="dest_bank_select" name="dest_bank_select" required>
                            <option value="" disabled selected>-- Select Bank --</option>
                            <?php
                              $prefillBank  = $refundRow['payout_bank_name'] ?: ($customer['bank_name'] ?? '');
                              $prefillKnown = in_array($prefillBank,$MALAYSIAN_BANKS,true);
                              foreach ($MALAYSIAN_BANKS as $bn) {
                                  $sel = ($prefillKnown && $prefillBank===$bn)?'selected':'';
                                  echo '<option value="'.h($bn).'" '.$sel.'>'.h($bn).'</option>';
                              }
                            ?>
                            <option value="OTHER" <?= (!$prefillKnown && $prefillBank)?'selected':''; ?>>Other (Not Listed)</option>
                        </select>
                        <div class="note">Choose listed bank or select "Other".</div>
                    </div>
                    <div class="field" id="other-bank-row">
                        <label for="dest_bank_other">Other Bank Name</label>
                        <input type="text" id="dest_bank_other" name="dest_bank_other"
                               value="<?= !$prefillKnown ? h($prefillBank) : '' ?>"
                               maxlength="100" pattern="[A-Za-z0-9 .,&'()\-]{2,100}">
                        <div class="note">Allowed: letters, digits, space . , & ' ( ) -</div>
                    </div>
                    <div class="field">
                        <label for="dest_bank_account">Customer Account Number</label>
                        <input type="text" id="dest_bank_account" name="dest_bank_account"
                               value="<?= h($refundRow['payout_bank_account_no'] ?: ($customer['bank_account_no'] ?? '')) ?>"
                               maxlength="34" pattern="\d{8,20}" required>
                        <div class="note">Digits only (8–20). Stored unmasked.</div>
                    </div>
                <?php else: ?>
                    <p class="inline-note">Destination snapshot columns missing.</p>
                <?php endif; ?>

                <div class="section-title">Admin / Company Bank (Source)</div>
                <?php if ($hasSourceCols): ?>
                    <?php if ($companyBankTableExists): ?>
                        <?php if ($companyBanks): ?>
                            <div class="field">
                                <label for="source_bank_id">Company Bank</label>
                                <select id="source_bank_id" name="source_bank_id" required>
                                    <option value="" disabled selected>-- Select Company Bank --</option>
                                    <?php foreach ($companyBanks as $cb):
                                        $label=$cb['bank_name'].' - '.$cb['account_holder'].' ('.maskAcct($cb['account_no']).')'; ?>
                                        <option value="<?= h($cb['company_bank_id']) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="note">Snapshot saved for audit.</div>
                            </div>
                        <?php else: ?>
                            <p class="inline-note">No active company bank accounts.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="inline-note">company_bank_account table missing.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="inline-note">Source bank snapshot columns missing.</p>
                <?php endif; ?>

                <div class="section-title">Finalize</div>
                <div class="note">Reference code will identify this refund: <?= h($refCode) ?>.</div>

                <div class="actions">
                    <button type="submit"><?= $isDepositType ? 'Process Deposit Refund' : 'Process Refund' ?></button>
                    <a href="payments.php" class="back-link" style="align-self:center;">Cancel</a>
                </div>
            </form>
        <?php elseif ($refundRow['refund_status']==='pending' && $errors): ?>
            <div class="warning-box">Resolve errors above and retry.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function(){
    const selectEl=document.getElementById('dest_bank_select');
    const otherRow=document.getElementById('other-bank-row');
    function toggleOther(){
        if(!selectEl||!otherRow) return;
        if(selectEl.value==='OTHER'){
            otherRow.style.display='block';
            const ob=document.getElementById('dest_bank_other');
            if(ob) ob.required=true;
        } else {
            otherRow.style.display='none';
            const ob=document.getElementById('dest_bank_other');
            if(ob) ob.required=false;
        }
    }
    if(selectEl){ selectEl.addEventListener('change',toggleOther); toggleOther(); }
})();
</script>
</body>
</html>