<?php

// ==========================================================
// Calendario Académico Institucional — eventos reales (exámenes,
// reuniones, feriados, actividades) sobre una tabla nueva
// `eventos_institucionales` (ver migracion_eventos_institucionales.sql),
// más las "entregas" que YA existen en `trabajos.fecha_limite`
// (no se duplica esa fecha en ningún lado nuevo, se lee en vivo).
//
// Quién ve qué:
//   - ADMIN y SUBDIRECTOR: crean/eliminan eventos y ven TODOS,
//     sin importar a qué nivel+grado estén dirigidos.
//   - PROFESOR: solo lee (no crea), y solo ve los eventos "de todo
//     el colegio" (id_nivel_grado NULL) + los de los nivel+grado que
//     tiene asignados (ver profesor_grados_asignados() en
//     profesor_grados.php) — nunca los de un grado ajeno. Además ve
//     sus propios Trabajos como "entregas" en el mismo calendario.
// ==========================================================

if (!function_exists("nivel_grado_label")) {
    require_once __DIR__ . "/profesor_grados.php";
}

/**
 * Metadatos de presentación por tipo de evento (etiqueta + clase CSS
 * para el color, ver css/dashboard.css). "ENTREGA" es un tipo
 * "virtual": nunca se guarda en la tabla, se usa solo para pintar
 * los Trabajos como si fueran eventos del mismo calendario.
 */
function eventos_tipo_meta(string $tipo): array {

    $tipos = [
        "EXAMEN"    => ["label" => "Examen",    "clase" => "evt-examen"],
        "REUNION"   => ["label" => "Reunión",   "clase" => "evt-reunion"],
        "FERIADO"   => ["label" => "Feriado",   "clase" => "evt-feriado"],
        "ACTIVIDAD" => ["label" => "Actividad", "clase" => "evt-actividad"],
        "ENTREGA"   => ["label" => "Entrega de trabajo", "clase" => "evt-entrega"],
        "OTRO"      => ["label" => "Otro",      "clase" => "evt-otro"],
    ];

    return $tipos[$tipo] ?? $tipos["OTRO"];

}

/**
 * Tipos que se pueden crear a mano desde el formulario (ENTREGA
 * queda afuera a propósito: esa la genera Trabajos, no se crea acá
 * para no terminar con dos fuentes de verdad para la misma fecha).
 */
function eventos_tipos_creables(): array {
    return ["EXAMEN", "REUNION", "FERIADO", "ACTIVIDAD", "OTRO"];
}

/**
 * Arma las semanas (arrays de 7 días, Lunes a Domingo) de un mes
 * calendario, con `null` en las celdas que caen fuera del mes (para
 * completar la primera y la última semana). Cada celda real es un
 * DateTimeImmutable con hora 00:00 de ese día.
 *
 * @return DateTimeImmutable[][] Lista de semanas (cada una, 7 celdas)
 */
function calendario_grid_semanas(int $anio, int $mes): array {

    $primerDia = new DateTimeImmutable(sprintf("%04d-%02d-01", $anio, $mes));
    $totalDias = (int) $primerDia->format("t");
    $diaSemanaInicio = (int) $primerDia->format("N"); // 1=Lunes … 7=Domingo

    $celdas = [];

    for ($i = 1; $i < $diaSemanaInicio; $i++) {
        $celdas[] = null;
    }

    for ($d = 1; $d <= $totalDias; $d++) {
        $celdas[] = $primerDia->setDate($anio, $mes, $d);
    }

    while (count($celdas) % 7 !== 0) {
        $celdas[] = null;
    }

    return array_chunk($celdas, 7);

}

/**
 * Nombre del mes en español, sin depender de la extensión intl
 * (no todos los servidores locales la tienen habilitada) ni de
 * setlocale() (poco confiable entre sistemas operativos).
 */
function mes_nombre_es(int $mes): string {

    $nombres = [
        1 => "enero", 2 => "febrero", 3 => "marzo", 4 => "abril",
        5 => "mayo", 6 => "junio", 7 => "julio", 8 => "agosto",
        9 => "septiembre", 10 => "octubre", 11 => "noviembre", 12 => "diciembre",
    ];

    return $nombres[$mes] ?? "";

}

/** Abreviatura de 3 letras en español (ENE, FEB, MAR…) para la agenda. */
function mes_abrev_es(int $mes): string {
    return strtoupper(substr(mes_nombre_es($mes), 0, 3));
}

/**
 * Guarda un evento institucional nuevo. Espera $datos ya validado
 * (ver admin/calendario.php y subdirector/calendario.php para las
 * reglas de validación) con las llaves: titulo, descripcion,
 * tipo, fecha_inicio, fecha_fin, id_nivel_grado, id_usuario.
 */
function eventos_crear(PDO $conexion, array $datos): void {

    $stmt = $conexion->prepare("
        INSERT INTO eventos_institucionales
            (titulo, descripcion, tipo, fecha_inicio, fecha_fin, id_nivel_grado, id_usuario)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $datos["titulo"],
        $datos["descripcion"] !== "" ? $datos["descripcion"] : null,
        $datos["tipo"],
        $datos["fecha_inicio"],
        $datos["fecha_fin"] !== "" ? $datos["fecha_fin"] : null,
        $datos["id_nivel_grado"] > 0 ? $datos["id_nivel_grado"] : null,
        $datos["id_usuario"],
    ]);

}

