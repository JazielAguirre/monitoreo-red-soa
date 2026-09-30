<?php
/**
 * Pruebas del Servicio de Autenticación y de la protección de los servicios
 * (docs/PLAN.md, Fase 7; docs/API.md, "Acceso" y "Autenticación").
 * Recarga la base de datos al empezar y la restaura al terminar.
 *
 *   /opt/lampp/bin/php pruebas/auth.php
 */

declare(strict_types=1);

require_once __DIR__ . '/ayudas.php';
require_once __DIR__ . '/../servicios/comun/auth.php';

const USUARIO_ADMIN = ['id' => 1, 'usuario' => 'admin', 'nombre' => 'Administrador'];
const CREDENCIALES_ADMIN = ['usuario' => 'admin', 'contrasena' => 'admin123'];
const MENSAJE_CREDENCIALES = 'Usuario o contraseña incorrectos';
const MENSAJE_SIN_SESION_ACTIVA = 'No hay una sesión activa';
const INTENTOS_TIEMPO = 21;

echo "Pruebas del Servicio de Autenticación\n";
echo "Recargando la base de datos (schema.sql + seed.sql)...\n";
recargarBaseDeDatos();
quitarFalloSimulado();
register_shutdown_function('quitarFalloSimulado');

/**
 * Inicia sesión y devuelve la respuesta completa (sin usar la cookie guardada).
 */
function login(array $cuerpo, string|bool $cookie = false): array
{
    // Un arreglo vacío se codificaría como [] (arreglo JSON): se envía {}.
    return peticion('POST', '/auth/login', $cuerpo === [] ? '{}' : $cuerpo, ['sesion' => $cookie]);
}

/**
 * Cabecera Cookie con un id de sesión.
 */
function cookieCon(string $id): string
{
    return NOMBRE_COOKIE_SESION . "=$id";
}

/**
 * Atributos de una Set-Cookie: ['valor' => ..., 'path' => ..., 'httponly' => true, ...].
 */
function atributosCookie(string $cookie): array
{
    $partes = array_map('trim', explode(';', $cookie));
    [$nombre, $valor] = array_pad(explode('=', array_shift($partes), 2), 2, '');
    $atributos = ['nombre' => $nombre, 'valor' => $valor];

    foreach ($partes as $parte) {
        [$clave, $dato] = array_pad(explode('=', $parte, 2), 2, true);
        $atributos[strtolower($clave)] = $dato;
    }

    return $atributos;
}

/**
 * Mediana de una lista de números.
 */
function mediana(array $valores): float
{
    sort($valores);
    $mitad = intdiv(count($valores), 2);

    return count($valores) % 2 === 1 ? $valores[$mitad] : ($valores[$mitad - 1] + $valores[$mitad]) / 2;
}

// =====================================================================
seccion('1. POST /auth/login correcto: datos del usuario y cookie de sesión');

$r = login(CREDENCIALES_ADMIN);
comprobarRespuesta($r, 200, 'POST /auth/login con admin / admin123');
comprobarIgual(['usuario' => USUARIO_ADMIN], $r['json']['data'] ?? null, '  data = { usuario: { id, usuario, nombre } }, sin el hash');
comprobarIgual(1, count($r['cookies']), '  una sola Set-Cookie', implode(' | ', $r['cookies']));
$cookie = atributosCookie($r['cookies'][0] ?? '');
comprobarIgual(NOMBRE_COOKIE_SESION, $cookie['nombre'], '  con el nombre propio ' . NOMBRE_COOKIE_SESION);
comprobarIgual(RUTA_COOKIE_SESION, $cookie['path'] ?? null, '  Path=' . RUTA_COOKIE_SESION . ' (solo viaja a este proyecto)');
comprobarIgual(true, $cookie['httponly'] ?? false, '  HttpOnly (JavaScript no puede leerla)');
comprobarIgual('Lax', $cookie['samesite'] ?? null, '  SameSite=Lax');
comprobarIgual((string) DURACION_SESION, $cookie['max-age'] ?? null, '  Max-Age = 8 horas (' . DURACION_SESION . ' s)');
$expira = strtotime((string) ($cookie['expires'] ?? ''));
comprobar(is_int($expira) && abs($expira - (time() + DURACION_SESION)) < 60, '  Expires dentro de 8 horas', (string) ($cookie['expires'] ?? '(ninguno)'));
$idSesion = $r['cookie'];

