@echo off
setlocal
cd /d "%~dp0"

if not exist ".venv\Scripts\python.exe" (
  echo Creez mediul Python local...
  py -m venv .venv
  if errorlevel 1 goto :error
)

echo Verific dependentele...
".venv\Scripts\python.exe" -m pip install -q -r requirements.txt
if errorlevel 1 goto :error

echo.
echo LOGA3: documente generate + rapoarte lunare din 2024-10 pana la ultima luna incheiata
".venv\Scripts\python.exe" loga3_downloader.py
set "LOGA_EXIT=%ERRORLEVEL%"
echo.
if "%LOGA_EXIT%"=="0" (
  echo Gata. Fisierele sunt in folderul downloads.
) else (
  echo Scriptul s-a oprit cu codul %LOGA_EXIT%.
)
pause
exit /b %LOGA_EXIT%

:error
echo Instalarea mediului Python a esuat.
pause
exit /b 1
