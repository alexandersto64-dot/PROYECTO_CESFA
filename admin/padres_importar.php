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
require_once "../backend/config/politica_password.php";
require_once "../backend/config/validar_contenido_archivo.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";
require_once "../backend/config/correo.php";

// ==========================================================
// Importación masiva de cuentas de padres desde un CSV.
//
// Columnas (con encabezado, en cualquier orden):
//   dni_alumno, nombres_padre, apellidos_padre, correo
//
// Por cada fila:
//   - busca al alumno por DNI (si no existe, la fila se omite)
//   - si ya hay un usuario con ese correo, NO crea otra cuenta:
//     solo lo vincula al alumno (así un padre con 2 hijos = 1 cuenta)
//   - si no existe, crea la cuenta PADRE con contraseña temporal
//     aleatoria (hash, nunca texto plano en la BD) y lo vincula
// La contraseña temporal solo se muestra en esta pantalla (para
// descargarla); no se guarda en ningún lado.
// ==========================================================

$idRolPadre = (int) $conexion->query("SELECT id_rol FROM roles WHERE nombre = 'PADRE'")->fetchColumn();

function padres_csv_normalizar_encabezado(string $h): string {
    $h = mb_strtolower(trim($h), "UTF-8");
    $h = str_replace(["\xEF\xBB\xBF", " "], ["", "_"], $h);
    return strtr($h, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u"]);
}

function padres_password_temporal(): string {
    $alfabeto = "ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789";
    $p = "";
    for ($i = 0; $i < 10; $i++) {
        $p .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $p;
}

$resultados = null;
$resumen = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();
    @set_time_limit(0);

    try {

        $archivo = $_FILES["csv"] ?? null;
        if (!$archivo || $archivo["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo["tmp_name"])) {
            throw new RuntimeException("Selecciona un archivo CSV.");
        }
        if ($archivo["size"] > 5 * 1024 * 1024) {
            throw new RuntimeException("El CSV supera 5 MB.");
        }
        if (strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION)) !== "csv") {
            throw new RuntimeException("Solo se aceptan archivos .csv (en Excel: Guardar como → CSV UTF-8).");
        }

        // El CSV debe ser texto de verdad (no un binario renombrado).
        archivo_validar_contenido($archivo["tmp_name"], "csv");

        $enviarCorreo = !empty($_POST["enviar_correo"]);

        $fh = fopen($archivo["tmp_name"], "r");
        $primera = fgets($fh);
        rewind($fh);
        $delim = substr_count($primera, ";") > substr_count($primera, ",") ? ";" : ",";

        $encabezado = fgetcsv($fh, 0, $delim);
        $cols = array_flip(array_map("padres_csv_normalizar_encabezado", $encabezado ?: []));
        foreach (["dni_alumno", "nombres_padre", "apellidos_padre", "correo"] as $req) {
            if (!isset($cols[$req])) {
                throw new RuntimeException("Falta la columna \"$req\" en el encabezado del CSV.");
            }
        }

        $stmtAlumno = $conexion->prepare("SELECT id_alumno FROM alumnos WHERE dni = ? LIMIT 1");
        $stmtUsuario = $conexion->prepare("SELECT id_usuario, id_rol FROM usuarios WHERE correo = ? LIMIT 1");
        $stmtCrear = $conexion->prepare("INSERT INTO usuarios (nombres, apellidos, correo, password, estado, id_rol) VALUES (?, ?, ?, ?, 'ACTIVO', ?)");
        $stmtVinculo = $conexion->prepare("INSERT INTO padres_alumnos (id_usuario_padre, id_alumno) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_usuario_padre = id_usuario_padre");

        $resultados = [];
        $resumen = ["creadas" => 0, "vinculadas" => 0, "omitidas" => 0];
        $numFila = 1;

        while (($f = fgetcsv($fh, 0, $delim)) !== false) {

            $numFila++;
            if (count(array_filter($f, fn($c) => trim((string) $c) !== "")) === 0) {
                continue; // fila vacía
            }

            $dni = preg_replace('/\D/', '', $f[$cols["dni_alumno"]] ?? "");
            $nombres = trim($f[$cols["nombres_padre"]] ?? "");
            $apellidos = trim($f[$cols["apellidos_padre"]] ?? "");
            $correo = mb_strtolower(trim($f[$cols["correo"]] ?? ""), "UTF-8");

            $omitir = function (string $motivo) use (&$resultados, &$resumen, $numFila, $correo, $dni) {
                $resultados[] = ["fila" => $numFila, "correo" => $correo, "dni_alumno" => $dni, "estado" => "Omitida", "detalle" => $motivo, "password" => ""];
                $resumen["omitidas"]++;
            };

            if (!preg_match('/^\d{8}$/', $dni)) { $omitir("DNI del alumno inválido (deben ser 8 dígitos)."); continue; }
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { $omitir("Correo inválido o vacío."); continue; }

            $stmtAlumno->execute([$dni]);
            $idAlumno = $stmtAlumno->fetchColumn();
            if (!$idAlumno) { $omitir("No existe un alumno con ese DNI en el sistema."); continue; }

            $stmtUsuario->execute([$correo]);
            $existente = $stmtUsuario->fetch();

            if ($existente) {
                if ((int) $existente["id_rol"] !== $idRolPadre) {
                    $omitir("Ese correo pertenece a un usuario que no es padre de familia.");
                    continue;
                }
                $stmtVinculo->execute([(int) $existente["id_usuario"], (int) $idAlumno]);
                $resultados[] = ["fila" => $numFila, "correo" => $correo, "dni_alumno" => $dni, "estado" => "Vinculada", "detalle" => "La cuenta ya existía; se vinculó al alumno.", "password" => ""];
                $resumen["vinculadas"]++;
                continue;
            }

            if ($nombres === "" || $apellidos === "") { $omitir("Faltan nombres o apellidos del padre."); continue; }

            $password = padres_password_temporal();

            try {
                $stmtCrear->execute([$nombres, $apellidos, $correo, password_hash($password, PASSWORD_DEFAULT), $idRolPadre]);
                $idNuevo = (int) $conexion->lastInsertId();
                password_marcar_cambio_obligatorio($conexion, $idNuevo);
                $stmtVinculo->execute([$idNuevo, (int) $idAlumno]);
            } catch (\Throwable $e) {
                $omitir("No se pudo crear la cuenta.");
                continue;
            }

            $detalle = "Cuenta creada y vinculada.";

            if ($enviarCorreo) {
                $ok = correo_enviar(
                    $correo, $nombres, "Tu cuenta del Intranet - I.E.P. 88044",
                    correo_plantilla("Bienvenido al Intranet", "
                        <p>Hola " . htmlspecialchars($nombres) . ",</p>
                        <p>Se creó tu cuenta de Padre de familia en el Intranet del I.E.P. 88044 Abraham Valdelomar.</p>
                        <p><strong>Correo:</strong> " . htmlspecialchars($correo) . "<br>
                        <strong>Contraseña temporal:</strong> " . htmlspecialchars($password) . "</p>
                        <p>Ingresa y cámbiala desde tu perfil en cuanto puedas.</p>
                    ")
                );
                $detalle .= $ok ? " Contraseña enviada por correo." : " El correo NO se pudo enviar: entrega la contraseña a mano.";
            }

            $resultados[] = ["fila" => $numFila, "correo" => $correo, "dni_alumno" => $dni, "estado" => "Creada", "detalle" => $detalle, "password" => $password];
            $resumen["creadas"]++;

        }

        fclose($fh);

        auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "IMPORTAR", "PADRES", 0,
            "CSV de padres: {$resumen['creadas']} creadas, {$resumen['vinculadas']} vinculadas, {$resumen['omitidas']} omitidas");

    } catch (\Throwable $e) {
        flash_set($e->getMessage(), "error");
        header("Location: padres_importar.php");
        exit;
    }

}

