@echo off
title Stop ShopTrack Server
echo ====================================================================
echo Stopping ShopTrack Local Server...
echo ====================================================================
taskkill /f /im php.exe >nul 2>&1
echo ShopTrack server stopped successfully.
timeout /t 2 >nul
