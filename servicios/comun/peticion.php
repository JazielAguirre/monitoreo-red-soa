<?php
/**
 * Lectura de la petición HTTP: método, segmentos de la ruta y cuerpo JSON.
 * Solo infraestructura; las reglas de cada servicio viven en su carpeta.
 */

declare(strict_types=1);

require_once __DIR__ . '/respuesta.php';

/**
 * Devuelve el método HTTP en mayúsculas.
 */
function obtenerMetodo(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Devuelve los segmentos de la ruta que siguen a la carpeta del servicio.
 * Ejemplo: /monitoreo-red-soa/servicios/dispositivos/5/estado → ["5", "estado"]
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
 * Lee el cuerpo de la petición como un objeto JSON y lo devuelve como arreglo
 * asociativo. Responde 400 si está vacío, no es JSON válido o no es un objeto.
 */
function leerCuerpoJson(): array
{
    $texto = file_get_contents('php://input');

    try {
        $valor = json_decode($texto === false ? '' : $texto, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        responderError('El cuerpo de la petición no es JSON válido', 400);
    }

    if (!$valor instanceof stdClass) {
        responderError('El cuerpo de la petición debe ser un objeto JSON', 400);
    }

    return json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
}