$gc = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export(__DIR__ . '/../servicios/comun/auth.php', true) . '; configurarSesion(); echo ini_get("session.gc_maxlifetime");'
)));
comprobarIgual((string) DURACION_SESION, $gc, 'configurarSesion() fija session.gc_maxlifetime en 8 horas');
comprobarIgual(null, usuarioVigente(['usuario' => USUARIO_ADMIN, 'expira' => time() - 1]), 'una sesión cuya hora de expiración ya pasó no vale (usuarioVigente)');
comprobarIgual(USUARIO_ADMIN, usuarioVigente(['usuario' => USUARIO_ADMIN, 'expira' => time() + 60]), '  y una vigente, sí');

// =====================================================================
seccion('2. GET /auth/sesion');

$r = peticion('GET', '/auth/sesion', null, ['sesion' => cookieCon($idSesion)]);
comprobarRespuesta($r, 200, 'GET /auth/sesion con la cookie del login');
comprobarIgual(['usuario' => USUARIO_ADMIN], $r['json']['data'] ?? null, '  el mismo data que el login');
comprobarIgual([], $r['cookies'], '  leer la sesión no envía cookies');
comprobarRespuesta(peticion('GET', '/auth/sesion', null, ['sesion' => false]), 401, 'sin cookie', MENSAJE_SIN_SESION_ACTIVA);
comprobarRespuesta(peticion('GET', '/auth/sesion', null, ['sesion' => cookieCon('inventada0123456789abcdef')]), 401, 'con un id que el servidor no creó', MENSAJE_SIN_SESION_ACTIVA);
comprobarRespuesta(peticion('GET', '/auth/sesion', null, ['sesion' => cookieCon('../../etc/passwd')]), 401, 'con caracteres no válidos (no es un 500)', MENSAJE_SIN_SESION_ACTIVA);
$r = peticion('GET', '/dispositivos', null, ['sesion' => false]);
comprobarIgual([], $r['cookies'], 'una petición sin cookie no crea sesión ni envía cookies');

// =====================================================================
seccion('3. Credenciales incorrectas → siempre el mismo mensaje, sin cookie');

$casos = [
    'contraseña incorrecta' => ['usuario' => 'admin', 'contrasena' => 'admin124'],
    'usuario que no existe' => ['usuario' => 'noexiste', 'contrasena' => 'admin123'],
    'usuario de 51 caracteres' => ['usuario' => str_repeat('a', 51), 'contrasena' => 'admin123'],
    'contraseña de 73 bytes' => ['usuario' => 'admin', 'contrasena' => 'admin123' . str_repeat('x', 65)],
    'contraseña con espacios de más' => ['usuario' => 'admin', 'contrasena' => ' admin123 '],
];
foreach ($casos as $descripcion => $cuerpo) {
    $r = login($cuerpo);
    comprobarRespuesta($r, 401, $descripcion, MENSAJE_CREDENCIALES);
    comprobarIgual([], $r['cookies'], '  sin cookie de sesión');
}

// =====================================================================
seccion('4. Datos que faltan o no son texto → 400 con campos; métodos y rutas');

