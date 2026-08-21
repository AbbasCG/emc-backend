@echo off
rem خادم التطوير المحلي — عمال متوازون حتى لا تصطف طلبات لوحات التحكم خلف بعضها.
set PHP_CLI_SERVER_WORKERS=12
php "%~dp0artisan" serve --host=127.0.0.1 --port=8000
