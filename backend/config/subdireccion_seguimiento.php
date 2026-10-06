<?php

// ==========================================================
// Mejoras de Subdirección: recordatorios directos, semáforo de
// cumplimiento, bitácora de observaciones por profesor, y
// confirmación de lectura de Comunicados.
// ==========================================================

if (!function_exists("notificar_crear")) {
    require_once __DIR__ . "/notificaciones.php";
}

if (!function_exists("materiales_color_progreso")) {
    require_once __DIR__ . "/materiales_cursos.php";
}

// ----------------------------------------------------------
// 1) RECORDATORIO DIRECTO A UN PROFESOR
//
// Reutiliza la tabla `notificaciones` (misma que ya usa todo el
// sistema de avisos de vencimiento) — no hay tabla nueva para esto.
// ----------------------------------------------------------

/**
 * Envía un recordatorio puntual al profesor (aparece en su campana
 * de notificaciones, igual que un aviso de vencimiento). $urlDestino
 * es relativa a la carpeta profesor/, ej. "dashboard.php".
 */
function subdireccion_enviar_recordatorio(PDO $conexion, int $idUsuarioProfesor, string $mensaje, ?string $urlDestino = null): void {
    notificar_crear($conexion, $idUsuarioProfesor, "RECORDATORIO_SUBDIRECCION", $mensaje, $urlDestino);
}

// ----------------------------------------------------------
// 2) SEMÁFORO DE CUMPLIMIENTO
//
// No hay tabla nueva: se calcula sobre los mismos datos que ya trae
// subdirector/reportes.php (avance de PCA/Unidades/Sesiones por
// profesor y grado). Esta función solo centraliza el criterio de
// color para que Reportes y el Semáforo nunca queden desalineados.
// ----------------------------------------------------------

/**
 * Color del semáforo para una fila profesor×grado, según el PEOR
 * (más bajo) de sus 3 porcentajes — si va mal en Sesiones aunque
 * PCA esté al 100%, la fila se marca en rojo/ámbar igual, porque el
 * objetivo es que Subdirección note lo que falta, no lo que ya está bien.
 */
function semaforo_color_fila(int $pctPca, int $pctUnidades, int $pctSesiones): string {
    return materiales_color_progreso(min($pctPca, $pctUnidades, $pctSesiones));
}

// ----------------------------------------------------------
// 3) BITÁCORA DE OBSERVACIONES POR PROFESOR
// ----------------------------------------------------------

function observacion_crear(PDO $conexion, int $idProfesor, int $idUsuarioAutor, string $contenido): void {
    $stmt = $conexion->prepare("
        INSERT INTO observaciones_profesor (id_profesor, id_usuario_autor, contenido)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$idProfesor, $idUsuarioAutor, $contenido]);
}

function observacion_eliminar(PDO $conexion, int $idObservacion): void {
    $stmt = $conexion->prepare("DELETE FROM observaciones_profesor WHERE id_observacion = ?");
    $stmt->execute([$idObservacion]);
}

/** Bitácora completa de un profesor, más reciente primero. */
function observaciones_listar(PDO $conexion, int $idProfesor): array {
    $stmt = $conexion->prepare("
        SELECT o.id_observacion, o.contenido, o.creado_en, u.nombres, u.apellidos
        FROM observaciones_profesor o
        INNER JOIN usuarios u ON u.id_usuario = o.id_usuario_autor
        WHERE o.id_profesor = ?
        ORDER BY o.creado_en DESC
    ");
    $stmt->execute([$idProfesor]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------
// 4) CONFIRMACIÓN DE LECTURA DE COMUNICADOS
// ----------------------------------------------------------

/**
 * Marca un comunicado como leído por este usuario. INSERT IGNORE:
 * si ya lo había marcado antes, no hace nada (no pisa leido_en con
 * una lectura repetida) y nunca lanza error por duplicado.
 */
function comunicado_marcar_leido(PDO $conexion, int $idComunicado, int $idUsuario): void {
    $stmt = $conexion->prepare("
        INSERT IGNORE INTO comunicados_lecturas (id_comunicado, id_usuario)
        VALUES (?, ?)
    ");
    $stmt->execute([$idComunicado, $idUsuario]);
}

/** Cuántos usuarios distintos han leído cada comunicado (id_comunicado => total). */
function comunicados_conteo_lecturas(PDO $conexion): array {
    $stmt = $conexion->query("
        SELECT id_comunicado, COUNT(*) AS total
        FROM comunicados_lecturas
        GROUP BY id_comunicado
    ");
    $conteo = [];
    foreach ($stmt->fetchAll() as $fila) {
        $conteo[(int) $fila["id_comunicado"]] = (int) $fila["total"];
    }
    return $conteo;
}

/** Detalle de quién leyó un comunicado puntual (para el desplegable "Ver quiénes"). */
function comunicado_lectores(PDO $conexion, int $idComunicado): array {
    $stmt = $conexion->prepare("
        SELECT u.nombres, u.apellidos, cl.leido_en
        FROM comunicados_lecturas cl
        INNER JOIN usuarios u ON u.id_usuario = cl.id_usuario
        WHERE cl.id_comunicado = ?
        ORDER BY cl.leido_en ASC
    ");
    $stmt->execute([$idComunicado]);
    return $stmt->fetchAll();
}

/** Profesores con cuenta ACTIVA — el "total" contra el que se mide "X/Y lo han visto". */
function comunicados_total_profesores_activos(PDO $conexion): int {
    return (int) $conexion->query("
        SELECT COUNT(*)
        FROM profesores p
        INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
        WHERE u.estado = 'ACTIVO'
    ")->fetchColumn();
}
