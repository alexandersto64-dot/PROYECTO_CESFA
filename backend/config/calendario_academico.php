<?php

// ==========================================================
// Calendario académico — cuenta regresiva de cierre de bimestre.
//
// Depende de `periodos_academicos.fecha_inicio`/`fecha_fin`
// (columnas nuevas, ver migracion_calendario_academico.sql). Si
// Subdirección todavía no les puso fecha a los periodos, esta
// función simplemente devuelve null y el banner no se muestra en
// ningún dashboard — no rompe nada de lo que ya funciona sin fechas
// (profesor_tutoria.php sigue resolviendo el periodo por nombre,
// sin tocar estas columnas).
//
// Se excluyen a propósito los periodos "Año Académico ..." (ver
// profesor_tutoria.php): esos son de granularidad anual, la cuenta
// regresiva es siempre sobre el BIMESTRE.
// ==========================================================

/**
 * El bimestre "actual" para efectos de cierre: el que tiene
 * fecha_fin más próxima entre los que todavía no cerraron
 * (fecha_fin >= hoy). Si ninguno tiene fecha_fin futura (todos ya
 * cerraron, o ninguno tiene fecha cargada todavía), devuelve null.
 *
 * @return array{id_periodo:int, nombre:string, fecha_fin:string, dias_restantes:int}|null
 */
function bimestre_por_cerrar(PDO $conexion): ?array {

    $stmt = $conexion->query("
        SELECT id_periodo, nombre, fecha_fin, DATEDIFF(fecha_fin, CURDATE()) AS dias_restantes
        FROM periodos_academicos
        WHERE nombre NOT LIKE 'Año Académico%'
          AND fecha_fin IS NOT NULL
          AND fecha_fin >= CURDATE()
        ORDER BY fecha_fin ASC
        LIMIT 1
    ");

    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    $fila["id_periodo"] = (int) $fila["id_periodo"];
    $fila["dias_restantes"] = (int) $fila["dias_restantes"];

    return $fila;

}

/**
 * Texto listo para mostrar en un banner del dashboard, o null si no
 * hay ningún bimestre con fecha de cierre próxima registrada.
 * Se marca como "urgente" (para resaltar en rojo/ámbar) cuando
 * quedan 7 días o menos.
 *
 * @return array{texto:string, urgente:bool}|null
 */
function calendario_banner_cierre(PDO $conexion): ?array {

    $bimestre = bimestre_por_cerrar($conexion);

    if (!$bimestre) {
        return null;
    }

    $dias = $bimestre["dias_restantes"];

    if ($dias === 0) {
        $texto = "El " . $bimestre["nombre"] . " cierra hoy.";
    } elseif ($dias === 1) {
        $texto = "Falta 1 día para el cierre del " . $bimestre["nombre"] . ".";
    } else {
        $texto = "Faltan " . $dias . " días para el cierre del " . $bimestre["nombre"] . ".";
    }

    return [
        "texto" => $texto,
        "urgente" => $dias <= 7,
    ];

}
