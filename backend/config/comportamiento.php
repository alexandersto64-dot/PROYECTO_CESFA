<?php

// ==========================================
// Registro de comportamiento (AUTOMATIZACIÓN).
//
// La tabla `comportamiento` y la vista del padre (padre/comportamiento.php,
// padre_comportamiento() en padres.php) ya existían, pero en TODO el
// proyecto no había ningún formulario que insertara una fila ahí —
// Profesor, Auxiliar y Subdirección podían "registrar comportamiento"
// según la decisión original, pero no existía la pantalla. Esta
// función es el único punto de inserción; subdirector/comportamiento.php
// es quien la usa por ahora.
// ==========================================

require_once __DIR__ . "/notificaciones.php";
require_once __DIR__ . "/correo.php";

function comportamiento_registrar(PDO $conexion, int $idAlumno, int $idPeriodo, string $tipo, string $descripcion, int $idUsuarioRegistro): int {

    if (!in_array($tipo, ["POSITIVO", "NEGATIVO", "NEUTRO"], true)) {
        throw new RuntimeException("Tipo de comportamiento inválido.");
    }

    if (trim($descripcion) === "") {
        throw new RuntimeException("La descripción es obligatoria.");
    }

    $stmt = $conexion->prepare("
        INSERT INTO comportamiento (id_alumno, id_periodo, tipo, descripcion, id_usuario_registro)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$idAlumno, $idPeriodo, $tipo, trim($descripcion), $idUsuarioRegistro]);

    $idComportamiento = (int) $conexion->lastInsertId();

    $stmtAlumno = $conexion->prepare("SELECT nombres, apellidos FROM alumnos WHERE id_alumno = ?");
    $stmtAlumno->execute([$idAlumno]);
    $alumno = $stmtAlumno->fetch();
    $nombreAlumno = $alumno ? trim($alumno["nombres"] . " " . $alumno["apellidos"]) : "tu hijo(a)";

    auditoria_registrar($conexion, $idUsuarioRegistro, "REGISTRAR", "COMPORTAMIENTO", $idComportamiento,
        "$tipo — $nombreAlumno: " . mb_substr($descripcion, 0, 150));

    // Avisa a los padres vinculados a este alumno — antes solo se
    // enteraban si entraban por su cuenta a padre/comportamiento.php.
    $etiquetas = ["POSITIVO" => "positiva", "NEGATIVO" => "negativa", "NEUTRO" => "neutra"];
    $url = "../padre/comportamiento.php?id_alumno={$idAlumno}";
    $mensaje = "Se registró una incidencia " . $etiquetas[$tipo] . " de comportamiento para " . $nombreAlumno . ".";

    $stmtPadres = $conexion->prepare("
        SELECT u.id_usuario, u.nombres, u.correo
        FROM padres_alumnos pa
        INNER JOIN usuarios u ON u.id_usuario = pa.id_usuario_padre
        WHERE pa.id_alumno = ? AND u.estado = 'ACTIVO'
    ");
    $stmtPadres->execute([$idAlumno]);

    foreach ($stmtPadres->fetchAll() as $padre) {

        notificar_crear($conexion, (int) $padre["id_usuario"], "COMPORTAMIENTO_REGISTRADO", $mensaje, $url);

        correo_enviar(
            $padre["correo"],
            $padre["nombres"],
            "Nueva incidencia de comportamiento - Intranet I.E.P. 88044",
            correo_plantilla("Comportamiento registrado", "
                <p>Hola " . htmlspecialchars($padre["nombres"]) . ",</p>
                <p>" . htmlspecialchars($mensaje) . "</p>
                <p>Ingresa al Intranet para ver el detalle.</p>
            ")
        );

    }

    return $idComportamiento;

}
