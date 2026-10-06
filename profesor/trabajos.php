<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";

// ==================================================
// CREAR / EDITAR / ELIMINAR TRABAJOS
//
// Misma lógica que antes vivía en dashboard.php, solo movida a su
// propia página. Todas las consultas de edición/eliminación llevan
// "AND id_profesor = ?" (con el id_profesor de la sesión actual,
// nunca uno enviado por el formulario), para que un profesor no
// pueda modificar ni eliminar trabajos de otro aunque manipule el
// id_trabajo en la petición.
// ==================================================

$mensaje = null;
$mensajeTipo = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];

    if ($accion === "crear") {

        $titulo = trim($_POST["titulo"] ?? "");
        $descripcion = trim($_POST["descripcion"] ?? "");
        $fecha_limite = trim($_POST["fecha_limite"] ?? "");
        $id_curso = $_POST["id_curso"] ?? "";
        $id_tipo_trabajo = $_POST["id_tipo_trabajo"] ?? "";
        $id_periodo = $_POST["id_periodo"] ?? "";

        if ($titulo === "" || $fecha_limite === "" || $id_curso === "" || $id_tipo_trabajo === "" || $id_periodo === "") {

            $mensaje = "Debe completar todos los campos obligatorios.";
            $mensajeTipo = "error";

        } else {

            $stmt = $conexion->prepare("
                INSERT INTO trabajos (titulo, descripcion, fecha_limite, estado, id_curso, id_tipo_trabajo, id_periodo, id_profesor)
                VALUES (?, ?, ?, 'PENDIENTE', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $titulo,
                $descripcion !== "" ? $descripcion : null,
                $fecha_limite,
                $id_curso,
                $id_tipo_trabajo,
                $id_periodo,
                $profesor["id_profesor"],
            ]);

            $mensaje = "Trabajo creado correctamente.";
            $mensajeTipo = "success";

        }

    } elseif ($accion === "guardar_edicion") {

        $id_trabajo = (int)($_POST["id_trabajo"] ?? 0);
        $titulo = trim($_POST["titulo"] ?? "");
        $descripcion = trim($_POST["descripcion"] ?? "");
        $fecha_limite = trim($_POST["fecha_limite"] ?? "");
        $estado = $_POST["estado"] ?? "PENDIENTE";
        $id_curso = $_POST["id_curso"] ?? "";
        $id_tipo_trabajo = $_POST["id_tipo_trabajo"] ?? "";
        $id_periodo = $_POST["id_periodo"] ?? "";

        if ($id_trabajo === 0 || $titulo === "" || $fecha_limite === "" || $id_curso === "" || $id_tipo_trabajo === "" || $id_periodo === "") {

            $mensaje = "Debe completar todos los campos obligatorios.";
            $mensajeTipo = "error";

        } else {

            $stmt = $conexion->prepare("
                UPDATE trabajos
                SET titulo = ?, descripcion = ?, fecha_limite = ?, estado = ?,
                    id_curso = ?, id_tipo_trabajo = ?, id_periodo = ?
                WHERE id_trabajo = ? AND id_profesor = ?
            ");
            $stmt->execute([
                $titulo,
                $descripcion !== "" ? $descripcion : null,
                $fecha_limite,
                $estado,
                $id_curso,
                $id_tipo_trabajo,
                $id_periodo,
                $id_trabajo,
                $profesor["id_profesor"],
            ]);

            if ($stmt->rowCount() === 0) {

                $mensaje = "No se pudo editar: el trabajo no existe o no le pertenece.";
                $mensajeTipo = "error";

            } else {

                $mensaje = "Trabajo actualizado correctamente.";
                $mensajeTipo = "success";

            }

        }

        // Invalida el cache de sesión del Dashboard (dashboard_cache()
        // en profesor/dashboard.php) para que "Trabajos registrados" y
        // "Con entrega esta semana" se vean al instante, sin esperar
        // el TTL de 2.5 min — mismo criterio que ya se aplicó en
        // profesor/envios.php.
        unset($_SESSION["dashboard_cache"]["trabajos_resumen_" . $profesor["id_profesor"]]);

        header("Location: trabajos.php");
        exit;

    } elseif ($accion === "eliminar") {

        $id_trabajo = (int)($_POST["id_trabajo"] ?? 0);

        $stmt = $conexion->prepare("DELETE FROM trabajos WHERE id_trabajo = ? AND id_profesor = ?");
        $stmt->execute([$id_trabajo, $profesor["id_profesor"]]);

        if ($stmt->rowCount() === 0) {

            $mensaje = "No se pudo eliminar: el trabajo no existe o no le pertenece.";
            $mensajeTipo = "error";

        } else {

            $mensaje = "Trabajo eliminado correctamente.";
            $mensajeTipo = "success";

        }

    }

    // Misma invalidación que arriba, para "crear" y "eliminar"
    // (ambas terminan en este redirect común).
    unset($_SESSION["dashboard_cache"]["trabajos_resumen_" . $profesor["id_profesor"]]);

    header("Location: trabajos.php");
    exit;

}


// ==================================================
// LISTAS PARA LOS FORMULARIOS (cursos, tipos, periodos)
// ==================================================

