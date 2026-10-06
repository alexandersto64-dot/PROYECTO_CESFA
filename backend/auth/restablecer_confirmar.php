<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/politica_password.php";
require_once __DIR__ . "/../config/admin_seguimiento.php";


// ==========================================
// COMPROBAR MÉTODO POST
// ==========================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../login.html");
    exit;
}


// ==========================================
// Helper: volver mostrando un mensaje de error, conservando
// el token en la URL para que el usuario no tenga que pedir
// otro enlace por un simple error de tipeo en la contraseña.
// ==========================================

function volverConError(string $mensaje, string $token) {
    header(
        "Location: ../../restablecer-contrasena.html?token=" . urlencode($token)
        . "&error=" . urlencode($mensaje)
    );
    exit;
}


// ==========================================
// LEER Y VALIDAR DATOS DEL FORMULARIO
// ==========================================

$tokenPlano = trim($_POST["token"] ?? "");
$password = $_POST["password"] ?? "";
$passwordConfirmar = $_POST["password_confirmar"] ?? "";

if ($tokenPlano === "") {
    volverConError("Enlace inválido o incompleto.", $tokenPlano);
}

$errorPolitica = password_validar_politica($password);

if ($errorPolitica !== null) {
    volverConError($errorPolitica, $tokenPlano);
}

if ($password !== $passwordConfirmar) {
    volverConError("Las contraseñas no coinciden.", $tokenPlano);
}


// ==========================================
// BUSCAR EL TOKEN (por su hash, nunca en texto plano) Y
// COMPROBAR QUE NO ESTÉ VENCIDO NI YA USADO
// ==========================================

$tokenHash = hash("sha256", $tokenPlano);

// "vencido" se calcula DENTRO de MySQL (expira_en < NOW()), no
// comparando con time()/strtotime() de PHP: así la comprobación
// usa el mismo reloj/zona horaria con el que se guardó expira_en
// (ver recuperar_solicitud.php), sin importar cómo esté
// configurada la zona horaria de PHP en el servidor.
$stmt = $conexion->prepare("
    SELECT id_restablecimiento, id_usuario, usado, (expira_en < NOW()) AS vencido
    FROM restablecimientos_password
    WHERE token_hash = ?
    LIMIT 1
");
$stmt->execute([$tokenHash]);
$restablecimiento = $stmt->fetch();

if (!$restablecimiento) {
    volverConError("Este enlace no es válido. Solicita uno nuevo.", "");
}

if ((int) $restablecimiento["usado"] === 1) {
    volverConError("Este enlace ya fue utilizado. Solicita uno nuevo.", "");
}

if ((int) $restablecimiento["vencido"] === 1) {
    volverConError("Este enlace venció. Solicita uno nuevo.", "");
}


// ==========================================
// ACTUALIZAR LA CONTRASEÑA Y MARCAR EL TOKEN COMO USADO
//
// Ambas operaciones en una transacción: si algo falla, ninguna
// de las dos queda a medias (o se actualiza la contraseña y se
// invalida el token, o no se hace ninguna de las dos cosas).
// ==========================================

try {

    $conexion->beginTransaction();

    $conexion->prepare("
        UPDATE usuarios SET password = ? WHERE id_usuario = ?
    ")->execute([
        password_hash($password, PASSWORD_DEFAULT),
        $restablecimiento["id_usuario"],
    ]);

    password_marcar_cambio_obligatorio($conexion, (int) $restablecimiento["id_usuario"], false);

    $conexion->prepare("
        UPDATE restablecimientos_password SET usado = 1 WHERE id_restablecimiento = ?
    ")->execute([$restablecimiento["id_restablecimiento"]]);

    $conexion->commit();

    auditoria_evento($conexion, (int) $restablecimiento["id_usuario"], "RESTABLECER_PASSWORD", "USUARIO", (int) $restablecimiento["id_usuario"], "Restableció su contraseña con el enlace del correo");

} catch (\Throwable $e) {

    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log("[restablecer_confirmar] Error al actualizar contraseña: " . $e->getMessage());
    volverConError("Ocurrió un error al guardar la nueva contraseña. Intenta nuevamente.", $tokenPlano);

}

header("Location: ../../login.html?reset=1");
exit;
