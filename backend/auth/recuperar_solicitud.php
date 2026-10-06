<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/correo.php";
require_once __DIR__ . "/../config/admin_seguimiento.php";


// ==========================================
// COMPROBAR MÉTODO POST
// ==========================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../recuperar-contrasena.html");
    exit;
}


// ==========================================
// Helper: volver mostrando un mensaje de error
// ==========================================

function volverConError(string $mensaje) {
    header("Location: ../../recuperar-contrasena.html?error=" . urlencode($mensaje));
    exit;
}

/**
 * Vuelve siempre con el MISMO mensaje de "revisa tu correo", exista
 * o no el correo en la base de datos. Es intencional: si el mensaje
 * cambiara según exista o no la cuenta, cualquiera podría usar este
 * formulario para averiguar qué correos están registrados en el
 * Intranet (enumeración de usuarios). El correo real solo se envía
 * si la cuenta existe, pero la respuesta en pantalla es idéntica.
 */
function volverConConfirmacion() {
    header("Location: ../../recuperar-contrasena.html?enviado=1");
    exit;
}


// ==========================================
// VALIDAR CORREO
// ==========================================

$correo = trim($_POST["correo"] ?? "");

if ($correo === "" || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    volverConError("Ingresa un correo válido.");
}


// ==========================================
// BUSCAR USUARIO (solo activos)
// ==========================================

$stmt = $conexion->prepare("
    SELECT id_usuario, nombres, apellidos, correo
    FROM usuarios
    WHERE correo = ? AND estado = 'ACTIVO'
    LIMIT 1
");
$stmt->execute([$correo]);
$usuario = $stmt->fetch();

// Sin cuenta activa con ese correo: misma respuesta que si todo
// hubiera salido bien (ver volverConConfirmacion()), simplemente
// sin generar token ni enviar nada.
if (!$usuario) {
    volverConConfirmacion();
}


// ==========================================
// GENERAR TOKEN DE UN SOLO USO (válido 1 hora)
//
// El token en texto plano SOLO va en el enlace del correo; en BD
// se guarda su hash (sha256), igual de irreversible que un
// password_hash para este propósito de un solo uso y corta
// duración. Cualquier restablecimiento previo sin usar del mismo
// usuario queda inutilizado al no coincidir con el hash nuevo.
//
// expira_en se calcula con NOW() + INTERVAL 1 HOUR DENTRO de MySQL
// (no con time()/date() de PHP): así el "creado_en" (que pone
// MySQL con CURRENT_TIMESTAMP) y el "expira_en" siempre usan el
// mismo reloj/zona horaria, aunque PHP y MySQL tengan configuradas
// zonas horarias distintas en el servidor.
// ==========================================

$tokenPlano = bin2hex(random_bytes(32));
$tokenHash = hash("sha256", $tokenPlano);

$conexion->prepare("
    INSERT INTO restablecimientos_password (id_usuario, token_hash, expira_en)
    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
")->execute([$usuario["id_usuario"], $tokenHash]);

auditoria_evento($conexion, (int) $usuario["id_usuario"], "SOLICITAR_RESTABLECER", "USUARIO", (int) $usuario["id_usuario"], "Pidió un enlace para restablecer su contraseña");


// ==========================================
// ENVIAR CORREO
//
// Si el envío falla (SMTP no configurado, etc.), correo_enviar()
// devuelve false y ya registró el error — no se interrumpe el
// flujo: el usuario ve la misma confirmación genérica.
// ==========================================

// __DIR__ = backend/auth ; el proyecto vive en la raíz del sitio.
$baseUrl = rtrim(dirname(dirname(dirname($_SERVER["SCRIPT_NAME"]))), "/");
$enlace = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" ? "https://" : "http://")
    . $_SERVER["HTTP_HOST"]
    . $baseUrl
    . "/restablecer-contrasena.html?token=" . urlencode($tokenPlano);

$nombreCompleto = trim($usuario["nombres"] . " " . $usuario["apellidos"]);

$cuerpo = "
    <p>Hola " . htmlspecialchars($usuario["nombres"]) . ",</p>
    <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en el Intranet del colegio.</p>
    <p style=\"text-align:center; margin:28px 0;\">
        <a href=\"" . htmlspecialchars($enlace) . "\"
           style=\"background:#1D4ED8; color:#FFFFFF; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:bold; display:inline-block;\">
            Elegir nueva contraseña
        </a>
    </p>
    <p>Este enlace es válido por 1 hora y solo puede usarse una vez.</p>
    <p>Si tú no solicitaste este cambio, puedes ignorar este correo — tu contraseña actual seguirá funcionando.</p>
";

correo_enviar(
    $usuario["correo"],
    $nombreCompleto,
    "Recuperar tu contraseña - Intranet I.E.P. 88044",
    correo_plantilla("Recuperar contraseña", $cuerpo)
);

volverConConfirmacion();
