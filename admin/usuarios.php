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
[$mensaje, $mensajeTipo] = flash_get();


// ==========================================
// 4. ROLES DISPONIBLES (para los formularios)
// ==========================================

$roles = $conexion->query("SELECT id_rol, nombre FROM roles ORDER BY nombre")->fetchAll();


// ==========================================
// 5. ACCIONES (crear / editar / activar / desactivar / eliminar)
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];

    if ($accion === "crear") {

        $nombres = trim($_POST["nombres"] ?? "");
        $apellidos = trim($_POST["apellidos"] ?? "");
        $correo = trim($_POST["correo"] ?? "");
        $password = $_POST["password"] ?? "";
        $id_rol = $_POST["id_rol"] ?? "";

        if ($nombres === "" || $apellidos === "" || $correo === "" || $password === "" || $id_rol === "") {

            $mensaje = "Debe completar todos los campos para crear un usuario.";
            $mensajeTipo = "error";

        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {

            $mensaje = "El correo ingresado no es válido.";
            $mensajeTipo = "error";

        } elseif (($errorPolitica = password_validar_politica($password)) !== null) {

            $mensaje = $errorPolitica;
            $mensajeTipo = "error";

        } else {

            try {

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conexion->prepare(
                    "INSERT INTO usuarios (nombres, apellidos, correo, password, estado, id_rol)
                     VALUES (?, ?, ?, ?, 'ACTIVO', ?)"
                );
                $stmt->execute([$nombres, $apellidos, $correo, $hash, $id_rol]);
                $idUsuarioNuevo = (int) $conexion->lastInsertId();
                password_marcar_cambio_obligatorio($conexion, $idUsuarioNuevo);

                auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "CREAR", "USUARIO", (int) $conexion->lastInsertId(), "Creó al usuario $nombres $apellidos ($correo)");

                $mensaje = "Usuario creado correctamente.";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo crear el usuario (el correo ya podría estar registrado).";
                $mensajeTipo = "error";

            }

        }

    } elseif ($accion === "guardar_edicion") {

        $id_usuario = (int)($_POST["id_usuario"] ?? 0);
        $nombres = trim($_POST["nombres"] ?? "");
        $apellidos = trim($_POST["apellidos"] ?? "");
        $correo = trim($_POST["correo"] ?? "");
        $id_rol = $_POST["id_rol"] ?? "";
        $password = $_POST["password"] ?? "";

        if ($id_usuario === 0 || $nombres === "" || $apellidos === "" || $correo === "" || $id_rol === "") {

            $mensaje = "Debe completar todos los campos para editar el usuario.";
            $mensajeTipo = "error";

        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {

            $mensaje = "El correo ingresado no es válido.";
            $mensajeTipo = "error";

        } elseif ($password !== "" && ($errorPolitica = password_validar_politica($password)) !== null) {

            $mensaje = $errorPolitica;
            $mensajeTipo = "error";

        } else {

            try {

                if ($password !== "") {

                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $conexion->prepare(
                        "UPDATE usuarios
                         SET nombres = ?, apellidos = ?, correo = ?, id_rol = ?, password = ?
                         WHERE id_usuario = ?"
                    );
                    $stmt->execute([$nombres, $apellidos, $correo, $id_rol, $hash, $id_usuario]);
                    password_marcar_cambio_obligatorio($conexion, $id_usuario);

                } else {

                    $stmt = $conexion->prepare(
                        "UPDATE usuarios
                         SET nombres = ?, apellidos = ?, correo = ?, id_rol = ?
                         WHERE id_usuario = ?"
                    );
                    $stmt->execute([$nombres, $apellidos, $correo, $id_rol, $id_usuario]);

                }

                auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "EDITAR", "USUARIO", $id_usuario, "Editó al usuario $nombres $apellidos ($correo)" . ($password !== "" ? " y cambió su contraseña" : ""));

                $mensaje = "Usuario actualizado correctamente.";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo actualizar el usuario (el correo ya podría estar registrado).";
                $mensajeTipo = "error";

            }

        }

    } elseif ($accion === "activar" || $accion === "desactivar") {

        $id_usuario = (int)($_POST["id_usuario"] ?? 0);
        $nuevoEstado = $accion === "activar" ? "ACTIVO" : "INACTIVO";

        if ($id_usuario === (int)$_SESSION["id_usuario"] && $nuevoEstado === "INACTIVO") {

            $mensaje = "No puede desactivar su propia cuenta.";
            $mensajeTipo = "error";

        } else {

            $stmt = $conexion->prepare("UPDATE usuarios SET estado = ? WHERE id_usuario = ?");
            $stmt->execute([$nuevoEstado, $id_usuario]);

            auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], strtoupper($accion), "USUARIO", $id_usuario, ($accion === "activar" ? "Activó" : "Desactivó") . " al usuario #$id_usuario");

            $mensaje = "Estado del usuario actualizado.";
            $mensajeTipo = "success";

        }

    } elseif ($accion === "eliminar") {

        $id_usuario = (int)($_POST["id_usuario"] ?? 0);

        if ($id_usuario === (int)$_SESSION["id_usuario"]) {

            $mensaje = "No puede eliminar su propia cuenta.";
            $mensajeTipo = "error";

        } else {

            // Papelera (borrado suave) en vez de DELETE directo: el
            // usuario deja de listarse/poder iniciar sesión, pero
            // queda recuperable desde Admin > Papelera. Ya no hace
            // falta el try/catch de antes por registros asociados,
            // porque no se borra ninguna fila.
            papelera_mover($conexion, "usuarios", "id_usuario", $id_usuario);

            auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "ELIMINAR", "USUARIO", $id_usuario, "Envió a la papelera al usuario #$id_usuario");

            $mensaje = "Usuario movido a la papelera. Puede restaurarlo desde Admin › Papelera.";
            $mensajeTipo = "success";

        }

    }

    // Evita el reenvío del formulario al recargar (PRG)
    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: usuarios.php?editar=" . (isset($_GET["editar"]) ? (int)$_GET["editar"] : ""));
    exit;

}


