<?php

session_start();

require_once "../config/database.php";
require_once "../config/red_cliente.php";
require_once "../config/login_throttle.php";
require_once "../config/admin_seguimiento.php";


// ==========================================
// COMPROBAR MÉTODO POST
// ==========================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: ../../login.html");
    exit;

}


// ==========================================
// CONFIGURACIÓN
// ==========================================

// Largo oficial del código de estudiante (dígitos, guardado como TEXTO).
const CODIGO_ESTUDIANTE_LARGO = 14;

// Mensaje único para cualquier fallo de credenciales: no revela si el
// correo/código existe, si tiene padre vinculado o si la clave es mala.
const LOGIN_MSG_GENERICO = "Usuario o contraseña incorrectos.";

// Hash bcrypt de una clave descartable. Se usa para que el tiempo de
// respuesta sea parecido aunque el usuario/código no exista.
const LOGIN_HASH_FALSO = '$2y$10$pcb3YVTpO.0SnwE8w/Du/.UenA8D40fpPSOOO8FLN8gBFDWG/5f62';


// ==========================================
// Helper: volver al login mostrando un mensaje
// de error claro y visible (sin exponer detalles
// internos ni contraseñas).
// ==========================================

function volverConError($mensaje) {

    header("Location: ../../login.html?error=" . urlencode($mensaje));
    exit;

}


// ==========================================
// OBTENER DATOS DEL FORMULARIO
// El campo se llama "usuario"; se acepta también "correo" por si
// alguna copia antigua de login.html sigue en caché del navegador.
// ==========================================

$identificador = trim((string) ($_POST["usuario"] ?? $_POST["correo"] ?? ""));
$password = (string) ($_POST["password"] ?? "");

if ($identificador === "" || $password === "") {

    volverConError("Debe completar todos los campos.");

}

if (strlen($identificador) > 150 || strlen($password) > 1000) {

    volverConError(LOGIN_MSG_GENERICO);

}


// ==========================================
// DETECTAR TIPO DE ENTRADA
//   - contiene "@"     -> correo
//   - solo dígitos     -> código de estudiante (se completa con ceros
//                         a la izquierda hasta 14; siempre como TEXTO)
//   - cualquier otra cosa -> no válido
// ==========================================

$modo = null;
$correo = null;
$codigo = null;

if (strpos($identificador, "@") !== false) {

    $modo = "correo";
    $correo = $identificador;

} else {

    $soloDigitos = preg_replace('/[\s\-\.]/', "", $identificador);

    if ($soloDigitos !== "" && ctype_digit($soloDigitos)
        && strlen($soloDigitos) <= CODIGO_ESTUDIANTE_LARGO) {

        $modo = "codigo";
        $codigo = str_pad($soloDigitos, CODIGO_ESTUDIANTE_LARGO, "0", STR_PAD_LEFT);

    }

}


// ==========================================
// COMPROBAR BLOQUEO POR DEMASIADOS INTENTOS
// ==========================================

$ip = ip_cliente();

$claveId = "id:" . ($modo === "codigo" ? $codigo : strtolower((string) ($correo ?? $identificador)));
$claveIp = "ip:" . $ip;

throttle_mantenimiento($conexion);

if (throttle_bloqueado($conexion, $claveId, LOGIN_MAX_INTENTOS_IDENTIFICADOR)
    || throttle_bloqueado($conexion, $claveIp, LOGIN_MAX_INTENTOS_IP)) {

    volverConError("Demasiados intentos fallidos. Espera 15 minutos e inténtalo nuevamente.");

}

