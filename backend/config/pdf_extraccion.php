<?php

// ==========================================================
// Extracción de texto de boletas en PDF (Etapa 2).
//
// Decisión confirmada: las boletas reales del colegio tienen texto
// seleccionable (generadas digitalmente), no son escaneadas. Por
// eso esta etapa SOLO implementa extracción directa de texto, sin
// OCR. Si en el futuro aparece una boleta escaneada, esta función
// la detecta (texto vacío) y la marca como ERROR en vez de fallar
// silenciosamente o inventar contenido — el soporte de OCR
// (Tesseract) queda para una etapa aparte, ver riesgo #1 del
// análisis original.
//
// Requiere Composer (el proyecto hoy no lo usa: dompdf/phpmailer
// están instalados a mano en backend/vendor/). Antes de usar este
// archivo:
//   cd backend
//   composer require smalot/pdfparser
// Composer crea backend/vendor/autoload.php sin tocar las carpetas
// dompdf/ ni phpmailer/ ya existentes.
// ==========================================================

require_once __DIR__ . "/../vendor/autoload.php";

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extrae el texto plano de un PDF con capa de texto. Lanza
 * RuntimeException con un mensaje seguro para mostrar al usuario si
 * el PDF no se puede leer o si no tiene texto (probablemente
 * escaneado — no soportado en esta etapa).
 */
function boleta_extraer_texto(string $rutaAbsoluta): string {

    if (!is_file($rutaAbsoluta)) {
        throw new RuntimeException("El archivo de la boleta ya no existe en el servidor.");
    }

    try {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($rutaAbsoluta);
        $texto = $pdf->getText();
    } catch (\Throwable $e) {
        throw new RuntimeException("No se pudo leer el PDF (archivo corrupto o dañado).");
    }

    $texto = trim(preg_replace('/[ \t]+/', ' ', $texto ?? ""));

    if ($texto === "" || mb_strlen($texto) < 20) {
        throw new RuntimeException(
            "El PDF no tiene texto seleccionable (parece una boleta escaneada). " .
            "Esta versión del módulo solo procesa boletas generadas digitalmente."
        );
    }

    return $texto;

}