// ==========================================
// 6. USUARIO A EDITAR (si corresponde)
// ==========================================

$usuarioEditar = null;

if (isset($_GET["editar"]) && (int)$_GET["editar"] > 0) {

    $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, id_rol FROM usuarios WHERE id_usuario = ?");
    $stmt->execute([(int)$_GET["editar"]]);
    $usuarioEditar = $stmt->fetch();

}


// ==========================================
// 7. OBTENER TODOS LOS USUARIOS
// ==========================================

$sql = "
    SELECT
        u.id_usuario,
        u.nombres,
        u.apellidos,
        u.correo,
        u.estado,
        u.ultimo_acceso,
        r.nombre AS rol

    FROM usuarios u

    INNER JOIN roles r
        ON u.id_rol = r.id_rol

    WHERE u.eliminado_en IS NULL

    ORDER BY u.apellidos, u.nombres
";

$stmt = $conexion->query($sql);

$usuarios = $stmt->fetchAll();

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Usuarios · Panel de Administración - I.E.P. 88044 Abraham Valdelomar
    </title>

    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">

</head>


<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">



<header>

    <div>
        <h1>Gestionar usuarios</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>

    <div class="header-actions">
        <a href="papelera.php" class="btn-secondary"><?= icon("trash") ?> Papelera</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>

</header>


