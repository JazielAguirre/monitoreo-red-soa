<?php
/**
 * Servicio de Monitoreo: ejecuta ping a los dispositivos y guarda el historial.
 * Dueño de la tabla monitoreos. Coordina a Dispositivos y Alertas solo por HTTP.
 * Punto de entrada: todas las rutas del servicio llegan aquí por .htaccess.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/../comun/auth.php';
require_once __DIR__ . '/../comun/enrutador.php';
require_once __DIR__ . '/../comun/http.php';
require_once __DIR__ . '/endpoints.php';

const NOMBRE_SERVICIO = 'monitoreo';

const RUTAS_MONITOREO = [
    ['GET', '', 'listarMonitoreos', ACCESO_INTERFAZ],
    ['GET', 'recientes', 'listarRecientes', ACCESO_INTERFAZ],
    ['POST', '{id}', 'monitorearDispositivo', ACCESO_INTERFAZ],
];

configurarErrores();

try {
    despachar(RUTAS_MONITOREO, obtenerMetodo(), obtenerSegmentosRuta());
} catch (ErrorDeComunicacion $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e->getMessage());
    responderError('No se pudo comunicar con el Servicio de ' . $e->servicio, 502);
} catch (Throwable $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e);
    responderError('Error interno del servidor', 500);
}
