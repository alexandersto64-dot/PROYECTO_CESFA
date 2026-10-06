<?php

// ==========================================
// IP del visitante (para auditoría y límite de intentos de login).
//
// Por defecto es REMOTE_ADDR. Si el hosting pone un proxy/CDN delante
// (Cloudflare, etc.) todos los visitantes aparecerían con la IP del
// proxy; en ese caso crea backend/config/red.local.php:
//
//   <?php return ["proxies_confiables" => ["IP_DEL_PROXY"]];
//
// y solo entonces se usa la cabecera X-Forwarded-For. Nunca se confía
// en esa cabecera si la petición no viene de un proxy listado.
// ==========================================

function ip_cliente(): string {

    static $ip = null;

    if ($ip !== null) {
        return $ip;
    }

    $remota = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
    $ip = filter_var($remota, FILTER_VALIDATE_IP) ? $remota : "desconocida";

    $archivo = __DIR__ . "/red.local.php";

    if (is_file($archivo) && $ip !== "desconocida") {

        $cfg = require $archivo;
        $proxies = is_array($cfg["proxies_confiables"] ?? null) ? $cfg["proxies_confiables"] : [];

        if (in_array($ip, $proxies, true) && !empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {

            // Se recorre de derecha a izquierda: la primera IP que NO es de
            // un proxy confiable es la del visitante real.
            $cadena = array_reverse(array_map("trim", explode(",", (string) $_SERVER["HTTP_X_FORWARDED_FOR"])));

            foreach ($cadena as $candidata) {
                if (filter_var($candidata, FILTER_VALIDATE_IP) && !in_array($candidata, $proxies, true)) {
                    $ip = $candidata;
                    break;
                }
            }

        }

    }

    return $ip;

}
