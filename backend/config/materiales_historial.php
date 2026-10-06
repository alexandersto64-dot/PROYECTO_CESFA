<?php

// ==========================================
// Historial de cambios de materiales (PCA/Unidades/Sesiones).
// Requiere $conexion (PDO) ya definido y la tabla creada por
// backend/config/migracion_materiales_historial.sql.
//
// No reemplaza a profesor_pca_archivos / profesor_unidad_archivos /
// profesor_sesion_archivos (esas tablas siguen siendo "el archivo
// vigente"); esta es solo la bitácora de SUBIDO/REEMPLAZADO/ELIMINADO
// para poder auditar cambios pasados, ver
// migracion_materiales_historial.php para más contexto.
// ==========================================

/**
 * Registra una entrada en el historial. $archivo debe traer al menos
 * nombre_archivo, ruta_archivo, extension y tamano_bytes (mismo
 * formato que devuelve materiales_guardar_archivo(), o el que ya
 * tenía guardado en BD el archivo reemplazado/eliminado).
 */
function materiales_historial_registrar(
    PDO $conexion,
    string $modulo,
    int $idProfesor,
    int $idNivelGrado,
    int $unidad,
    ?int $semana,
    ?string $curso,
    string $accion,
    array $archivo,
    int $idUsuarioAccion
): void {

    $stmt = $conexion->prepare("
        INSERT INTO materiales_historial
            (modulo, id_profesor, id_nivel_grado, unidad, semana, curso,
             accion, nombre_archivo, ruta_archivo, extension, tamano_bytes, id_usuario_accion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $modulo,
        $idProfesor,
        $idNivelGrado,
        $unidad,
        $semana,
        $curso,
        $accion,
        $archivo["nombre_archivo"],
        $archivo["ruta_archivo"],
        $archivo["extension"],
        $archivo["tamano_bytes"],
        $idUsuarioAccion,
    ]);

}

/**
 * Lista el historial de un profesor, más reciente primero.
 * $modulo: "PCA" | "UNIDADES" | "SESIONES" | null (todos).
 * $idNivelGrado: null = todos los grados del profesor.
 */
function materiales_historial_listar(
    PDO $conexion,
    int $idProfesor,
    ?string $modulo = null,
    ?int $idNivelGrado = null,
    int $limite = 50
): array {

    $condiciones = ["h.id_profesor = ?"];
    $parametros = [$idProfesor];

    if ($modulo !== null) {
        $condiciones[] = "h.modulo = ?";
        $parametros[] = $modulo;
    }

    if ($idNivelGrado !== null) {
        $condiciones[] = "h.id_nivel_grado = ?";
        $parametros[] = $idNivelGrado;
    }

    $sql = "
        SELECT
            h.*,
            ng.nivel, ng.grado, ng.nombre AS grado_nombre,
            u.nombres AS usuario_nombres, u.apellidos AS usuario_apellidos
        FROM materiales_historial h
        INNER JOIN niveles_grados ng ON ng.id_nivel_grado = h.id_nivel_grado
        INNER JOIN usuarios u ON u.id_usuario = h.id_usuario_accion
        WHERE " . implode(" AND ", $condiciones) . "
        ORDER BY h.creado_en DESC
        LIMIT " . (int) $limite;

    $stmt = $conexion->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll();

}

/**
 * Historial de materiales de TODOS los profesores (a diferencia de
 * materiales_historial_listar(), que exige un id_profesor). Pensado
 * para el feed de "Actividad reciente" del panel de Administración —
 * misma tabla, mismo criterio, sin filtrar por profesor.
 */
function materiales_historial_listar_global(PDO $conexion, int $limite = 10): array {

    $sql = "
        SELECT
            h.*,
            ng.nivel, ng.grado, ng.nombre AS grado_nombre,
            u.nombres AS usuario_nombres, u.apellidos AS usuario_apellidos
        FROM materiales_historial h
        INNER JOIN niveles_grados ng ON ng.id_nivel_grado = h.id_nivel_grado
        INNER JOIN usuarios u ON u.id_usuario = h.id_usuario_accion
        ORDER BY h.creado_en DESC
        LIMIT " . (int) $limite;

    return $conexion->query($sql)->fetchAll();

}
