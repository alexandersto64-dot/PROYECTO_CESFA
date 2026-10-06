<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";


// ==================================================
// 1. COMPROBAR SESIÓN
// ==================================================

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}


// ==================================================
// 2. COMPROBAR QUE SEA PROFESOR
// ==================================================

if (
    !in_array(
        $_SESSION["rol"],
        [
            "PROFESOR_PRIMARIA",
            "PROFESOR_SECUNDARIO"
        ],
        true
    )
) {
    die("Acceso no autorizado.");
}


// ==================================================
// 3. SOLO PRIMARIA
// ==================================================

if ($_SESSION["rol"] !== "PROFESOR_PRIMARIA") {
    die("Este grado pertenece al nivel Primaria.");
}


// ==================================================
// 4. DATOS DEL PROFESOR
// ==================================================

require_once "../backend/config/database.php";
require_once "config_cursos.php";


// ==================================================
// 5. OBTENER PROFESOR
// ==================================================

$sql = "
    SELECT
        p.id_profesor,
        u.nombres,
        u.apellidos,
        u.correo
    FROM profesores p

    INNER JOIN usuarios u
        ON p.id_usuario = u.id_usuario

    WHERE p.id_usuario = ?
";

$stmt = $conexion->prepare($sql);

$stmt->execute([
    $_SESSION["id_usuario"]
]);

$profesor = $stmt->fetch();


// ==================================================
// 6. COMPROBAR PROFESOR
// ==================================================

if (!$profesor) {
    die("No se encontró el perfil del profesor.");
}

?>


<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        1.º Primero - Panel del Profesor
    </title>


    <link
        rel="stylesheet"
        href="../css/styles.css?v=202609211855"
    >

    <link
        rel="stylesheet"
        href="../css/dashboard.css?v=202609300252"
    >

</head>


<body>


<div class="app-shell">


    <!-- ==================================================
         SIDEBAR
    ================================================== -->

    <?php

    $currentFile = basename(__FILE__);

    include __DIR__ .
        "/../backend/partials/sidebar.php";

    ?>


    <!-- ==================================================
         CONTENIDO
    ================================================== -->

    <div class="app-content">


        <!-- ==================================================
             ENCABEZADO
        ================================================== -->

        <header>

            <h1>
                <?= icon("book", "icon") ?> 1.º Primero
            </h1>


            <p>

                Bienvenido,

                <?= htmlspecialchars(
                    $profesor["nombres"]
                ) ?>

                <?= htmlspecialchars(
                    $profesor["apellidos"]
                ) ?>

            </p>


            <p>

                <strong>
                    Nivel educativo:
                </strong>

                Primaria

            </p>


            <p>

                <a href="dashboard.php">
                    ← Volver al Dashboard
                </a>

            </p>

        </header>


        <!-- ==================================================
             CONTENIDO PRINCIPAL
        ================================================== -->

        <main>


            <section>


                <h2>
                    Organización pedagógica
                </h2>


                <p class="placeholder-text">

                    Seleccione una opción para gestionar
                    los materiales pedagógicos de
                    <strong>1.º Primero</strong>.

                </p>


                <!-- ==================================================
                     CARDS
                ================================================== -->

                <div class="cards">


                    <!-- ==========================================
                         PCA
                    =========================================== -->

                    <div class="card">


                        <h3>
                            <?= icon("book", "icon") ?> PCA
                        </h3>


                        <p>

                            Planificación Curricular Anual
                            correspondiente a
                            <strong>1.º Primero</strong>.

                        </p>


                        <a
                            href="pca.php?grado=1"
                            class="btn-submit"
                        >
                            Abrir PCA
                        </a>

                    </div>


                    <!-- ==========================================
                         UNIDADES
                    =========================================== -->

                    <div class="card">


                        <h3>
                            <?= icon("layers", "icon") ?> Unidades
                        </h3>


                        <p>

                            Gestiona las unidades de
                            aprendizaje de
                            <strong>1.º Primero</strong>.

                        </p>


                        <a
                            href="unidades.php?grado=1"
                            class="btn-submit"
                        >
                            Abrir Unidades
                        </a>

                    </div>


                    <!-- ==========================================
                         SESIONES
                    =========================================== -->

                    <div class="card">


                        <h3>
                            <?= icon("file-text", "icon") ?> Sesiones
                        </h3>


                        <p>

                            Gestiona las sesiones de
                            aprendizaje de
                            <strong>1.º Primero</strong>.

                        </p>


                        <a
                            href="sesiones.php?grado=1"
                            class="btn-submit"
                        >
                            Abrir Sesiones
                        </a>

                    </div>


                    <!-- ==========================================
                         DOCUMENTOS
                    =========================================== -->

                    <div class="card">


                        <h3>
                            <?= icon("folder", "icon") ?> Documentos institucionales
                        </h3>


                        <p>

                            Consulta los documentos
                            institucionales relacionados
                            con <strong>1.º Primero</strong>.

                        </p>


                        <a
                            href="documentos.php?grado=1"
                            class="btn-submit"
                        >
                            Abrir Documentos
                        </a>

                    </div>


                </div>


            </section>
        </main>


    </div>


</div>


<script src="../js/panel.js?v=202609211901"></script>


</body>

</html>