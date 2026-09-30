<?php
/**
 * Servicio de Dispositivos: registro y consulta de dispositivos de red.
 * Dueño de la tabla dispositivos.
 * Punto de entrada: todas las rutas del servicio llegan aquí por .htaccess.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/../comun/auth.php';
require_once __DIR__ . '/../comun/enrutador.php';
require_once __DIR__ . '/endpoints.php';

const NOMBRE_SERVICIO = 'dispositivos';

const RUTAS_DISPOSITIVOS = [
    ['GET', '', 'listarDispositivos', ACCESO_INTERFAZ],
    ['POST', '', 'crearDispositivo', ACCESO_INTERFAZ],
    ['GET', '{id}', 'obtenerDispositivo', ACCESO_INTERFAZ],
    ['PUT', '{id}', 'actualizarDispositivo', ACCESO_INTERFAZ],
    ['DELETE', '{id}', 'eliminarDispositivo', ACCESO_INTERFAZ],
    ['PATCH', '{id}/estado', 'actualizarEstadoDispositivo', ACCESO_INTERNO],
];

configurarErrores();

try {
    despachar(RUTAS_DISPOSITIVOS, obtenerMetodo(), obtenerSegmentosRuta());
} catch (Throwable $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e);
    responderError('Error interno del servidor', 500);
}