// $motivo e $idUsuario van SOLO a la auditoría (el visitante siempre ve el
// mismo mensaje). Nunca se guarda lo que escribió en el campo usuario/clave:
// mucha gente teclea ahí su contraseña por error.
function fallo_credenciales(string $claveId, string $claveIp, string $mensaje = LOGIN_MSG_GENERICO, string $motivo = "Credenciales incorrectas", ?int $idUsuario = null) {

    global $conexion;

    $nId = throttle_registrar_fallo($conexion, $claveId);
    throttle_registrar_fallo($conexion, $claveIp);

    auditoria_evento($conexion, $idUsuario, "LOGIN_FALLIDO", "SESION", $idUsuario, $motivo);

    // Solo se avisa una vez, justo cuando se alcanza el límite (así un atacante
    // no puede llenar la tabla de auditoría con miles de filas).
    if ($nId === LOGIN_MAX_INTENTOS_IDENTIFICADOR) {
        auditoria_evento($conexion, $idUsuario, "LOGIN_BLOQUEADO", "SESION", $idUsuario, "Cuenta o código bloqueado 15 min por demasiados intentos fallidos");
    }

    volverConError($mensaje);

}

if ($modo === null) {

    // Ni correo ni código: se gasta el mismo tiempo que un intento real.
    password_verify($password, LOGIN_HASH_FALSO);
    fallo_credenciales($claveId, $claveIp, LOGIN_MSG_GENERICO, "Usuario con formato no válido");

}


// ==========================================
// BUSCAR USUARIO
// ==========================================

$usuario = null;
$idAlumnoDelCodigo = null;

if ($modo === "correo") {

    // ---- Acceso por correo (todos los roles, como siempre) ----

    $sql = "
        SELECT
            u.id_usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.password,
            u.estado,
            r.id_rol,
            r.nombre AS rol

        FROM usuarios u

        INNER JOIN roles r
            ON u.id_rol = r.id_rol

        WHERE u.correo = ?
          AND u.eliminado_en IS NULL

        LIMIT 1
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([$correo]);
    $fila = $stmt->fetch();

    // Siempre se verifica una contraseña (real o falsa) para igualar tiempos.
    $hash = $fila ? (string) $fila["password"] : LOGIN_HASH_FALSO;
    $claveOk = password_verify($password, $hash);

    if (!$fila || !$claveOk) {
        fallo_credenciales(
            $claveId, $claveIp, LOGIN_MSG_GENERICO,
            $fila ? "Contraseña incorrecta" : "Correo no registrado",
            $fila ? (int) $fila["id_usuario"] : null
        );
    }

    // Solo quien ya acertó la contraseña se entera de que la cuenta está inactiva.
    if ($fila["estado"] !== "ACTIVO") {
        auditoria_evento($conexion, (int) $fila["id_usuario"], "LOGIN_FALLIDO", "SESION", (int) $fila["id_usuario"], "Cuenta inactiva");
        volverConError("Tu cuenta se encuentra inactiva. Comunícate con el colegio.");
    }

    $usuario = $fila;

} else {

    // ---- Acceso por código de estudiante (solo PADRE) ----
    // código -> alumnos.id_alumno -> padres_alumnos -> usuarios

    $sql = "
        SELECT DISTINCT
            u.id_usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.password,
            u.estado,
            r.id_rol,
            r.nombre AS rol,
            a.id_alumno

        FROM alumnos a

        INNER JOIN padres_alumnos pa
            ON pa.id_alumno = a.id_alumno

        INNER JOIN usuarios u
            ON u.id_usuario = pa.id_usuario_padre

        INNER JOIN roles r
            ON r.id_rol = u.id_rol

        WHERE a.codigo_estudiante = ?
          AND a.eliminado_en IS NULL
          AND UPPER(r.nombre) = 'PADRE'
          AND u.estado = 'ACTIVO'
          AND u.eliminado_en IS NULL
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([$codigo]);
    $candidatos = $stmt->fetchAll();

    if (count($candidatos) === 0) {

        password_verify($password, LOGIN_HASH_FALSO);
        fallo_credenciales($claveId, $claveIp, LOGIN_MSG_GENERICO, "Código de estudiante sin cuenta de padre");

    }

    // Se prueba la contraseña con TODOS los candidatos (sin cortar al
    // primero) para no delatar por tiempo cuántos padres hay.
    $coinciden = [];

    foreach ($candidatos as $c) {

        if (password_verify($password, (string) $c["password"])) {
            $coinciden[] = $c;
        }

    }

    if (count($coinciden) === 0) {

        fallo_credenciales(
            $claveId, $claveIp, LOGIN_MSG_GENERICO, "Contraseña incorrecta (acceso por código)",
            count($candidatos) === 1 ? (int) $candidatos[0]["id_usuario"] : null
        );

    }

    if (count($coinciden) > 1) {

        // Dos padres del mismo alumno con la misma clave: no se puede
        // saber quién es. No se adivina; se pide entrar por correo.
        fallo_credenciales(
            $claveId,
            $claveIp,
            "No fue posible ingresar con el código. Ingresa con tu correo.",
            "Acceso por código ambiguo (varios padres con la misma clave)"
        );

    }

    $usuario = $coinciden[0];
    $idAlumnoDelCodigo = (int) $usuario["id_alumno"];

}


