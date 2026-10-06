<?php

// ==========================================
// Envío de correo (PHPMailer + SMTP) — módulo genérico y
// reutilizable para todo el Intranet (recuperación de contraseña,
// aprobación/rechazo de trabajos enviados a Subdirección, etc.).
//
// CONFIGURACIÓN, NUNCA CREDENCIALES EN EL CÓDIGO:
// correo_config() busca cada valor primero en una variable de
// entorno real del servidor (getenv) — la forma recomendada en
// hosting de producción — y si no existe, cae a
// backend/config/correo.local.php (para XAMPP local, donde definir
// variables de entorno del sistema operativo no es práctico para
// un colegio). correo.local.php NUNCA se sube a git (ver
// .gitignore) — solo se sube correo.local.php.example, con valores
// de ejemplo, no reales.
//
// MANEJO DE ERRORES:
// correo_enviar() nunca lanza una excepción hacia quien la llama:
// si el SMTP no está configurado, o el envío falla por cualquier
// motivo (credenciales, red, etc.), devuelve false y registra el
// detalle con error_log(). Un correo que no sale JAMÁS debe romper
// un flujo del sistema (login, revisión de trabajos, etc.) — las
// notificaciones internas (backend/config/notificaciones.php) ya
// garantizan que el usuario se entera dentro del Intranet aunque
// el correo falle.
// ==========================================

require_once __DIR__ . "/../vendor/phpmailer/src/Exception.php";
require_once __DIR__ . "/../vendor/phpmailer/src/SMTP.php";
require_once __DIR__ . "/../vendor/phpmailer/src/PHPMailer.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;


/**
 * Configuración SMTP resuelta (con caché en memoria para no releer
 * el archivo local en cada llamada dentro del mismo request).
 */
function correo_config(): array {

    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $local = [];
    $archivoLocal = __DIR__ . "/correo.local.php";

    if (file_exists($archivoLocal)) {
        $local = require $archivoLocal;
    }

    $leer = function (string $clave, $default = "") use ($local) {
        $valorEnv = getenv($clave);
        if ($valorEnv !== false && $valorEnv !== "") {
            return $valorEnv;
        }
        return $local[$clave] ?? $default;
    };

    $config = [
        "host"        => (string) $leer("SMTP_HOST"),
        "puerto"      => (int) $leer("SMTP_PORT", 587),
        "usuario"     => (string) $leer("SMTP_USER"),
        "clave"       => (string) $leer("SMTP_PASS"),
        "from_email"  => (string) $leer("SMTP_FROM_EMAIL"),
        "from_nombre" => (string) $leer("SMTP_FROM_NAME", "I.E.P. 88044 Abraham Valdelomar"),
        "seguridad"   => (string) $leer("SMTP_SECURE", "tls"), // "tls" (puerto 587) o "ssl" (puerto 465)
    ];

    return $config;

}

/**
 * true si hay lo mínimo necesario para intentar enviar un correo.
 * No comprueba que las credenciales sean CORRECTAS (eso solo se
 * sabe al conectar), solo que no falten datos evidentes.
 */
function correo_esta_configurado(): bool {

    $c = correo_config();

    return $c["host"] !== "" && $c["usuario"] !== "" && $c["clave"] !== "" && $c["from_email"] !== "";

}

/**
 * Envía un correo HTML. Devuelve true/false — nunca lanza excepción.
 *
 * @param string $destinatarioEmail
 * @param string $destinatarioNombre
 * @param string $asunto
 * @param string $cuerpoHtml   HTML ya armado (usar correo_plantilla())
 * @param string $cuerpoTexto  Alternativa en texto plano (opcional; si se omite, se deriva del HTML)
 */
function correo_enviar(
    string $destinatarioEmail,
    string $destinatarioNombre,
    string $asunto,
    string $cuerpoHtml,
    string $cuerpoTexto = ""
): bool {

    if (!correo_esta_configurado()) {
        error_log("[correo] Envío omitido (SMTP no configurado en backend/config/correo.local.php). Asunto: \"{$asunto}\", destinatario: {$destinatarioEmail}");
        return false;
    }

    if ($destinatarioEmail === "") {
        error_log("[correo] Envío omitido: el destinatario no tiene correo registrado. Asunto: \"{$asunto}\"");
        return false;
    }

    $config = correo_config();

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host = $config["host"];
        $mail->Port = $config["puerto"];
        $mail->SMTPAuth = true;
        $mail->Username = $config["usuario"];
        $mail->Password = $config["clave"];
        $mail->SMTPSecure = $config["seguridad"] === "ssl"
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = "UTF-8";

        $mail->setFrom($config["from_email"], $config["from_nombre"]);
        $mail->addAddress($destinatarioEmail, $destinatarioNombre);

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $cuerpoHtml;
        $mail->AltBody = $cuerpoTexto !== "" ? $cuerpoTexto : trim(strip_tags($cuerpoHtml));

        $mail->send();

        return true;

    } catch (PHPMailerException | \Throwable $e) {

        error_log("[correo] Error al enviar a {$destinatarioEmail} (\"{$asunto}\"): " . $mail->ErrorInfo);

        return false;

    }

}

/**
 * Plantilla HTML simple e institucional, reutilizada por todos los
 * correos del Intranet (mismo azul y tipografía que la web pública
 * y el panel). $contenidoHtml es el cuerpo específico de cada
 * correo (ya puede traer sus propias etiquetas <p>, <a>, etc.).
 */
function correo_plantilla(string $tituloCorto, string $contenidoHtml): string {

    $titulo = htmlspecialchars($tituloCorto);

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{$titulo}</title>
</head>
<body style="margin:0; padding:0; background:#F0F7FE; font-family: Arial, Helvetica, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F0F7FE; padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#FFFFFF; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.08); max-width:480px;">
          <tr>
            <td style="background:#1D4ED8; padding:20px 28px;">
              <span style="color:#FFFFFF; font-size:16px; font-weight:bold; font-family: Arial, Helvetica, sans-serif;">
                I.E.P. 88044 Abraham Valdelomar
              </span>
            </td>
          </tr>
          <tr>
            <td style="padding:28px; color:#16263B; font-size:14px; line-height:1.6;">
              {$contenidoHtml}
            </td>
          </tr>
          <tr>
            <td style="padding:16px 28px; background:#F0F7FE; color:#4A5A6A; font-size:12px;">
              Este es un mensaje automático del Intranet del colegio. No respondas a este correo.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

}
