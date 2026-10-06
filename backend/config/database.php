<?php

$host = "localhost";
$dbname = "colegio_ie88044";
$username = "root";
$password = "";

// Si existe database.local.php (no se sube a git) sus valores tienen prioridad.
// Permite usar un usuario de BD propio sin tocar este archivo.
$local = __DIR__ . "/database.local.php";
if (is_file($local)) {
    $cfg = require $local;
    $host     = $cfg["host"]     ?? $host;
    $dbname   = $cfg["dbname"]   ?? $dbname;
    $username = $cfg["username"] ?? $username;
    $password = $cfg["password"] ?? $password;
}

try {

    $conexion = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $conexion->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $conexion->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

} catch (PDOException $e) {

    http_response_code(500);

    die("Error de conexión con la base de datos.");

}
