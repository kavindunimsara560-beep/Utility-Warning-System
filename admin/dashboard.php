<?php
session_start();
// Redirect to login if the admin is not authenticated
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require '../config/db_connect.php';

// Handle form submission to add a new warning
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_warning'])) {
    $utility_type = $_POST['utility_type'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $color_code = $_POST['color_code'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $admin_id = $_SESSION['admin_id'];

    $stmt = $conn->prepare("INSERT INTO warnings (utility_type, title, description, color_code, start_time, end_time, posted_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssi", $utility_type, $title, $description, $color_code, $start_time, $end_time, $admin_id);
    
    if ($stmt->execute()) {
        $message = "Warning posted successfully!";
    } else {
        $message = "Error posting warning: " . $conn->error;
    }
    $stmt->close();
}

// Handle deletion of a warning
if (isset($_GET['delete'])) {
    $warning_id = intval($_GET['delete']);
    $conn->query("DELETE FROM warnings WHERE warning_id = $warning_id");
    header("Location: dashboard.php");
    exit();
}

// Handle deletion of a complaint
if (isset($_GET['delete_complaint'])) {
    $complaint_id = intval($_GET['delete_complaint']);
    $conn->query("DELETE FROM complaints WHERE complaint_id = $complaint_id");
    header("Location: dashboard.php");
    exit();
}

// Handle updating complaint status
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $new_status = $_POST['status'];
    
    $update_stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE complaint_id = ?");
    $update_stmt->bind_param("si", $new_status, $complaint_id);
    $update_stmt->execute();
    $update_stmt->close();
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Balangoda Warnings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-dark bg-dark shadow-sm mb-4">
        <div class="container">
            <span class="navbar-brand mb-0 h1">Admin Dashboard</span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </nav>

    <!-- Notification Banner -->
    <div class="container">
        <?php if(!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <strong>Notification:</strong> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="row g-4">
            <!-- Form to Post Warning -->
            <div class="col-lg-5">
                <div class="card shadow-sm border-0 p-4">
                    <h4 class="mb-3">Post New Warning</h4>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Utility Type</label>
                            <select name="utility_type" class="form-select" required>
                                <option value="Power">Power</option>
                                <option value="Water">Water</option>
                                <option value="Road">Road</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color Code (Hex)</label>
                            <input type="color" name="color_code" class="form-control form-control-color" value="#ffC107" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Time</label>
                            <input type="datetime-local" name="start_time" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End Time</label>
                            <input type="datetime-local" name="end_time" class="form-control" required>
                        </div>
                        <button type="submit" name="add_warning" class="btn btn-primary w-100">Publish Warning</button>
                    </form>
                </div>
            </div>

            <!-- List of Existing Warnings -->
            <div class="col-lg-7">
                <div class="card shadow-sm border-0 p-4">
                    <h4 class="mb-3">Active Warnings Management</h4>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $result = $conn->query("SELECT * FROM warnings ORDER BY start_time DESC");
                                if ($result && $result->num_rows > 0) {
                                    while($row = $result->fetch_assoc()) {
                                        echo "<tr>
                                            <td><strong>" . htmlspecialchars($row['utility_type']) . "</strong></td>
                                            <td>" . htmlspecialchars($row['title']) . "</td>
                                            <td>
                                                <a href='edit.php?id=" . $row['warning_id'] . "' class='btn btn-warning btn-sm me-1'>Edit</a>
                                                <a href='dashboard.php?delete=" . $row['warning_id'] . "' class='btn btn-danger btn-sm' onclick='return confirm(\"Are you sure you want to delete this warning?\");'>Delete</a>
                                            </td>
                                        </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='3' class='text-center text-muted'>No warnings found.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Resident Complaints Management Section -->
        <div class="row mt-4 mb-5">
            <div class="col-12">
                <div class="card shadow-sm border-0 p-4">
                    <h4 class="mb-3">Resident Complaints Management</h4>
                    <p class="text-muted small">Review incoming issues, update their progress status, and delete them once repairs are complete.</p>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Issue & Description</th>
                                    <th>Status Update</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $comp_result = $conn->query("SELECT * FROM complaints ORDER BY created_at DESC");
                                if ($comp_result && $comp_result->num_rows > 0) {
                                    while($comp = $comp_result->fetch_assoc()) {
                                        $current_status = isset($comp['status']) ? $comp['status'] : 'Pending Review';
                                        echo "<tr>
                                            <td><span class='badge bg-secondary'>" . htmlspecialchars($comp['utility_type']) . "</span></td>
                                            <td>
                                                <strong>" . htmlspecialchars($comp['title']) . "</strong><br>
                                                <small class='text-muted'>" . htmlspecialchars($comp['description']) . "</small><br>
                                                <small class='text-primary'>By: " . htmlspecialchars($comp['resident_name']) . " (" . htmlspecialchars($comp['contact_info']) . ")</small>
                                            </td>
                                            <td>
                                                <form method='POST' action='' class='d-flex gap-1 align-items-center'>
                                                    <input type='hidden' name='complaint_id' value='" . $comp['complaint_id'] . "'>
                                                    <select name='status' class='form-select form-select-sm' style='width: 170px;'>
                                                        <option value='Pending Review' " . ($current_status == 'Pending Review' ? 'selected' : '') . ">Pending Review</option>
                                                        <option value='Warning Published' " . ($current_status == 'Warning Published' ? 'selected' : '') . ">Warning Published</option>
                                                        <option value='Repair in Progress' " . ($current_status == 'Repair in Progress' ? 'selected' : '') . ">Repair in Progress</option>
                                                        <option value='Resolved' " . ($current_status == 'Resolved' ? 'selected' : '') . ">Resolved</option>
                                                    </select>
                                                    <button type='submit' name='update_status' class='btn btn-outline-primary btn-sm'>Save</button>
                                                </form>
                                            </td>
                                            <td>
                                                <a href='dashboard.php?delete_complaint=" . $comp['complaint_id'] . "' class='btn btn-danger btn-sm' onclick='return confirm(\"Are you sure you want to delete this complaint?\");'>Delete</a>
                                            </td>
                                        </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='4' class='text-center text-muted'>No resident complaints submitted yet.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>