<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($usuarioEditar): ?>

        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Editar usuario</h3>
            <input type="hidden" name="accion" value="guardar_edicion">
            <input type="hidden" name="id_usuario" value="<?= (int)$usuarioEditar["id_usuario"] ?>">
            <div class="form-row">
                <div class="field">
                    <label for="e_nombres">Nombres</label>
                    <input type="text" name="nombres" id="e_nombres" value="<?= htmlspecialchars($usuarioEditar["nombres"]) ?>" required>
                </div>
                <div class="field">
                    <label for="e_apellidos">Apellidos</label>
                    <input type="text" name="apellidos" id="e_apellidos" value="<?= htmlspecialchars($usuarioEditar["apellidos"]) ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="e_correo">Correo</label>
                    <input type="email" name="correo" id="e_correo" value="<?= htmlspecialchars($usuarioEditar["correo"]) ?>" required>
                </div>
                <div class="field">
                    <label for="e_id_rol">Rol</label>
                    <select name="id_rol" id="e_id_rol" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r["id_rol"] ?>" <?= (int)$r["id_rol"] === (int)$usuarioEditar["id_rol"] ? "selected" : "" ?>>
                                <?= htmlspecialchars($r["nombre"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="e_password">Nueva contraseña (dejar en blanco para no cambiarla)</label>
                <input type="password" name="password" id="e_password" placeholder="Dejar vacío para no cambiarla" minlength="10" maxlength="72" title="Mínimo 10 caracteres, con letras y números">
            </div>
            <div class="row-actions">
                <button type="submit" class="btn-submit">Guardar cambios</button>
                <a href="usuarios.php" class="btn-secondary">Cancelar</a>
            </div>
        </form>

    <?php else: ?>

        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Crear usuario</h3>
            <input type="hidden" name="accion" value="crear">
            <div class="form-row">
                <div class="field">
                    <label for="nombres">Nombres</label>
                    <input type="text" name="nombres" id="nombres" placeholder="Ej. Juan" required>
                </div>
                <div class="field">
                    <label for="apellidos">Apellidos</label>
                    <input type="text" name="apellidos" id="apellidos" placeholder="Ej. Pérez Ríos" required>
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="correo">Correo</label>
                    <input type="email" name="correo" id="correo" placeholder="usuario@ie88044.edu.pe" required>
                </div>
                <div class="field">
                    <label for="id_rol">Rol</label>
                    <select name="id_rol" id="id_rol" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r["id_rol"] ?>"><?= htmlspecialchars($r["nombre"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input type="password" name="password" id="password" placeholder="Mín. 10 caracteres, letras y números" minlength="10" maxlength="72" title="Mínimo 10 caracteres, con letras y números" required>
            </div>
            <button type="submit" class="btn-submit">Crear usuario</button>
        </form>

    <?php endif; ?>

    <h2>Usuarios registrados (<?= count($usuarios) ?>)</h2>

    <?php if (count($usuarios) === 0): ?>

        <p class="placeholder-text">Todavía no hay usuarios registrados en el sistema.</p>

    <?php else: ?>

        <?php $cols = "1.4fr 1.6fr 1fr .8fr 1fr 1.4fr"; ?>
        <div class="list-rows">
            <div class="list-rows-head" style="grid-template-columns: <?= $cols ?>;">
                <span>Nombre</span>
                <span>Correo</span>
                <span>Rol</span>
                <span>Estado</span>
                <span>Último acceso</span>
                <span>Acciones</span>
            </div>
            <?php foreach ($usuarios as $u): ?>
                <div class="list-row" style="grid-template-columns: <?= $cols ?>;">
                    <div>
                        <span class="list-cell-label">Nombre</span>
                        <?= htmlspecialchars($u["nombres"]) ?>
                        <?= htmlspecialchars($u["apellidos"]) ?>
                    </div>
                    <div><span class="list-cell-label">Correo</span><?= htmlspecialchars($u["correo"]) ?></div>
                    <div><span class="list-cell-label">Rol</span><span class="role-badge"><?= htmlspecialchars($u["rol"]) ?></span></div>
                    <div>
                        <span class="list-cell-label">Estado</span>
                        <span class="status-badge status-<?= strtolower(htmlspecialchars($u["estado"])) ?>">
                            <?= htmlspecialchars($u["estado"]) ?>
                        </span>
                    </div>
                    <div>
                        <span class="list-cell-label">Último acceso</span>
                        <?= $u["ultimo_acceso"] ? htmlspecialchars($u["ultimo_acceso"]) : '—' ?>
                    </div>
                    <div>
                        <span class="list-cell-label">Acciones</span>
                        <div class="row-actions">
                            <a href="usuarios.php?editar=<?= (int)$u["id_usuario"] ?>" class="btn-mini">Editar</a>
                            <?php if ((int)$u["id_usuario"] !== (int)$_SESSION["id_usuario"]): ?>
                                <form method="POST" style="display:inline-block">
                                <?= csrf_field() ?>
                                    <input type="hidden" name="id_usuario" value="<?= (int)$u["id_usuario"] ?>">
                                    <?php if ($u["estado"] === "ACTIVO"): ?>
                                        <input type="hidden" name="accion" value="desactivar">
                                        <button type="submit" class="btn-mini btn-mini-reject">Desactivar</button>
                                    <?php else: ?>
                                        <input type="hidden" name="accion" value="activar">
                                        <button type="submit" class="btn-mini btn-mini-approve">Activar</button>
                                    <?php endif; ?>
                                </form>
                                <form method="POST" style="display:inline-block" onsubmit="return confirm('¿Enviar este usuario a la papelera? Podrá restaurarlo luego desde Admin › Papelera.');">
                                <?= csrf_field() ?>
                                    <input type="hidden" name="id_usuario" value="<?= (int)$u["id_usuario"] ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <button type="submit" class="btn-mini btn-mini-reject"><?= icon("trash") ?> Eliminar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
