<?php

// ==========================================
// Límite de intentos fallidos de login.
//
// Guarda los fallos en la tabla `login_intentos` (ventana deslizante de
// 15 min). Así el bloqueo no depende de la carpeta temporal del
// servidor, que en hosting compartido puede vaciarse o compartirse.
//
// Si la tabla todavía no existe (migracion_login_intentos.sql pendiente)
// se usa el método anterior con archivos temporales, así que el login
// nunca deja de funcionar.
// ==========================================

const LOGIN_MAX_INTENTOS_IDENTIFICADOR = 5;   // por usuario/código
const LOGIN_MAX_INTENTOS_IP            = 30;  // por IP
const LOGIN_VENTANA_SEGUNDOS           = 900; // 15 minutos

function throttle_clave_hash(string $clave): string {
    return hash("sha256", $clave);
}

/** true si la tabla login_intentos existe y responde. */
function throttle_usa_bd(PDO $conexion): bool {

    static $ok = null;

    if ($ok === null) {
        try {
            $conexion->query("SELECT 1 FROM login_intentos LIMIT 1");
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false;
        }
    }

    return $ok;

}

function throttle_bloqueado(PDO $conexion, string $clave, int $max): bool {

    if (throttle_usa_bd($conexion)) {
        $stmt = $conexion->prepare("
            SELECT COUNT(*) FROM login_intentos
            WHERE clave = ? AND creado_en > (NOW() - INTERVAL " . LOGIN_VENTANA_SEGUNDOS . " SECOND)
        ");
        $stmt->execute([throttle_clave_hash($clave)]);
        return (int) $stmt->fetchColumn() >= $max;
    }

    $datos = throttle_archivo_leer($clave);
    return $datos !== null && (int) $datos["n"] >= $max;

}

/** Registra un fallo y devuelve cuántos lleva dentro de la ventana. */
function throttle_registrar_fallo(PDO $conexion, string $clave): int {

    if (throttle_usa_bd($conexion)) {
        $hash = throttle_clave_hash($clave);
        $conexion->prepare("INSERT INTO login_intentos (clave) VALUES (?)")->execute([$hash]);
        $stmt = $conexion->prepare("
            SELECT COUNT(*) FROM login_intentos
            WHERE clave = ? AND creado_en > (NOW() - INTERVAL " . LOGIN_VENTANA_SEGUNDOS . " SECOND)
        ");
        $stmt->execute([$hash]);
        return (int) $stmt->fetchColumn();
    }

    return throttle_archivo_registrar($clave);

}

function throttle_limpiar(PDO $conexion, string $clave): void {

    if (throttle_usa_bd($conexion)) {
        $conexion->prepare("DELETE FROM login_intentos WHERE clave = ?")->execute([throttle_clave_hash($clave)]);
        return;
    }

    @unlink(throttle_archivo_ruta($clave));

}

/** De vez en cuando borra intentos viejos para que la tabla no crezca. */
function throttle_mantenimiento(PDO $conexion): void {

    if (random_int(1, 50) !== 1) {
        return;
    }

    if (throttle_usa_bd($conexion)) {
        $conexion->exec("DELETE FROM login_intentos WHERE creado_en < (NOW() - INTERVAL " . (LOGIN_VENTANA_SEGUNDOS * 2) . " SECOND)");
        return;
    }

    foreach (glob(throttle_archivo_dir() . DIRECTORY_SEPARATOR . "*.json") ?: [] as $f) {
        if ((time() - (int) @filemtime($f)) > (LOGIN_VENTANA_SEGUNDOS * 2)) {
            @unlink($f);
        }
    }

}


// ----------------------------------------------------------
// Respaldo con archivos temporales (solo si falta la tabla)
// ----------------------------------------------------------

function throttle_archivo_dir(): string {

    $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . "ie88044_login";

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;

}

function throttle_archivo_ruta(string $clave): string {
    return throttle_archivo_dir() . DIRECTORY_SEPARATOR . hash("sha256", $clave) . ".json";
}

function throttle_archivo_leer(string $clave): ?array {

    $archivo = throttle_archivo_ruta($clave);

    if (!is_file($archivo)) {
        return null;
    }

    $datos = json_decode((string) @file_get_contents($archivo), true);

    if (!is_array($datos) || !isset($datos["n"], $datos["t"])) {
        return null;
    }

    if ((time() - (int) $datos["t"]) > LOGIN_VENTANA_SEGUNDOS) {
        @unlink($archivo);
        return null;
    }

    return $datos;

}

function throttle_archivo_registrar(string $clave): int {

    $fh = @fopen(throttle_archivo_ruta($clave), "c+");

    if (!$fh) {
        return 0; // si no se puede escribir, el login sigue funcionando
    }

    $n = 0;

    if (flock($fh, LOCK_EX)) {

        $datos = json_decode((string) stream_get_contents($fh), true);

        if (!is_array($datos) || !isset($datos["n"], $datos["t"])
            || (time() - (int) $datos["t"]) > LOGIN_VENTANA_SEGUNDOS) {
            $datos = ["n" => 0, "t" => time()];
        }

        $datos["n"] = (int) $datos["n"] + 1;
        $n = $datos["n"];

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($datos));
        fflush($fh);
        flock($fh, LOCK_UN);

    }

    fclose($fh);

    return $n;

}
