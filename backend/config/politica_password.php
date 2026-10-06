<?php

// ==========================================
// Política de contraseñas (un solo lugar para todo el sistema).
//
// - password_validar_politica(): reglas mínimas al CREAR/CAMBIAR una
//   contraseña (usuarios.php, cambiar_password.php, restablecer).
// - password_marcar_cambio_obligatorio() / password_cambio_obligatorio():
//   las cuentas de padres creadas en lote (o con clave puesta por el
//   Admin) quedan con usuarios.debe_cambiar_password = 1 y el padre es
//   llevado a padre/cambiar_password.php hasta que elija la suya.
//
// Si todavía no se ejecutó migracion_password_seguridad.sql (la columna
// no existe), las funciones de "cambio obligatorio" no hacen nada y el
// sistema sigue funcionando igual que antes.
// ==========================================

const PASSWORD_MIN_LARGO = 10;
const PASSWORD_MAX_LARGO = 72; // límite real de bcrypt (password_hash por defecto)

/** Devuelve null si la contraseña cumple, o el mensaje de error en español. */
function password_validar_politica(string $password): ?string {

    if (strlen($password) < PASSWORD_MIN_LARGO) {
        return "La contraseña debe tener al menos " . PASSWORD_MIN_LARGO . " caracteres.";
    }

    if (strlen($password) > PASSWORD_MAX_LARGO) {
        return "La contraseña es demasiado larga (máximo " . PASSWORD_MAX_LARGO . " caracteres).";
    }

    if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        return "La contraseña debe incluir al menos una letra y un número.";
    }

    return null;

}

/** Marca (o desmarca) que el usuario debe elegir su propia contraseña. Nunca lanza error. */
function password_marcar_cambio_obligatorio(PDO $conexion, int $idUsuario, bool $obligatorio = true): void {

    try {
        $conexion->prepare("UPDATE usuarios SET debe_cambiar_password = ? WHERE id_usuario = ?")
            ->execute([$obligatorio ? 1 : 0, $idUsuario]);
    } catch (\Throwable $e) {
        // Columna inexistente (migración pendiente): se ignora a propósito.
    }

}

function password_cambio_obligatorio(PDO $conexion, int $idUsuario): bool {

    try {
        $stmt = $conexion->prepare("SELECT debe_cambiar_password FROM usuarios WHERE id_usuario = ?");
        $stmt->execute([$idUsuario]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (\Throwable $e) {
        return false;
    }

}
