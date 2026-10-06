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
require_once "../backend/config/importacion_notas.php";
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Carga masiva: el navegador manda UN pdf por petición (fetch) y
    // esta página responde JSON. Así no hay límite de max_file_uploads
    // ni de post_max_size por lote, y cada boleta se procesa aislada
    // (si una falla, las demás siguen).
    $esAjax = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "fetch";

    if ($esAjax) {
        header("Content-Type: application/json; charset=utf-8");
        @set_time_limit(120);
        if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
            http_response_code(403);
            echo json_encode(["resultado" => "error", "mensaje" => "Token de seguridad inválido o expirado. Recarga la página."]);
            exit;
        }
    } else {
        csrf_verificar();
    }

    try {

        $idPeriodo = (int) ($_POST["id_periodo"] ?? 0);
        $idAlumno = !empty($_POST["id_alumno"]) ? (int) $_POST["id_alumno"] : null;

        if ($idPeriodo <= 0) {
            throw new RuntimeException("Selecciona el periodo académico (año) al que corresponde esta boleta.");
        }

        $datosArchivo = boleta_guardar_pdf($_FILES["pdf_boleta"] ?? []);

        $existente = boleta_buscar_por_hash($conexion, $datosArchivo["hash"]);

        // Si el mismo archivo ya se subió pero quedó en ERROR (nunca tuvo filas), no es un duplicado
        // real: se reprocesa esa misma boleta con el archivo recién subido, en vez de obligar a borrarla.
        $reintentar = $existente && $existente["estado"] === "ERROR";

        if ($existente && !$reintentar) {
            @unlink($datosArchivo["ruta_absoluta"]); // el PDF ya está guardado con otro registro; no dejar copia huérfana
            if ($esAjax) {
                echo json_encode([
                    "resultado" => "duplicada",
                    "id_boleta" => (int) $existente["id_boleta"],
                    "mensaje" => "Ya se había subido (boleta #" . $existente["id_boleta"] . ", " . $existente["estado"] . ").",
                ]);
                exit;
            }
            throw new RuntimeException(
                "Este mismo archivo ya fue subido antes (boleta #" . $existente["id_boleta"] . ", estado " . $existente["estado"] . ")."
            );
        }

        if ($reintentar) {
            $idBoleta = (int) $existente["id_boleta"];
            $conexion->prepare("UPDATE boletas_importadas SET ruta_archivo = ?, id_periodo = ?, estado = 'PENDIENTE', mensaje_error = NULL WHERE id_boleta = ?")
                ->execute([$datosArchivo["ruta_relativa"], $idPeriodo, $idBoleta]);
            @unlink(__DIR__ . "/../" . $existente["ruta_archivo"]); // reemplaza el archivo anterior de esa boleta
        } else {
            $idBoleta = boleta_crear($conexion, $datosArchivo, $idPeriodo, $idAlumno, (int) $_SESSION["id_usuario"]);
        }

        auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "SUBIR", "BOLETA", $idBoleta, $datosArchivo["nombre_original"]);

        $soloBimestre = (int) ($_POST["bimestre"] ?? 0);
        boleta_procesar($conexion, $idBoleta, $soloBimestre >= 1 && $soloBimestre <= 4 ? $soloBimestre : null);

        if ($esAjax) {
            $boleta = boleta_obtener($conexion, $idBoleta);
            if ($boleta["estado"] === "ERROR") {
                echo json_encode(["resultado" => "error", "id_boleta" => $idBoleta, "mensaje" => $boleta["mensaje_error"]]);
                exit;
            }
            $resumen = boleta_resumen_alumno($conexion, $idBoleta);
            echo json_encode([
                "resultado" => $resumen["id_alumno"] ? "asignada" : "sin_asignar",
                "id_boleta" => $idBoleta,
                "alumno" => $resumen["nombre"],
                "dni" => $resumen["dni"],
                "mensaje" => $resumen["advertencia"],
            ]);
            exit;
        }

        flash_set("Boleta subida y procesada. Revisa el resultado abajo.", "success");
        header("Location: revisar_importacion.php?id_boleta=$idBoleta");
        exit;

    } catch (\Throwable $e) {
        if ($esAjax) {
            echo json_encode(["resultado" => "error", "mensaje" => $e->getMessage()]);
            exit;
        }
        flash_set($e->getMessage(), "error");
        header("Location: importar_notas.php");
        exit;
    }

}

