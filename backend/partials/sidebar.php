<?php

// ==========================================
// Sidebar compartido del Intranet.
// Requiere: sesión iniciada (para $_SESSION["rol"])
// y $currentFile = basename(__FILE__) definido antes del include.
//
// Contador global de notificaciones: reutiliza la tabla
// `notificaciones` y las funciones de backend/config/notificaciones.php
// ya existentes (no se crea ninguna fuente de datos nueva). Se
// carga el helper aquí mismo (con ruta relativa a este archivo)
// para que funcione sin importar qué página incluya el sidebar.
//
// Identidad del profesor (nombre, rol, avatar) y grado activo: se
// leen de $profesor (ya lo obtienen con una consulta propia todas
// las páginas del módulo Profesor antes de este include) y de
// $etiqueta/$idNivelGradoSidebar (idem). No se agrega ninguna
// consulta nueva por esto.
//
// Selector de grado (2+ grados): reutiliza profesor_grados_asignados()
// -si el profesor ya la calculó antes del include (Dashboard, en
// $gradosAsignados) se reaprovecha esa misma variable en vez de
// volver a consultar; si no, se calcula aquí una sola vez.
// ==========================================

if (!function_exists("icon")) {
    require_once __DIR__ . "/icons.php";
}

$rolActual = $_SESSION["rol"] ?? "";

// Los 3 roles de profesor (el genérico "PROFESOR" y los específicos
// "PROFESOR_PRIMARIA"/"PROFESOR_SECUNDARIO" agregados en la
// migración de niveles) comparten el mismo menú del Dashboard del
// Profesor: la separación primaria/secundaria ya la hace el propio
// contenido (grados asignados), no el menú.
$esRolProfesor = in_array($rolActual, ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true);
$rolMenu = $esRolProfesor ? "PROFESOR" : $rolActual;

$rolEtiquetas = [
    "ADMIN"               => "Administrador",
    "SUBDIRECTOR"         => "Subdirección",
    "PROFESOR"            => "Profesor",
    "PROFESOR_PRIMARIA"   => "Profesor de Primaria",
    "PROFESOR_SECUNDARIO" => "Profesor de Secundaria",
    "PADRE"               => "Padre de familia",
];
$rolEtiqueta = $rolEtiquetas[$rolActual] ?? "Panel";

$notifNoLeidas = 0;

if (isset($conexion) && isset($_SESSION["id_usuario"])) {

    if (!function_exists("notificaciones_no_leidas")) {
        require_once __DIR__ . "/../config/notificaciones.php";
    }

    $notifNoLeidas = notificaciones_no_leidas($conexion, (int) $_SESSION["id_usuario"]);

}

// Página a la que debe apuntar el contador: solo enlazamos a una
// vista que realmente exista y liste las notificaciones del rol.
// Profesor, Subdirector y Administrador ya tienen su propia página
// (profesor/notificaciones.php, subdirector/notificaciones.php y
// admin/notificaciones.php — este último recibe el mismo aviso de
// "módulo completado" que ya recibía Subdirección, ver
// notificaciones_verificar_modulo_completo).
$notifHref = ($esRolProfesor || $rolActual === "SUBDIRECTOR" || $rolActual === "ADMIN" || $rolActual === "PADRE") ? "notificaciones.php" : null;

// Sufijo para conservar el hijo seleccionado al navegar entre las
// páginas del módulo Padre (mismo mecanismo que $sufijoGrado, pero
// con id_alumno). $idAlumnoActivo lo define padre_bootstrap.php.
$sufijoHijo = isset($idAlumnoActivo) && (int) $idAlumnoActivo > 0
    ? "?id_alumno=" . (int) $idAlumnoActivo
    : null;

// PCA/Unidades/Sesiones/Documentos ahora son por nivel+grado: si la
// página que incluye este sidebar ya tiene un grado en contexto
// (definido como $idNivelGradoSidebar antes del include), los
// enlaces del menú lo conservan; si no hay ninguno seleccionado
// todavía (p.ej. parado en "Mi panel"), esos enlaces llevan de
// vuelta al panel para elegir un grado primero.
// Padre: la campanita conserva el hijo seleccionado, igual que el resto del menú.
if ($rolActual === "PADRE" && $notifHref) {
    $notifHref .= $sufijoHijo ?? "";
}

