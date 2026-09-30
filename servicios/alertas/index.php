<?php
/**
 * Servicio de Alertas: alertas generadas cuando un dispositivo no responde.
 * Dueño de la tabla alertas.
 * Punto de entrada: todas las rutas del servicio llegan aquí por .htaccess.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/../comun/auth.php';
require_once __DIR__ . '/../comun/enrutador.php';
require_once __DIR__ . '/endpoints.php';

const NOMBRE_SERVICIO = 'alertas';

const RUTAS_ALERTAS = [
    ['GET', '', 'listarAlertas', ACCESO_INTERFAZ],
    ['POST', '', 'crearAlerta', ACCESO_INTERNO],
    ['GET', 'conteo', 'contarAlertasPorEstado', ACCESO_INTERFAZ],
    ['PATCH', '{id}/atender', 'atenderAlerta', ACCESO_INTERFAZ],
];

configurarErrores();

try {
    despachar(RUTAS_ALERTAS, obtenerMetodo(), obtenerSegmentosRuta());
} catch (Throwable $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e);
    responderError('Error interno del servidor', 500);
}
