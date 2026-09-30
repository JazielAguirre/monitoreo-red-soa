<?php
/**
 * Verificación de acceso (docs/API.md, "Acceso"): sesión de PHP para las
 * peticiones del navegador y cabecera X-Servicio-Clave para las peticiones
 * entre servicios. Solo infraestructura: los datos de los usuarios son del
 * Servicio de Autenticación, que es el único que inicia y cierra sesiones.
 *
 * - ACCESO_INTERFAZ: sesión activa o clave de servicio válida; si no, 401.
 * - ACCESO_INTERNO: solo clave de servicio; sin ella, 403 aunque haya sesión.
 * - ACCESO_PUBLICO: sin comprobación (login, logout y sesión deciden solos).
 * Una X-Servicio-Clave presente pero incorrecta responde 403 en cualquier endpoint.
 *
 * La sesión se lee con read_and_close: se cierra al leerla y no bloquea las
 * peticiones en paralelo del mismo navegador (PHP bloquea el archivo de la
 * sesión mientras está abierta). Solo el login y el logout escriben en ella.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/respuesta.php';

const ACCESO_INTERFAZ = 'interfaz';
const ACCESO_INTERNO = 'interno';
const ACCESO_PUBLICO = 'publico';

const CABECERA_CLAVE_SERVICIO = 'HTTP_X_SERVICIO_CLAVE';

/**
 * Responde 401 o 403 si la petición no tiene el acceso que pide la ruta.
 */
function verificarAcceso(string $acceso): void
{
    if ($acceso === ACCESO_PUBLICO) {
        return;
    }

    $clave = $_SERVER[CABECERA_CLAVE_SERVICIO] ?? null;

    if ($clave !== null && !claveDeServicioValida($clave)) {
        responderError('Solo otros servicios pueden usar este endpoint', 403);
    }

    if ($acceso === ACCESO_INTERNO) {
        if ($clave === null) {
            responderError('Solo otros servicios pueden usar este endpoint', 403);
        }
        return;
    }

    if ($clave === null && leerUsuarioDeLaSesion() === null) {
        responderError('Tu sesión terminó. Inicia sesión para continuar.', 401);
    }
}

/**
 * Compara la cabecera con la clave de config.php en tiempo constante.
 */
function claveDeServicioValida(mixed $clave): bool
{
    return is_string($clave) && hash_equals(CLAVE_SERVICIO, $clave);
}

/**
 * Parámetros de la sesión: cookie propia, HttpOnly, SameSite=Lax, limitada a la
 * carpeta del proyecto y con la misma vida que la sesión (8 h).
 * Debe llamarse antes de cualquier session_start().
 */
function configurarSesion(): void
{
    ini_set('session.use_strict_mode', '1'); // un id que no creó el servidor no se acepta
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) DURACION_SESION);
    session_name(NOMBRE_COOKIE_SESION);
    session_set_cookie_params([
        'lifetime' => DURACION_SESION,
        'path' => RUTA_COOKIE_SESION,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false, // la aplicación se usa por HTTP en la red local
    ]);
}

/**
 * Devuelve el usuario de la sesión activa ({ id, usuario, nombre }) o null.
 * Solo lee: la sesión se cierra en el acto y nunca se envía una cookie nueva.
 */
function leerUsuarioDeLaSesion(): ?array
{
    $id = idDeSesionDeLaCookie();

    if ($id === null) {
        return null;
    }

    configurarSesion();
    ini_set('session.use_cookies', '0'); // leer no debe crear ni renovar cookies
    session_id($id);
    session_start(['read_and_close' => true]);

    return usuarioVigente($_SESSION);
}

/**
 * El usuario guardado en la sesión, si la sesión no ha expirado.
 */
function usuarioVigente(array $sesion): ?array
{
    $usuario = $sesion['usuario'] ?? null;
    $expira = $sesion['expira'] ?? 0;

    return is_array($usuario) && is_int($expira) && $expira > time() ? $usuario : null;
}

/**
 * El id de sesión de la cookie, o null si no hay cookie o su valor no puede ser
 * un id de PHP. Un valor con otros caracteres haría que session_start() lanzara
 * un aviso (que aquí sería un 500), así que se descarta antes.
 */
function idDeSesionDeLaCookie(): ?string
{
    $id = $_COOKIE[NOMBRE_COOKIE_SESION] ?? null;

    return is_string($id) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $id) === 1 ? $id : null;
}
