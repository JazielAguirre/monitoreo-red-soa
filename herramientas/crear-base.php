<?php
/**
 * Crea la base de uso diario (monitoreo_red_soa) vacía: las tablas de schema.sql y
 * el usuario admin de inicial.sql, sin dispositivos, monitoreos ni alertas.
 *
 *   /opt/lampp/bin/php herramientas/crear-base.php                crea la base si no existe
 *   /opt/lampp/bin/php herramientas/crear-base.php --reemplazar   la vacía: respalda y pide confirmación
 *   (Windows: C:/xampp/php/php.exe herramientas/crear-base.php …)
 *
 * Sin --reemplazar nunca borra nada: si la base ya existe, la deja como está.
 * Con --reemplazar, primero guarda un respaldo en respaldos/ y pide escribir SI;
 * para volver atrás: herramientas/restaurar-respaldo.php.
 *
 * Termina con código 0 si la base quedó lista (o ya existía), 1 si se canceló y 2
 * si hubo un error.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/base-de-datos.php';

$argumentos = array_slice($argv, 1);
if (array_diff($argumentos, ['--reemplazar']) !== []) {
    fwrite(STDERR, "Uso: php herramientas/crear-base.php [--reemplazar]\n");
    exit(2);
}
$reemplazar = in_array('--reemplazar', $argumentos, true);

if (!mysqlResponde()) {
    fwrite(STDERR, "✗ No se pudo conectar con MySQL. Enciéndelo (Linux: sudo /opt/lampp/lampp startmysql; Windows: panel de XAMPP).\n");
    exit(2);
}

$base = nombreDeLaBase(); // la del usuario (o la de pruebas, solo mientras corren las suites)

if (crearBaseInicial($base)) {
    echo "✓ Base $base creada: sin dispositivos, monitoreos ni alertas. Usuario admin / admin123.\n";
    exit(0);
}

$conteos = contarFilas($base);
$resumen = describirConteos($conteos);

if (!$reemplazar) {
    echo "La base $base ya existe ($resumen): no se tocó.\n";
    echo "Para vaciarla y empezar de cero: php herramientas/crear-base.php --reemplazar (respalda y pide confirmación).\n";
    exit(0);
}

$respaldo = respaldarBase($base, 'antes-de-vaciar');
echo "Respaldo guardado: $respaldo\n\n";

if (!pedirConfirmacion("Se borrará todo lo que tiene la base $base ($resumen) y quedará vacía.")) {
    echo "Cancelado: la base $base no cambió.\n";
    exit(1);
}

recrearBase($base, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL]);
echo "\n✓ La base $base quedó vacía: sin dispositivos, monitoreos ni alertas. Usuario admin / admin123.\n";
echo "Para volver atrás: php herramientas/restaurar-respaldo.php " . basename($respaldo) . "\n";
exit(0);

/**
 * "3 dispositivos, 40 monitoreos, 2 alertas"
 */
function describirConteos(?array $conteos): string
{
    if ($conteos === null) {
        return 'no existe';
    }

    return sprintf('%d dispositivos, %d monitoreos, %d alertas', $conteos['dispositivos'], $conteos['monitoreos'], $conteos['alertas']);
}
