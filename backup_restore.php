<?php
// --- CONFIGURATION: Edit as needed ---
$host = '127.0.0.1';
$db   = 'timelesscarrental';
$user = 'root';
$pass = '';
$port = 3306;

// Path to mysqldump and mysql for XAMPP
$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';

$backup_dir = __DIR__ . '/db_backups';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

function alert($msg) {
    echo "<script>alert(".json_encode($msg).");</script>";
}

// ========== BACKUP ==========
if (isset($_POST['backup'])) {
    $filename = 'db_backup_' . date("Ymd_His") . '.sql';
    $filepath = $backup_dir . '\\' . $filename;
    $cmd = "\"$GLOBALS[mysqldump]\" -h $GLOBALS[host] -P $GLOBALS[port] -u $GLOBALS[user]" .
           ($GLOBALS['pass'] !== '' ? " -p\"$GLOBALS[pass]\"" : "") .
           " $GLOBALS[db] > \"$filepath\"";

    $retval = null;
    system($cmd, $retval);

    if ($retval == 0 && file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        readfile($filepath);
        unlink($filepath);
        exit;
    } else {
        alert("Backup failed! Make sure mysqldump is enabled and credentials are correct.");
    }
}

// ========== RESTORE ==========
if (isset($_POST['restore']) && isset($_FILES['sql_file'])) {
    $file = $_FILES['sql_file'];
    if ($file['error'] == UPLOAD_ERR_OK && pathinfo($file['name'], PATHINFO_EXTENSION) === 'sql') {
        $uploaded = $backup_dir . '\\restore_' . date("Ymd_His") . '.sql';
        move_uploaded_file($file['tmp_name'], $uploaded);

        $cmd = "\"$mysql\" -h $host -P $port -u $user" .
               ($pass !== '' ? " -p\"$pass\"" : "") .
               " $db < \"$uploaded\"";

        $retval = null;
        system($cmd, $retval);

        unlink($uploaded);

        if ($retval == 0) {
            alert("Restore successful!");
        } else {
            alert("Restore failed! Make sure the SQL file is valid and credentials are correct.");
        }
    } else {
        alert("Please upload a valid .sql file.");
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Database Backup & Restore</title>
    <style>
    body { font-family: Arial, sans-serif; background: #f7fafd; margin: 0; }
    .container { max-width: 500px; margin: 45px auto; background: #fff;
        box-shadow: 0 2px 12px #e0e7ef33; border-radius: 12px; padding: 32px 30px 24px 30px; }
    h2 { color: #2b5cbc; font-weight: 800; letter-spacing: 1px; }
    form { margin-bottom: 28px; }
    label { color: #2b5cbc; font-weight: 600; margin-top: 14px; }
    input[type=file] { margin: 13px 0 0 0; }
    button {
        padding: 11px 26px;
        background: #2b5cbc;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 1.08em;
        cursor: pointer;
        transition: background 0.14s;
        margin-top: 12px;
    }
    button:hover { background: #243570; }
    .note { color: #888; font-size: 0.95em; margin-top: 10px; }
    .section { margin-bottom: 28px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Database Backup & Restore</h2>
    <div class="section">
        <form method="post">
            <button name="backup" type="submit">Download Backup (.sql)</button>
        </form>
        <div class="note">Click to download a backup of your MySQL database.</div>
    </div>
    <div class="section">
        <form method="post" enctype="multipart/form-data">
            <label for="sql_file">Restore from .sql file:</label><br>
            <input type="file" name="sql_file" id="sql_file" accept=".sql" required><br>
            <button name="restore" type="submit">Restore Database</button>
        </form>
        <div class="note">Upload a valid MySQL <b>.sql</b> file to restore the database.<br>
        <b>Warning:</b> This will overwrite current data!</div>
    </div>
</div>
</body>
</html>