<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require '../config/db_connect.php';

$message = "";
$warning_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch existing warning data safely
$stmt = $conn->prepare("SELECT * FROM warnings WHERE warning_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $warning_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        header("Location: dashboard.php");
        exit();
    }
    $warning = $result->fetch_assoc();
    $stmt->close();
} else {
    header("Location: dashboard.php");
    exit();
}

// Handle update form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_warning'])) {
    $utility_type = trim($_POST['utility_type'] ?? 'Power');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color_code = trim($_POST['color_code'] ?? '#dc3545');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    if (empty($title) || empty($description) || empty($start_time) || empty($end_time)) {
        $message = "All fields are required.";
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $message = "End Time must be after Start Time.";
    } else {
        $upd_stmt = $conn->prepare("UPDATE warnings SET utility_type=?, title=?, description=?, color_code=?, start_time=?, end_time=? WHERE warning_id=?");
        if ($upd_stmt) {
            $upd_stmt->bind_param("ssssssi", $utility_type, $title, $description, $color_code, $start_time, $end_time, $warning_id);
            if ($upd_stmt->execute()) {
                $upd_stmt->close();
                header("Location: dashboard.php?msg=warning_updated");
                exit();
            } else {
                $message = "Error updating warning: " . $upd_stmt->error;
            }
            $upd_stmt->close();
        } else {
            $message = "Database error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warning - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark shadow-sm mb-4">
        <div class="container-fluid px-4">
            <span class="navbar-brand mb-0 h1 fw-bold">Edit Outage Warning Notice</span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">← Back to Dashboard</a>
        </div>
    </nav>

    <div class="container my-4" style="max-width: 650px;">
        <div class="card shadow-sm border-0 p-4">
            <h4 class="mb-3 fw-bold">Update Warning #<?php echo $warning_id; ?></h4>
            <?php if(!empty($message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="edit.php?id=<?php echo $warning_id; ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Utility Type</label>
                    <select name="utility_type" class="form-select" required>
                        <option value="Power" <?php if($warning['utility_type']=='Power') echo 'selected'; ?>>Power</option>
                        <option value="Water" <?php if($warning['utility_type']=='Water') echo 'selected'; ?>>Water</option>
                        <option value="Road" <?php if($warning['utility_type']=='Road') echo 'selected'; ?>>Road</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Title</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($warning['title']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea name="description" class="form-control" rows="3" required><?php echo htmlspecialchars($warning['description']); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Color Code (Hex)</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" name="color_code" class="form-control form-control-color" value="<?php echo htmlspecialchars($warning['color_code']); ?>" required>
                        <span class="text-muted small">Choose card accent color</span>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Start Time</label>
                        <input type="datetime-local" name="start_time" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($warning['start_time'])); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">End Time</label>
                        <input type="datetime-local" name="end_time" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($warning['end_time'])); ?>" required>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <button type="submit" name="update_warning" class="btn btn-primary w-100 fw-bold">Update Warning Notice</button>
                    <a href="dashboard.php" class="btn btn-outline-secondary px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>