<?php
// ==========================================================
// RESPALDO AUTOMÁTICO — I.E.P. 88044
// Genera un .sql de la base de datos y un .zip de los archivos
// subidos (boletas, materiales, envíos). Se ejecuta SOLO por
// consola:   php respaldar.php [--destino=RUTA] [--conservar=N]
//   --destino   carpeta donde guardar (ideal: otro disco / USB / nube)
//   --conservar cuántos respaldos mantener (por defecto 14)
// ==========================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por consola.');
}

$raiz = dirname(__DIR__);
$opciones = getopt('', ['destino::', 'conservar::']);
$destinoBase = !empty($opciones['destino']) ? rtrim($opciones['destino'], '/\\') : __DIR__ . DIRECTORY_SEPARATOR . 'respaldos';
$conservar = isset($opciones['conservar']) ? max(1, (int)$opciones['conservar']) : 14;

// --- Credenciales: las mismas que usa el sistema (database.php / database.local.php)
$host = 'localhost'; $dbname = 'colegio_ie88044'; $user = 'root'; $pass = '';
$local = $raiz . '/backend/config/database.local.php';
if (is_file($local)) {
    $cfg = require $local;
    $host = $cfg['host'] ?? $host; $dbname = $cfg['dbname'] ?? $dbname;
    $user = $cfg['username'] ?? $user; $pass = $cfg['password'] ?? $pass;
}

// --- Localizar mysqldump (XAMPP en Windows, o el del sistema)
$candidatos = [
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
    '/opt/lampp/bin/mysqldump',
    '/usr/bin/mysqldump',
    '/usr/local/bin/mysqldump',
];
$mysqldump = 'mysqldump';
foreach ($candidatos as $c) { if (is_file($c)) { $mysqldump = $c; break; } }

$sello = date('Y-m-d_H-i');
$carpeta = $destinoBase . DIRECTORY_SEPARATOR . $sello;
if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
    fwrite(STDERR, "No se pudo crear $carpeta\n"); exit(1);
}

// --- 1) Base de datos
$archivoSql = $carpeta . DIRECTORY_SEPARATOR . $dbname . '.sql';
$cmd = [$mysqldump, '--host=' . $host, '--user=' . $user, '--single-transaction', '--routines',
        '--default-character-set=utf8mb4', '--result-file=' . $archivoSql, $dbname];
$env = array_merge(getenv() ?: [], ['MYSQL_PWD' => $pass]); // la clave no viaja en la línea de comandos
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
if (!is_resource($p)) { fwrite(STDERR, "No se pudo ejecutar mysqldump.\n"); exit(1); }
stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
$codigo = proc_close($p);
if ($codigo !== 0 || !is_file($archivoSql) || filesize($archivoSql) < 100) {
    fwrite(STDERR, "Falló el respaldo de la base de datos: $err\n"); exit(1);
}
echo "OK base de datos: $archivoSql\n";

// --- 2) Archivos subidos
if (class_exists('ZipArchive')) {
    $zipRuta = $carpeta . DIRECTORY_SEPARATOR . 'archivos_subidos.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $origen = $raiz . '/backend/uploads';
        $ruta = realpath($origen);
        if ($ruta) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = substr($f->getPathname(), strlen($ruta) + 1);
                if (strpos(str_replace('\\', '/', $rel), 'dompdf-cache/') === 0) continue; // caché: no hace falta
                $zip->addFile($f->getPathname(), 'uploads/' . str_replace('\\', '/', $rel));
            }
        }
        $zip->close();
        echo "OK archivos subidos: $zipRuta\n";
    }
} else {
    echo "AVISO: la extensión zip de PHP no está activa; se omitieron los archivos subidos.\n";
}

// --- 3) Rotación: conserva solo los últimos N respaldos
$dirs = array_filter(glob($destinoBase . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR), function ($d) {
    return preg_match('/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}$/', $d);
});
sort($dirs);
while (count($dirs) > $conservar) {
    $viejo = array_shift($dirs);
    foreach (glob($viejo . DIRECTORY_SEPARATOR . '*') as $f) @unlink($f);
    @rmdir($viejo);
    echo "Eliminado respaldo antiguo: $viejo\n";
}
echo "Respaldo terminado.\n";
