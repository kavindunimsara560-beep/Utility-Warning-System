<?php
// customer/verify.php — Email verification handler
session_start();
require_once '../config/db_connect.php';

$token   = trim($_GET['token'] ?? '');
$message = '';
$type    = 'danger';

if (empty($token)) {
    $message = "Invalid or missing verification token.";
} else {
    $stmt = $conn->prepare("SELECT customer_id, full_name, email_verified FROM customers WHERE verify_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $cust   = $result->fetch_assoc();
    $stmt->close();

    if (!$cust) {
        $message = "This verification link is invalid or has already been used.";
    } elseif ($cust['email_verified']) {
        // Already verified — just log them in
        $_SESSION['customer_id']   = $cust['customer_id'];
        $_SESSION['customer_name'] = $cust['full_name'];
        header("Location: dashboard.php?msg=already_verified");
        exit();
    } else {
        // Mark verified, clear token, start session
        $upd = $conn->prepare("UPDATE customers SET email_verified = 1, verify_token = NULL WHERE customer_id = ?");
        $upd->bind_param("i", $cust['customer_id']);
        $upd->execute();
        $upd->close();

        // Fetch full record for session
        $full = $conn->prepare("SELECT customer_id, full_name, phone, email FROM customers WHERE customer_id = ?");
        $full->bind_param("i", $cust['customer_id']);
        $full->execute();
        $row = $full->get_result()->fetch_assoc();
        $full->close();

        $_SESSION['customer_id']    = $row['customer_id'];
        $_SESSION['customer_name']  = $row['full_name'];
        $_SESSION['customer_email'] = $row['email'];
        $_SESSION['customer_phone'] = $row['phone'];

        header("Location: dashboard.php?msg=verified");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification — Balangoda Utility Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: linear-gradient(135deg,#0f172a,#1e293b); min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:'Segoe UI',sans-serif; }
        .card-box { background:rgba(30,41,59,.9); border:1px solid rgba(255,255,255,.1); border-radius:18px; padding:3rem 2rem; max-width:440px; width:100%; text-align:center; box-shadow:0 25px 50px rgba(0,0,0,.5); }
        h2 { color:#f8fafc; font-weight:700; }
        p  { color:#94a3b8; }
        .icon { font-size:3.5rem; margin-bottom:1rem; display:block; }
    </style>
</head>
<body>
<div class="card-box">
    <span class="icon">⚠️</span>
    <h2>Verification Failed</h2>
    <p><?= htmlspecialchars($message) ?></p>
    <a href="register.php" class="btn btn-outline-info mt-3">Register again</a>
    <a href="login.php" class="btn btn-outline-secondary mt-3 ms-2">Sign in</a>
</div>
</body>
</html>
