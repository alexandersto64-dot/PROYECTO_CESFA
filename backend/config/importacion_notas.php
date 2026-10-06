<?php

// ==========================================================
// Importación de notas desde PDF (Etapa 2).
//
// FORMATO REAL (confirmado con Primaria.docx, Secundaria1ro.docx,
// Secundaria-2-5.docx — "Informe de Progreso del Aprendizaje",
// MINEDU/CNEB): cada PDF es de UN alumno, con sus 4 bimestres del
// año en la misma tabla — una fila por Área Curricular con un
// calificativo por bimestre, más una fila por cada Competencia
// dentro de esa área. Por eso el bimestre vive en
// boletas_notas_extraidas (una fila por área+bimestre), no en
// boletas_importadas.
//
// Flujo: Admin sube -> boleta_procesar() extrae y matchea (nunca
// escribe en `notas`) -> Admin corrige matching técnico -> Profesor
// confirma/corrige valores de sus cursos -> boleta pasa a REVISADA
// -> Subdirector aprueba (con o sin fecha programada) -> Subdirector
// publica -> ahí recién se escribe en `notas`/`notas_competencias`.
//
// Todas las funciones reciben PDO ya conectado (via database.php) y
// nunca confían en un id que llegue de POST/GET sin revalidarlo
// contra la tabla de pertenencia correspondiente.
// ==========================================================

require_once __DIR__ . "/validar_contenido_archivo.php";
require_once __DIR__ . "/pdf_extraccion.php";
require_once __DIR__ . "/pdf_posiciones.php";
require_once __DIR__ . "/competencias_catalogo.php";
require_once __DIR__ . "/notificaciones.php";
require_once __DIR__ . "/correo.php";

const BOLETAS_DIRECTORIO = __DIR__ . "/../uploads/boletas";
const BOLETAS_TAMANO_MAXIMO_BYTES = 10 * 1024 * 1024; // 10 MB

// ----------------------------------------------------------
// SUBIDA DEL ARCHIVO
// ----------------------------------------------------------

/**
 * Valida y mueve el PDF subido. Verifica extensión Y el MIME real
 * del archivo (no solo la extensión) porque este archivo, a
 * diferencia de un envío de trabajo, se va a PARSEAR, no solo
 * almacenar.
 */
function boleta_guardar_pdf(array $archivo): array {

    if (!isset($archivo["error"]) || is_array($archivo["error"])) {
        throw new RuntimeException("Parámetros de archivo inválidos.");
    }

    if ($archivo["error"] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("Debe seleccionar un archivo PDF.");
    }

    if ($archivo["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Error al subir el archivo (código " . $archivo["error"] . ").");
    }

    if ($archivo["size"] <= 0 || $archivo["size"] > BOLETAS_TAMANO_MAXIMO_BYTES) {
        throw new RuntimeException("El archivo supera el tamaño máximo permitido (10 MB).");
    }

    if (!is_uploaded_file($archivo["tmp_name"])) {
        throw new RuntimeException("Archivo inválido.");
    }

    $extension = strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION));

    if ($extension !== "pdf") {
        throw new RuntimeException("Solo se aceptan archivos PDF.");
    }

    $mime = mime_content_type($archivo["tmp_name"]);

    if ($mime !== "application/pdf") {
        throw new RuntimeException("El archivo no es un PDF válido (tipo detectado: $mime).");
    }

    // Refuerzo: firma %PDF- real, no solo el tipo MIME.
    archivo_validar_contenido($archivo["tmp_name"], "pdf");

    if (!is_dir(BOLETAS_DIRECTORIO)) {
        mkdir(BOLETAS_DIRECTORIO, 0755, true);
    }

    $hash = hash_file("sha256", $archivo["tmp_name"]);
    $nombreFisico = bin2hex(random_bytes(16)) . ".pdf";
    $rutaAbsoluta = BOLETAS_DIRECTORIO . "/" . $nombreFisico;

    if (!move_uploaded_file($archivo["tmp_name"], $rutaAbsoluta)) {
        throw new RuntimeException("No se pudo guardar el archivo en el servidor.");
    }

    return [
        "nombre_original" => $archivo["name"],
        "ruta_relativa" => "backend/uploads/boletas/" . $nombreFisico,
        "ruta_absoluta" => $rutaAbsoluta,
        "hash" => $hash,
    ];

}

/** Devuelve la boleta existente con ese hash, o null si no hay ninguna. */
function boleta_buscar_por_hash(PDO $conexion, string $hash): ?array {
    $stmt = $conexion->prepare("SELECT * FROM boletas_importadas WHERE hash_archivo = ? LIMIT 1");
    $stmt->execute([$hash]);
    return $stmt->fetch() ?: null;
}

/**
 * Crea la fila de boletas_importadas en estado PENDIENTE. $idAlumno
 * es solo una referencia de quién lo subió como "creo que es este
 * alumno" — el matching real y definitivo es por DNI leído del PDF
 * en boleta_procesar(), no depende de este valor.
 */
