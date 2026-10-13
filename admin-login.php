<?php
session_start();
// Direct routing — eliminate intermediate splash step
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
if (!empty($_SESSION['admin_id'])) {
    header("Location: admin/dashboard.php");
} else {
    header("Location: admin/login_page.php" . $qs);
}
exit();
