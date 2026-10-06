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
require_once "../backend/config/eventos_institucionales.php";
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $accion = $_POST["accion"] ?? "crear";

    if ($accion === "crear") {

        $titulo = trim($_POST["titulo"] ?? "");
        $descripcion = trim($_POST["descripcion"] ?? "");
        $tipo = $_POST["tipo"] ?? "";
        $fechaInicio = trim($_POST["fecha_inicio"] ?? "");
        $fechaFin = trim($_POST["fecha_fin"] ?? "");
        $idNivelGrado = (int) ($_POST["id_nivel_grado"] ?? 0);

        $fechaInicioValida = DateTime::createFromFormat("Y-m-d", $fechaInicio) !== false;
        $fechaFinValida = $fechaFin === "" || DateTime::createFromFormat("Y-m-d", $fechaFin) !== false;

        if ($titulo === "" || !in_array($tipo, eventos_tipos_creables(), true) || !$fechaInicioValida) {

            $mensaje = "Completa el título, el tipo y una fecha de inicio válida.";
            $mensajeTipo = "error";

        } elseif (!$fechaFinValida) {

            $mensaje = "La fecha de fin no es válida.";
            $mensajeTipo = "error";

        } elseif ($fechaFin !== "" && $fechaFin < $fechaInicio) {

            $mensaje = "La fecha de fin no puede ser anterior a la fecha de inicio.";
            $mensajeTipo = "error";

        } else {

            eventos_crear($conexion, [
                "titulo"         => $titulo,
                "descripcion"    => $descripcion,
                "tipo"           => $tipo,
                "fecha_inicio"   => $fechaInicio,
                "fecha_fin"      => $fechaFin,
                "id_nivel_grado" => $idNivelGrado,
                "id_usuario"     => (int) $_SESSION["id_usuario"],
            ]);

            $mensaje = "Evento agregado al calendario.";
            $mensajeTipo = "success";

        }

    } elseif ($accion === "eliminar") {

        eventos_eliminar($conexion, (int) ($_POST["id_evento"] ?? 0));

        $mensaje = "Evento eliminado del calendario.";
        $mensajeTipo = "success";

    }

    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }

    // Conserva el mes que se estaba viendo al volver (PRG).
    $volverA = "calendario.php";
    if (!empty($_POST["volver_anio"]) && !empty($_POST["volver_mes"])) {
        $volverA .= "?anio=" . (int) $_POST["volver_anio"] . "&mes=" . (int) $_POST["volver_mes"];
    }
    header("Location: $volverA");
    exit;

}

// ==================================================
// MES A MOSTRAR (por defecto, el actual)
// ==================================================
$hoy = new DateTimeImmutable("today");
$anio = (int) ($_GET["anio"] ?? $hoy->format("Y"));
$mes = (int) ($_GET["mes"] ?? $hoy->format("n"));

if ($mes < 1 || $mes > 12) {
    $mes = (int) $hoy->format("n");
    $anio = (int) $hoy->format("Y");
}

$primerDiaMes = new DateTimeImmutable(sprintf("%04d-%02d-01", $anio, $mes));
$mesAnterior = $primerDiaMes->modify("-1 month");
$mesSiguiente = $primerDiaMes->modify("+1 month");

$semanas = calendario_grid_semanas($anio, $mes);
$eventos = eventos_listar_mes($conexion, $anio, $mes, null); // Admin ve todo, sin filtrar por grado
$eventosPorDia = eventos_agrupar_por_dia($eventos);

