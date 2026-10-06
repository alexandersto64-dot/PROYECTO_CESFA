<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if ($_SESSION["rol"] !== "ADMIN") {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";
[$mensaje, $mensajeTipo] = flash_get();

// Mapea la entidad que llega del formulario a su tabla/columna real
// — nunca se toma el nombre de tabla directo de $_POST, siempre pasa
// por este mapeo fijo, así que no hay forma de inyectar otra tabla.
const PAPELERA_ENTIDADES = [
    "USUARIO" => ["tabla" => "usuarios", "columna" => "id_usuario"],
    "ALUMNO"  => ["tabla" => "alumnos", "columna" => "id_alumno"],
    "CURSO"   => ["tabla" => "cursos", "columna" => "id_curso"],
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "restaurar") {

    csrf_verificar();

    $entidad = $_POST["entidad"] ?? "";
    $id = (int) ($_POST["id"] ?? 0);

    if (!isset(PAPELERA_ENTIDADES[$entidad]) || $id <= 0) {

        $mensaje = "No se pudo identificar qué restaurar.";
        $mensajeTipo = "error";

    } else {

        $info = PAPELERA_ENTIDADES[$entidad];
        papelera_restaurar($conexion, $info["tabla"], $info["columna"], $id);

        auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "RESTAURAR", $entidad, $id, "Restauró desde la papelera: $entidad #$id");

        $mensaje = "Restaurado correctamente.";
        $mensajeTipo = "success";

    }

    flash_set($mensaje, $mensajeTipo);
    header("Location: papelera.php");
    exit;

}

$usuariosPapelera = papelera_usuarios($conexion);
$alumnosPapelera = papelera_alumnos($conexion);
$cursosPapelera = papelera_cursos($conexion);
$totalPapelera = count($usuariosPapelera) + count($alumnosPapelera) + count($cursosPapelera);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Papelera · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Papelera</h1>
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

    <p class="placeholder-text">Lo que se elimina en Usuarios, Alumnos y Cursos llega acá primero — nada se borra de verdad hasta que decidas quitarlo directamente de la base de datos. Restaura lo que se eliminó por error.</p>

    <?php if ($totalPapelera === 0): ?>
        <p class="placeholder-text">La papelera está vacía.</p>
    <?php else: ?>

        <h2>Usuarios (<?= count($usuariosPapelera) ?>)</h2>
        <?php if (count($usuariosPapelera) === 0): ?>
            <p class="placeholder-text">Nada en la papelera.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Eliminado</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($usuariosPapelera as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u["nombres"] . " " . $u["apellidos"]) ?></td>
                                <td><?= htmlspecialchars($u["correo"]) ?></td>
                                <td><span class="role-badge"><?= htmlspecialchars($u["rol"]) ?></span></td>
                                <td><?= htmlspecialchars($u["eliminado_en"]) ?></td>
                                <td>
                                    <form method="POST">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="restaurar">
                                        <input type="hidden" name="entidad" value="USUARIO">
                                        <input type="hidden" name="id" value="<?= (int) $u["id_usuario"] ?>">
                                        <button type="submit" class="btn-mini btn-mini-approve"><?= icon("rotate-ccw") ?> Restaurar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2>Alumnos (<?= count($alumnosPapelera) ?>)</h2>
        <?php if (count($alumnosPapelera) === 0): ?>
            <p class="placeholder-text">Nada en la papelera.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Nombre</th><th>DNI</th><th>Eliminado</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($alumnosPapelera as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a["nombres"] . " " . $a["apellidos"]) ?></td>
                                <td><?= htmlspecialchars($a["dni"]) ?></td>
                                <td><?= htmlspecialchars($a["eliminado_en"]) ?></td>
                                <td>
                                    <form method="POST">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="restaurar">
                                        <input type="hidden" name="entidad" value="ALUMNO">
                                        <input type="hidden" name="id" value="<?= (int) $a["id_alumno"] ?>">
                                        <button type="submit" class="btn-mini btn-mini-approve"><?= icon("rotate-ccw") ?> Restaurar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2>Cursos (<?= count($cursosPapelera) ?>)</h2>
        <?php if (count($cursosPapelera) === 0): ?>
            <p class="placeholder-text">Nada en la papelera.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Curso</th><th>Nivel</th><th>Eliminado</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($cursosPapelera as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c["nombre"]) ?></td>
                                <td><?= htmlspecialchars(ucfirst(strtolower($c["nivel"]))) ?></td>
                                <td><?= htmlspecialchars($c["eliminado_en"]) ?></td>
                                <td>
                                    <form method="POST">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="restaurar">
                                        <input type="hidden" name="entidad" value="CURSO">
                                        <input type="hidden" name="id" value="<?= (int) $c["id_curso"] ?>">
                                        <button type="submit" class="btn-mini btn-mini-approve"><?= icon("rotate-ccw") ?> Restaurar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
