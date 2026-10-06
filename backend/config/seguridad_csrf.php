<?php

// ==========================================
// Protección CSRF — requiere session_start() ya llamado.
// Uso:
//   require_once "../backend/config/seguridad_csrf.php";
//   ... dentro del formulario POST, imprimir el resultado de csrf_field()
//   ... al inicio del bloque POST: csrf_verificar();
// ==========================================

function csrf_token(): string {

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];

}

function csrf_field(): string {

    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';

}

function csrf_verificar(): void {

    $enviado = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"] ?? "", $enviado)) {

        http_response_code(403);
        die("Token de seguridad inválido o expirado. Vuelve a la página anterior e inténtalo de nuevo.");

    }

}