function boleta_crear(PDO $conexion, array $datosArchivo, int $idPeriodo, ?int $idAlumno, int $idUsuarioSubio): int {

    $stmt = $conexion->prepare("
        INSERT INTO boletas_importadas
            (nombre_archivo_original, ruta_archivo, hash_archivo, id_periodo, id_alumno, estado, id_usuario_subio)
        VALUES (?, ?, ?, ?, ?, 'PENDIENTE', ?)
    ");
    $stmt->execute([
        $datosArchivo["nombre_original"],
        $datosArchivo["ruta_relativa"],
        $datosArchivo["hash"],
        $idPeriodo,
        $idAlumno,
        $idUsuarioSubio,
    ]);

    return (int) $conexion->lastInsertId();

}

// ----------------------------------------------------------
// PROCESAMIENTO (extracción + parseo + matching)
// ----------------------------------------------------------

/**
 * Procesa una boleta recién subida: extrae el texto, lo parsea en
 * un alumno con sus áreas/competencias por bimestre, resuelve
 * alumno/curso/competencia contra la BD, valida los valores y
 * guarda todo en boletas_notas_extraidas / boletas_competencias_extraidas.
 * Nunca toca `notas`.
 */
function boleta_procesar(PDO $conexion, int $idBoleta, ?int $soloBimestre = null): void {

    $boleta = boleta_obtener($conexion, $idBoleta);
    if (!$boleta) {
        throw new RuntimeException("Boleta no encontrada.");
    }

    $conexion->prepare("UPDATE boletas_importadas SET estado = 'PROCESANDO' WHERE id_boleta = ?")->execute([$idBoleta]);

    try {

        $rutaAbsoluta = __DIR__ . "/../../" . $boleta["ruta_archivo"];
        $texto = boleta_extraer_texto($rutaAbsoluta);
        $datosAlumno = boleta_parsear_texto($texto);

        // Formato SIAGIE 2026 (sin "CALIFICATIVO DE AREA", celdas vacías sin rastro en el texto):
        // se leen las áreas/competencias/notas por posición en la página. Si el PDF no tiene ese
        // formato, se conserva el resultado del parser por texto (formato anterior).
        $areasPorPosicion = boleta_parsear_posiciones($rutaAbsoluta, $datosAlumno["nivel_texto"] ?? null);
        if (count($areasPorPosicion) > 0) {
            $datosAlumno["areas"] = $areasPorPosicion;
        }

        if (count($datosAlumno["areas"]) === 0) {
            throw new RuntimeException("No se reconoció ninguna Área Curricular en el PDF (¿formato distinto al esperado?).");
        }

        [$idAlumno, $advertenciaAlumno] = notas_matching_alumno($conexion, $datosAlumno["dni_candidatos"], $datosAlumno["alumno_texto"]);
        $datosAlumno["dni"] = boleta_dni_de_alumno($conexion, $idAlumno); // el DNI real ya verificado contra la BD, no un valor crudo del PDF
        $nivel = $idAlumno
            ? boleta_nivel_por_alumno($conexion, $idAlumno)
            : boleta_normalizar_nivel($datosAlumno["nivel_texto"] ?? null);

        $conexion->beginTransaction();

        foreach ($datosAlumno["areas"] as $area) {
            boleta_guardar_area_extraida($conexion, $idBoleta, $idAlumno, $advertenciaAlumno, $nivel, $datosAlumno, $area, $soloBimestre);
        }

        $stmtCuenta = $conexion->prepare("SELECT COUNT(*) FROM boletas_notas_extraidas WHERE id_boleta = ?");
        $stmtCuenta->execute([$idBoleta]);
        if ((int) $stmtCuenta->fetchColumn() === 0) {
            throw new RuntimeException($soloBimestre
                ? "El PDF no trae notas del bimestre $soloBimestre (¿es la boleta correcta o ese bimestre aún está vacío?)."
                : "El PDF no trae ninguna nota todavía.");
        }

        $conexion->prepare("UPDATE boletas_importadas SET estado = 'EN_REVISION' WHERE id_boleta = ?")->execute([$idBoleta]);

        $conexion->commit();

    } catch (\Throwable $e) {

        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        $conexion->prepare("UPDATE boletas_importadas SET estado = 'ERROR', mensaje_error = ? WHERE id_boleta = ?")
            ->execute([$e->getMessage(), $idBoleta]);

    }

}

/**
 * Parsea el texto crudo del "Informe de Progreso del Aprendizaje"
 * (formato MINEDU/CNEB, confirmado contra 3 boletas reales del
 * colegio) en la estructura:
 *
 * [
 *   "alumno_texto" => "PEREZ GOMEZ, JUAN",
 *   "dni"          => "12345678",           // o null si no se leyó
 *   "nivel_texto"  => "Secundaria",          // texto crudo del campo "Nivel:"
 *   "areas" => [
 *     [
 *       "texto_curso" => "Ciencias Sociales",   // nombre del ÁREA tal como sale impreso
 *       "bimestres"   => ["1" => "14", "2" => "15", "3" => null, "4" => null],
 *       "competencias" => [
 *         ["texto" => "Construye interpretaciones históricas.", "bimestres" => ["1"=>"15","2"=>"14","3"=>null,"4"=>null]],
 *         ...
 *       ],
 *     ],
 *     ...
 *   ],
 * ]
 *
 * ESTRATEGIA (importante para calibrar contra un PDF real, que hoy
 * no tenemos — solo los .docx de origen): pdfparser (smalot) entrega
 * texto plano; una tabla de Word exportada a PDF normalmente se lee
 * fila por fila, celda por celda, de izquierda a derecha, aunque sin
 * ningún separador de columna. Por eso el parseo NO intenta leer
 * posiciones x/y: usa como "anclas" los nombres exactos de las 12
 * Áreas Curriculares y sus Competencias oficiales (ver
 * competencias_catalogo.php, sacado directamente de las boletas
 * reales) y, después de cada ancla, toma como valores los primeros
 * 4 tokens que parezcan un calificativo válido (AD/A/B/C o 0-20),
 * dejando en null los bimestres que el PDF traiga vacíos (fila sin
 * calificar todavía, no es un error).
 *
 * Si al probar contra un PDF real el layout intercala texto entre
 * los calificativos (headers repetidos, pies de página, etc.) y esto
 * falla, el punto a ajustar es boleta_extraer_calificativos_siguientes():
 * ahí se decide cuántos tokens saltar y qué patrones aceptar.
 */
function boleta_parsear_texto(string $texto): array {

    // El texto "DNI:" y su valor a veces NO quedan adyacentes en el texto
    // extraído del PDF (confirmado con un PDF real: esta plantilla se
    // acomoda en 2 columnas por página y algunos exportadores de PDF
    // reordenan el contenido de la segunda columna). Por eso no se confía
    // en "el número que sigue a la palabra DNI": se juntan TODOS los
    // números de 8 dígitos que aparezcan en el documento (DNI real,
    // código del estudiante, UGEL, etc. — cualquiera puede colisionar en
    // formato) y es notas_matching_alumno() quien decide cuál es el DNI
    // real, probando cada candidato contra la tabla `alumnos` — el que
    // sí exista en la BD gana, sin importar en qué posición del texto
    // haya caído.
    preg_match_all('/\b[0-9]{8}\b/u', $texto, $mDni);
    $dniCandidatos = array_values(array_unique($mDni[0] ?? []));

    $nivelTexto = null;
    if (preg_match('/Nivel:?\s*(Primaria|Secundaria)/iu', $texto, $m)) {
        $nivelTexto = $m[1];
    }

    $alumnoTexto = "";
    if (preg_match('/Apellidos y nombres del\s+estudiante:?\s*([A-ZÁÉÍÓÚÑ,\.\s]{5,100}?)(?:Código del estudiante|DNI|$)/u', $texto, $m)) {
        $alumnoTexto = trim($m[1]);
    }

    $nivel = boleta_normalizar_nivel($nivelTexto) ?? "SECUNDARIA";
    $catalogo = COMPETENCIAS_POR_CURSO[$nivel] ?? [];
    $aliasArea = AREA_A_CURSO[$nivel] ?? [];

    $areas = [];

    foreach ($aliasArea as $areaNormalizada => $cursoNombre) {

        $competenciasCurso = $catalogo[$cursoNombre] ?? [];
        if (count($competenciasCurso) === 0) {
            continue;
        }

        // Busca el nombre del área en el texto (con tildes o sin ellas,
        // el PDF real puede traerlo de cualquiera de las dos formas).
        $posArea = boleta_buscar_texto_normalizado($texto, $areaNormalizada);
        if ($posArea === null) {
            continue; // esta área no aparece en el PDF (ej. colegio sin ese curso ese año)
        }

        $competenciasExtraidas = [];
        $posBusqueda = $posArea;

        foreach ($competenciasCurso as $textoCompetencia) {
            $posComp = boleta_buscar_texto_normalizado($texto, $textoCompetencia, $posBusqueda);
            if ($posComp === null) {
                continue; // esa competencia puntual no se encontró — se deja fuera, no rompe las demás
            }
            $finComp = $posComp + mb_strlen($textoCompetencia);
            $bimestresComp = boleta_extraer_calificativos_siguientes($texto, $finComp, 4);
            $competenciasExtraidas[] = ["texto" => $textoCompetencia, "bimestres" => $bimestresComp];
            $posBusqueda = $finComp;
        }

        // "CALIFICATIVO DE AREA" es la nota final del área para cada
        // bimestre — se busca después de la última competencia de esa
        // área, para no confundirla con calificativos de competencias.
        $posCalifArea = boleta_buscar_texto_normalizado($texto, "CALIFICATIVO DE AREA", $posBusqueda);
        $bimestresArea = $posCalifArea !== null
            ? boleta_extraer_calificativos_siguientes($texto, $posCalifArea + strlen("CALIFICATIVO DE AREA"), 4)
            : ["1" => null, "2" => null, "3" => null, "4" => null];

        $areas[] = [
            "texto_curso" => $cursoNombre,
            "bimestres" => $bimestresArea,
            "competencias" => $competenciasExtraidas,
        ];

    }

    return [
        "alumno_texto" => $alumnoTexto,
        "dni_candidatos" => $dniCandidatos,
        "nivel_texto" => $nivelTexto,
        "areas" => $areas,
    ];

}

/** Busca $textoBuscado dentro de $texto ignorando may/min y tildes, desde $desde. Devuelve la posición o null. */
function boleta_buscar_texto_normalizado(string $texto, string $textoBuscado, int $desde = 0): ?int {

    $normalizadoTexto = notas_normalizar_texto(mb_substr($texto, $desde));
    $normalizadoBuscado = notas_normalizar_texto($textoBuscado);

    $pos = mb_strpos($normalizadoTexto, $normalizadoBuscado);

    return $pos === false ? null : $desde + $pos;

}

/**
 * A partir de la posición $desde, toma los siguientes $cantidad
 * "tokens" que parezcan un calificativo válido (AD/A/B/C o un
 * número 0-20), devueltos como ["1"=>valor, "2"=>valor, ...]. Un
 * bimestre sin calificativo todavía (celda vacía en el PDF) queda
 * en null en vez de arrastrar el valor de otro bimestre.
 */
function boleta_extraer_calificativos_siguientes(string $texto, int $desde, int $cantidad): array {

    $fragmento = mb_substr($texto, $desde, 400); // ventana corta: no cruza a la siguiente competencia/área
    // "AD" a veces se parte en dos líneas dentro de la celda (columna
    // angosta) — se acepta un salto de línea/espacio entre la A y la D
    // y se normaliza a "AD" después, para no leerlo como una "A" suelta
    // (que sería una nota distinta: "Logro esperado" en vez de "Logro
    // destacado").
    preg_match_all('/\bA\s*D\b|\b[ABC]\b|\b(?:[0-9]|1[0-9]|20)\b/u', $fragmento, $matches);
    $crudos = $matches[0] ?? [];
    $valores = array_map(fn($v) => preg_replace('/\s+/', '', $v), $crudos);
    $resultado = [];

    for ($bimestre = 1; $bimestre <= $cantidad; $bimestre++) {
        $resultado[(string) $bimestre] = $valores[$bimestre - 1] ?? null;
    }

    return $resultado;

}

function boleta_normalizar_nivel(?string $nivelTexto): ?string {
    if (!$nivelTexto) {
        return null;
    }
    $n = notas_normalizar_texto($nivelTexto);
    if (str_starts_with($n, "PRIMARIA")) return "PRIMARIA";
    if (str_starts_with($n, "SECUNDARIA")) return "SECUNDARIA";
    return null;
}

/** Guarda un área (con sus competencias) como filas por bimestre — una fila por bimestre que sí tiene valor. */
/**
 * Guarda un área (con sus competencias) como filas por bimestre.
 *
 * IMPORTANTE: solo Secundaria 2do-5to trae una fila "CALIFICATIVO DE
 * AREA" con el total del área por bimestre — Primaria y Secundaria
 * 1ro NO la tienen (confirmado contra los 3 formatos reales): ahí el
 * área solo tiene un total ANUAL (columnas que esta etapa no importa
 * todavía), nunca uno por bimestre. Por eso esta función NO se salta
 * un bimestre solo porque el área no tenga total — se salta solo si
 * NI el área NI ninguna de sus competencias tiene valor ese bimestre.
 * Cuando el área no trae total (Primaria/1ro), la fila en `notas`
 * igual se crea (con nota_vigesimal_final/nota_literal_final en
 * NULL) para que sus competencias tengan dónde colgar vía id_nota —
 * el padre verá esa nota final en blanco pero sí el desglose por
 * competencia, que es exactamente lo que trae el documento real.
 */
function boleta_guardar_area_extraida(PDO $conexion, int $idBoleta, ?int $idAlumno, ?string $advertenciaAlumno, ?string $nivel, array $datosAlumno, array $area, ?int $soloBimestre = null): void {

    [$idCurso, $advertenciaCurso] = notas_matching_curso($conexion, $area["texto_curso"], $nivel);

    // Subida por bimestre: la boleta trae los 4 bimestres en la misma tabla
    // (con los ya cerrados llenos), pero si el Admin eligió UN bimestre solo
    // se importa ese; los anteriores no se vuelven a procesar.
    $bimestresAProcesar = ($soloBimestre !== null && $soloBimestre >= 1 && $soloBimestre <= 4) ? [$soloBimestre] : [1, 2, 3, 4];

    foreach ($bimestresAProcesar as $bimestre) {

        $valorCrudo = $area["bimestres"][(string) $bimestre] ?? null;

        $competenciasBimestre = [];
        foreach ($area["competencias"] as $comp) {
            $valorComp = $comp["bimestres"][(string) $bimestre] ?? null;
            if ($valorComp !== null) {
                $competenciasBimestre[] = ["comp" => $comp, "valor" => $valorComp];
            }
        }

        if ($valorCrudo === null && count($competenciasBimestre) === 0) {
            continue; // este bimestre no tiene NADA todavía para esta área — no es una fila, no es un error
        }

        [$vigesimal, $literal, $errorValor] = $valorCrudo !== null
            ? notas_validar_valor($valorCrudo, $nivel)
            : [null, null, null]; // sin total de área en el documento (Primaria/1ro) — no es un error, es esperado

        $advertencias = array_filter([$advertenciaAlumno, $advertenciaCurso, $errorValor]);
        $estadoFila = $errorValor || !$idAlumno || !$idCurso ? "ERROR" : (count($advertencias) > 0 ? "ADVERTENCIA" : "OK");

        $stmt = $conexion->prepare("
            INSERT INTO boletas_notas_extraidas
                (id_boleta, bimestre, texto_alumno_extraido, dni_extraido, id_alumno_resuelto,
                 texto_curso_extraido, id_curso_resuelto, valor_final_extraido,
                 nota_vigesimal_final, nota_literal_final, estado_fila, mensaje_advertencia)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $idBoleta, $bimestre,
            $datosAlumno["alumno_texto"], $datosAlumno["dni"], $idAlumno,
            $area["texto_curso"], $idCurso, $valorCrudo,
            $vigesimal, $literal, $estadoFila,
            implode(" / ", $advertencias) ?: null,
        ]);

        $idExtraccion = (int) $conexion->lastInsertId();

        foreach ($competenciasBimestre as $item) {

            $comp = $item["comp"];
            $valorCompCrudo = $item["valor"];

            [$idCompetencia, $advertenciaComp] = $idCurso
                ? notas_matching_competencia($conexion, $idCurso, $comp["texto"])
                : [null, "Curso no resuelto: no se puede matchear la competencia."];

            [$vigesimalComp, $literalComp, $errorValorComp] = notas_validar_valor($valorCompCrudo, $nivel);

            $advertenciasComp = array_filter([$advertenciaComp, $errorValorComp]);
            $estadoComp = $errorValorComp || !$idCompetencia ? "ERROR" : (count($advertenciasComp) > 0 ? "ADVERTENCIA" : "OK");

            $stmtComp = $conexion->prepare("
                INSERT INTO boletas_competencias_extraidas
                    (id_extraccion, texto_competencia_extraido, id_competencia_resuelto,
                     valor_extraido, nota_vigesimal, nota_literal, estado_fila, mensaje_advertencia)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtComp->execute([
                $idExtraccion, $comp["texto"], $idCompetencia,
                $valorCompCrudo, $vigesimalComp, $literalComp, $estadoComp,
                implode(" / ", $advertenciasComp) ?: null,
            ]);

        }

    }

}

// ----------------------------------------------------------
// MATCHING Y VALIDACIÓN
// ----------------------------------------------------------

/** Mayúsculas, sin tildes ni espacios repetidos — para comparar texto extraído del PDF contra la BD. */
function notas_normalizar_texto(string $s): string {
    $s = mb_strtoupper(trim($s));
    $s = strtr($s, ["Á"=>"A","É"=>"E","Í"=>"I","Ó"=>"O","Ú"=>"U","Ñ"=>"N"]);
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Busca al alumno probando cada candidato a DNI encontrado en el PDF
 * contra la tabla `alumnos` (el que SÍ exista gana — no importa en qué
 * posición del texto extraído haya caído, ver nota en boleta_parsear_texto()).
 * Si ningún candidato coincide, cae a buscar por nombre completo (match
 * aproximado, SIEMPRE marcado con advertencia aunque encuentre resultado,
 * porque el nombre no es único).
 *
 * @param string[] $dniCandidatos
 * @return array{0: ?int, 1: ?string} [id_alumno, advertencia]
 */
function notas_matching_alumno(PDO $conexion, array $dniCandidatos, string $nombreTexto): array {

    $encontrados = []; // dni => id_alumno

    foreach ($dniCandidatos as $dni) {
        $stmt = $conexion->prepare("SELECT id_alumno FROM alumnos WHERE dni = ? LIMIT 1");
        $stmt->execute([$dni]);
        $fila = $stmt->fetch();
        if ($fila) {
            $encontrados[$dni] = (int) $fila["id_alumno"];
        }
    }

    if (count($encontrados) === 1) {
        return [reset($encontrados), null];
    }

    if (count($encontrados) > 1) {
        return [null, "Varios números de 8 dígitos del PDF (" . implode(", ", array_keys($encontrados)) . ") coinciden con alumnos distintos — corrige manualmente cuál es el DNI real."];
    }

    if ($nombreTexto === "") {
        return [null, "Ningún número de 8 dígitos del PDF corresponde a un alumno registrado, y no se pudo leer el nombre."];
    }

    $normalizado = notas_normalizar_texto($nombreTexto);

    $stmt = $conexion->prepare("
        SELECT id_alumno, nombres, apellidos
        FROM alumnos
        WHERE UPPER(CONCAT(apellidos, ' ', nombres)) LIKE CONCAT('%', ?, '%')
           OR UPPER(CONCAT(nombres, ' ', apellidos)) LIKE CONCAT('%', ?, '%')
    ");
    $stmt->execute([$normalizado, $normalizado]);
    $candidatos = $stmt->fetchAll();

    if (count($candidatos) === 1) {
        return [(int) $candidatos[0]["id_alumno"], "Alumno resuelto por nombre (ningún DNI del PDF coincidió con la BD) — verificar."];
    }

    if (count($candidatos) > 1) {
        return [null, "Nombre ambiguo: " . count($candidatos) . " alumnos coinciden. Requiere DNI o corrección manual."];
    }

    return [null, "No se encontró ningún alumno con los DNI del PDF (" . implode(", ", $dniCandidatos) . ") ni con el nombre \"$nombreTexto\"."];

}

/**
 * Busca el curso por el nombre del ÁREA CURRICULAR tal como sale en
 * el PDF, traduciéndolo primero vía AREA_A_CURSO (ver
 * competencias_catalogo.php) y, si no está en el catálogo, cayendo a
 * una búsqueda directa por nombre normalizado contra `cursos`.
 *
 * @return array{0: ?int, 1: ?string} [id_curso, advertencia]
 */
function notas_matching_curso(PDO $conexion, string $textoArea, ?string $nivel): array {

    $normalizado = notas_normalizar_texto($textoArea);
    $nombreCurso = (AREA_A_CURSO[$nivel] ?? [])[$normalizado] ?? $textoArea;

    $sql = "SELECT id_curso FROM cursos WHERE UPPER(nombre) = ?";
    $params = [notas_normalizar_texto($nombreCurso)];

    if ($nivel) {
        $sql .= " AND nivel = ?";
        $params[] = $nivel;
    }

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $fila = $stmt->fetch();

    if ($fila) {
        return [(int) $fila["id_curso"], null];
    }

    return [null, "No se encontró el curso para el área \"$textoArea\"" . ($nivel ? " en $nivel" : "") . "."];

}

/**
 * Busca la competencia dentro del catálogo de un curso específico
 * (sembrado en `competencias` desde seed_competencias.sql, con los
 * nombres oficiales exactos de la boleta real).
 *
 * @return array{0: ?int, 1: ?string} [id_competencia, advertencia]
 */
function notas_matching_competencia(PDO $conexion, int $idCurso, string $textoCompetencia): array {

    $normalizado = notas_normalizar_texto($textoCompetencia);

    $stmt = $conexion->prepare("SELECT id_competencia FROM competencias WHERE id_curso = ? AND UPPER(nombre) = ? AND activo = 1");
    $stmt->execute([$idCurso, $normalizado]);
    $fila = $stmt->fetch();

    if ($fila) {
        return [(int) $fila["id_competencia"], null];
    }

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) AS total FROM competencias WHERE id_curso = ? AND activo = 1");
    $stmtTotal->execute([$idCurso]);
    $tieneCatalogo = (int) $stmtTotal->fetch()["total"] > 0;

    return [null, $tieneCatalogo
        ? "La competencia \"$textoCompetencia\" no coincide con el catálogo registrado para este curso."
        : "Este curso todavía no tiene competencias registradas (corre seed_competencias.sql)."];

}

/**
 * Valida el valor extraído según el sistema del nivel: literal
 * (AD/A/B/C) para Primaria y Secundaria en este formato CNEB — las
 * 3 boletas reales usan literal en TODOS los niveles (el "0-20"
 * vigesimal del sistema anterior ya no aplica a este formato), pero
 * se deja el rango numérico como alternativa por si el colegio
 * todavía registra notas antiguas en vigesimal.
 *
 * @return array{0: ?int, 1: ?string, 2: ?string} [vigesimal, literal, error]
 */
function notas_validar_valor(string $valorTexto, ?string $nivel): array {

    $valor = trim(strtoupper($valorTexto));

    if (in_array($valor, ["AD", "A", "B", "C"], true)) {
        return [null, $valor, null];
    }

    if (is_numeric($valor)) {
        $num = (int) round((float) $valor);
        if ($num < 0 || $num > 20) {
            return [null, null, "Valor \"$valorTexto\" fuera de rango (0-20)."];
        }
        return [$num, null, null];
    }

    return [null, null, "Valor \"$valorTexto\" no reconocido como nota vigesimal ni literal (AD/A/B/C)."];

}

/** DNI real del alumno ya resuelto (para mostrarlo en la vista previa como dato verificado, no un valor crudo del PDF). */
function boleta_dni_de_alumno(PDO $conexion, ?int $idAlumno): ?string {
    if (!$idAlumno) {
        return null;
    }
    $stmt = $conexion->prepare("SELECT dni FROM alumnos WHERE id_alumno = ?");
    $stmt->execute([$idAlumno]);
    $fila = $stmt->fetch();
    return $fila ? $fila["dni"] : null;
}

/** Nivel (PRIMARIA/SECUNDARIA) del alumno ya resuelto, según su matrícula activa. */
function boleta_nivel_por_alumno(PDO $conexion, int $idAlumno): ?string {

    $stmt = $conexion->prepare("
        SELECT gs.nivel
        FROM matriculas m
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
        WHERE m.id_alumno = ? AND m.estado = 'ACTIVA'
        ORDER BY m.id_matricula DESC LIMIT 1
    ");
    $stmt->execute([$idAlumno]);
    $fila = $stmt->fetch();

    return $fila ? $fila["nivel"] : null;

}

// ----------------------------------------------------------
// LECTURA
// ----------------------------------------------------------

function boleta_obtener(PDO $conexion, int $idBoleta): ?array {
    $stmt = $conexion->prepare("SELECT * FROM boletas_importadas WHERE id_boleta = ?");
    $stmt->execute([$idBoleta]);
    return $stmt->fetch() ?: null;
}

/**
 * A qué alumno quedó asignada una boleta ya procesada (el que resolvió
 * boleta_procesar() por DNI/nombre leído del PDF). Devuelve
 * ["id_alumno" => ?int, "nombre" => ?string, "dni" => ?string, "advertencia" => ?string].
 * id_alumno = null significa que NINGÚN alumno pudo identificarse y el
 * Admin debe asignarlo a mano en revisar_importacion.php.
 */
function boleta_resumen_alumno(PDO $conexion, int $idBoleta): array {

    $stmt = $conexion->prepare("
        SELECT e.id_alumno_resuelto, e.mensaje_advertencia, a.nombres, a.apellidos, a.dni
        FROM boletas_notas_extraidas e
        LEFT JOIN alumnos a ON a.id_alumno = e.id_alumno_resuelto
        WHERE e.id_boleta = ?
        ORDER BY e.id_extraccion
        LIMIT 1
    ");
    $stmt->execute([$idBoleta]);
    $fila = $stmt->fetch();

    if (!$fila || !$fila["id_alumno_resuelto"]) {
        return ["id_alumno" => null, "nombre" => null, "dni" => null, "advertencia" => $fila["mensaje_advertencia"] ?? null];
    }

    return [
        "id_alumno" => (int) $fila["id_alumno_resuelto"],
        "nombre" => trim($fila["apellidos"] . ", " . $fila["nombres"]),
        "dni" => $fila["dni"],
        "advertencia" => $fila["mensaje_advertencia"],
    ];

}

function boletas_listar(PDO $conexion, ?string $estado = null): array {
    $sql = "
        SELECT b.*, u.nombres AS subio_nombres, u.apellidos AS subio_apellidos, p.nombre AS periodo_nombre,
               (SELECT COUNT(*) FROM boletas_notas_extraidas e WHERE e.id_boleta = b.id_boleta) AS total_filas,
               (SELECT COUNT(*) FROM boletas_notas_extraidas e WHERE e.id_boleta = b.id_boleta AND e.estado_fila = 'ERROR') AS filas_error,
               (SELECT GROUP_CONCAT(DISTINCT e.bimestre ORDER BY e.bimestre SEPARATOR ', ') FROM boletas_notas_extraidas e WHERE e.id_boleta = b.id_boleta) AS bimestres,
               (SELECT CONCAT(a.apellidos, ', ', a.nombres) FROM boletas_notas_extraidas e
                    INNER JOIN alumnos a ON a.id_alumno = e.id_alumno_resuelto
                    WHERE e.id_boleta = b.id_boleta LIMIT 1) AS alumno_resuelto
        FROM boletas_importadas b
        INNER JOIN usuarios u ON u.id_usuario = b.id_usuario_subio
        INNER JOIN periodos_academicos p ON p.id_periodo = b.id_periodo
    ";
    $params = [];
    if ($estado) {
        $sql .= " WHERE b.estado = ?";
        $params[] = $estado;
    }
    $sql .= " ORDER BY b.creado_en DESC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Todas las filas de una boleta (una por área+bimestre), cada una con sus competencias anidadas en ["competencias"]. */
function boleta_filas(PDO $conexion, int $idBoleta): array {

    $stmt = $conexion->prepare("
        SELECT e.*, a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, c.nombre AS curso_nombre
        FROM boletas_notas_extraidas e
        LEFT JOIN alumnos a ON a.id_alumno = e.id_alumno_resuelto
        LEFT JOIN cursos c ON c.id_curso = e.id_curso_resuelto
        WHERE e.id_boleta = ?
        ORDER BY e.texto_curso_extraido, e.bimestre
    ");
    $stmt->execute([$idBoleta]);
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila["competencias"] = boleta_fila_competencias($conexion, (int) $fila["id_extraccion"]);
    }
    unset($fila);

    return $filas;

}

function boleta_fila_competencias(PDO $conexion, int $idExtraccion): array {
    $stmt = $conexion->prepare("
        SELECT ce.*, comp.nombre AS competencia_nombre
        FROM boletas_competencias_extraidas ce
        LEFT JOIN competencias comp ON comp.id_competencia = ce.id_competencia_resuelto
        WHERE ce.id_extraccion = ?
        ORDER BY comp.orden, ce.texto_competencia_extraido
    ");
    $stmt->execute([$idExtraccion]);
    return $stmt->fetchAll();
}

/**
 * Filas de una boleta que pertenecen a los cursos que el profesor
 * dicta (vía profesor_curso_grado, nunca confiando en un id_curso
 * suelto).
 */
function profesor_boleta_filas(PDO $conexion, int $idBoleta, int $idProfesor): array {

    $stmt = $conexion->prepare("
        SELECT DISTINCT e.*, a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, c.nombre AS curso_nombre
        FROM boletas_notas_extraidas e
        INNER JOIN cursos c ON c.id_curso = e.id_curso_resuelto
        INNER JOIN alumnos a ON a.id_alumno = e.id_alumno_resuelto
        INNER JOIN matriculas m ON m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
        INNER JOIN niveles_grados ng ON ng.nivel = gs.nivel COLLATE utf8mb4_unicode_ci AND ng.grado = gs.grado
        INNER JOIN profesor_curso_grado pcg
            ON pcg.id_curso = e.id_curso_resuelto AND pcg.id_nivel_grado = ng.id_nivel_grado
        WHERE e.id_boleta = ? AND pcg.id_profesor = ?
        ORDER BY e.texto_curso_extraido, e.bimestre
    ");
    $stmt->execute([$idBoleta, $idProfesor]);
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila["competencias"] = boleta_fila_competencias($conexion, (int) $fila["id_extraccion"]);
    }
    unset($fila);

    return $filas;

}

/** Boletas EN_REVISION que tienen al menos una fila en los cursos del profesor. */
function profesor_boletas_pendientes(PDO $conexion, int $idProfesor): array {
    $stmt = $conexion->prepare("
        SELECT DISTINCT b.*
        FROM boletas_importadas b
        INNER JOIN boletas_notas_extraidas e ON e.id_boleta = b.id_boleta
        INNER JOIN matriculas m ON m.id_alumno = e.id_alumno_resuelto AND m.estado = 'ACTIVA'
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
        INNER JOIN niveles_grados ng ON ng.nivel = gs.nivel COLLATE utf8mb4_unicode_ci AND ng.grado = gs.grado
        INNER JOIN profesor_curso_grado pcg
            ON pcg.id_curso = e.id_curso_resuelto AND pcg.id_nivel_grado = ng.id_nivel_grado
        WHERE b.estado = 'EN_REVISION' AND pcg.id_profesor = ?
        ORDER BY b.creado_en DESC
    ");
    $stmt->execute([$idProfesor]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------
// CORRECCIÓN (Admin — solo matching técnico, nunca el valor)
// ----------------------------------------------------------

/** Corrige el alumno de TODAS las filas de la boleta a la vez (el PDF es de un solo alumno, no tiene sentido corregirlo fila por fila). */
function boleta_corregir_alumno(PDO $conexion, int $idBoleta, int $idAlumno, int $idUsuarioAdmin): void {

    $conexion->prepare("UPDATE boletas_notas_extraidas SET id_alumno_resuelto = ? WHERE id_boleta = ?")
        ->execute([$idAlumno, $idBoleta]);

    $stmt = $conexion->prepare("SELECT id_extraccion FROM boletas_notas_extraidas WHERE id_boleta = ?");
    $stmt->execute([$idBoleta]);
    foreach ($stmt->fetchAll() as $fila) {
        boleta_fila_recalcular_estado($conexion, (int) $fila["id_extraccion"]);
    }

    auditoria_registrar($conexion, $idUsuarioAdmin, "CORREGIR_MATCH", "BOLETA", $idBoleta, "id_alumno_resuelto -> $idAlumno (todas las filas)");

}

function boleta_fila_corregir_curso(PDO $conexion, int $idExtraccion, int $idCurso, int $idUsuarioAdmin): void {

    $stmt = $conexion->prepare("SELECT id_curso_resuelto FROM boletas_notas_extraidas WHERE id_extraccion = ?");
    $stmt->execute([$idExtraccion]);
    $anterior = $stmt->fetch();

    $conexion->prepare("UPDATE boletas_notas_extraidas SET id_curso_resuelto = ? WHERE id_extraccion = ?")
        ->execute([$idCurso, $idExtraccion]);

    boleta_fila_recalcular_estado($conexion, $idExtraccion);

    auditoria_registrar($conexion, $idUsuarioAdmin, "CORREGIR_MATCH", "BOLETA_NOTA", $idExtraccion,
        "id_curso_resuelto: " . ($anterior["id_curso_resuelto"] ?? "NULL") . " -> $idCurso");

}

/** Recalcula estado_fila (OK/ADVERTENCIA/ERROR) tras una corrección manual. */
function boleta_fila_recalcular_estado(PDO $conexion, int $idExtraccion): void {
    $stmt = $conexion->prepare("SELECT id_alumno_resuelto, id_curso_resuelto FROM boletas_notas_extraidas WHERE id_extraccion = ?");
    $stmt->execute([$idExtraccion]);
    $fila = $stmt->fetch();

    $nuevoEstado = ($fila["id_alumno_resuelto"] && $fila["id_curso_resuelto"]) ? "OK" : "ERROR";

    $conexion->prepare("UPDATE boletas_notas_extraidas SET estado_fila = ?, mensaje_advertencia = NULL WHERE id_extraccion = ?")
        ->execute([$nuevoEstado, $idExtraccion]);
}

// ----------------------------------------------------------
// REVISIÓN (Profesor — solo el valor, nunca a quién/qué curso pertenece)
// ----------------------------------------------------------

/**
 * El profesor confirma una fila (un área+bimestre) de su curso.
 * Puede corregir el valor final y/o los valores de competencia,
 * pero nunca id_alumno_resuelto/id_curso_resuelto.
 */
function profesor_fila_confirmar(PDO $conexion, int $idExtraccion, int $idProfesor, ?string $nuevoValorFinal, array $nuevosValoresCompetencias): void {

    // Autorización: la fila debe ser de un curso/grado que ESTE profesor
    // dicta (mismo criterio que profesor_boleta_filas) y su boleta no
    // puede estar ya PUBLICADA. Sin esto, cualquier profesor podía enviar
    // el id_extraccion de otro y cambiarle la nota.
    $stmt = $conexion->prepare("
        SELECT e.id_boleta
        FROM boletas_notas_extraidas e
        INNER JOIN boletas_importadas b ON b.id_boleta = e.id_boleta
        INNER JOIN matriculas m ON m.id_alumno = e.id_alumno_resuelto AND m.estado = 'ACTIVA'
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
        INNER JOIN niveles_grados ng ON ng.nivel = gs.nivel COLLATE utf8mb4_unicode_ci AND ng.grado = gs.grado
        INNER JOIN profesor_curso_grado pcg
            ON pcg.id_curso = e.id_curso_resuelto AND pcg.id_nivel_grado = ng.id_nivel_grado
        WHERE e.id_extraccion = ?
          AND pcg.id_profesor = ?
          AND b.estado <> 'PUBLICADA'
        LIMIT 1
    ");
    $stmt->execute([$idExtraccion, $idProfesor]);
    $fila = $stmt->fetch();

    if (!$fila) {
        throw new RuntimeException("No tienes permiso para modificar esta fila, o la boleta ya fue publicada.");
    }

    if ($nuevoValorFinal !== null) {
        [$vigesimal, $literal, $error] = notas_validar_valor($nuevoValorFinal, null);
        if ($error) {
            throw new RuntimeException($error);
        }
        $conexion->prepare("
            UPDATE boletas_notas_extraidas
            SET valor_final_extraido = ?, nota_vigesimal_final = ?, nota_literal_final = ?
            WHERE id_extraccion = ?
        ")->execute([$nuevoValorFinal, $vigesimal, $literal, $idExtraccion]);
    }

    foreach ($nuevosValoresCompetencias as $idExtraccionCompetencia => $valor) {
        [$vigesimal, $literal, $error] = notas_validar_valor($valor, null);
        if ($error) {
            throw new RuntimeException($error);
        }
        $conexion->prepare("
            UPDATE boletas_competencias_extraidas
            SET valor_extraido = ?, nota_vigesimal = ?, nota_literal = ?
            WHERE id_extraccion_competencia = ? AND id_extraccion = ?
        ")->execute([$valor, $vigesimal, $literal, $idExtraccionCompetencia, $idExtraccion]);
    }

    $conexion->prepare("UPDATE boletas_notas_extraidas SET revisado_por_profesor = 1 WHERE id_extraccion = ?")
        ->execute([$idExtraccion]);

    boleta_verificar_revisada($conexion, (int) $fila["id_boleta"]);

}

/** Si todas las filas sin ERROR ya fueron revisadas por su profesor, pasa la boleta a REVISADA. */
function boleta_verificar_revisada(PDO $conexion, int $idBoleta): void {

    $stmt = $conexion->prepare("
        SELECT SUM(CASE WHEN estado_fila != 'ERROR' AND revisado_por_profesor = 0 THEN 1 ELSE 0 END) AS pendientes
        FROM boletas_notas_extraidas
        WHERE id_boleta = ?
    ");
    $stmt->execute([$idBoleta]);
    $pendientes = (int) $stmt->fetch()["pendientes"];

    if ($pendientes === 0) {
        $conexion->prepare("UPDATE boletas_importadas SET estado = 'REVISADA' WHERE id_boleta = ? AND estado = 'EN_REVISION'")
            ->execute([$idBoleta]);
    }

}

/**
 * El Administrador envía la boleta directamente a aprobación
 * (EN_REVISION -> REVISADA), SIN pasar por la revisión fila por
 * fila del profesor: las boletas que recibe el Admin ya llegan
 * completas y sin errores desde el colegio, así que ese paso es
 * innecesario para este flujo.
 *
 * Solo exige que no queden filas en ERROR (alumno o curso sin
 * resolver) — eso sigue siendo obligatorio, es matching técnico,
 * no una revisión de valores. Las filas ADVERTENCIA sí pueden
 * pasar. Al avanzar, marca todas las filas no-ERROR como
 * revisado_por_profesor = 1 para que el dato quede consistente
 * si alguna vez se vuelve a mirar esa columna (auditoría,
 * reportes, o si un profesor igual entra a revisar_boleta.php).
 */
function boleta_marcar_revisada_admin(PDO $conexion, int $idBoleta, int $idUsuarioAdmin): void {

    $stmt = $conexion->prepare("SELECT COUNT(*) FROM boletas_notas_extraidas WHERE id_boleta = ? AND estado_fila = 'ERROR'");
    $stmt->execute([$idBoleta]);
    $filasConError = (int) $stmt->fetchColumn();

    if ($filasConError > 0) {
        throw new RuntimeException("No se puede enviar a aprobación: quedan $filasConError fila(s) con error (alumno o curso sin resolver). Corrígelas primero.");
    }

    $conexion->prepare("UPDATE boletas_notas_extraidas SET revisado_por_profesor = 1 WHERE id_boleta = ? AND estado_fila != 'ERROR'")
        ->execute([$idBoleta]);

    $stmtUpdate = $conexion->prepare("UPDATE boletas_importadas SET estado = 'REVISADA' WHERE id_boleta = ? AND estado = 'EN_REVISION'");
    $stmtUpdate->execute([$idBoleta]);

    if ($stmtUpdate->rowCount() === 0) {
        throw new RuntimeException("Solo se pueden enviar a aprobación boletas en estado EN_REVISION.");
    }

    auditoria_registrar($conexion, $idUsuarioAdmin, "MARCAR_REVISADA", "BOLETA", $idBoleta,
        "Enviada a aprobación directamente por el Administrador (sin revisión por profesor).");

}

// ----------------------------------------------------------
// RECHAZO / APROBACIÓN / PUBLICACIÓN (Subdirector, o Admin para rechazo)
// ----------------------------------------------------------

function boleta_rechazar(PDO $conexion, int $idBoleta, string $motivo, int $idUsuario): void {

    $stmt = $conexion->prepare("
        SELECT b.nombre_archivo_original, b.id_usuario_subio, u.nombres, u.correo
        FROM boletas_importadas b
        INNER JOIN usuarios u ON u.id_usuario = b.id_usuario_subio
        WHERE b.id_boleta = ?
    ");
    $stmt->execute([$idBoleta]);
    $boleta = $stmt->fetch();

    $conexion->prepare("UPDATE boletas_importadas SET estado = 'RECHAZADA', motivo_rechazo = ? WHERE id_boleta = ?")
        ->execute([$motivo, $idBoleta]);
    auditoria_registrar($conexion, $idUsuario, "RECHAZAR", "BOLETA", $idBoleta, $motivo);

    // Avisa a quien subió el archivo (normalmente el Admin), para
    // que sepa que quedó rechazada y por qué, sin tener que ir a
    // revisar el historial para descubrirlo.
    if ($boleta && (int) $boleta["id_usuario_subio"] !== $idUsuario) {

        notificar_crear(
            $conexion,
            (int) $boleta["id_usuario_subio"],
            "BOLETA_RECHAZADA",
            "Tu boleta \"" . $boleta["nombre_archivo_original"] . "\" fue rechazada. Motivo: " . $motivo,
            "../admin/importaciones_pendientes.php"
        );

        correo_enviar(
            $boleta["correo"],
            $boleta["nombres"],
            "Boleta de notas rechazada - Intranet I.E.P. 88044",
            correo_plantilla("Boleta rechazada", "
                <p>Hola " . htmlspecialchars($boleta["nombres"]) . ",</p>
                <p>La boleta <strong>" . htmlspecialchars($boleta["nombre_archivo_original"]) . "</strong> fue rechazada.</p>
                <p><strong>Motivo:</strong> " . htmlspecialchars($motivo) . "</p>
                <p>Puedes volver a subirla corregida desde el Intranet.</p>
            ")
        );

    }

}

/**
 * El Subdirector aprueba. Si $fechaProgramada viene vacío, la
 * boleta queda lista para publicar de inmediato — aprobar NUNCA
 * publica automáticamente, el botón "Publicar" es una acción aparte.
 */
function boleta_aprobar(PDO $conexion, int $idBoleta, int $idUsuarioSubdirector, ?string $fechaProgramada): void {

    $stmt = $conexion->prepare("
        UPDATE boletas_importadas
        SET estado = 'APROBADA', id_usuario_aprobo = ?, aprobado_en = NOW(), fecha_publicacion_programada = ?
        WHERE id_boleta = ? AND estado = 'REVISADA'
    ");
    $stmt->execute([$idUsuarioSubdirector, $fechaProgramada ?: null, $idBoleta]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException("Solo se pueden aprobar boletas en estado REVISADA.");
    }

    auditoria_registrar($conexion, $idUsuarioSubdirector, "APROBAR", "BOLETA", $idBoleta,
        $fechaProgramada ? "Programada para $fechaProgramada" : "Sin fecha programada (lista para publicar ya)");

}

/** Boletas APROBADAS, indicando si ya se puede publicar ahora o si está programada para más tarde. */
function boletas_listas_para_publicar(PDO $conexion): array {
    $stmt = $conexion->prepare("
        SELECT b.*, p.nombre AS periodo_nombre,
               (SELECT GROUP_CONCAT(DISTINCT e.bimestre ORDER BY e.bimestre SEPARATOR ', ') FROM boletas_notas_extraidas e WHERE e.id_boleta = b.id_boleta) AS bimestres,
               (SELECT COUNT(*) FROM boletas_notas_extraidas e WHERE e.id_boleta = b.id_boleta) AS total_filas,
               (b.fecha_publicacion_programada IS NULL OR b.fecha_publicacion_programada <= NOW()) AS lista_ahora
        FROM boletas_importadas b
        INNER JOIN periodos_academicos p ON p.id_periodo = b.id_periodo
        WHERE b.estado = 'APROBADA'
        ORDER BY b.fecha_publicacion_programada IS NULL DESC, b.fecha_publicacion_programada
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Publica: en una sola transacción, escribe/actualiza `notas` y
 * `notas_competencias` a partir de las filas sin ERROR (cada una ya
 * trae su propio bimestre), y recién ahí se convierte en la fuente
 * que lee padre/notas.php.
 */
function boleta_publicar(PDO $conexion, int $idBoleta, int $idUsuarioSubdirector): void {

    $boleta = boleta_obtener($conexion, $idBoleta);

    if (!$boleta || $boleta["estado"] !== "APROBADA") {
        throw new RuntimeException("Solo se pueden publicar boletas en estado APROBADA.");
    }

    if ($boleta["fecha_publicacion_programada"] && strtotime($boleta["fecha_publicacion_programada"]) > time()) {
        throw new RuntimeException("Esta boleta está programada para publicarse el " . $boleta["fecha_publicacion_programada"] . ".");
    }

    $conexion->beginTransaction();

    try {

        $filas = boleta_filas($conexion, $idBoleta);
        $creadas = 0;
        $actualizadas = 0;

        foreach ($filas as $fila) {

            if ($fila["estado_fila"] === "ERROR") {
                continue;
            }

            $idMatricula = boleta_matricula_activa($conexion, (int) $fila["id_alumno_resuelto"], (int) $boleta["id_periodo"]);

            $stmtNota = $conexion->prepare("
                INSERT INTO notas
                    (id_alumno, id_matricula, id_curso, id_periodo, bimestre,
                     nota_vigesimal, nota_literal, id_usuario_registro, id_importacion_nota)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id_matricula = VALUES(id_matricula),
                    nota_vigesimal = VALUES(nota_vigesimal),
                    nota_literal = VALUES(nota_literal),
                    id_usuario_modifico = VALUES(id_usuario_registro),
                    id_importacion_nota = VALUES(id_importacion_nota)
            ");
            $stmtNota->execute([
                $fila["id_alumno_resuelto"], $idMatricula, $fila["id_curso_resuelto"],
                $boleta["id_periodo"], $fila["bimestre"],
                $fila["nota_vigesimal_final"], $fila["nota_literal_final"],
                $idUsuarioSubdirector, $idBoleta,
            ]);

            if ($stmtNota->rowCount() === 1) {
                $creadas++;
                $idNota = (int) $conexion->lastInsertId();
            } else {
                $actualizadas++;
                $idNota = (int) $conexion->query("
                    SELECT id_nota FROM notas
                    WHERE id_alumno = " . (int) $fila["id_alumno_resuelto"] . "
                      AND id_curso = " . (int) $fila["id_curso_resuelto"] . "
                      AND id_periodo = " . (int) $boleta["id_periodo"] . "
                      AND bimestre = " . (int) $fila["bimestre"]
                )->fetchColumn();
            }

            $conexion->prepare("UPDATE boletas_notas_extraidas SET id_nota_generada = ? WHERE id_extraccion = ?")
                ->execute([$idNota, $fila["id_extraccion"]]);

            foreach ($fila["competencias"] as $comp) {

                if ($comp["estado_fila"] === "ERROR") {
                    continue;
                }

                $stmtComp = $conexion->prepare("
                    INSERT INTO notas_competencias
                        (id_nota, id_competencia, nota_vigesimal, nota_literal, id_usuario_registro)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        nota_vigesimal = VALUES(nota_vigesimal),
                        nota_literal = VALUES(nota_literal),
                        id_usuario_modifico = VALUES(id_usuario_registro)
                ");
                $stmtComp->execute([
                    $idNota, $comp["id_competencia_resuelto"],
                    $comp["nota_vigesimal"], $comp["nota_literal"], $idUsuarioSubdirector,
                ]);

                $idNotaCompetencia = $stmtComp->rowCount() === 1
                    ? (int) $conexion->lastInsertId()
                    : (int) $conexion->query("
                        SELECT id_nota_competencia FROM notas_competencias
                        WHERE id_nota = $idNota AND id_competencia = " . (int) $comp["id_competencia_resuelto"]
                    )->fetchColumn();

                $conexion->prepare("UPDATE boletas_competencias_extraidas SET id_nota_competencia_generada = ? WHERE id_extraccion_competencia = ?")
                    ->execute([$idNotaCompetencia, $comp["id_extraccion_competencia"]]);

            }

        }

        $conexion->prepare("
            UPDATE boletas_importadas
            SET estado = 'PUBLICADA', id_usuario_publico = ?, publicado_en = NOW()
            WHERE id_boleta = ?
        ")->execute([$idUsuarioSubdirector, $idBoleta]);

        auditoria_registrar($conexion, $idUsuarioSubdirector, "PUBLICAR", "BOLETA", $idBoleta,
            "$creadas notas nuevas, $actualizadas actualizadas");

        $conexion->commit();

    } catch (\Throwable $e) {
        $conexion->rollBack();
        throw $e;
    }

    // Avisa a los padres vinculados al alumno de esta boleta — antes
    // tenían que entrar por su cuenta a padre/notas.php para
    // enterarse. Se notifica DESPUÉS del commit (con la boleta ya
    // publicada de verdad), usando el mismo $filas ya cargado
    // (boleta_filas ya trae alumno_nombres/alumno_apellidos vía
    // JOIN, no hace falta otra consulta). La url incluye el
    // id_boleta para que cada publicación avise una sola vez, aunque
    // el mismo alumno reciba boletas de otros bimestres más adelante.
    $alumnosDeEstaBoleta = [];
    $bimestresPublicados = [];
    foreach ($filas as $f) {
        if ($f["estado_fila"] !== "ERROR") {
            $alumnosDeEstaBoleta[(int) $f["id_alumno_resuelto"]] = trim($f["alumno_nombres"] . " " . $f["alumno_apellidos"]);
            $bimestresPublicados[(int) $f["bimestre"]] = true;
        }
    }
    ksort($bimestresPublicados);
    $textoBimestres = count($bimestresPublicados) === 1
        ? "del bimestre " . array_key_first($bimestresPublicados)
        : (count($bimestresPublicados) > 1 ? "de los bimestres " . implode(", ", array_keys($bimestresPublicados)) : "");

    foreach ($alumnosDeEstaBoleta as $idAlumno => $nombreAlumno) {

        $urlPadre = "../padre/notas.php?id_alumno={$idAlumno}&id_boleta={$idBoleta}";
        $mensaje = "Ya están disponibles las notas " . ($textoBimestres !== "" ? $textoBimestres . " " : "") . "de " . $nombreAlumno . ".";

        $stmtPadres = $conexion->prepare("
            SELECT u.id_usuario, u.nombres, u.correo
            FROM padres_alumnos pa
            INNER JOIN usuarios u ON u.id_usuario = pa.id_usuario_padre
            WHERE pa.id_alumno = ? AND u.estado = 'ACTIVO'
        ");
        $stmtPadres->execute([$idAlumno]);

        foreach ($stmtPadres->fetchAll() as $padre) {

            notificar_crear($conexion, (int) $padre["id_usuario"], "BOLETA_PUBLICADA", $mensaje, $urlPadre);

            correo_enviar(
                $padre["correo"],
                $padre["nombres"],
                "Nuevas notas disponibles - Intranet I.E.P. 88044",
                correo_plantilla("Nuevas notas publicadas", "
                    <p>Hola " . htmlspecialchars($padre["nombres"]) . ",</p>
                    <p>" . htmlspecialchars($mensaje) . "</p>
                    <p>Ingresa al Intranet para revisarlas.</p>
                ")
            );

        }

    }

}

/**
 * LOTE (Admin): envía a aprobación TODAS las boletas EN_REVISION que no
 * tengan filas en ERROR. Las que sí tengan error (alumno o curso sin
 * resolver) se dejan como están para corregirlas a mano.
 * Devuelve ["enviadas" => n, "con_error" => n].
 */
function boletas_enviar_a_aprobacion_lote(PDO $conexion, int $idUsuarioAdmin): array {

    @set_time_limit(0);
    $ids = $conexion->query("SELECT id_boleta FROM boletas_importadas WHERE estado = 'EN_REVISION' ORDER BY id_boleta")->fetchAll(PDO::FETCH_COLUMN);
    $enviadas = 0;
    $conError = 0;

    foreach ($ids as $idBoleta) {
        try {
            boleta_marcar_revisada_admin($conexion, (int) $idBoleta, $idUsuarioAdmin);
            $enviadas++;
        } catch (\Throwable $e) {
            $conError++;
        }
    }

    return ["enviadas" => $enviadas, "con_error" => $conError];

}

/**
 * LOTE (Subdirector): aprueba todas las boletas REVISADA (sin fecha
 * programada) y publica las que quedan listas. Cada boleta va en su
 * propia transacción: si una falla, las demás siguen.
 * Devuelve ["aprobadas" => n, "publicadas" => n, "fallidas" => n].
 */
function boletas_aprobar_y_publicar_lote(PDO $conexion, int $idUsuarioSubdirector): array {

    @set_time_limit(0);
    $aprobadas = 0;
    $publicadas = 0;
    $fallidas = 0;

    $revisadas = $conexion->query("SELECT id_boleta FROM boletas_importadas WHERE estado = 'REVISADA' ORDER BY id_boleta")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($revisadas as $idBoleta) {
        try {
            boleta_aprobar($conexion, (int) $idBoleta, $idUsuarioSubdirector, null);
            $aprobadas++;
        } catch (\Throwable $e) {
            $fallidas++;
        }
    }

    foreach (boletas_listas_para_publicar($conexion) as $b) {
        if (!$b["lista_ahora"]) {
            continue; // programada para más tarde: se respeta la fecha
        }
        try {
            boleta_publicar($conexion, (int) $b["id_boleta"], $idUsuarioSubdirector);
            $publicadas++;
        } catch (\Throwable $e) {
            $fallidas++;
        }
    }

    return ["aprobadas" => $aprobadas, "publicadas" => $publicadas, "fallidas" => $fallidas];

}

/** Matrícula activa del alumno en el periodo dado, o null si no tiene (la nota igual se guarda, id_matricula queda NULL). */
function boleta_matricula_activa(PDO $conexion, int $idAlumno, int $idPeriodo): ?int {
    $stmt = $conexion->prepare("
        SELECT id_matricula FROM matriculas
        WHERE id_alumno = ? AND id_periodo = ? AND estado = 'ACTIVA'
        LIMIT 1
    ");
    $stmt->execute([$idAlumno, $idPeriodo]);
    $fila = $stmt->fetch();
    return $fila ? (int) $fila["id_matricula"] : null;
}
