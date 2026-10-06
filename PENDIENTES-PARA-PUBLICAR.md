# PENDIENTES PARA PUBLICAR — I.E. 88044

**Estado técnico: terminado y verificado.** Todo lo de este documento depende de información real del colegio o de tener dominio/hosting definitivo — nada de esto es código pendiente, es contenido y datos.

---

## 1. Contenido real (lo más importante — bloquea todo lo demás)

Cada página tiene texto marcado en cursiva gris como `[pendiente]`. Resumen de cuánto falta por página:

| Página | Elementos pendientes | Qué se necesita |
|---|---|---|
| `historia.html` | 6 | Reseña histórica, misión, visión |
| `valores.html` | 9 | Valores institucionales oficiales |
| `espacio.html` | 8 | Descripción de aulas y áreas |
| `docentes.html` | 14 | Nombres, cargos y bio breve del equipo docente |
| `noticias.html` | 17 | Comunicados/noticias reales con fecha |
| `padres.html` | 17 | Respuestas del FAQ (matrícula, horarios, documentos, vacantes) |
| `index.html` | 25 | Cifras (años, N° alumnos/docentes), textos de portada |
| `galeria.html` | 12 | 8 fotos reales (hoy son ilustraciones de relleno) |
| `bienvenida.html` | 12 | Mensaje de bienvenida, foto institucional |
| Resto de páginas | 1-8 c/u | Datos menores (secretaría, correo, redes sociales) |

**Para reemplazar contenido:** cada bloque pendiente está en una etiqueta con `class="placeholder-text"` — es fácil de ubicar buscando la palabra "pendiente" en cada archivo `.html`.

**Fotos de la galería:** están en `img/galeria/foto-1.svg` a `foto-8.svg`. Cuando tengan las fotos reales, solo hay que reemplazar esos 8 archivos (mismo nombre, cambiar `.svg` por `.jpg` o `.png` y actualizar la extensión en los 3 archivos que las usan: `galeria.html`, `bienvenida.html` y `js/main.js` no las toca, solo el HTML).

⚠️ **Recordatorio del plan inicial:** antes de publicar fotos de estudiantes, confirmar con el colegio que cuentan con autorización de los padres para publicar imágenes de menores.

---

## 2. Datos que dependen de tener dominio/hosting definitivo

| Qué | Dónde | Cuántas veces |
|---|---|---|
| ID real de Formspree (reemplazar `TU_ID_DE_FORMSPREE`) | `contacto.html`, línea 108 | 1 vez |
| Dominio real (reemplazar `TU-DOMINIO-AQUI.pe`) | `sitemap.xml`, `robots.txt` | 15 líneas |
| Número real de WhatsApp del colegio | Las 15 páginas (`<a class="whatsapp-float"...>`, al final de cada archivo, antes de `</body>`) | 15 veces |

Para el WhatsApp, cuando tengan el número, el enlace se reemplaza así (ejemplo con número ficticio +51 999 888 777):
```html
<!-- Antes -->
<a class="whatsapp-float placeholder-fab" href="contacto.html" ...>

<!-- Después -->
<a class="whatsapp-float" href="https://wa.me/51999888777" target="_blank" rel="noopener" ...>
```
(y quitar la clase `placeholder-fab`, que solo existe para que se note que es un botón de prueba).

---

## 3. Publicación

1. Elegir hosting: Netlify o Vercel (gratis, HTTPS automático, ideal si el colegio no tiene uno propio) o el hosting que ya tenga el colegio si cuenta con dominio `.edu.pe`.
2. Subir la carpeta completa del proyecto tal cual está.
3. Una vez publicado con el dominio real, hacer los reemplazos de la sección 2.

---

## 4. Pruebas que solo se pueden hacer con navegador real

Ya validé todo lo que se puede probar sin navegador (estructura HTML, enlaces, imágenes, y **simulé clics reales en las 15 páginas** para confirmar que el menú, FAQ, galería, carrusel, formulario, modal de docentes y calendario de eventos funcionan sin errores). Falta, una vez tengan el sitio accesible en un navegador de verdad:

- [ ] Correr **Lighthouse** desde Chrome DevTools (F12 → pestaña Lighthouse → Analizar) — rendimiento, accesibilidad, SEO.
- [ ] Probar el sitio en **Firefox** y **Edge** además de Chrome.
- [ ] Probar el **formulario de contacto ya conectado** con un envío real de prueba.
- [ ] Revisar visualmente en un celular real (no solo el simulador de Chrome).

---

## 5. Entrega final (16-17 de agosto, según el plan)

- [ ] Backup del proyecto (el ZIP que ya tienes sirve como respaldo).
- [ ] Preparar una demo corta para mostrar el sitio (2-3 minutos: inicio → menú → galería → contacto).

---

**En resumen:** el sitio está terminado técnicamente. Lo único que falta es contenido real del colegio y los 3 datos que dependen de tener dominio/hosting. En cuanto tengan eso, es cuestión de horas (no días) terminar de conectar todo.

---

## 5. Revisión de publicación (octubre 2026) — hecho y pendiente

### Ya implementado en el código
- Aviso de cookies + `cookies.html` + enlaces legales en el pie (el mapa de Google solo carga con consentimiento).
- `privacidad.html` y `terminos.html` completos (Ley 29733 y reglamento D.S. 016-2024-JUS).
- Casilla de aceptación de privacidad en el formulario de Contacto.
- `.htaccess`: redirección a HTTPS (no afecta localhost), HSTS y cookie de sesión `Secure` (solo si hay HTTPS), Content-Security-Policy, compresión y caché, bloqueo de `debug_*.txt` y `test.php`.
- Respaldos: `herramientas/respaldar.php` / `.bat` + `LEEME-RESPALDOS.md`.
- Login: mejor contraste y enlaces a Privacidad / Términos / Cookies.
- Se eliminaron `backend/config/debug_boleta_9.txt` y `debug_boleta_10.txt` (contenían nombre y DNI reales de una estudiante).

### Pendiente — necesita información o acciones fuera del código
| Pendiente | Qué se necesita |
|---|---|
| Dominio real (`TU-DOMINIO-AQUI.pe` en `sitemap.xml` y `robots.txt`) | Dominio y hosting definitivos |
| Certificado HTTPS | Se activa en el hosting; luego el `.htaccess` ya hace el resto |
| Nombre/cargo del responsable de datos y correo para derechos ARCO (`privacidad.html`) | Datos de la dirección |
| Plazos de conservación de datos (`privacidad.html`) | Definirlos con la dirección o un asesor legal |
| Revisión legal de privacidad, términos y cookies | Un abogado o asesor |
| Formspree: reemplazar `TU_ID_DE_FORMSPREE` en `contacto.html` | Cuenta de Formspree |
| Teléfonos, correos, redes y WhatsApp del pie (textos "pendiente") | Datos reales del colegio |
| Correo institucional (hoy `correo.local.php` usa un Gmail personal) | Cuenta/SMTP institucional |
| Contenido real (historia, docentes, fotos, noticias) | Ver sección 1 |
| Autorización de padres para publicar fotos de menores | Formato firmado por los padres |
| Actualizar librerías de `backend/vendor` (dompdf, etc.) | Ejecutar `composer update` con internet y probar boletas |
| Google Search Console / Analytics | Cuenta de Google y dominio publicado |
| Monitoreo (UptimeRobot u otro) y plan de soporte | Definir responsable y canal |
| Migración `migracion_login_intentos.sql` | Ejecutarla en la BD de producción (sin ella el límite de intentos usa archivos temporales) |
| Página de error personalizada (`ErrorDocument`) | Depende de la ruta final del sitio |
