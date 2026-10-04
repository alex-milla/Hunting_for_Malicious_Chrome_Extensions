@echo off
 REM ============================================================
 REM Script para crear el repositorio GitHub y subir el código
 REM Chrome Extension Validator - Alex Milla
 REM ============================================================

chcp 65001 >nul 2>&1

echo ***********************************************************
echo *  Chrome Extension Validator - Setup GitHub Repository    *
echo ***********************************************************
echo.

 REM Verificar que estamos en el directorio correcto
 if not exist "index.html" (
     echo ERROR: No estas en el directorio public_html
     echo Ejecuta este script desde: I:\ChromeExtensions\public_html
     pause
     exit /b 1
 )

 echo [1/3] Verificando git...
 git --version >nul 2>&1
 if errorlevel 1 (
     echo ERROR: Git no esta instalado
     echo Descarga Git desde: https://git-scm.com/download/win
     pause
     exit /b 1
 )
 echo OK: Git esta instalado

 echo.
 echo [2/3] Configurando repositorio local...
 
 REM Verificar si ya hay commits
 git log --quiet >nul 2>&1
 if errorlevel 1 (
     echo Inicializando repositorio git...
     git init
     git add -A
     git commit -m "Initial commit" --allow-empty
 )

 REM Asegurar que config.php no esta en el repositorio
 git rm --cached config.php 2>nul

 echo.
 echo ***********************************************************
echo *  PASO MANUAL REQUERIDO                                    *
echo ***********************************************************
echo.
echo 1. Ve a: https://github.com/new
echo.
echo 2. Crea un repositorio con:
    Nombre: Chrome-Extension-Validator
    Descripcion: Web application to validate malicious Chrome extensions - Apache + PHP
    Visibilidad: Publico
    NO marques "Initialize this repository with a README"
echo.
echo 3. Haz clic en "Create repository"
echo.
echo 4. Luego pulsa ENTER para continuar...
echo.
 pause

 echo.
 echo [3/3] Subiendo el codigo a GitHub...
 set /p repo_url=Introduce la URL del repositorio (https://github.com/alex-milla/Chrome-Extension-Validator.git): 
 
 if "%repo_url%"=="" (
     set repo_url=https://github.com/alex-milla/Chrome-Extension-Validator.git
 )

echo Configurando remote...
git remote set-url origin %repo_url%

echo Empujando a GitHub (esto puede tardar unos minutos)...
git push -u origin master

 if errorlevel 1 (
     echo.
     echo ***********************************************************
     echo *  Ha fallado el push a GitHub                             *
     echo ***********************************************************
     echo.
     echo Posibles causas:
     echo 1. El repositorio no existe en GitHub
     echo 2. No tienes permisos de escritura
     echo 3. Problemas de autenticacion
     echo.
     echo SOLUCION:
     echo.
     echo Opcion A: Usa HTTPS con token de GitHub
     echo   git remote set-url origin https://<TOKEN>@github.com/alex-milla/Chrome-Extension-Validator.git
     echo   (Sustituye <TOKEN> por tu PAT de GitHub)
     echo.
     echo Opcion B: Usa SSH
     echo   git remote set-url origin git@github.com:alex-milla/Chrome-Extension-Validator.git
     echo.
     pause
     exit /b 1
 )

 echo.
 echo ***********************************************************
 echo *  EXITO: Codigo subido a GitHub                             *
 echo ***********************************************************
 echo.
 echo Repositorio: %repo_url%
 echo.
 echo Ahora puedes:
 echo 1. Subir el codigo a tu hosting (excepto .git/, config.php, y CREAR_REPOSITORIO_GITHUB.bat)
 echo 2. Renombrar config.sample.php a config.php
 echo 3. Configurar los tokens en config.php
 echo    - ADMIN_TOKEN: token para autorizar la sincronizacion
 echo    - GH_TOKEN: tu PAT de GitHub con permisos Contents: Read and write
 echo    - GH_REPO: alex-milla/Hunting_for_Malicious_Chrome_Extensions
 echo    - GH_BRANCH: main
 echo 4. Asegurarte de que la carpeta cache/ tenga permisos de escritura (755)
 echo.
 echo Para ver la aplicacion: Abre tu dominio en el navegador
 echo.
 pause
