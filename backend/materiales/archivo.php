<?php

// ==========================================
// Sirve (ver / descargar) un archivo de PCA, Unidades o Sesiones
// del Profesor. Nunca se enlaza directamente a backend/uploads/...:
// todo pasa por aquí para poder comprobar sesión, rol y que el
// archivo pertenezca al profesor que lo pide.
//
// Uso:
//   archivo.php?modulo=pca&id=123&accion=ver
//   archivo.php?modulo=unidad&id=123&accion=descargar
//   archivo.php?modulo=sesion&id=123&accion=descargar
//   archivo.php?modulo=tutoria_pca&id=123&accion=ver
//   archivo.php?modulo=tutoria_unidad&id=123&accion=descargar
//   archivo.php?modulo=tutoria_sesion&id=123&accion=descargar
// ==========================================

session_start();
require_once __DIR__ . "/../config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    http_response_code(401);
    die("No autorizado.");
}

if (!in_array($_SESSION["rol"], ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true)) {
    http_response_code(403);
    die("Acceso no autorizado.");
}

require_once __DIR__ . "/../config/database.php";

$modulo = $_GET["modulo"] ?? "";
$id = (int) ($_GET["id"] ?? 0);
$accion = $_GET["accion"] ?? "ver";

if (!in_array($modulo, ["pca", "unidad", "sesion", "tutoria_pca", "tutoria_unidad", "tutoria_sesion"], true) || $id <= 0) {
    http_response_code(400);
    die("Solicitud inválida.");
}

// El profesor autenticado (nunca se confía en un id_profesor enviado por GET/POST)
$stmtProfesor = $conexion->prepare("SELECT id_profesor FROM profesores WHERE id_usuario = ?");
$stmtProfesor->execute([$_SESSION["id_usuario"]]);
$idProfesor = $stmtProfesor->fetchColumn();

if (!$idProfesor) {
    http_response_code(403);
    die("No se encontró el perfil del profesor.");
}

$tablasPorModulo = [
    "pca" => "profesor_pca_archivos",
    "unidad" => "profesor_unidad_archivos",
    "sesion" => "profesor_sesion_archivos",
    // Tutoría (SOLO Secundaria): tablas propias, independientes de
    // las de arriba — ver backend/config/migracion_tutoria.sql.
    "tutoria_pca" => "profesor_tutoria_pca_archivos",
    "tutoria_unidad" => "profesor_tutoria_unidad_archivos",
    "tutoria_sesion" => "profesor_tutoria_sesion_archivos",
];
$tabla = $tablasPorModulo[$modulo];

$stmt = $conexion->prepare("SELECT * FROM {$tabla} WHERE id_material = ? AND id_profesor = ?");
$stmt->execute([$id, $idProfesor]);
$archivo = $stmt->fetch();

if (!$archivo) {
    http_response_code(404);
    die("Archivo no encontrado o no pertenece a este profesor.");
}

$rutaAbsoluta = __DIR__ . "/../../" . $archivo["ruta_archivo"];

if (!is_file($rutaAbsoluta)) {
    http_response_code(404);
    die("El archivo ya no existe en el servidor.");
}

$mimesPorExtension = [
    "pdf" => "application/pdf",
    "doc" => "application/msword",
    "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "xls" => "application/vnd.ms-excel",
    "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "ppt" => "application/vnd.ms-powerpoint",
    "pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "txt" => "text/plain",
    "zip" => "application/zip",
    "rar" => "application/x-rar-compressed",
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png" => "image/png",
];

$mime = $mimesPorExtension[$archivo["extension"]] ?? "application/octet-stream";
$disposicion = $accion === "descargar" ? "attachment" : "inline";

header("Content-Type: " . $mime);
header("Content-Length: " . filesize($rutaAbsoluta));
header(
    "Content-Disposition: " . $disposicion . '; filename="' .
    str_replace('"', "", $archivo["nombre_archivo"]) . '"'
);
header("X-Content-Type-Options: nosniff");

readfile($rutaAbsoluta);
exit;