$sufijoGrado = isset($idNivelGradoSidebar) && (int) $idNivelGradoSidebar > 0
    ? "?id_nivel_grado=" . (int) $idNivelGradoSidebar
    : null;

$menus = [

    "ADMIN" => [
        "titulo" => "Administración",
        "grupos" => [
            "" => [
                ["label" => "Dashboard", "icon" => "home", "href" => "dashboard.php"],
                ["label" => "Calendario", "icon" => "calendar", "href" => "calendario.php"],
                ["label" => "Usuarios", "icon" => "user", "href" => "usuarios.php"],
                ["label" => "Alumnos", "icon" => "graduation", "children" => [
                    ["label" => "Alumnos", "href" => "alumnos.php"],
                    ["label" => "Matrículas", "href" => "matriculas.php"],
                    ["label" => "Padres de familia", "href" => "padres.php"],
                    ["label" => "Importar padres (CSV)", "href" => "padres_importar.php"],
                    ["label" => "Cuentas familiares (código)", "href" => "padres_generar_familias.php"],
                    ["label" => "Solicitudes de matrícula", "href" => "solicitudes_matricula.php"],
                ]],
                ["label" => "Profesores", "icon" => "briefcase", "href" => "profesores.php"],
                ["label" => "Cursos", "icon" => "book", "href" => "cursos.php"],
                ["label" => "Importar notas (PDF)", "icon" => "upload", "children" => [
                    ["label" => "Subir boleta", "href" => "importar_notas.php"],
                    ["label" => "Historial de importaciones", "href" => "importaciones_pendientes.php"],
                ]],
                ["label" => "Documentos", "icon" => "file-text", "href" => "documentos.php"],
                ["label" => "Reportes", "icon" => "bar-chart", "href" => "reportes.php"],
                ["label" => "Notificaciones", "icon" => "bell", "href" => "notificaciones.php"],
                ["label" => "Configuración", "icon" => "settings", "href" => "configuracion.php"],
            ],
            "Sistema" => [
                ["label" => "Papelera", "icon" => "trash", "href" => "papelera.php"],
                ["label" => "Auditoría", "icon" => "history", "href" => "auditoria.php"],
                ["label" => "Salud del sistema", "icon" => "hard-drive", "href" => "salud.php"],
            ],
        ],
    ],

    "SUBDIRECTOR" => [
        "titulo" => "Subdirección",
        "grupos" => [
            "" => [
                ["label" => "Dashboard", "icon" => "home", "href" => "dashboard.php"],
                ["label" => "Calendario", "icon" => "calendar", "href" => "calendario.php"],
                ["label" => "Alumnos", "icon" => "graduation", "href" => "alumnos.php"],
                ["label" => "Comportamiento", "icon" => "smile", "href" => "comportamiento.php"],
                ["label" => "Profesores", "icon" => "briefcase", "href" => "profesores.php"],
                ["label" => "Documentos", "icon" => "file-text", "href" => "documentos.php"],
                ["label" => "Revisión de trabajos", "icon" => "clipboard", "href" => "revision.php"],
                ["label" => "Solicitudes", "icon" => "send", "href" => "solicitudes.php"],
                ["label" => "Aprobación de notas", "icon" => "check-circle", "href" => "notas_aprobacion.php"],
                ["label" => "Comunicados", "icon" => "megaphone", "href" => "comunicados.php"],
                ["label" => "Periodos", "icon" => "calendar", "href" => "periodos.php"],
                ["label" => "Reportes", "icon" => "bar-chart", "href" => "reportes.php"],
                ["label" => "Historial de materiales", "icon" => "clock", "href" => "historial.php"],
                ["label" => "Notificaciones", "icon" => "bell", "href" => "notificaciones.php"],
            ],
        ],
    ],

    "PROFESOR" => [
        "titulo" => "Profesor",
        "grupos" => [
            "Principal" => [
                ["label" => "Dashboard", "icon" => "home", "href" => "dashboard.php"],
                ["label" => "Calendario", "icon" => "calendar", "href" => "calendario.php"],
            ],
            "Planificación" => [
                ["label" => "PCA", "icon" => "book", "href" => "pca.php" . ($sufijoGrado ?? ""), "requiereGrado" => true],
                ["label" => "Unidades", "icon" => "layers", "href" => "unidades.php" . ($sufijoGrado ?? ""), "requiereGrado" => true],
                ["label" => "Sesiones", "icon" => "file-text", "href" => "sesiones.php" . ($sufijoGrado ?? ""), "requiereGrado" => true],
            ],
            "Recursos" => [
                ["label" => "Documentos Institucionales", "icon" => "folder", "href" => "documentos.php" . ($sufijoGrado ?? ""), "requiereGrado" => true],
            ],
            "Seguimiento" => [
                ["label" => "Mi progreso", "icon" => "bar-chart", "href" => "progreso.php"],
                ["label" => "Notas pendientes", "icon" => "clipboard", "href" => "notas_pendientes.php"],
                ["label" => "Mis Alumnos", "icon" => "users", "href" => "alumnos.php"],
                ["label" => "Mis Trabajos", "icon" => "briefcase", "href" => "trabajos.php"],
                ["label" => "Envíos a Subdirección", "icon" => "send", "href" => "envios.php"],
                ["label" => "Historial de materiales", "icon" => "clock", "href" => "historial.php"],
            ],
            "Cuenta" => [
                ["label" => "Notificaciones", "icon" => "bell", "href" => "notificaciones.php"],
                ["label" => "Comunicados", "icon" => "megaphone", "href" => "comunicados.php"],
                ["label" => "Perfil", "icon" => "user", "href" => "perfil.php"],
            ],
        ],
    ],

    "PADRE" => [
        "titulo" => "Padre de familia",
        "grupos" => [
            "Principal" => [
                ["label" => "Dashboard", "icon" => "home", "href" => "dashboard.php" . ($sufijoHijo ?? "")],
            ],
            "Seguimiento" => [
                ["label" => "Boleta de notas", "icon" => "clipboard", "href" => "notas.php" . ($sufijoHijo ?? "")],
                ["label" => "Comportamiento", "icon" => "smile", "href" => "comportamiento.php" . ($sufijoHijo ?? "")],
            ],
            "Trámites" => [
                ["label" => "Preinscripción de matrícula", "icon" => "file-plus", "href" => "preinscripcion.php"],
            ],
            "Mi cuenta" => [
                ["label" => "Cambiar contraseña", "icon" => "user", "href" => "cambiar_password.php"],
            ],
        ],
    ],

];

