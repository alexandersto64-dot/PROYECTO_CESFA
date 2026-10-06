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
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";

[$mensaje, $mensajeTipo] = flash_get();


// ==========================================
// 4. ACCIONES (crear / editar / activar / desactivar / eliminar)
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];

    if ($accion === "crear") {

        $nombres = trim($_POST["nombres"] ?? "");
        $apellidos = trim($_POST["apellidos"] ?? "");
        $dni = trim($_POST["dni"] ?? "");
        $fecha_nacimiento = trim($_POST["fecha_nacimiento"] ?? "");
        $apoderado_nombre = trim($_POST["apoderado_nombre"] ?? "");
        $apoderado_telefono = trim($_POST["apoderado_telefono"] ?? "");

        if ($nombres === "" || $apellidos === "" || $dni === "") {

            $mensaje = "Nombres, apellidos y DNI son obligatorios.";
            $mensajeTipo = "error";

        } elseif (!preg_match('/^\d{8}$/', $dni)) {

            $mensaje = "El DNI debe tener 8 dígitos.";
            $mensajeTipo = "error";

        } else {

            try {

                $stmt = $conexion->prepare(
                    "INSERT INTO alumnos (nombres, apellidos, dni, fecha_nacimiento, apoderado_nombre, apoderado_telefono, estado)
                     VALUES (?, ?, ?, ?, ?, ?, 'ACTIVO')"
                );
                $stmt->execute([
                    $nombres, $apellidos, $dni,
                    $fecha_nacimiento !== "" ? $fecha_nacimiento : null,
                    $apoderado_nombre !== "" ? $apoderado_nombre : null,
                    $apoderado_telefono !== "" ? $apoderado_telefono : null,
                ]);

                $mensaje = "Alumno registrado correctamente.";
                $mensajeTipo = "success";

                auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "CREAR", "ALUMNO", (int) $conexion->lastInsertId(), "Registró al alumno $nombres $apellidos (DNI $dni)");

            } catch (PDOException $e) {

                $mensaje = "No se pudo registrar el alumno (el DNI ya podría estar registrado).";
                $mensajeTipo = "error";

            }

        }

    } elseif ($accion === "guardar_edicion") {

        $id_alumno = (int)($_POST["id_alumno"] ?? 0);
        $nombres = trim($_POST["nombres"] ?? "");
        $apellidos = trim($_POST["apellidos"] ?? "");
        $dni = trim($_POST["dni"] ?? "");
        $fecha_nacimiento = trim($_POST["fecha_nacimiento"] ?? "");
        $apoderado_nombre = trim($_POST["apoderado_nombre"] ?? "");
        $apoderado_telefono = trim($_POST["apoderado_telefono"] ?? "");

        if ($id_alumno === 0 || $nombres === "" || $apellidos === "" || $dni === "") {

            $mensaje = "Nombres, apellidos y DNI son obligatorios.";
            $mensajeTipo = "error";

        } elseif (!preg_match('/^\d{8}$/', $dni)) {

            $mensaje = "El DNI debe tener 8 dígitos.";
            $mensajeTipo = "error";

        } else {

            try {

                $stmt = $conexion->prepare(
                    "UPDATE alumnos
                     SET nombres = ?, apellidos = ?, dni = ?, fecha_nacimiento = ?, apoderado_nombre = ?, apoderado_telefono = ?
                     WHERE id_alumno = ?"
                );
                $stmt->execute([
                    $nombres, $apellidos, $dni,
                    $fecha_nacimiento !== "" ? $fecha_nacimiento : null,
                    $apoderado_nombre !== "" ? $apoderado_nombre : null,
                    $apoderado_telefono !== "" ? $apoderado_telefono : null,
                    $id_alumno,
                ]);

                $mensaje = "Alumno actualizado correctamente.";
                $mensajeTipo = "success";

                auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "EDITAR", "ALUMNO", $id_alumno, "Editó al alumno $nombres $apellidos (DNI $dni)");

            } catch (PDOException $e) {

                $mensaje = "No se pudo actualizar el alumno (el DNI ya podría estar registrado).";
                $mensajeTipo = "error";

            }

        }

    } elseif ($accion === "activar" || $accion === "desactivar") {

        $id_alumno = (int)($_POST["id_alumno"] ?? 0);
        $nuevoEstado = $accion === "activar" ? "ACTIVO" : "INACTIVO";

        $stmt = $conexion->prepare("UPDATE alumnos SET estado = ? WHERE id_alumno = ?");
        $stmt->execute([$nuevoEstado, $id_alumno]);

        auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], strtoupper($accion), "ALUMNO", $id_alumno, ($accion === "activar" ? "Activó" : "Desactivó") . " al alumno #$id_alumno");

        $mensaje = "Estado del alumno actualizado.";
        $mensajeTipo = "success";

    } elseif ($accion === "eliminar") {

        $id_alumno = (int)($_POST["id_alumno"] ?? 0);

        papelera_mover($conexion, "alumnos", "id_alumno", $id_alumno);

        auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "ELIMINAR", "ALUMNO", $id_alumno, "Envió a la papelera al alumno #$id_alumno");

        $mensaje = "Alumno movido a la papelera. Puede restaurarlo desde Admin › Papelera.";
        $mensajeTipo = "success";

    }

    // Evita el reenvío del formulario al recargar (PRG)
    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: alumnos.php?editar=" . (isset($_GET["editar"]) ? (int)$_GET["editar"] : ""));
    exit;

}


