# 🐟 魚菜共生智慧管理平台 (AIoT Aquaponics System) v2.5

**整合 ThingSpeak 雲端大數據、Telegram 智慧告警與 MQTT 自動化控制的完整解決方案。**

## 安裝與後續步驟說明

### 安裝環境

請允許執行我為您準備的 wsl --install 指令。這可能會跳出 Windows 的管理員權限要求 (UAC)，請點選「是」。

### 重新開機

安裝完成後，通常需要重新開機。

### 設定帳號密碼

重新開機後，系統會自動跳出一個 Ubuntu 的黑色終端機視窗，請依照畫面提示設定一組您自己的 UNIX 使用者名稱與密碼（密碼輸入時不會顯示字元，這是正常的）。

### 進入專案目錄並執行指令

在 WSL (Ubuntu) 裡面，您的 Windows C: 碟會被掛載在 /mnt/c/。請在設定完帳號後，於 Ubuntu 終端機內輸入以下指令：

bash

# 切換到您的 Windows 專案目錄下

cd /mnt/c/Users/wangs/Fish_PHP/cloud_fish_system

# 轉換腳本的換行字元 (避免 Windows 換行符號造成 Linux 腳本報錯)

sudo apt-get install dos2unix
dos2unix setup.sh

# 賦予執行權限並執行

chmod +x setup.sh
sudo ./setup.sh
這樣就能在 Windows 上完美模擬出 Linux 環境並執行您的伺服器程式碼了！

接下來只需要開啟 Ubuntu 終端機 按一下鍵盤的 Windows 鍵（或是點擊畫面左下角的開始按鈕），直接輸入「Ubuntu」或「WSL」，就會看到對應的應用程式。點開它即可開啟黑色的 Linux 終端機畫面。

進入專案資料夾並執行配置腳本 因為在 WSL 裡面，C: 碟會被放在 /mnt/c/ 路徑下，所以請複製貼上以下指令到剛打開的 Ubuntu 視窗中並按下 Enter 執行：

bash
cd /mnt/c/Users/wangs/Fish_PHP/cloud_fish_system
sudo apt-get update
sudo apt-get install -y dos2unix
dos2unix setup.sh
chmod +x setup.sh
sudo ./setup.sh
(備註：執行 sudo 時如果要求輸入密碼，請輸入您當初安裝 Ubuntu 時設定的密碼即可，輸入時畫面上不會顯示字元是正常的)

這樣就可以順利在您的 Windows 上跑起這個原生的 Linux 服務環境了！

在剛剛的

setup.sh
 腳本中，已經幫您設定好並自動啟動了 Apache 網頁伺服器，所以只要腳本順利跑完，網頁服務其實就已經在運作中了！

您只需要在您的 Windows 電腦上打開瀏覽器（如 Chrome、Edge 等），然後在網址列輸入以下網址即可看到剛架好的網頁：

👉 <http://localhost/cloud_fish_system/php/html/> （或輸入 <http://127.0.0.1/cloud_fish_system/php/html/）>

日常維護指令（在 Ubuntu 終端機內執行即可）
若您後續有修改網頁檔案或需要重啟服務，可以參考以下常用指令：

重新啟動網頁伺服器 (Apache)

bash
sudo systemctl restart apache2
重新啟動背景 MQTT 監聽程式

bash
sudo systemctl restart cloud_fish.service
查看背景服務是否發生錯誤或其輸出日誌

bash
sudo journalctl -u cloud_fish.service -f
(提示：如果您未來將專案轉移到獨立的 Raspberry Pi 上，只需把上面的 localhost 替換成該台樹莓派的區網 IP 位址即可！)