comprobarErrorCampo(login([]), 400, 'usuario', 'Escribe tu usuario', 'cuerpo {}');
comprobarErrorCampo(login([]), 400, 'contrasena', 'Escribe tu contraseña', '  y contrasena');
comprobarIgual('Escribe tu usuario y tu contraseña', login([])['json']['error'] ?? null, '  mensaje general');
comprobarErrorCampo(login(['usuario' => '   ', 'contrasena' => 'x']), 400, 'usuario', 'Escribe tu usuario', 'usuario solo con espacios');
comprobarIgual(['usuario'], array_keys(login(['usuario' => '   ', 'contrasena' => 'x'])['json']['campos'] ?? []), '  solo ese campo');
comprobarErrorCampo(login(['usuario' => 'admin', 'contrasena' => 123]), 400, 'contrasena', 'Escribe tu contraseña', 'contrasena que no es texto');
comprobarErrorCampo(login(['usuario' => ['admin'], 'contrasena' => 'admin123']), 400, 'usuario', 'Escribe tu usuario', 'usuario que no es texto');
comprobarRespuesta(peticion('POST', '/auth/login', '{roto', ['sesion' => false]), 400, 'JSON inválido', 'El cuerpo de la petición no es JSON válido');
comprobarRespuesta(peticion('POST', '/auth/login', '[1]', ['sesion' => false]), 400, 'un arreglo', 'El cuerpo de la petición debe ser un objeto JSON');
foreach ([['GET', '/auth/login', 'POST'], ['GET', '/auth/logout', 'POST'], ['POST', '/auth/sesion', 'GET']] as [$metodo, $ruta, $permitidos]) {
    $r = peticion($metodo, $ruta, null, ['sesion' => false]);
    comprobarRespuesta($r, 405, "$metodo $ruta", 'Método no permitido');
    comprobarIgual($permitidos, $r['cabeceras']['allow'] ?? null, "  Allow: $permitidos");
}
comprobarRespuesta(peticion('GET', '/auth/otra', null, ['sesion' => false]), 404, 'GET /auth/otra', 'La ruta no existe');
comprobarRespuesta(peticion('GET', '/auth', null, ['sesion' => false]), 404, 'GET /auth', 'La ruta no existe');

// =====================================================================
seccion('5. Fijación de sesión: el login siempre entrega un id nuevo');

$fijado = 'fijadoporunatacante0123456789';
$r = login(CREDENCIALES_ADMIN, cookieCon($fijado));
comprobar($r['codigo'] === 200 && $r['cookie'] !== null && $r['cookie'] !== $fijado, 'con una cookie inventada, el login responde con otro id', (string) $r['cookie']);
comprobarRespuesta(peticion('GET', '/auth/sesion', null, ['sesion' => cookieCon($fijado)]), 401, '  el id inventado no queda con sesión', MENSAJE_SIN_SESION_ACTIVA);
$anterior = $r['cookie'];
$r = login(CREDENCIALES_ADMIN, cookieCon($anterior));
comprobar($r['codigo'] === 200 && $r['cookie'] !== null && $r['cookie'] !== $anterior, 'con una sesión válida, iniciar sesión otra vez cambia el id (session_regenerate_id)');
comprobarRespuesta(peticion('GET', '/auth/sesion', null, ['sesion' => cookieCon($anterior)]), 401, '  y el id anterior deja de valer', MENSAJE_SIN_SESION_ACTIVA);
comprobarRespuesta(peticion('GET', '/auth/sesion', null, ['sesion' => cookieCon((string) $r['cookie'])]), 200, '  el nuevo sí vale');

// =====================================================================
seccion('6. Mismo tiempo de respuesta exista o no el usuario');

// Intercalados para que una racha de carga de la computadora afecte a los dos por igual.
$tiempos = ['existe' => [], 'no_existe' => []];
for ($i = 0; $i < INTENTOS_TIEMPO; $i++) {
    foreach (['existe' => 'admin', 'no_existe' => 'noexiste' . $i] as $caso => $usuario) {
        $inicio = hrtime(true);
        login(['usuario' => $usuario, 'contrasena' => 'incorrecta']);
        $tiempos[$caso][] = (hrtime(true) - $inicio) / 1e6;
    }
}
$existe = mediana($tiempos['existe']);
$noExiste = mediana($tiempos['no_existe']);
$hashReferencia = password_hash('referencia', PASSWORD_BCRYPT, ['cost' => 10]); // mismo costo que el seed
$bcrypt = mediana(array_map(function () use ($hashReferencia): float {
    $inicio = hrtime(true);
    password_verify('otra', $hashReferencia);
    return (hrtime(true) - $inicio) / 1e6;
}, range(1, 5)));
// Sin el hash ficticio, el usuario inexistente se ahorraría un password_verify() entero
// (~$bcrypt ms). El margen es la mitad de eso: detecta el atajo y tolera el ruido.
$diferencia = abs($existe - $noExiste);
comprobar($diferencia < max($bcrypt / 2, 15.0),
    sprintf('mediana de %d intentos: usuario existente %.1f ms, inexistente %.1f ms (diferencia %.1f ms; un password_verify tarda %.1f ms)', INTENTOS_TIEMPO, $existe, $noExiste, $diferencia, $bcrypt),
    'la diferencia supera la mitad de un password_verify');

