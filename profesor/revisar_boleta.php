<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";
require_once __DIR__ . "/../backend/config/importacion_notas.php";
require_once __DIR__ . "/../backend/config/admin_seguimiento.php";
[$mensaje, $mensajeTipo] = flash_get();

$idBoleta = (int) ($_GET["id_boleta"] ?? $_POST["id_boleta"] ?? 0);
$idProfesor = (int) $profesor["id_profesor"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    try {

        $idExtraccion = (int) $_POST["id_extraccion"];
        $valorFinal = trim($_POST["valor_final"] ?? "");
        $valoresCompetencias = [];

        foreach ($_POST["competencia"] ?? [] as $idExtraccionComp => $valor) {
            $valoresCompetencias[(int) $idExtraccionComp] = trim($valor);
        }

        profesor_fila_confirmar($conexion, $idExtraccion, $idProfesor, $valorFinal !== "" ? $valorFinal : null, $valoresCompetencias);

        auditoria_evento(
            $conexion,
            (int) $_SESSION["id_usuario"],
            "CONFIRMAR",
            "BOLETA_NOTA",
            $idExtraccion,
            "Profesor #$idProfesor confirmó la fila #$idExtraccion de la boleta #$idBoleta"
            . ($valorFinal !== "" ? " (valor final: " . mb_substr($valorFinal, 0, 20, "UTF-8") . ")" : "")
        );

        flash_set("Fila confirmada.", "success");

    } catch (\Throwable $e) {
        flash_set($e->getMessage(), "error");
    }

    header("Location: revisar_boleta.php?id_boleta=$idBoleta");
    exit;

}

// Nunca confía en un id_boleta suelto: profesor_boleta_filas() ya filtra
// solo las filas que realmente pertenecen a un curso de este profesor,
// vía profesor_curso_grado — si no tiene ninguna fila ahí, la lista queda
// vacía en vez de mostrar datos de otro profesor.
$filas = profesor_boleta_filas($conexion, $idBoleta, $idProfesor);

$filasPorCurso = [];
foreach ($filas as $fila) {
    $filasPorCurso[$fila["curso_nombre"]][] = $fila;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisar mis notas · Profesor - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = "notas_pendientes.php"; include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Revisar mis notas de esta boleta</h1>
        <p>Confirma que la nota extraída del PDF coincide con lo que evaluaste. Puedes corregir el valor si el PDF lo leyó mal.</p>
    </div>
    <div class="header-actions">
        <a href="notas_pendientes.php">Volver</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if (count($filas) === 0): ?>
        <p class="placeholder-text">No hay filas de tus cursos en esta boleta (o ya las revisaste todas).</p>
    <?php endif; ?>

    <?php foreach ($filasPorCurso as $nombreCurso => $filasCurso): ?>
        <h3><?= htmlspecialchars($nombreCurso) ?>
            — <?= htmlspecialchars($filasCurso[0]["alumno_apellidos"] . ", " . $filasCurso[0]["alumno_nombres"]) ?>
        </h3>

        <?php foreach ($filasCurso as $fila): ?>
            <form method="POST" class="panel-form" style="margin-bottom: 16px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id_boleta" value="<?= $idBoleta ?>">
                <input type="hidden" name="id_extraccion" value="<?= (int) $fila["id_extraccion"] ?>">

                <div class="form-row" style="justify-content: space-between; align-items: center;">
                    <strong>Bimestre <?= (int) $fila["bimestre"] ?></strong>
                    <?php if ($fila["revisado_por_profesor"]): ?>
                        <span class="role-badge">Ya revisada</span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label>Nota final del área (según lo que tú evaluaste — deja vacío si tu boletín no la calcula por bimestre)</label>
                    <input type="text" name="valor_final" value="<?= htmlspecialchars($fila["valor_final_extraido"] ?? "") ?>" style="max-width: 120px;">
                </div>

                <?php if (count($fila["competencias"]) > 0): ?>
                    <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Competencia</th><th>Valor</th></tr></thead>
                        <tbody>
                            <?php foreach ($fila["competencias"] as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c["competencia_nombre"] ?? $c["texto_competencia_extraido"]) ?></td>
                                    <td>
                                        <input type="text" name="competencia[<?= (int) $c["id_extraccion_competencia"] ?>]"
                                               value="<?= htmlspecialchars($c["valor_extraido"]) ?>" style="max-width: 100px;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-submit">Confirmar bimestre <?= (int) $fila["bimestre"] ?></button>
            </form>
        <?php endforeach; ?>
    <?php endforeach; ?>

</main>
</div>
</div>

</body>
</html>