$nivelesGrados = $conexion->query("
    SELECT id_nivel_grado, nivel, grado, nombre
    FROM niveles_grados
    ORDER BY nivel, grado
")->fetchAll();

$tituloMes = ucfirst(mes_nombre_es($mes)) . " de " . $anio;

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario Académico · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Calendario Académico</h1>
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

    <form class="panel-form" method="POST">
    <?= csrf_field() ?>
        <h3>Agregar evento</h3>
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="volver_anio" value="<?= $anio ?>">
        <input type="hidden" name="volver_mes" value="<?= $mes ?>">
        <div class="form-row">
            <div class="field">
                <label for="titulo">Título</label>
                <input type="text" name="titulo" id="titulo" placeholder="Ej. Examen bimestral de Matemática" required>
            </div>
            <div class="field">
                <label for="tipo">Tipo</label>
                <select name="tipo" id="tipo" required>
                    <?php foreach (eventos_tipos_creables() as $t): ?>
                        <option value="<?= $t ?>"><?= htmlspecialchars(eventos_tipo_meta($t)["label"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="field">
                <label for="fecha_inicio">Fecha de inicio</label>
                <input type="date" name="fecha_inicio" id="fecha_inicio" required>
            </div>
            <div class="field">
                <label for="fecha_fin">Fecha de fin (opcional)</label>
                <input type="date" name="fecha_fin" id="fecha_fin">
            </div>
        </div>
        <div class="field">
            <label for="id_nivel_grado">Dirigido a</label>
            <select name="id_nivel_grado" id="id_nivel_grado">
                <option value="0">Todo el colegio</option>
                <?php foreach ($nivelesGrados as $ng): ?>
                    <option value="<?= (int) $ng["id_nivel_grado"] ?>"><?= htmlspecialchars(nivel_grado_label($ng)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="descripcion">Descripción (opcional)</label>
            <textarea name="descripcion" id="descripcion" placeholder="Detalles adicionales del evento…"></textarea>
        </div>
        <button type="submit" class="btn-submit">Guardar evento</button>
    </form>

    <div class="cal-toolbar">
        <h2><?= htmlspecialchars($tituloMes) ?></h2>
        <div class="cal-nav">
            <a class="cal-nav-btn" href="calendario.php?anio=<?= $mesAnterior->format("Y") ?>&mes=<?= (int) $mesAnterior->format("n") ?>" aria-label="Mes anterior"><?= icon("chevron-left") ?></a>
            <a class="cal-today-btn" href="calendario.php">Hoy</a>
            <a class="cal-nav-btn cal-next" href="calendario.php?anio=<?= $mesSiguiente->format("Y") ?>&mes=<?= (int) $mesSiguiente->format("n") ?>" aria-label="Mes siguiente"><?= icon("chevron-left") ?></a>
        </div>
    </div>

    <p class="placeholder-text">Exámenes, reuniones, feriados y actividades del colegio, en un solo lugar. "Dirigido a" decide quién lo ve: todo el colegio o un grado puntual.</p>

    <div class="cal-legend">
        <?php foreach (["EXAMEN", "REUNION", "FERIADO", "ACTIVIDAD", "OTRO"] as $t): $m = eventos_tipo_meta($t); ?>
            <span class="cal-legend-pill <?= $m["clase"] ?>"><?= htmlspecialchars($m["label"]) ?></span>
        <?php endforeach; ?>
    </div>

    <div class="cal-grid">
        <?php foreach (["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"] as $diaNombre): ?>
            <div class="cal-weekday"><?= $diaNombre ?></div>
        <?php endforeach; ?>

        <?php foreach ($semanas as $semana): foreach ($semana as $dia): ?>
            <?php if ($dia === null): ?>
                <div class="cal-day is-empty"></div>
            <?php else:
                $clave = $dia->format("Y-m-d");
                $esHoy = $clave === $hoy->format("Y-m-d");
                $eventosDelDia = $eventosPorDia[$clave] ?? [];
            ?>
                <div class="cal-day<?= $esHoy ? " is-today" : "" ?>">
                    <span class="cal-day-num"><?= (int) $dia->format("j") ?></span>
                    <div class="cal-day-events">
                        <?php foreach (array_slice($eventosDelDia, 0, 3) as $ev): $m = eventos_tipo_meta($ev["tipo"]); ?>
                            <span class="cal-event <?= $m["clase"] ?>" title="<?= htmlspecialchars($ev["titulo"]) ?>"><?= htmlspecialchars($ev["titulo"]) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($eventosDelDia) > 3): ?>
                            <span class="cal-event-more">+<?= count($eventosDelDia) - 3 ?> más</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; endforeach; ?>
    </div>

    <h2>Eventos de <?= htmlspecialchars($tituloMes) ?> (<?= count($eventos) ?>)</h2>

    <?php if (count($eventos) === 0): ?>
        <p class="placeholder-text">Todavía no hay eventos registrados para este mes.</p>
    <?php else: ?>
        <div class="cal-agenda">
            <?php foreach ($eventos as $ev): $m = eventos_tipo_meta($ev["tipo"]); $fIni = new DateTimeImmutable($ev["fecha_inicio"]); ?>
                <div class="cal-agenda-item">
                    <div class="cal-agenda-date">
                        <strong><?= $fIni->format("d") ?></strong>
                        <span><?= mes_abrev_es((int) $fIni->format("n")) ?></span>
                    </div>
                    <div class="cal-agenda-body">
                        <h4><?= htmlspecialchars($ev["titulo"]) ?></h4>
                        <?php if (!empty($ev["descripcion"])): ?>
                            <p><?= htmlspecialchars($ev["descripcion"]) ?></p>
                        <?php endif; ?>
                        <div class="cal-agenda-meta">
                            <span class="cal-agenda-tipo <?= $m["clase"] ?>"><?= htmlspecialchars($m["label"]) ?></span>
                            <span class="cal-agenda-grado"><?= $ev["id_nivel_grado"] ? htmlspecialchars(nivel_grado_label(["nombre" => $ev["grado_nombre"], "nivel" => $ev["grado_nivel"]])) : "Todo el colegio" ?></span>
                            <span class="placeholder-text" style="margin:0;">Creado por <?= htmlspecialchars($ev["nombres"] . " " . $ev["apellidos"]) ?></span>
                            <form method="POST" class="cal-agenda-actions" onsubmit="return confirm('¿Eliminar este evento del calendario?');">
                            <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id_evento" value="<?= (int) $ev["id_evento"] ?>">
                                <input type="hidden" name="volver_anio" value="<?= $anio ?>">
                                <input type="hidden" name="volver_mes" value="<?= $mes ?>">
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
