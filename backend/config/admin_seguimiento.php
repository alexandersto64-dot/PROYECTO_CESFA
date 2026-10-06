<?php

// ==========================================================
// Mejoras de Administración: auditoría de acciones, papelera
// (borrado suave/restaurar) y panel de salud del sistema.
// ==========================================================

// ----------------------------------------------------------
// AUDITORÍA
// ----------------------------------------------------------

function auditoria_registrar(PDO $conexion, int $idUsuario, string $accion, string $entidad, ?int $idEntidad, string $detalle): void {

    require_once __DIR__ . "/red_cliente.php";

    $detalle = mb_substr($detalle, 0, 255, "UTF-8");

    try {

        $stmt = $conexion->prepare("
            INSERT INTO auditoria (id_usuario, accion, entidad, id_entidad, detalle, ip)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$idUsuario, $accion, $entidad, $idEntidad, $detalle, ip_cliente()]);

    } catch (PDOException $e) {

        // Aún no se ejecutó migracion_auditoria_seguridad.sql (no existe la
        // columna `ip`): se guarda igual, como antes, sin la IP.
        $stmt = $conexion->prepare("
            INSERT INTO auditoria (id_usuario, accion, entidad, id_entidad, detalle)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$idUsuario, $accion, $entidad, $idEntidad, $detalle]);

    }

}

/**
 * Evento de seguridad/uso (login, descargas, cambios de clave...).
 * A diferencia de auditoria_registrar():
 *   - $idUsuario puede ser null (intento con un usuario que no existe)
 *   - NUNCA lanza error: si no se puede guardar, el sistema sigue normal.
 * No guardes aquí contraseñas ni lo que el visitante escribió en el login.
 */
function auditoria_evento(PDO $conexion, ?int $idUsuario, string $accion, string $entidad, ?int $idEntidad, string $detalle): void {

    require_once __DIR__ . "/red_cliente.php";

    $detalle = mb_substr($detalle, 0, 255, "UTF-8");

    try {

        $conexion->prepare("
            INSERT INTO auditoria (id_usuario, accion, entidad, id_entidad, detalle, ip)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$idUsuario, $accion, $entidad, $idEntidad, $detalle, ip_cliente()]);

    } catch (\Throwable $e) {

        // Sin migración: solo se puede guardar si hay usuario (columna NOT NULL).
        if ($idUsuario !== null) {
            try {
                $conexion->prepare("
                    INSERT INTO auditoria (id_usuario, accion, entidad, id_entidad, detalle)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$idUsuario, $accion, $entidad, $idEntidad, $detalle]);
            } catch (\Throwable $e2) {
                // se ignora a propósito
            }
        }

    }

}

/**
 * @param string|null $accion  Filtra por acción exacta (ej. "LOGIN_FALLIDO").
 * @param string|null $entidad Filtra por entidad exacta (ej. "SESION").
 */
function auditoria_listar(PDO $conexion, int $limite = 150, ?string $accion = null, ?string $entidad = null): array {

    $where = [];
    $params = [];

    if ($accion !== null && $accion !== "") {
        $where[] = "a.accion = ?";
        $params[] = $accion;
    }

    if ($entidad !== null && $entidad !== "") {
        $where[] = "a.entidad = ?";
        $params[] = $entidad;
    }

    $sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";

    // LEFT JOIN: los eventos sin usuario (login fallido con cuenta
    // inexistente) también deben verse. `ip` puede no existir todavía.
    foreach ([", a.ip", ""] as $colIp) {

        try {

            $stmt = $conexion->prepare("
                SELECT a.id_auditoria, a.accion, a.entidad, a.id_entidad, a.detalle, a.creado_en{$colIp},
                       u.nombres, u.apellidos
                FROM auditoria a
                LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario
                {$sqlWhere}
                ORDER BY a.creado_en DESC, a.id_auditoria DESC
                LIMIT " . max(1, $limite) . "
            ");
            $stmt->execute($params);
            return $stmt->fetchAll();

        } catch (PDOException $e) {
            if ($colIp === "") { throw $e; }
        }

    }

    return [];

}

/** Acciones distintas que hay registradas (para el filtro de la pantalla). */
function auditoria_acciones(PDO $conexion): array {
    return $conexion->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);
}

