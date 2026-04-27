<?php
require 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$event_type = $input['event_type'] ?? 'INFO';
$message = $input['message'] ?? '';

date_default_timezone_set('Asia/Taipei');
$timestamp = date('Y-m-d H:i:s');

try {
    $stmt = $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, ?, ?)");
    $stmt->execute([$timestamp, $event_type, $message]);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>