$cursos = $conexion->query("SELECT id_curso, nombre, nivel FROM cursos WHERE eliminado_en IS NULL ORDER BY nivel, nombre")->fetchAll();
$tiposTrabajo = $conexion->query("SELECT id_tipo_trabajo, nombre FROM tipos_trabajo ORDER BY nombre")->fetchAll();
$periodos = $conexion->query("SELECT id_periodo, nombre FROM periodos_academicos ORDER BY id_periodo")->fetchAll();


// ==================================================
// TRABAJO A EDITAR (si corresponde)
//
// También filtrado por id_profesor: un profesor no puede ni
// siquiera cargar en el formulario un trabajo que no es suyo.
// ==================================================

$trabajoEditar = null;

if (isset($_GET["editar"]) && (int)$_GET["editar"] > 0) {

    $stmt = $conexion->prepare("SELECT * FROM trabajos WHERE id_trabajo = ? AND id_profesor = ?");
    $stmt->execute([(int)$_GET["editar"], $profesor["id_profesor"]]);
    $trabajoEditar = $stmt->fetch();

}


// ==================================================
// TRABAJOS DEL PROFESOR
// ==================================================

$sql = "
    SELECT
        t.id_trabajo,
        t.titulo,
        t.descripcion,
        t.fecha_limite,
        t.estado,

        c.nombre AS curso,

        tt.nombre AS tipo_trabajo,

        p.nombre AS periodo

    FROM trabajos t

    INNER JOIN cursos c
        ON t.id_curso = c.id_curso

    INNER JOIN tipos_trabajo tt
        ON t.id_tipo_trabajo = tt.id_tipo_trabajo

    INNER JOIN periodos_academicos p
        ON t.id_periodo = p.id_periodo

    WHERE t.id_profesor = ?

    ORDER BY t.fecha_limite ASC
";


$stmt = $conexion->prepare($sql);

$stmt->execute([
    $profesor["id_profesor"]
]);

$trabajos = $stmt->fetchAll();

$gradosAsignados = profesor_grados_asignados($conexion, $profesor["id_profesor"]);

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
        Mis Trabajos - I.E.P. 88044 Abraham Valdelomar
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
        <span class="header-eyebrow">Seguimiento</span>
        <h1>Mis trabajos</h1>
    </div>

    <div class="header-actions">
        <div class="header-identity">
            <div class="header-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($profesor["nombres"], 0, 1)) . mb_strtoupper(mb_substr($profesor["apellidos"], 0, 1))) ?></div>
            <div class="header-identity-text">
                <strong><?= htmlspecialchars($profesor["nombres"]) ?> <?= htmlspecialchars($profesor["apellidos"]) ?></strong>
                <span>I.E.P. 88044</span>
            </div>
        </div>
    </div>

</header>


