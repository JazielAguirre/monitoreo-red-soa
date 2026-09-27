<?php
/**
 * Servicio de Dispositivos: registro y consulta de dispositivos de red.
 * Dueño de la tabla dispositivos.
 * Punto de entrada: todas las rutas del servicio llegan aquí por .htaccess.
 * Fase 0: solo responde con el servicio, el método y los segmentos de la ruta.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';

const NOMBRE_SERVICIO = 'dispositivos';

/**
 * Devuelve los segmentos de la ruta que siguen a la carpeta del servicio.
 * Ejemplo: /monitoreo-red-soa/servicios/dispositivos/5 → ["5"]
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

try {
    responder([
        'servicio' => NOMBRE_SERVICIO,
        'metodo' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'ruta' => obtenerSegmentosRuta(),
    ]);
} catch (Throwable $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e);
    responderError('Error interno del servidor', 500);
}
