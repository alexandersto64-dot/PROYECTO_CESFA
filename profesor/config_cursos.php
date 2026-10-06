<?php
/**
 * profesor/config_cursos.php
 *
 * Fuente única de cursos para la Intranet del profesor.
 * Los cursos se obtienen desde:
 *
 * profesor_curso_grado
 *      ↓
 * profesor
 *      ↓
 * grado/sección
 *      ↓
 * curso
 */

/**
 * Obtiene los cursos asignados a un profesor.
 *
 * @param PDO $pdo
 * @param int $idProfesor
 * @param int|null $idGradoSeccion
 * @return array
 */
function obtenerCursosProfesor(PDO $pdo, int $idProfesor, ?int $idGradoSeccion = null): array
{
    $sql = "
        SELECT DISTINCT
            c.id_curso,
            c.nombre,
            c.nivel,
            gs.id_grado_seccion,
            gs.grado,
            gs.seccion,
            gs.nombre AS grado_seccion
        FROM profesor_curso_grado pcg
        INNER JOIN cursos c
            ON c.id_curso = pcg.id_curso
        INNER JOIN grados_secciones gs
            ON gs.id_grado_seccion = pcg.id_grado_seccion
        WHERE pcg.id_profesor = :id_profesor
    ";

    $params = [
        ':id_profesor' => $idProfesor
    ];

    if ($idGradoSeccion !== null) {
        $sql .= "
            AND pcg.id_grado_seccion = :id_grado_seccion
        ";

        $params[':id_grado_seccion'] = $idGradoSeccion;
    }

    $sql .= "
        ORDER BY gs.grado ASC, gs.seccion ASC, c.nombre ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Obtiene los grados/secciones asignados a un profesor.
 *
 * @param PDO $pdo
 * @param int $idProfesor
 * @return array
 */
function obtenerGradosProfesor(PDO $pdo, int $idProfesor): array
{
    $sql = "
        SELECT DISTINCT
            gs.id_grado_seccion,
            gs.nivel,
            gs.grado,
            gs.seccion,
            gs.nombre
        FROM profesor_curso_grado pcg
        INNER JOIN grados_secciones gs
            ON gs.id_grado_seccion = pcg.id_grado_seccion
        WHERE pcg.id_profesor = :id_profesor
        ORDER BY gs.grado ASC, gs.seccion ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_profesor' => $idProfesor
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Verifica si un profesor tiene acceso a un curso dentro
 * de un grado/sección determinado.
 *
 * @param PDO $pdo
 * @param int $idProfesor
 * @param int $idCurso
 * @param int $idGradoSeccion
 * @return bool
 */
function profesorTieneCurso(
    PDO $pdo,
    int $idProfesor,
    int $idCurso,
    int $idGradoSeccion
): bool {
    $sql = "
        SELECT 1
        FROM profesor_curso_grado
        WHERE id_profesor = :id_profesor
          AND id_curso = :id_curso
          AND id_grado_seccion = :id_grado_seccion
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':id_profesor' => $idProfesor,
        ':id_curso' => $idCurso,
        ':id_grado_seccion' => $idGradoSeccion
    ]);

    return (bool) $stmt->fetchColumn();
}
/**
 * Devuelve el nivel educativo permitido según el rol del profesor.
 */
function profesor_nivel_permitido(string $rol): string
{
    return match ($rol) {
        'PROFESOR_PRIMARIA' => 'PRIMARIA',
        'PROFESOR_SECUNDARIO' => 'SECUNDARIA',
        default => throw new RuntimeException('Rol de profesor no válido.')
    };
}