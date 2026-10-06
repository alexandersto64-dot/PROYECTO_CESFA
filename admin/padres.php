<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";


// ==========================================
// 1. COMPROBAR SESIÓN
// ==========================================

if (!isset($_SESSION["id_usuario"])) {

    header("Location: ../login.html");
    exit;

}


// ==========================================
// 2. COMPROBAR ROL
// ==========================================

if ($_SESSION["rol"] !== "ADMIN") {

    die("Acceso no autorizado.");

}


// ==========================================
// 3. CONECTAR A MYSQL
// ==========================================

require_once "../backend/config/database.php";
require_once "../backend/config/politica_password.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";
require_once "../backend/config/correo.php";
[$mensaje, $mensajeTipo] = flash_get();


// ==========================================
// 4. ID DEL ROL "PADRE" (el catálogo de roles no tiene IDs fijos)
// ==========================================

$idRolPadre = (int) $conexion->query("SELECT id_rol FROM roles WHERE nombre = 'PADRE'")->fetchColumn();


// ==========================================
// 5. ACCIONES (crear cuenta de padre / vincular a alumno / desvincular)
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];

    if ($accion === "crear_padre") {

        $nombres = trim($_POST["nombres"] ?? "");
        $apellidos = trim($_POST["apellidos"] ?? "");
        $correo = trim($_POST["correo"] ?? "");

        if ($nombres === "" || $apellidos === "" || $correo === "") {

            $mensaje = "Debe completar nombres, apellidos y correo para crear la cuenta del padre.";
            $mensajeTipo = "error";

        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {

            $mensaje = "El correo ingresado no es válido.";
            $mensajeTipo = "error";

        } else {

            try {

                // La contraseña ya no la escribe el Admin: se genera
                // sola (10 caracteres, letras+números fáciles de leer,
                // sin 0/O/1/l para no confundir al padre al copiarla)
                // y se envía por correo — el Admin nunca llega a verla.
                $alfabeto = "ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789";
                $password = "";
                for ($i = 0; $i < 10; $i++) {
                    $password .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conexion->prepare(
                    "INSERT INTO usuarios (nombres, apellidos, correo, password, estado, id_rol)
                     VALUES (?, ?, ?, ?, 'ACTIVO', ?)"
                );
                $stmt->execute([$nombres, $apellidos, $correo, $hash, $idRolPadre]);

                $idUsuarioPadreCreado = (int) $conexion->lastInsertId();
                password_marcar_cambio_obligatorio($conexion, $idUsuarioPadreCreado);

                auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "CREAR", "USUARIO",
                    $idUsuarioPadreCreado, "Creó la cuenta de padre $nombres $apellidos ($correo)");

                // Si de una vez eligió un alumno para vincular, lo hace en el mismo paso.
                $idAlumnoInicial = (int) ($_POST["id_alumno_inicial"] ?? 0);
                if ($idAlumnoInicial > 0) {
                    $stmtVinculo = $conexion->prepare(
                        "INSERT INTO padres_alumnos (id_usuario_padre, id_alumno) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE id_usuario_padre = id_usuario_padre"
                    );
                    $stmtVinculo->execute([$idUsuarioPadreCreado, $idAlumnoInicial]);
                    auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "VINCULAR", "PADRE_ALUMNO",
                        $idUsuarioPadreCreado, "Vinculó al padre $nombres $apellidos con el alumno #$idAlumnoInicial");
                }

                $correoEnviado = correo_enviar(
                    $correo,
                    $nombres,
                    "Tu cuenta del Intranet - I.E.P. 88044",
                    correo_plantilla("Bienvenido al Intranet", "
                        <p>Hola " . htmlspecialchars($nombres) . ",</p>
                        <p>Se creó tu cuenta de Padre de familia en el Intranet del I.E.P. 88044 Abraham Valdelomar.</p>
                        <p><strong>Correo:</strong> " . htmlspecialchars($correo) . "<br>
                        <strong>Contraseña temporal:</strong> " . htmlspecialchars($password) . "</p>
                        <p>Ingresa y cámbiala desde tu perfil en cuanto puedas.</p>
                    ")
                );

                $mensaje = "Cuenta de padre creada" . ($idAlumnoInicial > 0 ? " y vinculada al alumno" : "") . ". ";
                $mensaje .= $correoEnviado
                    ? "La contraseña se envió por correo a $correo."
                    : "El correo no se pudo enviar (revisa la configuración SMTP) — contraseña temporal: $password (cópiala y entrégasela tú mismo).";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo crear la cuenta (el correo ya podría estar registrado).";
                $mensajeTipo = "error";

            }

        }

    } elseif ($accion === "vincular") {

        $idUsuarioPadre = (int) ($_POST["id_usuario_padre"] ?? 0);
        $idAlumno = (int) ($_POST["id_alumno"] ?? 0);

        if ($idUsuarioPadre === 0 || $idAlumno === 0) {

            $mensaje = "Selecciona un padre y un alumno para vincular.";
            $mensajeTipo = "error";

        } else {

            $stmt = $conexion->prepare(
                "INSERT INTO padres_alumnos (id_usuario_padre, id_alumno) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE id_usuario_padre = id_usuario_padre"
            );
            $stmt->execute([$idUsuarioPadre, $idAlumno]);

            auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "VINCULAR", "PADRE_ALUMNO",
                $idUsuarioPadre, "Vinculó al usuario padre #$idUsuarioPadre con el alumno #$idAlumno");

            $mensaje = "Vínculo creado (o ya existía).";
            $mensajeTipo = "success";

        }

    } elseif ($accion === "desvincular") {

        $idPadreAlumno = (int) ($_POST["id_padre_alumno"] ?? 0);

        $stmt = $conexion->prepare("SELECT id_usuario_padre, id_alumno FROM padres_alumnos WHERE id_padre_alumno = ?");
        $stmt->execute([$idPadreAlumno]);
        $vinculo = $stmt->fetch();

        $conexion->prepare("DELETE FROM padres_alumnos WHERE id_padre_alumno = ?")->execute([$idPadreAlumno]);

        if ($vinculo) {
            auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "DESVINCULAR", "PADRE_ALUMNO",
                (int) $vinculo["id_usuario_padre"], "Quitó el vínculo con el alumno #" . $vinculo["id_alumno"]);
        }

        $mensaje = "Vínculo eliminado.";
        $mensajeTipo = "success";

    }

    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: padres.php");
    exit;

}


