<?php

// ==========================================
// Generación de reportes en PDF (Dompdf) — módulo genérico y
// reutilizable para todo el Intranet (listado de alumnos, trabajos
// enviados a Subdirección, avance del PCA, etc.), igual de
// reutilizable que backend/config/correo.php.
//
// Dompdf está vendorizado a mano en backend/vendor/dompdf/ (sin
// Composer), con su propio autoloader — ver
// backend/vendor/dompdf/autoload.php.
// ==========================================

require_once __DIR__ . "/../vendor/dompdf/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Devuelve el escudo del colegio como data URI base64, para
 * incrustarlo directamente en el HTML del PDF (Dompdf no siempre
 * puede resolver rutas relativas de imagen de forma confiable
 * según desde qué script se genere el reporte, así que se incrusta
 * en vez de enlazar el archivo).
 *
 * Devuelve "" (cadena vacía) si no se puede mostrar el escudo —
 * ya sea porque el archivo no existe o porque el servidor no tiene
 * la extensión GD de PHP instalada (Dompdf la necesita para
 * incrustar imágenes PNG/JPG en el PDF). En ese caso, quien llama
 * a esta función debe omitir el <img> y mostrar solo el nombre del
 * colegio en el encabezado — así el reporte se genera igual, sin
 * error 500, con o sin GD disponible en el servidor.
 */
function pdf_logo_base64(): string {

    static $dataUri = null;

    if ($dataUri !== null) {
        return $dataUri;
    }

    if (!extension_loaded("gd")) {
        $dataUri = "";
        return $dataUri;
    }

    $ruta = __DIR__ . "/../../img/brand/escudo.png";

    if (!file_exists($ruta)) {
        $dataUri = "";
        return $dataUri;
    }

    $dataUri = "data:image/png;base64," . base64_encode(file_get_contents($ruta));

    return $dataUri;

}

/**
 * Envuelve el contenido específico de un reporte (tablas, listas,
 * etc. — ya armadas por quien llama) en la plantilla institucional
 * común: encabezado azul con escudo y nombre del colegio, título
 * del reporte, y pie con fecha/hora de generación y quién lo
 * generó. Mismos colores que css/styles.css (--ocean-deep, --ink,
 * --text-secondary, --sand) y que correo_plantilla() en correo.php,
 * para que los PDF se vean parte del mismo sistema.
 *
 * @param string $tituloReporte   Ej. "Listado de alumnos"
 * @param string $subtitulo       Ej. "Panel de Administración"
 * @param string $contenidoHtml   HTML ya armado del cuerpo del reporte
 * @param string $generadoPor     Nombre de quien generó el reporte
 */
