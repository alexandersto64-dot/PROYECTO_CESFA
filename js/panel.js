document.addEventListener("DOMContentLoaded", function () {

    var toggle = document.getElementById("sidebarToggle");
    var sidebar = document.getElementById("panelSidebar");
    var overlay = document.getElementById("sidebarOverlay");

    function closeSidebar() {
        if (!sidebar || !overlay || !toggle) return;
        sidebar.classList.remove("open");
        overlay.classList.remove("visible");
        toggle.setAttribute("aria-expanded", "false");
    }

    if (toggle && sidebar && overlay) {

        toggle.addEventListener("click", function () {
            var isOpen = sidebar.classList.toggle("open");
            overlay.classList.toggle("visible", isOpen);
            toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        });

        overlay.addEventListener("click", closeSidebar);

    }

    // Contraer/expandir el sidebar en escritorio: solo agrega/quita una
    // clase en <body> (el CSS se encarga de ocultar las etiquetas de
    // texto) y recuerda la preferencia en localStorage para que no
    // "salte" en cada página del panel. En móvil el botón ni siquiera
    // es visible (ver CSS), así que esto no afecta el menú deslizante.
    var collapseBtn = document.getElementById("sidebarCollapseBtn");
    var COLLAPSE_KEY = "ie88044_sidebar_collapsed";

    if (collapseBtn) {

        if (localStorage.getItem(COLLAPSE_KEY) === "1") {
            document.body.classList.add("sidebar-collapsed");
            collapseBtn.setAttribute("aria-pressed", "true");
        }

        collapseBtn.addEventListener("click", function () {
            var colapsado = document.body.classList.toggle("sidebar-collapsed");
            collapseBtn.setAttribute("aria-pressed", colapsado ? "true" : "false");
            collapseBtn.setAttribute("aria-label", colapsado ? "Expandir menú" : "Contraer menú");
            try {
                localStorage.setItem(COLLAPSE_KEY, colapsado ? "1" : "0");
            } catch (e) {
                // Almacenamiento no disponible (modo privado, etc.): la
                // preferencia simplemente no persiste, el botón sigue
                // funcionando igual dentro de la misma página.
            }
        });

    }

    // Grupos del sidebar (Planificación, Recursos, Seguimiento, etc.):
    // colapsables por separado, uno independiente del otro. El estado
    // se guarda en localStorage por grupo para que no cambie al
    // navegar a otra página del panel. El grupo que contiene el
    // enlace activo siempre se muestra expandido al cargar, sin
    // importar lo guardado, para que el profesor no "pierda" en qué
    // sección está parado.
    var GROUP_COLLAPSE_PREFIX = "ie88044_sidebar_group_";

    document.querySelectorAll(".sidebar-group-toggle").forEach(function (boton) {

        var grupo = boton.dataset.sidebarGroup;
        // Relación directa en el DOM (el div de items siempre es el
        // siguiente hermano del botón, ver backend/partials/sidebar.php)
        // en vez de buscarlo por atributo: más simple y no puede
        // desincronizarse.
        var contenedor = boton.nextElementSibling;
        if (!contenedor || !contenedor.classList.contains("sidebar-group-items")) return;

        var tieneActivo = contenedor.querySelector(".nav-link.active, .nav-sublink.active") !== null;
        var colapsadoGuardado = false;

        try {
            colapsadoGuardado = localStorage.getItem(GROUP_COLLAPSE_PREFIX + grupo) === "1";
        } catch (e) {
            // Almacenamiento no disponible: se queda expandido por defecto.
        }

        var colapsado = colapsadoGuardado && !tieneActivo;

        contenedor.classList.toggle("is-collapsed", colapsado);
        boton.setAttribute("aria-expanded", colapsado ? "false" : "true");

        boton.addEventListener("click", function () {
            var yaColapsado = contenedor.classList.toggle("is-collapsed");
            boton.setAttribute("aria-expanded", yaColapsado ? "false" : "true");
            try {
                localStorage.setItem(GROUP_COLLAPSE_PREFIX + grupo, yaColapsado ? "1" : "0");
            } catch (e) {
                // Sin almacenamiento disponible: el toggle sigue
                // funcionando igual dentro de la misma página, solo no
                // persiste entre páginas.
            }
        });

        // El botón es un <div role="button"> (ver backend/partials/
        // sidebar.php) para evitar el "chrome" nativo del <button> en
        // algunos navegadores, que pintaba un fondo gris claro encima
        // del estilo y dejaba el texto ilegible. La contrapartida es
        // que un div no responde a Enter/Espacio como un botón real,
        // así que ese soporte de teclado se agrega a mano acá.
        boton.addEventListener("keydown", function (evento) {
            if (evento.key === "Enter" || evento.key === " " || evento.key === "Spacebar") {
                evento.preventDefault();
                boton.click();
            }
        });

    });

    // Submenús del sidebar: solo uno abierto a la vez.
    var parents = document.querySelectorAll(".nav-parent");

    parents.forEach(function (parent) {

        var btn = parent.querySelector(".nav-parent-toggle");
        if (!btn) return;

        btn.addEventListener("click", function () {
            var yaAbierto = parent.classList.contains("open");
            parents.forEach(function (p) { p.classList.remove("open"); });
            if (!yaAbierto) { parent.classList.add("open"); }
        });

    });

    // Estado de carga en formularios del panel (subida de PCA/Unidades/
    // Sesiones, envío de trabajos, etc.): deshabilita el botón al enviar
    // para evitar doble clic y dar una señal visual inmediata mientras
    // la página recarga. Solo visual: el formulario sigue enviándose de
    // forma normal (POST + redirect), no se cambia ninguna validación.
    var formulariosPanel = document.querySelectorAll(".panel-form, .material-upload-form");

    formulariosPanel.forEach(function (form) {

        form.addEventListener("submit", function () {

            var boton = form.querySelector("button[type='submit']");
            if (!boton || boton.disabled) return;

            boton.dataset.textoOriginal = boton.textContent;
            boton.disabled = true;
            boton.classList.add("is-loading");
            boton.textContent = "Guardando…";

        });

    });

    // Toast de confirmación: los mensajes de éxito (.panel-alert-success)
    // ya vienen calculados y renderizados por PHP; esto solo los hace
    // desaparecer solos después de unos segundos, para no dejarlos
    // flotando permanentemente sobre el contenido. No toca los de
    // error, que se quedan visibles en su lugar.
    document.querySelectorAll(".panel-alert-success").forEach(function (toast) {
        setTimeout(function () {
            toast.classList.add("is-hiding");
            setTimeout(function () { toast.remove(); }, 350);
        }, 4000);
    });

    // Buscador simple (Mis alumnos / Mis trabajos, cada una en su
    // propia página). Filtra en el navegador por texto visible de
    // cada fila/tarjeta — no dispara ninguna consulta nueva ni
    // cambia qué datos se cargaron.
    function activarBuscador(inputId, contenedorSelector, itemSelector) {

        var input = document.getElementById(inputId);
        var contenedor = document.querySelector(contenedorSelector);
        if (!input || !contenedor) return;

        input.addEventListener("input", function () {
            var texto = input.value.trim().toLowerCase();
            contenedor.querySelectorAll(itemSelector).forEach(function (item) {
                var coincide = item.textContent.toLowerCase().indexOf(texto) !== -1;
                item.style.display = coincide ? "" : "none";
            });
        });

    }

    activarBuscador("filtro-alumnos", ".table-wrap", "tbody tr");
    activarBuscador("filtro-trabajos", ".trabajos-container", ".trabajo-card");

    // Selector de grado del SIDEBAR (profesor con 2+ grados, visible en
    // cualquier página del módulo Profesor): solo decide a qué URL
    // navegar. Las opciones ya vienen filtradas por PHP a los grados
    // reales de este profesor (profesor_grados_asignados), y la página
    // de destino (grado.php/pca.php/unidades.php/sesiones.php/
    // documentos.php) vuelve a validar el grado por su cuenta al
    // cargar, así que esto es solo una comodidad de navegación, nunca
    // la fuente de la autorización.
    var selectorGradoSidebar = document.getElementById("sidebarGradoSelector");

    if (selectorGradoSidebar) {
        selectorGradoSidebar.addEventListener("change", function () {
            var id = selectorGradoSidebar.value;
            var mantenerPagina = selectorGradoSidebar.dataset.mantenerPagina === "1";
            if (mantenerPagina) {
                window.location.href = window.location.pathname + "?id_nivel_grado=" + id;
            } else {
                window.location.href = "grado.php?id_nivel_grado=" + id;
            }
        });
    }

    // Selector de hijo(a) del SIDEBAR (Padre con 2+ hijos vinculados):
    // mismo criterio que el selector de grado de arriba, pero todas
    // las páginas del módulo Padre aceptan id_alumno en su propia URL
    // (no hay una "página de destino" fija como grado.php), así que
    // siempre se mantiene la página actual.
    var selectorHijoSidebar = document.getElementById("sidebarHijoSelector");

    if (selectorHijoSidebar) {
        selectorHijoSidebar.addEventListener("change", function () {
            window.location.href = window.location.pathname + "?id_alumno=" + selectorHijoSidebar.value;
        });
    }

    // ==================================================
    // Validación de archivo en el navegador ANTES de enviar
    // (tipo/extensión y tamaño), en los formularios de subida de
    // materiales (PCA/Unidades/Sesiones, Tutoría y Envíos a
    // Subdirección). Es solo una ayuda para el profesor — el
    // servidor (materiales_guardar_archivo() / envios_guardar_archivo())
    // sigue validando todo de nuevo y es la única fuente de verdad;
    // esto no reemplaza esa validación, solo evita el viaje al
    // servidor cuando el archivo obviamente no va a pasar.
    // ==================================================

    document.querySelectorAll(".material-upload-form[data-max-mb]").forEach(function (form) {

        var input = form.querySelector("input[type=file]");
        if (!input) return;

        var maxBytes = parseFloat(form.dataset.maxMb) * 1024 * 1024;
        var extensionesPermitidas = form.dataset.extensiones.split(",").map(function (e) { return e.trim().toLowerCase(); });

        var aviso = document.createElement("p");
        aviso.className = "placeholder-text upload-validacion-error";
        aviso.style.color = "#c0392b";
        aviso.style.display = "none";
        input.insertAdjacentElement("afterend", aviso);

        function limpiarAviso() {
            aviso.style.display = "none";
            aviso.textContent = "";
        }

        function mostrarAviso(texto) {
            aviso.textContent = texto;
            aviso.style.display = "";
        }

        input.addEventListener("change", function () {

            limpiarAviso();

            if (!input.files || input.files.length === 0) return;

            var archivo = input.files[0];
            var extension = archivo.name.includes(".") ? archivo.name.split(".").pop().toLowerCase() : "";

            if (extensionesPermitidas.indexOf(extension) === -1) {
                mostrarAviso("Extensión no permitida. Solo se aceptan: " + extensionesPermitidas.join(", ") + ".");
                input.value = "";
                return;
            }

            if (archivo.size > maxBytes) {
                mostrarAviso("El archivo pesa " + (archivo.size / (1024 * 1024)).toFixed(1) + " MB; el máximo permitido es " + form.dataset.maxMb + " MB.");
                input.value = "";
                return;
            }

        });

        form.addEventListener("submit", function (evento) {

            if (!input.files || input.files.length === 0) return; // required ya lo exige

            var archivo = input.files[0];
            var extension = archivo.name.includes(".") ? archivo.name.split(".").pop().toLowerCase() : "";

            if (extensionesPermitidas.indexOf(extension) === -1 || archivo.size > maxBytes) {
                if (extensionesPermitidas.indexOf(extension) === -1) {
                    mostrarAviso("Extensión no permitida. Solo se aceptan: " + extensionesPermitidas.join(", ") + ".");
                } else {
                    mostrarAviso("El archivo pesa " + (archivo.size / (1024 * 1024)).toFixed(1) + " MB; el máximo permitido es " + form.dataset.maxMb + " MB.");
                }
                evento.preventDefault();
            }

        });

    });

});
