# Respaldos automáticos — I.E.P. 88044

## Qué hace
`respaldar.php` crea una carpeta con fecha (ej. `2026-10-05_22-00`) que contiene:
- `colegio_ie88044.sql` → toda la base de datos.
- `archivos_subidos.zip` → boletas, materiales y envíos de `backend/uploads`.

Mantiene los últimos 14 respaldos y borra los más antiguos.

## Prueba manual (XAMPP / Windows)
1. Abre el Símbolo del sistema.
2. Ejecuta: `C:\xampp\php\php.exe C:\xampp\htdocs\IE.88044\herramientas\respaldar.php`
3. Revisa que aparezca la carpeta nueva en `herramientas\respaldos\`.

## Programarlo cada día (Windows)
1. Programador de tareas → *Crear tarea básica* → Diaria, 10:00 p. m.
2. Acción: *Iniciar un programa* → `C:\xampp\htdocs\IE.88044\herramientas\respaldar.bat`.

## En un servidor Linux (cron)
`0 22 * * * php /ruta/IE.88044/herramientas/respaldar.php --destino=/ruta/respaldos --conservar=30`

## Importante
- Un respaldo en la MISMA computadora no protege si el equipo falla: usa `--destino=` con otro disco, USB o carpeta sincronizada en la nube.
- Los respaldos contienen datos de menores: guárdalos con acceso restringido.
- Prueba restaurar de vez en cuando (importar el `.sql` en una BD de prueba).
- Esta carpeta está bloqueada para el navegador (`.htaccess`), pero no la subas a un servidor público si no la necesitas allí.
