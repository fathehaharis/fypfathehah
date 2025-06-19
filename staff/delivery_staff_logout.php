<?php
session_start();
session_destroy();
header("Location: delivery_staff_login.php");
exit;