<?php

// ==========================================
// Verificación del CONTENIDO real de un archivo subido.
//
// La extensión (.pdf) la escribe quien sube el archivo: no prueba nada.
// Aquí se lee el archivo temporal y se comprueba que por dentro sea de
// verdad lo que dice su extensión (firma del formato), antes de guardarlo.
//
//   archivo_validar_contenido($_FILES["x"]["tmp_name"], "pdf");
//
// Lanza RuntimeException con un mensaje seguro para mostrar al usuario.
// ==========================================

function archivo_validar_contenido(string $rutaTemporal, string $extension): void {

    $extension = strtolower($extension);

    $fh = @fopen($rutaTemporal, "rb");

    if (!$fh) {
        throw new RuntimeException("No se pudo leer el archivo subido.");
    }

    $inicio = (string) fread($fh, 8192);
    fclose($fh);

    if ($inicio === "") {
        throw new RuntimeException("El archivo está vacío.");
    }

    $ok = false;

    switch ($extension) {

        case "pdf":
            // La especificación permite el encabezado dentro de los primeros 1024 bytes.
            $ok = strpos(substr($inicio, 0, 1024), "%PDF-") !== false
                && archivo_mime_coincide($rutaTemporal, ["application/pdf"]);
            break;

        case "jpg":
        case "jpeg":
            $ok = strncmp($inicio, "\xFF\xD8\xFF", 3) === 0
                && archivo_mime_coincide($rutaTemporal, ["image/jpeg"])
                && !archivo_contiene_codigo_php($rutaTemporal);
            break;

        case "png":
            $ok = strncmp($inicio, "\x89PNG\r\n\x1A\n", 8) === 0
                && archivo_mime_coincide($rutaTemporal, ["image/png"])
                && !archivo_contiene_codigo_php($rutaTemporal);
            break;

        case "docx":
        case "xlsx":
        case "pptx":
            $carpeta = ["docx" => "word/", "xlsx" => "xl/", "pptx" => "ppt/"][$extension];
            $ok = archivo_zip_office_valido($rutaTemporal, $inicio, $carpeta);
            break;

        case "doc":
        case "xls":
        case "ppt":
            // Formato binario antiguo de Office (contenedor OLE2).
            $ok = strncmp($inicio, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
            break;

        case "zip":
            $ok = strncmp($inicio, "PK\x03\x04", 4) === 0;
            break;

        case "rar":
            $ok = strncmp($inicio, "Rar!\x1A\x07", 6) === 0;
            break;

        case "txt":
        case "csv":
            // Texto plano: sin bytes nulos y sin código PHP escondido.
            $ok = strpos($inicio, "\0") === false
                && !archivo_contiene_codigo_php($rutaTemporal);
            break;

        default:
            // Extensión no contemplada: por seguridad no se acepta.
            $ok = false;

    }

    if (!$ok) {
        throw new RuntimeException(
            "El contenido del archivo no corresponde a su extensión (.$extension). "
            . "Sube un archivo válido, sin cambiarle la extensión a mano."
        );
    }

}

/** Si la extensión fileinfo está activa, el tipo MIME detectado debe ser uno de los esperados. */
function archivo_mime_coincide(string $ruta, array $permitidos): bool {

    if (!function_exists("finfo_open")) {
        return true; // la firma de bytes ya se comprobó; fileinfo es un refuerzo
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if (!$finfo) {
        return true;
    }

    $mime = finfo_file($finfo, $ruta);
    finfo_close($finfo);

    return in_array($mime, $permitidos, true);

}

/** Busca etiquetas de apertura de PHP dentro del archivo (imágenes/texto con código escondido). */
function archivo_contiene_codigo_php(string $ruta): bool {

    $fh = @fopen($ruta, "rb");

    if (!$fh) {
        return true;
    }

    $resto = "";

    while (!feof($fh)) {

        $bloque = $resto . (string) fread($fh, 1048576);

        if (stripos($bloque, "<?php") !== false || strpos($bloque, "<?=") !== false) {
            fclose($fh);
            return true;
        }

        $resto = substr($bloque, -8); // por si la etiqueta queda partida entre dos bloques

    }

    fclose($fh);

    return false;

}

/** .docx/.xlsx/.pptx son ZIP que deben traer [Content_Types].xml y su carpeta propia. */
function archivo_zip_office_valido(string $ruta, string $inicio, string $carpeta): bool {

    if (strncmp($inicio, "PK\x03\x04", 4) !== 0) {
        return false;
    }

    if (class_exists("ZipArchive")) {

        $zip = new ZipArchive();

        if ($zip->open($ruta) !== true) {
            return false;
        }

        $tieneTipos = $zip->locateName("[Content_Types].xml") !== false;
        $tieneCarpeta = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (strncmp((string) $zip->getNameIndex($i), $carpeta, strlen($carpeta)) === 0) {
                $tieneCarpeta = true;
                break;
            }
        }

        $zip->close();

        return $tieneTipos && $tieneCarpeta;

    }

    // Sin ZipArchive: se busca el nombre en el encabezado de las primeras entradas.
    return strpos($inicio, "[Content_Types].xml") !== false;

}
