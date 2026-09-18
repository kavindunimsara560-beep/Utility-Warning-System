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
    } elseif ($_GET['msg'] === 'account_created') {
        $message = "Welcome! Your admin account was successfully created and you are now signed in.";
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

    // Insert a client notification for the status change, keyed to contact_info
    $ref_res = $conn->prepare("SELECT resident_name, contact_info, title FROM complaints WHERE complaint_id = ?");
    if ($ref_res) {
        $ref_res->bind_param("i", $complaint_id);
        $ref_res->execute();
        $comp_row = $ref_res->get_result()->fetch_assoc();
        $ref_res->close();
        if ($comp_row) {
            $notif_msg = "Your complaint #" . $complaint_id . " (\"" . mb_strimwidth($comp_row['title'], 0, 60, '...') . "\") status has been updated to: " . $new_status . ".";
            $notif_link = null;
            $client_ref = $comp_row['contact_info']; // client identified by contact_info
            $cn_stmt = $conn->prepare("INSERT INTO notifications (target_type, target_ref, message, link) VALUES ('client', ?, ?, ?)");
            if ($cn_stmt) {
                $cn_stmt->bind_param("sss", $client_ref, $notif_msg, $notif_link);
                $cn_stmt->execute();
                $cn_stmt->close();
            }
        }
    }

    header("Location: dashboard.php?msg=status_updated");
    exit();
}

// ── Fetch admin notification data for badge ───────────────────────────────
$admin_notif_count = 0;
$admin_notifs = [];
$anc_res = $conn->query("SELECT COUNT(*) AS cnt FROM notifications WHERE target_type='admin' AND is_read=0");
if ($anc_res) {
    $admin_notif_count = (int) ($anc_res->fetch_assoc()['cnt'] ?? 0);
}
$an_res = $conn->query("SELECT notification_id, message, created_at, is_read FROM notifications WHERE target_type='admin' ORDER BY created_at DESC LIMIT 15");
if ($an_res) {
    while ($an = $an_res->fetch_assoc()) {
        $admin_notifs[] = $an;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Balangoda Warnings</title>
    <link rel="manifest" href="/Web_base_project/manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Notification bell styles */
        .notif-bell-btn {
            position: relative;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 8px;
            color: #fff;
            padding: 0.35rem 0.65rem;
            transition: background 0.18s;
            cursor: pointer;
        }

        .notif-bell-btn:hover {
            background: rgba(255, 255, 255, 0.16);
        }

        .notif-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #ef4444;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            border-radius: 50%;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 3px;
            border: 2px solid #1a1a2e;
            animation: badge-pop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes badge-pop {
            from {
                transform: scale(0.4);
            }

            to {
                transform: scale(1);
            }
        }

        .notif-dropdown {
            min-width: 340px;
            max-height: 420px;
            overflow-y: auto;
            border-radius: 12px;
            border: 1px solid rgba(0, 0, 0, 0.12);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
            padding: 0;
        }

        .notif-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.07);
            transition: background 0.15s;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item.unread {
            background: rgba(13, 110, 253, 0.06);
        }

        .notif-item:hover {
            background: rgba(13, 110, 253, 0.1);
        }

        .notif-msg {
            font-size: 0.84rem;
            color: #1e293b;
            line-height: 1.4;
        }

        .notif-time {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 2px;
        }
    </style>
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
                <!-- Notification Bell -->
                <div class="dropdown" id="notif-dropdown-container">
                    <button class="notif-bell-btn" id="notifBellBtn" data-bs-toggle="dropdown" aria-expanded="false"
                        title="Notifications">
                        <i class="bi bi-bell-fill fs-5"></i>
                        <?php if ($admin_notif_count > 0): ?>
                            <span class="notif-badge" id="notif-badge"><?php echo $admin_notif_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu notif-dropdown" aria-labelledby="notifBellBtn">
                        <!-- Dropdown header -->
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <span class="fw-bold small">Notifications</span>
                            <button id="mark-all-read-btn"
                                class="btn btn-link btn-sm p-0 text-primary text-decoration-none small">Mark all
                                read</button>
                        </div>
                        <div id="notif-list">
                            <?php if (empty($admin_notifs)): ?>
                                <div class="text-center text-muted py-4 small"><i
                                        class="bi bi-bell-slash fs-4 d-block mb-2"></i>No notifications yet.</div>
                            <?php else: ?>
                                <?php foreach ($admin_notifs as $an): ?>
                                    <div class="notif-item <?php echo $an['is_read'] ? '' : 'unread'; ?>">
                                        <div class="notif-msg"><?php echo htmlspecialchars($an['message']); ?></div>
                                        <div class="notif-time"><?php
                                        $diff = time() - strtotime($an['created_at']);
                                        if ($diff < 60)
                                            echo 'just now';
                                        elseif ($diff < 3600)
                                            echo (int) ($diff / 60) . 'm ago';
                                        elseif ($diff < 86400)
                                            echo (int) ($diff / 3600) . 'h ago';
                                        else
                                            echo date('M d, Y', strtotime($an['created_at']));
                                        ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
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
    <script>
        // ── PWA: Service Worker registration ──────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/Web_base_project/sw.js').catch(() => { });
        }

        // ── Notification badge auto-poll (every 30s) ──────────────────
        const NOTIF_API = '../api/admin_notif_count.php';

        async function fetchNotifCount() {
            try {
                const res = await fetch(NOTIF_API, { credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                updateBadge(data.count);
            } catch (e) { }
        }

        function updateBadge(count) {
            const container = document.querySelector('#notif-dropdown-container .notif-bell-btn');
            if (!container) return;
            let badge = document.getElementById('notif-badge');
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.id = 'notif-badge';
                    badge.className = 'notif-badge';
                    container.appendChild(badge);
                }
                badge.textContent = count;
            } else {
                if (badge) badge.remove();
            }
        }

        // Poll every 30 seconds
        setInterval(fetchNotifCount, 30000);

        // ── Mark all read ─────────────────────────────────────────────
        document.getElementById('mark-all-read-btn')?.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                const fd = new FormData();
                fd.append('action', 'mark_all_read');
                await fetch(NOTIF_API, { method: 'POST', body: fd, credentials: 'same-origin' });
                // Visually clear badge and unread highlights
                updateBadge(0);
                document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            } catch (e) { }
        });
    </script>
</body>

</html>