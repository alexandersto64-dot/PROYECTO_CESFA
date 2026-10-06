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

require_once "../backend/config/database.php";
require_once "../backend/config/profesor_grados.php";

if (!in_array($_SESSION["rol"], ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true)) {
    die("Acceso no autorizado.");
}

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
// 3. VERIFICAR QUE EL GRADO PEDIDO REALMENTE LE PERTENECE
//    (nunca se confía en el id_nivel_grado de la URL)
// ==================================================

$idNivelGrado = (int) ($_GET["id_nivel_grado"] ?? 0);
$nivelGrado = profesor_verificar_grado($conexion, $idProfesor, $idNivelGrado);

if (!$nivelGrado) {
    http_response_code(403);
    die("No tiene un aula asignada de ese nivel y grado.");
}

$etiqueta = nivel_grado_label($nivelGrado);

// ==================================================
// 4. CURSO QUE DICTA (profesor_curso_grado) Y SECCIONES
//    ASIGNADAS (asignaciones_docentes) PARA ESTE GRADO
//
// Ambas relaciones se combinan aquí, pero cada una viene de su
// propia tabla: el curso NUNCA sale de asignaciones_docentes, y
// las secciones NUNCA salen de profesor_curso_grado.
// ==================================================

$cursos = profesor_cursos_por_grado($conexion, $idProfesor, $idNivelGrado);
$secciones = profesor_secciones_por_grado($conexion, $idProfesor, $nivelGrado);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($etiqueta) ?> · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php
$currentFile = basename(__FILE__);
$idNivelGradoSidebar = $idNivelGrado; // usado por sidebar.php para armar los enlaces de módulos
include __DIR__ . "/../backend/partials/sidebar.php";
?>
<div class="app-content">

<header>
    <div>
        <span class="header-eyebrow">Panel del Profesor</span>
        <h1><?= icon("backpack") ?> <?= htmlspecialchars($etiqueta) ?></h1>
    </div>
    <div class="header-actions">
        <a href="dashboard.php" class="btn-secondary">← Mis grados</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <div class="context-bar">
        <nav class="breadcrumbs">
            <a href="dashboard.php">Dashboard</a>
            <span>›</span>
            <span class="crumb-current"><?= htmlspecialchars($etiqueta) ?></span>
        </nav>
        <div class="context-tags">
            <span class="badge-nivel-grado"><?= icon("backpack") ?> <?= htmlspecialchars($etiqueta) ?></span>
            <?php foreach ($cursos as $c): ?>
                <span class="badge-curso-actual"><?= icon("book") ?> <?= htmlspecialchars($c["nombre"]) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Secciones asignadas</h2>
        </div>

        <?php if (count($secciones) === 0): ?>
            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true"><?= icon("backpack") ?></span>
                <h2>Sin secciones asignadas</h2>
                <p>
                    No tienes ninguna sección de <?= htmlspecialchars($etiqueta) ?> asignada todavía.
                </p>
            </div>
        <?php else: ?>
            <p>
                <?php foreach ($secciones as $s): ?>
                    <span class="role-badge" style="margin-right:8px;"><?= htmlspecialchars(strtoupper($s["nombre"])) ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <?php if (count($cursos) === 0): ?>
            <p class="placeholder-text">
                Todavía no tienes un curso asignado en <?= htmlspecialchars($etiqueta) ?>. Pide al
                administrador que te asigne el curso desde «Gestionar profesores».
            </p>
        <?php endif; ?>
    </section>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>¿Qué deseas gestionar en <?= htmlspecialchars($etiqueta) ?>?</h2>
            <p class="panel-section-sub">Cada módulo trabaja únicamente sobre este grado.</p>
        </div>

        <div class="cards">
            <div class="card">
                <h3><?= icon("book") ?> PCA</h3>
                <p>Sube el archivo del PCA (único, U1) para este grado.</p>
                <div class="card-foot" style="justify-content:flex-end;">
                    <a href="pca.php?id_nivel_grado=<?= $idNivelGrado ?>">Abrir PCA</a>
                </div>
            </div>
            <div class="card">
                <h3><?= icon("layers") ?> Unidades</h3>
                <p>Sube el archivo de Unidades por cada curso que dictas en este grado (sin unidades ni semanas).</p>
                <div class="card-foot" style="justify-content:flex-end;">
                    <a href="unidades.php?id_nivel_grado=<?= $idNivelGrado ?>">Abrir Unidades</a>
                </div>
            </div>
            <div class="card">
                <h3><?= icon("file-text") ?> Sesiones</h3>
                <p>Unidad → Semana → Sem-01 → cursos, con subida de archivos para este grado.</p>
                <div class="card-foot" style="justify-content:flex-end;">
                    <a href="sesiones.php?id_nivel_grado=<?= $idNivelGrado ?>">Abrir Sesiones</a>
                </div>
            </div>
            <div class="card">
                <h3><?= icon("folder") ?> Documentos Institucionales</h3>
                <p>Documentos generales del colegio y específicos de este grado.</p>
                <div class="card-foot" style="justify-content:flex-end;">
                    <a href="documentos.php?id_nivel_grado=<?= $idNivelGrado ?>">Abrir Documentos</a>
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
