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
// 3. ASIGNACIÓN DE TUTORÍA DEL AÑO ACTUAL
//
// Nunca se asume Tutoría por el nivel del profesor: solo existe si
// hay una fila real en profesor_tutoria para el período académico
// actual. Si no la hay (ya no es tutor este año, o nunca lo fue),
// se redirige al Dashboard — Tutoría no es una página a la que se
// pueda "entrar" sin asignación real, aunque se adivine la URL.
// ==================================================

$tutoria = profesor_tutoria_actual($conexion, $idProfesor);

if (!$tutoria) {
    header("Location: dashboard.php");
    exit;
}

$idGradoSeccion = (int) $tutoria["id_grado_seccion"];
$etiquetaTutoria = tutoria_label($tutoria);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutoría · <?= htmlspecialchars($etiquetaTutoria) ?> · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
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
        <h1><?= icon("graduation") ?> Tutoría</h1>
    </div>
    <div class="header-actions">
        <a href="dashboard.php" class="btn-secondary">← Dashboard</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <div class="context-bar">
        <nav class="breadcrumbs">
            <a href="dashboard.php">Dashboard</a>
            <span>›</span>
            <span class="crumb-current">Tutoría</span>
        </nav>
        <div class="context-tags">
            <span class="badge-nivel-grado"><?= icon("graduation") ?> <?= htmlspecialchars(strtoupper($tutoria["nombre"])) ?> · <?= htmlspecialchars($tutoria["nivel"]) ?></span>
            <span class="badge-curso-actual"><?= icon("book") ?> <?= htmlspecialchars($tutoria["nombre_periodo"]) ?></span>
        </div>
    </div>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Tutoría independiente de tus cursos normales</h2>
        </div>
        <p class="placeholder-text">
            Eres tutor(a) de <strong><?= htmlspecialchars(strtoupper($tutoria["nombre"])) ?> Secundaria</strong>
            durante <strong><?= htmlspecialchars($tutoria["nombre_periodo"]) ?></strong>. Esta sección es
            independiente de tus cursos y áreas: el PCA, las Unidades y las Sesiones de Tutoría nunca se
            mezclan con los de tus otras asignaciones. Esta asignación corresponde únicamente al año
            escolar actual: si el próximo año no tienes Tutoría, esta sección dejará de aparecer.
        </p>
    </section>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>¿Qué deseas gestionar en Tutoría?</h2>
            <p class="panel-section-sub">Cada módulo trabaja únicamente sobre esta aula y este año escolar.</p>
        </div>

        <div class="cards">
            <div class="card">
                <h3><?= icon("book") ?> PCA</h3>
                <p>Sube el archivo del PCA de Tutoría de cada unidad (U1–U10) para esta aula.</p>
                <div class="card-foot" style="justify-content:flex-end;">
                    <a href="tutoria_pca.php">Abrir PCA</a>
                </div>
            </div>
            <div class="card">
                <h3><?= icon("layers") ?> Unidades</h3>
                <p>Unidad → Sem-01 a Sem-05, con subida de archivos propios de Tutoría.</p>
                <div class="card-foot" style="justify-content:flex-end;">
                    <a href="tutoria_unidades.php">Abrir Unidades</a>
                </div>
            </div>
            <div class="card">
                <h3><?= icon("file-text") ?> Sesiones</h3>
                <p>Unidad → Sem-01 a Sem-05, con subida de archivos propios de Tutoría (solo PDF).</p>
                <div class="card-foot" style="justify-content:flex-end;">
                    <a href="tutoria_sesiones.php">Abrir Sesiones</a>
                </div>
            </div>
        </div>
    </section>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
