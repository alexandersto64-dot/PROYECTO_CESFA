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
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";

// ==========================================================
// Cuentas familiares por alumno (acceso con CÓDIGO DE ESTUDIANTE).
//
// Cuando el colegio no tiene los nombres ni correos de los padres,
// se crea UNA cuenta de usuario (rol PADRE) por alumno:
//   - nombres = "Familia", apellidos = apellidos del alumno
//   - correo técnico único y NO real: <codigo>@padres.ie88044.invalid
//     (el dominio .invalid está reservado: nunca recibe correo)
//   - vínculo en padres_alumnos con ese alumno
//   - contraseña temporal aleatoria (solo se guarda el hash)
// La familia entra en login.html con: código + contraseña.
//
// Reglas de seguridad (solo aditivo):
//   - NUNCA se crea cuenta a un alumno que ya tiene un padre vinculado.
//   - NUNCA se modifica ni borra alumnos, notas, boletas ni usuarios
//     existentes. Solo INSERT en usuarios y padres_alumnos.
//   - Es repetible: volver a ejecutar no duplica nada.
//   - El alumno se identifica por alumnos.codigo_estudiante (14 dígitos,
//     texto). Nunca por nombre.
// ==========================================================

const FAMILIA_DOMINIO = "padres.ie88044.invalid";
const CODIGO_LARGO = 14;

$idRolPadre = (int) $conexion->query("SELECT id_rol FROM roles WHERE nombre = 'PADRE'")->fetchColumn();

if ($idRolPadre <= 0) {
    die("No existe el rol PADRE en la base de datos.");
}