[$mensaje, $mensajeTipo] = flash_get();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar padres · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Importar cuentas de padres (CSV)</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="padres.php">Padres de familia</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($resultados === null): ?>

    <form class="panel-form" method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h3>Crear y vincular muchas cuentas a la vez</h3>
        <p class="placeholder-text">
            Prepara una hoja de Excel con estas columnas (primera fila = encabezado):
            <strong>dni_alumno, nombres_padre, apellidos_padre, correo</strong>. Guárdala como CSV.
            Un padre con varios hijos va en varias filas con el mismo correo: se crea una sola cuenta y se vincula a todos.
            El alumno debe existir antes en el sistema (se busca por DNI).
            Cada padre entra con su <strong>correo</strong> y una contraseña temporal generada automáticamente.
        </p>
        <p><a href="data:text/csv;charset=utf-8,%EF%BB%BFdni_alumno,nombres_padre,apellidos_padre,correo%0A12345678,Juan,Perez%20Gomez,juan.perez@correo.com" download="plantilla_padres.csv">Descargar plantilla CSV</a></p>

        <div class="field">
            <label for="csv">Archivo CSV</label>
            <input type="file" name="csv" id="csv" accept=".csv,text/csv" required>
        </div>

        <div class="field">
            <label><input type="checkbox" name="enviar_correo" value="1"> Enviar la contraseña por correo a cada padre (con muchos padres puede tardar; si lo dejas sin marcar, al final descargas un archivo con las contraseñas para entregarlas)</label>
        </div>

        <button type="submit" class="btn-submit">Importar</button>
    </form>

    <?php else: ?>

    <h2>Resultado: <?= (int) $resumen["creadas"] ?> creadas · <?= (int) $resumen["vinculadas"] ?> vinculadas · <?= (int) $resumen["omitidas"] ?> omitidas</h2>

    <?php if ($resumen["creadas"] > 0): ?>
        <p class="placeholder-text">Las contraseñas temporales solo se ven en esta pantalla. Descárgalas ahora si necesitas entregarlas.</p>
        <p><button type="button" class="btn-submit" id="btn-descargar">Descargar credenciales (CSV)</button></p>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Fila</th><th>Correo</th><th>DNI alumno</th><th>Resultado</th><th>Detalle</th></tr></thead>
            <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><?= (int) $r["fila"] ?></td>
                        <td><?= htmlspecialchars($r["correo"]) ?></td>
                        <td><?= htmlspecialchars($r["dni_alumno"]) ?></td>
                        <td><?= htmlspecialchars($r["estado"]) ?></td>
                        <td><?= htmlspecialchars($r["detalle"]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p><a href="padres_importar.php">Importar otro archivo</a></p>

    <?php if ($resumen["creadas"] > 0): ?>
    <script>
    (function () {
        var creadas = <?= json_encode(array_values(array_map(
            fn($r) => ["correo" => $r["correo"], "password" => $r["password"]],
            array_filter($resultados, fn($r) => $r["estado"] === "Creada")
        ))) ?>;
        document.getElementById("btn-descargar").addEventListener("click", function () {
            var filas = ["correo,contrasena_temporal"].concat(creadas.map(function (c) { return c.correo + "," + c.password; }));
            var blob = new Blob(["\ufeff" + filas.join("\n")], { type: "text/csv;charset=utf-8" });
            var a = document.createElement("a");
            a.href = URL.createObjectURL(blob);
            a.download = "credenciales_padres.csv";
            document.body.appendChild(a); a.click(); a.remove();
        });
    })();
    </script>
    <?php endif; ?>

    <?php endif; ?>

</main>
</div>
</div>

</body>
</html>
