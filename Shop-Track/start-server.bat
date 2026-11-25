@echo off
cd /d "%~dp0"
title ShopTrack Server
echo ====================================================================
echo ShopTrack - Smart Small Shop Inventory Management System
echo ====================================================================
echo Starting PHP Development Server...
echo Document Root: %CD%
echo Access URL:    http://localhost:8000
echo.
echo NOTE: Keep this window open or minimized while using ShopTrack.
echo       Closing this window will stop the server.
echo ====================================================================
echo.

:: Wait 2 seconds for PHP to bind, then automatically open in Google Chrome
start /b cmd /c "timeout /t 2 /nobreak >nul && if exist ""C:\Program Files\Google\Chrome\Application\chrome.exe"" (start """" ""C:\Program Files\Google\Chrome\Application\chrome.exe"" ""http://localhost:8000"") else (start http://localhost:8000)"

:: Run PHP Server from current directory
"C:\Users\raksh\php83\php.exe" -S localhost:8000
pause
