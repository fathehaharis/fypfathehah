<?php
// === CONFIGURATION ===
// Set to the directory where you want to restore files (default: current directory)
$restore_to = __DIR__;

// === RESTORE FUNCTION ===
if (isset($_POST['restore']) && isset($_FILES['zip_file'])) {
    $file = $_FILES['zip_file'];
    if ($file['error'] === UPLOAD_ERR_OK && pathinfo($file['name'], PATHINFO_EXTENSION) === 'zip') {
        $tmp_zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('restore_', true) . '.zip';
        move_uploaded_file($file['tmp_name'], $tmp_zip);

        $zip = new ZipArchive();
        if ($zip->open($tmp_zip) === TRUE) {
            $zip->extractTo($restore_to);
            $zip->close();
            unlink($tmp_zip);
            echo "<script>alert('Files restored successfully!');</script>";
        } else {
            unlink($tmp_zip);
            echo "<script>alert('Failed to open ZIP file.');</script>";
        }
    } else {
        echo "<script>alert('Please upload a valid .zip file.');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Restore Project Files (ZIP)</title>
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
    .warning { color: #c00; font-size: 0.97em; margin-top: 10px; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <h2>Restore Project Files (ZIP)</h2>
    <form method="post" enctype="multipart/form-data">
        <label for="zip_file">Select ZIP file to restore:</label><br>
        <input type="file" name="zip_file" id="zip_file" accept=".zip" required><br>
        <button name="restore" type="submit">Restore Files</button>
    </form>
    <div class="note">
        Upload a ZIP file previously backed up (contains your PHP, images, uploads, etc.).<br>
        The contents will be extracted to this folder.<br>
    </div>
    <div class="warning">
        Warning: This will <strong>overwrite existing files</strong> with the same name!<br>
        <b>Only use on a trusted, local, or protected environment.</b>
    </div>
</div>
</body>
</html>