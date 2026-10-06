<?php

// ==========================================
// Subida segura de archivos para "envíos de trabajo"
// (PROFESOR -> SUBDIRECTOR). Lista blanca de extensiones y
// límite de tamaño; nunca confía en el nombre/tipo enviado
// por el navegador para decidir si el archivo es válido.
// ==========================================

require_once __DIR__ . "/validar_contenido_archivo.php";

const ENVIOS_EXTENSIONES_PERMITIDAS = [
    "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx",
    "txt", "zip", "rar", "jpg", "jpeg", "png",
];

const ENVIOS_TAMANO_MAXIMO_BYTES = 20 * 1024 * 1024; // 20 MB

const ENVIOS_DIRECTORIO = __DIR__ . "/../uploads/envios";

/**
 * Valida y mueve un archivo subido ($_FILES[...]) a la carpeta de
 * envíos. Devuelve un arreglo con la info a guardar en BD, o lanza
 * RuntimeException con un mensaje seguro para mostrar al usuario.
 */
function envios_guardar_archivo(array $archivo): array {

    if (!isset($archivo["error"]) || is_array($archivo["error"])) {
        throw new RuntimeException("Parámetros de archivo inválidos.");
    }

    if ($archivo["error"] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("Debe seleccionar un archivo.");
    }

    if ($archivo["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Error al subir el archivo (código " . $archivo["error"] . ").");
    }

    if ($archivo["size"] <= 0 || $archivo["size"] > ENVIOS_TAMANO_MAXIMO_BYTES) {
        throw new RuntimeException("El archivo supera el tamaño máximo permitido (20 MB).");
    }

    if (!is_uploaded_file($archivo["tmp_name"])) {
        throw new RuntimeException("Archivo inválido.");
    }

    $nombreOriginal = $archivo["name"];
    $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

    if (!in_array($extension, ENVIOS_EXTENSIONES_PERMITIDAS, true)) {
        throw new RuntimeException(
            "Extensión no permitida. Solo se aceptan: " . implode(", ", ENVIOS_EXTENSIONES_PERMITIDAS) . "."
        );
    }

    // El contenido real debe coincidir con la extensión (no solo el nombre).
    archivo_validar_contenido($archivo["tmp_name"], $extension);

    if (!is_dir(ENVIOS_DIRECTORIO)) {
        mkdir(ENVIOS_DIRECTORIO, 0755, true);
    }

    // Nombre físico impredecible: nunca usamos el nombre original
    // como ruta en disco, para evitar path traversal / colisiones.
    $nombreFisico = bin2hex(random_bytes(16)) . "." . $extension;
    $rutaAbsoluta = ENVIOS_DIRECTORIO . "/" . $nombreFisico;

    if (!move_uploaded_file($archivo["tmp_name"], $rutaAbsoluta)) {
        throw new RuntimeException("No se pudo guardar el archivo en el servidor.");
    }

    return [
        "nombre_archivo" => $nombreOriginal,
        "ruta_archivo" => "backend/uploads/envios/" . $nombreFisico,
        "extension" => $extension,
        "tamano_bytes" => $archivo["size"],
    ];

}
