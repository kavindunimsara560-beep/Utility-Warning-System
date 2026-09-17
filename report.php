<?php
// Include database connection
require_once 'config/db_connect.php';

$searchQuery = "";
$result = null;

// Check if a search was performed
if (isset($_GET['contact']) && !empty(trim($_GET['contact']))) {
    $searchQuery = trim($_GET['contact']);
    $stmt = $conn->prepare("SELECT * FROM complaints WHERE phone LIKE ? OR email LIKE ? ORDER BY id DESC");
    $searchTerm = "%" . $searchQuery . "%";
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    // Default view: Show recent complaints before any search is made
    $sql = "SELECT * FROM complaints ORDER BY id DESC LIMIT 10";
    $result = $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Portal - Complaints | Balangoda Utility Warnings</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-light">

    <!-- Top Navigation Header -->
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold" href="index.php">← Back to Outage Warnings</a>
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
            <p class="text-muted">Enter your contact number or email to filter issues, or view recent submissions below.</p>
            
            <form method="GET" action="report.php" class="row g-3">
                <div class="col-md-9">
                    <input type="text" name="contact" class="form-control form-control-lg" placeholder="Enter your contact number or email..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-lg w-100">Search</button>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <div class="card shadow-sm border-0 p-4">
            <h4 class="mb-3"><?php echo !empty($searchQuery) ? "Search Results for: " . htmlspecialchars($searchQuery) : "Recent Submitted Complaints"; ?></h4>
            
            <?php if ($result && $result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Utility Type</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['utility_type'] ?? 'General'); ?></span></td>
                                    <td><?php echo htmlspecialchars(substr($row['description'], 0, 50)); ?>...</td>
                                    <td>
                                        <?php 
                                            $status = $row['status'] ?? 'Pending';
                                            $badgeColor = 'warning';
                                            if ($status == 'Resolved') $badgeColor = 'success';
                                            if ($status == 'In Progress') $badgeColor = 'info';
                                        ?>
                                        <span class="badge bg-<?php echo $badgeColor; ?>"><?php echo $status; ?></span>
                                    </td>
                                    <td><?php echo $row['created_at'] ?? 'N/A'; ?></td>
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