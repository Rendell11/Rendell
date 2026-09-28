@echo off
title Binang 2nd Resident App - Setup
cd /d "%~dp0"
:menu
cls
echo ==========================================================
echo   Binang 2nd Resident App - Setup (Windows)
echo ==========================================================
echo.
echo   BAGONG LAPTOP (isang beses lang):
echo     1  Install tools  (Git, JDK 17, Flutter, Android SDK)
echo     2  Setup server   (XAMPP check, firewall, database, Firebase)
echo.
echo   ARAW-ARAW:
echo     3  Run sa phone   (USB, may hot reload)
echo     4  Build APK      (ipapasa/iinstall sa ibang phone)
echo.
echo   PUSH / DATA:
echo     5  Push scheduler (notif kahit sarado ang app)
echo     6  Backup database (bago ilipat sa ibang laptop)
echo.
echo     0  Exit
echo.
set /p c="Piliin (0-6): "
if "%c%"=="1" call :ps 1_install_tools.ps1
if "%c%"=="2" call :ps 2_setup_server.ps1
if "%c%"=="3" call :ps 3_run_on_phone.ps1
if "%c%"=="4" call :ps 4_build_apk.ps1
if "%c%"=="5" call :ps 5_push_scheduler.ps1
if "%c%"=="6" call :ps 6_backup_database.ps1
if "%c%"=="0" exit /b
goto menu

:ps
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0%~1"
exit /b
