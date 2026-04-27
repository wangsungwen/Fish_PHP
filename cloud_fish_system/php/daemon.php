<?php
require __DIR__ . '/vendor/autoload.php';
use PhpMqtt\Client\MqttClient;
use GuzzleHttp\Client;

$db_path = '/var/www/html/cloud_fish_system/data/fish_system.db';
$config_path = '/var/www/html/cloud_fish_system/data/config.json';
date_default_timezone_set('Asia/Taipei');

try {
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage() . "\n");
}

function getConfig()
{
    global $config_path;
    if (file_exists($config_path)) {
        $file_config = json_decode(file_get_contents($config_path), true);
        if (is_array($file_config))
            return $file_config;
    }
    return [];
}

function sendTelegram($msg, $config)
{
    $token = $config['telegram_bot_token'] ?? '';
    $chat_id = $config['telegram_chat_id'] ?? '';
    if (!$token || !$chat_id)
        return;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $client = new Client(['timeout' => 10]);
    try {
        $client->post($url, ['form_params' => ['chat_id' => $chat_id, 'text' => $msg]]);
    } catch (Exception $e) {
        echo "[Telegram Error] " . $e->getMessage() . "\n";
    }
}

$config = getConfig();
$broker = $config['mqtt_broker'] ?? 'mqttgo.io';
$port = 1883; // 背景程式使用 TCP MQTT Port
$topic_sensors = $config['topic_sensors'] ?? 'ttu_fish/sensors';

$last_thingspeak_time = time();
$last_alert_time = ['ph' => 0];
$ALERT_COOLDOWN = 600;

$current_data = ['temp' => 0, 'tds' => 0, 'ph' => 0, 'turbidity' => 0, 'turbidity_ntu' => 0, 'water_level' => 0];

$mqtt = new MqttClient($broker, $port, 'php_daemon_' . uniqid());

try {
    $mqtt->connect();
    echo "[System] MQTT Connected to $broker:$port\n";

    // 1. 監聽感測器數據
    $mqtt->subscribe($topic_sensors, function ($topic, $message) use (&$pdo, &$current_data, &$config, &$last_alert_time, $ALERT_COOLDOWN) {
        $data = json_decode($message, true);
        if ($data) {
            $current_data['temp'] = floatval($data['temp'] ?? 0);
            $current_data['ph'] = floatval($data['ph'] ?? 0);
            $current_data['tds'] = floatval($data['tds'] ?? 0);
            $current_data['turbidity'] = floatval($data['turbidity'] ?? 0);
            $current_data['turbidity_ntu'] = intval($data['ntu'] ?? 0);
            $current_data['water_level'] = intval($data['level'] ?? 0);

            $timestamp = date('Y-m-d H:i:s');

            // 寫入資料庫
            $stmt = $pdo->prepare("INSERT INTO sensor_logs (timestamp, temp, tds, ph, turbidity, turbidity_ntu, water_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$timestamp, $current_data['temp'], $current_data['tds'], $current_data['ph'], $current_data['turbidity'], $current_data['turbidity_ntu'], $current_data['water_level']]);

            // pH 警報邏輯
            $current_time = time();
            if (($current_data['ph'] < 6.5 || $current_data['ph'] > 8.5) && ($current_time - $last_alert_time['ph'] > $ALERT_COOLDOWN)) {
                sendTelegram("【pH 警報】pH：{$current_data['ph']}", $config);
                $stmt_log = $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, ?, ?)");
                $stmt_log->execute([$timestamp, 'ALARM', "pH 異常 ({$current_data['ph']})"]);
                $last_alert_time['ph'] = $current_time;
            }
        }
    }, 0);

    // 2. 監聽 ESP32 系統日誌
    $mqtt->subscribe('ttu_fish/log', function ($topic, $message) use (&$pdo) {
        $data = json_decode($message, true);
        if ($data) {
            $stmt = $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, ?, ?)");
            $stmt->execute([date('Y-m-d H:i:s'), $data['event_type'] ?? 'INFO', $data['message'] ?? '']);
        }
    }, 0);

    // 3. 監聽手動控制日誌
    $mqtt->subscribe('fish/control/#', function ($topic, $message) use (&$pdo) {
        if (str_ends_with($topic, '/status'))
            return;

        $parts = explode('/', $topic);
        $device = end($parts);
        $action = $message;

        $device_map = [
            'pump' => '循環過濾馬達',
            'heater' => '水族燈',
            'light' => '水族燈',
            'feeder' => '自動餵食器 1',
            'feeder2' => '自動餵食器 2',
            'motor1' => '清洗平台'
        ];
        $device_name = $device_map[$device] ?? $device;
        $action_map = ['ON' => '開啟', 'OFF' => '關閉', '0' => '停止', '1' => '前進', '2' => '後退', '3' => '一鍵清洗 (前進)', '4' => '後退'];

        $action_name = (strpos($device, 'feeder') !== false && $action == 'ON') ? '執行餵食' : ($action_map[$action] ?? $action);
        $log_msg = "手動控制: $device_name -> $action_name";

        $stmt = $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, ?, ?)");
        $stmt->execute([date('Y-m-d H:i:s'), 'MANUAL', $log_msg]);
    }, 0);

    // 4. 定時上傳 ThingSpeak
    $mqtt->registerLoopEventHandler(function (MqttClient $client, float $elapsedTime) use (&$last_thingspeak_time, &$current_data, &$config) {
        if (time() - $last_thingspeak_time >= 20) {
            $api_key = $config['thingspeak_api_key'] ?? '';
            if (!empty($api_key) && strlen($api_key) > 10 && strpos($api_key, "YOUR_WRITE_API_KEY") === false) {
                $http = new Client(['timeout' => 5]);
                try {
                    $http->get("https://api.thingspeak.com/update", [
                        'query' => [
                            'api_key' => $api_key,
                            'field1' => $current_data['temp'],
                            'field2' => $current_data['tds'],
                            'field3' => $current_data['ph'],
                            'field4' => $current_data['turbidity_ntu'],
                            'field5' => $current_data['water_level']
                        ]
                    ]);
                } catch (Exception $e) {
                }
            }
            $last_thingspeak_time = time();
            $config = getConfig(); // 動態刷新設定
        }
    });

    $mqtt->loop(true); // 進入常駐循環

} catch (Exception $e) {
    echo "MQTT Connection failed: " . $e->getMessage() . "\n";
}
?>