// Si el menú es el de Profesor y todavía no hay un grado en
// contexto, los ítems que lo requieren apuntan a "Mi panel" (donde
// se elige el grado) en vez de a una página que rechazaría el
// acceso por no traer id_nivel_grado. Se guarda el destino original
// en "activeMatch" ANTES de sobrescribir "href": si no se hiciera
// esto, PCA/Unidades/Sesiones/Documentos terminarían con el mismo
// href que Dashboard ("dashboard.php") y el sidebar los resaltaría
// a los 4 como "activos" al mismo tiempo en vez de solo a Dashboard.
if ($rolMenu === "PROFESOR" && !$sufijoGrado) {
    foreach ($menus["PROFESOR"]["grupos"] as &$grupoItems) {
        foreach ($grupoItems as &$item) {
            if (!empty($item["requiereGrado"])) {
                $item["activeMatch"] = strtok($item["href"], "?#");
                $item["href"] = "dashboard.php";
            }
        }
        unset($item);
    }
    unset($grupoItems);
}

$menu = $menus[$rolMenu] ?? ["titulo" => "Panel", "grupos" => []];

// ------------------------------------------------------------
// Identidad del profesor/usuario para la cabecera del sidebar.
// $profesor ya viene con nombres/apellidos en toda página del
// módulo Profesor (consulta propia de cada archivo); si no existe
// (ADMIN/SUBDIRECTOR, que no definen $profesor), no se muestra.
// ------------------------------------------------------------
$nombreCompletoSidebar = null;
$inicialesSidebar = "?";

