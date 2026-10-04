@echo off
 REM ============================================================
 REM Script para crear el repositorio GitHub y subir el código
 REM Chrome Extension Validator - Alex Milla
 REM ============================================================

 echo ***********************************************************
 echo *  Chrome Extension Validator - Subida a GitHub            *
 echo ***********************************************************
 echo.

 REM Verificar que estamos en el directorio correcto
 if not exist "index.html" (
     echo ERROR: No estás en el directorio public_html
     echo Ejecuta este script desde: I:\ChromeExtensions\public_html
     pause
     exit /b 1
 )

 echo [1/4] Verificando git...
 git --version >nul 2>&1
 if errorlevel 1 (
     echo ERROR: Git no está instalado
     echo Descarga Git desde: https://git-scm.com/download/win
     pause
     exit /b 1
 )
 echo Git está instalado

 echo.
 echo [2/4] Revisando el repositorio local...
 git status >nul 2>&1
 if errorlevel 1 (
     echo Inicializando repositorio git...
     git init
 )

 echo.
 echo [3/4] Asegurando que config.php NO está en el repositorio...
 git rm --cached config.php 2>nul
 echo en .gitignore >nul 2>&1
 if not errorlevel 1 (
     git rm --cached config.php 2>nul
 )

 echo.
 echo ***********************************************************
 echo *  ACCION REQUIERIDA: Crea el repositorio en GitHub          *
 echo ***********************************************************
 echo.
 echo Ve a: https://github.com/new
 echo.
 echo   Nombre del repositorio: Chrome-Extension-Validator
 echo   Descripcion: Web application to validate malicious Chrome extensions - Apache + PHP
 echo   Publico (no privado)
 echo   NO marques "Initialize this repository with a README"
 echo.
 echo   Luego pulsa ENTER para continuar...
 echo.
 pause

 echo.
 echo [4/4] Configurando remote y empujando...
 set /p repo_url=Introduce la URL del repositorio (https://github.com/alex-milla/Chrome-Extension-Validator.git): 
 
 if "%repo_url%"=="" (
     set repo_url=https://github.com/alex-milla/Chrome-Extension-Validator.git
 )

echo Configurando remote...
git remote set-url origin %repo_url%

echo Empujando a GitHub...
git push -u origin master

 if errorlevel 1 (
     echo.
     echo ***********************************************************
     echo *  Ha fallado el push a GitHub                             *
     echo ***********************************************************
     echo.
     echo Esto puede deberse a:
     echo 1. El repositorio no existe en GitHub
     echo 2. No tienes permisos
     echo 3. Problemas de autenticacion
     echo.
     echo Soluciones:
     echo - Crea el repositorio manualmente en https://github.com/new
     echo - Usa un token de GitHub:
     echo   git remote set-url origin https://<TOKEN>@github.com/alex-milla/Chrome-Extension-Validator.git
     echo.
     pause
     exit /b 1
 )

 echo.
 echo ***********************************************************
 echo *  EXITO: Código subido a GitHub                             *
 echo ***********************************************************
 echo.
 echo El repositorio está en: %repo_url%
 echo.
 echo Ahora puedes:
 echo 1. Subir el código a tu hosting (excepto .git/ y config.php)
 echo 2. Renombrar config.sample.php a config.php
 echo 3. Configurar los tokens en config.php
 echo.
 pause
