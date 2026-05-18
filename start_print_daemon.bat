@echo off
REM ============================================================
REM start_print_daemon.bat
REM Demarre le daemon d'impression BlueBeeTN.
REM A configurer dans le Planificateur de taches Windows :
REM   - Declencheur : "Au demarrage de l'ordinateur"
REM   - Action      : ce fichier .bat
REM   - Cocher      : "Executer meme si l'utilisateur n'est pas connecte"
REM   - Cocher      : "Masque" (pour ne pas voir la fenetre)
REM
REM Le daemon redemarre automatiquement s'il plante, et patiente
REM tant que MySQL/WAMP n'est pas pret.
REM ============================================================

title BlueBeeTN - Daemon d'impression cuisine
cd /d "C:\wamp64\www\resto"

REM Detection automatique du chemin PHP dans WAMP
set "PHP_EXE="
for /d %%i in ("C:\wamp64\bin\php\php*") do set "PHP_EXE=%%i\php.exe"

if not exist "%PHP_EXE%" (
    echo [ERREUR] php.exe introuvable dans C:\wamp64\bin\php\
    echo Verifier l'installation de WAMP.
    pause
    exit /b 1
)

echo PHP detecte : %PHP_EXE%

:loop
echo.
echo [%date% %time%] Lancement du daemon...
"%PHP_EXE%" print_daemon.php
echo [%date% %time%] Le daemon s'est arrete. Redemarrage dans 5 secondes...
timeout /t 5 /nobreak >nul
goto loop