// =====================================================================
seccion('7. Acceso: [Interfaz] → 401 sin sesión; [Interno] → 403 aunque haya sesión');

iniciarSesionDePrueba();
$interfaz = [
    ['GET', '/dispositivos'], ['POST', '/dispositivos'], ['GET', '/dispositivos/1'], ['PUT', '/dispositivos/1'], ['DELETE', '/dispositivos/1'],
    ['GET', '/alertas'], ['GET', '/alertas/conteo'], ['PATCH', '/alertas/4/atender'],
    ['GET', '/monitoreo'], ['GET', '/monitoreo/recientes'], ['POST', '/monitoreo/1'],
];
foreach ($interfaz as [$metodo, $ruta]) {
    comprobarRespuesta(peticion($metodo, $ruta, null, ['sesion' => false]), 401, "[Interfaz] $metodo $ruta sin sesión", MENSAJE_SIN_SESION);
}
comprobarIgual(6, count(peticion('GET', '/dispositivos')['json']['data'] ?? []), 'con sesión, los endpoints [Interfaz] responden (GET /dispositivos)');
foreach ([['PATCH', '/dispositivos/1/estado', ['estado' => 'activo', 'tiempo_respuesta_ms' => 1, 'fecha' => '2026-09-28 11:00:00']], ['POST', '/alertas', ['dispositivo_id' => 5, 'tipo' => 'sin_respuesta', 'mensaje' => 'Prueba']]] as [$metodo, $ruta, $cuerpo]) {
    comprobarRespuesta(peticion($metodo, $ruta, $cuerpo), 403, "[Interno] $metodo $ruta con sesión pero sin clave", MENSAJE_SOLO_SERVICIOS);
    $r = peticionInterna($metodo, $ruta, $cuerpo);
    comprobar(in_array($r['codigo'], [200, 201], true), '  con la clave de servicio, responde', "código {$r['codigo']}");
}
comprobarIgual(64, strlen(CLAVE_SERVICIO), 'la clave de servicio de config.php es larga (64 caracteres hexadecimales)');
comprobar(preg_match('/^[0-9a-f]{64}$/', CLAVE_SERVICIO) === 1 && CLAVE_SERVICIO !== str_repeat('0', 64), '  y aleatoria (random_bytes), no un texto de ejemplo');

// =====================================================================
seccion('8. "Monitorear todos" funciona con sesión: los servicios se hablan con la clave');

recargarBaseDeDatos();
// Nunca IPs de la red local en las pruebas (CLAUDE.md, "IPs de prueba").
peticion('PUT', '/dispositivos/2', ['nombre' => 'Router principal', 'ip' => '192.0.2.30', 'tipo' => 'router', 'ubicacion' => 'Cuarto de telecomunicaciones']);
peticion('PUT', '/dispositivos/3', ['nombre' => 'PC Recepción', 'ip' => '192.0.2.31', 'tipo' => 'computadora', 'ubicacion' => 'Recepción']);
$codigos = [];
for ($id = 1; $id <= 6; $id++) {
    $codigos[$id] = peticion('POST', "/monitoreo/$id")['codigo'];
}
comprobarIgual(array_fill(1, 6, 201), $codigos, 'con sesión, POST /monitoreo/{id} para los 6 dispositivos → 201');
comprobarIgual('activo', peticion('GET', '/dispositivos/1')['json']['data']['estado'] ?? null, '  Mi PC quedó activo (PATCH /estado [Interno] con la clave)');
comprobarIgual(5, (int) valorBd('SELECT COUNT(*) FROM alertas WHERE atendida = 0'), '  una alerta pendiente por cada uno de los 5 que no respondieron: la del seed y 4 nuevas (POST /alertas [Interno] con la clave)');
$codigoHttp = (string) file_get_contents(__DIR__ . '/../servicios/comun/http.php');
comprobar(preg_match('/Cookie|HTTP_COOKIE|\$_COOKIE/', preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $codigoHttp)) === 0, 'comun/http.php no reenvía la cookie del navegador a los otros servicios');

