@echo off
:: GigHive Windows Installer Launcher
:: Bypasses PowerShell execution policy so users can double-click to install.
:: Note: arguments containing spaces must be quoted. Running install.ps1 directly
:: from a PowerShell terminal is recommended when paths contain spaces.
powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%~dp0install.ps1" %*
pause
