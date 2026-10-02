<?php
/**
 * Restaura la base de uso diario (monitoreo_red_soa) desde un respaldo de respaldos/
 * (los que guardan crear-base.php --reemplazar y preparar-demo.php --datos-de-ejemplo).
 *
 *   /opt/lampp/bin/php herramientas/restaurar-respaldo.php            lista los respaldos
 *   /opt/lampp/bin/php herramientas/restaurar-respaldo.php ARCHIVO    restaura ese respaldo
 *   (Windows: C:/xampp/php/php.exe herramientas/restaurar-respaldo.php …)
 *
 * Antes de restaurar respalda lo que hay ahora y pide escribir SI: restaurar nunca
 * pierde datos. Solo acepta archivos de respaldos/ hechos por estas herramientas
 * (sin CREATE DATABASE ni USE, para que no escriban en otra base).
 *
 * Termina con código 0 si restauró (o listó), 1 si se canceló y 2 si hubo un error.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/base-de-datos.php';

$argumentos = array_slice($argv, 1);
if (count($argumentos) > 1) {
    fwrite(STDERR, "Uso: php herramientas/restaurar-respaldo.php [ARCHIVO]\n");
    exit(2);
}

if ($argumentos === []) {
    listarRespaldos();
    exit(0);
}

if (!mysqlResponde()) {
    fwrite(STDERR, "✗ No se pudo conectar con MySQL. Enciéndelo (Linux: sudo /opt/lampp/lampp startmysql; Windows: panel de XAMPP).\n");
    exit(2);
}

$archivo = respaldoValido($argumentos[0]);
if ($archivo === null) {
    fwrite(STDERR, "✗ «{$argumentos[0]}» no es un respaldo de respaldos/ hecho por estas herramientas.\n");
    exit(2);
}

$base = DB_NOMBRE;
$actual = contarFilas($base);
$respaldoActual = $actual === null ? null : respaldarBase($base, 'antes-de-restaurar');
if ($respaldoActual !== null) {
    echo "Respaldo de lo que hay ahora: $respaldoActual\n\n";
}

$descripcion = $actual === null ? 'no existe' : sprintf('%d dispositivos, %d monitoreos, %d alertas', $actual['dispositivos'], $actual['monitoreos'], $actual['alertas']);
if (!pedirConfirmacion("La base $base ($descripcion) se reemplazará por " . basename($archivo) . '.')) {
    echo "Cancelado: la base $base no cambió.\n";
    exit(1);
}

recrearBase($base, [$archivo]);
$despues = contarFilas($base);
printf("\n✓ Base %s restaurada: %d dispositivos, %d monitoreos, %d alertas.\n", $base, $despues['dispositivos'], $despues['monitoreos'], $despues['alertas']);
exit(0);

/**
 * Lista los respaldos, del más reciente al más antiguo.
 */
function listarRespaldos(): void
{
    $archivos = is_dir(CARPETA_RESPALDOS) ? glob(CARPETA_RESPALDOS . '/*.sql') : [];
    rsort($archivos);

    if ($archivos === []) {
        echo "No hay respaldos en respaldos/.\n";
        return;
    }

    echo "Respaldos (del más reciente al más antiguo):\n";
    foreach ($archivos as $archivo) {
        printf("  %s  (%d KB)\n", basename($archivo), (int) ceil(filesize($archivo) / 1024));
    }
    echo "\nPara restaurar uno: php herramientas/restaurar-respaldo.php ARCHIVO\n";
}

/**
 * Ruta del respaldo si está en respaldos/, es un volcado completo de mysqldump y
 * no cambia de base (sin CREATE DATABASE ni USE); si no, null.
 */
function respaldoValido(string $nombre): ?string
{
    $carpeta = realpath(CARPETA_RESPALDOS);
    $ruta = $carpeta === false ? false : realpath($carpeta . DIRECTORY_SEPARATOR . basename($nombre));

    if ($ruta === false || !str_ends_with($ruta, '.sql') || dirname($ruta) !== $carpeta) {
        return null;
    }

    $contenido = (string) file_get_contents($ruta);

    return str_contains($contenido, '-- Dump completed') && preg_match('/^\s*(CREATE\s+DATABASE|USE\s)/mi', $contenido) !== 1
        ? $ruta : null;
}