<main>

    <section class="panel-section" id="crear">

        <div class="panel-section-head">
            <h2>
                <?= icon("briefcase") ?> Mis trabajos
            </h2>
        </div>

        <?php if ($mensaje): ?>
            <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <?php if ($trabajoEditar): ?>

            <form class="panel-form" method="POST">
            <?= csrf_field() ?>
                <h3>Editar trabajo</h3>
                <input type="hidden" name="accion" value="guardar_edicion">
                <input type="hidden" name="id_trabajo" value="<?= (int)$trabajoEditar["id_trabajo"] ?>">
                <div class="field">
                    <label for="t_titulo">Título</label>
                    <input type="text" name="titulo" id="t_titulo" value="<?= htmlspecialchars($trabajoEditar["titulo"]) ?>" required>
                </div>
                <div class="field">
                    <label for="t_descripcion">Descripción</label>
                    <textarea name="descripcion" id="t_descripcion"><?= htmlspecialchars($trabajoEditar["descripcion"] ?? "") ?></textarea>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="t_curso">Curso</label>
                        <select name="id_curso" id="t_curso" required>
                            <?php foreach ($cursos as $c): ?>
                                <option value="<?= (int)$c["id_curso"] ?>" <?= (int)$c["id_curso"] === (int)$trabajoEditar["id_curso"] ? "selected" : "" ?>>
                                    <?= htmlspecialchars($c["nombre"] . " · " . ucfirst(strtolower($c["nivel"]))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="t_tipo">Tipo de trabajo</label>
                        <select name="id_tipo_trabajo" id="t_tipo" required>
                            <?php foreach ($tiposTrabajo as $t): ?>
                                <option value="<?= (int)$t["id_tipo_trabajo"] ?>" <?= (int)$t["id_tipo_trabajo"] === (int)$trabajoEditar["id_tipo_trabajo"] ? "selected" : "" ?>>
                                    <?= htmlspecialchars($t["nombre"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="t_periodo">Periodo</label>
                        <select name="id_periodo" id="t_periodo" required>
                            <?php foreach ($periodos as $p): ?>
                                <option value="<?= (int)$p["id_periodo"] ?>" <?= (int)$p["id_periodo"] === (int)$trabajoEditar["id_periodo"] ? "selected" : "" ?>>
                                    <?= htmlspecialchars($p["nombre"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="t_fecha">Fecha límite</label>
                        <input type="date" name="fecha_limite" id="t_fecha" value="<?= htmlspecialchars($trabajoEditar["fecha_limite"]) ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="t_estado">Estado</label>
                    <select name="estado" id="t_estado">
                        <option value="PENDIENTE" <?= $trabajoEditar["estado"] === "PENDIENTE" ? "selected" : "" ?>>PENDIENTE</option>
                        <option value="ENTREGADO" <?= $trabajoEditar["estado"] === "ENTREGADO" ? "selected" : "" ?>>ENTREGADO</option>
                        <option value="CERRADO" <?= $trabajoEditar["estado"] === "CERRADO" ? "selected" : "" ?>>CERRADO</option>
                    </select>
                </div>
                <div class="row-actions">
                    <button type="submit" class="btn-submit">Guardar cambios</button>
                    <a href="trabajos.php" class="btn-secondary">Cancelar</a>
                </div>
            </form>

        <?php else: ?>

            <form class="panel-form" method="POST">
            <?= csrf_field() ?>
                <h3>Crear trabajo</h3>
                <input type="hidden" name="accion" value="crear">
                <div class="field">
                    <label for="n_titulo">Título</label>
                    <input type="text" name="titulo" id="n_titulo" placeholder="Ej. Tarea de Matemática" required>
                </div>
                <div class="field">
                    <label for="n_descripcion">Descripción</label>
                    <textarea name="descripcion" id="n_descripcion" placeholder="Detalle del trabajo"></textarea>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="n_curso">Curso</label>
                        <select name="id_curso" id="n_curso" required>
                            <option value="">Seleccione…</option>
                            <?php foreach ($cursos as $c): ?>
                                <option value="<?= (int)$c["id_curso"] ?>"><?= htmlspecialchars($c["nombre"] . " · " . ucfirst(strtolower($c["nivel"]))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="n_tipo">Tipo de trabajo</label>
                        <select name="id_tipo_trabajo" id="n_tipo" required>
                            <option value="">Seleccione…</option>
                            <?php foreach ($tiposTrabajo as $t): ?>
                                <option value="<?= (int)$t["id_tipo_trabajo"] ?>"><?= htmlspecialchars($t["nombre"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="n_periodo">Periodo</label>
                        <select name="id_periodo" id="n_periodo" required>
                            <option value="">Seleccione…</option>
                            <?php foreach ($periodos as $p): ?>
                                <option value="<?= (int)$p["id_periodo"] ?>"><?= htmlspecialchars($p["nombre"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="n_fecha">Fecha límite</label>
                        <input type="date" name="fecha_limite" id="n_fecha" required>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Crear trabajo</button>
            </form>

        <?php endif; ?>


        <?php if (count($trabajos) === 0): ?>

            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true"><?= icon("clipboard") ?></span>
                <h2>No tienes trabajos registrados</h2>
                <p>Usa el formulario de arriba para crear tu primer trabajo.</p>
            </div>


        <?php else: ?>


            <?php if (count($trabajos) > 6): ?>
                <div class="panel-search">
                    <input type="search" id="filtro-trabajos" placeholder="Buscar por título, curso o tipo…">
                </div>
            <?php endif; ?>

            <div class="trabajos-container">


                <?php foreach ($trabajos as $trabajo): ?>


                    <div class="trabajo-card">


                        <h3>

                            <?= htmlspecialchars(
                                $trabajo["titulo"]
                            ) ?>

                        </h3>


                        <p>

                            <strong>
                                Descripción:
                            </strong>

                            <?= htmlspecialchars(
                                $trabajo["descripcion"]
                            ) ?>

                        </p>


                        <p>

                            <strong>
                                Curso:
                            </strong>

                            <?= htmlspecialchars(
                                $trabajo["curso"]
                            ) ?>

                        </p>


                        <p>

                            <strong>
                                Tipo:
                            </strong>

                            <?= htmlspecialchars(
                                $trabajo["tipo_trabajo"]
                            ) ?>

                        </p>


                        <p>

                            <strong>
                                Fecha límite:
                            </strong>

                            <?= htmlspecialchars(
                                $trabajo["fecha_limite"]
                            ) ?>

                        </p>


                        <p>

                            <strong>
                                Periodo:
                            </strong>

                            <?= htmlspecialchars(
                                $trabajo["periodo"]
                            ) ?>

                        </p>


                        <strong>

                            Estado:

                            <?= htmlspecialchars(
                                $trabajo["estado"]
                            ) ?>

                        </strong>

                        <div class="row-actions">
                            <a href="trabajos.php?editar=<?= (int)$trabajo["id_trabajo"] ?>" class="btn-mini">Editar</a>
                            <form method="POST" onsubmit="return confirm('¿Eliminar este trabajo?');">
                            <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id_trabajo" value="<?= (int)$trabajo["id_trabajo"] ?>">
                                <button type="submit" class="btn-mini btn-mini-reject">Eliminar</button>
                            </form>
                        </div>


                    </div>


                <?php endforeach; ?>


            </div>


        <?php endif; ?>


    </section>


</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
