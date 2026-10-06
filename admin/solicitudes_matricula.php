<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";


// ==========================================
// 1. COMPROBAR SESIÓN Y ROL
// ==========================================

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if ($_SESSION["rol"] !== "ADMIN") {
    die("Acceso no autorizado.");
}


// ==========================================
// 2. CONECTAR Y CARGAR HELPERS
// ==========================================

require_once "../backend/config/database.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";
require_once "../backend/config/padres.php";
require_once "../backend/config/notificaciones.php";
require_once "../backend/config/correo.php";
[$mensaje, $mensajeTipo] = flash_get();

notificaciones_generar_avisos_solicitudes_pendientes($conexion);



// ==========================================
// 3. ACCIONES (aprobar / rechazar)
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $idSolicitud = (int) ($_POST["id_solicitud"] ?? 0);
    $accion = $_POST["accion"];

    try {

        if ($accion === "aprobar") {

            $resultado = solicitud_matricula_aprobar($conexion, $idSolicitud, (int) $_SESSION["id_usuario"]);

            $nombreCompleto = trim($resultado["alumno_nombres"] . " " . $resultado["alumno_apellidos"]);

            notificar_crear(
                $conexion,
                $resultado["id_usuario_padre"],
                "MATRICULA_APROBADA",
                "Tu solicitud de matrícula para $nombreCompleto fue aprobada. Ya puedes verlo en tus hijos vinculados.",
                "../padre/dashboard.php"
            );

            $stmtPadre = $conexion->prepare("SELECT nombres, correo FROM usuarios WHERE id_usuario = ?");
            $stmtPadre->execute([$resultado["id_usuario_padre"]]);
            $padreUsuario = $stmtPadre->fetch();
            if ($padreUsuario) {
                correo_enviar(
                    $padreUsuario["correo"],
                    $padreUsuario["nombres"],
                    "Matrícula aprobada - Intranet I.E.P. 88044",
                    correo_plantilla("Matrícula aprobada", "
                        <p>Hola " . htmlspecialchars($padreUsuario["nombres"]) . ",</p>
                        <p>Tu solicitud de matrícula para <strong>" . htmlspecialchars($nombreCompleto) . "</strong> fue aprobada.</p>
                        <p>Ya puedes ingresar al Intranet para verlo en tu lista de hijos.</p>
                    ")
                );
            }

            $mensaje = "Solicitud aprobada: se creó/actualizó al alumno, su matrícula y el vínculo con el padre.";
            $mensajeTipo = "success";

        } elseif ($accion === "rechazar") {

            $motivo = trim($_POST["motivo"] ?? "");
            if ($motivo === "") {
                throw new RuntimeException("Debes indicar el motivo del rechazo.");
            }

            $resultado = solicitud_matricula_rechazar($conexion, $idSolicitud, $motivo, (int) $_SESSION["id_usuario"]);
            $nombreCompleto = trim($resultado["alumno_nombres"] . " " . $resultado["alumno_apellidos"]);

            notificar_crear(
                $conexion,
                $resultado["id_usuario_padre"],
                "MATRICULA_RECHAZADA",
                "Tu solicitud de matrícula para $nombreCompleto fue rechazada. Motivo: $motivo",
                "../padre/dashboard.php"
            );

            $stmtPadre = $conexion->prepare("SELECT nombres, correo FROM usuarios WHERE id_usuario = ?");
            $stmtPadre->execute([$resultado["id_usuario_padre"]]);
            $padreUsuario = $stmtPadre->fetch();
            if ($padreUsuario) {
                correo_enviar(
                    $padreUsuario["correo"],
                    $padreUsuario["nombres"],
                    "Sobre tu solicitud de matrícula - Intranet I.E.P. 88044",
                    correo_plantilla("Solicitud de matrícula rechazada", "
                        <p>Hola " . htmlspecialchars($padreUsuario["nombres"]) . ",</p>
                        <p>Tu solicitud de matrícula para <strong>" . htmlspecialchars($nombreCompleto) . "</strong> no pudo ser aprobada.</p>
                        <p><strong>Motivo:</strong> " . htmlspecialchars($motivo) . "</p>
                        <p>Puedes volver a enviar la solicitud corrigiendo lo indicado.</p>
                    ")
                );
            }

            $mensaje = "Solicitud rechazada.";
            $mensajeTipo = "success";

        }

    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $mensajeTipo = "error";
    }

    flash_set($mensaje, $mensajeTipo);
    header("Location: solicitudes_matricula.php");
    exit;

}


// ==========================================
// 4. DATOS PARA LA VISTA
// ==========================================

$pendientes = solicitudes_matricula_pendientes($conexion);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes de matrícula · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = "solicitudes_matricula.php"; include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Solicitudes de matrícula</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <h2>Pendientes (<?= count($pendientes) ?>)</h2>

    <?php if (count($pendientes) === 0): ?>
        <p class="placeholder-text">No hay solicitudes de preinscripción pendientes.</p>
    <?php else: ?>
        <?php foreach ($pendientes as $s): ?>
            <div class="panel-form" style="margin-bottom: 16px;">
                <div class="form-row" style="justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h3 style="margin: 0 0 6px;"><?= htmlspecialchars($s["alumno_nombres"] . " " . $s["alumno_apellidos"]) ?></h3>
                        <p class="placeholder-text" style="margin: 0 0 4px;">
                            DNI <?= htmlspecialchars($s["alumno_dni"]) ?>
                            <?= $s["alumno_fecha_nacimiento"] ? " · Nacido " . htmlspecialchars(date("d/m/Y", strtotime($s["alumno_fecha_nacimiento"]))) : "" ?>
                        </p>
                        <p class="placeholder-text" style="margin: 0 0 4px;">
                            Solicita: <strong><?= htmlspecialchars($s["grado_nombre"]) ?> (<?= htmlspecialchars($s["nivel"]) ?>)</strong>
                            · <?= htmlspecialchars($s["periodo_nombre"]) ?>
                        </p>
                        <p class="placeholder-text" style="margin: 0;">
                            Enviado por: <?= htmlspecialchars($s["padre_nombres"] . " " . $s["padre_apellidos"]) ?> (<?= htmlspecialchars($s["padre_correo"]) ?>)
                            · <?= htmlspecialchars(date("d/m/Y", strtotime($s["creado_en"]))) ?>
                        </p>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:8px; min-width:220px;">
                        <form method="POST" onsubmit="return confirm('¿Aprobar esta matrícula? Se creará el alumno (si no existe) y su matrícula activa.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id_solicitud" value="<?= (int) $s["id_solicitud_matricula"] ?>">
                            <input type="hidden" name="accion" value="aprobar">
                            <button type="submit" class="btn-submit" style="width:100%;">Aprobar</button>
                        </form>
                        <form method="POST" onsubmit="return confirm('¿Rechazar esta solicitud?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id_solicitud" value="<?= (int) $s["id_solicitud_matricula"] ?>">
                            <input type="hidden" name="accion" value="rechazar">
                            <input type="text" name="motivo" placeholder="Motivo del rechazo" required style="width:100%; margin-bottom:6px;">
                            <button type="submit" class="btn-mini btn-mini-reject" style="width:100%;">Rechazar</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</main>
</div>
</div>

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
