<?php

// ==========================================
// Helpers de notificaciones — genérico y reutilizable.
// Requiere $conexion (PDO) ya definido, ver database.php.
// ==========================================

function notificar_crear(PDO $conexion, int $id_usuario, string $tipo, string $mensaje, ?string $url = null): void {

    $stmt = $conexion->prepare("
        INSERT INTO notificaciones (id_usuario, tipo, mensaje, url)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([$id_usuario, $tipo, $mensaje, $url]);

}

function notificaciones_no_leidas(PDO $conexion, int $id_usuario): int {

    $stmt = $conexion->prepare("
        SELECT COUNT(*) AS total
        FROM notificaciones
        WHERE id_usuario = ? AND leido = 0
    ");

    $stmt->execute([$id_usuario]);

    return (int) $stmt->fetch()["total"];

}

function notificaciones_listar(PDO $conexion, int $id_usuario, int $limite = 10): array {

    $stmt = $conexion->prepare("
        SELECT id_notificacion, tipo, mensaje, url, leido, creado_en
        FROM notificaciones
        WHERE id_usuario = ?
        ORDER BY creado_en DESC
        LIMIT " . (int) $limite . "
    ");

    $stmt->execute([$id_usuario]);

    return $stmt->fetchAll();

}

function notificaciones_marcar_leidas(PDO $conexion, int $id_usuario): void {

    $stmt = $conexion->prepare("
        UPDATE notificaciones
        SET leido = 1
        WHERE id_usuario = ? AND leido = 0
    ");

    $stmt->execute([$id_usuario]);

}

/** Marca UNA notificación como leída, solo si pertenece a ese usuario. Devuelve su url (o null). */
function notificacion_marcar_leida(PDO $conexion, int $id_usuario, int $id_notificacion): ?string {

    $stmt = $conexion->prepare("SELECT url FROM notificaciones WHERE id_notificacion = ? AND id_usuario = ?");
    $stmt->execute([$id_notificacion, $id_usuario]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    $conexion->prepare("UPDATE notificaciones SET leido = 1 WHERE id_notificacion = ? AND id_usuario = ?")
        ->execute([$id_notificacion, $id_usuario]);

    return $fila["url"];

}

// ==========================================
// AUTOMATIZACIONES (MEJORA)
//
// Ambas funciones de abajo reutilizan la tabla `notificaciones` y
// notificar_crear() de arriba — no se crea ninguna tabla nueva. Se
// generan "al vuelo" (cuando el profesor abre su panel, o justo
// después de subir un archivo), no por un cron: este proyecto no
// tiene tareas programadas, así que generarlas en el momento en que
// alguien realmente usa el sistema es el mismo patrón que ya usa
// subdirector/revision.php para notificar al profesor.
//
// Requieren funciones de profesor_grados.php y materiales_cursos.php
// (nivel_grado_label, profesor_cursos_por_grado, pca_total_unidades,
// materiales_total_unidades, materiales_total_semanas). Se cargan
// aquí para que notificaciones.php funcione solo con requerir
// database.php antes, sin obligar a cada página a recordar el orden.
// ==========================================

require_once __DIR__ . "/profesor_grados.php";
require_once __DIR__ . "/materiales_cursos.php";

/**
 * CASO A — Aviso al profesor cuando un trabajo (tarea que él mismo
 * asignó a sus alumnos) está por vencer.
 *
 * No existe en la BD ninguna fecha de "corte de bimestre": la tabla
 * `periodos_academicos` solo tiene `id_periodo` y `nombre` (ver
 * backend/config/colegio_ie88044.sql y subdirector/periodos.php),
 * sin ninguna fecha de inicio/fin. La única fecha académica real que
 * existe en el proyecto es `trabajos.fecha_limite` (la fecha límite
 * que el propio profesor define al crear un trabajo desde
 * profesor/trabajos.php), así que es la que se usa aquí — no se
 * inventa ninguna fecha nueva ni se agrega ninguna columna.
 *
 * Identifica el curso y el trabajo pendiente en el mensaje. Evita
 * duplicados: antes de insertar revisa si ya existe una notificación
 * de ESE mismo trabajo (mismo tipo + misma url) para ese usuario.
 */
function notificaciones_generar_avisos_vencimiento(PDO $conexion, int $idProfesor, int $idUsuario, int $diasAviso = 3): void {

    $stmt = $conexion->prepare("
        SELECT t.id_trabajo, t.titulo, t.fecha_limite, c.nombre AS curso
        FROM trabajos t
        INNER JOIN cursos c ON c.id_curso = t.id_curso
        WHERE t.id_profesor = ?
          AND t.estado = 'PENDIENTE'
          AND t.fecha_limite BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ");
    $stmt->execute([$idProfesor, $diasAviso]);

    foreach ($stmt->fetchAll() as $trabajo) {

        $url = "../profesor/trabajos.php?editar=" . (int) $trabajo["id_trabajo"];

        $existe = $conexion->prepare("
            SELECT id_notificacion FROM notificaciones
            WHERE id_usuario = ? AND tipo = 'TRABAJO_POR_VENCER' AND url = ?
            LIMIT 1
        ");
        $existe->execute([$idUsuario, $url]);

        if ($existe->fetch()) {
            continue; // ya se avisó de este trabajo, no se duplica
        }

        $diasRestantes = (int) floor((strtotime($trabajo["fecha_limite"]) - strtotime(date("Y-m-d"))) / 86400);
        $cuando = $diasRestantes <= 0 ? "hoy" : ($diasRestantes === 1 ? "mañana" : "en {$diasRestantes} días");

        notificar_crear(
            $conexion,
            $idUsuario,
            "TRABAJO_POR_VENCER",
            "El trabajo \"" . $trabajo["titulo"] . "\" de " . $trabajo["curso"] . " vence {$cuando}.",
            $url
        );

    }

}

/**
 * CASO B — Aviso a Subdirección cuando un profesor completa el 100%
 * de un módulo (PCA, UNIDADES o SESIONES) en un nivel+grado.
 *
 * Usa exactamente el mismo criterio de avance que ya calcula
 * profesor/dashboard.php (mismas tablas, mismo "total" = cursos
 * reales del profesor en ese grado vía profesor_curso_grado), para
 * que la notificación sea consistente con lo que el profesor ve en
 * su propio panel. Solo lectura: no modifica ninguna tabla de
 * materiales.
 *
 * $modulo: "PCA" | "UNIDADES" | "SESIONES"
 * $nivelGrado: la fila de niveles_grados ya verificada (la que
 * devuelve profesor_verificar_grado()), con al menos nivel/grado/nombre.
 *
 * Evita duplicados: la url de la notificación codifica
 * profesor+grado+módulo, así que ese evento nunca se notifica dos
 * veces a Subdirección (si el profesor borra y vuelve a completar el
 * módulo, no se vuelve a avisar; se considera un único evento).
 */
function notificaciones_verificar_modulo_completo(PDO $conexion, int $idProfesor, int $idNivelGrado, array $nivelGrado, string $modulo): void {

    $nivel = $nivelGrado["nivel"];
    $totalCursos = count(profesor_cursos_por_grado($conexion, $idProfesor, $idNivelGrado));
    $completo = false;

    if ($modulo === "PCA") {

        $totalPca = pca_total_unidades($nivel);

        $stmt = $conexion->prepare("
            SELECT COUNT(DISTINCT unidad) FROM profesor_pca_archivos
            WHERE id_profesor = ? AND id_nivel_grado = ?
        ");
        $stmt->execute([$idProfesor, $idNivelGrado]);

        $completo = $totalPca > 0 && (int) $stmt->fetchColumn() >= $totalPca;

    } elseif ($modulo === "UNIDADES") {

        if ($totalCursos > 0) {

            $stmt = $conexion->prepare("
                SELECT COUNT(DISTINCT curso) FROM profesor_unidad_archivos
                WHERE id_profesor = ? AND id_nivel_grado = ? AND unidad = 1 AND semana = 1
            ");
            $stmt->execute([$idProfesor, $idNivelGrado]);

            $completo = (int) $stmt->fetchColumn() >= $totalCursos;

        }

    } elseif ($modulo === "SESIONES") {

        if ($totalCursos > 0) {

            $totalSesionesGrado = materiales_total_unidades($nivel) * materiales_total_semanas($nivel);

            $stmt = $conexion->prepare("
                SELECT COUNT(DISTINCT curso) AS cursos_cubiertos
                FROM profesor_sesion_archivos
                WHERE id_profesor = ? AND id_nivel_grado = ?
                GROUP BY unidad, semana
            ");
            $stmt->execute([$idProfesor, $idNivelGrado]);

            $sesionesCompletas = 0;
            foreach ($stmt->fetchAll() as $fila) {
                if ((int) $fila["cursos_cubiertos"] >= $totalCursos) {
                    $sesionesCompletas++;
                }
            }

            $completo = $totalSesionesGrado > 0 && $sesionesCompletas >= $totalSesionesGrado;

        }

    } else {

        return; // módulo desconocido, no hace nada

    }

    if (!$completo) {
        return;
    }

    $stmtProfesor = $conexion->prepare("
        SELECT u.nombres, u.apellidos FROM usuarios u
        INNER JOIN profesores p ON p.id_usuario = u.id_usuario
        WHERE p.id_profesor = ?
    ");
    $stmtProfesor->execute([$idProfesor]);
    $datosProfesor = $stmtProfesor->fetch();

    if (!$datosProfesor) {
        return;
    }

    $nombreModulo = match ($modulo) {
        "PCA" => "PCA",
        "UNIDADES" => "Unidades",
        "SESIONES" => "Sesiones",
        default => $modulo,
    };

    $mensaje = trim($datosProfesor["nombres"] . " " . $datosProfesor["apellidos"])
        . " completó el 100% de " . $nombreModulo . " en " . nivel_grado_label($nivelGrado) . ".";

    // Este aviso llega a Subdirección Y a Administrador. Cada rol
    // tiene su propia página de reportes (subdirector/reportes.php
    // y admin/reportes.php), así que la url -y con ella la clave que
    // evita duplicados, "profesor+grado+módulo+rol"- se arma por
    // separado para cada uno: notificar a un rol nunca bloquea que
    // se notifique al otro, y cada quien recibe un enlace que sí
    // puede abrir (un ADMIN no tiene acceso a subdirector/reportes.php
    // y viceversa).
    $destinatariosPorRol = [
        "SUBDIRECTOR" => "../subdirector/reportes.php?id_profesor={$idProfesor}&id_nivel_grado={$idNivelGrado}&modulo={$modulo}",
        "ADMIN"       => "../admin/reportes.php?id_profesor={$idProfesor}&id_nivel_grado={$idNivelGrado}&modulo={$modulo}",
    ];

    foreach ($destinatariosPorRol as $rol => $url) {

        $yaExiste = $conexion->prepare("
            SELECT id_notificacion FROM notificaciones
            WHERE tipo = 'MODULO_COMPLETADO' AND url = ?
            LIMIT 1
        ");
        $yaExiste->execute([$url]);

        if ($yaExiste->fetch()) {
            continue; // este profesor+grado+módulo ya fue notificado a este rol
        }

        $usuariosDelRol = $conexion->prepare("
            SELECT u.id_usuario FROM usuarios u
            INNER JOIN roles r ON r.id_rol = u.id_rol
            WHERE r.nombre = ? AND u.estado = 'ACTIVO'
        ");
        $usuariosDelRol->execute([$rol]);

        foreach ($usuariosDelRol->fetchAll() as $fila) {
            notificar_crear($conexion, (int) $fila["id_usuario"], "MODULO_COMPLETADO", $mensaje, $url);
        }

    }

}

/**
 * CASO C — Recordatorio a Administrador de solicitudes de
 * preinscripción de matrícula (tabla solicitudes_matricula) que
 * llevan varios días en PENDIENTE sin que nadie las apruebe ni
 * rechace. Antes esas solicitudes se podían quedar ahí para
 * siempre sin que el Admin se enterara.
 *
 * Deduplica por id_solicitud (vía la url), así que cada solicitud
 * pendiente avisa una sola vez por Administrador, no una vez por
 * día que pase el umbral.
 */
function notificaciones_generar_avisos_solicitudes_pendientes(PDO $conexion, int $diasEspera = 2): void {

    $stmt = $conexion->prepare("
        SELECT id_solicitud_matricula, alumno_nombres, alumno_apellidos, creado_en
        FROM solicitudes_matricula
        WHERE estado = 'PENDIENTE'
          AND creado_en <= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$diasEspera]);
    $solicitudes = $stmt->fetchAll();

    if (count($solicitudes) === 0) {
        return;
    }

    $usuariosAdmin = $conexion->query("
        SELECT u.id_usuario FROM usuarios u
        INNER JOIN roles r ON r.id_rol = u.id_rol
        WHERE r.nombre = 'ADMIN' AND u.estado = 'ACTIVO'
    ")->fetchAll();

    foreach ($solicitudes as $s) {

        $url = "../admin/solicitudes_matricula.php?id_solicitud=" . (int) $s["id_solicitud_matricula"];
        $diasEsperando = (int) floor((strtotime(date("Y-m-d")) - strtotime($s["creado_en"])) / 86400);

        $mensaje = $s["alumno_nombres"] . " " . $s["alumno_apellidos"]
            . ": su solicitud de matrícula lleva {$diasEsperando} día(s) esperando revisión.";

        foreach ($usuariosAdmin as $admin) {

            $yaExiste = $conexion->prepare("
                SELECT id_notificacion FROM notificaciones
                WHERE id_usuario = ? AND tipo = 'SOLICITUD_MATRICULA_PENDIENTE' AND url = ?
                LIMIT 1
            ");
            $yaExiste->execute([$admin["id_usuario"], $url]);

            if ($yaExiste->fetch()) {
                continue; // esta solicitud ya se avisó a este admin
            }

            notificar_crear($conexion, (int) $admin["id_usuario"], "SOLICITUD_MATRICULA_PENDIENTE", $mensaje, $url);

        }

    }

}
