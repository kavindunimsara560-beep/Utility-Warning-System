<?php
session_start();
// Redirect to login if the admin is not authenticated
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require '../config/db_connect.php';

$message = "";
$message_type = "success";

// Handle GET messages (e.g. from redirects)
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'warning_deleted') {
        $message = "Warning notice has been removed.";
        $message_type = "success";
    } elseif ($_GET['msg'] === 'complaint_deleted') {
        $message = "Complaint record has been removed.";
        $message_type = "success";
    } elseif ($_GET['msg'] === 'status_updated') {
        $message = "Complaint status updated successfully.";
        $message_type = "success";
    } elseif ($_GET['msg'] === 'warning_updated') {
        $message = "Warning notice updated successfully.";
        $message_type = "success";
    }
}

// Handle form submission to add a new warning
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_warning'])) {
    $utility_type = trim($_POST['utility_type'] ?? 'Power');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color_code = trim($_POST['color_code'] ?? '#dc3545');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $admin_id = $_SESSION['admin_id'];

    if (empty($title) || empty($description) || empty($start_time) || empty($end_time)) {
        $message = "All warning fields are required.";
        $message_type = "danger";
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $message = "Warning End Time must be strictly after Start Time.";
        $message_type = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO warnings (utility_type, title, description, color_code, start_time, end_time, posted_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $utility_type, $title, $description, $color_code, $start_time, $end_time, $admin_id);
            if ($stmt->execute()) {
                $message = "Warning notice successfully published to residents!";
                $message_type = "success";
            } else {
                $message = "Error posting warning: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "Database error: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Handle deletion of a warning
if (isset($_GET['delete'])) {
    $warning_id = intval($_GET['delete']);
    $del_stmt = $conn->prepare("DELETE FROM warnings WHERE warning_id = ?");
    if ($del_stmt) {
        $del_stmt->bind_param("i", $warning_id);
        $del_stmt->execute();
        $del_stmt->close();
    }
    header("Location: dashboard.php?msg=warning_deleted");
    exit();
}

// Handle deletion of a complaint
if (isset($_GET['delete_complaint'])) {
    $complaint_id = intval($_GET['delete_complaint']);
    $del_stmt = $conn->prepare("DELETE FROM complaints WHERE complaint_id = ?");
    if ($del_stmt) {
        $del_stmt->bind_param("i", $complaint_id);
        $del_stmt->execute();
        $del_stmt->close();
    }
    header("Location: dashboard.php?msg=complaint_deleted");
    exit();
}

// Handle updating complaint status
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $new_status = trim($_POST['status'] ?? 'Pending Review');

    $update_stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE complaint_id = ?");
    if ($update_stmt) {
        $update_stmt->bind_param("si", $new_status, $complaint_id);
        $update_stmt->execute();
        $update_stmt->close();
    }
    header("Location: dashboard.php?msg=status_updated");
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
    <link rel="stylesheet" href="../css/style.css">
</head>

<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-dark bg-dark shadow-sm mb-4">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center gap-3">
                <span class="navbar-brand mb-0 h1 fw-bold">⚡ Balangoda Utility Admin</span>
                <a href="../index.php" target="_blank" class="btn btn-outline-info btn-sm">View Public Site ↗</a>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 small">Logged in as: <strong
                        class="text-white"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></strong></span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Notification Banner -->
    <div class="container-fluid px-4">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show shadow-sm" role="alert">
                <strong><?php echo $message_type === 'success' ? 'Success:' : 'Alert:'; ?></strong>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <div class="container-fluid px-4">
        <div class="row g-4">
            <!-- Form to Post Warning -->
            <div class="col-lg-5">
                <div class="card shadow-sm border-0 p-4">
                    <h4 class="mb-3 fw-bold">📢 Post New Outage Warning</h4>
                    <p class="text-muted small">Publish an emergency or scheduled outage notice for Balangoda residents.
                    </p>
                    <form method="POST" action="dashboard.php">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Utility Type</label>
                            <select name="utility_type" class="form-select" required>
                                <option value="Power">Power</option>
                                <option value="Water">Water</option>
                                <option value="Road">Road</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Warning Title</label>
                            <input type="text" name="title" class="form-control"
                                placeholder="e.g. Scheduled Main Grid Power Cut" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Detailed Description & Affected Locations</label>
                            <textarea name="description" class="form-control" rows="3"
                                placeholder="Areas affected, reason, safety instructions..." required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Alert Color Code</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" name="color_code" class="form-control form-control-color"
                                    value="#dc3545" required>
                                <span class="text-muted small">Default: Red (#dc3545 for urgent), Yellow (#ffc107 for
                                    advisory)</span>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Start Time</label>
                                <input type="datetime-local" name="start_time" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">End Time</label>
                                <input type="datetime-local" name="end_time" class="form-control" required>
                            </div>
                        </div>
                        <button type="submit" name="add_warning" class="btn btn-primary w-100 py-2 fw-semibold">Publish
                            Outage Warning</button>
                    </form>
                </div>
            </div>

            <!-- List of Existing Warnings -->
            <div class="col-lg-7">
                <div class="card shadow-sm border-0 p-4">
                    <h4 class="mb-3 fw-bold">Active & Scheduled Warnings</h4>
                    <p class="text-muted small">Manage ongoing and scheduled outage notifications.</p>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Type</th>
                                    <th>Title & Duration</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $result = $conn->query("SELECT * FROM warnings ORDER BY start_time DESC");
                                if ($result && $result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        $badgeClass = 'bg-secondary';
                                        if ($row['utility_type'] === 'Power')
                                            $badgeClass = 'bg-danger';
                                        elseif ($row['utility_type'] === 'Water')
                                            $badgeClass = 'bg-primary';
                                        elseif ($row['utility_type'] === 'Road')
                                            $badgeClass = 'bg-warning text-dark';

                                        $is_active = (strtotime($row['end_time']) > time() && strtotime($row['start_time']) <= time());
                                        $statusBadge = $is_active ? "<span class='badge bg-success ms-1'>Active</span>" : "";

                                        echo "<tr>
                                            <td><span class='badge $badgeClass'>" . htmlspecialchars($row['utility_type']) . "</span>$statusBadge</td>
                                            <td>
                                                <strong>" . htmlspecialchars($row['title']) . "</strong><br>
                                                <small class='text-muted d-block'>" . htmlspecialchars(mb_strimwidth($row['description'], 0, 70, "...")) . "</small>
                                                <small class='text-secondary'>
                                                    " . date('M d, g:i A', strtotime($row['start_time'])) . " - " . date('M d, g:i A', strtotime($row['end_time'])) . "
                                                </small>
                                            </td>
                                            <td>
                                                <div class='btn-group'>
                                                    <a href='edit.php?id=" . $row['warning_id'] . "' class='btn btn-warning btn-sm'>Edit</a>
                                                    <a href='dashboard.php?delete=" . $row['warning_id'] . "' class='btn btn-danger btn-sm' onclick='return confirm(\"Are you sure you want to delete this warning?\");'>Delete</a>
                                                </div>
                                            </td>
                                        </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='3' class='text-center text-muted py-3'>No warnings found. Post one using the form on the left.</td></tr>";
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
                    <h4 class="mb-2 fw-bold">📋 Resident Complaints Management</h4>
                    <p class="text-muted small mb-4">Review incoming issues from residents, update repair status, or
                        remove completed tickets.</p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Ref #</th>
                                    <th>Type</th>
                                    <th>Issue & Resident Details</th>
                                    <th>Status Update</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $comp_result = $conn->query("SELECT * FROM complaints ORDER BY complaint_id DESC");
                                if ($comp_result && $comp_result->num_rows > 0) {
                                    while ($comp = $comp_result->fetch_assoc()) {
                                        $current_status = isset($comp['status']) ? $comp['status'] : 'Pending Review';

                                        $uBadge = 'bg-secondary';
                                        if ($comp['utility_type'] === 'Power')
                                            $uBadge = 'bg-danger';
                                        elseif ($comp['utility_type'] === 'Water')
                                            $uBadge = 'bg-primary';
                                        elseif ($comp['utility_type'] === 'Road')
                                            $uBadge = 'bg-warning text-dark';

                                        echo "<tr>
                                            <td><span class='fw-bold text-secondary'>#" . $comp['complaint_id'] . "</span></td>
                                            <td><span class='badge " . $uBadge . "'>" . htmlspecialchars($comp['utility_type']) . "</span></td>
                                            <td>
                                                <h6 class='mb-1 fw-bold'>" . htmlspecialchars($comp['title']) . "</h6>
                                                <p class='text-muted small mb-1'>" . htmlspecialchars($comp['description']) . "</p>
                                                <small class='text-primary fw-medium'>Resident: " . htmlspecialchars($comp['resident_name']) . " | Contact: " . htmlspecialchars($comp['contact_info']) . "</small>
                                                <small class='text-muted d-block'>Submitted: " . date('M d, Y - g:i A', strtotime($comp['created_at'])) . "</small>
                                            </td>
                                            <td>
                                                <form method='POST' action='dashboard.php' class='d-flex gap-1 align-items-center'>
                                                    <input type='hidden' name='complaint_id' value='" . $comp['complaint_id'] . "'>
                                                    <select name='status' class='form-select form-select-sm' style='min-width: 160px;'>
                                                        <option value='Pending Review' " . ($current_status == 'Pending Review' ? 'selected' : '') . ">Pending Review</option>
                                                        <option value='Warning Published' " . ($current_status == 'Warning Published' ? 'selected' : '') . ">Warning Published</option>
                                                        <option value='Repair in Progress' " . ($current_status == 'Repair in Progress' ? 'selected' : '') . ">Repair in Progress</option>
                                                        <option value='Resolved' " . ($current_status == 'Resolved' ? 'selected' : '') . ">Resolved</option>
                                                    </select>
                                                    <button type='submit' name='update_status' class='btn btn-outline-primary btn-sm'>Save</button>
                                                </form>
                                            </td>
                                            <td>
                                                <a href='dashboard.php?delete_complaint=" . $comp['complaint_id'] . "' class='btn btn-outline-danger btn-sm' onclick='return confirm(\"Are you sure you want to delete this complaint record?\");'>Delete</a>
                                            </td>
                                        </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center text-muted py-3'>No resident complaints submitted yet.</td></tr>";
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