function pdf_plantilla(
    string $tituloReporte,
    string $subtitulo,
    string $contenidoHtml,
    string $generadoPor
): string {

    $titulo = htmlspecialchars($tituloReporte);
    $sub = htmlspecialchars($subtitulo);
    $por = htmlspecialchars($generadoPor);
    $fecha = date("d/m/Y H:i");
    $logo = pdf_logo_base64();
    $logoHtml = $logo !== ""
        ? '<td style="width:40px;"><img src="' . $logo . '" alt=""></td>'
        : "";

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{$titulo}</title>
<style>

    @page {
        margin: 90px 32px 70px 32px;
    }

    * { box-sizing: border-box; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        color: #16263B;
        font-size: 10.5px;
        line-height: 1.5;
    }

    header {
        position: fixed;
        top: -70px;
        left: 0;
        right: 0;
        height: 60px;
        background: #1D4ED8;
        color: #FFFFFF;
        padding: 12px 24px;
    }

    header table { width: 100%; border-collapse: collapse; }
    header td { vertical-align: middle; }
    header img { height: 36px; }
    header .institucion {
        font-size: 13px;
        font-weight: bold;
        padding-left: 12px;
    }
    header .reporte-nombre {
        text-align: right;
        font-size: 11px;
    }

    footer {
        position: fixed;
        bottom: -50px;
        left: 0;
        right: 0;
        height: 40px;
        border-top: 1px solid #D7E3F0;
        padding-top: 6px;
        font-size: 8.5px;
        color: #4A5A6A;
    }
    footer table { width: 100%; }
    footer .pagina:after {
        content: counter(page) " / " counter(pages);
    }

    h1.titulo-reporte {
        font-size: 17px;
        color: #1D4ED8;
        margin: 0 0 2px;
    }
    p.subtitulo-reporte {
        font-size: 10.5px;
        color: #4A5A6A;
        margin: 0 0 18px;
    }

    h2 {
        font-size: 12.5px;
        color: #16263B;
        border-bottom: 2px solid #BAE6FD;
        padding-bottom: 4px;
        margin: 20px 0 8px;
    }

    table.reporte-tabla {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }
    table.reporte-tabla th {
        background: #F0F7FE;
        color: #16263B;
        text-align: left;
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: .03em;
        padding: 6px 8px;
        border-bottom: 2px solid #BAE6FD;
    }
    table.reporte-tabla td {
        padding: 6px 8px;
        border-bottom: 1px solid #E5EDF7;
        font-size: 10px;
    }
    table.reporte-tabla tr:nth-child(even) td {
        background: #FAFCFF;
    }

    .stats-resumen {
        width: 100%;
        margin-bottom: 16px;
    }
    .stats-resumen td {
        width: 25%;
        text-align: center;
        padding: 10px 6px;
    }
    .stats-resumen .valor {
        display: block;
        font-size: 19px;
        font-weight: bold;
        color: #1D4ED8;
    }
    .stats-resumen .etiqueta {
        display: block;
        font-size: 8.5px;
        color: #4A5A6A;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 8px;
        font-size: 9px;
        font-weight: bold;
    }
    .badge-verde  { background: #dcfce7; color: #15803d; }
    .badge-rojo   { background: #fee2e2; color: #b91c1c; }
    .badge-azul   { background: #dbeafe; color: #1D4ED8; }
    .badge-gris   { background: #EEF2F7; color: #4A5A6A; }

    .placeholder-text { color: #4A5A6A; font-style: italic; }

</style>
</head>
<body>

    <header>
        <table>
            <tr>
                {$logoHtml}
                <td class="institucion">I.E.P. 88044 Abraham Valdelomar</td>
                <td class="reporte-nombre">Intranet · Reporte institucional</td>
            </tr>
        </table>
    </header>

    <footer>
        <table>
            <tr>
                <td>Generado el {$fecha} por {$por}</td>
                <td style="text-align:right;" class="pagina">Página </td>
            </tr>
        </table>
    </footer>

    <h1 class="titulo-reporte">{$titulo}</h1>
    <p class="subtitulo-reporte">{$sub} · I.E.P. 88044 Abraham Valdelomar</p>

    {$contenidoHtml}

</body>
</html>
HTML;

}

/**
 * Genera el PDF a partir de HTML ya armado (normalmente el
 * resultado de pdf_plantilla()) y lo envía directo a la respuesta
 * HTTP — ya sea para verlo en el navegador (inline) o forzar la
 * descarga (attachment). Termina la ejecución del script.
 *
 * @param string $html
 * @param string $nombreArchivo  Sin extensión, ej. "listado-alumnos"
 * @param bool   $descargar      true = descarga forzada, false = abre en el navegador
 */
function pdf_generar_y_enviar(string $html, string $nombreArchivo, bool $descargar = true): void {

    $opciones = new Options();
    $opciones->set("isRemoteEnabled", false);
    $opciones->set("isHtml5ParserEnabled", true);
    $opciones->set("defaultFont", "DejaVu Sans");
    $opciones->set("fontDir", __DIR__ . "/../uploads/dompdf-cache/");
    $opciones->set("fontCache", __DIR__ . "/../uploads/dompdf-cache/");
    $opciones->set("chroot", dirname(__DIR__, 2));

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml($html, "UTF-8");
    $dompdf->setPaper("A4", "portrait");
    $dompdf->render();

    $nombreArchivo = preg_replace('/[^a-zA-Z0-9\-_]+/', "-", $nombreArchivo) . ".pdf";

    $dompdf->stream($nombreArchivo, [
        "Attachment" => $descargar,
    ]);

    exit;

}
