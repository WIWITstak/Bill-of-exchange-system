@echo off
cd /d "F:\OpenServer\domains\ogas"
"F:\OpenServer\modules\php\PHP_8.0\php.exe" "F:\OpenServer\domains\ogas\websocket\chat-server.php" >> "F:\OpenServer\domains\ogas\websocket\server.log" 2>&1
