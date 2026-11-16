@echo off
REM Respaldo diario de la BD y archivos subidos (XAMPP). Programarlo con el Programador de tareas de Windows.
REM Para guardar en otro disco/USB: agrega  --destino=D:\Respaldos\CESFA
"C:\xampp\php\php.exe" "%~dp0respaldar.php" --conservar=14
