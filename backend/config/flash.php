<?php

// ==========================================
// Mensajes flash (éxito/error) que sobreviven
// al patrón POST-Redirect-GET.
// Requiere session_start() ya llamado.
// Uso:
//   flash_set("Guardado correctamente.", "success");
//   header("Location: pagina.php");
//   exit;
//
//   ... y en el GET siguiente:
//   [$mensaje, $mensajeTipo] = flash_get();
// ==========================================

function flash_set(string $mensaje, string $tipo): void {

    $_SESSION["flash_mensaje"] = $mensaje;
    $_SESSION["flash_tipo"] = $tipo;

}

function flash_get(): array {

    $mensaje = $_SESSION["flash_mensaje"] ?? null;
    $tipo = $_SESSION["flash_tipo"] ?? null;

    unset($_SESSION["flash_mensaje"], $_SESSION["flash_tipo"]);

    return [$mensaje, $tipo];

}
