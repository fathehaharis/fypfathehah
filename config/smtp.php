<?php
// Load credentials from env in production. For local dev, fill these values.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'fathehaharis69@gmail.com');   // e.g., your Gmail/Workspace address
define('SMTP_PASS', 'cuel ijeu lzqv vsgv');         // 16-char Google App Password (no spaces)
define('SMTP_FROM_EMAIL', SMTP_USER);                  // With Gmail SMTP, use the same Gmail as From
define('SMTP_FROM_NAME', 'Timeless Car Rental');
// Optional branded reply-to (use your domain if configured)
define('SMTP_REPLY_TO_EMAIL', 'no-reply@timelesscarrental.com');
define('SMTP_REPLY_TO_NAME', 'Timeless Car Rental');