// =====================================================================
seccion('9. La sesión no bloquea las peticiones en paralelo (read_and_close)');

if (!curlDisponible()) {
    avisar('Prueba de peticiones en paralelo omitida: PHP no tiene la extensión curl.');
} else {
    // Dispositivos "no responde" al GET del paso 1: cada POST /monitoreo espera el tiempo
    // máximo (TIEMPO_ESPERA_SERVICIOS) y responde 502. Si la sesión quedara abierta, PHP
    // bloquearía su archivo y las tres peticiones se atenderían una tras otra.
    [$socket, $puerto] = socketQueNoResponde();
    simularFallo('Dispositivos', 'sin_respuesta', 'GET', $puerto);
    $inicio = microtime(true);
    $respuestas = peticionesSimultaneas([['POST', '/monitoreo/1', null], ['POST', '/monitoreo/2', null], ['POST', '/monitoreo/3', null]]);
    $duracion = microtime(true) - $inicio;
    fclose($socket);
    quitarFalloSimulado();
    comprobarIgual([502, 502, 502], array_column($respuestas, 'codigo'), '3 POST /monitoreo simultáneos con la misma cookie, con Dispositivos sin responder → 502');
    comprobar($duracion < TIEMPO_ESPERA_SERVICIOS * 2 && $duracion >= TIEMPO_ESPERA_SERVICIOS - 0.5,
        sprintf('  tardaron %.1f s en total: en paralelo (~%d s), no uno tras otro (~%d s)', $duracion, TIEMPO_ESPERA_SERVICIOS, TIEMPO_ESPERA_SERVICIOS * 3),
        sprintf('%.2f s', $duracion));
}

// =====================================================================
seccion('10. POST /auth/logout cierra la sesión');

$sesion = login(CREDENCIALES_ADMIN)['cookie'];
$r = peticion('POST', '/auth/logout', null, ['sesion' => cookieCon((string) $sesion)]);
comprobarRespuesta($r, 200, 'POST /auth/logout con sesión');
comprobarIgual([true, null], [array_key_exists('data', $r['json'] ?? []), $r['json']['data'] ?? null], '  data = null');
$borrada = atributosCookie($r['cookies'][0] ?? '');
comprobar(($borrada['nombre'] ?? '') === NOMBRE_COOKIE_SESION && ($borrada['path'] ?? '') === RUTA_COOKIE_SESION && ($borrada['max-age'] ?? '') === '0',
    '  pide borrar la cookie, con la misma ruta', $r['cookies'][0] ?? '(sin Set-Cookie)');
comprobarRespuesta(peticion('GET', '/auth/sesion', null, ['sesion' => cookieCon((string) $sesion)]), 401, 'la cookie anterior ya no tiene sesión', MENSAJE_SIN_SESION_ACTIVA);
comprobarRespuesta(peticion('GET', '/dispositivos', null, ['sesion' => cookieCon((string) $sesion)]), 401, '  ni acceso a los servicios', MENSAJE_SIN_SESION);
$r = peticion('POST', '/auth/logout', null, ['sesion' => false]);
comprobarRespuesta($r, 200, 'POST /auth/logout sin sesión también responde 200');
comprobarIgual([], $r['cookies'], '  sin Set-Cookie');
comprobarRespuesta(peticion('POST', '/auth/logout', null, ['sesion' => cookieCon('../../etc/passwd')]), 200, 'con una cookie con caracteres no válidos, 200 (no un 500)');

terminarPruebas();