// ==========================================
// 6. DATOS PARA LOS FORMULARIOS Y LA TABLA
// ==========================================

$padres = $conexion->query("
    SELECT id_usuario, nombres, apellidos, correo
    FROM usuarios
    WHERE id_rol = $idRolPadre AND estado = 'ACTIVO'
    ORDER BY apellidos, nombres
")->fetchAll();

$alumnos = $conexion->query("
    SELECT id_alumno, nombres, apellidos, dni
    FROM alumnos
    WHERE estado = 'ACTIVO'
    ORDER BY apellidos, nombres
")->fetchAll();

$vinculos = $conexion->query("
    SELECT pa.id_padre_alumno,
           u.id_usuario AS id_usuario_padre, u.nombres AS padre_nombres, u.apellidos AS padre_apellidos, u.correo AS padre_correo,
           a.id_alumno, a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, a.dni AS alumno_dni
    FROM padres_alumnos pa
    INNER JOIN usuarios u ON u.id_usuario = pa.id_usuario_padre
    INNER JOIN alumnos a ON a.id_alumno = pa.id_alumno
    ORDER BY u.apellidos, u.nombres, a.apellidos, a.nombres
")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Padres de familia · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = "padres.php"; include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Padres de familia</h1>
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

    <?php if ($idRolPadre === 0): ?>
        <div class="panel-alert panel-alert-error">
            No existe el rol "PADRE" en la tabla <code>roles</code>. Sin ese rol no se pueden crear cuentas de padres.
        </div>
    <?php else: ?>

        <form class="panel-form" method="POST" style="margin-bottom: 20px;">
            <?= csrf_field() ?>
            <h3>Crear cuenta de padre</h3>
            <input type="hidden" name="accion" value="crear_padre">
            <div class="form-row">
                <div class="field">
                    <label for="nombres">Nombres</label>
                    <input type="text" name="nombres" id="nombres" required>
                </div>
                <div class="field">
                    <label for="apellidos">Apellidos</label>
                    <input type="text" name="apellidos" id="apellidos" required>
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="correo">Correo (con esto inicia sesión)</label>
                    <input type="email" name="correo" id="correo" required>
                </div>
            </div>
            <p class="placeholder-text" style="margin-top: -8px;">
                La contraseña se genera sola y se envía por correo — no necesitas escribirla ni comunicársela tú.
            </p>
            <div class="field">
                <label for="id_alumno_inicial">Vincular de una vez a este alumno (opcional)</label>
                <select name="id_alumno_inicial" id="id_alumno_inicial">
                    <option value="">— sin vincular por ahora —</option>
                    <?php foreach ($alumnos as $a): ?>
                        <option value="<?= (int) $a["id_alumno"] ?>">
                            <?= htmlspecialchars($a["apellidos"] . ", " . $a["nombres"] . " (DNI " . $a["dni"] . ")") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-submit">Crear cuenta de padre</button>
        </form>

        <?php if (count($padres) > 0 && count($alumnos) > 0): ?>
            <form class="panel-form" method="POST" style="margin-bottom: 20px;">
                <?= csrf_field() ?>
                <h3>Vincular padre existente a un alumno</h3>
                <input type="hidden" name="accion" value="vincular">
                <div class="form-row">
                    <div class="field">
                        <label for="id_usuario_padre">Padre</label>
                        <select name="id_usuario_padre" id="id_usuario_padre" required>
                            <option value="">Seleccione…</option>
                            <?php foreach ($padres as $p): ?>
                                <option value="<?= (int) $p["id_usuario"] ?>">
                                    <?= htmlspecialchars($p["apellidos"] . ", " . $p["nombres"] . " (" . $p["correo"] . ")") ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="id_alumno">Alumno</label>
                        <select name="id_alumno" id="id_alumno" required>
                            <option value="">Seleccione…</option>
                            <?php foreach ($alumnos as $a): ?>
                                <option value="<?= (int) $a["id_alumno"] ?>">
                                    <?= htmlspecialchars($a["apellidos"] . ", " . $a["nombres"] . " (DNI " . $a["dni"] . ")") ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Vincular</button>
            </form>
        <?php endif; ?>

    <?php endif; ?>

    <h2>Vínculos registrados (<?= count($vinculos) ?>)</h2>

    <?php if (count($vinculos) === 0): ?>
        <p class="placeholder-text">Todavía no hay ningún padre vinculado a un alumno.</p>
    <?php else: ?>
        <?php $cols = "1.4fr 2fr 1.6fr 1fr 1fr"; ?>
        <div class="list-rows">
            <div class="list-rows-head" style="grid-template-columns: <?= $cols ?>;">
                <span>Padre</span>
                <span>Correo</span>
                <span>Alumno</span>
                <span>DNI alumno</span>
                <span>Acciones</span>
            </div>
            <?php foreach ($vinculos as $v): ?>
                <div class="list-row" style="grid-template-columns: <?= $cols ?>;">
                    <div><span class="list-cell-label">Padre</span><?= htmlspecialchars($v["padre_apellidos"] . ", " . $v["padre_nombres"]) ?></div>
                    <div><span class="list-cell-label">Correo</span><?= htmlspecialchars($v["padre_correo"]) ?></div>
                    <div><span class="list-cell-label">Alumno</span><?= htmlspecialchars($v["alumno_apellidos"] . ", " . $v["alumno_nombres"]) ?></div>
                    <div><span class="list-cell-label">DNI alumno</span><?= htmlspecialchars($v["alumno_dni"]) ?></div>
                    <div>
                        <form method="POST" onsubmit="return confirm('¿Quitar este vínculo? El padre dejará de ver a este alumno.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="desvincular">
                            <input type="hidden" name="id_padre_alumno" value="<?= (int) $v["id_padre_alumno"] ?>">
                            <button type="submit" class="btn-mini btn-mini-reject">Quitar vínculo</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>
</div>
</div>

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
