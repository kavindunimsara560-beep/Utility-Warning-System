<?php
session_start();
// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

require '../config/db_connect.php'; 

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT admin_id, username, password_hash FROM admins WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if (password_verify($password, $row['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $row['admin_id'];
                    $_SESSION['admin_username'] = $row['username'];
                    header("Location: dashboard.php"); 
                    exit();
                } else {
                    $error = "Incorrect password.";
                }
            } else {
                $error = "Admin username not found.";
            }
            $stmt->close();
        } else {
            $error = "Database query failed.";
        }
    } else {
        $error = "Please enter both username and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balangoda Admin Login</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="bg-light d-flex align-items-center py-5" style="min-height: 100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5 col-lg-4">
                <div class="card shadow border-0 p-4 rounded-4">
                    <div class="text-center mb-4">
                        <span class="fs-1">🔒</span>
                        <h3 class="fw-bold mt-2">Official Login</h3>
                        <p class="text-muted small">Balangoda Utility Warning Administration</p>
                    </div>

                    <?php if(!empty($error)): ?>
                        <div class="alert alert-danger py-2 text-center fs-6 shadow-sm"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="login.php">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="Enter username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">Sign In</button>
                    </form>

                    <div class="text-center mt-4 border-top pt-3">
                        <a href="../index.php" class="text-decoration-none small text-secondary">← Back to Public Portal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>