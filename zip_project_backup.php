<?php
// === CONFIGURATION ===
// Set to the absolute or relative path of your project folder (no trailing slash)
$folder_to_backup = __DIR__; // Current folder; change if needed, e.g. __DIR__ . "/../htdocs/yourproject"
$backup_name = "project_backup_" . date("Ymd_His") . ".zip";

// === ZIP FUNCTION ===
function zipFolder($source, $destination) {
    if (!extension_loaded('zip') || !file_exists($source)) return false;
    $zip = new ZipArchive();
    if (!$zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE)) return false;

    $source = realpath($source);
    if (is_dir($source)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            $file = realpath($file);
            if (is_dir($file)) {
                $zip->addEmptyDir(str_replace($source . DIRECTORY_SEPARATOR, '', $file . DIRECTORY_SEPARATOR));
            } elseif (is_file($file)) {
                $zip->addFile($file, str_replace($source . DIRECTORY_SEPARATOR, '', $file));
            }
        }
    } elseif (is_file($source)) {
        $zip->addFile($source, basename($source));
    }
    return $zip->close();
}

// === RUN BACKUP AND DOWNLOAD ===
if (isset($_POST['zipbackup'])) {
    $zip_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $backup_name;
    if (zipFolder($folder_to_backup, $zip_path)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$backup_name.'"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        unlink($zip_path);
        exit;
    } else {
        echo "<script>alert('Failed to create ZIP. Make sure PHP Zip extension is enabled and folder path is correct.');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Project Files Backup (ZIP)</title>
    <style>
    body { font-family: Arial, sans-serif; background: #f7fafd; margin: 0; }
    .container { max-width: 500px; margin: 45px auto; background: #fff;
        box-shadow: 0 2px 12px #e0e7ef33; border-radius: 12px; padding: 32px 30px 24px 30px; }
    h2 { color: #2b5cbc; font-weight: 800; letter-spacing: 1px; }
    form { margin-bottom: 28px; }
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
    </style>
</head>
<body>
<div class="container">
    <h2>Project Files Backup (ZIP)</h2>
    <form method="post">
        <button name="zipbackup" type="submit">Download Project ZIP</button>
    </form>
    <div class="note">
        This will create and download a ZIP of your entire project folder, including images, PDFs, uploads, and PHP files.<br>
        <b>Note:</b> Large projects may take some time to archive and download.<br>
        <b>Security Tip:</b> Delete or move this file after use.
    </div>
</div>
</body>
</html>