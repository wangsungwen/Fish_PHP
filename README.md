🐟 雲端魚菜共生系統 (Cloud Fish System)
🚀 PHP 原生環境無痛部署手冊
本手冊將引導您在原生 Linux 環境（如 Raspberry Pi, Ubuntu, Debian）中，從零開始建置 Apache + PHP 8 + SQLite 的物聯網系統。包含前端控制台與背景 MQTT 常駐服務 (Daemon)。
📦 準備工作
準備一台已連上網路的 Linux 主機（如 Raspberry Pi）。
具備終端機 (Terminal) 或 SSH 連線能力。
具備 sudo 管理員權限。
第一步：安裝系統核心套件
請開啟終端機，依序複製並貼上以下指令。這會安裝網頁伺服器 (Apache)、PHP 執行環境及資料庫引擎。
# 1. 更新系統套件庫清單
sudo apt update

# 2. 安裝 Apache、PHP 8 以及 SQLite、cURL 等必要模組
sudo apt install -y apache2 php libapache2-mod-php php-cli php-sqlite3 php-curl php-mbstring git unzip curl

# 3. 安裝 Composer (PHP 的套件管理工具，用於後續安裝 MQTT 套件)
curl -sS [https://getcomposer.org/installer](https://getcomposer.org/installer) | php
sudo mv composer.phar /usr/local/bin/composer


第二步：建立專案目錄與權限設定
我們將系統建置在 Apache 的預設網頁目錄 /var/www/html 之下。
# 1. 建立專案與子目錄
sudo mkdir -p /var/www/html/cloud_fish_system/data
sudo mkdir -p /var/www/html/cloud_fish_system/php/html/api

# 2. 將目錄的所有權轉交給 Apache 的預設使用者 (www-data)
sudo chown -R www-data:www-data /var/www/html/cloud_fish_system

# 3. 設定讀寫權限，確保 PHP 能順利寫入 SQLite 資料庫
sudo chmod -R 775 /var/www/html/cloud_fish_system/data

# 4. 建立安全防護，禁止外部直接下載資料庫與設定檔
echo "Require all denied" | sudo tee /var/www/html/cloud_fish_system/data/.htaccess


第三步：放置程式碼檔案
請將轉換後的 PHP 與 HTML 程式碼放置到對應的路徑中。您的最終目錄結構應該長這樣：
/var/www/html/cloud_fish_system/
├── data/
│   ├── .htaccess             # (上一步已自動建立)
│   ├── config.json           # 系統設定檔
│   └── fish_system.db        # SQLite 資料庫 (系統執行後會自動產生)
└── php/
    ├── composer.json         # MQTT 套件定義檔
    ├── daemon.php            # 背景常駐程式 (MQTT 監聽 & ThingSpeak)
    └── html/
        ├── index.html        # 前端控制台介面
        └── api/
            ├── db.php        # 資料庫共用模組
            ├── settings.php  # 設定檔存取 API
            ├── logs.php      # 讀取日誌 API
            ├── log_event.php # 寫入日誌 API
            └── control.php   # 設備控制 API


💡 提示：您可以使用 nano 或 vim 指令建立檔案，或者透過 FTP/SFTP 將檔案上傳至該目錄，上傳後記得再次執行 sudo chown -R www-data:www-data /var/www/html/cloud_fish_system 確保權限正確。
第四步：安裝 MQTT 通訊套件
進入專案的 PHP 目錄，使用 Composer 安裝 MQTT 所需的函式庫。
# 切換到 PHP 程式目錄
cd /var/www/html/cloud_fish_system/php

# 建立 composer.json 檔案並寫入套件需求
cat << 'EOF' | sudo -u www-data tee composer.json
{
    "require": {
        "php-mqtt/client": "^2.1",
        "guzzlehttp/guzzle": "^7.8"
    }
}
EOF

# 以 www-data 身分執行安裝
sudo -u www-data composer install


第五步：設定背景常駐服務 (Daemon)
這一步最為關鍵，我們要讓 daemon.php 在背景自動運行，負責 24 小時監聽 MQTT 感測器數據並處理警報。
1. 建立 Systemd 服務檔：
sudo nano /etc/systemd/system/fish_daemon.service


2. 貼入以下配置內容後存檔 (Ctrl+O, Enter, Ctrl+X)：
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


3. 啟動並鎖定開機自動啟動：
# 重新載入系統服務配置
sudo systemctl daemon-reload

# 設定開機自動啟動
sudo systemctl enable fish_daemon

# 立即啟動服務
sudo systemctl start fish_daemon


第六步：系統驗證與監控
1. 測試網頁介面
打開與設備處於同一個區網內的電腦或手機，在瀏覽器輸入：
👉 http://<您的設備IP>/cloud_fish_system/php/html/
2. 檢查背景服務狀態
如果您想確認背景程式是否正常連接到 MQTT，或是否有報錯，請使用以下指令查看即時日誌：
sudo systemctl status fish_daemon

# 或查看連續滾動日誌 (按 Ctrl+C 退出)
sudo journalctl -u fish_daemon -f


🛠️ 常見問題排解 (Troubleshooting)
網頁顯示「資料庫連線失敗」或「Read-only file system」？
👉 通常是權限問題。請重新執行：sudo chown -R www-data:www-data /var/www/html/cloud_fish_system 與 sudo chmod -R 775 /var/www/html/cloud_fish_system/data。
修改了 daemon.php 的程式碼，但好像沒生效？
👉 每次修改背景程式後，都必須重新啟動服務才會套用：sudo systemctl restart fish_daemon。
網頁的 MQTT 一直顯示「待連線」？
👉 確保設定頁面中的 Broker URL (如 mqttgo.io) 是正確的，且前端網頁預設走 WebSocket 協議 (通常為 Port 8084 或 8083 加密端口)，這與背景程式走的 TCP Port (通常為 1883) 不同。
