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
// 2.1 GRADO (NIVEL+GRADO) ACTUAL
//
// SEGURIDAD: nunca se confía en el id_nivel_grado que llega por
// GET; siempre se verifica contra las aulas realmente asignadas
// al profesor antes de consultar nada.
// ==================================================

$idNivelGrado = (int) ($_GET["id_nivel_grado"] ?? 0);
$nivelGrado = profesor_verificar_grado($conexion, $idProfesor, $idNivelGrado);

if (!$nivelGrado) {
    http_response_code(403);
    die("No tiene un aula asignada de ese nivel y grado.");
}

$etiqueta = nivel_grado_label($nivelGrado);
$idNivelGradoSidebar = $idNivelGrado; // usado por sidebar.php

// ==================================================
// 3. DOCUMENTOS: institucionales generales (id_nivel_grado IS
//    NULL) + los específicos de este nivel+grado.
//
//    Es de solo lectura para el Profesor: el registro/edición de
//    documentos institucionales es una función del módulo Admin
//    (admin/documentos.php), que no forma parte de este módulo y
//    no se modifica aquí.
// ==================================================

$stmt = $conexion->prepare("
    SELECT d.id_documento, d.titulo, d.categoria, d.url, d.id_nivel_grado, d.creado_en
    FROM documentos d
    WHERE d.id_nivel_grado IS NULL OR d.id_nivel_grado = ?
    ORDER BY (d.id_nivel_grado IS NULL) DESC, d.categoria, d.creado_en DESC
");
$stmt->execute([$idNivelGrado]);
$documentos = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos Institucionales · <?= htmlspecialchars($etiqueta) ?> · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
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
        <h1><?= icon("folder") ?> Documentos Institucionales</h1>
    </div>
    <div class="header-actions">
        <a href="grado.php?id_nivel_grado=<?= $idNivelGrado ?>" class="btn-secondary">← <?= htmlspecialchars($etiqueta) ?></a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <div class="context-bar">
        <nav class="breadcrumbs">
            <a href="dashboard.php">Dashboard</a>
            <span>›</span>
            <a href="grado.php?id_nivel_grado=<?= $idNivelGrado ?>"><?= htmlspecialchars($etiqueta) ?></a>
            <span>›</span>
            <span class="crumb-current">Documentos Institucionales</span>
        </nav>
        <div class="context-tags">
            <span class="badge-nivel-grado"><?= icon("backpack") ?> <?= htmlspecialchars($etiqueta) ?></span>
        </div>
    </div>

    <p class="placeholder-text" style="margin-bottom:20px;">
        Documentos generales del colegio y documentos específicos de <?= htmlspecialchars($etiqueta) ?>.
        Esta vista es de solo lectura; el registro de documentos lo hace Administración.
    </p>

    <?php if (count($documentos) === 0): ?>

        <div class="empty-state">
            <span class="empty-state-icon" aria-hidden="true"><?= icon("folder") ?></span>
            <h2>Todavía no hay documentos registrados</h2>
            <p>
                Cuando Administración registre documentos institucionales (generales o
                específicos de <?= htmlspecialchars($etiqueta) ?>), aparecerán aquí.
            </p>
        </div>

    <?php else: ?>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Título</th><th>Categoría</th><th>Alcance</th><th>Enlace</th><th>Fecha</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($documentos as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d["titulo"]) ?></td>
                            <td><span class="role-badge"><?= htmlspecialchars($d["categoria"]) ?></span></td>
                            <td>
                                <?php if ($d["id_nivel_grado"] === null): ?>
                                    <span class="status-badge status-aprobado">General</span>
                                <?php else: ?>
                                    <span class="status-badge status-pendiente"><?= htmlspecialchars($etiqueta) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($d["url"]): ?>
                                    <a href="<?= htmlspecialchars($d["url"]) ?>" target="_blank" rel="noopener">Ver archivo</a>
                                <?php else: ?>
                                    <span class="placeholder-text">Sin enlace</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($d["creado_en"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