function eventos_eliminar(PDO $conexion, int $idEvento): void {
    $stmt = $conexion->prepare("DELETE FROM eventos_institucionales WHERE id_evento = ?");
    $stmt->execute([$idEvento]);
}

/**
 * Eventos institucionales que caen (total o parcialmente) dentro del
 * mes indicado.
 *
 * @param int[]|null $idsNivelGradoVisibles null = sin filtro (Admin/
 *   Subdirección, ven todo). Array (puede ser vacío) = solo eventos
 *   de todo el colegio (id_nivel_grado NULL) + los de esos grados
 *   puntuales (uso del Profesor).
 */
function eventos_listar_mes(PDO $conexion, int $anio, int $mes, ?array $idsNivelGradoVisibles = null): array {

    $inicioMes = sprintf("%04d-%02d-01", $anio, $mes);
    $finMes = date("Y-m-t", strtotime($inicioMes));

    $sql = "
        SELECT
            e.id_evento, e.titulo, e.descripcion, e.tipo,
            e.fecha_inicio, e.fecha_fin, e.id_nivel_grado,
            ng.nivel AS grado_nivel, ng.grado AS grado_grado, ng.nombre AS grado_nombre,
            u.nombres, u.apellidos
        FROM eventos_institucionales e
        LEFT JOIN niveles_grados ng ON ng.id_nivel_grado = e.id_nivel_grado
        INNER JOIN usuarios u ON u.id_usuario = e.id_usuario
        WHERE e.fecha_inicio <= ? AND COALESCE(e.fecha_fin, e.fecha_inicio) >= ?
    ";
    $params = [$finMes, $inicioMes];

    if ($idsNivelGradoVisibles !== null) {
        if (count($idsNivelGradoVisibles) > 0) {
            $marcadores = implode(",", array_fill(0, count($idsNivelGradoVisibles), "?"));
            $sql .= " AND (e.id_nivel_grado IS NULL OR e.id_nivel_grado IN ($marcadores))";
            $params = array_merge($params, $idsNivelGradoVisibles);
        } else {
            $sql .= " AND e.id_nivel_grado IS NULL";
        }
    }

    $sql .= " ORDER BY e.fecha_inicio ASC, e.titulo ASC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();

}

/**
 * Trabajos (fecha_limite) del profesor que caen en el mes indicado,
 * ya con la forma de "evento" (mismas llaves que un evento real, más
 * "es_trabajo" => true) para poder mezclarlos en la misma grilla y
 * agenda sin que la vista tenga que distinguir dos formatos.
 */
function eventos_trabajos_mes(PDO $conexion, int $idProfesor, int $anio, int $mes): array {

    $inicioMes = sprintf("%04d-%02d-01", $anio, $mes);
    $finMes = date("Y-m-t", strtotime($inicioMes));

    $stmt = $conexion->prepare("
        SELECT t.id_trabajo, t.titulo, t.fecha_limite, t.estado, c.nombre AS curso_nombre
        FROM trabajos t
        INNER JOIN cursos c ON c.id_curso = t.id_curso
        WHERE t.id_profesor = ? AND t.fecha_limite BETWEEN ? AND ?
        ORDER BY t.fecha_limite ASC
    ");
    $stmt->execute([$idProfesor, $inicioMes, $finMes]);

    $filas = [];

    foreach ($stmt->fetchAll() as $t) {
        $filas[] = [
            "id_evento"     => "trabajo-" . $t["id_trabajo"],
            "titulo"        => $t["titulo"],
            "descripcion"   => "Entrega de " . $t["curso_nombre"] . " · estado: " . ucfirst(strtolower($t["estado"])),
            "tipo"          => "ENTREGA",
            "fecha_inicio"  => $t["fecha_limite"],
            "fecha_fin"     => null,
            "id_nivel_grado"=> null,
            "grado_nombre"  => null,
            "grado_nivel"   => null,
            "grado_grado"   => null,
            "nombres"       => null,
            "apellidos"     => null,
            "es_trabajo"    => true,
        ];
    }

    return $filas;

}

/**
 * Combina eventos + entregas de trabajos en un solo array agrupado
 * por fecha ("Y-m-d" => lista de eventos ese día), ya ordenado por
 * fecha para pintar la grilla y la agenda con una sola pasada.
 */
function eventos_agrupar_por_dia(array $eventos): array {

    $porDia = [];

    foreach ($eventos as $evento) {

        $inicio = new DateTimeImmutable($evento["fecha_inicio"]);
        $fin = $evento["fecha_fin"] ? new DateTimeImmutable($evento["fecha_fin"]) : $inicio;

        $cursor = $inicio;
        while ($cursor <= $fin) {
            $porDia[$cursor->format("Y-m-d")][] = $evento;
            $cursor = $cursor->modify("+1 day");
        }

    }

    ksort($porDia);

    return $porDia;

}
