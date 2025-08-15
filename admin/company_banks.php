<?php
/**************************************************************
 * company_banks.php (ADMIN – Single Bank Version, SIMPLE, NO DELETE)
 *
 * - Only one company bank allowed.
 * - Admin can add (if none exists) or edit (if exists).
 * - Delete action is removed.
 **************************************************************/
declare(strict_types=1);
session_start();
require_once '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_company_bank'])) {
    $_SESSION['csrf_company_bank'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_company_bank'];

/* ---------- Helpers ---------- */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function maskAcct(?string $acct): string {
    $acct = preg_replace('/\D+/', '', (string)$acct);
    if ($acct === '') return '-';
    return strlen($acct)<=4 ? $acct : str_repeat('•', strlen($acct)-4).substr($acct,-4);
}
function validBankName(string $s): bool {
    return (bool)preg_match("/^[A-Za-z0-9 .,&'()\-]{2,100}$/", $s);
}
function validHolder(string $s): bool {
    return (bool)preg_match("/^[A-Za-z0-9 .,&'()\-]{2,120}$/", $s);
}
function validAcct(string $s): bool {
    return (bool)preg_match('/^\d{8,30}$/', $s);
}
function redirectSelf(array $extra = []): never {
    $qs = array_merge($_GET, $extra);
    header('Location: company_banks.php'.($qs ? '?'.http_build_query($qs) : ''));
    exit;
}

/* ---------- (Optional) Bank name suggestions ---------- */
$MALAYSIAN_BANKS = [
    'Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank',
    'Bank Islam','Bank Rakyat','Affin Bank','Alliance Bank','UOB Malaysia',
    'OCBC Bank','HSBC Bank Malaysia','Standard Chartered','Agrobank','Bank Muamalat',
    'MBSB Bank','Citi Malaysia'
];

/* ---------- Fetch existing single record (if any) ---------- */
$bankRow = null;
$res = $conn->query("SELECT * FROM company_bank_account ORDER BY company_bank_id LIMIT 1");
if ($res && $res->num_rows) {
    $bankRow = $res->fetch_assoc();
}
if ($res) $res->close();

$flash = $_GET['flash'] ?? '';
$errors = [];

/* ---------- Handle POST (Add / Edit) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $errors[]='Invalid session token.';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'add') {
            if ($bankRow) {
                $errors[]='Bank already exists. Edit it to update.';
            } else {
                $bankSel  = trim($_POST['bank_name'] ?? '');
                $custom   = trim($_POST['custom_bank_name'] ?? '');
                $bankName = ($bankSel === '_OTHER_' ? $custom : $bankSel);
                $holder   = trim($_POST['account_holder'] ?? '');
                $acctNo   = preg_replace('/\D+/', '', $_POST['account_no'] ?? '');

                if ($bankName === '' || !validBankName($bankName)) $errors[]='Invalid bank name.';
                if ($holder === ''   || !validHolder($holder))     $errors[]='Invalid account holder.';
                if ($acctNo === ''   || !validAcct($acctNo))       $errors[]='Invalid account number (8–30 digits).';

                if (!$errors) {
                    $ins = $conn->prepare("INSERT INTO company_bank_account
                        (bank_name, account_holder, account_no, created_at)
                        VALUES (?, ?, ?, NOW())");
                    $ins->bind_param('sss', $bankName, $holder, $acctNo);
                    if ($ins->execute()) {
                        redirectSelf(['flash'=>'Company bank added.']);
                    } else {
                        $errors[]='Insert failed: '.$conn->error;
                    }
                    $ins->close();
                }
            }
        } elseif ($formAction === 'edit') {
            if (!$bankRow) {
                $errors[]='No bank to edit.';
            } else {
                $bankSel  = trim($_POST['bank_name'] ?? '');
                $custom   = trim($_POST['custom_bank_name'] ?? '');
                $bankName = ($bankSel === '_OTHER_' ? $custom : $bankSel);
                $holder   = trim($_POST['account_holder'] ?? '');
                $acctNo   = preg_replace('/\D+/', '', $_POST['account_no'] ?? '');

                if ($bankName === '' || !validBankName($bankName)) $errors[]='Invalid bank name.';
                if ($holder === ''   || !validHolder($holder))     $errors[]='Invalid account holder.';
                if ($acctNo === ''   || !validAcct($acctNo))       $errors[]='Invalid account number (8–30 digits).';

                if (!$errors) {
                    $up = $conn->prepare("UPDATE company_bank_account
                                           SET bank_name=?, account_holder=?, account_no=?, updated_at=NOW()
                                           WHERE company_bank_id=? LIMIT 1");
                    $id = (int)$bankRow['company_bank_id'];
                    $up->bind_param('sssi', $bankName, $holder, $acctNo, $id);
                    if ($up->execute()) {
                        redirectSelf(['flash'=>'Company bank updated.']);
                    } else {
                        $errors[]='Update failed: '.$conn->error;
                    }
                    $up->close();
                }
            }
        }
    }
}

/* ---------- Re-fetch after possible changes ---------- */
if (!$errors && !empty($_GET['flash'])) {
    // flash was set via redirectSelf
} else {
    // If modifications happened without redirect (unlikely), refresh local copy
    if (!$flash && !$errors) {
        $res2 = $conn->query("SELECT * FROM company_bank_account ORDER BY company_bank_id LIMIT 1");
        $bankRow = ($res2 && $res2->num_rows) ? $res2->fetch_assoc() : null;
        if ($res2) $res2->close();
    }
}

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Company Bank (Single)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { background:#f5f7fb; margin:0; font-family:Arial,Helvetica,sans-serif; }
.container { max-width:820px; margin:40px auto 80px; background:#fff; padding:34px 44px 54px;
  border-radius:18px; box-shadow:0 6px 30px rgba(40,60,120,.08); }
h1 { margin:0 0 22px; font-size:1.6rem; color:#1e3c60; }
.msg { padding:12px 16px; border-radius:10px; font-size:.82rem; font-weight:600; margin:0 0 18px; }
.msg.flash { background:#e6fcf3; color:#176a42; }
.msg.error { background:#ffe9e9; color:#b63a3a; }
.card { background:#f8fbff; border:1px solid #dbe4f0; border-radius:16px; padding:22px 24px 26px;
  margin:0 0 24px; }
.card h2 { margin:0 0 12px; font-size:1.05rem; color:#274b74; }
.kv { width:100%; border-collapse:collapse; font-size:.78rem; }
.kv th { text-align:left; padding:6px 4px 6px 0; width:160px; color:#4a5a74; font-weight:600; }
.kv td { padding:6px 4px; }
.actions { display:flex; gap:14px; margin-top:12px; flex-wrap:wrap; }
button, .btn-link {
  background:#2d5fd6; color:#fff; border:none; border-radius:8px; padding:10px 18px;
  font-size:.72rem; font-weight:700; cursor:pointer; letter-spacing:.4px; text-decoration:none;
  box-shadow:0 3px 10px rgba(0,0,0,.12);
}
button:hover, .btn-link:hover { background:#1f4fab; }
form.bank-form { margin-top:8px; }
.bank-form .field { margin-bottom:18px; }
.bank-form label { font-size:.62rem; font-weight:700; letter-spacing:.5px; color:#2b3f5f;
  display:block; margin:0 0 6px; text-transform:uppercase; }
.bank-form select, .bank-form input[type=text] {
  width:100%; padding:10px 12px; border:1.5px solid #c6cede; border-radius:8px; background:#fff;
  font-size:.78rem; font-family:inherit;
}
.bank-form input[type=text]:focus, .bank-form select:focus { outline:none; border-color:#2d5fd6; }
.note { font-size:.6rem; color:#5d6d83; margin-top:4px; line-height:1.05rem; }
.inline-warning { font-size:.6rem; color:#b63a3a; margin-top:4px; }
.hidden { display:none; }
@media (max-width:720px){
  .container { padding:26px 26px 70px; }
}
</style>
</head>
<body>
<div class="container">
    <h1>Company Bank Account</h1>

    <?php if ($flash): ?><div class="msg flash"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="msg error"><?php foreach ($errors as $er) echo '<div>'.h($er).'</div>'; ?></div><?php endif; ?>

    <?php if ($bankRow): ?>
        <div class="card">
            <h2>Current Bank</h2>
            <table class="kv">
                <tr><th>Bank Name</th><td><?= h($bankRow['bank_name']) ?></td></tr>
                <tr><th>Account Holder</th><td><?= h($bankRow['account_holder']) ?></td></tr>
                <tr><th>Account Number (Masked)</th><td><?= h(maskAcct($bankRow['account_no'])) ?></td></tr>
                <tr><th>Full Account Number</th><td><?= h($bankRow['account_no']) ?></td></tr>
                <tr><th>Created</th><td><?= h($bankRow['created_at']) ?></td></tr>
                <?php if (!empty($bankRow['updated_at'])): ?>
                    <tr><th>Updated</th><td><?= h($bankRow['updated_at']) ?></td></tr>
                <?php endif; ?>
            </table>
            <div class="actions">
                <button type="button" onclick="toggleEdit()" id="editBtn">Edit</button>
            </div>

            <form method="post" class="bank-form hidden" id="editForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="form_action" value="edit">

                <?php
                  $current = $bankRow['bank_name'];
                  $inList  = in_array($current, $MALAYSIAN_BANKS, true);
                ?>
                <div class="field">
                    <label for="bank_name">Bank Name</label>
                    <select id="bank_name" name="bank_name" required>
                        <option value="" disabled>-- Select Bank --</option>
                        <?php foreach ($MALAYSIAN_BANKS as $bn): ?>
                            <option value="<?= h($bn) ?>" <?= $bn===$current?'selected':''; ?>><?= h($bn) ?></option>
                        <?php endforeach; ?>
                        <option value="_OTHER_" <?= $inList ? '' : 'selected'; ?>>Other (Manual Input Below)</option>
                    </select>
                    <div class="note">Pick a listed bank or choose Other to type custom name.</div>
                </div>
                <div class="field" id="custom-bank-row" style="<?= $inList?'display:none;':''; ?>">
                    <label for="custom_bank_name">Custom Bank Name</label>
                    <input type="text" id="custom_bank_name" name="custom_bank_name"
                           value="<?= !$inList ? h($current) : '' ?>"
                           maxlength="100" pattern="[A-Za-z0-9 .,&'()\-]{2,100}">
                </div>
                <div class="field">
                    <label for="account_holder">Account Holder</label>
                    <input type="text" id="account_holder" name="account_holder"
                           value="<?= h($bankRow['account_holder']) ?>"
                           maxlength="120" pattern="[A-Za-z0-9 .,&'()\-]{2,120}" required>
                </div>
                <div class="field">
                    <label for="account_no">Account Number (Digits 8–30)</label>
                    <input type="text" id="account_no" name="account_no"
                           value="<?= h($bankRow['account_no']) ?>"
                           maxlength="30" pattern="\d{8,30}" required>
                </div>
                <button type="submit">Save Changes</button>
                <button type="button" style="background:#6c757d;margin-left:10px;" onclick="toggleEdit()">Cancel</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>Add Company Bank</h2>
            <form method="post" class="bank-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="form_action" value="add">
                <div class="field">
                    <label for="bank_name">Bank Name</label>
                    <select id="bank_name" name="bank_name" required>
                        <option value="" disabled selected>-- Select Bank --</option>
                        <?php foreach ($MALAYSIAN_BANKS as $bn): ?>
                            <option value="<?= h($bn) ?>"><?= h($bn) ?></option>
                        <?php endforeach; ?>
                        <option value="_OTHER_">Other (Manual Input Below)</option>
                    </select>
                    <div class="note">Select or choose Other to type your own bank name.</div>
                </div>
                <div class="field" id="custom-bank-row" style="display:none;">
                    <label for="custom_bank_name">Custom Bank Name</label>
                    <input type="text" id="custom_bank_name" name="custom_bank_name"
                           maxlength="100" pattern="[A-Za-z0-9 .,&'()\-]{2,100}">
                </div>
                <div class="field">
                    <label for="account_holder">Account Holder</label>
                    <input type="text" id="account_holder" name="account_holder"
                           maxlength="120" pattern="[A-Za-z0-9 .,&'()\-]{2,120}" required>
                </div>
                <div class="field">
                    <label for="account_no">Account Number (Digits 8–30)</label>
                    <input type="text" id="account_no" name="account_no"
                           maxlength="30" pattern="\d{8,30}" required>
                </div>
                <button type="submit">Add Bank</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
(function(){
    function setupCustom(selectId){
        const sel=document.getElementById(selectId);
        if(!sel) return;
        const row=document.getElementById('custom-bank-row');
        const input=document.getElementById('custom_bank_name');
        function toggle(){
            if(sel.value==='_OTHER_'){
                row.style.display='block';
                if(input) input.required=true;
            } else {
                row.style.display='none';
                if(input) input.required=false;
            }
        }
        sel.addEventListener('change',toggle);
        toggle();
    }
    setupCustom('bank_name');

    // Replace _OTHER_ with custom value before submit
    document.addEventListener('submit', function(e){
        const f = e.target;
        if(!f.classList.contains('bank-form')) return;
        const sel = f.querySelector('#bank_name');
        if(!sel) return;
        if(sel.value==='_OTHER_'){
            const custom = f.querySelector('#custom_bank_name');
            if(custom && custom.value.trim()!==''){
                const hidden=document.createElement('input');
                hidden.type='hidden';
                hidden.name='bank_name';
                hidden.value=custom.value.trim();
                f.appendChild(hidden);
            }
        }
    });
})();

function toggleEdit(){
    const form=document.getElementById('editForm');
    const btn=document.getElementById('editBtn');
    if(!form||!btn) return;
    if(form.classList.contains('hidden')){
        form.classList.remove('hidden');
        btn.style.display='none';
        window.scrollTo({top:form.offsetTop-80,behavior:'smooth'});
    } else {
        form.classList.add('hidden');
        btn.style.display='inline-block';
    }
}
</script>
<?php include '../includes/footer.php'; ?>
</body>
</html>