// ==========================================
// 5. ALUMNO A EDITAR (si corresponde)
// ==========================================

$alumnoEditar = null;

if (isset($_GET["editar"]) && (int)$_GET["editar"] > 0) {

    $stmt = $conexion->prepare("SELECT * FROM alumnos WHERE id_alumno = ?");
    $stmt->execute([(int)$_GET["editar"]]);
    $alumnoEditar = $stmt->fetch();

}


// ==========================================
// 6. OBTENER TODOS LOS ALUMNOS
// ==========================================

$sql = "
    SELECT
        a.id_alumno, a.nombres, a.apellidos, a.dni, a.codigo_estudiante, a.estado,
        gs.nombre AS grado_seccion, gs.nivel

    FROM alumnos a

    LEFT JOIN matriculas m
        ON m.id_alumno = a.id_alumno
        AND m.estado = 'ACTIVA'

    LEFT JOIN grados_secciones gs
        ON gs.id_grado_seccion = m.id_grado_seccion

    WHERE a.eliminado_en IS NULL

    ORDER BY a.apellidos, a.nombres
";

$stmt = $conexion->query($sql);

$alumnos = $stmt->fetchAll();

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
        Alumnos · Panel de Administración - I.E.P. 88044 Abraham Valdelomar
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
        <h1>Gestionar alumnos</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>

    <div class="header-actions">
        <a href="../backend/reportes/alumnos_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Descargar PDF</a>
        <a href="papelera.php" class="btn-secondary"><?= icon("trash") ?> Papelera</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>

</header>


