<?php
require 'db.php';
require '../../vendor/autoload.php'; // 載入 Composer 的 MQTT 套件
use PhpMqtt\Client\MqttClient;

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

$device = $input['device'] ?? null;
$action = $input['action'] ?? null;

if (!$device || !$action) {
    http_response_code(400);
    echo json_encode(['error' => '缺少參數']);
    exit;
}

$config = load_config();
$broker = $config['mqtt_broker'] ?? 'mqttgo.io';
// 後端發送通常使用標準 TCP port (1883)
$port = 1883;

try {
    $mqtt = new MqttClient($broker, $port, 'php_web_' . uniqid());
    $mqtt->connect();
    $mqtt->publish("fish/control/$device", $action, 0);
    $mqtt->disconnect();
    echo json_encode(['status' => 'success', 'message' => "Sent $action to $device"]);
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'MQTT 連線失敗: ' . $e->getMessage()]);
}
?>