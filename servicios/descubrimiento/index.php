<?php
/**
 * Servicio de Descubrimiento: busca los equipos conectados a la red local de
 * esta computadora (ping en paralelo y tabla ARP). No tiene tablas; para saber
 * qué IPs ya están registradas le pregunta al Servicio de Dispositivos por HTTP.
 * Punto de entrada: todas las rutas del servicio llegan aquí por .htaccess.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/../comun/auth.php';
require_once __DIR__ . '/../comun/enrutador.php';
require_once __DIR__ . '/../comun/http.php';
require_once __DIR__ . '/endpoints.php';

const NOMBRE_SERVICIO = 'descubrimiento';

const RUTAS_DESCUBRIMIENTO = [
    ['GET', 'red', 'consultarRed', ACCESO_INTERFAZ],
    ['POST', 'red/lotes/{id}', 'escanearLote', ACCESO_INTERFAZ],
];

configurarErrores();

try {
    despachar(RUTAS_DESCUBRIMIENTO, obtenerMetodo(), obtenerSegmentosRuta());
} catch (ErrorDeComunicacion $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e->getMessage());
    responderError('No se pudo comunicar con el Servicio de ' . $e->servicio, 502);
} catch (Throwable $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e);
    responderError('Error interno del servidor', 500);
}
