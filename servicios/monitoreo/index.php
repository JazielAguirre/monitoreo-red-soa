<?php
/**
 * Servicio de Monitoreo: ejecuta ping a los dispositivos y guarda el historial.
 * Dueño de la tabla monitoreos.
 * Punto de entrada: todas las rutas del servicio llegan aquí por .htaccess.
 * Fase 0: responde con el servicio, el método y los segmentos de la ruta, y
 * expone la ruta temporal GET /prueba?ip=... para probar ping.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/ping.php';

const NOMBRE_SERVICIO = 'monitoreo';

/**
 * Devuelve los segmentos de la ruta que siguen a la carpeta del servicio.
 * Ejemplo: /monitoreo-red-soa/servicios/monitoreo/5 → ["5"]
 */
function obtenerSegmentosRuta(): array
{
    $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    if (str_starts_with($ruta, $base)) {
        $ruta = substr($ruta, strlen($base));
    }

    $segmentos = array_filter(explode('/', $ruta), fn(string $s): bool => $s !== '');

    return array_values(array_map('rawurldecode', $segmentos));
}

/**
 * TEMPORAL (Fase 0): GET /servicios/monitoreo/prueba?ip=...
 * Hace ping a la IP y responde el resultado. Se elimina en la Fase 4.
 */
function atenderPruebaPing(string $metodo): never
{
    if ($metodo !== 'GET') {
        responderError('Método no permitido', 405);
    }

    $ip = $_GET['ip'] ?? '';

    if (!is_string($ip) || trim($ip) === '') {
        responderError('Falta el parámetro ip', 400);
    }

    $ip = trim($ip);

    if (!esIpv4Valida($ip)) {
        responderError('La IP no es una dirección IPv4 válida', 400);
    }

    $resultado = hacerPing($ip);

    responder([
        'ip' => $ip,
        'estado' => $resultado['responde'] ? 'activo' : 'inactivo',
        'tiempo_respuesta_ms' => $resultado['tiempo_respuesta_ms'],
        'salida' => $resultado['salida'],
    ]);
}

try {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $ruta = obtenerSegmentosRuta();

    // TEMPORAL (Fase 0): se elimina en la Fase 4 junto con atenderPruebaPing().
    if ($ruta === ['prueba']) {
        atenderPruebaPing($metodo);
    }

    responder([
        'servicio' => NOMBRE_SERVICIO,
        'metodo' => $metodo,
        'ruta' => $ruta,
    ]);
} catch (Throwable $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e);
    responderError('Error interno del servidor', 500);
}
