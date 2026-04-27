#!/bin/bash

# Ensure script is run as root
if [ "$EUID" -ne 0 ]; then
  echo "請使用 root 權限執行此指令 (sudo ./setup.sh)"
  exit
fi

echo "開始建立 Linux (Raspberry Pi/Ubuntu) 執行環境..."

# 1. 安裝系統相依套件庫 (Apache, PHP, SQLite, Composer)
echo "更新系統套件並安裝背景相依環境..."
apt-get update
# 安裝 Apache2 和 PHP 與 SQLite 模組
apt-get install -y apache2 php php-cli php-sqlite3 php-curl curl unzip sqlite3 libsqlite3-dev

# 安裝 Composer (如果尚未安裝)
if ! command -v composer &> /dev/null; then
    echo "安裝 Composer..."
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    php -r "unlink('composer-setup.php');"
fi

# 2. 建立資料夾與目錄
TARGET_DIR="/var/www/html/cloud_fish_system"

# 若發現目標目錄尚未建立，嘗試將目前位置的檔案搬移/複製過去
if [ ! -d "$TARGET_DIR" ]; then
    echo "建立 $TARGET_DIR 並將目前的檔案複製過去..."
    mkdir -p "$TARGET_DIR"
    cp -r ./* "$TARGET_DIR"/
fi

# 確保資料夾存在
mkdir -p "$TARGET_DIR/data"
mkdir -p "$TARGET_DIR/php"

# 設定 www-data (Apache 預設使用者) 和 root 對 data 目錄皆有讀寫權限
chown -R www-data:www-data "$TARGET_DIR"
chmod -R 775 "$TARGET_DIR"
# 針對 SQLite DB 放寬權限確保 Daemon 與 Web 皆可操作
chmod -R 777 "$TARGET_DIR/data"

# 3. 安裝 PHP MQTT 相依套件
if [ -d "$TARGET_DIR/php" ]; then
    echo "進入 $TARGET_DIR/php 安裝 Composer MQTT 相依套件..."
    cd "$TARGET_DIR/php" || exit
    composer require php-mqtt/client guzzlehttp/guzzle
else
    echo "找不到 $TARGET_DIR/php 目錄，請檢查專案結構！"
fi

# 4. 設定 Systemd Daemon 服務
echo "配置 Daemon Systemd 服務..."
SERVICE_FILE="$TARGET_DIR/cloud_fish.service"

if [ -f "$SERVICE_FILE" ]; then
    cp "$SERVICE_FILE" /etc/systemd/system/cloud_fish.service
    chmod 644 /etc/systemd/system/cloud_fish.service

    # 重新載入 Systemd 並啟動服務
    systemctl daemon-reload
    systemctl enable cloud_fish.service
    systemctl restart cloud_fish.service
else
    echo "警告：找不到 $SERVICE_FILE 設定檔！Systemd 服務尚未建立。"
fi

# 5. 重啟 Apache 確保網頁設定生效
systemctl restart apache2

echo "========================================================="
echo "環境建置完成！"
echo "- Apache 網頁伺服器已啟動: http://<你的樹莓派_IP>/cloud_fish_system/php/html/"
echo "- 背景 MQTT 程式 (Daemon) 已啟動"
echo ""
echo "您可以透過以下指令查看背景 MQTT 記錄："
echo "  sudo journalctl -u cloud_fish.service -f"
echo "========================================================="
