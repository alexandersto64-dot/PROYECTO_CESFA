# CESFA — Centro de Estudios de Formación Activa (Fase 1 + Fase 2 aditiva)

Proyecto web de CESFA (Nuevo Chimbote, Santa, Áncash): web pública + intranet, adaptado desde el sistema anterior.
Teléfono 043-224920 · WhatsApp 992 723 924.

## Estructura principal
| Carpeta / archivo | Qué contiene |
|---|---|
| `index.html`, `nosotros.html`, `contacto.html`, `comunicado.html` | Web pública |
| `Cocina.html`, `Computacion_e_Informatica.html`, `Electronica.html`, `Estilismo.html`, `Fabricacion_de_Prendas_de_Vestir.html`, `Secretariado_Ejecutivo_Computarizado.html` | Páginas de carreras (menú CARRERAS) |
| `privacidad.html`, `terminos.html`, `cookies.html`, `404.html` | Páginas legales y de error |
| `login.html`, `recuperar-contrasena.html` | Acceso a la intranet |
| `admin/`, `subdirector/`, `profesor/`, `padre/` | Intranet (PHP) por rol |
| `backend/` | Conexión a BD, login, correo, PDFs, migraciones `.sql` y `vendor/` (Dompdf) |
| `css/`, `js/`, `img/` | Estilos, scripts e imágenes (logo CESFA en `img/brand/`) |
| `herramientas/` | Respaldo de BD (ver `herramientas/LEEME-RESPALDOS.md`) |

## Web pública
No necesita base de datos. Abre `index.html` o copia la carpeta a `C:\xampp\htdocs\CESFA` y entra a `http://localhost/CESFA/`.
El formulario de Contacto usa Formspree y **no enviará mensajes** hasta poner el ID real (ver `PENDIENTES-PARA-PUBLICAR.md`).

## Intranet
Requiere PHP 8+ y MySQL/MariaDB (XAMPP sirve). Entra por `login.html`. Los roles y pantallas siguen siendo los del sistema anterior; la adaptación a programas/módulos de CESFA es **Fase 2** (no hecha).

## Base de datos
- Nombre esperado por el código: **`cesfa`** (`backend/config/database.php`).
- **Importante:** la BD `cesfa` debe partir de la **estructura exportada de la BD actual** (`colegio_ie88044`), sin datos, mientras no exista una migración completa. No se renombraron tablas ni columnas.
  1. phpMyAdmin → crear BD `cesfa` (utf8mb4).
  2. Exportar **solo estructura** de `colegio_ie88044` e importarla en `cesfa`.
  3. Importar `backend/config/fase2_programas_modulos.sql` (Fase 2, ver abajo).
  4. Crear el primer usuario administrador (hash con `password_hash`; ver comentarios al final de `backend/config/database.sql`).
- Los `.sql` de `backend/config/` (`database.sql` y `migracion_*.sql`) son del sistema anterior y **no se verificó** que reconstruyan la BD completa; úsalos solo como referencia. La BD original no se modificó.

## Fase 2 (aditiva) — programas, ciclos y módulos
- `backend/config/fase2_programas_modulos.sql` crea tablas **nuevas** (`programas`, `ciclos`, `modulos`, `matriculas_programa`) y carga solo los nombres de las 6 carreras. No toca tablas ni datos existentes y se puede repetir.
- Nueva pantalla **Administración → Programas y módulos** (`admin/programas.php`, solo ADMIN): registrar duración, ciclos y módulos con horas, con CSRF y auditoría. Si faltan las tablas, avisa qué archivo importar.
- Probado con un MariaDB real: login de administrador, carga de la pantalla, alta de ciclo y módulo, rechazo sin token CSRF y registro en auditoría.
- **Aún no hecho (Fase 2b):** reemplazar en el resto de la intranet los grados/secciones, Primaria/Secundaria y notas por competencias por programa/ciclo/módulo. Hace falta la estructura exportada real de `colegio_ie88044`: los `.sql` del proyecto no la reconstruyen (faltan, por ejemplo, `niveles_grados`, `notificaciones`, `notas` y columnas como `cursos.nivel`).

## Configuración necesaria
1. Copia `backend/config/correo.local.php.example` a `correo.local.php` y completa los datos SMTP (para recuperar contraseña y avisos). Ese archivo es secreto: no se incluye en el ZIP ni se sube a git.
2. Si usas un usuario de BD distinto de `root` sin clave, copia `database.local.php.example` a `database.local.php` y ajusta los datos.
3. Para PDFs (boletas/reportes) PHP necesita la extensión GD.

## Pendientes actuales
Ver `PENDIENTES-PARA-PUBLICAR.md`. Resumen: textos y datos oficiales de CESFA (misión/visión, contenidos, duración, horarios, costos), ID de Formspree, dominio, URL oficial de Facebook, logo en mayor resolución y la Fase 2 de BD.
