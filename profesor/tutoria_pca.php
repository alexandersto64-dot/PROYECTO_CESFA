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
// SEGURIDAD: igual que pca.php verifica el id_nivel_grado contra
// las aulas realmente asignadas al profesor, aquí NUNCA se confía
// en ningún id de la URL: la aula y el período salen siempre de
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

// Estructura uniforme: PCA de Tutoría también va de U1 a U10, igual
// que PCA/Unidades/Sesiones de las áreas normales.
$totalUnidadesPca = pca_total_unidades($tutoria["nivel"]);

// ==================================================
// 3. ACCIONES: SUBIR/REEMPLAZAR Y ELIMINAR
// ==================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];
    $unidad = (int) ($_POST["unidad"] ?? 0);

    $redireccion = "tutoria_pca.php";

    if ($unidad < 1 || $unidad > $totalUnidadesPca) {

        flash_set("Unidad inválida.", "error");
        header("Location: " . $redireccion);
        exit;

    }

    if ($accion === "guardar_pca") {

        try {

            $archivoInfo = materiales_guardar_archivo(
                $_FILES["archivo"] ?? [],
                MATERIALES_DIRECTORIO_TUTORIA_PCA,
                "backend/uploads/materiales/tutoria/pca/"
            );

            $existente = $conexion->prepare("
                SELECT id_material, ruta_archivo FROM profesor_tutoria_pca_archivos
                WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ? AND unidad = ?
            ");
            $existente->execute([$idProfesor, $idGradoSeccion, $idPeriodo, $unidad]);
            $existente = $existente->fetch();

            if ($existente) {

                materiales_eliminar_archivo_fisico($existente["ruta_archivo"]);

                $conexion->prepare("
                    UPDATE profesor_tutoria_pca_archivos
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

                flash_set("Archivo de U{$unidad} reemplazado correctamente.", "success");

            } else {

                $conexion->prepare("
                    INSERT INTO profesor_tutoria_pca_archivos
                        (id_profesor, id_grado_seccion, id_periodo, unidad, nombre_archivo, ruta_archivo, extension, tamano_bytes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $idProfesor,
                    $idGradoSeccion,
                    $idPeriodo,
                    $unidad,
                    $archivoInfo["nombre_archivo"],
                    $archivoInfo["ruta_archivo"],
                    $archivoInfo["extension"],
                    $archivoInfo["tamano_bytes"],
                ]);

                flash_set("Archivo de U{$unidad} subido correctamente.", "success");

            }

        } catch (RuntimeException $e) {

            flash_set($e->getMessage(), "error");

        }

    } elseif ($accion === "eliminar_pca") {

        $existente = $conexion->prepare("
            SELECT id_material, ruta_archivo FROM profesor_tutoria_pca_archivos
            WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ? AND unidad = ?
        ");
        $existente->execute([$idProfesor, $idGradoSeccion, $idPeriodo, $unidad]);
        $existente = $existente->fetch();

        if (!$existente) {

            flash_set("No se pudo eliminar: el archivo no existe o no le pertenece.", "error");

        } else {

            $conexion->prepare("
                DELETE FROM profesor_tutoria_pca_archivos
                WHERE id_material = ? AND id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ?
            ")->execute([$existente["id_material"], $idProfesor, $idGradoSeccion, $idPeriodo]);

            materiales_eliminar_archivo_fisico($existente["ruta_archivo"]);

            flash_set("Archivo de U{$unidad} eliminado correctamente.", "success");

        }

    }

    header("Location: " . $redireccion);
    exit;

}

[$mensaje, $mensajeTipo] = flash_get();

// ==================================================
// 4. ARCHIVOS ACTUALES DE PCA DE TUTORÍA
// ==================================================

$stmt = $conexion->prepare("
    SELECT * FROM profesor_tutoria_pca_archivos
    WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ?
");
$stmt->execute([$idProfesor, $idGradoSeccion, $idPeriodo]);

$archivosPorUnidad = [];
foreach ($stmt->fetchAll() as $fila) {
    $archivosPorUnidad[(int) $fila["unidad"]] = $fila;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PCA · Tutoría · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
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
        <h1><?= icon("book") ?> PCA · Tutoría</h1>
    </div>
    <div class="header-actions">
        <a href="tutoria.php" class="btn-secondary">← Tutoría</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <div class="context-bar">
        <nav class="breadcrumbs">
            <a href="dashboard.php">Dashboard</a>
            <span>›</span>
            <a href="tutoria.php">Tutoría</a>
            <span>›</span>
            <span class="crumb-current">PCA</span>
        </nav>
        <div class="context-tags">
            <span class="badge-nivel-grado"><?= icon("graduation") ?> <?= htmlspecialchars(strtoupper($tutoria["nombre"])) ?> · <?= htmlspecialchars($tutoria["nivel"]) ?></span>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <p class="placeholder-text" style="margin-bottom:20px;">
        Sube el archivo del PCA de Tutoría correspondiente a cada unidad. Formatos permitidos:
        PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, ZIP, RAR, JPG, PNG. Tamaño máximo 20&nbsp;MB.
    </p>

    <div class="materiales-grid">

        <?php for ($u = 1; $u <= $totalUnidadesPca; $u++): ?>

            <?php $archivo = $archivosPorUnidad[$u] ?? null; ?>

            <div class="material-card">

                <div class="material-card-head">
                    <h3>U<?= $u ?></h3>
                    <?php if ($archivo): ?>
                        <span class="status-badge status-aprobado">Cargado</span>
                    <?php else: ?>
                        <span class="status-badge status-pendiente">Sin archivo</span>
                    <?php endif; ?>
                </div>

                <?php if ($archivo): ?>

                    <p class="material-filename" title="<?= htmlspecialchars($archivo["nombre_archivo"]) ?>">
                        <?= icon("file-text") ?> <?= htmlspecialchars($archivo["nombre_archivo"]) ?>
                    </p>

                    <div class="row-actions">
                        <a class="btn-mini" target="_blank" rel="noopener"
                           href="../backend/materiales/archivo.php?modulo=tutoria_pca&id=<?= (int) $archivo["id_material"] ?>&accion=ver">
                            Ver
                        </a>
                        <a class="btn-mini" href="../backend/materiales/archivo.php?modulo=tutoria_pca&id=<?= (int) $archivo["id_material"] ?>&accion=descargar">
                            Descargar
                        </a>
                        <form method="POST" onsubmit="return confirm('¿Eliminar el archivo de U<?= $u ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="eliminar_pca">
                            <input type="hidden" name="unidad" value="<?= $u ?>">
                            <button type="submit" class="btn-mini btn-mini-reject">Eliminar</button>
                        </form>
                    </div>

                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="material-upload-form"
                      data-max-mb="20" data-extensiones="<?= implode(',', MATERIALES_EXTENSIONES_PERMITIDAS) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="guardar_pca">
                    <input type="hidden" name="unidad" value="<?= $u ?>">
                    <input type="file" name="archivo" accept="<?= implode(',', array_map(fn($e) => '.' . $e, MATERIALES_EXTENSIONES_PERMITIDAS)) ?>" required>
                    <button type="submit" class="btn-mini">
                        <?= $archivo ? "Reemplazar" : "Subir" ?>
                    </button>
                </form>

            </div>

        <?php endfor; ?>

    </div>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