function familias_password(): string {
    // Sin caracteres ambiguos (0/O, 1/l/I) para que se lea bien en papel.
    $alfabeto = "ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789";
    $p = "";
    for ($i = 0; $i < 10; $i++) {
        $p .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $p;
}

function familias_correo(string $codigo): string {
    return $codigo . "@" . FAMILIA_DOMINIO;
}

/**
 * Clasifica a los alumnos activos SIN escribir nada en la BD.
 * La pantalla (vista previa) y la acción usan exactamente esta misma
 * lista, así que lo que se ve es lo que se ejecuta.
 *
 * estado: crear | relinkear | ya_padre | sin_codigo | trasladado |
 *         papelera | rol_incorrecto
 */
function familias_clasificar(PDO $conexion, int $idRolPadre, bool $incluirTrasladados): array {

    // Alumnos con matrícula TRASLADADO/RETIRADO y ninguna ACTIVA.
    $fuera = [];
    $rs = $conexion->query("
        SELECT DISTINCT m.id_alumno
        FROM matriculas m
        WHERE m.estado IN ('TRASLADADO', 'RETIRADO')
          AND NOT EXISTS (
              SELECT 1 FROM matriculas m2
              WHERE m2.id_alumno = m.id_alumno AND m2.estado = 'ACTIVA'
          )
    ");
    foreach ($rs->fetchAll() as $r) {
        $fuera[(int) $r["id_alumno"]] = true;
    }

    $alumnos = $conexion->query("
        SELECT a.id_alumno, a.nombres, a.apellidos, a.codigo_estudiante,
               gs.grado, gs.seccion, gs.nombre AS grado_nombre
        FROM alumnos a
        LEFT JOIN matriculas m
            ON m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'
        LEFT JOIN grados_secciones gs
            ON gs.id_grado_seccion = m.id_grado_seccion
        WHERE a.eliminado_en IS NULL AND a.estado = 'ACTIVO'
        ORDER BY gs.grado, gs.seccion, a.apellidos, a.nombres
    ")->fetchAll();

    $stmtPadre = $conexion->prepare("
        SELECT 1
        FROM padres_alumnos pa
        INNER JOIN usuarios u ON u.id_usuario = pa.id_usuario_padre
        WHERE pa.id_alumno = ? AND u.eliminado_en IS NULL
        LIMIT 1
    ");
    $stmtCorreo = $conexion->prepare("SELECT id_usuario, id_rol, eliminado_en FROM usuarios WHERE correo = ? LIMIT 1");

    $vistos = [];
    $lista = [];

    foreach ($alumnos as $a) {

        $id = (int) $a["id_alumno"];
        if (isset($vistos[$id])) { continue; } // varias matrículas ACTIVAS: una sola fila
        $vistos[$id] = true;

        $codigo = (string) ($a["codigo_estudiante"] ?? "");
        $item = $a + ["estado" => "", "id_usuario_existente" => null];

        if (!preg_match('/^\d{' . CODIGO_LARGO . '}$/', $codigo)) {
            $item["estado"] = "sin_codigo";
        } elseif (!$incluirTrasladados && isset($fuera[$id])) {
            $item["estado"] = "trasladado";
        } else {

            $stmtPadre->execute([$id]);

            if ($stmtPadre->fetchColumn()) {
                $item["estado"] = "ya_padre";
            } else {

                $stmtCorreo->execute([familias_correo($codigo)]);
                $u = $stmtCorreo->fetch();

                if (!$u) {
                    $item["estado"] = "crear";
                } elseif ($u["eliminado_en"] !== null) {
                    $item["estado"] = "papelera";
                } elseif ((int) $u["id_rol"] !== $idRolPadre) {
                    $item["estado"] = "rol_incorrecto";
                } else {
                    $item["estado"] = "relinkear";
                    $item["id_usuario_existente"] = (int) $u["id_usuario"];
                }

            }

        }

        $lista[] = $item;

    }

    return $lista;

}

const FAMILIAS_MOTIVOS = [
    "crear"          => "Cuenta por crear",
    "relinkear"      => "Cuenta familiar existente, sin vínculo (se vincula)",
    "ya_padre"       => "Ya tiene un padre/cuenta vinculada (no se toca)",
    "sin_codigo"     => "Sin código oficial de 14 dígitos (omitido)",
    "trasladado"     => "Matrícula trasladada/retirada (omitido)",
    "papelera"       => "Su cuenta familiar está en la papelera (restáurala en Usuarios)",
    "rol_incorrecto" => "El correo técnico pertenece a un usuario que no es padre (revisar)",
];

function familias_fila_credencial(array $a, string $password, string $detalle): array {
    return [
        "codigo"    => (string) $a["codigo_estudiante"],
        "apellidos" => (string) $a["apellidos"],
        "nombres"   => (string) $a["nombres"],
        "grado"     => (string) ($a["grado"] ?? ""),
        "seccion"   => (string) ($a["seccion"] ?? ""),
        "password"  => $password,
        "detalle"   => $detalle,
    ];
}

$credenciales = null;   // filas con contraseña nueva a mostrar (una sola vez)
$resumen = null;
$tituloResultado = "";
$omitidas = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();
    @set_time_limit(0);

    $accion = $_POST["accion"] ?? "";
    $idAdmin = (int) $_SESSION["id_usuario"];

    try {

        // --------------------------------------------------
        // A) GENERAR cuentas familiares
        // --------------------------------------------------
        if ($accion === "generar") {

            $incluirTrasl = !empty($_POST["incluir_trasladados"]);
            $plan = familias_clasificar($conexion, $idRolPadre, $incluirTrasl);

            $stmtCrear = $conexion->prepare("INSERT INTO usuarios (nombres, apellidos, correo, password, estado, id_rol) VALUES ('Familia', ?, ?, ?, 'ACTIVO', ?)");
            $stmtVinculo = $conexion->prepare("INSERT INTO padres_alumnos (id_usuario_padre, id_alumno) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_usuario_padre = id_usuario_padre");

            $credenciales = [];
            $resumen = ["creadas" => 0, "vinculadas" => 0, "omitidas" => 0, "errores" => 0];

            foreach ($plan as $a) {

                if ($a["estado"] === "crear") {

                    $password = familias_password();
                    $codigo = (string) $a["codigo_estudiante"];

                    try {
                        $conexion->beginTransaction();
                        $stmtCrear->execute([
                            mb_substr((string) $a["apellidos"], 0, 100, "UTF-8"),
                            familias_correo($codigo),
                            password_hash($password, PASSWORD_DEFAULT),
                            $idRolPadre,
                        ]);
                        $idNuevo = (int) $conexion->lastInsertId();
                        password_marcar_cambio_obligatorio($conexion, $idNuevo);
                        $stmtVinculo->execute([$idNuevo, (int) $a["id_alumno"]]);
                        $conexion->commit();
                    } catch (\Throwable $e) {
                        if ($conexion->inTransaction()) { $conexion->rollBack(); }
                        $resumen["errores"]++;
                        $omitidas[] = $a + ["motivo" => "No se pudo crear la cuenta (se deshizo, no quedó nada a medias)."];
                        continue;
                    }

                    $credenciales[] = familias_fila_credencial($a, $password, "Cuenta creada");
                    $resumen["creadas"]++;

                } elseif ($a["estado"] === "relinkear") {

                    $stmtVinculo->execute([(int) $a["id_usuario_existente"], (int) $a["id_alumno"]]);
                    $resumen["vinculadas"]++;

                } else {

                    $resumen["omitidas"]++;
                    if ($a["estado"] !== "ya_padre") {
                        $omitidas[] = $a + ["motivo" => FAMILIAS_MOTIVOS[$a["estado"]] ?? $a["estado"]];
                    }

                }

            }

            auditoria_registrar($conexion, $idAdmin, "GENERAR", "CUENTAS_FAMILIARES", 0,
                "Cuentas familiares por código: {$resumen['creadas']} creadas, {$resumen['vinculadas']} vinculadas, {$resumen['omitidas']} omitidas, {$resumen['errores']} con error");

            $tituloResultado = "Resultado: {$resumen['creadas']} creadas · {$resumen['vinculadas']} vinculadas · {$resumen['omitidas']} omitidas · {$resumen['errores']} con error";

        // --------------------------------------------------
        // B) RESTABLECER la contraseña de UNA familia por código
        // --------------------------------------------------
        } elseif ($accion === "reset_codigo") {

            $entrada = preg_replace('/[\s\-\.]/', "", trim((string) ($_POST["codigo"] ?? "")));

            if ($entrada === "" || !ctype_digit($entrada) || strlen($entrada) > CODIGO_LARGO) {
                throw new RuntimeException("Escribe un código de estudiante válido (solo dígitos).");
            }

            $codigo = str_pad($entrada, CODIGO_LARGO, "0", STR_PAD_LEFT);

            $stmt = $conexion->prepare("
                SELECT a.id_alumno, a.nombres, a.apellidos, a.codigo_estudiante,
                       gs.grado, gs.seccion, u.id_usuario
                FROM alumnos a
                INNER JOIN padres_alumnos pa ON pa.id_alumno = a.id_alumno
                INNER JOIN usuarios u ON u.id_usuario = pa.id_usuario_padre
                LEFT JOIN matriculas m ON m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'
                LEFT JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
                WHERE a.codigo_estudiante = ?
                  AND u.correo = ?
                  AND u.eliminado_en IS NULL
                LIMIT 1
            ");
            $stmt->execute([$codigo, familias_correo($codigo)]);
            $fila = $stmt->fetch();

            if (!$fila) {
                throw new RuntimeException("No hay una cuenta familiar generada para ese código. Si el alumno tiene un padre con cuenta propia, cambia su contraseña desde Usuarios.");
            }

            $password = familias_password();
            $conexion->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $fila["id_usuario"]]);
            password_marcar_cambio_obligatorio($conexion, (int) $fila["id_usuario"]);

            auditoria_registrar($conexion, $idAdmin, "RESTABLECER", "CUENTA_FAMILIAR", (int) $fila["id_usuario"],
                "Restableció la contraseña de la cuenta familiar del alumno #" . (int) $fila["id_alumno"]);

            $credenciales = [familias_fila_credencial($fila, $password, "Contraseña restablecida")];
            $resumen = ["creadas" => 0, "vinculadas" => 0, "omitidas" => 0, "errores" => 0];
            $tituloResultado = "Contraseña restablecida";

        // --------------------------------------------------
        // C) REGENERAR contraseñas de cuentas familiares que NUNCA han
        //    ingresado (por si se perdió el CSV entregado).
        // --------------------------------------------------
        } elseif ($accion === "regenerar_sin_ingreso") {

            if (empty($_POST["confirmar"])) {
                throw new RuntimeException("Marca la casilla de confirmación para regenerar las contraseñas.");
            }

            $cuentas = $conexion->prepare("
                SELECT u.id_usuario
                FROM usuarios u
                WHERE u.id_rol = ?
                  AND u.correo LIKE ?
                  AND u.estado = 'ACTIVO'
                  AND u.eliminado_en IS NULL
                  AND u.ultimo_acceso IS NULL
            ");
            $cuentas->execute([$idRolPadre, "%@" . FAMILIA_DOMINIO]);

            $stmtAlumno = $conexion->prepare("
                SELECT a.id_alumno, a.nombres, a.apellidos, a.codigo_estudiante, gs.grado, gs.seccion
                FROM padres_alumnos pa
                INNER JOIN alumnos a ON a.id_alumno = pa.id_alumno
                LEFT JOIN matriculas m ON m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'
                LEFT JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
                WHERE pa.id_usuario_padre = ?
                ORDER BY a.id_alumno
                LIMIT 1
            ");
            $stmtUpd = $conexion->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");

            $credenciales = [];

            foreach ($cuentas->fetchAll() as $c) {

                $stmtAlumno->execute([(int) $c["id_usuario"]]);
                $a = $stmtAlumno->fetch();
                if (!$a) { continue; }

                $password = familias_password();
                $stmtUpd->execute([password_hash($password, PASSWORD_DEFAULT), (int) $c["id_usuario"]]);
                password_marcar_cambio_obligatorio($conexion, (int) $c["id_usuario"]);
                $credenciales[] = familias_fila_credencial($a, $password, "Contraseña regenerada");

            }

            usort($credenciales, fn($x, $y) => [$x["grado"], $x["seccion"], $x["apellidos"]] <=> [$y["grado"], $y["seccion"], $y["apellidos"]]);

            auditoria_registrar($conexion, $idAdmin, "RESTABLECER", "CUENTA_FAMILIAR", 0,
                "Regeneró la contraseña de " . count($credenciales) . " cuentas familiares que nunca habían ingresado");

            $resumen = ["creadas" => 0, "vinculadas" => 0, "omitidas" => 0, "errores" => 0];
            $tituloResultado = count($credenciales) . " contraseñas regeneradas (cuentas que nunca ingresaron)";

        } else {
            throw new RuntimeException("Acción no válida.");
        }

    } catch (\Throwable $e) {
        flash_set($e->getMessage(), "error");
        header("Location: padres_generar_familias.php");
        exit;
    }

}

[$mensaje, $mensajeTipo] = flash_get();

// Vista previa (solo lectura) cuando no se está mostrando un resultado.
$vista = [];
$conteo = [];
$conteoTrasladados = 0;

if ($credenciales === null) {
    $vista = familias_clasificar($conexion, $idRolPadre, false);
    foreach ($vista as $a) {
        $conteo[$a["estado"]] = ($conteo[$a["estado"]] ?? 0) + 1;
    }
    $conteoTrasladados = $conteo["trasladado"] ?? 0;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuentas familiares · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Cuentas familiares (acceso con código de estudiante)</h1>
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

    <?php if ($credenciales === null): ?>

    <section class="panel-form">
        <h3>Generar una cuenta familiar por alumno</h3>
        <p class="placeholder-text">
            Crea, para cada alumno activo que <strong>todavía no tiene un padre vinculado</strong>, una cuenta de familia
            (correo técnico no real + contraseña temporal aleatoria). La familia ingresa en el Intranet con su
            <strong>código de estudiante</strong> y esa contraseña. No se modifica ningún alumno, nota ni boleta,
            y se puede ejecutar varias veces sin duplicar cuentas.
        </p>

        <div class="table-wrap">
            <table class="data-table data-table-wrap">
                <thead><tr><th>Situación (vista previa)</th><th>Alumnos</th></tr></thead>
                <tbody>
                <?php foreach (FAMILIAS_MOTIVOS as $clave => $texto): ?>
                    <tr>
                        <td><?= htmlspecialchars($texto) ?></td>
                        <td><?= (int) ($conteo[$clave] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="POST" onsubmit="return confirm('¿Generar las cuentas familiares indicadas en \u201cCuenta por crear\u201d?');">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="generar">

            <div class="field">
                <label>
                    <input type="checkbox" name="incluir_trasladados" value="1">
                    Incluir también alumnos con matrícula trasladada/retirada
                    (<?= (int) $conteoTrasladados ?> alumnos hoy omitidos por esto)
                </label>
            </div>

            <button type="submit" class="btn-submit">Generar cuentas familiares</button>
        </form>
    </section>

    <section class="panel-form" style="margin-top:24px;">
        <h3>Restablecer la contraseña de una familia</h3>
        <p class="placeholder-text">Si una familia perdió su contraseña, escribe el código del alumno: se genera una nueva y se muestra una sola vez.</p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="reset_codigo">
            <div class="field">
                <label for="codigo">Código de estudiante</label>
                <input type="text" name="codigo" id="codigo" inputmode="numeric" maxlength="20" placeholder="14 dígitos" required>
            </div>
            <button type="submit" class="btn-submit">Restablecer contraseña</button>
        </form>
    </section>

    <section class="panel-form" style="margin-top:24px;">
        <h3>¿Perdiste el archivo de contraseñas?</h3>
        <p class="placeholder-text">
            Genera de nuevo la lista solo para las cuentas familiares que <strong>nunca han ingresado</strong>
            (las contraseñas anteriores dejan de servir). Las familias que ya ingresaron no se tocan.
        </p>
        <form method="POST" onsubmit="return confirm('Se cambiarán las contraseñas de las cuentas que nunca ingresaron. ¿Continuar?');">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="regenerar_sin_ingreso">
            <div class="field">
                <label><input type="checkbox" name="confirmar" value="1"> Entiendo que las contraseñas entregadas antes a esas familias dejarán de funcionar</label>
            </div>
            <button type="submit" class="btn-submit">Regenerar contraseñas</button>
        </form>
    </section>

    <?php else: ?>

    <h2><?= htmlspecialchars($tituloResultado) ?></h2>

    <?php if (count($credenciales) > 0): ?>
        <p class="placeholder-text">Las contraseñas solo se ven en esta pantalla y no se guardan en ningún otro lugar. Descárgalas ahora para entregarlas a las familias (el archivo trae código, alumno, grado, sección y contraseña).</p>
        <p><button type="button" class="btn-submit" id="btn-descargar">Descargar credenciales (CSV)</button></p>

        <div class="table-wrap">
            <table class="data-table data-table-wrap">
                <thead><tr><th>Código</th><th>Alumno</th><th>Grado</th><th>Sección</th><th>Contraseña</th><th>Detalle</th></tr></thead>
                <tbody>
                <?php foreach ($credenciales as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c["codigo"]) ?></td>
                        <td><?= htmlspecialchars($c["apellidos"] . ", " . $c["nombres"]) ?></td>
                        <td><?= htmlspecialchars($c["grado"]) ?></td>
                        <td><?= htmlspecialchars($c["seccion"]) ?></td>
                        <td><code><?= htmlspecialchars($c["password"]) ?></code></td>
                        <td><?= htmlspecialchars($c["detalle"]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="placeholder-text">No se creó ninguna contraseña nueva.</p>
    <?php endif; ?>

    <?php if (count($omitidas) > 0): ?>
        <h3 style="margin-top:24px;">Alumnos no procesados (revisar)</h3>
        <div class="table-wrap">
            <table class="data-table data-table-wrap">
                <thead><tr><th>Código</th><th>Alumno</th><th>Motivo</th></tr></thead>
                <tbody>
                <?php foreach ($omitidas as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($o["codigo_estudiante"] ?? "")) ?></td>
                        <td><?= htmlspecialchars($o["apellidos"] . ", " . $o["nombres"]) ?></td>
                        <td><?= htmlspecialchars($o["motivo"]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p><a href="padres_generar_familias.php">Volver</a></p>

    <?php if (count($credenciales) > 0): ?>
    <script>
    (function () {
        var filas = <?= json_encode($credenciales, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        function celda(v) {
            v = String(v == null ? "" : v);
            // Evita que Excel interprete una celda como fórmula.
            if (/^[=+\-@]/.test(v)) { v = "'" + v; }
            return '"' + v.replace(/"/g, '""') + '"';
        }
        document.getElementById("btn-descargar").addEventListener("click", function () {
            var lineas = ['codigo,apellidos,nombres,grado,seccion,contrasena'];
            filas.forEach(function (f) {
                // El código va como texto ="..." para que Excel NO le quite los ceros iniciales.
                lineas.push(['="' + f.codigo + '"', celda(f.apellidos), celda(f.nombres), celda(f.grado), celda(f.seccion), celda(f.password)].join(","));
            });
            var blob = new Blob(["\ufeff" + lineas.join("\r\n")], { type: "text/csv;charset=utf-8" });
            var a = document.createElement("a");
            a.href = URL.createObjectURL(blob);
            a.download = "credenciales_familias.csv";
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
