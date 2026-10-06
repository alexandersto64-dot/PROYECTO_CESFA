<?php

// ==========================================
// Funciones compartidas del módulo Padre.
//
// Mismo criterio que profesor_grados.php: centraliza aquí las
// consultas que se repiten en varias páginas (dashboard.php,
// notas.php, comportamiento.php, preinscripcion.php) para no
// duplicarlas. No crea ninguna tabla nueva — usa exactamente las
// que trae migracion_portal_padres.sql (padres_alumnos, notas,
// comportamiento, solicitudes_matricula) más las ya existentes
// (alumnos, matriculas, grados_secciones, periodos_academicos,
// cursos, usuarios).
// ==========================================


/**
 * Hijos vinculados a un usuario PADRE (vía padres_alumnos), con su
 * matrícula ACTIVA más reciente si la tiene. Un alumno sin matrícula
 * activa igual aparece (con los campos de matrícula en null) para
 * que el padre lo vea, aunque de momento no tenga notas/comportamiento
 * que consultar.
 */
function padre_hijos(PDO $conexion, int $idUsuarioPadre): array
{
    $sql = "
        SELECT
            a.id_alumno, a.nombres, a.apellidos, a.dni, a.estado,
            m.id_matricula, m.id_periodo,
            gs.id_grado_seccion, gs.nivel, gs.grado, gs.seccion, gs.nombre AS grado_nombre,
            per.nombre AS periodo_nombre

        FROM padres_alumnos pa

        INNER JOIN alumnos a
            ON a.id_alumno = pa.id_alumno

        LEFT JOIN matriculas m
            ON m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'

        LEFT JOIN grados_secciones gs
            ON gs.id_grado_seccion = m.id_grado_seccion

        LEFT JOIN periodos_academicos per
            ON per.id_periodo = m.id_periodo

        WHERE pa.id_usuario_padre = ?

        ORDER BY a.apellidos, a.nombres
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([$idUsuarioPadre]);

    return $stmt->fetchAll();
}


/**
 * Comprueba que el alumno realmente sea hijo del padre en sesión,
 * antes de mostrarle notas/comportamiento de nadie más. Nunca se
 * confía solo en el id_alumno que llega por GET.
 */