// ----------------------------------------------------------
// PAPELERA (borrado suave / restaurar)
//
// $tabla y $columnaId SIEMPRE vienen fijos desde el código (nunca de
// $_GET/$_POST), así que interpolarlos en el SQL es seguro — no hay
// forma de que el usuario los cambie.
// ----------------------------------------------------------

function papelera_mover(PDO $conexion, string $tabla, string $columnaId, int $id): void {
    $stmt = $conexion->prepare("UPDATE `$tabla` SET eliminado_en = NOW() WHERE `$columnaId` = ?");
    $stmt->execute([$id]);
}

function papelera_restaurar(PDO $conexion, string $tabla, string $columnaId, int $id): void {
    $stmt = $conexion->prepare("UPDATE `$tabla` SET eliminado_en = NULL WHERE `$columnaId` = ?");
    $stmt->execute([$id]);
}

function papelera_usuarios(PDO $conexion): array {
    return $conexion->query("
        SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.eliminado_en, r.nombre AS rol
        FROM usuarios u
        INNER JOIN roles r ON r.id_rol = u.id_rol
        WHERE u.eliminado_en IS NOT NULL
        ORDER BY u.eliminado_en DESC
    ")->fetchAll();
}

function papelera_alumnos(PDO $conexion): array {
    return $conexion->query("
        SELECT id_alumno, nombres, apellidos, dni, eliminado_en
        FROM alumnos
        WHERE eliminado_en IS NOT NULL
        ORDER BY eliminado_en DESC
    ")->fetchAll();
}

function papelera_cursos(PDO $conexion): array {
    return $conexion->query("
        SELECT id_curso, nombre, nivel, eliminado_en
        FROM cursos
        WHERE eliminado_en IS NOT NULL
        ORDER BY eliminado_en DESC
    ")->fetchAll();
}

// ----------------------------------------------------------
// SALUD DEL SISTEMA
// ----------------------------------------------------------

/** Tamaño total (bytes) y cantidad de archivos dentro de una carpeta, recursivo. */
function salud_tamano_carpeta(string $ruta): array {

    if (!is_dir($ruta)) {
        return ["bytes" => 0, "archivos" => 0];
    }

    $bytes = 0;
    $archivos = 0;

    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterador as $item) {
        if ($item->isFile()) {
            $bytes += $item->getSize();
            $archivos++;
        }
    }

    return ["bytes" => $bytes, "archivos" => $archivos];

}

/** "1.2 MB", "340 KB", etc. */
function salud_formato_bytes(int $bytes): string {

    if ($bytes < 1024) {
        return $bytes . " B";
    }

    $unidades = ["KB", "MB", "GB", "TB"];
    $valor = $bytes / 1024;

    foreach ($unidades as $unidad) {
        if ($valor < 1024 || $unidad === end($unidades)) {
            return round($valor, 1) . " $unidad";
        }
        $valor /= 1024;
    }

    return $bytes . " B";

}

/** Conteo de filas de las tablas más relevantes, para el panel de salud. */
function salud_conteos_tablas(PDO $conexion): array {

    $tablas = [
        "usuarios" => "Usuarios",
        "alumnos" => "Alumnos",
        "documentos" => "Documentos institucionales",
        "trabajos" => "Trabajos",
        "comunicados" => "Comunicados",
        "eventos_institucionales" => "Eventos del calendario",
    ];

    $conteos = [];

    foreach ($tablas as $tabla => $etiqueta) {
        try {
            $total = (int) $conexion->query("SELECT COUNT(*) FROM `$tabla`")->fetchColumn();
        } catch (PDOException $e) {
            // La tabla podría no existir todavía si faltó correr
            // alguna migración anterior — no se cae el panel por eso.
            $total = null;
        }
        $conteos[] = ["etiqueta" => $etiqueta, "total" => $total];
    }

    return $conteos;

}