if (isset($profesor["nombres"])) {
    $nombreCompletoSidebar = trim($profesor["nombres"] . " " . ($profesor["apellidos"] ?? ""));
    $iniciales = mb_strtoupper(mb_substr($profesor["nombres"], 0, 1))
        . mb_strtoupper(mb_substr($profesor["apellidos"] ?? "", 0, 1));
    if ($iniciales !== "") {
        $inicialesSidebar = $iniciales;
    }
} elseif ($rolActual === "PADRE" && isset($_SESSION["nombres"])) {
    // El módulo Padre no tiene tabla propia (ver migracion_portal_padres.sql:
    // usa directamente usuarios, igual que Subdirector/Admin), así que su
    // identidad sale de la sesión en vez de una consulta como $profesor.
    $nombreCompletoSidebar = trim($_SESSION["nombres"] . " " . ($_SESSION["apellidos"] ?? ""));
    $iniciales = mb_strtoupper(mb_substr($_SESSION["nombres"], 0, 1))
        . mb_strtoupper(mb_substr($_SESSION["apellidos"] ?? "", 0, 1));
    if ($iniciales !== "") {
        $inicialesSidebar = $iniciales;
    }
}

// ------------------------------------------------------------
// Selector de grado (solo Profesor, cuando tiene 2+ grados
// asignados). Reaprovecha $gradosAsignados si la página que
// incluye el sidebar ya lo calculó (Dashboard); si no, se calcula
// aquí con la misma función ya existente, sin tocar BD extra.
// ------------------------------------------------------------
$gradosSidebar = [];

if ($esRolProfesor && isset($conexion)) {

    $idProfesorSidebar = null;
    if (isset($idProfesor)) {
        $idProfesorSidebar = (int) $idProfesor;
    } elseif (isset($profesor["id_profesor"])) {
        $idProfesorSidebar = (int) $profesor["id_profesor"];
    }

    if ($idProfesorSidebar) {
        if (isset($gradosAsignados) && is_array($gradosAsignados)) {
            $gradosSidebar = $gradosAsignados;
        } else {
            if (!function_exists("profesor_grados_asignados")) {
                require_once __DIR__ . "/../config/profesor_grados.php";
            }
            $gradosSidebar = profesor_grados_asignados($conexion, $idProfesorSidebar);
        }
    }

}

// ------------------------------------------------------------
// Tutoría (SOLO Secundaria, opcional, por año escolar actual).
// Reutiliza el mismo $idProfesorSidebar ya resuelto arriba para el
// selector de grado — no se agrega ninguna consulta nueva de
// identidad del profesor. El ítem "Tutoría" solo se agrega al menú
// si hay una asignación REAL para el período académico actual (ver
// profesor_tutoria_actual() en backend/config/profesor_tutoria.php);
// nunca se muestra solo por el nivel del profesor.
// ------------------------------------------------------------
$tutoriaSidebar = null;

if ($esRolProfesor && isset($conexion) && !empty($idProfesorSidebar)) {

    if (!function_exists("profesor_tutoria_actual")) {
        require_once __DIR__ . "/../config/profesor_tutoria.php";
    }

    $tutoriaSidebar = profesor_tutoria_actual($conexion, $idProfesorSidebar);

}

// ------------------------------------------------------------
// Selector de hijo (solo Padre, cuando tiene 2+ hijos vinculados).
// Reaprovecha $hijos si la página que incluye el sidebar ya lo
// calculó (todas las páginas del módulo Padre lo hacen en
// padre_bootstrap.php) — no se agrega ninguna consulta nueva.
// ------------------------------------------------------------
$hijosSidebar = ($rolActual === "PADRE" && isset($hijos) && is_array($hijos)) ? $hijos : [];

// Páginas que ya trabajan "dentro" de un grado: al cambiar el grado
// en el selector, JS reemplaza el id_nivel_grado manteniendo la
// misma página. Desde cualquier otra página (p.ej. el Dashboard),
// cambiar el grado lleva a grado.php de ese nuevo grado.
$paginasPorGrado = ["grado.php", "pca.php", "unidades.php", "sesiones.php", "documentos.php"];
$mantenerPaginaAlCambiarGrado = in_array($currentFile ?? "", $paginasPorGrado, true);

// Ítem de Tutoría: se agrega a $menu (la copia ya resuelta para
// este rol), no a $menus, para no afectar otros roles. Va en su
// propio grupo, después de "Seguimiento" y antes de "Cuenta", para
// dejarlo claramente separado de PCA/Unidades/Sesiones/Documentos
// de los cursos normales — nunca mezclado con ellos.
if ($tutoriaSidebar && isset($menu["grupos"])) {
    $grupoTutoria = ["Tutoría" => [
        ["label" => "Tutoría", "icon" => "graduation", "href" => "tutoria.php"],
    ]];
    $posicionCuenta = array_search("Cuenta", array_keys($menu["grupos"]), true);
    if ($posicionCuenta === false) {
        $menu["grupos"] += $grupoTutoria;
    } else {
        $menu["grupos"] = array_slice($menu["grupos"], 0, $posicionCuenta, true)
            + $grupoTutoria
            + array_slice($menu["grupos"], $posicionCuenta, null, true);
    }
}

