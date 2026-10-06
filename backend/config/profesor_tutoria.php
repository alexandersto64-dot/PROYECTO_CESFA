<?php

// ==========================================
// Tutoría — asignación dinámica por año escolar (SOLO Secundaria).
//
// Fuente de verdad: `profesor_tutoria` (profesor -> aula de
// grados_secciones -> id_periodo), tabla nueva y 100% aditiva (ver
// backend/config/migracion_tutoria.sql). Nunca se asume Tutoría por
// el simple hecho de que el profesor sea de Secundaria: solo existe
// si hay una fila real para el profesor en el período académico
// actual.
//
// "Año escolar actual": en la BD real, `periodos_academicos` NO
// contiene solo años — también contiene los bimestres del año
// (ej. "Año Académico 2026" junto con "Primer Bimestre 2026",
// "Segundo Bimestre 2026", etc.). Por eso NO se puede usar
// simplemente "el id_periodo más alto" como año actual (eso daría
// el último bimestre, no el año). En cambio, se usa el mismo
// período que ya usa `matriculas` en esta BD para matricular
// alumnos: el que tiene nombre "Año Académico ..." — es decir, el
// período de granularidad ANUAL, que es el que corresponde a una
// asignación de Tutoría ("un profesor es tutor de un aula durante
// el año escolar X", no durante un bimestre suelto).
//
// No se agrega ninguna columna "activo" nueva ni se toca
// `periodos_academicos`: se resuelve por nombre. Si en el futuro
// se registra "Año Académico 2027", ese pasa a ser automáticamente
// el actual (el de id_periodo más alto entre los que empiezan con
// "Año Académico"), sin tocar código.
// ==========================================

/**
 * Período académico "de año completo" más reciente (el "año
 * escolar actual" para efectos de Tutoría) — el mismo que usa
 * `matriculas` en esta BD. Si por algún motivo no hay ningún
 * período con nombre "Año Académico ..." (institución no siguió
 * esa convención de nombres), cae de vuelta al id_periodo más alto
 * de toda la tabla, para no dejar Tutoría inutilizable.
 *
 * Devuelve null si todavía no existe ningún período registrado.
 */
function periodo_academico_actual(PDO $conexion): ?array {

    $stmt = $conexion->query("
        SELECT id_periodo, nombre FROM periodos_academicos
        WHERE nombre LIKE 'Año Académico%'
        ORDER BY id_periodo DESC
        LIMIT 1
    ");

    $fila = $stmt->fetch();

    if ($fila) {
        return $fila;
    }

    // Respaldo: ningún período sigue la convención "Año Académico ..."
    $stmt = $conexion->query("
        SELECT id_periodo, nombre FROM periodos_academicos
        ORDER BY id_periodo DESC
        LIMIT 1
    ");

    $fila = $stmt->fetch();

    return $fila ?: null;

}

/**
 * Asignación de Tutoría del profesor para el período académico
 * actual, o null si no tiene ninguna ese año (aunque haya tenido,
 * o vaya a tener, en otro). Siempre filtra por nivel SECUNDARIA como
 * comprobación adicional (la asignación ya debería crearse solo
 * sobre aulas de Secundaria desde el panel de Admin, pero esta
 * función nunca confía en eso y lo vuelve a verificar aquí).
 *
 * @return array|null Fila con id_tutoria, id_grado_seccion, nivel,
 *                     grado, seccion, nombre (del aula), id_periodo,
 *                     nombre_periodo.
 */
function profesor_tutoria_actual(PDO $conexion, int $idProfesor): ?array {

    $periodo = periodo_academico_actual($conexion);

    if (!$periodo) {
        return null;
    }

    $stmt = $conexion->prepare("
        SELECT
            pt.id_tutoria, pt.id_grado_seccion, pt.id_periodo,
            gs.nivel, gs.grado, gs.seccion, gs.nombre,
            pa.nombre AS nombre_periodo
        FROM profesor_tutoria pt
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = pt.id_grado_seccion
        INNER JOIN periodos_academicos pa ON pa.id_periodo = pt.id_periodo
        WHERE pt.id_profesor = ?
          AND pt.id_periodo = ?
          AND gs.nivel = 'SECUNDARIA'
        LIMIT 1
    ");
    $stmt->execute([$idProfesor, (int) $periodo["id_periodo"]]);

    $fila = $stmt->fetch();

    return $fila ?: null;

}

/**
 * Verifica en backend que la Tutoría pedida (por id_grado_seccion)
 * realmente le pertenece al profesor en el período académico
 * actual. Nunca se confía en el id_grado_seccion recibido por
 * GET/POST: se contrasta siempre contra profesor_tutoria_actual().
 * Devuelve la misma fila que profesor_tutoria_actual() si coincide,
 * o null si no.
 */
function profesor_tutoria_verificar(PDO $conexion, int $idProfesor, int $idGradoSeccion): ?array {

    if ($idGradoSeccion <= 0) {
        return null;
    }

    $tutoria = profesor_tutoria_actual($conexion, $idProfesor);

    if (!$tutoria || (int) $tutoria["id_grado_seccion"] !== $idGradoSeccion) {
        return null;
    }

    return $tutoria;

}

/**
 * Etiqueta visible para una Tutoría, ej. "1RO A SECUNDARIA — Tutoría".
 */
function tutoria_label(array $tutoria): string {
    return strtoupper($tutoria["nombre"]) . " " . $tutoria["nivel"] . " — Tutoría";
}
