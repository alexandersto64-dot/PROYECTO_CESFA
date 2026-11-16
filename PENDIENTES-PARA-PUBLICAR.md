# PENDIENTES — CESFA

Estado: **Fase 1 aplicada y Fase 2 aditiva lista** (programas, ciclos y módulos). Lo siguiente depende de datos reales de CESFA; no se inventó ninguno.

## Contenido (falta información de CESFA)
- [ ] **Misión y visión** — `nosotros.html` (hoy dice "pendiente de confirmar").
- [ ] **Contenidos de Estilismo** — `Estilismo.html`.
- [ ] **Contenidos de Secretariado Ejecutivo Computarizado** — `Secretariado_Ejecutivo_Computarizado.html`.
- [ ] **Contenidos de Electrónica** — `Electronica.html`.
- [ ] **Duración de las carreras** (las 6).
- [ ] **Horarios** (las 6).
- [ ] **Costos** (las 6; hoy las páginas remiten a "consultar con nuestro equipo").
- [ ] Comunicados reales — `comunicado.html` (hoy son tarjetas de ejemplo con "Fecha pendiente").
- [ ] Foto/flyer para el carrusel de Estilismo, Secretariado y Electrónica — `index.html`.
- [ ] Dirección: confirmar si lleva "Av. Agraria" (hoy se usa la del banner) — footer y `contacto.html`.

## Datos técnicos
- [ ] **ID de Formspree** (`TU_ID_DE_FORMSPREE`) — `contacto.html`. Sin él el formulario no envía.
- [ ] **Dominio oficial** (`TU-DOMINIO-AQUI.pe`) — `sitemap.xml` y `robots.txt`.
- [ ] **URL oficial de Facebook "Cetpro Cesfa"** — hoy es un enlace de búsqueda en header y footer de todas las páginas.
- [ ] **Logo en mayor resolución** (hoy 600 px de ancho) — `img/brand/logo-cesfa.png`.
- [ ] Instagram / YouTube: no hay datos; se quitaron del header.
- [ ] Credenciales SMTP: crear `backend/config/correo.local.php` desde el `.example`.

## Legal (`privacidad.html`) — no se redactó nada sin confirmación
- [ ] Nombre y cargo del representante legal / encargado de datos.
- [ ] Correo para solicitudes de datos personales (derechos ARCO).
- [ ] Plazos de conservación de datos (idealmente con asesoría legal).
- [ ] Revisión legal del texto sobre menores de edad y apoderados (es texto genérico).

## Base de datos
- [ ] Crear la BD `cesfa` desde la **estructura exportada** de `colegio_ie88044` (ver `LEEME-CESFA.md`). No hay migración completa.

## Fase 2
- [x] Tablas `programas`, `ciclos`, `modulos`, `matriculas_programa` y pantalla Administración → Programas y módulos (aditivo, probado).
- [ ] Cargar en esa pantalla los ciclos, módulos, horas y duración reales (CESFA debe confirmarlos).
- [ ] Turnos de `matriculas_programa`: hoy es texto libre; definir cuáles existen.
- [ ] **Fase 2b:** adaptar el resto de la intranet (grados/secciones, Primaria/Secundaria, rol "Padre", notas por competencias, PCA/Unidades/Sesiones) a programas/ciclos/módulos. Requiere la estructura exportada real de `colegio_ie88044`.
- [ ] Decidir quién usará la intranet (administración, profesores y/o alumnos); define si el portal "Padre" pasa a portal del alumno.

## Fase 3 (independiente)
- Notas por módulo, asistencia y certificados.
