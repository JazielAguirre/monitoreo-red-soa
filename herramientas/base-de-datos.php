<?php
/**
 * Herramienta de base de datos compartida por las pruebas (pruebas/ayudas.php) y la
 * preparación de la demo (herramientas/preparar-demo.php): recrea la base con
 * database/schema.sql y database/seed.sql usando el cliente mysql de XAMPP.
 *
 * herramientas/ es, como schema.sql y seed.sql, una herramienta de base de datos:
 * puede tocar todas las tablas. La regla de que cada servicio solo toca las suyas
 * sigue valiendo para servicios/ (CLAUDE.md, "Reglas SOA").
 *
 * Solo línea de comandos. La carpeta no es accesible por HTTP (.htaccess de la raíz).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../servicios/comun/config.php';

/**
 * Carga schema.sql (borra y recrea la base) y seed.sql. Termina con código 2 si falla.
 * Se puede indicar otro binario de mysql con la variable de entorno MYSQL_BIN.
 */
function cargarSchemaYSeed(): void
{
    $raiz = dirname(__DIR__);

    foreach (['database/schema.sql', 'database/seed.sql'] as $archivo) {
        $baseDatos = $archivo === 'database/seed.sql' ? ' ' . escapeshellarg(DB_NOMBRE) : '';
        $comando = comandoMysql() . $baseDatos . ' < ' . escapeshellarg("$raiz/$archivo") . ' 2>&1';

        exec($comando, $salida, $codigo);

        if ($codigo !== 0) {
            fwrite(STDERR, "No se pudo cargar $archivo:\n" . implode("\n", $salida) . "\n");
            exit(2);
        }
    }
}

/**
 * Arma el comando del cliente mysql con los datos de config.php.
 */
function comandoMysql(): string
{
    $binario = getenv('MYSQL_BIN') ?: (PHP_OS_FAMILY === 'Windows'
        ? 'C:/xampp/mysql/bin/mysql.exe'
        : '/opt/lampp/bin/mysql');

    $comando = escapeshellarg($binario)
        . ' --default-character-set=utf8mb4'
        . ' -h ' . escapeshellarg(DB_HOST)
        . ' -P ' . (int) DB_PUERTO
        . ' -u ' . escapeshellarg(DB_USUARIO);

    if (DB_CLAVE !== '') {
        $comando .= ' -p' . escapeshellarg(DB_CLAVE);
    }

    return $comando;
}
