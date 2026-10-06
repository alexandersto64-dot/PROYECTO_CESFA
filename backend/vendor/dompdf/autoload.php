<?php

// ==========================================
// Autoloader PSR-4 manual para Dompdf + sus 3 dependencias
// (Dompdf no viene instalado con Composer en este proyecto —
// igual que backend/vendor/phpmailer/, cada librería se dejó como
// código fuente descargado directamente, sin gestor de paquetes).
//
// Librerías incluidas aquí (todas necesarias para que Dompdf
// funcione — son sus dependencias obligatorias, no opcionales):
//   - dompdf/dompdf            (Dompdf\...)
//   - phenx/php-font-lib       (FontLib\...)
//   - phenx/php-svg-lib        (Svg\...)
//   - masterminds/html5-php    (Masterminds\...)
// ==========================================

spl_autoload_register(function (string $clase): void {

    static $prefijos = [
        "Dompdf\\"      => __DIR__ . "/dompdf/src/",
        "FontLib\\"     => __DIR__ . "/php-font-lib/src/",
        "Svg\\"         => __DIR__ . "/php-svg-lib/src/",
        "Masterminds\\" => __DIR__ . "/html5/src/",
    ];

    foreach ($prefijos as $prefijo => $directorioBase) {

        if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
            continue;
        }

        $rutaRelativa = substr($clase, strlen($prefijo));
        $archivo = $directorioBase . str_replace("\\", "/", $rutaRelativa) . ".php";

        if (file_exists($archivo)) {
            require_once $archivo;
        }

        return;

    }

});

// Dompdf\Cpdf vive fuera de src/ (en lib/), así que no lo cubre el
// mapeo PSR-4 de arriba — se registra aparte, igual que lo hace
// Composer con la entrada "classmap": ["lib/"] del composer.json
// original de Dompdf.
require_once __DIR__ . "/dompdf/lib/Cpdf.php";
