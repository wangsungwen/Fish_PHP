# 🐟 雲端魚菜共生系統 - 無痛部署教學手冊 (原生 PHP 環境版)
本手冊將引導您在非 Docker 環境（如 Raspberry Pi、Ubuntu Server 或 Debian 系統）下，使用 **Apache + PHP 8 + SQLite + Systemd** 完整建置您的魚菜共生智慧管理平台。
## 🛠️ 第一階段：系統環境準備與套件安裝
請開啟設備的終端機（SSH 或實體終端），依序複製並執行以下指令。
### 1. 更新系統並安裝基礎套件
這會安裝 Apache 網頁伺服器、PHP 8、SQLite 資料庫模組以及 Composer。
```bash
sudo apt update
sudo apt install -y apache2 php libapache2-mod-php php-cli php-sqlite3 php-curl php-mbstring git unzip curl nano

# 安裝 Composer (PHP 套件管理工具)
curl -sS [https://getcomposer.org/installer](https://getcomposer.org/installer) | php
sudo mv composer.phar /usr/local/bin/composer

```
### 2. 建立系統資料夾結構
我們將系統統一放置在 Apache 預設的網頁根目錄 /var/www/html 下。
```bash
# 建立主目錄與子目錄
sudo mkdir -p /var/www/html/cloud_fish_system/data
sudo mkdir -p /var/www/html/cloud_fish_system/php/html/api

# 設定目錄權限給 www-data (Apache 預設使用者)
sudo chown -R www-data:www-data /var/www/html/cloud_fish_system
sudo chmod -R 775 /var/www/html/cloud_fish_system

```
### 3. 設定資料庫與設定檔的安全防護
防止外部人員直接從瀏覽器下載您的資料庫檔案。
```bash
echo "Require all denied" | sudo tee /var/www/html/cloud_fish_system/data/.htaccess

```
## 💻 第二階段：寫入系統程式碼
請使用 nano 編輯器（或其他您習慣的工具）依序建立以下檔案，並將內容貼上。
### 1. 共用資料庫設定 (db.php)
```bash
sudo -u www-data nano /var/www/html/cloud_fish_system/php/html/api/db.php

```
**貼入以下內容：**
```php
<?php
$db_path = '/var/www/html/cloud_fish_system/data/fish_system.db';
$config_path = '/var/www/html/cloud_fish_system/data/config.json';

try {
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL;"); 
    $pdo->exec("PRAGMA synchronous=NORMAL;");
    
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
        "uuid_pump_feeder" => "1afe34d3020b", "uuid_light" => "a220a61487a6",
        "rtsp_url" => "", "rtsp_url_2" => "", "telegram_bot_token" => "", "telegram_chat_id" => "",
        "mqtt_broker" => "mqttgo.io", "mqtt_port" => "8084", "topic_sensors" => "ttu_fish/sensors",
        "thingspeak_api_key" => ""
    ];
    if (file_exists($config_path)) {
        $file_config = json_decode(file_get_contents($config_path), true);
        if (is_array($file_config)) return array_merge($default, $file_config);
    }
    return $default;
}
?>

```
### 2. 設定檔 API (settings.php)
```bash
sudo -u www-data nano /var/www/html/cloud_fish_system/php/html/api/settings.php

```
**貼入以下內容：**
```php
<?php
require 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(load_config());
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $new_config = array_merge(load_config(), $input);
    file_put_contents($config_path, json_encode($new_config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success', 'message' => 'Settings saved.']);
}
?>

```
### 3. 日誌讀取 API (logs.php)
```bash
sudo -u www-data nano /var/www/html/cloud_fish_system/php/html/api/logs.php

```
**貼入以下內容：**
```php
<?php
require 'db.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT timestamp, event_type, message FROM system_events ORDER BY timestamp DESC LIMIT 20");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

```
### 4. 日誌寫入 API (log_event.php)
```bash
sudo -u www-data nano /var/www/html/cloud_fish_system/php/html/api/log_event.php

```
**貼入以下內容：**
```php
<?php
require 'db.php';
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
date_default_timezone_set('Asia/Taipei');

try {
    $stmt = $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, ?, ?)");
    $stmt->execute([date('Y-m-d H:i:s'), $input['event_type'] ?? 'INFO', $input['message'] ?? '']);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

```
### 5. 設備控制 API (control.php)
```bash
sudo -u www-data nano /var/www/html/cloud_fish_system/php/html/api/control.php

```
**貼入以下內容：**
```php
<?php
require 'db.php';
require '../../vendor/autoload.php';
use PhpMqtt\Client\MqttClient;

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$device = $input['device'] ?? null;
$action = $input['action'] ?? null;

if (!$device || !$action) { http_response_code(400); die(json_encode(['error' => '缺少參數'])); }

$config = load_config();
try {
    $mqtt = new MqttClient($config['mqtt_broker'] ?? 'mqttgo.io', 1883, 'php_web_' . uniqid());
    $mqtt->connect();
    $mqtt->publish("fish/control/$device", $action, 0);
    $mqtt->disconnect();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'MQTT Error: ' . $e->getMessage()]);
}
?>

```
### 6. 背景核心常駐程式 (daemon.php)
這支程式負責取代 Python 的背景執行緒。
```bash
sudo -u www-data nano /var/www/html/cloud_fish_system/php/daemon.php

```
**貼入以下內容：**
```php
<?php
require __DIR__ . '/vendor/autoload.php';
use PhpMqtt\Client\MqttClient;
use GuzzleHttp\Client;

$db_path = '/var/www/html/cloud_fish_system/data/fish_system.db';
$config_path = '/var/www/html/cloud_fish_system/data/config.json';
date_default_timezone_set('Asia/Taipei');

try { $pdo = new PDO("sqlite:" . $db_path); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
catch (PDOException $e) { die("DB Error: " . $e->getMessage() . "\n"); }

function getConfig() {
    global $config_path;
    return file_exists($config_path) ? json_decode(file_get_contents($config_path), true) : [];
}

function sendTelegram($msg, $config) {
    if (empty($config['telegram_bot_token']) || empty($config['telegram_chat_id'])) return;
    try {
        (new Client(['timeout' => 10]))->post("[https://api.telegram.org/bot](https://api.telegram.org/bot){$config['telegram_bot_token']}/sendMessage", 
        ['form_params' => ['chat_id' => $config['telegram_chat_id'], 'text' => $msg]]);
    } catch (Exception $e) { echo "[TG Error] " . $e->getMessage() . "\n"; }
}

$config = getConfig();
$broker = $config['mqtt_broker'] ?? 'mqttgo.io';
$topic_sensors = $config['topic_sensors'] ?? 'ttu_fish/sensors';
$last_ts = time(); $last_alert = ['ph' => 0]; $current_data = [];

try {
    $mqtt = new MqttClient($broker, 1883, 'php_daemon_' . uniqid());
    $mqtt->connect();
    echo "[System] MQTT Connected to $broker\n";

    $mqtt->subscribe($topic_sensors, function ($topic, $msg) use (&$pdo, &$current_data, &$config, &$last_alert) {
        $d = json_decode($msg, true);
        if ($d) {
            $current_data = ['temp' => $d['temp']??0, 'ph' => $d['ph']??0, 'tds' => $d['tds']??0, 'turbidity' => $d['turbidity']??0, 'ntu' => $d['ntu']??0, 'level' => $d['level']??0];
            $stmt = $pdo->prepare("INSERT INTO sensor_logs (timestamp, temp, tds, ph, turbidity, turbidity_ntu, water_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([date('Y-m-d H:i:s'), $current_data['temp'], $current_data['tds'], $current_data['ph'], $current_data['turbidity'], $current_data['ntu'], $current_data['level']]);
            
            if (($current_data['ph'] < 6.5 || $current_data['ph'] > 8.5) && (time() - $last_alert['ph'] > 600)) {
                sendTelegram("【pH 警報】pH：{$current_data['ph']}", $config);
                $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, 'ALARM', ?)")->execute([date('Y-m-d H:i:s'), "pH 異常 ({$current_data['ph']})"]);
                $last_alert['ph'] = time();
            }
        }
    }, 0);

    $mqtt->subscribe('ttu_fish/log', function ($topic, $msg) use (&$pdo) {
        $d = json_decode($msg, true);
        if ($d) $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, ?, ?)")->execute([date('Y-m-d H:i:s'), $d['event_type']??'INFO', $d['message']??'']);
    }, 0);

    $mqtt->subscribe('fish/control/#', function ($topic, $msg) use (&$pdo) {
        if (str_ends_with($topic, '/status')) return;
        $device = end(explode('/', $topic));
        $log_msg = "手動控制: $device -> $msg";
        $pdo->prepare("INSERT INTO system_events (timestamp, event_type, message) VALUES (?, 'MANUAL', ?)")->execute([date('Y-m-d H:i:s'), $log_msg]);
    }, 0);

    $mqtt->registerLoopEventHandler(function (MqttClient $c, float $e) use (&$last_ts, &$current_data, &$config) {
        if (time() - $last_ts >= 20) {
            if (!empty($config['thingspeak_api_key']) && strpos($config['thingspeak_api_key'], "YOUR") === false) {
                try {
                    (new Client(['timeout'=>5]))->get("[https://api.thingspeak.com/update](https://api.thingspeak.com/update)", ['query' => [
                        'api_key' => $config['thingspeak_api_key'], 'field1' => $current_data['temp']??0, 'field2' => $current_data['tds']??0, 'field3' => $current_data['ph']??0, 'field4' => $current_data['ntu']??0, 'field5' => $current_data['level']??0
                    ]]);
                } catch (Exception $ex) {}
            }
            $last_ts = time(); $config = getConfig();
        }
    });

    $mqtt->loop(true);
} catch (Exception $e) { echo "MQTT Error: " . $e->getMessage() . "\n"; }
?>

```
### 7. 前端儀表板 (index.html)
請複製您專案中修改好的 index.html，並儲存至 /var/www/html/cloud_fish_system/php/html/index.html。
*(請確保 JavaScript 區塊中的 fetch 路徑皆為 api/xxx.php，例如 fetch('api/settings.php'))*
### 8. 建立預設設定檔 (config.json)
```bash
sudo -u www-data nano /var/www/html/cloud_fish_system/data/config.json

```
**貼入以下內容：**
```json
{
    "uuid_pump_feeder": "1afe34d3020b",
    "uuid_light": "a220a61487a6",
    "mqtt_broker": "mqttgo.io",
    "mqtt_port": "8084",
    "topic_sensors": "ttu_fish/sensors",
    "rtsp_url": "[https://www.homeyes.com.tw:8443/_cam2/?controls=0&autoplay=1](https://www.homeyes.com.tw:8443/_cam2/?controls=0&autoplay=1)",
    "rtsp_url_2": "[https://www.homeyes.com.tw:8443/_cam3/?controls=0&autoplay=1](https://www.homeyes.com.tw:8443/_cam3/?controls=0&autoplay=1)",
    "telegram_bot_token": "8599794674:AAHEZhOtsUd4khTR1_HDtae9tn190Ip1XOU",
    "telegram_chat_id": "8357342837",
    "thingspeak_api_key": "X7R6GEYXQDDMWEZP"
}

```
## 📦 第三階段：安裝 PHP MQTT 套件
切換至 PHP 工作目錄，並使用 Composer 安裝 php-mqtt/client。
```bash
cd /var/www/html/cloud_fish_system/php

# 建立套件宣告檔
sudo -u www-data bash -c 'echo "{\"require\": {\"php-mqtt/client\": \"^2.1\",\"guzzlehttp/guzzle\": \"^7.8\"}}" > composer.json'

# 執行安裝
sudo -u www-data composer install

```
## 🚀 第四階段：設定 Systemd 背景服務並啟動系統
為了讓 daemon.php 可以在開機時自動啟動，並在崩潰時自動重啟，我們設定一個 Systemd 服務。
### 1. 建立服務設定檔
```bash
sudo nano /etc/systemd/system/fish_daemon.service

```
**貼入以下內容：**
```ini
[Unit]
Description=Cloud Fish System MQTT Daemon
After=network.target apache2.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html/cloud_fish_system/php
ExecStart=/usr/bin/php /var/www/html/cloud_fish_system/php/daemon.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target

```
### 2. 啟動並設定開機自啟
```bash
# 重新載入設定
sudo systemctl daemon-reload

# 設定開機自動啟動
sudo systemctl enable fish_daemon

# 立即啟動服務
sudo systemctl start fish_daemon

```
### 3. 檢查系統狀態
執行以下指令確認背景服務是否健康運作中：
```bash
sudo systemctl status fish_daemon

```
*💡 看到綠色的 active (running) 以及輸出 [System] MQTT Connected to mqttgo.io 即代表大功告成！*
## 🎉 完成部署！開始使用
請開啟同一區域網路內的電腦或手機瀏覽器，輸入您的設備 IP：
👉 **http://<您的伺服器IP>/cloud_fish_system/php/html/**
您應該能看到熟悉的魚菜共生平台畫面，且背景程式正默默處理著所有資料庫儲存與通訊任務。
### 🔧 常見維護指令
 * **查看背景程式即時日誌：** sudo journalctl -u fish_daemon -f
 * **重啟背景程式：** sudo systemctl restart fish_daemon
 * **查看網頁錯誤日誌：** sudo tail -f /var/log/apache2/error.log