$periodos = $conexion->query("SELECT id_periodo, nombre FROM periodos_academicos ORDER BY id_periodo DESC")->fetchAll();
$alumnos = $conexion->query("SELECT id_alumno, nombres, apellidos, dni FROM alumnos WHERE estado = 'ACTIVO' ORDER BY apellidos, nombres")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar notas · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Importar notas desde PDF</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="importaciones_pendientes.php">Ver historial de importaciones</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form class="panel-form" id="form-lote" method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h3>Subir boletas (PDF) — una o cientos a la vez</h3>
        <p class="placeholder-text">
            Cada PDF es el "Informe de Progreso del Aprendizaje" de UN alumno. Sube los PDFs bimestre por
            bimestre: elige abajo qué bimestre es. El sistema lee el DNI dentro del PDF y manda las notas al alumno que le corresponde
            (si el DNI no aparece, prueba por nombre). Si no logra identificarlo, la boleta queda
            "Sin asignar" para que la asignes a mano. Nada llega a las notas reales todavía: primero
            pasa por revisión del profesor y aprobación de Subdirección.
        </p>

        <div class="field">
            <label for="id_periodo">Periodo académico (año)</label>
            <select name="id_periodo" id="id_periodo" required>
                <?php foreach ($periodos as $p): ?>
                    <option value="<?= (int) $p["id_periodo"] ?>"><?= htmlspecialchars($p["nombre"]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="bimestre">Bimestre que estás subiendo</label>
            <select name="bimestre" id="bimestre" required>
                <option value="1">1er bimestre</option>
                <option value="2">2do bimestre</option>
                <option value="3">3er bimestre</option>
                <option value="4">4to bimestre</option>
                <option value="0">Todos los que traiga el PDF</option>
            </select>
            <small class="placeholder-text">Cada boleta trae los bimestres ya cerrados; se importa solo el que elijas, así no se repiten los anteriores.</small>
        </div>

        <div class="field">
            <label for="pdf_boletas">Archivos PDF (puedes seleccionar muchos, o una carpeta completa)</label>
            <input type="file" id="pdf_boletas" accept="application/pdf" multiple required>
        </div>

        <div class="field">
            <label><input type="checkbox" id="carpeta"> Elegir una carpeta entera en vez de archivos sueltos</label>
        </div>

        <button type="submit" class="btn-submit" id="btn-lote">Subir y procesar</button>
    </form>

    <section id="lote-resultado" style="display:none; margin-top:1.5rem;">
        <h3 id="lote-titulo">Procesando…</h3>
        <progress id="lote-barra" value="0" max="1" style="width:100%;"></progress>
        <p id="lote-resumen" class="placeholder-text"></p>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Archivo</th><th>Resultado</th><th>Alumno</th><th></th></tr></thead>
                <tbody id="lote-filas"></tbody>
            </table>
        </div>
    </section>

    <script>
    (function () {
        var form = document.getElementById("form-lote");
        var input = document.getElementById("pdf_boletas");
        var chkCarpeta = document.getElementById("carpeta");
        var tbody = document.getElementById("lote-filas");
        var CONCURRENCIA = 3;

        chkCarpeta.addEventListener("change", function () {
            if (chkCarpeta.checked) { input.setAttribute("webkitdirectory", ""); }
            else { input.removeAttribute("webkitdirectory"); }
            input.value = "";
        });

        function celda(texto) {
            var td = document.createElement("td");
            td.textContent = texto || "";
            return td;
        }

        var ETIQ = {
            asignada: "Asignada al alumno",
            sin_asignar: "Sin asignar (asignar a mano)",
            duplicada: "Duplicada (ya estaba)",
            error: "Error",
            pendiente: "En cola…",
            subiendo: "Procesando…"
        };

        form.addEventListener("submit", function (ev) {
            ev.preventDefault();

            var archivos = Array.prototype.filter.call(input.files, function (f) {
                return /\.pdf$/i.test(f.name);
            });
            if (archivos.length === 0) { alert("No hay archivos PDF seleccionados."); return; }

            var periodo = document.getElementById("id_periodo").value;
            var bimestre = document.getElementById("bimestre").value;
            var token = form.querySelector("[name=csrf_token]").value;

            document.getElementById("btn-lote").disabled = true;
            document.getElementById("lote-resultado").style.display = "block";
            document.getElementById("lote-titulo").textContent = "Procesando " + archivos.length + " boleta(s)…";
            tbody.innerHTML = "";

            var barra = document.getElementById("lote-barra");
            barra.max = archivos.length; barra.value = 0;

            var conteo = { asignada: 0, sin_asignar: 0, duplicada: 0, error: 0 };
            var hechos = 0, siguiente = 0;

            var filas = archivos.map(function (f) {
                var tr = document.createElement("tr");
                var tdRes = celda(ETIQ.pendiente), tdAl = celda(""), tdLink = document.createElement("td");
                tr.appendChild(celda(f.name)); tr.appendChild(tdRes); tr.appendChild(tdAl); tr.appendChild(tdLink);
                tbody.appendChild(tr);
                return { tdRes: tdRes, tdAl: tdAl, tdLink: tdLink };
            });

            function resumen() {
                document.getElementById("lote-resumen").textContent =
                    conteo.asignada + " asignadas · " + conteo.sin_asignar + " sin asignar · " +
                    conteo.duplicada + " duplicadas · " + conteo.error + " con error";
            }

            function terminar() {
                document.getElementById("lote-titulo").textContent = "Listo: " + archivos.length + " boleta(s) procesadas";
                document.getElementById("btn-lote").disabled = false;
                resumen();
            }

            function trabajar() {
                if (siguiente >= archivos.length) { return Promise.resolve(); }
                var i = siguiente++;
                var fila = filas[i];
                fila.tdRes.textContent = ETIQ.subiendo;

                var fd = new FormData();
                fd.append("csrf_token", token);
                fd.append("id_periodo", periodo);
                fd.append("bimestre", bimestre);
                fd.append("pdf_boleta", archivos[i]);

                return fetch("importar_notas.php", {
                    method: "POST",
                    body: fd,
                    headers: { "X-Requested-With": "fetch" },
                    credentials: "same-origin"
                }).then(function (r) { return r.json(); }).catch(function () {
                    return { resultado: "error", mensaje: "No se pudo procesar (red o servidor)." };
                }).then(function (d) {
                    var clave = ETIQ[d.resultado] ? d.resultado : "error";
                    conteo[clave]++;
                    fila.tdRes.textContent = ETIQ[clave] + (d.mensaje && clave !== "asignada" ? " — " + d.mensaje : "");
                    if (d.alumno) { fila.tdAl.textContent = d.alumno + (d.dni ? " (DNI " + d.dni + ")" : ""); }
                    if (d.id_boleta && clave !== "error") {
                        var a = document.createElement("a");
                        a.href = "revisar_importacion.php?id_boleta=" + d.id_boleta;
                        a.textContent = clave === "sin_asignar" ? "Asignar" : "Ver";
                        fila.tdLink.appendChild(a);
                    }
                    hechos++; barra.value = hechos; resumen();
                    return trabajar();
                });
            }

            var hilos = [];
            for (var k = 0; k < Math.min(CONCURRENCIA, archivos.length); k++) { hilos.push(trabajar()); }
            Promise.all(hilos).then(terminar);
        });
    })();
    </script>

</main>
</div>
</div>

</body>
</html>
