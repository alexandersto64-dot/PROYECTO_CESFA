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
require_once "../backend/config/admin_seguimiento.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/profesor_tutoria.php";
[$mensaje, $mensajeTipo] = flash_get();

// ==========================================
// REGISTRAR ESPECIALIDAD DE UN PROFESOR
// (solo para usuarios que ya tienen rol PROFESOR
// y todavía no tienen perfil en la tabla `profesores`)
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $accion = $_POST["accion"] ?? "crear";

    if ($accion === "guardar_edicion") {

        $id_profesor = (int)($_POST["id_profesor"] ?? 0);
        $especialidad = trim($_POST["especialidad"] ?? "");

        if ($id_profesor === 0 || $especialidad === "") {

            $mensaje = "Debe indicar la especialidad.";
            $mensajeTipo = "error";

        } else {

            $stmt = $conexion->prepare("UPDATE profesores SET especialidad = ? WHERE id_profesor = ?");
            $stmt->execute([$especialidad, $id_profesor]);
            auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "EDITAR", "PROFESOR", $id_profesor, "Cambió la especialidad del profesor #$id_profesor");

            $mensaje = "Profesor actualizado correctamente.";
            $mensajeTipo = "success";

        }

        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: profesores.php");
        exit;

    } elseif ($accion === "eliminar") {

        $id_profesor = (int)($_POST["id_profesor"] ?? 0);

        try {

            $stmt = $conexion->prepare("DELETE FROM profesores WHERE id_profesor = ?");
            $stmt->execute([$id_profesor]);
            auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "ELIMINAR", "PROFESOR", $id_profesor, "Eliminó al profesor #$id_profesor");

            $mensaje = "Profesor eliminado correctamente.";
            $mensajeTipo = "success";

        } catch (PDOException $e) {

            $mensaje = "No se pudo eliminar: el profesor tiene trabajos asociados.";
            $mensajeTipo = "error";

        }

        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: profesores.php");
        exit;

    } elseif ($accion === "asignar_aula") {

        $id_profesor = (int)($_POST["id_profesor"] ?? 0);
        $id_grado_seccion = (int)($_POST["id_grado_seccion"] ?? 0);

        if ($id_profesor === 0 || $id_grado_seccion === 0) {

            $mensaje = "Debe seleccionar un aula.";
            $mensajeTipo = "error";

        } else {

            try {

                $stmt = $conexion->prepare("INSERT INTO asignaciones_docentes (id_profesor, id_grado_seccion) VALUES (?, ?)");
                $stmt->execute([$id_profesor, $id_grado_seccion]);
                auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "ASIGNAR", "PROFESOR_AULA", $id_profesor, "Asignó el aula #$id_grado_seccion al profesor #$id_profesor");

                $mensaje = "Aula asignada correctamente.";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo asignar: el aula ya estaba asignada a este profesor o no corresponde a su nivel.";
                $mensajeTipo = "error";

            }

        }

        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: profesores.php");
        exit;

    } elseif ($accion === "asignar_aula_masivo") {

        // Asignación masiva: mismo INSERT que "asignar_aula", uno
        // por cada aula marcada, en vez de tener que repetir el
        // formulario de a un aula por vez. Si alguna ya estaba
        // asignada (o el trigger de nivel la rechaza), se cuenta
        // aparte y se sigue con las demás en vez de abortar todo.
        $id_profesor = (int)($_POST["id_profesor"] ?? 0);
        $idsGradoSeccion = array_unique(array_filter(array_map("intval", $_POST["id_grado_seccion"] ?? [])));

        if ($id_profesor === 0 || count($idsGradoSeccion) === 0) {

            $mensaje = "Debe seleccionar el profesor y al menos un aula.";
            $mensajeTipo = "error";

        } else {

            $asignadas = 0;
            $rechazadas = 0;
            $stmtAsignar = $conexion->prepare("INSERT INTO asignaciones_docentes (id_profesor, id_grado_seccion) VALUES (?, ?)");

            foreach ($idsGradoSeccion as $idGradoSeccion) {
                try {
                    $stmtAsignar->execute([$id_profesor, $idGradoSeccion]);
                    $asignadas++;
                } catch (PDOException $e) {
                    $rechazadas++;
                }
            }

            if ($asignadas > 0) {
                auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "ASIGNAR", "PROFESOR_AULA", $id_profesor, "Asignó $asignadas aula(s) al profesor #$id_profesor");
            }
            $mensaje = $asignadas . " aula(s) asignada(s) correctamente"
                . ($rechazadas > 0 ? " ({$rechazadas} ya estaban asignadas o no válidas para este profesor)." : ".");
            $mensajeTipo = $asignadas > 0 ? "success" : "error";

        }

        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: profesores.php");
        exit;

    } elseif ($accion === "quitar_aula") {

        $id_asignacion = (int)($_POST["id_asignacion"] ?? 0);

        $stmt = $conexion->prepare("DELETE FROM asignaciones_docentes WHERE id_asignacion = ?");
        $stmt->execute([$id_asignacion]);
        auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "QUITAR", "PROFESOR_AULA", $id_asignacion, "Retiró la asignación de aula #$id_asignacion");

        $mensaje = "Aula retirada del profesor.";
        $mensajeTipo = "success";

        flash_set($mensaje, $mensajeTipo);
        header("Location: profesores.php");
        exit;

    } elseif ($accion === "asignar_tutoria") {

        // Tutoría (SOLO Secundaria, año escolar actual). Nunca se
        // confía en el nivel del profesor enviado por el formulario:
        // la propia BD (trigger trg_tutoria_valida_nivel_bi) rechaza
        // el INSERT si el aula no es de SECUNDARIA o si el profesor
        // tiene nivel_educativo distinto de SECUNDARIA — igual patrón
        // que "asignar_aula" ya usa con trg_asignaciones_valida_nivel_bi.
        $id_profesor = (int)($_POST["id_profesor"] ?? 0);
        $id_grado_seccion = (int)($_POST["id_grado_seccion"] ?? 0);

        $periodoActual = periodo_academico_actual($conexion);

        if ($id_profesor === 0 || $id_grado_seccion === 0) {

            $mensaje = "Debe seleccionar un aula de Secundaria para la Tutoría.";
            $mensajeTipo = "error";

        } elseif (!$periodoActual) {

            $mensaje = "No hay ningún período académico (año escolar) registrado todavía.";
            $mensajeTipo = "error";

        } else {

            try {

                $stmt = $conexion->prepare("
                    INSERT INTO profesor_tutoria (id_profesor, id_grado_seccion, id_periodo)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$id_profesor, $id_grado_seccion, (int) $periodoActual["id_periodo"]]);
                auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "ASIGNAR", "PROFESOR_TUTORIA", $id_profesor, "Asignó tutoría del aula #$id_grado_seccion al profesor #$id_profesor");

                $mensaje = "Tutoría asignada correctamente para " . $periodoActual["nombre"] . ".";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                // Puede ser: el aula ya tiene tutor este año
                // (uq_tutoria_aula_periodo), o el trigger de nivel
                // rechazó un aula/profesor que no es de Secundaria.
                $mensaje = "No se pudo asignar: el aula ya tiene tutor este año, o no es de Secundaria.";
                $mensajeTipo = "error";

            }

        }

        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: profesores.php");
        exit;

    } elseif ($accion === "quitar_tutoria") {

        $id_tutoria = (int)($_POST["id_tutoria"] ?? 0);

        $stmt = $conexion->prepare("DELETE FROM profesor_tutoria WHERE id_tutoria = ?");
        $stmt->execute([$id_tutoria]);
        auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "QUITAR", "PROFESOR_TUTORIA", $id_tutoria, "Retiró la tutoría #$id_tutoria");

        $mensaje = "Tutoría retirada del profesor.";
        $mensajeTipo = "success";

        flash_set($mensaje, $mensajeTipo);
        header("Location: profesores.php");
        exit;

    } else {

        $id_usuario = $_POST["id_usuario"] ?? "";
        $especialidad = trim($_POST["especialidad"] ?? "");

        if ($id_usuario === "" || $especialidad === "") {

            $mensaje = "Debe seleccionar un usuario e indicar la especialidad.";
            $mensajeTipo = "error";

        } else {

            try {
                $stmt = $conexion->prepare("INSERT INTO profesores (id_usuario, especialidad) VALUES (?, ?)");
                $stmt->execute([(int)$id_usuario, $especialidad]);

                $mensaje = "Profesor registrado correctamente.";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo registrar (verifique que el usuario no tenga ya un perfil de profesor).";
                $mensajeTipo = "error";

            }

        }

        // Evita el reenvío del formulario al recargar (PRG)
        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: profesores.php");
        exit;

    }

}