function padre_verificar_hijo(PDO $conexion, int $idUsuarioPadre, int $idAlumno): bool
{
    $stmt = $conexion->prepare("
        SELECT 1 FROM padres_alumnos
        WHERE id_usuario_padre = ? AND id_alumno = ?
        LIMIT 1
    ");
    $stmt->execute([$idUsuarioPadre, $idAlumno]);

    return (bool) $stmt->fetchColumn();
}


/**
 * Notas de un alumno para un periodo dado, agrupadas por curso y
 * bimestre. Si no se indica periodo, usa el de su matrícula activa
 * (si tiene). Devuelve las filas ya con el nombre del curso.
 */
function padre_notas(PDO $conexion, int $idAlumno, ?int $idPeriodo = null): array
{
    $sql = "
        SELECT
            n.id_nota, n.id_curso, c.nombre AS curso_nombre,
            n.bimestre, n.nota_vigesimal, n.nota_literal,
            n.actualizado_en

        FROM notas n

        INNER JOIN cursos c
            ON c.id_curso = n.id_curso

        WHERE n.id_alumno = ?
    ";

    $params = [$idAlumno];

    if ($idPeriodo !== null) {
        $sql .= " AND n.id_periodo = ?";
        $params[] = $idPeriodo;
    }

    $sql .= " ORDER BY n.bimestre, c.nombre";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}


/**
 * Notas por competencia de una nota final ya registrada (Etapa 2,
 * módulo de importación desde PDF). Devuelve un array vacío para
 * notas antiguas que no tienen ninguna competencia cargada — el
 * llamador debe tratar eso como "no hay desglose", no como error.
 */
function padre_notas_competencias(PDO $conexion, int $idNota): array
{
    $stmt = $conexion->prepare("
        SELECT nc.nota_vigesimal, nc.nota_literal, comp.nombre AS competencia_nombre, comp.orden
        FROM notas_competencias nc
        INNER JOIN competencias comp ON comp.id_competencia = nc.id_competencia
        WHERE nc.id_nota = ?
        ORDER BY comp.orden, comp.nombre
    ");
    $stmt->execute([$idNota]);
    return $stmt->fetchAll();
}


/**
 * Último bimestre con al menos una nota registrada, para el
 * resumen del Dashboard (evita mostrar de entrada un bimestre
 * vacío cuando ya hay varios cargados).
 */
function padre_ultimo_bimestre_con_notas(array $notas): ?int
{
    if (count($notas) === 0) {
        return null;
    }

    return (int) max(array_column($notas, "bimestre"));
}


/**
 * Registros de comportamiento de un alumno, más recientes primero.
 * $limite = null trae todos (para comportamiento.php); con número
 * trae solo esa cantidad (para el adelanto del Dashboard).
 */
function padre_comportamiento(PDO $conexion, int $idAlumno, ?int $limite = null): array
{
    $sql = "
        SELECT
            cp.id_comportamiento, cp.tipo, cp.descripcion, cp.creado_en,
            u.nombres AS registrado_por_nombres, u.apellidos AS registrado_por_apellidos,
            per.nombre AS periodo_nombre

        FROM comportamiento cp

        INNER JOIN usuarios u
            ON u.id_usuario = cp.id_usuario_registro

        INNER JOIN periodos_academicos per
            ON per.id_periodo = cp.id_periodo

        WHERE cp.id_alumno = ?

        ORDER BY cp.creado_en DESC
    ";

    if ($limite !== null) {
        $sql .= " LIMIT " . (int) $limite;
    }

    $stmt = $conexion->prepare($sql);
    $stmt->execute([$idAlumno]);

    return $stmt->fetchAll();
}


/**
 * Conteo de comportamiento POSITIVO/NEGATIVO/NEUTRO de un alumno,
 * para las tarjetas de resumen del Dashboard. Una sola consulta
 * agrupada en vez de tres.
 */
function padre_comportamiento_conteo(PDO $conexion, int $idAlumno): array
{
    $conteo = ["POSITIVO" => 0, "NEGATIVO" => 0, "NEUTRO" => 0];

    $stmt = $conexion->prepare("
        SELECT tipo, COUNT(*) AS total
        FROM comportamiento
        WHERE id_alumno = ?
        GROUP BY tipo
    ");
    $stmt->execute([$idAlumno]);

    foreach ($stmt->fetchAll() as $fila) {
        $conteo[$fila["tipo"]] = (int) $fila["total"];
    }

    return $conteo;
}


/**
 * Solicitudes de preinscripción de matrícula hechas por este padre
 * (tabla solicitudes_matricula), más recientes primero.
 */
function padre_solicitudes_matricula(PDO $conexion, int $idUsuarioPadre): array
{
    $stmt = $conexion->prepare("
        SELECT
            sm.id_solicitud_matricula, sm.alumno_nombres, sm.alumno_apellidos,
            sm.alumno_dni, sm.estado, sm.observacion_admin, sm.creado_en,
            gs.nombre AS grado_nombre, gs.nivel,
            per.nombre AS periodo_nombre

        FROM solicitudes_matricula sm

        INNER JOIN grados_secciones gs
            ON gs.id_grado_seccion = sm.id_grado_seccion_deseado

        INNER JOIN periodos_academicos per
            ON per.id_periodo = sm.id_periodo

        WHERE sm.id_usuario_padre = ?

        ORDER BY sm.creado_en DESC
    ");
    $stmt->execute([$idUsuarioPadre]);

    return $stmt->fetchAll();
}


/**
 * Grados/secciones disponibles para el formulario de preinscripción,
 * agrupados por nivel (mismo dato que ya usa admin/matriculas.php).
 */
function padre_grados_secciones_disponibles(PDO $conexion): array
{
    return $conexion
        ->query("SELECT id_grado_seccion, nivel, grado, seccion, nombre FROM grados_secciones ORDER BY nivel, grado, seccion")
        ->fetchAll();
}


/**
 * Periodo académico "actual" para el formulario de preinscripción:
 * el de mayor id (el más reciente creado), mismo criterio simple
 * que ya usa el resto del panel donde no hay una bandera explícita
 * de "periodo activo" en la tabla.
 */
function padre_periodo_actual(PDO $conexion): ?array
{
    $fila = $conexion
        ->query("SELECT id_periodo, nombre FROM periodos_academicos ORDER BY id_periodo DESC LIMIT 1")
        ->fetch();

    return $fila ?: null;
}


// ==========================================
// Lado ADMIN: revisar solicitudes de preinscripción (AUTOMATIZACIÓN).
// Hasta ahora el padre podía enviar una solicitud (arriba) pero no
// existía ninguna pantalla para que el Admin la vea ni la resuelva
// — se quedaba en PENDIENTE para siempre. Estas funciones son las
// que usa admin/solicitudes_matricula.php.
// ==========================================

/**
 * Solicitudes pendientes, con los datos del padre que las envió,
 * para la bandeja del Admin.
 */
function solicitudes_matricula_pendientes(PDO $conexion): array
{
    return $conexion->query("
        SELECT
            sm.id_solicitud_matricula, sm.alumno_nombres, sm.alumno_apellidos,
            sm.alumno_dni, sm.alumno_fecha_nacimiento, sm.id_periodo, sm.creado_en,
            gs.id_grado_seccion, gs.nombre AS grado_nombre, gs.nivel,
            per.nombre AS periodo_nombre,
            u.id_usuario AS id_usuario_padre, u.nombres AS padre_nombres, u.apellidos AS padre_apellidos, u.correo AS padre_correo
        FROM solicitudes_matricula sm
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = sm.id_grado_seccion_deseado
        INNER JOIN periodos_academicos per ON per.id_periodo = sm.id_periodo
        INNER JOIN usuarios u ON u.id_usuario = sm.id_usuario_padre
        WHERE sm.estado = 'PENDIENTE'
        ORDER BY sm.creado_en ASC
    ")->fetchAll();
}

/**
 * Aprueba la solicitud: crea al alumno (si no existe ya uno con ese
 * DNI — por ejemplo, un hermano que se re-matricula usa al mismo
 * alumno) + su matrícula ACTIVA, vincula al padre con el alumno en
 * padres_alumnos (para que ya pueda ver su boleta sin que el Admin
 * tenga que hacerlo aparte en admin/padres.php) y marca la solicitud
 * como APROBADA. Todo en una transacción: o se hace todo, o nada.
 */
function solicitud_matricula_aprobar(PDO $conexion, int $idSolicitud, int $idUsuarioAdmin): array
{
    $stmt = $conexion->prepare("SELECT * FROM solicitudes_matricula WHERE id_solicitud_matricula = ? AND estado = 'PENDIENTE'");
    $stmt->execute([$idSolicitud]);
    $solicitud = $stmt->fetch();

    if (!$solicitud) {
        throw new RuntimeException("La solicitud no existe o ya fue resuelta.");
    }

    $conexion->beginTransaction();

    try {

        $stmtAlumno = $conexion->prepare("SELECT id_alumno FROM alumnos WHERE dni = ?");
        $stmtAlumno->execute([$solicitud["alumno_dni"]]);
        $alumnoExistente = $stmtAlumno->fetch();

        if ($alumnoExistente) {
            $idAlumno = (int) $alumnoExistente["id_alumno"];
        } else {

            $stmtPadre = $conexion->prepare("SELECT nombres, apellidos FROM usuarios WHERE id_usuario = ?");
            $stmtPadre->execute([$solicitud["id_usuario_padre"]]);
            $padre = $stmtPadre->fetch();

            $conexion->prepare("
                INSERT INTO alumnos (nombres, apellidos, dni, fecha_nacimiento, apoderado_nombre, estado)
                VALUES (?, ?, ?, ?, ?, 'ACTIVO')
            ")->execute([
                $solicitud["alumno_nombres"],
                $solicitud["alumno_apellidos"],
                $solicitud["alumno_dni"],
                $solicitud["alumno_fecha_nacimiento"],
                $padre ? trim($padre["nombres"] . " " . $padre["apellidos"]) : null,
            ]);

            $idAlumno = (int) $conexion->lastInsertId();

            auditoria_registrar($conexion, $idUsuarioAdmin, "CREAR", "ALUMNO", $idAlumno,
                "Alumno creado automáticamente al aprobar la solicitud de matrícula #$idSolicitud");
        }

        $conexion->prepare("
            INSERT INTO matriculas (id_alumno, id_grado_seccion, id_periodo, estado, fecha_matricula)
            VALUES (?, ?, ?, 'ACTIVA', CURDATE())
        ")->execute([$idAlumno, $solicitud["id_grado_seccion_deseado"], $solicitud["id_periodo"]]);

        $conexion->prepare("
            INSERT INTO padres_alumnos (id_usuario_padre, id_alumno) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE id_usuario_padre = id_usuario_padre
        ")->execute([$solicitud["id_usuario_padre"], $idAlumno]);

        $conexion->prepare("
            UPDATE solicitudes_matricula SET estado = 'APROBADA' WHERE id_solicitud_matricula = ?
        ")->execute([$idSolicitud]);

        $conexion->commit();

    } catch (Throwable $e) {
        $conexion->rollBack();
        throw $e;
    }

    auditoria_registrar($conexion, $idUsuarioAdmin, "APROBAR", "SOLICITUD_MATRICULA", $idSolicitud,
        "Aprobó la matrícula de " . $solicitud["alumno_nombres"] . " " . $solicitud["alumno_apellidos"]);

    return ["id_alumno" => $idAlumno, "id_usuario_padre" => (int) $solicitud["id_usuario_padre"], "alumno_nombres" => $solicitud["alumno_nombres"], "alumno_apellidos" => $solicitud["alumno_apellidos"]];
}

/**
 * Rechaza la solicitud con un motivo (obligatorio, para que el
 * padre sepa qué corregir si quiere volver a intentarlo).
 */
function solicitud_matricula_rechazar(PDO $conexion, int $idSolicitud, string $motivo, int $idUsuarioAdmin): array
{
    $stmt = $conexion->prepare("SELECT * FROM solicitudes_matricula WHERE id_solicitud_matricula = ? AND estado = 'PENDIENTE'");
    $stmt->execute([$idSolicitud]);
    $solicitud = $stmt->fetch();

    if (!$solicitud) {
        throw new RuntimeException("La solicitud no existe o ya fue resuelta.");
    }

    $conexion->prepare("
        UPDATE solicitudes_matricula SET estado = 'RECHAZADA', observacion_admin = ? WHERE id_solicitud_matricula = ?
    ")->execute([$motivo, $idSolicitud]);

    auditoria_registrar($conexion, $idUsuarioAdmin, "RECHAZAR", "SOLICITUD_MATRICULA", $idSolicitud, $motivo);

    return ["id_usuario_padre" => (int) $solicitud["id_usuario_padre"], "alumno_nombres" => $solicitud["alumno_nombres"], "alumno_apellidos" => $solicitud["alumno_apellidos"]];
}
