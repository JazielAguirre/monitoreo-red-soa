<?php
/**
 * Llamadas HTTP del Servicio de Monitoreo a los otros servicios (regla SOA:
 * Monitoreo nunca consulta las tablas dispositivos ni alertas).
 * Un 404 de otro servicio significa que el dispositivo no existe → 404.
 * Cualquier otra respuesta inesperada → ErrorDeComunicacion (502).
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/http.php';
require_once __DIR__ . '/../comun/respuesta.php';

const MENSAJE_DISPOSITIVO_NO_EXISTE = 'El dispositivo no existe';

/**
 * Paso 1: GET /dispositivos/{id}. Devuelve el dispositivo (con ip y nombre).
 */
function obtenerDispositivoRemoto(int $id): array
{
    $respuesta = llamarServicio('Dispositivos', 'GET', URL_DISPOSITIVOS . "/$id");
    responder404SiNoExiste($respuesta);
    exigirCodigo($respuesta, [200], 'Dispositivos', "GET /dispositivos/$id");

    $dispositivo = $respuesta['data'];
    $esValido = is_array($dispositivo) && is_string($dispositivo['nombre'] ?? null)
        && filter_var($dispositivo['ip'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

    if (!$esValido) {
        throw new ErrorDeComunicacion('Dispositivos', "GET /dispositivos/$id devolvió un dispositivo sin ip o nombre válidos");
    }

    return $dispositivo;
}

/**
 * Paso 4: PATCH /dispositivos/{id}/estado con el mismo estado, tiempo y fecha del monitoreo.
 */
function informarEstadoDispositivo(int $id, string $estado, ?float $tiempoMs, string $fecha): void
{
    $respuesta = llamarServicio('Dispositivos', 'PATCH', URL_DISPOSITIVOS . "/$id/estado", [
        'estado' => $estado,
        'tiempo_respuesta_ms' => $tiempoMs,
        'fecha' => $fecha,
    ]);
    responder404SiNoExiste($respuesta);
    exigirCodigo($respuesta, [200], 'Dispositivos', "PATCH /dispositivos/$id/estado");
}

/**
 * Paso 5: POST /alertas. Devuelve true si se creó una alerta nueva, false si
 * el dispositivo ya tenía una pendiente. La fecha de la alerta la genera Alertas.
 */
function crearAlertaRemota(int $dispositivoId, string $nombre): bool
{
    $respuesta = llamarServicio('Alertas', 'POST', URL_ALERTAS, [
        'dispositivo_id' => $dispositivoId,
        'tipo' => 'sin_respuesta',
        'mensaje' => "El dispositivo $nombre no respondió al monitoreo.",
    ]);
    responder404SiNoExiste($respuesta);
    exigirCodigo($respuesta, [200, 201], 'Alertas', 'POST /alertas');

    $creada = $respuesta['data']['creada'] ?? null;

    if (!is_bool($creada)) {
        throw new ErrorDeComunicacion('Alertas', 'POST /alertas respondió sin el campo creada');
    }

    return $creada;
}

function responder404SiNoExiste(array $respuesta): void
{
    if ($respuesta['codigo'] === 404) {
        responderError(MENSAJE_DISPOSITIVO_NO_EXISTE, 404);
    }
}

/**
 * Lanza ErrorDeComunicacion si el código no es uno de los esperados o la respuesta no es ok.
 */
function exigirCodigo(array $respuesta, array $esperados, string $servicio, string $operacion): void
{
    if (!$respuesta['ok'] || !in_array($respuesta['codigo'], $esperados, true)) {
        throw new ErrorDeComunicacion(
            $servicio,
            "$operacion respondió {$respuesta['codigo']}: " . ($respuesta['error'] ?? 'sin mensaje')
        );
    }
}
