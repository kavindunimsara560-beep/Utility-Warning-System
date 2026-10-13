<?php
session_start();
unset(
    $_SESSION['customer_id'],
    $_SESSION['customer_name'],
    $_SESSION['customer_email'],
    $_SESSION['customer_phone'],
    $_SESSION['customer_avatar']
);
if (($_SESSION['active_role'] ?? '') === 'customer') {
    unset($_SESSION['active_role']);
}
header("Location: login_page.php?msg=logged_out");
exit();
