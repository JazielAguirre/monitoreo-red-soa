@echo off
rem Enciende Apache y MySQL de XAMPP y revisa Tailscale, el Firewall de Windows y la
rem demo (herramientas\iniciar-servicios.ps1). Doble clic, o desde una terminal:
rem   herramientas\iniciar-servicios.bat
rem -ExecutionPolicy Bypass vale solo para este script: no cambia la configuración de
rem Windows, y así funciona aunque los scripts de PowerShell estén bloqueados.
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0iniciar-servicios.ps1" %*
set "CODIGO=%ERRORLEVEL%"
rem Con doble clic la ventana se cerraría al terminar: espera una tecla para leer el resumen.
echo %CMDCMDLINE% | findstr /i /c:"%~nx0" >nul && (echo. & pause)
exit /b %CODIGO%