// ==========================================
// LISTA DE PROFESORES YA REGISTRADOS
// ==========================================

$profesores = $conexion->query("
    SELECT
        p.id_profesor,
        u.nombres,
        u.apellidos,
        u.correo,
        p.especialidad,
        p.nivel_educativo

    FROM profesores p

    INNER JOIN usuarios u
        ON p.id_usuario = u.id_usuario

    ORDER BY u.apellidos, u.nombres
")->fetchAll();

// ==========================================
// USUARIOS CON ROL PROFESOR SIN PERFIL AÚN
// ==========================================

$pendientes = $conexion->query("
    SELECT u.id_usuario, u.nombres, u.apellidos
    FROM usuarios u
    INNER JOIN roles r ON u.id_rol = r.id_rol
    WHERE r.nombre = 'PROFESOR'
      AND u.id_usuario NOT IN (SELECT id_usuario FROM profesores)
    ORDER BY u.apellidos, u.nombres
")->fetchAll();

// ==========================================
// AULAS ASIGNADAS POR PROFESOR + CATÁLOGO DE AULAS
// ==========================================

$gradosSecciones = $conexion->query("SELECT id_grado_seccion, nivel, grado, seccion, nombre FROM grados_secciones ORDER BY nivel, grado, seccion")->fetchAll();

$ordinalesGrado = [1 => "1ro", 2 => "2do", 3 => "3ro", 4 => "4to", 5 => "5to", 6 => "6to"];

$asignacionesPorProfesor = [];
$filasAsig = $conexion->query("
    SELECT ad.id_asignacion, ad.id_profesor, ad.id_grado_seccion, gs.nombre AS grado_seccion, gs.nivel, gs.grado
    FROM asignaciones_docentes ad
    INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
    ORDER BY gs.nivel, gs.grado, gs.seccion
")->fetchAll();

foreach ($filasAsig as $fila) {
    $asignacionesPorProfesor[$fila["id_profesor"]][] = $fila;
}

// ==========================================
// DATOS PARA LOS FILTROS (Nivel -> Grado -> Curso)
//
// Fuente de los cursos por profesor: profesor_curso_grado (igual que
// profesor_grados.php), nunca asignaciones_docentes. Esta pantalla no
// asigna cursos (eso se hace directo por BD, ver notas en
// materiales_cursos.php); aquí solo se lee para poder filtrar.
// ==========================================

// Filtros en cascada Nivel -> Grado -> Curso. Los cursos se identifican por
// id_curso (no por nombre) porque el mismo nombre existe en Primaria y en
// Secundaria como cursos distintos (ej. "Matemática").
$gradosPorNivel = [];
foreach ($conexion->query("SELECT nivel, grado, nombre FROM niveles_grados ORDER BY nivel, grado")->fetchAll() as $fila) {
    $gradosPorNivel[$fila["nivel"]][] = ["v" => (int) $fila["grado"], "t" => $fila["nombre"]];
}

$cursosPorNivel = [];
foreach ($conexion->query("SELECT id_curso, nombre, nivel FROM cursos WHERE eliminado_en IS NULL ORDER BY nivel, nombre")->fetchAll() as $fila) {
    $cursosPorNivel[$fila["nivel"]][] = ["v" => (int) $fila["id_curso"], "t" => $fila["nombre"]];
}

// Por profesor: lista de [nivel, grado|null, id_curso|null] contra la que el
// filtro comprueba si el profesor "encaja" (nivel, grado y curso a la vez).
$tuplasPorProfesor = [];
foreach ($conexion->query("
    SELECT pcg.id_profesor, ng.nivel, ng.grado, pcg.id_curso
    FROM profesor_curso_grado pcg
    INNER JOIN niveles_grados ng ON ng.id_nivel_grado = pcg.id_nivel_grado
")->fetchAll() as $fila) {
    $tuplasPorProfesor[$fila["id_profesor"]][] = [$fila["nivel"], (int) $fila["grado"], (int) $fila["id_curso"]];
}
foreach ($filasAsig as $fila) {
    $tuplasPorProfesor[$fila["id_profesor"]][] = [$fila["nivel"], (int) $fila["grado"], null];
}

// ==========================================
// TUTORÍA — SOLO SECUNDARIA, SOLO AÑO ESCOLAR ACTUAL
//
// Se administra siempre sobre el período académico actual (ver
// periodo_academico_actual()); para asignar/retirar Tutoría de un
// año distinto (por ejemplo, para dejar preparado el próximo año
// escolar antes de que empiece), hay que registrar primero ese
// período en Subdirección › Periodos y esperar a que se convierta
// en el actual, o hacerlo directamente por BD — no se agrega aquí
// un selector de año para no complejizar esta pantalla más de lo
// que pide el ticket.
// ==========================================

$periodoActualAdmin = periodo_academico_actual($conexion);

$tutoriaPorProfesor = [];

if ($periodoActualAdmin) {
    $stmt = $conexion->prepare("
        SELECT pt.id_tutoria, pt.id_profesor, gs.nombre AS grado_seccion, gs.nivel
        FROM profesor_tutoria pt
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = pt.id_grado_seccion
        WHERE pt.id_periodo = ?
        ORDER BY gs.grado, gs.seccion
    ");
    $stmt->execute([(int) $periodoActualAdmin["id_periodo"]]);
    foreach ($stmt->fetchAll() as $fila) {
        $tutoriaPorProfesor[$fila["id_profesor"]] = $fila; // un profesor = a lo más 1 tutoría vigente
    }
}

// Catálogo de aulas de Secundaria (Tutoría SOLO Secundaria), en
// principio disponibles para asignar — igual que $gradosSecciones ya
// lista TODAS las aulas para "asignar_aula", esta lista no descarta
// las que ya tienen tutor este año: uq_tutoria_aula_periodo lo
// impide igualmente al guardar, con el mismo mensaje de error que
// ya usa "asignar_aula" cuando el aula ya estaba asignada.
$gradosSeccionesSecundaria = $conexion->query("
    SELECT id_grado_seccion, nivel, grado, seccion, nombre
    FROM grados_secciones
    WHERE nivel = 'SECUNDARIA'
    ORDER BY grado, seccion
")->fetchAll();

$opcionesTutoria = array_map(fn($gs) => [
    "id" => (int) $gs["id_grado_seccion"], "nivel" => $gs["nivel"],
    "grado" => (int) $gs["grado"], "seccion" => $gs["seccion"],
], $gradosSeccionesSecundaria);

// ==========================================
// PROFESOR A EDITAR (si corresponde)
// ==========================================

$profesorEditar = null;

if (isset($_GET["editar"]) && (int)$_GET["editar"] > 0) {

    $stmt = $conexion->prepare("
        SELECT p.id_profesor, p.especialidad, u.nombres, u.apellidos
        FROM profesores p
        INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
        WHERE p.id_profesor = ?
    ");
    $stmt->execute([(int)$_GET["editar"]]);
    $profesorEditar = $stmt->fetch();

}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profesores · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Gestionar profesores</h1>
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

    <?php if ($profesorEditar): ?>
        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Editar especialidad — <?= htmlspecialchars($profesorEditar['nombres'] . ' ' . $profesorEditar['apellidos']) ?></h3>
            <input type="hidden" name="accion" value="guardar_edicion">
            <input type="hidden" name="id_profesor" value="<?= (int)$profesorEditar['id_profesor'] ?>">
            <div class="field">
                <label for="especialidad_edit">Especialidad / área</label>
                <input type="text" name="especialidad" id="especialidad_edit" value="<?= htmlspecialchars($profesorEditar['especialidad']) ?>" required>
            </div>
            <div class="row-actions">
                <button type="submit" class="btn-submit">Guardar cambios</button>
                <a href="profesores.php" class="btn-secondary">Cancelar</a>
            </div>
        </form>
    <?php elseif (count($pendientes) > 0): ?>
        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Registrar especialidad de un profesor</h3>
            <input type="hidden" name="accion" value="crear">
            <div class="form-row">
                <div class="field">
                    <label for="id_usuario">Usuario (rol PROFESOR, sin perfil aún)</label>
                    <select name="id_usuario" id="id_usuario" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($pendientes as $p): ?>
                            <option value="<?= (int)$p['id_usuario'] ?>">
                                <?= htmlspecialchars($p['nombres'] . ' ' . $p['apellidos']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="especialidad">Especialidad / área</label>
                    <input type="text" name="especialidad" id="especialidad" placeholder="Ej. Matemática" required>
                </div>
            </div>
            <button type="submit" class="btn-submit">Registrar</button>
        </form>
    <?php else: ?>
        <p class="placeholder-text" style="margin-bottom:20px">
            No hay usuarios con rol PROFESOR pendientes de completar su perfil.
            Primero crea el usuario en «Gestionar usuarios» con rol PROFESOR.
        </p>
    <?php endif; ?>

    <h2>Profesores registrados (<?= count($profesores) ?>)</h2>

    <?php if (count($profesores) === 0): ?>
        <p class="placeholder-text">Todavía no hay profesores registrados.</p>
    <?php else: ?>

        <div class="panel-form" style="margin-bottom:16px">
            <div class="form-row">
                <div class="field">
                    <label for="buscar-profesor">Buscar por nombre</label>
                    <input type="text" id="buscar-profesor" placeholder="Ej. García">
                </div>
                <div class="field">
                    <label for="filtro-nivel">Nivel</label>
                    <select id="filtro-nivel">
                        <option value="">Todos</option>
                        <option value="PRIMARIA">Primaria</option>
                        <option value="SECUNDARIA">Secundaria</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filtro-grado">Grado</label>
                    <select id="filtro-grado" disabled>
                        <option value="">Primero elige el nivel</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filtro-curso">Curso</label>
                    <select id="filtro-curso" disabled>
                        <option value="">Primero elige el nivel</option>
                    </select>
                </div>
            </div>
        </div>

        <p id="sin-resultados-profesores" class="placeholder-text" style="display:none">
            Ningún profesor coincide con ese filtro.
        </p>

        <div class="profesor-list" id="tabla-profesores">
            <?php foreach ($profesores as $p): ?>
                <?php
                    $nivelProf = $p["nivel_educativo"] ?? "";
                    $tuplasProf = $tuplasPorProfesor[$p["id_profesor"]] ?? [];
                    if ($nivelProf !== "") {
                        $tuplasProf[] = [$nivelProf, null, null]; // su nivel cuenta aunque aún no tenga aulas/cursos
                    }
                    $tuplasProf = array_values(array_unique($tuplasProf, SORT_REGULAR));
                ?>
                <div class="profesor-card"
                    data-nombre="<?= htmlspecialchars(strtolower($p['nombres'] . ' ' . $p['apellidos'])) ?>"
                    data-tuplas="<?= htmlspecialchars(json_encode($tuplasProf), ENT_QUOTES) ?>"
                >
                    <div class="profesor-card-head">
                        <div class="profesor-card-id">
                            <span class="profesor-card-nombre"><?= htmlspecialchars($p['nombres'] . ' ' . $p['apellidos']) ?></span>
                            <span class="profesor-card-correo placeholder-text"><?= htmlspecialchars($p['correo']) ?></span>
                        </div>
                        <span class="role-badge"><?= htmlspecialchars($p['especialidad']) ?></span>
                        <div class="row-actions">
                            <a href="profesores.php?editar=<?= (int)$p['id_profesor'] ?>" class="btn-mini">Editar</a>
                            <form method="POST" onsubmit="return confirm('¿Eliminar el perfil de este profesor?');">
                            <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id_profesor" value="<?= (int)$p['id_profesor'] ?>">
                                <button type="submit" class="btn-mini btn-mini-reject">Eliminar</button>
                            </form>
                        </div>
                    </div>
                    <div class="profesor-card-body">
                        <div>
                            <span class="profesor-card-section-label">Aulas asignadas</span>
                            <?php foreach (($asignacionesPorProfesor[$p['id_profesor']] ?? []) as $asig): ?>
                                <form method="POST" style="display:inline-block;margin:0 4px 4px 0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="quitar_aula">
                                    <input type="hidden" name="id_asignacion" value="<?= (int)$asig['id_asignacion'] ?>">
                                    <button type="submit" class="role-badge" style="border:0;cursor:pointer" title="Quitar aula">
                                        <?= htmlspecialchars(ucfirst(strtolower($asig['nivel']))) ?> · <?= htmlspecialchars($asig['grado_seccion']) ?> ✕
                                    </button>
                                </form>
                            <?php endforeach; ?>
                            <?php
                                // Solo se ofrecen las aulas del NIVEL del profesor (la BD rechaza las de otro
                                // nivel con trg_asignaciones_valida_nivel_bi) y que aún no tiene asignadas.
                                $idsAsignados = array_map(fn($a) => (int) $a["id_grado_seccion"], $asignacionesPorProfesor[$p["id_profesor"]] ?? []);
                                $aulasDisponibles = array_values(array_filter($gradosSecciones, function ($gs) use ($idsAsignados, $nivelProf) {
                                    return !in_array((int) $gs["id_grado_seccion"], $idsAsignados, true)
                                        && ($nivelProf === "" || $gs["nivel"] === $nivelProf);
                                }));
                                $opcionesAulas = array_map(fn($gs) => [
                                    "id" => (int) $gs["id_grado_seccion"], "nivel" => $gs["nivel"],
                                    "grado" => (int) $gs["grado"], "seccion" => $gs["seccion"],
                                ], $aulasDisponibles);
                            ?>
                            <?php if (count($aulasDisponibles) === 0): ?>
                                <p class="placeholder-text" style="margin-top:6px">Ya tiene todas las aulas de su nivel asignadas.</p>
                            <?php else: ?>
                                <form method="POST" style="margin-top:6px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="asignar_aula">
                                    <input type="hidden" name="id_profesor" value="<?= (int)$p['id_profesor'] ?>">
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                                        <div data-cascada-grados data-compacto
                                             data-name="id_grado_seccion"
                                             <?= $nivelProf !== "" ? 'data-nivel-fijo="' . htmlspecialchars($nivelProf) . '"' : "" ?>
                                             data-opciones="<?= htmlspecialchars(json_encode($opcionesAulas), ENT_QUOTES) ?>"></div>
                                        <button type="submit" class="btn-mini">+ Asignar aula</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                            <?php if (count($aulasDisponibles) > 1): ?>
                                <?php
                                    $aulasPorGrado = [];
                                    foreach ($aulasDisponibles as $gs) {
                                        $etiquetaGrupo = ucfirst(strtolower($gs["nivel"])) . " · " . ($ordinalesGrado[(int) $gs["grado"]] ?? ($gs["grado"] . "°"));
                                        $aulasPorGrado[$etiquetaGrupo][] = $gs;
                                    }
                                ?>
                                <details style="margin-top:6px">
                                    <summary class="btn-mini" style="display:inline-block;cursor:pointer">+ Asignar varias aulas</summary>
                                    <form method="POST" style="margin-top:8px;max-width:300px">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="asignar_aula_masivo">
                                        <input type="hidden" name="id_profesor" value="<?= (int)$p['id_profesor'] ?>">
                                        <?php foreach ($aulasPorGrado as $etiquetaGrupo => $aulasGrupo): ?>
                                            <div class="grupo-aulas" style="margin-bottom:8px">
                                                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:2px">
                                                    <input type="checkbox" data-marcar-grupo>
                                                    <?= htmlspecialchars($etiquetaGrupo) ?> (todas)
                                                </label>
                                                <div style="display:flex;flex-wrap:wrap;gap:4px 12px;padding-left:20px">
                                                    <?php foreach ($aulasGrupo as $gs): ?>
                                                        <label style="font-size:.85rem;font-weight:normal">
                                                            <input type="checkbox" name="id_grado_seccion[]" value="<?= (int)$gs['id_grado_seccion'] ?>">
                                                            Sección <?= htmlspecialchars($gs['seccion']) ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <button type="submit" class="btn-mini" style="margin-top:4px">Asignar seleccionadas</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="profesor-card-section-label">Tutoría<?= $periodoActualAdmin ? " (" . htmlspecialchars($periodoActualAdmin["nombre"]) . ")" : "" ?></span>
                            <?php $tutoriaFila = $tutoriaPorProfesor[$p['id_profesor']] ?? null; ?>

                            <?php if ($tutoriaFila): ?>
                                <form method="POST" style="display:inline-block;margin:0 0 4px 0"
                                      onsubmit="return confirm('¿Retirar la Tutoría de este profesor?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="quitar_tutoria">
                                    <input type="hidden" name="id_tutoria" value="<?= (int)$tutoriaFila['id_tutoria'] ?>">
                                    <button type="submit" class="role-badge" style="border:0;cursor:pointer" title="Quitar Tutoría">
                                        <?= icon("graduation") ?> <?= htmlspecialchars($tutoriaFila['grado_seccion']) ?> Secundaria ✕
                                    </button>
                                </form>
                            <?php elseif ($nivelProf === "PRIMARIA"): ?>
                                <span class="placeholder-text">La Tutoría es solo para profesores de Secundaria.</span>
                            <?php elseif ($periodoActualAdmin): ?>
                                <form method="POST" style="margin-top:2px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="asignar_tutoria">
                                    <input type="hidden" name="id_profesor" value="<?= (int)$p['id_profesor'] ?>">
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                                        <div data-cascada-grados data-compacto data-nivel-fijo="SECUNDARIA"
                                             data-name="id_grado_seccion"
                                             data-opciones="<?= htmlspecialchars(json_encode($opcionesTutoria), ENT_QUOTES) ?>"></div>
                                        <button type="submit" class="btn-mini">+ Asignar Tutoría</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <span class="placeholder-text">Sin período académico registrado</span>
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
<script src="../js/cascada-grados.js?v=2"></script>
<script>
// "Marcar todo el grado" en la asignación masiva de aulas.
document.addEventListener("change", function (ev) {
    var maestro = ev.target;
    if (!maestro.hasAttribute || !maestro.hasAttribute("data-marcar-grupo")) return;
    var grupo = maestro.closest(".grupo-aulas");
    if (!grupo) return;
    Array.prototype.forEach.call(grupo.querySelectorAll('input[name="id_grado_seccion[]"]'), function (c) {
        c.checked = maestro.checked;
    });
});
</script>
<script>
// Filtro en cascada Nivel -> Grado -> Curso + búsqueda por nombre.
// Solo esconde tarjetas ya renderizadas — no toca el servidor ni la BD.
// El profesor "encaja" si tiene al menos UNA combinación (nivel, grado, curso)
// que cumpla a la vez todo lo elegido (así, Matemática de Primaria no aparece
// al filtrar Matemática de Secundaria).
(function () {
    var GRADOS = <?= json_encode($gradosPorNivel, JSON_UNESCAPED_UNICODE) ?>;
    var CURSOS = <?= json_encode($cursosPorNivel, JSON_UNESCAPED_UNICODE) ?>;

    var input = document.getElementById("buscar-profesor");
    var fNivel = document.getElementById("filtro-nivel");
    var fGrado = document.getElementById("filtro-grado");
    var fCurso = document.getElementById("filtro-curso");
    var tabla = document.getElementById("tabla-profesores");
    var sinResultados = document.getElementById("sin-resultados-profesores");
    if (!tabla || !fNivel) return;

    var tarjetas = Array.prototype.slice.call(tabla.querySelectorAll(".profesor-card"));
    tarjetas.forEach(function (t) {
        try { t.tuplas = JSON.parse(t.getAttribute("data-tuplas") || "[]"); } catch (e) { t.tuplas = []; }
    });

    function llenar(select, lista, textoTodos, textoVacio) {
        select.innerHTML = "";
        var o = document.createElement("option");
        o.value = "";
        if (!lista) { o.textContent = textoVacio; select.appendChild(o); select.disabled = true; return; }
        o.textContent = textoTodos; select.appendChild(o);
        lista.forEach(function (x) {
            var op = document.createElement("option");
            op.value = String(x.v); op.textContent = x.t; select.appendChild(op);
        });
        select.disabled = false;
    }

    function aplicar() {
        var texto = (input.value || "").trim().toLowerCase();
        var nivel = fNivel.value;
        var grado = fGrado.value === "" ? null : parseInt(fGrado.value, 10);
        var curso = fCurso.value === "" ? null : parseInt(fCurso.value, 10);
        var visibles = 0;

        tarjetas.forEach(function (t) {
            var okNombre = !texto || (t.getAttribute("data-nombre") || "").indexOf(texto) !== -1;
            var okTupla = t.tuplas.some(function (x) {
                return (!nivel || x[0] === nivel)
                    && (grado === null || x[1] === grado)
                    && (curso === null || x[2] === curso);
            });
            var ok = okNombre && okTupla;
            t.style.display = ok ? "" : "none";
            if (ok) visibles++;
        });

        sinResultados.style.display = visibles === 0 ? "" : "none";
        tabla.style.display = visibles === 0 ? "none" : "";
    }

    fNivel.addEventListener("change", function () {
        var n = fNivel.value;
        llenar(fGrado, n ? (GRADOS[n] || []) : null, "Todos los grados", "Primero elige el nivel");
        llenar(fCurso, n ? (CURSOS[n] || []) : null, "Todos los cursos", "Primero elige el nivel");
        aplicar();
    });
    fGrado.addEventListener("change", aplicar);
    fCurso.addEventListener("change", aplicar);
    input.addEventListener("input", aplicar);
})();
</script>

</body>
</html>