?>
<button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menú" aria-expanded="false">
    <?= icon("menu", "icon icon-menu-open") ?>
    <?= icon("x", "icon icon-menu-close") ?>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="panelSidebar">

    <div class="sidebar-top">
        <div class="sidebar-brand">
            <span class="sidebar-brand-icon"><?= icon("building") ?></span>
            <div class="sidebar-label">
                <strong>I.E.P. 88044</strong>
                <span><?= htmlspecialchars($menu["titulo"]) ?></span>
            </div>
        </div>
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" aria-label="Contraer menú" aria-pressed="false" title="Contraer menú">
            <?= icon("chevron-left") ?>
        </button>
    </div>

    <?php if ($nombreCompletoSidebar): ?>
        <div class="sidebar-identity" title="<?= htmlspecialchars($nombreCompletoSidebar) ?>">
            <span class="sidebar-avatar"><?= htmlspecialchars($inicialesSidebar) ?></span>
            <div class="sidebar-label sidebar-identity-text">
                <strong><?= htmlspecialchars($nombreCompletoSidebar) ?></strong>
                <span><?= htmlspecialchars($rolEtiqueta) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (count($gradosSidebar) === 1): $gradoUnico = $gradosSidebar[0]; ?>
        <div class="sidebar-context sidebar-label" title="Grado actual">
            <span class="nav-icon"><?= icon("backpack") ?></span>
            <span><?= htmlspecialchars(nivel_grado_label($gradoUnico)) ?></span>
        </div>
    <?php elseif (count($gradosSidebar) > 1): ?>
        <div class="sidebar-context sidebar-context-select sidebar-label">
            <span class="nav-icon"><?= icon("backpack") ?></span>
            <select
                id="sidebarGradoSelector"
                aria-label="Cambiar de grado"
                data-mantener-pagina="<?= $mantenerPaginaAlCambiarGrado ? "1" : "0" ?>"
            >
                <?php foreach ($gradosSidebar as $ng): ?>
                    <option
                        value="<?= (int) $ng["id_nivel_grado"] ?>"
                        <?= isset($idNivelGradoSidebar) && (int) $idNivelGradoSidebar === (int) $ng["id_nivel_grado"] ? "selected" : "" ?>
                    ><?= htmlspecialchars(nivel_grado_label($ng)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php elseif (isset($etiqueta) && isset($idNivelGradoSidebar) && (int) $idNivelGradoSidebar > 0): ?>
        <div class="sidebar-context sidebar-label" title="Grado actual">
            <span class="nav-icon"><?= icon("backpack") ?></span>
            <span><?= htmlspecialchars($etiqueta) ?></span>
        </div>
    <?php endif; ?>

    <?php if (count($hijosSidebar) === 1): $hijoUnico = $hijosSidebar[0]; ?>
        <div class="sidebar-context sidebar-label" title="Hijo(a)">
            <span class="nav-icon"><?= icon("smile") ?></span>
            <span><?= htmlspecialchars(trim($hijoUnico["nombres"] . " " . $hijoUnico["apellidos"])) ?></span>
        </div>
    <?php elseif (count($hijosSidebar) > 1): ?>
        <div class="sidebar-context sidebar-context-select sidebar-label">
            <span class="nav-icon"><?= icon("smile") ?></span>
            <select
                id="sidebarHijoSelector"
                aria-label="Cambiar de hijo(a)"
            >
                <?php foreach ($hijosSidebar as $h): ?>
                    <option
                        value="<?= (int) $h["id_alumno"] ?>"
                        <?= isset($idAlumnoActivo) && (int) $idAlumnoActivo === (int) $h["id_alumno"] ? "selected" : "" ?>
                    ><?= htmlspecialchars(trim($h["nombres"] . " " . $h["apellidos"])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if ($notifHref): ?>
        <a href="<?= htmlspecialchars($notifHref) ?>" class="sidebar-notif<?= $notifNoLeidas > 0 ? " has-unread" : "" ?>">
            <span class="nav-icon"><?= icon("bell") ?></span>
            <span class="sidebar-label">Notificaciones</span>
            <?php if ($notifNoLeidas > 0): ?>
                <span class="notif-count"><?= $notifNoLeidas ?></span>
            <?php endif; ?>
        </a>
    <?php else: ?>
        <div class="sidebar-notif sidebar-notif-static" title="Notificaciones no leídas">
            <span class="nav-icon"><?= icon("bell") ?></span>
            <span class="sidebar-label">Notificaciones</span>
            <?php if ($notifNoLeidas > 0): ?>
                <span class="notif-count"><?= $notifNoLeidas ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <?php foreach ($menu["grupos"] as $tituloGrupo => $items): ?>

            <?php
                // Grupos con título son colapsables (el botón guarda/lee su
                // estado en localStorage, ver js/panel.js) — EXCEPTO
                // "Principal": ese debe quedarse fijo, como enlace normal
                // (Dashboard siempre visible, sin flecha ni clic). El
                // grupo sin título ("" — usado por ADMIN y SUBDIRECTOR, un
                // solo menú plano) tampoco se envuelve en botón.
                $gruposNoColapsables = ["Principal"];
                $grupoSlug = ($tituloGrupo !== "" && !in_array($tituloGrupo, $gruposNoColapsables, true))
                    ? "grp-" . preg_replace('/[^a-z0-9]+/', "-", strtolower($tituloGrupo))
                    : null;
            ?>

            <?php if ($tituloGrupo !== "" && !$grupoSlug): ?>
                <p class="sidebar-group-title sidebar-label"><?= htmlspecialchars($tituloGrupo) ?></p>
            <?php endif; ?>

            <?php if ($grupoSlug): ?>
                <div
                    role="button"
                    tabindex="0"
                    class="sidebar-group-title sidebar-label sidebar-group-toggle"
                    data-sidebar-group="<?= htmlspecialchars($grupoSlug) ?>"
                    aria-expanded="true"
                >
                    <span><?= htmlspecialchars($tituloGrupo) ?></span>
                    <span class="sidebar-group-caret"><?= icon("chevron-down") ?></span>
                </div>
            <?php endif; ?>

            <div class="sidebar-group-items"<?= $grupoSlug ? ' data-sidebar-group="' . htmlspecialchars($grupoSlug) . '"' : "" ?>>

            <?php foreach ($items as $item): ?>

                <?php if (!empty($item["children"])): ?>

                    <?php
                        $abierto = false;
                        foreach ($item["children"] as $child) {
                            if ($child["href"] === $currentFile) { $abierto = true; }
                        }
                    ?>

                    <div class="nav-parent<?= $abierto ? " open" : "" ?>">
                        <button type="button" class="nav-link nav-parent-toggle">
                            <span class="nav-icon"><?= icon($item["icon"]) ?></span>
                            <span class="sidebar-label"><?= htmlspecialchars($item["label"]) ?></span>
                            <span class="nav-caret sidebar-label"><?= icon("chevron-down") ?></span>
                        </button>
                        <div class="nav-submenu">
                            <?php foreach ($item["children"] as $child): ?>
                                <a href="<?= htmlspecialchars($child["href"]) ?>" class="nav-sublink<?= $child["href"] === $currentFile ? " active" : "" ?>">
                                    <?= htmlspecialchars($child["label"]) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else: ?>

                    <?php $hrefBase = $item["activeMatch"] ?? strtok($item["href"], "?#"); ?>
                    <a
                        href="<?= htmlspecialchars($item["href"]) ?>"
                        class="nav-link<?= $hrefBase === $currentFile && strpos($item["href"], "#") === false ? " active" : "" ?>"
                        title="<?= htmlspecialchars($item["label"]) ?>"
                    >
                        <span class="nav-icon"><?= icon($item["icon"]) ?></span>
                        <span class="sidebar-label"><?= htmlspecialchars($item["label"]) ?></span>
                    </a>

                <?php endif; ?>

            <?php endforeach; ?>

            </div>

        <?php endforeach; ?>
    </nav>

    <a href="../backend/auth/logout.php" class="sidebar-logout" title="Cerrar sesión">
        <span class="nav-icon"><?= icon("log-out") ?></span>
        <span class="sidebar-label">Cerrar sesión</span>
    </a>

</aside>