// ==========================================
// CREAR SESIÓN
// ==========================================

throttle_limpiar($conexion, $claveId);

// Se vacía la sesión anterior para no heredar datos de otra persona
// (por ejemplo un padre_id_alumno_activo viejo).
$_SESSION = [];

session_regenerate_id(true);

$_SESSION["id_usuario"] = $usuario["id_usuario"];

$_SESSION["nombres"] = $usuario["nombres"];

$_SESSION["apellidos"] = $usuario["apellidos"];

$_SESSION["correo"] = $usuario["correo"];

$_SESSION["rol"] = $usuario["rol"];

$_SESSION["id_rol"] = $usuario["id_rol"];

// Entrada por código: el hijo elegido queda como hijo activo del padre.
// padre_bootstrap.php lo vuelve a validar contra los hijos vinculados.
if ($idAlumnoDelCodigo !== null) {

    $_SESSION["padre_id_alumno_activo"] = $idAlumnoDelCodigo;

}


// ==========================================
// "RECORDARME": si el usuario marcó la casilla,
// la cookie de sesión dura 30 días en vez de
// cerrarse al salir del navegador. No afecta la
// validación del usuario/contraseña, solo cuánto
// dura la sesión ya autenticada.
// ==========================================

if (!empty($_POST["recordar"])) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        session_id(),
        time() + (30 * 24 * 60 * 60),
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );

}


// ==========================================
// ACTUALIZAR ÚLTIMO ACCESO
// ==========================================

$sql = "
    UPDATE usuarios

    SET ultimo_acceso = NOW()

    WHERE id_usuario = ?
";


$stmt = $conexion->prepare($sql);

$stmt->execute([
    $usuario["id_usuario"]
]);

auditoria_evento(
    $conexion,
    (int) $usuario["id_usuario"],
    "LOGIN_OK",
    "SESION",
    (int) $usuario["id_usuario"],
    "Inicio de sesión (" . strtolower(trim((string) $usuario["rol"])) . ", " . $modo . ")"
);


// ==========================================
// REDIRECCIONAR SEGÚN ROL
// ==========================================

switch (strtoupper(trim($usuario["rol"]))) {

    case "ADMIN":

        header(
            "Location: ../../admin/dashboard.php"
        );

        exit;


    case "SUBDIRECTOR":

        header(
            "Location: ../../subdirector/dashboard.php"
        );

        exit;


    case "PROFESOR":
    case "PROFESOR_PRIMARIA":
    case "PROFESOR_SECUNDARIO":

        header(
            "Location: ../../profesor/dashboard.php"
        );

        exit;


    case "PADRE":

        header(
            "Location: ../../padre/dashboard.php"
        );

        exit;


    case "AUXILIAR":

        // El módulo de Auxiliar (Fase 2) todavía no tiene páginas
        // propias — se deja este caso explícito para no caer en el
        // "default" (que destruiría la sesión y hablaría de un "rol
        // no válido" siendo en realidad un rol válido, solo que su
        // panel aún no está construido).
        session_destroy();
        volverConError("El panel de Auxiliar todavía no está disponible.");


    default:

        session_destroy();

        volverConError("Rol de usuario no válido.");

}
