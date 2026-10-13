<?php
session_start();
// Direct routing — eliminate intermediate splash step
if (!empty($_SESSION['customer_id'])) {
    header("Location: customer/dashboard.php");
} else {
    $qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
    header("Location: customer/login_page.php" . $qs);
}
exit();
