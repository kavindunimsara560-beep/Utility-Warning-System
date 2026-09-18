<?php
// Include database connection
require_once 'config/db_connect.php';

$searchQuery = "";
$result = null;
$client_notifications = []; // Notifications for this client

// Check if a search was performed
if (isset($_GET['contact']) && !empty(trim($_GET['contact']))) {
    $searchQuery = trim($_GET['contact']);
    $stmt = $conn->prepare("SELECT * FROM complaints WHERE contact_info LIKE ? OR resident_name LIKE ? OR title LIKE ? ORDER BY complaint_id DESC");
    if ($stmt) {
        $searchTerm = "%" . $searchQuery . "%";
        $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    // Fetch unread client notifications matching this contact
    $notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE target_type='client' AND target_ref LIKE ? AND is_read=0 ORDER BY created_at DESC");
    if ($notif_stmt) {
        $notif_stmt->bind_param("s", $searchTerm);
        $notif_stmt->execute();
        $notif_res = $notif_stmt->get_result();
        while ($nrow = $notif_res->fetch_assoc()) {
            $client_notifications[] = $nrow;
        }
        $notif_stmt->close();
        // Mark them as read
        if (!empty($client_notifications)) {
            $mark_stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE target_type='client' AND target_ref LIKE ? AND is_read=0");
            if ($mark_stmt) { $mark_stmt->bind_param("s", $searchTerm); $mark_stmt->execute(); $mark_stmt->close(); }
        }
    }
} else {
    // Default view: Show recent complaints before any search is made
    $sql = "SELECT * FROM complaints ORDER BY complaint_id DESC LIMIT 20";
    $result = $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Portal - Track Complaints | Balangoda Utility Warnings</title>
    <link rel="manifest" href="/Web_base_project/manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-light">

    <!-- Top Navigation Header -->
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold text-decoration-none" href="index.php">← Back to Outage Warnings</a>
            <div class="d-flex align-items-center gap-2">
                <span class="text-white">Resident Portal - Complaints</span>
                <button id="pwa-install-btn" class="btn btn-outline-info btn-sm d-none" title="Install this app">
                    <i class="bi bi-download"></i> Install App
                </button>
            </div>
        </div>
    </nav>

    <div class="container my-5">

        <?php if (!empty($client_notifications)): ?>
        <!-- Client Status Notification Banner -->
        <div class="alert alert-info alert-dismissible shadow-sm mb-4" role="alert" style="border-left: 4px solid #0d6efd; border-radius: 10px;">
            <div class="d-flex align-items-start gap-3">
                <span style="font-size:1.4rem;">🔔</span>
                <div class="flex-grow-1">
                    <strong>Status Update<?php echo count($client_notifications) > 1 ? 's' : ''; ?> for Your Complaint<?php echo count($client_notifications) > 1 ? 's' : ''; ?></strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <?php foreach ($client_notifications as $cn): ?>
                            <li class="small"><?php echo htmlspecialchars($cn['message']); ?>
                                <span class="text-muted ms-1" style="font-size:0.75rem;">— <?php echo date('M d, g:i A', strtotime($cn['created_at'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="d-flex justify-content-center gap-3 mb-4">
            <a href="submit_complaint.php" class="btn btn-primary px-4 fw-bold">Submit New Complaint</a>
            <a href="report.php" class="btn btn-outline-primary px-4 fw-bold active">Track My Complaints</a>
        </div>

        <!-- Search Card -->
        <div class="card shadow-sm border-0 p-4 mb-4">
            <h3 class="mb-3">Track Your Complaint Status</h3>
            <p class="text-muted">Enter your phone number, email, name, or issue keyword to filter complaints, or view recent submissions below.</p>
            
            <form method="GET" action="report.php" class="row g-3">
                <div class="col-md-9">
                    <input type="text" name="contact" class="form-control form-control-lg" placeholder="Enter contact number, email, or name..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100">Search</button>
                    <?php if (!empty($searchQuery)): ?>
                        <a href="report.php" class="btn btn-outline-secondary btn-lg">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <div class="card shadow-sm border-0 p-4">
            <h4 class="mb-3"><?php echo !empty($searchQuery) ? "Search Results for: \"" . htmlspecialchars($searchQuery) . "\"" : "Recent Submitted Complaints"; ?></h4>
            
            <?php if ($result && $result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Ref #</th>
                                <th>Resident</th>
                                <th>Utility</th>
                                <th>Issue & Description</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="fw-bold text-primary">#<?php echo $row['complaint_id']; ?></span></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['resident_name']); ?></strong>
                                        <?php if (!empty($row['contact_info'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($row['contact_info']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $utype = $row['utility_type'] ?? 'General';
                                            $uBadge = 'bg-secondary';
                                            if ($utype == 'Power') $uBadge = 'bg-danger';
                                            elseif ($utype == 'Water') $uBadge = 'bg-primary';
                                            elseif ($utype == 'Road') $uBadge = 'bg-warning text-dark';
                                        ?>
                                        <span class="badge <?php echo $uBadge; ?>"><?php echo htmlspecialchars($utype); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['title']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($row['description'], 0, 100, "...")); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                            $status = $row['status'] ?? 'Pending Review';
                                            $badgeColor = 'bg-warning text-dark';
                                            if ($status == 'Resolved') $badgeColor = 'bg-success';
                                            elseif ($status == 'In Progress' || $status == 'Repair in Progress') $badgeColor = 'bg-info text-dark';
                                            elseif ($status == 'Warning Published') $badgeColor = 'bg-primary';
                                        ?>
                                        <span class="badge <?php echo $badgeColor; ?>"><?php echo htmlspecialchars($status); ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo !empty($row['created_at']) ? date('M d, Y h:i A', strtotime($row['created_at'])) : 'N/A'; ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0" role="alert">
                    No complaints found. Try searching with a different contact detail or submit a new complaint.
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/Web_base_project/sw.js').catch(() => {});
        }
        let _deferredPrompt;
        window.addEventListener('beforeinstallprompt', e => {
            e.preventDefault(); _deferredPrompt = e;
            document.getElementById('pwa-install-btn').classList.remove('d-none');
        });
        document.getElementById('pwa-install-btn')?.addEventListener('click', async () => {
            if (!_deferredPrompt) return;
            _deferredPrompt.prompt();
            const { outcome } = await _deferredPrompt.userChoice;
            _deferredPrompt = null;
            if (outcome === 'accepted') document.getElementById('pwa-install-btn').classList.add('d-none');
        });
    </script>
</body>
</html>