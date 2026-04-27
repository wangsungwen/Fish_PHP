<?php
// 使用絕對路徑以確保無論是網頁端還是背景 Daemon 都能正確讀寫
$db_path = '/var/www/html/cloud_fish_system/data/fish_system.db';
$config_path = '/var/www/html/cloud_fish_system/data/config.json';

try {
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL;"); // SD 卡優化
    $pdo->exec("PRAGMA synchronous=NORMAL;");
    
    // 初始化資料表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sensor_logs (
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, 
            temp REAL, tds REAL, ph REAL, turbidity REAL, turbidity_ntu REAL, water_level REAL
        );
        CREATE TABLE IF NOT EXISTS system_events (
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, 
            event_type TEXT, message TEXT
        );
    ");
} catch (PDOException $e) {
    die(json_encode(['error' => '資料庫連線失敗: ' . $e->getMessage()]));
}

function load_config() {
    global $config_path;
    $default = [
        "uuid_pump_feeder" => "1afe34d3020b",
        "uuid_light" => "a220a61487a6",
        "rtsp_url" => "",
        "rtsp_url_2" => "",
        "telegram_bot_token" => "",
        "telegram_chat_id" => "",
        "mqtt_broker" => "mqttgo.io",
        "mqtt_port" => "8084",
        "topic_sensors" => "ttu_fish/sensors",
        "thingspeak_api_key" => ""
    ];
    if (file_exists($config_path)) {
        $file_config = json_decode(file_get_contents($config_path), true);
        if (is_array($file_config)) {
            return array_merge($default, $file_config);
        }
    }
    return $default;
}
?>
