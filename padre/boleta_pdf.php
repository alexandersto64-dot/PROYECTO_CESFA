<?php

// ==========================================
// Sirve (ver / descargar) el PDF de una boleta YA PUBLICADA al padre
// de familia. Nunca se enlaza directo a backend/uploads/boletas/:
// todo pasa por aquí para comprobar que
//   1. hay sesión de PADRE,
//   2. el alumno es hijo de ese padre,
//   3. la boleta está PUBLICADA (aprobada por Subdirección), y
//   4. la boleta pertenece a ese alumno (el que se resolvió por DNI
//      al importarla, no solo el "id_alumno" de referencia al subir).
//
// Uso:
//   boleta_pdf.php?id_boleta=3&id_alumno=12&accion=ver
//   boleta_pdf.php?id_boleta=3&id_alumno=12&accion=descargar
// ==========================================

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    http_response_code(401);
    die("No autorizado.");
}

if (($_SESSION["rol"] ?? "") !== "PADRE") {
    http_response_code(403);
    die("Acceso no autorizado.");
}

require_once __DIR__ . "/../backend/config/database.php";
require_once __DIR__ . "/../backend/config/padres.php";
require_once __DIR__ . "/../backend/config/politica_password.php";
require_once __DIR__ . "/../backend/config/admin_seguimiento.php";

if (password_cambio_obligatorio($conexion, (int) $_SESSION["id_usuario"])) {
    http_response_code(403);
    die("Debes elegir una contraseña nueva antes de ver las boletas.");
}

$idBoleta = (int) ($_GET["id_boleta"] ?? 0);
$idAlumno = (int) ($_GET["id_alumno"] ?? 0);
$accion = ($_GET["accion"] ?? "ver") === "descargar" ? "descargar" : "ver";

if ($idBoleta <= 0 || $idAlumno <= 0) {
    http_response_code(400);
    die("Solicitud inválida.");
}

if (!padre_verificar_hijo($conexion, (int) $_SESSION["id_usuario"], $idAlumno)) {
    http_response_code(403);
    die("Este alumno no está vinculado a tu cuenta.");
}

$stmt = $conexion->prepare("
    SELECT b.ruta_archivo, b.nombre_archivo_original
    FROM boletas_importadas b
    WHERE b.id_boleta = ?
      AND b.estado = 'PUBLICADA'
      AND EXISTS (
          SELECT 1 FROM boletas_notas_extraidas e
          WHERE e.id_boleta = b.id_boleta AND e.id_alumno_resuelto = ?
      )
");
$stmt->execute([$idBoleta, $idAlumno]);
$boleta = $stmt->fetch();

if (!$boleta) {
    http_response_code(404);
    die("La boleta no existe o todavía no fue publicada.");
}

$rutaAbsoluta = __DIR__ . "/../" . $boleta["ruta_archivo"];

if (!is_file($rutaAbsoluta)) {
    http_response_code(404);
    die("El archivo ya no existe en el servidor.");
}

// Nombre amigable para la descarga (sin caracteres que rompan el header)
$nombreDescarga = preg_replace('/[^A-Za-z0-9 _\.\-\(\)ÁÉÍÓÚáéíóúÑñ]/u', "", $boleta["nombre_archivo_original"]);
$nombreDescarga = trim($nombreDescarga) !== "" ? $nombreDescarga : "boleta.pdf";
if (strtolower(pathinfo($nombreDescarga, PATHINFO_EXTENSION)) !== "pdf") {
    $nombreDescarga .= ".pdf";
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

// Quién vio/descargó la boleta de qué alumno (datos de menores).
auditoria_evento(
    $conexion,
    (int) $_SESSION["id_usuario"],
    $accion === "descargar" ? "DESCARGAR" : "VER",
    "BOLETA",
    $idBoleta,
    "Boleta #$idBoleta del alumno #$idAlumno"
);

header("Content-Type: application/pdf");
header("Content-Length: " . filesize($rutaAbsoluta));
header("Content-Disposition: " . ($accion === "descargar" ? "attachment" : "inline") . "; filename=\"" . str_replace('"', "", $nombreDescarga) . "\"; filename*=UTF-8''" . rawurlencode($nombreDescarga));
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, max-age=0, must-revalidate");

readfile($rutaAbsoluta);
exit;
