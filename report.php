<?php
// Include database connection
require_once 'config/db_connect.php';

$searchQuery = "";
$result = null;

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
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-light">

    <!-- Top Navigation Header -->
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold text-decoration-none" href="index.php">← Back to Outage Warnings</a>
            <span class="text-white">Resident Portal - Complaints</span>
        </div>
    </nav>

    <div class="container my-5">
        
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
</body>
</html>