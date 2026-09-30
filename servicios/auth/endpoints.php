<?php
/**
 * Endpoints del Servicio de Autenticación (docs/API.md, "Autenticación").
 * Son los únicos que escriben en la sesión: el login la crea y el logout la borra.
 * Los demás servicios solo la leen, con leerUsuarioDeLaSesion() de comun/auth.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/../comun/auth.php';
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/validacion.php';

const MENSAJE_CREDENCIALES_INCORRECTAS = 'Usuario o contraseña incorrectos';

/**
 * Hash bcrypt de un texto aleatorio que nadie conoce, con el mismo costo que los
 * reales. Si el usuario no existe, password_verify() se ejecuta contra este hash:
 * así la respuesta tarda lo mismo y no revela qué usuarios existen.
 */
const HASH_FICTICIO = '$2y$10$77xnEUCH0o8UgXpDVTN4PeYTI8lLQzWlHjgaYMXqzr9LsvFC9mY0S';

/**
 * POST /auth/login: inicia la sesión con usuario y contraseña.
 * Siempre el mismo mensaje si falla, exista o no el usuario.
 */
function iniciarSesion(): never
{
    $validacion = validarLogin(leerCuerpoJson());

    if ($validacion['errores'] !== []) {
        responderError('Escribe tu usuario y tu contraseña', 400, $validacion['errores']);
    }

    ['usuario' => $usuario, 'contrasena' => $contrasena] = $validacion['datos'];
    $posibles = credencialesPosibles($usuario, $contrasena);
    $fila = $posibles ? consultarUsuarioPorNombre($usuario) : null;

    // password_verify() se ejecuta siempre, también sin usuario, para igualar el tiempo.
    $coincide = password_verify($contrasena, $fila['contrasena_hash'] ?? HASH_FICTICIO);

    if ($fila === null || !$coincide) {
        responderError(MENSAJE_CREDENCIALES_INCORRECTAS, 401);
    }

    $usuarioSesion = formatearUsuario($fila);
    guardarSesion($usuarioSesion);

    responder(['usuario' => $usuarioSesion]);
}

/**
 * POST /auth/logout: cierra la sesión. Responde 200 también si no había.
 */
function cerrarSesion(): never
{
    configurarSesion();

    if (idDeSesionDeLaCookie() !== null) {
        session_start();
        $_SESSION = [];
        session_destroy();
    }
    if (isset($_COOKIE[NOMBRE_COOKIE_SESION])) {
        borrarCookieDeSesion();
    }

    responder(null);
}

/**
 * GET /auth/sesion: el usuario de la sesión activa, o 401.
 */
function consultarSesion(): never
{
    $usuario = leerUsuarioDeLaSesion();

    if ($usuario === null) {
        responderError('No hay una sesión activa', 401);
    }

    responder(['usuario' => $usuario]);
}

/**
 * Crea la sesión con un id nuevo (contra la fijación de sesión) y la cierra
 * de inmediato para no bloquear otras peticiones.
 */
function guardarSesion(array $usuario): void
{
    if (idDeSesionDeLaCookie() === null) {
        unset($_COOKIE[NOMBRE_COOKIE_SESION]); // un valor inválido: se empieza una sesión nueva
    }
    configurarSesion();
    session_start();
    header_remove('Set-Cookie'); // la cookie que vale es la del id nuevo, no la de session_start()
    session_regenerate_id(true);
    $_SESSION = ['usuario' => $usuario, 'expira' => time() + DURACION_SESION];
    session_write_close();
}

/**
 * Pide al navegador que borre la cookie, con los mismos parámetros con que se creó.
 */
function borrarCookieDeSesion(): void
{
    $parametros = session_get_cookie_params();
    setcookie(NOMBRE_COOKIE_SESION, '', [
        'expires' => time() - 3600,
        'path' => $parametros['path'],
        'httponly' => $parametros['httponly'],
        'samesite' => $parametros['samesite'],
        'secure' => $parametros['secure'],
    ]);
}
