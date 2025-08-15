<?php
declare(strict_types=1);
session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

/*
  Requires columns (run once if missing):
  ALTER TABLE customer
    ADD COLUMN bank_name VARCHAR(100) DEFAULT NULL AFTER phone_no,
    ADD COLUMN bank_account_no VARCHAR(34) DEFAULT NULL AFTER bank_name;
*/

if (empty($_SESSION['cust_id'])) {
    header("Location: login.php");
    exit;
}

$cust_id = (int)$_SESSION['cust_id'];

/* ---------------- Malaysian Banks List ----------------
   NOTE: Add / remove as needed. Use the EXACT display labels you want to store.
*/
$MALAYSIAN_BANKS = [
    'Maybank',
    'CIMB Bank',
    'Public Bank',
    'RHB Bank',
    'Hong Leong Bank',
    'AmBank',
    'Bank Islam',
    'Bank Rakyat',
    'Affin Bank',
    'Alliance Bank',
    'UOB Malaysia',
    'OCBC Bank',
    'HSBC Bank Malaysia',
    'Standard Chartered',
    'Agrobank',
    'Bank Muamalat',
    'Citi Malaysia',
    'MBSB Bank',
];

/* --------------- CSRF Token --------------- */
if (empty($_SESSION['cust_csrf'])) {
    $_SESSION['cust_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['cust_csrf'];

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* --------------- Fetch Current Data --------------- */
$stmt = $conn->prepare("SELECT full_name, bank_name, bank_account_no FROM customer WHERE cust_id = ? LIMIT 1");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($full_name, $bank_name, $bank_account_no);
$stmt->fetch();
$stmt->close();

/* Mask account number for display */
$masked_account = '';
if (!empty($bank_account_no)) {
    $digits_only = preg_replace('/\D+/', '', $bank_account_no);
    if (strlen($digits_only) > 4) {
        $masked_account = str_repeat('•', strlen($digits_only) - 4) . substr($digits_only, -4);
    } else {
        $masked_account = $digits_only;
    }
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = "Invalid session token. Please refresh the page.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $selected_bank = trim($_POST['bank_select'] ?? '');
            $other_bank    = trim($_POST['other_bank_name'] ?? '');
            $acct_raw      = trim($_POST['bank_account_no'] ?? '');

            // Normalize account number: remove anything not a digit
            $normalized_acct = preg_replace('/\D+/', '', $acct_raw);

            // Determine final bank name
            if ($selected_bank === 'OTHER') {
                $final_bank = $other_bank;
            } else {
                $final_bank = $selected_bank;
            }

            // Validation
            if ($final_bank === '' || $normalized_acct === '') {
                $error = "Both bank name and account number are required.";
            } else {
                if ($selected_bank === 'OTHER') {
                    // Validate custom bank name (allow typical characters)
                    if (!preg_match("/^[A-Za-z0-9 .,&'()\-]{2,100}$/", $final_bank)) {
                        $error = "Other bank name contains invalid characters.";
                    }
                } else {
                    // Must be in list
                    if (!in_array($final_bank, $MALAYSIAN_BANKS, true)) {
                        $error = "Selected bank is not recognized.";
                    }
                }
                // Validate account number length (adjust range if your banks demand stricter limits)
                if (!$error && !preg_match('/^\d{8,20}$/', $normalized_acct)) {
                    $error = "Bank account number must be 8–20 digits (after removing spaces/dashes).";
                }
            }

            if (!$error) {
                $upd = $conn->prepare("
                    UPDATE customer
                       SET bank_name = ?, bank_account_no = ?
                     WHERE cust_id = ?
                     LIMIT 1
                ");
                $upd->bind_param("ssi", $final_bank, $normalized_acct, $cust_id);
                if ($upd->execute()) {
                    $success = "Bank details updated successfully.";
                    $bank_name = $final_bank;
                    $bank_account_no = $normalized_acct;
                    $digits_only = $normalized_acct;
                    if (strlen($digits_only) > 4) {
                        $masked_account = str_repeat('•', strlen($digits_only) - 4) . substr($digits_only, -4);
                    } else {
                        $masked_account = $digits_only;
                    }
                } else {
                    $error = "Update failed. Please try again.";
                }
                $upd->close();
            }

        } elseif ($action === 'clear') {
            $upd = $conn->prepare("UPDATE customer SET bank_name = NULL, bank_account_no = NULL WHERE cust_id = ? LIMIT 1");
            $upd->bind_param("i", $cust_id);
            if ($upd->execute()) {
                $success = "Bank details cleared.";
                $bank_name = $bank_account_no = $masked_account = '';
            } else {
                $error = "Failed to clear details.";
            }
            $upd->close();
        } else {
            $error = "Unknown action.";
        }
    }
}

include 'customer_header.php'; // Adjust if needed
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Bank Details</title>
    <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background:#f5f7fa; }
        .bank-wrapper {
            max-width: 560px;
            margin: 50px auto 70px;
            background:#fff;
            border-radius:14px;
            box-shadow:0 4px 18px -6px rgba(40,60,120,.18);
            padding:34px 40px 42px;
        }
        .bank-wrapper h1 {
            margin:0 0 20px;
            font-size:1.55rem;
            font-weight:800;
            color:#2b5cbc;
        }
        .desc { font-size:.9rem; color:#526079; margin-bottom:20px; line-height:1.35rem; }
        .msg { padding:12px 16px; border-radius:9px; font-size:.9rem; font-weight:600; margin-bottom:18px; }
        .msg-success { background:#e6f9ed; color:#156a36; border:1px solid #b8e7c7; }
        .msg-error { background:#ffe9e7; color:#9f2719; border:1px solid #f5c1b8; }
        .form-row { margin-bottom:18px; }
        label { display:block; font-size:.73rem; font-weight:700; letter-spacing:.6px; color:#2a3f67; margin-bottom:7px; text-transform:uppercase; }
        select, input[type=text] {
            width:100%; padding:11px 13px; border:1.6px solid #c6cee1; border-radius:8px;
            background:#fbfcff; font-size:.92rem; transition:border .15s, background .15s;
        }
        select:focus, input[type=text]:focus { outline:none; border-color:#385ecb; background:#fff; }
        .actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:4px; }
        button, .btn {
            border:none; cursor:pointer; border-radius:8px; padding:11px 22px;
            font-size:.85rem; font-weight:700; background:#2b5cbc; color:#fff;
            letter-spacing:.4px; transition:background .18s; text-decoration:none;
        }
        button:hover, .btn:hover { background:#1d407f; }
        .btn-secondary { background:#d6dce8; color:#2b426d; }
        .btn-secondary:hover { background:#c0c8d8; }
        .btn-danger { background:#d93838; }
        .btn-danger:hover { background:#b61f1f; }
        .note { font-size:.68rem; color:#5d6a80; margin-top:6px; line-height:1.1rem; }
        .divider { height:1px; background:#e3e8f2; margin:28px 0 24px; }
        .masked-box { font-family:monospace; letter-spacing:1px; }
        #other-bank-row { display:none; }
        @media (max-width:640px) { .bank-wrapper { padding:28px 24px 36px; } }
    </style>
</head>
<body>
<?php include '../includes/header.php'; ?>
<div class="bank-wrapper">
    <h1>Bank Details</h1>
    <div class="desc">
        Select your Malaysian bank and provide the account number used for refunds. We show only a masked version of your account number on this page.
    </div>

    <?php if ($success): ?><div class="msg msg-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg msg-error"><?= h($error) ?></div><?php endif; ?>

    <?php
      // Preselect logic: if stored bank is not in list, we treat it as OTHER
      $storedBank = $bank_name ?? '';
      $isStoredBankKnown = $storedBank && in_array($storedBank, $MALAYSIAN_BANKS, true);
      $selectValue = $isStoredBankKnown ? $storedBank : ($storedBank ? 'OTHER' : '');
      $otherBankValue = $isStoredBankKnown ? '' : $storedBank;
    ?>

    <form method="post" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

        <div class="form-row">
            <label for="bank_select">Bank Name<span style="color:#d42d2d;">*</span></label>
            <select name="bank_select" id="bank_select" required>
                <option value="" disabled <?= $selectValue===''?'selected':''; ?>>-- Select Malaysian Bank --</option>
                <?php foreach ($MALAYSIAN_BANKS as $bn): ?>
                    <option value="<?= h($bn) ?>" <?= $selectValue===$bn?'selected':''; ?>><?= h($bn) ?></option>
                <?php endforeach; ?>
                <option value="OTHER" <?= $selectValue==='OTHER'?'selected':''; ?>>Other (Not Listed)</option>
            </select>
            <div class="note">Choose your bank. If not in the list, pick "Other" and enter it below.</div>
        </div>

        <div class="form-row" id="other-bank-row">
            <label for="other_bank_name">Other Bank Name<span style="color:#d42d2d;">*</span></label>
            <input type="text" id="other_bank_name" name="other_bank_name"
                   maxlength="100"
                   value="<?= h($otherBankValue) ?>"
                   placeholder="Enter exact bank name"
                   pattern="[A-Za-z0-9 .,&'()\-]{2,100}">
            <div class="note">Allowed: letters, numbers, spaces, . , & ' ( ) - (2–100 chars)</div>
        </div>

        <div class="form-row">
            <label for="bank_account_no">Bank Account Number<span style="color:#d42d2d;">*</span></label>
            <input type="text"
                   id="bank_account_no"
                   name="bank_account_no"
                   value="<?= h($bank_account_no) ?>"
                   inputmode="numeric"
                   maxlength="34"
                   placeholder="Digits only (8–20 typical)"
                   pattern="[0-9 \-]{8,34}"
                   required>
            <div class="note">Enter digits (you can include spaces or dashes; they will be removed). 8–20 digits accepted.</div>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="update">Save / Update</button>
            <?php if ($bank_name || $bank_account_no): ?>
                <button type="submit" name="action" value="clear" class="btn-danger"
                        onclick="return confirm('Clear stored bank details?');">Clear</button>
            <?php endif; ?>
            <a href="dashboard.php" class="btn-secondary btn">Back to Dashboard</a>
        </div>
    </form>

    <div class="divider"></div>

    <h2 style="margin:0 0 14px; font-size:1rem; font-weight:800; color:#2f4775;">Stored (Masked) View</h2>
    <?php if ($bank_name || $bank_account_no): ?>
        <div style="margin-bottom:10px;"><strong>Bank:</strong> <?= h($bank_name ?: '-') ?></div>
        <div><strong>Account:</strong> <span class="masked-box"><?= $masked_account ? h($masked_account) : '<em>Not available</em>' ?></span></div>
        <div class="note" style="margin-top:10px;">Only the last 4 digits (if available) are shown for security.</div>
    <?php else: ?>
        <div class="note">No bank details saved yet.</div>
    <?php endif; ?>
</div>

<script>
(function(){
    const sel = document.getElementById('bank_select');
    const otherRow = document.getElementById('other-bank-row');
    function toggleOther() {
        if (!sel) return;
        if (sel.value === 'OTHER') {
            otherRow.style.display = 'block';
            document.getElementById('other_bank_name').required = true;
        } else {
            otherRow.style.display = 'none';
            const ob = document.getElementById('other_bank_name');
            ob.required = false;
            // Optionally clear value: ob.value = '';
        }
    }
    if (sel) {
        sel.addEventListener('change', toggleOther);
        toggleOther();
    }
})();
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>