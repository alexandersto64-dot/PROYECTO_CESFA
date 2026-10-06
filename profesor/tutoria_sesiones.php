<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

// ==================================================
// 1. SESIÓN Y ROL
// ==================================================

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if (!in_array($_SESSION["rol"], ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true)) {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/materiales_archivos.php";
require_once "../backend/config/materiales_cursos.php";
require_once "../backend/config/profesor_tutoria.php";

// ==================================================
// 2. DATOS DEL PROFESOR
// ==================================================

$stmt = $conexion->prepare("
    SELECT p.id_profesor, u.nombres, u.apellidos
    FROM profesores p
    INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
    WHERE p.id_usuario = ?
");
$stmt->execute([$_SESSION["id_usuario"]]);
$profesor = $stmt->fetch();

if (!$profesor) {
    die("No se encontró el perfil del profesor.");
}

$idProfesor = (int) $profesor["id_profesor"];

// ==================================================
// 2.1 ASIGNACIÓN DE TUTORÍA DEL AÑO ACTUAL
//
// SEGURIDAD: igual que tutoria_pca.php, nunca se confía en ningún id
// de la URL: la aula y el período salen siempre de
// profesor_tutoria_actual(), nunca de $_GET/$_POST.
// ==================================================

$tutoria = profesor_tutoria_actual($conexion, $idProfesor);

if (!$tutoria) {
    http_response_code(403);
    die("No tiene una Tutoría asignada para el año escolar actual.");
}

$idGradoSeccion = (int) $tutoria["id_grado_seccion"];
$idPeriodo = (int) $tutoria["id_periodo"];
$etiquetaTutoria = tutoria_label($tutoria);

// Estructura uniforme: 10 unidades (U1..U10), igual que el resto
// del Dashboard — ver materiales_total_unidades().
$totalUnidades = materiales_total_unidades($tutoria["nivel"]);

// ==================================================
// 3. ACCIONES: SUBIR/REEMPLAZAR Y ELIMINAR
//
// A diferencia de unidades.php (que tiene un archivo por CURSO en
// cada Unidad+Semana), Tutoría no tiene "cursos": hay un único
// archivo por Unidad+Semana.
// ==================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];
    $unidad = (int) ($_POST["unidad"] ?? 0);
    $semana = (int) ($_POST["semana"] ?? 0);

    $redireccion = "tutoria_sesiones.php?unidad=" . $unidad . "&semana=" . $semana;

    $unidadValida = $unidad >= 1 && $unidad <= $totalUnidades;
    $semanaValida = $semana >= 1 && $semana <= materiales_total_semanas($tutoria["nivel"]);

    if (!$unidadValida || !$semanaValida) {

        flash_set("Datos de unidad/semana inválidos.", "error");
        header("Location: tutoria_sesiones.php");
        exit;

    }

    if ($accion === "guardar_semana") {

        try {

            $archivoInfo = materiales_guardar_archivo(
                $_FILES["archivo"] ?? [],
                MATERIALES_DIRECTORIO_TUTORIA_SESIONES,
                "backend/uploads/materiales/tutoria/sesiones/",
                SESIONES_EXTENSIONES_PERMITIDAS
            );

            $existente = $conexion->prepare("
                SELECT id_material, ruta_archivo FROM profesor_tutoria_sesion_archivos
                WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ? AND unidad = ? AND semana = ?
            ");
            $existente->execute([$idProfesor, $idGradoSeccion, $idPeriodo, $unidad, $semana]);
            $existente = $existente->fetch();

            if ($existente) {

                materiales_eliminar_archivo_fisico($existente["ruta_archivo"]);

                $conexion->prepare("
                    UPDATE profesor_tutoria_sesion_archivos
                    SET nombre_archivo = ?, ruta_archivo = ?, extension = ?, tamano_bytes = ?
                    WHERE id_material = ? AND id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ?
                ")->execute([
                    $archivoInfo["nombre_archivo"],
                    $archivoInfo["ruta_archivo"],
                    $archivoInfo["extension"],
                    $archivoInfo["tamano_bytes"],
                    $existente["id_material"],
                    $idProfesor,
                    $idGradoSeccion,
                    $idPeriodo,
                ]);

                flash_set("Archivo de U{$unidad} · " . materiales_semana_label($semana) . " reemplazado correctamente.", "success");

            } else {

                $conexion->prepare("
                    INSERT INTO profesor_tutoria_sesion_archivos
                        (id_profesor, id_grado_seccion, id_periodo, unidad, semana, nombre_archivo, ruta_archivo, extension, tamano_bytes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $idProfesor,
                    $idGradoSeccion,
                    $idPeriodo,
                    $unidad,
                    $semana,
                    $archivoInfo["nombre_archivo"],
                    $archivoInfo["ruta_archivo"],
                    $archivoInfo["extension"],
                    $archivoInfo["tamano_bytes"],
                ]);

                flash_set("Archivo de U{$unidad} · " . materiales_semana_label($semana) . " subido correctamente.", "success");

            }

        } catch (RuntimeException $e) {

            flash_set($e->getMessage(), "error");

        }

    } elseif ($accion === "eliminar_semana") {

        $existente = $conexion->prepare("
            SELECT id_material, ruta_archivo FROM profesor_tutoria_sesion_archivos
            WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ? AND unidad = ? AND semana = ?
        ");
        $existente->execute([$idProfesor, $idGradoSeccion, $idPeriodo, $unidad, $semana]);
        $existente = $existente->fetch();

        if (!$existente) {

            flash_set("No se pudo eliminar: el archivo no existe o no le pertenece.", "error");

        } else {

            $conexion->prepare("
                DELETE FROM profesor_tutoria_sesion_archivos
                WHERE id_material = ? AND id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ?
            ")->execute([$existente["id_material"], $idProfesor, $idGradoSeccion, $idPeriodo]);

            materiales_eliminar_archivo_fisico($existente["ruta_archivo"]);

            flash_set("Archivo de U{$unidad} · " . materiales_semana_label($semana) . " eliminado correctamente.", "success");

        }

    }

    header("Location: " . $redireccion);
    exit;

}

[$mensaje, $mensajeTipo] = flash_get();

// ==================================================
// 4. NAVEGACIÓN: ¿en qué nivel estamos?
// ==================================================

$unidadSel = isset($_GET["unidad"]) ? (int) $_GET["unidad"] : 0;
$semanaSel = isset($_GET["semana"]) ? (int) $_GET["semana"] : 0;

if ($unidadSel < 1 || $unidadSel > $totalUnidades) {
    $unidadSel = 0;
}

if ($semanaSel < 1 || $semanaSel > materiales_total_semanas($tutoria["nivel"])) {
    $semanaSel = 0;
}

if ($unidadSel === 0) {
    $semanaSel = 0;
}

$archivoSemana = null;

if ($unidadSel && $semanaSel) {

    $stmt = $conexion->prepare("
        SELECT * FROM profesor_tutoria_sesion_archivos
        WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ? AND unidad = ? AND semana = ?
    ");
    $stmt->execute([$idProfesor, $idGradoSeccion, $idPeriodo, $unidadSel, $semanaSel]);
    $archivoSemana = $stmt->fetch() ?: null;

}

// Archivos existentes por unidad+semana, para mostrar "Cargado" en
// el listado de semanas sin necesitar abrir cada una.
$archivosPorUnidadSemana = [];

if ($unidadSel) {
    $stmt = $conexion->prepare("
        SELECT unidad, semana FROM profesor_tutoria_sesion_archivos
        WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ? AND unidad = ?
    ");
    $stmt->execute([$idProfesor, $idGradoSeccion, $idPeriodo, $unidadSel]);
    foreach ($stmt->fetchAll() as $fila) {
        $archivosPorUnidadSemana[(int) $fila["semana"]] = true;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesiones · Tutoría · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <span class="header-eyebrow">Panel del Profesor</span>
        <h1><?= icon("file-text") ?> Sesiones · Tutoría</h1>
    </div>
    <div class="header-actions">
        <a href="tutoria.php" class="btn-secondary">← Tutoría</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <p class="placeholder-text" style="margin-bottom:20px;">
        Esta sección solo acepta archivos en formato PDF.
    </p>

    <div class="context-bar">
        <nav class="breadcrumbs">
            <a href="dashboard.php">Dashboard</a>
            <span>›</span>
            <a href="tutoria.php">Tutoría</a>
            <span>›</span>
            <a href="tutoria_sesiones.php">Sesiones</a>
            <?php if ($unidadSel): ?>
                <span>›</span>
                <?php if ($semanaSel): ?>
                    <a href="tutoria_sesiones.php?unidad=<?= $unidadSel ?>">Unidad <?= $unidadSel ?></a>
                    <span>›</span>
                    <span class="crumb-current"><?= htmlspecialchars(materiales_semana_label($semanaSel)) ?></span>
                <?php else: ?>
                    <span class="crumb-current">Unidad <?= $unidadSel ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="context-tags">
            <span class="badge-nivel-grado"><?= icon("graduation") ?> <?= htmlspecialchars(strtoupper($tutoria["nombre"])) ?> · <?= htmlspecialchars($tutoria["nivel"]) ?></span>
        </div>
    </div>

    <?php if (!$unidadSel): ?>

        <!-- ========== NIVEL 1: LISTA DE UNIDADES ========== -->

        <div class="cards">
            <?php for ($u = 1; $u <= $totalUnidades; $u++): ?>
                <div class="card">
                    <h3>Unidad <?= $u ?></h3>
                    <p><?= materiales_total_semanas($tutoria["nivel"]) ?> semanas</p>
                    <a href="tutoria_sesiones.php?unidad=<?= $u ?>">Abrir unidad</a>
                </div>
            <?php endfor; ?>
        </div>

    <?php elseif (!$semanaSel): ?>

        <!-- ========== NIVEL 2: LISTA DE SEMANAS DE LA UNIDAD ========== -->

        <div class="cards">
            <?php for ($s = 1; $s <= materiales_total_semanas($tutoria["nivel"]); $s++): ?>
                <div class="card">
                    <h3><?= htmlspecialchars(materiales_semana_label($s)) ?></h3>
                    <p><?= isset($archivosPorUnidadSemana[$s]) ? "Cargado" : "Sin archivo" ?></p>
                    <a href="tutoria_sesiones.php?unidad=<?= $unidadSel ?>&semana=<?= $s ?>">Abrir semana</a>
                </div>
            <?php endfor; ?>
        </div>

    <?php else: ?>

        <!-- ========== NIVEL 3: SEM-0X — ARCHIVO DE TUTORÍA (sin curso) ========== -->

        <h2><?= htmlspecialchars(materiales_sem_label($semanaSel)) ?></h2>

        <div class="materiales-grid">

            <div class="material-card">

                <div class="material-card-head">
                    <h3>Unidad <?= $unidadSel ?> · <?= htmlspecialchars(materiales_semana_label($semanaSel)) ?></h3>
                    <?php if ($archivoSemana): ?>
                        <span class="status-badge status-aprobado">Cargado</span>
                    <?php else: ?>
                        <span class="status-badge status-pendiente">Sin archivo</span>
                    <?php endif; ?>
                </div>

                <?php if ($archivoSemana): ?>

                    <p class="material-filename" title="<?= htmlspecialchars($archivoSemana["nombre_archivo"]) ?>">
                        <?= icon("file-text") ?> <?= htmlspecialchars($archivoSemana["nombre_archivo"]) ?>
                    </p>

                    <div class="row-actions">
                        <a class="btn-mini" target="_blank" rel="noopener"
                           href="../backend/materiales/archivo.php?modulo=tutoria_sesion&id=<?= (int) $archivoSemana["id_material"] ?>&accion=ver">
                            Ver
                        </a>
                        <a class="btn-mini" href="../backend/materiales/archivo.php?modulo=tutoria_sesion&id=<?= (int) $archivoSemana["id_material"] ?>&accion=descargar">
                            Descargar
                        </a>
                        <form method="POST" onsubmit="return confirm('¿Eliminar este archivo?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="eliminar_semana">
                            <input type="hidden" name="unidad" value="<?= $unidadSel ?>">
                            <input type="hidden" name="semana" value="<?= $semanaSel ?>">
                            <button type="submit" class="btn-mini btn-mini-reject">Eliminar</button>
                        </form>
                    </div>

                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="material-upload-form"
                      data-max-mb="20" data-extensiones="pdf">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="guardar_semana">
                    <input type="hidden" name="unidad" value="<?= $unidadSel ?>">
                    <input type="hidden" name="semana" value="<?= $semanaSel ?>">
                    <input type="file" name="archivo" accept="application/pdf,.pdf" required>
                    <button type="submit" class="btn-mini">
                        <?= $archivoSemana ? "Reemplazar" : "Subir" ?>
                    </button>
                </form>

            </div>

        </div>

    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