<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($alumnoEditar): ?>

        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Editar alumno</h3>
            <input type="hidden" name="accion" value="guardar_edicion">
            <input type="hidden" name="id_alumno" value="<?= (int)$alumnoEditar["id_alumno"] ?>">
            <div class="form-row">
                <div class="field">
                    <label for="e_nombres">Nombres</label>
                    <input type="text" name="nombres" id="e_nombres" value="<?= htmlspecialchars($alumnoEditar["nombres"]) ?>" required>
                </div>
                <div class="field">
                    <label for="e_apellidos">Apellidos</label>
                    <input type="text" name="apellidos" id="e_apellidos" value="<?= htmlspecialchars($alumnoEditar["apellidos"]) ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="e_dni">DNI</label>
                    <input type="text" name="dni" id="e_dni" maxlength="8" value="<?= htmlspecialchars($alumnoEditar["dni"]) ?>" required>
                </div>
                <div class="field">
                    <label>Código de estudiante (solo lectura)</label>
                    <input type="text" value="<?= htmlspecialchars((string) ($alumnoEditar["codigo_estudiante"] ?? "")) ?>" placeholder="Sin código" readonly disabled>
                </div>
                <div class="field">
                    <label for="e_fecha_nacimiento">Fecha de nacimiento</label>
                    <input type="date" name="fecha_nacimiento" id="e_fecha_nacimiento" value="<?= htmlspecialchars($alumnoEditar["fecha_nacimiento"] ?? "") ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="e_apoderado_nombre">Apoderado</label>
                    <input type="text" name="apoderado_nombre" id="e_apoderado_nombre" value="<?= htmlspecialchars($alumnoEditar["apoderado_nombre"] ?? "") ?>">
                </div>
                <div class="field">
                    <label for="e_apoderado_telefono">Teléfono del apoderado</label>
                    <input type="text" name="apoderado_telefono" id="e_apoderado_telefono" value="<?= htmlspecialchars($alumnoEditar["apoderado_telefono"] ?? "") ?>">
                </div>
            </div>
            <div class="row-actions">
                <button type="submit" class="btn-submit">Guardar cambios</button>
                <a href="alumnos.php" class="btn-secondary">Cancelar</a>
            </div>
        </form>

    <?php else: ?>

        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Registrar alumno</h3>
            <input type="hidden" name="accion" value="crear">
            <div class="form-row">
                <div class="field">
                    <label for="nombres">Nombres</label>
                    <input type="text" name="nombres" id="nombres" placeholder="Ej. María" required>
                </div>
                <div class="field">
                    <label for="apellidos">Apellidos</label>
                    <input type="text" name="apellidos" id="apellidos" placeholder="Ej. Quispe Rojas" required>
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="dni">DNI</label>
                    <input type="text" name="dni" id="dni" maxlength="8" placeholder="8 dígitos" required>
                </div>
                <div class="field">
                    <label for="fecha_nacimiento">Fecha de nacimiento</label>
                    <input type="date" name="fecha_nacimiento" id="fecha_nacimiento">
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="apoderado_nombre">Apoderado</label>
                    <input type="text" name="apoderado_nombre" id="apoderado_nombre" placeholder="Nombre del padre/madre/tutor">
                </div>
                <div class="field">
                    <label for="apoderado_telefono">Teléfono del apoderado</label>
                    <input type="text" name="apoderado_telefono" id="apoderado_telefono" placeholder="Ej. 987654321">
                </div>
            </div>
            <button type="submit" class="btn-submit">Registrar alumno</button>
        </form>

    <?php endif; ?>

    <h2>Alumnos registrados (<?= count($alumnos) ?>)</h2>

    <?php if (count($alumnos) === 0): ?>

        <p class="placeholder-text">Todavía no hay alumnos registrados. Usa el formulario para registrar el primero.</p>

    <?php else: ?>

        <?php $cols = "1.6fr .8fr 1.2fr 1.2fr .8fr 3.4fr"; ?>
        <div class="list-rows">
            <div class="list-rows-head" style="grid-template-columns: <?= $cols ?>;">
                <span>Nombre</span>
                <span>DNI</span>
                <span>Código de estudiante</span>
                <span>Grado / Sección</span>
                <span>Estado</span>
                <span>Acciones</span>
            </div>
            <?php foreach ($alumnos as $a): ?>
                <div class="list-row" style="grid-template-columns: <?= $cols ?>;">
                    <div>
                        <span class="list-cell-label">Nombre</span>
                        <?= htmlspecialchars($a["nombres"]) ?>
                        <?= htmlspecialchars($a["apellidos"]) ?>
                    </div>
                    <div><span class="list-cell-label">DNI</span><?= htmlspecialchars($a["dni"]) ?></div>
                    <div>
                        <span class="list-cell-label">Código</span>
                        <?= $a["codigo_estudiante"] !== null && $a["codigo_estudiante"] !== "" ? htmlspecialchars((string) $a["codigo_estudiante"]) : '<span class="placeholder-text">—</span>' ?>
                    </div>
                    <div>
                        <span class="list-cell-label">Grado</span>
                        <?php if ($a["grado_seccion"]): ?>
                            <?= htmlspecialchars(ucfirst(strtolower($a["nivel"]))) ?> · <?= htmlspecialchars($a["grado_seccion"]) ?>
                        <?php else: ?>
                            <span class="placeholder-text">Sin matrícula activa</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="list-cell-label">Estado</span>
                        <span class="status-badge status-<?= strtolower(htmlspecialchars($a["estado"])) ?>">
                            <?= htmlspecialchars($a["estado"]) ?>
                        </span>
                    </div>
                    <div>
                        <div class="row-actions">
                            <a href="alumnos.php?editar=<?= (int)$a["id_alumno"] ?>" class="btn-mini">Editar</a>
                            <a href="matriculas.php?alumno=<?= (int)$a["id_alumno"] ?>" class="btn-mini">Matricular</a>
                            <form method="POST">
                            <?= csrf_field() ?>
                                <input type="hidden" name="id_alumno" value="<?= (int)$a["id_alumno"] ?>">
                                <?php if ($a["estado"] === "ACTIVO"): ?>
                                    <input type="hidden" name="accion" value="desactivar">
                                    <button type="submit" class="btn-mini btn-mini-reject">Desactivar</button>
                                <?php else: ?>
                                    <input type="hidden" name="accion" value="activar">
                                    <button type="submit" class="btn-mini btn-mini-approve">Activar</button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" onsubmit="return confirm('¿Enviar este alumno a la papelera? Podrá restaurarlo luego desde Admin › Papelera.');">
                            <?= csrf_field() ?>
                                <input type="hidden" name="id_alumno" value="<?= (int)$a["id_alumno"] ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <button type="submit" class="btn-mini btn-mini-reject"><?= icon("trash") ?> Eliminar</button>
                            </form>
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
