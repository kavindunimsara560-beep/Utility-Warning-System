<?php
// api/check_new_warnings.php — Public JSON endpoint for real-time & background outage alerts
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db_connect.php';

$since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

// Fetch maximum warning_id currently in DB
$max_res = $conn->query("SELECT MAX(warning_id) AS max_id FROM warnings");
$max_id = 0;
if ($max_res) {
    $row = $max_res->fetch_assoc();
    $max_id = (int)($row['max_id'] ?? 0);
}

$new_warnings = [];

if ($since_id > 0) {
    // Fetch any warnings created after since_id
    $stmt = $conn->prepare("SELECT warning_id, utility_type, title, description, color_code, start_time, end_time, created_at FROM warnings WHERE warning_id > ? ORDER BY warning_id ASC");
    if ($stmt) {
        $stmt->bind_param("i", $since_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($w = $result->fetch_assoc()) {
            $new_warnings[] = [
                'warning_id'   => (int)$w['warning_id'],
                'utility_type' => $w['utility_type'],
                'title'        => $w['title'],
                'description'  => $w['description'],
                'color_code'   => $w['color_code'] ?? '#dc3545',
                'start_time'   => $w['start_time'],
                'end_time'     => $w['end_time'],
                'created_at'   => $w['created_at'] ?? null,
            ];
        }
        $stmt->close();
    }
} else {
    // Return the latest warning for initial baseline
    $latest_res = $conn->query("SELECT warning_id, utility_type, title, description, color_code, start_time, end_time, created_at FROM warnings ORDER BY warning_id DESC LIMIT 1");
    if ($latest_res && $latest_res->num_rows > 0) {
        $w = $latest_res->fetch_assoc();
        $new_warnings[] = [
            'warning_id'   => (int)$w['warning_id'],
            'utility_type' => $w['utility_type'],
            'title'        => $w['title'],
            'description'  => $w['description'],
            'color_code'   => $w['color_code'] ?? '#dc3545',
            'start_time'   => $w['start_time'],
            'end_time'     => $w['end_time'],
            'created_at'   => $w['created_at'] ?? null,
        ];
    }
}

echo json_encode([
    'status'       => 'ok',
    'latest_id'    => $max_id,
    'has_new'      => !empty($new_warnings) && ($since_id > 0),
    'new_warnings' => $new_warnings,
    'server_time'  => date('Y-m-d H:i:s'),
]);
exit();
