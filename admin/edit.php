<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require '../config/db_connect.php';

$message = "";
$warning_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch existing warning data
$result = $conn->query("SELECT * FROM warnings WHERE warning_id = $warning_id");
if ($result->num_rows == 0) {
    header("Location: dashboard.php");
    exit();
}
$warning = $result->fetch_assoc();

// Handle update form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_warning'])) {
    $utility_type = $_POST['utility_type'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $color_code = $_POST['color_code'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];

    $stmt = $conn->prepare("UPDATE warnings SET utility_type=?, title=?, description=?, color_code=?, start_time=?, end_time=? WHERE warning_id=?");
    $stmt->bind_param("ssssssi", $utility_type, $title, $description, $color_code, $start_time, $end_time, $warning_id);
    
    if ($stmt->execute()) {
        header("Location: dashboard.php");
        exit();
    } else {
        $message = "Error updating warning: " . $conn->error;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warning - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark shadow-sm mb-4">
        <div class="container">
            <span class="navbar-brand mb-0 h1">Edit Warning Notice</span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Back to Dashboard</a>
        </div>
    </nav>

    <div class="container" style="max-width: 600px;">
        <div class="card shadow-sm border-0 p-4">
            <?php if($message): ?>
                <div class="alert alert-danger"><?php echo $message; ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Utility Type</label>
                    <select name="utility_type" class="form-select" required>
                        <option value="Power" <?php if($warning['utility_type']=='Power') echo 'selected'; ?>>Power</option>
                        <option value="Water" <?php if($warning['utility_type']=='Water') echo 'selected'; ?>>Water</option>
                        <option value="Road" <?php if($warning['utility_type']=='Road') echo 'selected'; ?>>Road</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($warning['title']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" required><?php echo htmlspecialchars($warning['description']); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Color Code (Hex)</label>
                    <input type="color" name="color_code" class="form-control form-control-color" value="<?php echo htmlspecialchars($warning['color_code']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Start Time</label>
                    <input type="datetime-local" name="start_time" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($warning['start_time'])); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">End Time</label>
                    <input type="datetime-local" name="end_time" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($warning['end_time'])); ?>" required>
                </div>
                <button type="submit" name="update_warning" class="btn btn-primary w-100">Update Warning</button>
            </form>
        </div>
    </div>
</body>
</html>