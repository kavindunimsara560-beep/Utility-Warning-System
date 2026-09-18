<?php
session_start();
$role = $_GET['role'] ?? '';

if ($role === 'customer') {
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
    header("Location: index.php");
    exit();
} elseif ($role === 'admin') {
    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_username'],
        $_SESSION['admin_email'],
        $_SESSION['admin_fullname'],
        $_SESSION['admin_avatar']
    );
    if (($_SESSION['active_role'] ?? '') === 'admin') {
        unset($_SESSION['active_role']);
    }
    header("Location: index.php");
    exit();
} else {
    session_unset();
    session_destroy();
    header("Location: login.php?msg=logged_out");
    exit();
}
