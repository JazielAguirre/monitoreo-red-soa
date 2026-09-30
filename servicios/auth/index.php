<?php
/**
 * Servicio de Autenticación: login, logout y sesión actual.
 * Dueño de la tabla usuarios.
 * Punto de entrada: todas las rutas del servicio llegan aquí por .htaccess.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/../comun/auth.php';
require_once __DIR__ . '/../comun/enrutador.php';
require_once __DIR__ . '/endpoints.php';

const NOMBRE_SERVICIO = 'auth';

// Las tres rutas son públicas: cada endpoint decide qué responder sin sesión.
const RUTAS_AUTH = [
    ['POST', 'login', 'iniciarSesion', ACCESO_PUBLICO],
    ['POST', 'logout', 'cerrarSesion', ACCESO_PUBLICO],
    ['GET', 'sesion', 'consultarSesion', ACCESO_PUBLICO],
];

configurarErrores();

try {
    despachar(RUTAS_AUTH, obtenerMetodo(), obtenerSegmentosRuta());
} catch (Throwable $e) {
    error_log('[' . NOMBRE_SERVICIO . '] ' . $e);
    responderError('Error interno del servidor', 500);
}
