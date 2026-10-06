<?php

// ==========================================
// FASE 2 — Grados (nivel+grado) asignados a un profesor.
//
// La fuente de verdad es `asignaciones_docentes` (profesor -> aula
// de grados_secciones), que ya existe y ya está poblada. A partir
// de ahí se deriva el nivel+grado "sin sección" (tabla catálogo
// `niveles_grados`, creada en la migración de Fase 1) que es el
// nivel de detalle que usan PCA/Unidades/Sesiones/Documentos.
//
// Deliberadamente NO se usa `profesores.nivel_educativo` como
// filtro de acceso: hoy esa columna está NULL para los 3 profesores
// reales del sistema (nadie ha sido reclasificado con el rol
// PROFESOR_PRIMARIA/PROFESOR_SECUNDARIO todavía), así que basar el
// acceso en ella dejaría el Dashboard sin grados para todos. Las
// aulas ya asignadas en `asignaciones_docentes` sí reflejan la
// realidad actual y ya vienen etiquetadas por nivel (columna
// `grados_secciones.nivel`), así que de ahí se puede derivar el
// nivel+grado con seguridad, sin inventar ni adivinar nada.
//
// Si más adelante se clasifica a los profesores con
// nivel_educativo, el trigger de BD (trg_asignaciones_valida_nivel_*)
// ya impide que se les asignen aulas de otro nivel, así que ambas
// fuentes quedan consistentes automáticamente.
// ==========================================

/**
 * Lista los nivel+grado (de la tabla niveles_grados) a los que el
 * profesor tiene al menos un aula asignada, sin duplicados.
 * Nunca mezcla PRIMARIA y SECUNDARIA porque cada fila de
 * niveles_grados es un nivel+grado específico.
 *
 * @return array Filas con id_nivel_grado, nivel, grado, nombre.
 */
function profesor_grados_asignados(PDO $conexion, int $idProfesor): array {

    $stmt = $conexion->prepare("
        SELECT DISTINCT ng.id_nivel_grado, ng.nivel, ng.grado, ng.nombre
        FROM asignaciones_docentes ad
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
        INNER JOIN niveles_grados ng ON ng.nivel = gs.nivel COLLATE utf8mb4_unicode_ci AND ng.grado = gs.grado
        WHERE ad.id_profesor = ?
        ORDER BY ng.nivel, ng.grado
    ");
    $stmt->execute([$idProfesor]);

    $grados = $stmt->fetchAll();

    // Para cada grado se agrega el/los curso(s) que el profesor dicta
    // ahí (profesor_curso_grado) y las secciones que tiene asignadas
    // (asignaciones_docentes), para que el Dashboard pueda mostrar
    // "Curso · N secciones" en cada tarjeta sin volver a consultar
    // manualmente desde la vista.
    foreach ($grados as &$grado) {
        $grado["cursos"] = profesor_cursos_por_grado($conexion, $idProfesor, (int) $grado["id_nivel_grado"]);
        $grado["secciones"] = profesor_secciones_por_grado($conexion, $idProfesor, $grado);
    }
    unset($grado);

    return $grados;

}

/**
 * Verifica en backend (nunca confiando en el id_nivel_grado de la
 * URL/formulario) que el profesor tenga realmente un aula asignada
 * de ese nivel+grado. Devuelve la fila de niveles_grados si es
 * válido, o null si no le pertenece / no existe.
 */
function profesor_verificar_grado(PDO $conexion, int $idProfesor, int $idNivelGrado): ?array {

    if ($idNivelGrado <= 0) {
        return null;
    }

    $stmt = $conexion->prepare("
        SELECT DISTINCT ng.id_nivel_grado, ng.nivel, ng.grado, ng.nombre
        FROM asignaciones_docentes ad
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
        INNER JOIN niveles_grados ng ON ng.nivel = gs.nivel COLLATE utf8mb4_unicode_ci AND ng.grado = gs.grado
        WHERE ad.id_profesor = ? AND ng.id_nivel_grado = ?
        LIMIT 1
    ");
    $stmt->execute([$idProfesor, $idNivelGrado]);
    $fila = $stmt->fetch();

    return $fila ?: null;

}

/**
 * Etiqueta visible para un nivel+grado, ej. "1RO PRIMARIA".
 */
function nivel_grado_label(array $nivelGrado): string {
    return strtoupper($nivelGrado["nombre"]) . " " . $nivelGrado["nivel"];
}

/**
 * Curso(s) que el profesor enseña en un nivel+grado determinado.
 *
 * Fuente de verdad: `profesor_curso_grado` (profesor -> curso que
 * enseña en ese nivel+grado), nunca `asignaciones_docentes` (esa
 * tabla solo dice qué aula/sección tiene, no qué curso dicta).
 * Normalmente es un único curso por grado, pero se devuelven todas
 * las filas que existan realmente en la BD, sin asumir cardinalidad.
 *
 * @return array Filas con id_curso, nombre, nivel.
 */
function profesor_cursos_por_grado(PDO $conexion, int $idProfesor, int $idNivelGrado): array {

    if ($idNivelGrado <= 0) {
        return [];
    }

    $stmt = $conexion->prepare("
        SELECT DISTINCT c.id_curso, c.nombre, c.nivel
        FROM profesor_curso_grado pcg
        INNER JOIN cursos c ON c.id_curso = pcg.id_curso
        WHERE pcg.id_profesor = ? AND pcg.id_nivel_grado = ?
        ORDER BY c.nombre
    ");
    $stmt->execute([$idProfesor, $idNivelGrado]);

    return $stmt->fetchAll();

}

/**
 * Secciones/aulas (de `grados_secciones`) que el profesor tiene
 * realmente asignadas (vía `asignaciones_docentes`) dentro de un
 * nivel+grado determinado.
 *
 * Fuente de verdad: `asignaciones_docentes` (profesor -> aula),
 * nunca `profesor_curso_grado` (esa tabla no dice secciones).
 * El nivel+grado recibido debe venir ya validado con
 * profesor_verificar_grado(); aquí solo se usa su nivel/grado para
 * filtrar las aulas.
 *
 * @return array Filas con id_grado_seccion, seccion, nombre.
 */
function profesor_secciones_por_grado(PDO $conexion, int $idProfesor, array $nivelGrado): array {

    $stmt = $conexion->prepare("
        SELECT DISTINCT gs.id_grado_seccion, gs.seccion, gs.nombre
        FROM asignaciones_docentes ad
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
        WHERE ad.id_profesor = ?
          AND gs.nivel = ? COLLATE utf8mb4_unicode_ci
          AND gs.grado = ?
        ORDER BY gs.seccion
    ");
    $stmt->execute([$idProfesor, $nivelGrado["nivel"], $nivelGrado["grado"]]);

    return $stmt->fetchAll();

}
