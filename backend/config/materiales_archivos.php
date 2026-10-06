<?php

// ==========================================
// Subida segura de archivos para "PCA" y "Unidades" del
// Dashboard del Profesor. Misma política de seguridad que
// backend/config/subida_archivos.php (lista blanca de
// extensiones, límite de tamaño, nombre físico impredecible):
// nunca se confía en el nombre/tipo enviado por el navegador.
// ==========================================

require_once __DIR__ . "/validar_contenido_archivo.php";

const MATERIALES_EXTENSIONES_PERMITIDAS = [
    "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx",
    "txt", "zip", "rar", "jpg", "jpeg", "png",
];

// Sesiones solo acepta PDF (así se especificó para este módulo).
const SESIONES_EXTENSIONES_PERMITIDAS = ["pdf"];

const MATERIALES_TAMANO_MAXIMO_BYTES = 20 * 1024 * 1024; // 20 MB

const MATERIALES_DIRECTORIO_PCA = __DIR__ . "/../uploads/materiales/pca";
const MATERIALES_DIRECTORIO_UNIDADES = __DIR__ . "/../uploads/materiales/unidades";
const MATERIALES_DIRECTORIO_SESIONES = __DIR__ . "/../uploads/materiales/sesiones";

// Tutoría (SOLO Secundaria): carpetas propias, separadas de las de
// arriba, para que sus archivos nunca se mezclen físicamente con
// los de PCA/Unidades/Sesiones de las áreas normales.
const MATERIALES_DIRECTORIO_TUTORIA_PCA = __DIR__ . "/../uploads/materiales/tutoria/pca";
const MATERIALES_DIRECTORIO_TUTORIA_UNIDADES = __DIR__ . "/../uploads/materiales/tutoria/unidades";
const MATERIALES_DIRECTORIO_TUTORIA_SESIONES = __DIR__ . "/../uploads/materiales/tutoria/sesiones";

/**
 * Valida y mueve un archivo subido ($_FILES[...]) a la carpeta de
 * materiales indicada. Devuelve un arreglo con la info a guardar
 * en BD, o lanza RuntimeException con un mensaje seguro para
 * mostrar al usuario.
 *
 * @param array  $archivo    Entrada de $_FILES.
 * @param string $directorio Carpeta absoluta destino (MATERIALES_DIRECTORIO_PCA, _UNIDADES o _SESIONES).
 * @param string $rutaRelativaBase Prefijo de ruta relativa a guardar en BD (p.ej. "backend/uploads/materiales/pca/").
 * @param array  $extensionesPermitidas Lista blanca de extensiones para este módulo (por defecto, MATERIALES_EXTENSIONES_PERMITIDAS).
 */
function materiales_guardar_archivo(
    array $archivo,
    string $directorio,
    string $rutaRelativaBase,
    array $extensionesPermitidas = MATERIALES_EXTENSIONES_PERMITIDAS
): array {

    if (!isset($archivo["error"]) || is_array($archivo["error"])) {
        throw new RuntimeException("Parámetros de archivo inválidos.");
    }

    if ($archivo["error"] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("Debe seleccionar un archivo.");
    }

    if ($archivo["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Error al subir el archivo (código " . $archivo["error"] . ").");
    }

    if ($archivo["size"] <= 0 || $archivo["size"] > MATERIALES_TAMANO_MAXIMO_BYTES) {
        throw new RuntimeException("El archivo supera el tamaño máximo permitido (20 MB).");
    }

    if (!is_uploaded_file($archivo["tmp_name"])) {
        throw new RuntimeException("Archivo inválido.");
    }

    $nombreOriginal = $archivo["name"];
    $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

    if (!in_array($extension, $extensionesPermitidas, true)) {
        throw new RuntimeException(
            "Extensión no permitida. Solo se aceptan: " . implode(", ", $extensionesPermitidas) . "."
        );
    }

    // El contenido real debe coincidir con la extensión (no solo el nombre).
    archivo_validar_contenido($archivo["tmp_name"], $extension);

    if (!is_dir($directorio)) {
        mkdir($directorio, 0755, true);
    }

    // Nombre físico impredecible: nunca usamos el nombre original
    // como ruta en disco, para evitar path traversal / colisiones.
    $nombreFisico = bin2hex(random_bytes(16)) . "." . $extension;
    $rutaAbsoluta = $directorio . "/" . $nombreFisico;

    if (!move_uploaded_file($archivo["tmp_name"], $rutaAbsoluta)) {
        throw new RuntimeException("No se pudo guardar el archivo en el servidor.");
    }

    return [
        "nombre_archivo" => $nombreOriginal,
        "ruta_archivo" => rtrim($rutaRelativaBase, "/") . "/" . $nombreFisico,
        "ruta_absoluta" => $rutaAbsoluta,
        "extension" => $extension,
        "tamano_bytes" => $archivo["size"],
    ];

}

/**
 * Elimina físicamente el archivo en disco a partir de su ruta
 * relativa guardada en BD (backend/uploads/materiales/...). No
 * lanza error si el archivo ya no existe (evita romper el flujo
 * de "eliminar registro" por un archivo huérfano).
 */
function materiales_eliminar_archivo_fisico(string $rutaRelativa): void {

    $rutaAbsoluta = __DIR__ . "/../../" . $rutaRelativa;

    if (is_file($rutaAbsoluta)) {
        @unlink($rutaAbsoluta);
    }

}
