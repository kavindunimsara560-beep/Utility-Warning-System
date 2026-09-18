<?php
// api/admin_notif_count.php — JSON endpoint for admin notification badge
// Requires active admin session. Called every 30 seconds by the dashboard JS.

session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require '../config/db_connect.php';

// Handle mark-all-read via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE target_type = 'admin' AND is_read = 0");
        echo json_encode(['status' => 'ok', 'count' => 0]);
        exit();
    }
    if ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
        $nid = intval($_POST['notification_id']);
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND target_type = 'admin'");
        if ($stmt) { $stmt->bind_param("i", $nid); $stmt->execute(); $stmt->close(); }
        echo json_encode(['status' => 'ok']);
        exit();
    }
}

// GET: fetch unread count + latest 15 notifications
$count_res = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE target_type='admin' AND is_read=0");
$count = 0;
if ($count_res) {
    $count = (int)($count_res->fetch_assoc()['cnt'] ?? 0);
}

$notifs = [];
$res = $conn->query("SELECT notification_id, message, link, is_read, created_at FROM notifications WHERE target_type='admin' ORDER BY created_at DESC LIMIT 15");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $notifs[] = [
            'id'         => (int)$row['notification_id'],
            'message'    => $row['message'],
            'link'       => $row['link'],
            'is_read'    => (bool)$row['is_read'],
            'created_at' => $row['created_at'],
            'time_ago'   => _time_ago(strtotime($row['created_at'])),
        ];
    }
}

echo json_encode(['count' => $count, 'notifications' => $notifs]);

function _time_ago($ts) {
    $diff = time() - $ts;
    if ($diff < 60)           return 'just now';
    if ($diff < 3600)         return (int)($diff/60) . 'm ago';
    if ($diff < 86400)        return (int)($diff/3600) . 'h ago';
    return date('M d', $ts);
}
