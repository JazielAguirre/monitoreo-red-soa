<?php
/**
 * Llamadas HTTP del Servicio de Descubrimiento a otros servicios (regla SOA:
 * Descubrimiento no tiene tablas y nunca consulta la de dispositivos).
 * Cualquier respuesta inesperada → ErrorDeComunicacion (502).
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/http.php';

/**
 * GET /dispositivos. Devuelve [ip => ['id' => int, 'nombre' => string]] de los
 * dispositivos registrados.
 */
function obtenerDispositivosRegistrados(): array
{
    $respuesta = llamarServicio('Dispositivos', 'GET', URL_DISPOSITIVOS);

    if ($respuesta['codigo'] !== 200 || !$respuesta['ok'] || !is_array($respuesta['data'])) {
        throw new ErrorDeComunicacion('Dispositivos', "GET /dispositivos respondió {$respuesta['codigo']}: " . ($respuesta['error'] ?? 'sin lista'));
    }

    $registrados = [];
    foreach ($respuesta['data'] as $dispositivo) {
        $esValido = is_array($dispositivo) && is_int($dispositivo['id'] ?? null)
            && is_string($dispositivo['nombre'] ?? null) && is_string($dispositivo['ip'] ?? null);

        if (!$esValido) {
            throw new ErrorDeComunicacion('Dispositivos', 'GET /dispositivos devolvió un dispositivo sin id, nombre o ip');
        }
        $registrados[$dispositivo['ip']] = ['id' => $dispositivo['id'], 'nombre' => $dispositivo['nombre']];
    }

    return $registrados;
}
