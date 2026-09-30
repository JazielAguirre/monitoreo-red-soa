<?php
/**
 * Pruebas de la interfaz (docs/PLAN.md, Fase 6) con un Chrome headless propio,
 * controlado por pruebas/navegador.php. Recarga la base de datos al empezar.
 *
 *   /opt/lampp/bin/php pruebas/interfaz.php
 *
 * Si no hay Chrome, Chromium o Edge, se omite con un aviso (CHROME_BIN indica otro).
 * Las capturas quedan en la carpeta temporal del sistema (se indica al final).
 */

declare(strict_types=1);

require_once __DIR__ . '/ayudas.php';
require_once __DIR__ . '/navegador.php';

const XSS = '<img src=x onerror=alert(1)>';

echo "Pruebas de la interfaz\n";

// =====================================================================
seccion('8.1 · Caché: páginas, módulos y estilos se revalidan; vendor/ no');

/**
 * GET a un archivo de web/ (opcionalmente con If-None-Match): [código, cabeceras en minúsculas].
 */
function cabecerasDeArchivo(string $ruta, ?string $etiqueta = null): array
{
    $contexto = stream_context_create(['http' => [
        'ignore_errors' => true,
        'timeout' => 10,
        'header' => $etiqueta === null ? '' : "If-None-Match: $etiqueta\r\n",
    ]]);
    @file_get_contents(URL_INTERFAZ . "/$ruta", false, $contexto);
    $cabeceras = leerCabeceras($http_response_header ?? []);

    return [$cabeceras['codigo'], $cabeceras['lista']];
}

foreach (['index.html', 'login.html', 'js/modulos/estructura.js', 'js/api.js', 'css/app.css'] as $archivo) {
    [$codigo, $cabeceras] = cabecerasDeArchivo($archivo);
    comprobarIgual([200, 'no-cache'], [$codigo, $cabeceras['cache-control'] ?? null], "$archivo → Cache-Control: no-cache (el navegador pregunta si hay versión nueva)");
    [$codigo] = cabecerasDeArchivo($archivo, $cabeceras['etag'] ?? '');
    comprobarIgual(304, $codigo, '  y si no cambió recibe un 304, sin volver a descargarlo');
}
[$codigo, $cabeceras] = cabecerasDeArchivo('');
comprobarIgual([200, 'no-cache'], [$codigo, $cabeceras['cache-control'] ?? null], 'web/ (índice de la carpeta) también');
foreach (['vendor/bootstrap/css/bootstrap.min.css', 'vendor/chart.js/chart.umd.min.js'] as $archivo) {
    [$codigo, $cabeceras] = cabecerasDeArchivo($archivo);
    comprobarIgual([200, null], [$codigo, $cabeceras['cache-control'] ?? null], "$archivo conserva la caché normal (sin no-cache)");
}
[$codigo, $cabeceras] = cabecerasDeArchivo('favicon.svg');
comprobarIgual([200, 'image/svg+xml'], [$codigo, $cabeceras['content-type'] ?? null], 'favicon.svg se sirve como imagen SVG');

$ejecutable = Navegador::buscarEjecutable();
if ($ejecutable === null) {
    avisar('Pruebas de interfaz omitidas: no se encontró Chrome, Chromium ni Edge (se puede indicar con CHROME_BIN).');
    terminarPruebas();
}

echo "Recargando la base de datos (schema.sql + seed.sql)...\n";
recargarBaseDeDatos();

$navegador = new Navegador($ejecutable);
$carpetaCapturas = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'monitoreo-capturas';
if (!is_dir($carpetaCapturas)) {
    mkdir($carpetaCapturas, 0700, true);
}
echo "Chrome de pruebas: perfil temporal y puerto propio ({$navegador->puerto()}).\n";

// Sesión para las peticiones de preparación que hace PHP. El Chrome de pruebas inicia
// la suya a través de la pantalla de login (sección 7.2).
iniciarSesionDePrueba();

/**
 * Abre una página de web/ y limpia los errores y diálogos registrados.
 */
function abrirPagina(Navegador $navegador, string $pagina, string $base = URL_INTERFAZ): void
{
    $navegador->erroresJs = [];
    $navegador->dialogos = [];
    $navegador->abrir("$base/$pagina");
}

/**
 * Guarda una captura de página completa en la carpeta de capturas.
 */
function capturar(Navegador $navegador, string $nombre): void
{
    global $carpetaCapturas;
    $navegador->captura($carpetaCapturas . DIRECTORY_SEPARATOR . "$nombre.png");
}

/**
 * Espera a que la estructura común esté montada y el contador de alertas cargado.
 */
function esperarEstructura(Navegador $navegador): bool
{
    return $navegador->esperarHasta("document.querySelector('#barra-lateral .nav-lateral__enlace') && document.querySelector('.js-contador-alertas')?.textContent !== ''", 10);
}

/**
 * Hace clic en el primer elemento que coincide con el selector (debe existir).
 */
function clic(Navegador $navegador, string $selector): void
{
    $selectorJs = json_encode($selector);
    $navegador->evaluar("(() => { const el = document.querySelector($selectorJs); if (!el) throw new Error('No existe ' + $selectorJs); el.click(); })()");
}

/**
 * Escribe en un campo y dispara los eventos input y change, como una persona.
 */
function escribir(Navegador $navegador, string $selector, string $valor): void
{
    $selectorJs = json_encode($selector);
    $valorJs = json_encode($valor);
    $navegador->evaluar("(() => { const el = document.querySelector($selectorJs); el.value = $valorJs; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); })()");
}

/**
 * Nombres de las filas visibles de la tabla de Dispositivos.
 */
function filasDispositivos(Navegador $navegador): array
{
    return $navegador->evaluar("[...document.querySelectorAll('.tabla-dispositivos tbody tr')].map((tr) => tr.querySelector('.tabla-nombre').textContent)") ?? [];
}

/**
 * Títulos de las notificaciones visibles.
 */
function notificaciones(Navegador $navegador): array
{
    return $navegador->evaluar("[...document.querySelectorAll('.toast .notificacion__titulo')].map((t) => t.textContent)") ?? [];
}

function cerrarNotificaciones(Navegador $navegador): void
{
    $navegador->evaluar("document.querySelectorAll('.toast-container .toast').forEach((t) => t.remove())");
}

/**
 * Importa un módulo de web/js/ en la página y evalúa código con él (variable m).
 */
function conModulo(Navegador $navegador, string $modulo, string $codigo): mixed
{
    return $navegador->evaluar("import('./js/$modulo').then(async (m) => { $codigo })");
}

/**
 * Llena el formulario de login.html y lo envía, como una persona.
 */
function enviarLogin(Navegador $navegador, string $usuario, string $contrasena): void
{
    escribir($navegador, '#usuario', $usuario);
    escribir($navegador, '#contrasena', $contrasena);
    clic($navegador, '#entrar');
}

/**
 * Inicia sesión en el Chrome de pruebas a través de la pantalla de login real y
 * espera a llegar al Dashboard.
 */
function iniciarSesionEnLaPantalla(Navegador $navegador, string $base = URL_INTERFAZ): bool
{
    abrirPagina($navegador, 'login.html', $base);
    $navegador->esperarHasta("document.getElementById('entrar')", 5);
    enviarLogin($navegador, 'admin', 'admin123');

    return $navegador->esperarHasta("location.pathname.endsWith('/index.html') && document.querySelector('.js-usuario-nombre')?.textContent === 'Administrador'", 10);
}

/**
 * Estado visible de login.html.
 */
function estadoLogin(Navegador $navegador): array
{
    return $navegador->evaluar(<<<'JS'
        (() => {
            const $ = (id) => document.getElementById(id);
            return {
                error: $('aviso-error').hidden ? null : $('aviso-error-texto').textContent,
                exito: $('aviso-exito').hidden ? null : $('aviso-exito-texto').textContent,
                invalidos: ['usuario', 'contrasena'].filter((c) => $(c).classList.contains('is-invalid')),
                mensajes: ['usuario', 'contrasena'].map((c) => ($(`error-${c}`).hidden ? null : $(`error-${c}`).textContent)),
                boton: [$('entrar').textContent.trim(), $('entrar').disabled],
            };
        })()
    JS) ?? [];
}

// =====================================================================
seccion('7.2 · Pantalla de inicio de sesión (5a y 5b)');

$navegador->tamano(1440, 900);
abrirPagina($navegador, 'index.html');
comprobar($navegador->esperarHasta("location.pathname.endsWith('/login.html')", 8), 'sin sesión, el Dashboard lleva al login');
comprobarIgual('?volver=%2Fmonitoreo-red-soa%2Fweb%2Findex.html', $navegador->evaluar('location.search'), '  recordando la pantalla en «volver»');
$navegador->procesarEventos(0.4);
$r = $navegador->evaluar(<<<'JS'
    (() => {
        const tarjeta = document.querySelector('.login__tarjeta').getBoundingClientRect();
        const token = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
        return {
            titulo: document.querySelector('h1').textContent,
            subtitulo: document.querySelector('.login__subtitulo').textContent,
            etiquetas: [...document.querySelectorAll('label')].map((l) => l.textContent),
            marcadores: [document.getElementById('usuario').placeholder, document.getElementById('contrasena').placeholder],
            autocompletar: [document.getElementById('usuario').autocomplete, document.getElementById('contrasena').autocomplete],
            tipo: document.getElementById('contrasena').type,
            ancho: Math.round(tarjeta.width),
            centrada: Math.abs((tarjeta.left + tarjeta.right) / 2 - innerWidth / 2) < 2,
            foco: document.activeElement.id,
            fondo: getComputedStyle(document.body).backgroundColor === getComputedStyle(document.querySelector('.marca__logo')).backgroundColor,
            novalidate: document.getElementById('formulario-login').noValidate,
        };
    })()
JS);
comprobarIgual([
    'titulo' => 'Monitoreo de Red SOA', 'subtitulo' => 'Inicia sesión para administrar la red', 'etiquetas' => ['Usuario', 'Contraseña'],
    'marcadores' => ['Tu usuario', 'Tu contraseña'], 'autocompletar' => ['username', 'current-password'], 'tipo' => 'password',
    'ancho' => 400, 'centrada' => true, 'foco' => 'usuario', 'fondo' => true, 'novalidate' => true,
], $r, 'como 5a: logo, título, subtítulo, campos con etiqueta y autocompletar, tarjeta de 400 px centrada sobre el fondo azul marino, foco en Usuario');
comprobarIgual(['error' => null, 'exito' => null, 'invalidos' => [], 'mensajes' => [null, null], 'boton' => ['Iniciar sesión', false]], estadoLogin($navegador), '  sin avisos ni errores al abrir');
capturar($navegador, 'login-1440');

clic($navegador, '#mostrar-contrasena');
comprobarIgual(['text', 'true', 'Mostrar contraseña', 'bi bi-eye-slash'], [$navegador->evaluar("document.getElementById('contrasena').type"), $navegador->evaluar("document.getElementById('mostrar-contrasena').getAttribute('aria-pressed')"), $navegador->evaluar("document.getElementById('mostrar-contrasena').getAttribute('aria-label')"), $navegador->evaluar("document.querySelector('#mostrar-contrasena i').className")], 'el ojo muestra la contraseña (aria-pressed="true"; la etiqueta no cambia)');
clic($navegador, '#mostrar-contrasena');
comprobarIgual(['password', 'false'], [$navegador->evaluar("document.getElementById('contrasena').type"), $navegador->evaluar("document.getElementById('mostrar-contrasena').getAttribute('aria-pressed')")], '  y la vuelve a ocultar');

clic($navegador, '#entrar');
$navegador->esperarHasta("!document.getElementById('error-usuario').hidden", 5);
comprobarIgual(['error' => null, 'exito' => null, 'invalidos' => ['usuario', 'contrasena'], 'mensajes' => ['Escribe tu usuario', 'Escribe tu contraseña'], 'boton' => ['Iniciar sesión', false]], estadoLogin($navegador), 'vacío: los mensajes de la API bajo cada campo (sin globos del navegador)');

$navegador->interceptar('auth/login', ['retener' => true]);
enviarLogin($navegador, 'admin', 'mala123');
$navegador->procesarEventos(0.2);
comprobarIgual(['Entrando…', true], estadoLogin($navegador)['boton'] ?? null, 'al enviar: «Entrando…» con el botón deshabilitado');
comprobarIgual(1, $navegador->evaluar("document.querySelectorAll('#entrar .girador').length"), '  y un spinner');
capturar($navegador, 'login-entrando');
$navegador->liberar('auth/login');
$navegador->quitarIntercepciones();
$navegador->esperarHasta("!document.getElementById('aviso-error').hidden", 5);
comprobarIgual(['error' => 'Usuario o contraseña incorrectos', 'exito' => null, 'invalidos' => ['usuario', 'contrasena'], 'mensajes' => [null, null], 'boton' => ['Iniciar sesión', false]], estadoLogin($navegador), 'contraseña incorrecta (5b): el aviso arriba y los dos campos en rojo, sin decir cuál falló');
comprobarIgual(['alert', 'contrasena'], [$navegador->evaluar("document.getElementById('aviso-error').getAttribute('role')"), $navegador->evaluar('document.activeElement.id')], '  el aviso es role="alert" y el foco vuelve a la contraseña');
capturar($navegador, 'login-error');
enviarLogin($navegador, 'noexiste', 'mala123');
$navegador->esperarHasta("document.getElementById('entrar').textContent.trim() === 'Iniciar sesión' && !document.getElementById('aviso-error').hidden", 5);
comprobarIgual('Usuario o contraseña incorrectos', estadoLogin($navegador)['error'] ?? null, '  usuario que no existe: el mismo mensaje');
escribir($navegador, '#usuario', 'admin');
comprobarIgual([null, []], campos(estadoLogin($navegador), ['error', 'invalidos']), '  al escribir de nuevo, se quitan el aviso y el rojo');

$navegador->interceptar('auth/login', ['fallar' => true]);
enviarLogin($navegador, 'admin', 'admin123');
$navegador->esperarHasta("!document.getElementById('aviso-error').hidden", 5);
comprobarIgual('No se pudo conectar con el servidor. Revisa que Apache esté encendido.', estadoLogin($navegador)['error'] ?? null, 'sin conexión: el aviso lo dice (y no marca los campos)');
$navegador->quitarIntercepciones();

$navegador->evaluar("document.activeElement.blur(); document.getElementById('usuario').focus()");
$recorrido = [];
foreach (range(1, 3) as $i) {
    $navegador->tecla('Tab');
    $navegador->procesarEventos(0.2);
    $recorrido[] = $navegador->evaluar("(() => { const el = document.activeElement; const e = getComputedStyle(el); return [el.id, e.boxShadow !== 'none' || e.outlineStyle !== 'none']; })()");
}
comprobarIgual([['contrasena', true], ['mostrar-contrasena', true], ['entrar', true]], $recorrido, 'con el teclado: Usuario → Contraseña → ojo → «Iniciar sesión», todos con foco visible');

enviarLogin($navegador, 'admin', 'admin123');
$navegador->esperarHasta("!document.getElementById('aviso-exito').hidden", 5);
comprobarIgual('Sesión iniciada. Abriendo el dashboard…', estadoLogin($navegador)['exito'] ?? null, 'credenciales correctas: «Sesión iniciada. Abriendo el dashboard…»');
comprobar($navegador->esperarHasta("location.pathname.endsWith('/index.html')", 8), '  y vuelve a la pantalla de «volver» (el Dashboard)');
comprobar(esperarEstructura($navegador), '  que carga con la sesión');
comprobarIgual(['Administrador', 'admin', 'AD'], [$navegador->evaluar("document.querySelector('#barra-lateral .js-usuario-nombre').textContent"), $navegador->evaluar("document.querySelector('#barra-lateral .js-usuario-usuario').textContent"), $navegador->evaluar("document.querySelector('#barra-lateral .js-usuario-avatar').textContent")], 'la barra lateral muestra el nombre, el usuario y las iniciales reales (GET /auth/sesion)');

$navegador->tamano(390, 844, true);
abrirPagina($navegador, 'login.html');
comprobar($navegador->esperarHasta("location.pathname.endsWith('/index.html')", 8), 'con sesión, login.html lleva al Dashboard');
$navegador->evaluar("fetch('../servicios/auth/logout', { method: 'POST' })");
$navegador->procesarEventos(0.5);
abrirPagina($navegador, 'login.html');
$navegador->procesarEventos(0.5);
$r = $navegador->evaluar("(() => { const t = document.querySelector('.login__tarjeta').getBoundingClientRect(); return { ancho: Math.round(t.width), alto: Math.round(document.getElementById('usuario').getBoundingClientRect().height), desborde: document.documentElement.scrollWidth > innerWidth }; })()");
comprobarIgual(['ancho' => 358, 'alto' => 44, 'desborde' => false], $r, 'a 390 px: tarjeta con 16 px a cada lado, campos de 44 px, sin desplazamiento horizontal');
capturar($navegador, 'login-390');
comprobarIgual([], array_values(array_filter($navegador->erroresJs, fn($e) => !str_contains($e, 'status of 401'))), 'login.html sin errores de JavaScript (los 401 esperados no cuentan)');

$navegador->tamano(1440, 900);
comprobar(iniciarSesionEnLaPantalla($navegador), 'inicio de sesión a través de la pantalla para el resto de las pruebas (localhost)');
comprobar(iniciarSesionEnLaPantalla($navegador, str_replace('://localhost/', '://127.0.0.1/', URL_INTERFAZ)), '  y desde 127.0.0.1 (otra cookie: otro origen)');

// =====================================================================
seccion('6.1 · Formatos (formatos.js)');

abrirPagina($navegador, 'componentes.html');
$r = conModulo($navegador, 'modulos/formatos.js', <<<'JS'
    const a = (y, mo, d, h = 12, mi = 0, s = 0) => new Date(y, mo - 1, d, h, mi, s);
    return {
        partes: m.partesFecha('2026-09-21 10:05:12'),
        partesIso: m.partesFecha('2026-09-21T10:05:12'),
        partesVacia: m.partesFecha(null),
        hoy: m.formatearFechaCorta('2026-09-21 10:05:12', { ahora: a(2026, 9, 21, 23) }),
        otroDia: m.formatearFechaCorta('2026-09-21 10:05:12', { ahora: a(2026, 9, 22, 8) }),
        medianoche: m.formatearFechaCorta('2026-09-21 23:59:59', { ahora: a(2026, 9, 22, 0, 0, 1) }),
        nunca: m.formatearFechaCorta(null, { siVacio: 'Nunca' }),
        fechaHora: m.formatearFechaHora('2026-09-21 10:05:12'),
        completa: m.formatearFechaCompleta('2026-09-21 10:05:12'),
        hora: m.formatearHora('2026-09-21 09:05:00'),
        ms: [m.formatearMs(0), m.formatearMs(0.32), m.formatearMs(1.8), m.formatearMs(2), m.formatearMs(52.2), m.formatearMs(0.07), m.formatearMs(null), m.formatearMs(null, '—')],
        porcentaje: [m.formatearPorcentaje(96.2, 1), m.formatearPorcentaje(200 / 3), m.formatearPorcentaje(100), m.formatearPorcentaje(null)],
        campo: m.fechaParaCampo(a(2026, 1, 5)),
        hace6: m.fechaParaCampo(m.haceDias(6, a(2026, 9, 28))),
        cruceMes: m.fechaParaCampo(m.haceDias(6, a(2026, 10, 3))),
        escapado: m.escaparHtml(`<img src=x onerror=alert(1)> & "comillas" 'simples'`),
        busqueda: m.normalizarBusqueda('  Recepción ÑANDÚ '),
        plural: [m.pluralizar(1, 'dispositivo', 'dispositivos'), m.pluralizar(3, 'dispositivo', 'dispositivos')],
        orden: ['2026-09-27 09:45:03', '2026-09-22 08:15:01', '2026-09-27 09:45:00'].sort(m.compararFechas),
    };
JS);

comprobarIgual(['anio' => '2026', 'mes' => '09', 'dia' => '21', 'hora' => '10', 'minuto' => '05', 'segundo' => '12'], $r['partes'] ?? null, 'separa "2026-09-21 10:05:12" en sus partes, sin new Date()');
comprobarIgual([null, null], campos($r, ['partesIso', 'partesVacia']), 'un texto con otro formato o vacío → null');
comprobarIgual('Hoy, 10:05', $r['hoy'] ?? null, 'fecha de hoy → "Hoy, 10:05"');
comprobarIgual('21/09/2026 10:05', $r['otroDia'] ?? null, 'fecha de otro día → "21/09/2026 10:05"');
comprobarIgual('21/09/2026 23:59', $r['medianoche'] ?? null, 'un segundo después de medianoche, lo de ayer ya no es "Hoy"');
comprobarIgual('Nunca', $r['nunca'] ?? null, 'sin fecha → el texto indicado ("Nunca")');
comprobarIgual(['21/09/2026 10:05', '21/09/2026 · 10:05:12', '09:05'], [$r['fechaHora'] ?? '', $r['completa'] ?? '', $r['hora'] ?? ''], 'fecha y hora · fecha completa · solo hora');
comprobarIgual(['< 1 ms', '0.32 ms', '1.8 ms', '2 ms', '52.2 ms', '0.07 ms', 'Sin respuesta', '—'], $r['ms'] ?? null, 'tiempos: 0 → "< 1 ms", decimales sin ceros de sobra, null → "Sin respuesta"');
comprobarIgual(['96.2 %', '67 %', '100 %', '—'], $r['porcentaje'] ?? null, 'porcentajes');
comprobarIgual(['2026-01-05', '2026-09-22', '2026-09-27'], [$r['campo'] ?? '', $r['hace6'] ?? '', $r['cruceMes'] ?? ''], 'fechas para <input type="date"> y "hace 6 días" (también al cruzar de mes)');
comprobarIgual('&lt;img src=x onerror=alert(1)&gt; &amp; &quot;comillas&quot; &#39;simples&#39;', $r['escapado'] ?? null, 'escaparHtml() neutraliza etiquetas, & y comillas');
comprobarIgual('recepcion nandu', $r['busqueda'] ?? null, 'búsqueda sin acentos ni mayúsculas');
comprobarIgual(['1 dispositivo', '3 dispositivos'], $r['plural'] ?? null, 'singular y plural');
comprobarIgual(['2026-09-22 08:15:01', '2026-09-27 09:45:00', '2026-09-27 09:45:03'], $r['orden'] ?? null, 'las fechas de la API se ordenan como texto');

// Ningún módulo usa new Date() con un texto (Safari del iPhone da fecha inválida).
$usosDate = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../web/js', FilesystemIterator::SKIP_DOTS)) as $archivo) {
    foreach (file($archivo->getPathname()) as $numero => $linea) {
        if (preg_match_all('/new Date\(([^)]*)\)/', $linea, $coincidencias)) {
            foreach ($coincidencias[1] as $argumento) {
                if (!preg_match('/^$|^\w+\.getFullYear\(|^y, mo - 1/', trim($argumento))) {
                    $usosDate[] = basename($archivo->getPathname()) . ':' . ($numero + 1) . " new Date($argumento)";
                }
            }
        }
    }
}
comprobarIgual([], $usosDate, 'ningún archivo de web/js usa new Date() con un texto de fecha');

// =====================================================================
seccion('6.1 · api.js: URL base relativa y errores');

$codigoApi = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../web/js/api.js'));
comprobar(!str_contains($codigoApi, 'localhost') && !preg_match('~https?://~', $codigoApi), 'el código de api.js (sin comentarios) no fija "localhost" ni ninguna URL absoluta');

abrirPagina($navegador, 'componentes.html', str_replace('://localhost/', '://127.0.0.1/', URL_INTERFAZ));
$r = conModulo($navegador, 'api.js', 'const d = await m.api.dispositivos.listar(); return { cantidad: d.length, origen: location.host };');
comprobarIgual(['cantidad' => 6, 'origen' => '127.0.0.1'], $r, 'abierta desde 127.0.0.1 (no localhost), api.js llega a los servicios del mismo servidor');

abrirPagina($navegador, 'componentes.html');
$capturar = 'try { await PETICION; return "sin error"; } catch (e) { return { nombre: e.name, codigo: e.codigo, mensaje: e.message, campos: e.campos }; }';
$r = conModulo($navegador, 'api.js', str_replace('PETICION', 'm.api.dispositivos.obtener(999)', $capturar));
comprobarIgual(['nombre' => 'ErrorApi', 'codigo' => 404, 'mensaje' => 'El dispositivo no existe', 'campos' => null], $r, 'un 404 llega como ErrorApi con el mensaje de la API');
$r = conModulo($navegador, 'api.js', str_replace('PETICION', "m.api.dispositivos.crear({ nombre: '', ip: 'x', tipo: 'router', ubicacion: 'Lab' })", $capturar));
comprobarIgual([400, ['nombre', 'ip']], [$r['codigo'] ?? null, array_keys($r['campos'] ?? [])], 'un 400 trae los mensajes por campo (campos)');

$navegador->interceptar('servicios/dispositivos', ['fallar' => true]);
$r = conModulo($navegador, 'api.js', str_replace('PETICION', 'm.api.dispositivos.listar()', $capturar));
comprobarIgual([0, 'No se pudo conectar con el servidor. Revisa que Apache esté encendido.'], [$r['codigo'] ?? null, $r['mensaje'] ?? null], 'sin conexión → código 0 y un mensaje claro');
$navegador->interceptar('servicios/dispositivos', ['codigo' => 500, 'tipo' => 'text/html', 'cuerpo' => '<h1>Internal Server Error</h1>']);
$r = conModulo($navegador, 'api.js', str_replace('PETICION', 'm.api.dispositivos.listar()', $capturar));
comprobarIgual([500, 'El servidor respondió algo inesperado. Intenta de nuevo en unos segundos.'], [$r['codigo'] ?? null, $r['mensaje'] ?? null], 'una respuesta HTML (no JSON) → mensaje claro, no un error de JavaScript');
$navegador->interceptar('servicios/dispositivos', ['codigo' => 500, 'cuerpo' => '{"ok":false,"error":"Error interno del servidor"}']);
$r = conModulo($navegador, 'api.js', str_replace('PETICION', 'm.api.dispositivos.listar()', $capturar));
comprobarIgual([500, 'Error interno del servidor'], [$r['codigo'] ?? null, $r['mensaje'] ?? null], 'MySQL apagado (500 en JSON) → el mensaje de la API');
$navegador->quitarIntercepciones();

// =====================================================================
seccion('6.1 · Notificaciones y estados de las vistas');

abrirPagina($navegador, 'componentes.html');
comprobarIgual([], $navegador->erroresJs, 'componentes.html carga sin errores de JavaScript');
$r = conModulo($navegador, 'modulos/notificaciones.js', <<<'JS'
    const toast = m.mostrarNotificacion({ tipo: 'error', icono: 'bi-wifi-off', titulo: '<img src=x onerror=alert(1)>', texto: '<b>texto</b>', enlace: { texto: 'Ver alerta', href: 'alertas.html' } });
    await new Promise((r) => setTimeout(r, 400));
    const contenedor = document.querySelector('.toast-container.notificaciones');
    return {
        titulo: toast.querySelector('.notificacion__titulo').textContent,
        imagenes: toast.querySelectorAll('img, b').length,
        enlace: toast.querySelector('a')?.getAttribute('href'),
        visible: toast.classList.contains('show'),
        rol: toast.getAttribute('role'),
        aria: contenedor.getAttribute('aria-live'),
        arriba: Math.round(contenedor.getBoundingClientRect().top),
        derecha: Math.round(innerWidth - contenedor.getBoundingClientRect().right),
    };
JS);
comprobarIgual(XSS, $r['titulo'] ?? null, 'el título se muestra como texto');
comprobarIgual(0, $r['imagenes'] ?? null, '  no se creó ninguna etiqueta a partir del texto');
comprobarIgual(['alertas.html', true, 'alert', 'polite'], [$r['enlace'] ?? null, $r['visible'] ?? null, $r['rol'] ?? null, $r['aria'] ?? null], 'enlace "Ver alerta", visible, role="alert" y contenedor con aria-live="polite"');
comprobarIgual([24, 32], [$r['arriba'] ?? null, $r['derecha'] ?? null], 'arriba a la derecha (24 y 32 px), como en el diseño');
$navegador->procesarEventos(1);
comprobarIgual([], $navegador->dialogos, 'no se ejecutó ningún alert() del título');
comprobar($navegador->esperarHasta("!document.querySelector('.toast-container.notificaciones .toast')", 8), 'desaparece sola después de --duracion-notificacion (4.5 s)');

$r = conModulo($navegador, 'modulos/vistas.js', <<<'JS'
    const caja = document.createElement('div');
    document.body.append(caja);
    let reintentos = 0;
    m.mostrarBloque(caja, m.crearError({ mensaje: '<script>x</script>', alReintentar: () => reintentos++ }));
    caja.querySelector('button').click();
    const error = { rol: caja.firstElementChild.getAttribute('role'), texto: caja.querySelector('.vista-error__texto').textContent, boton: caja.querySelector('button').textContent.trim(), reintentos, scripts: caja.querySelectorAll('script').length };
    m.mostrarBloque(caja, m.crearVacio({ icono: 'bi-search', titulo: 'Ningún dispositivo coincide', texto: 'Prueba con otros filtros.', accion: { texto: 'Limpiar filtros' } }));
    const vacio = { titulo: caja.querySelector('.estado-vacio__titulo').textContent, boton: caja.querySelector('button').textContent.trim() };
    caja.remove();
    return { error, vacio };
JS);
comprobarIgual(['rol' => 'alert', 'texto' => '<script>x</script>', 'boton' => 'Reintentar', 'reintentos' => 1, 'scripts' => 0], $r['error'] ?? null, 'bloque de error: role="alert", mensaje como texto y "Reintentar" llama de nuevo a la carga');
comprobarIgual(['titulo' => 'Ningún dispositivo coincide', 'boton' => 'Limpiar filtros'], $r['vacio'] ?? null, 'bloque vacío con su acción');

// =====================================================================
seccion('6.2 · Estructura común: barra lateral, encabezado móvil y contador de alertas');

$navegador->tamano(1440, 900);
$paginas = ['index' => 'Dashboard', 'dispositivos' => 'Dispositivos', 'monitoreos' => 'Monitoreos', 'alertas' => 'Alertas'];
foreach ($paginas as $pagina => $texto) {
    abrirPagina($navegador, "$pagina.html");
    comprobar(esperarEstructura($navegador), "$pagina.html: la estructura se monta");
    $r = $navegador->evaluar(<<<'JS'
        ({
            enlaces: [...document.querySelectorAll('#barra-lateral .nav-lateral__enlace')].map((a) => a.textContent.trim().replace(/\d+$/, '')),
            activo: document.querySelector('#barra-lateral [aria-current="page"]')?.textContent.trim().replace(/\d+$/, ''),
            menu: document.querySelector('#barra-lateral .nav-lateral__titulo')?.textContent,
            usuario: document.querySelector('#barra-lateral .usuario-barra__nombre')?.textContent,
            contador: document.querySelector('#barra-lateral .js-contador-alertas').textContent,
            contadorVisible: !document.querySelector('#barra-lateral .js-contador-alertas').hidden,
            anchoBarra: Math.round(document.getElementById('barra-lateral').getBoundingClientRect().width),
            encabezadoMovil: getComputedStyle(document.getElementById('encabezado-movil')).display,
        })
    JS);
    comprobarIgual(
        [['Dashboard', 'Dispositivos', 'Monitoreos', 'Alertas'], $texto, 'MENÚ', 'Administrador', '1', true, 248, 'none'],
        campos($r, ['enlaces', 'activo', 'menu', 'usuario', 'contador', 'contadorVisible', 'anchoBarra', 'encabezadoMovil']),
        "  4 enlaces, «{$texto}» activo, MENÚ, usuario, contador = 1 pendiente (seed), barra de 248 px, sin encabezado móvil"
    );
    comprobarIgual([], $navegador->erroresJs, "  $pagina.html sin errores de JavaScript");
}
capturar($navegador, 'estructura-1440');

$navegador->tecla('Tab');
comprobarIgual('saltar-contenido', $navegador->evaluar('document.activeElement.className'), 'el primer Tab lleva a «Saltar al contenido»');


$navegador->tamano(390, 844, true);
abrirPagina($navegador, 'dispositivos.html');
esperarEstructura($navegador);
$r = $navegador->evaluar(<<<'JS'
    ({
        barra: getComputedStyle(document.getElementById('barra-lateral')).display,
        encabezado: getComputedStyle(document.getElementById('encabezado-movil')).display,
        alto: Math.round(document.getElementById('encabezado-movil').getBoundingClientRect().height),
        campana: document.querySelector('#encabezado-movil .js-contador-alertas').textContent,
        menuOculto: document.getElementById('menu-movil').hidden,
        desborde: document.documentElement.scrollWidth > innerWidth,
    })
JS);
comprobarIgual(['none', 'flex', 56, '1', true, false], campos($r, ['barra', 'encabezado', 'alto', 'campana', 'menuOculto', 'desborde']), 'a 390 px: sin barra lateral, encabezado de 56 px, campana con contador, menú cerrado, sin desplazamiento horizontal');

$navegador->evaluar("document.querySelector('#encabezado-movil .encabezado-movil__boton').click()");
$r = $navegador->evaluar("({ abierto: !document.getElementById('menu-movil').hidden, expandido: document.querySelector('#encabezado-movil button').getAttribute('aria-expanded'), foco: document.activeElement.textContent.trim(), activo: document.querySelector('#menu-movil [aria-current=\"page\"]')?.textContent.trim() })");
comprobarIgual(['abierto' => true, 'expandido' => 'true', 'foco' => 'Dashboard', 'activo' => 'Dispositivos'], $r, '☰ abre el menú (aria-expanded, foco en el primer enlace, Dispositivos activo)');
capturar($navegador, 'estructura-390-menu');
$navegador->tecla('Escape');
$r = $navegador->evaluar("({ cerrado: document.getElementById('menu-movil').hidden, foco: document.activeElement.getAttribute('aria-controls') })");
comprobarIgual(['cerrado' => true, 'foco' => 'menu-movil'], $r, 'Escape cierra el menú y devuelve el foco al botón ☰');
$navegador->evaluar("document.querySelector('#encabezado-movil .encabezado-movil__boton').click(); document.querySelector('.menu-movil__fondo').click()");
comprobarIgual(true, $navegador->evaluar("document.getElementById('menu-movil').hidden"), 'tocar el fondo también lo cierra');
capturar($navegador, 'estructura-390');

$navegador->tamano(1440, 900);
$navegador->interceptar('alertas/conteo', ['codigo' => 200, 'cuerpo' => '{"ok":true,"data":{"pendientes":0,"atendidas":4}}']);
abrirPagina($navegador, 'index.html');
$navegador->esperarHasta("document.querySelector('.js-contador-alertas')?.textContent === '0'", 5);
comprobarIgual(true, $navegador->evaluar("[...document.querySelectorAll('.js-contador-alertas')].every((c) => c.hidden)"), 'con 0 pendientes, los contadores se ocultan');
$navegador->interceptar('alertas/conteo', ['fallar' => true]);
abrirPagina($navegador, 'index.html');
$navegador->esperarHasta("document.querySelector('#barra-lateral .nav-lateral__enlace')", 5);
$navegador->procesarEventos(0.5);
comprobar($navegador->evaluar("[...document.querySelectorAll('.js-contador-alertas')].every((c) => c.hidden)") === true
    && count(array_filter($navegador->erroresJs, fn($e) => !str_contains($e, 'Failed to load resource'))) === 0,
    'si el conteo falla, los contadores se ocultan sin romper la página', json_encode($navegador->erroresJs));
$navegador->quitarIntercepciones();

// =====================================================================
seccion('6.3 · Dispositivos: carga, tabla y filtros');

recargarBaseDeDatos();
$navegador->tamano(1440, 900);
$navegador->interceptar('servicios/dispositivos', ['retener' => true]);
abrirPagina($navegador, 'dispositivos.html');
$navegador->esperarRetenida('servicios/dispositivos'); // sale después de confirmar la sesión
$r = $navegador->evaluar("({ ocupado: document.getElementById('contenido-dispositivos').getAttribute('aria-busy'), esqueletos: document.querySelectorAll('#contenido-dispositivos .esqueleto').length, monitorearTodos: document.getElementById('monitorear-todos-escritorio').disabled })");
comprobarIgual(['ocupado' => 'true', 'esqueletos' => 16, 'monitorearTodos' => true], $r, 'mientras llegan los datos: esqueleto (aria-busy) y "Monitorear todos" deshabilitado');
$navegador->liberar('servicios/dispositivos');
$navegador->quitarIntercepciones();
comprobar($navegador->esperarHasta("document.querySelectorAll('.tabla-dispositivos tbody tr').length === 6", 8), 'al llegar los datos, la tabla muestra los 6 dispositivos');

comprobarIgual(['Impresora Laboratorio', 'Mi PC', 'PC Recepción', 'Router principal', 'Servidor de respaldo', 'Servidor principal'], filasDispositivos($navegador), '  ordenados por nombre');
$r = $navegador->evaluar(<<<'JS'
    (() => {
        const fila = (nombre) => [...document.querySelectorAll('.tabla-dispositivos tbody tr')].find((tr) => tr.querySelector('.tabla-nombre').textContent === nombre);
        const celdas = (tr) => [...tr.children].slice(0, 7).map((td) => td.textContent.trim());
        return {
            miPc: celdas(fila('Mi PC')),
            servidor: celdas(fila('Servidor principal')),
            respaldo: celdas(fila('Servidor de respaldo')),
            errorEnRojo: fila('Servidor principal').querySelector('.texto-error')?.textContent,
            enlace: fila('PC Recepción').querySelector('a.tabla-nombre').getAttribute('href'),
            subtitulo: document.getElementById('subtitulo-dispositivos').textContent,
            pie: document.getElementById('texto-pie-dispositivos').textContent,
            cuentas: [...document.querySelectorAll('[data-cuenta]')].map((n) => n.dataset.cuenta + ':' + n.textContent),
            ocupado: document.getElementById('contenido-dispositivos').hasAttribute('aria-busy'),
        };
    })()
JS);
comprobarIgual(['Activo', 'Mi PC', '127.0.0.1', 'Computadora', 'Laboratorio', '0.06 ms', '27/09/2026 09:45'], $r['miPc'] ?? null, 'fila de Mi PC: estado, nombre, IP, tipo, ubicación, respuesta y fecha (de otro día → "27/09/2026 09:45")');
comprobarIgual(['Inactivo', 'Servidor principal', '192.0.2.10', 'Servidor', 'Sala de servidores', 'Sin respuesta', '27/09/2026 09:45'], $r['servidor'] ?? null, 'fila del Servidor principal: "Sin respuesta"');
comprobarIgual('Sin respuesta', $r['errorEnRojo'] ?? null, '  en el color de error');
comprobarIgual(['Sin monitorear', 'Servidor de respaldo', '192.0.2.11', 'Servidor', 'Sala de servidores', '—', 'Nunca'], $r['respaldo'] ?? null, 'fila del Servidor de respaldo: "—" y "Nunca"');
comprobarIgual('monitoreos.html?dispositivo=3', $r['enlace'] ?? null, 'el nombre enlaza al historial del dispositivo en Monitoreos');
comprobarIgual(['6 dispositivos registrados', 'Mostrando 6 de 6 dispositivos'], [$r['subtitulo'] ?? '', $r['pie'] ?? ''], 'subtítulo y pie de tabla');
comprobarIgual(['todos:6', 'activo:4', 'inactivo:1', 'desconocido:1'], $r['cuentas'] ?? null, 'contadores del filtro de estado');
comprobarIgual(false, $r['ocupado'] ?? null, '  y la lista ya no está marcada como ocupada');

clic($navegador, '[for="estado-activo"]');
comprobarIgual(['Impresora Laboratorio', 'Mi PC', 'PC Recepción', 'Router principal'], filasDispositivos($navegador), 'filtro «Activos» → 4');
escribir($navegador, '#filtro-tipo', 'computadora');
comprobarIgual(['Mi PC', 'PC Recepción'], filasDispositivos($navegador), '  y tipo «Computadora» → 2');
comprobarIgual(['Mostrando 2 de 6 dispositivos', false], [$navegador->evaluar("document.getElementById('texto-pie-dispositivos').textContent"), $navegador->evaluar("document.getElementById('limpiar-filtros').hidden")], '  el pie lo refleja y aparece «Limpiar filtros»');
clic($navegador, '#limpiar-filtros');
comprobarIgual(6, count(filasDispositivos($navegador)), '«Limpiar filtros» vuelve a los 6');
escribir($navegador, '#buscar-dispositivo', 'recepcion');
comprobarIgual(['PC Recepción'], filasDispositivos($navegador), 'buscar «recepcion» (sin acento) encuentra «PC Recepción»');
escribir($navegador, '#buscar-dispositivo', '192.0.2');
comprobarIgual(['Impresora Laboratorio', 'Servidor de respaldo', 'Servidor principal'], filasDispositivos($navegador), 'buscar por parte de la IP («192.0.2»)');
escribir($navegador, '#buscar-dispositivo', 'no existe');
$r = $navegador->evaluar("({ titulo: document.querySelector('#contenido-dispositivos .estado-vacio__titulo')?.textContent, boton: document.querySelector('#contenido-dispositivos .estado-vacio button')?.textContent.trim() })");
comprobarIgual(['titulo' => 'Ningún dispositivo coincide', 'boton' => 'Limpiar filtros'], $r, 'sin coincidencias → estado vacío de filtros, con «Limpiar filtros»');
clic($navegador, '#contenido-dispositivos .estado-vacio button');
comprobarIgual([6, ''], [count(filasDispositivos($navegador)), $navegador->evaluar("document.getElementById('buscar-dispositivo').value")], '  que vacía la búsqueda y vuelve a los 6');
comprobarIgual([], $navegador->erroresJs, 'Dispositivos sin errores de JavaScript');
capturar($navegador, 'dispositivos-1440');

// =====================================================================
seccion('6.3 · Dispositivos: agregar, editar y eliminar');

/**
 * Espera a que el modal indicado termine de abrirse o cerrarse.
 */
function esperarModal(Navegador $navegador, string $id, bool $abierto): bool
{
    $condicion = $abierto
        ? "document.getElementById('$id').classList.contains('show')"
        : "!document.getElementById('$id').classList.contains('show') && !document.querySelector('.modal-backdrop')";
    $listo = $navegador->esperarHasta($condicion, 5);
    $navegador->procesarEventos(0.45); // la animación de Bootstrap dura 0.3 s
    return $listo;
}

function erroresFormulario(Navegador $navegador): array
{
    return $navegador->evaluar("Object.fromEntries(['nombre', 'ip', 'tipo', 'ubicacion'].map((c) => [c, document.getElementById('error-' + c).hidden ? null : document.getElementById('error-' + c).textContent]))") ?? [];
}

$navegador->evaluar("document.getElementById('agregar-dispositivo').focus()");
clic($navegador, '#agregar-dispositivo');
comprobar(esperarModal($navegador, 'modal-dispositivo', true), '«Agregar dispositivo» abre el modal');
$r = $navegador->evaluar("({ titulo: document.getElementById('modal-dispositivo-titulo').textContent, foco: document.activeElement.id, novalidate: document.querySelector('#modal-dispositivo form').noValidate, tipo: document.querySelector('#modal-dispositivo form').elements.tipo.value })");
comprobarIgual(['titulo' => 'Agregar dispositivo', 'foco' => 'campo-nombre', 'novalidate' => true, 'tipo' => 'computadora'], $r, '  título, foco en «Nombre», formulario con novalidate y tipo inicial «Computadora»');

clic($navegador, '#modal-dispositivo [type="submit"]');
$navegador->esperarHasta("!document.getElementById('error-nombre').hidden", 5);
comprobarIgual(['nombre' => 'El nombre es obligatorio', 'ip' => 'Dirección IP no válida', 'tipo' => null, 'ubicacion' => 'La ubicación es obligatoria'], erroresFormulario($navegador), 'enviar vacío → los mensajes de la API debajo de cada campo');
$r = $navegador->evaluar("({ invalido: document.getElementById('campo-ip').getAttribute('aria-invalid'), icono: !document.querySelector('#modal-dispositivo .campo-icono__error').hidden, ayuda: document.getElementById('ayuda-ip').hidden, foco: document.activeElement.id, visible: getComputedStyle(document.getElementById('error-ip')).display })");
comprobarIgual(['invalido' => 'true', 'icono' => true, 'ayuda' => true, 'foco' => 'campo-nombre', 'visible' => 'block'], $r, '  la IP queda marcada (aria-invalid, ícono, mensaje visible en lugar de la ayuda) y el foco va al primer campo con error');
escribir($navegador, '#campo-nombre', 'Nuevo equipo');
comprobarIgual([null], campos(erroresFormulario($navegador), ['nombre']), '  al corregir «Nombre», su error desaparece');

escribir($navegador, '#campo-ip', '192.168.1.300');
escribir($navegador, '#campo-ubicacion', 'Laboratorio');
clic($navegador, '#modal-dispositivo [type="submit"]');
$navegador->esperarHasta("!document.getElementById('error-ip').hidden", 5);
comprobarIgual('Dirección IP no válida', erroresFormulario($navegador)['ip'] ?? null, 'IP 192.168.1.300 → «Dirección IP no válida»');
escribir($navegador, '#campo-ip', '127.0.0.1');
clic($navegador, '#modal-dispositivo [type="submit"]');
$navegador->esperarHasta("document.getElementById('error-ip').textContent === 'Ya existe un dispositivo con esa IP'", 5);
comprobarIgual(['nombre' => null, 'ip' => 'Ya existe un dispositivo con esa IP', 'tipo' => null, 'ubicacion' => null], erroresFormulario($navegador), 'IP repetida (409) → «Ya existe un dispositivo con esa IP» debajo de la IP');

$antes = (int) valorBd('SELECT COUNT(*) FROM dispositivos');
escribir($navegador, '#campo-ip', '192.0.2.40');
clic($navegador, '[for="tipo-router"]');
$navegador->evaluar("const b = document.querySelector('#modal-dispositivo [type=submit]'); b.click(); b.click();");
comprobar(esperarModal($navegador, 'modal-dispositivo', false), 'con datos válidos, el modal se cierra');
comprobarIgual($antes + 1, (int) valorBd('SELECT COUNT(*) FROM dispositivos'), '  dos clics seguidos en «Agregar» crean un solo dispositivo');
comprobar($navegador->esperarHasta("[...document.querySelectorAll('.tabla-dispositivos tbody tr')].some((tr) => tr.textContent.includes('Nuevo equipo'))", 5), '  la tabla muestra «Nuevo equipo»');
comprobar(in_array('Dispositivo agregado', notificaciones($navegador), true), '  y la notificación «Dispositivo agregado»');
comprobarIgual(['router', 'desconocido'], [valorBd("SELECT tipo FROM dispositivos WHERE ip = '192.0.2.40'"), valorBd("SELECT estado FROM dispositivos WHERE ip = '192.0.2.40'")], '  guardado como router, «Sin monitorear»');
comprobarIgual('agregar-dispositivo', $navegador->evaluar('document.activeElement.id'), '  el foco vuelve al botón que abrió el modal');
cerrarNotificaciones($navegador);

$idNuevo = (int) valorBd("SELECT id FROM dispositivos WHERE ip = '192.0.2.40'");
clic($navegador, ".tabla-dispositivos tr[data-id=\"$idNuevo\"] [data-accion=\"editar\"]");
esperarModal($navegador, 'modal-dispositivo', true);
$r = $navegador->evaluar("(() => { const f = document.querySelector('#modal-dispositivo form'); return { titulo: document.getElementById('modal-dispositivo-titulo').textContent, nombre: f.elements.nombre.value, ip: f.elements.ip.value, tipo: f.elements.tipo.value, ubicacion: f.elements.ubicacion.value, boton: document.querySelector('#modal-dispositivo [type=submit]').textContent.trim() }; })()");
comprobarIgual(['titulo' => 'Editar dispositivo', 'nombre' => 'Nuevo equipo', 'ip' => '192.0.2.40', 'tipo' => 'router', 'ubicacion' => 'Laboratorio', 'boton' => 'Guardar cambios'], $r, '«Editar» abre el modal con los datos del dispositivo');
escribir($navegador, '#campo-nombre', 'Equipo renombrado');
clic($navegador, '#modal-dispositivo [type="submit"]');
esperarModal($navegador, 'modal-dispositivo', false);
comprobar($navegador->esperarHasta("[...document.querySelectorAll('.tabla-dispositivos .tabla-nombre')].some((a) => a.textContent === 'Equipo renombrado')", 5), '  al guardar, la tabla muestra el nombre nuevo');
comprobarIgual('Equipo renombrado', valorBd('SELECT nombre FROM dispositivos WHERE id = ?', [$idNuevo]), '  y queda en la base');

$navegador->evaluar("document.querySelector('.tabla-dispositivos tr[data-id=\"$idNuevo\"] [data-accion=\"editar\"]').focus()");
clic($navegador, ".tabla-dispositivos tr[data-id=\"$idNuevo\"] [data-accion=\"editar\"]");
esperarModal($navegador, 'modal-dispositivo', true);
$navegador->tecla('Escape');
comprobar(esperarModal($navegador, 'modal-dispositivo', false), 'Escape cierra el modal');
comprobarIgual('editar', $navegador->evaluar('document.activeElement.dataset.accion'), '  y el foco vuelve al botón «Editar» de la fila');

clic($navegador, ".tabla-dispositivos tr[data-id=\"$idNuevo\"] [data-accion=\"eliminar\"]");
esperarModal($navegador, 'modal-eliminar', true);
comprobarIgual(['Equipo renombrado', '192.0.2.40'], [$navegador->evaluar("document.getElementById('eliminar-nombre').textContent"), $navegador->evaluar("document.getElementById('eliminar-ip').textContent")], '«Eliminar» pide confirmación nombrando el dispositivo y su IP');
clic($navegador, '#confirmar-eliminar');
comprobar(esperarModal($navegador, 'modal-eliminar', false), '  al confirmar, el modal se cierra');
comprobar($navegador->esperarHasta('document.querySelectorAll(".tabla-dispositivos tbody tr").length === 6', 5), '  la fila desaparece');
comprobarIgual(0, (int) valorBd('SELECT COUNT(*) FROM dispositivos WHERE id = ?', [$idNuevo]), '  y el dispositivo ya no existe en la base');
comprobar(in_array('Dispositivo eliminado', notificaciones($navegador), true), '  notificación «Dispositivo eliminado»');
cerrarNotificaciones($navegador);

// =====================================================================
seccion('6.3 · Dispositivos: monitorear una fila y «Monitorear todos»');

$navegador->interceptar('servicios/monitoreo/', ['retener' => true]);
clic($navegador, '.tabla-dispositivos tr[data-id="1"] [data-accion="monitorear"]');
$navegador->esperarHasta("document.querySelector('.tabla-dispositivos tr[data-id=\"1\"]')?.classList.contains('fila-monitoreando')", 3);
$r = $navegador->evaluar(<<<'JS'
    (() => {
        const tr = document.querySelector('.tabla-dispositivos tr[data-id="1"]');
        return {
            insignia: tr.querySelector('.insignia-estado').textContent,
            pulso: !!tr.querySelector('.punto-estado--pulso'),
            respuesta: tr.children[5].textContent,
            girador: !!tr.querySelector('[data-accion="monitorear"] .girador'),
            editarDeshabilitado: tr.querySelector('[data-accion="editar"]').disabled,
            otrosMonitorear: [...document.querySelectorAll('.tabla-dispositivos [data-accion="monitorear"]')].every((b) => b.disabled),
        };
    })()
JS);
comprobarIgual(['insignia' => 'Monitoreando…', 'pulso' => true, 'respuesta' => 'Enviando ping…', 'girador' => true, 'editarDeshabilitado' => true, 'otrosMonitorear' => true], $r, 'durante el ping: «Monitoreando…» con pulso, «Enviando ping…», spinner, Editar/Eliminar y los demás Monitorear deshabilitados');
capturar($navegador, 'dispositivos-fila-monitoreando');
$navegador->liberar('servicios/monitoreo/');
$navegador->quitarIntercepciones();
comprobar($navegador->esperarHasta("document.querySelector('.tabla-dispositivos tr[data-id=\"1\"] .insignia-estado')?.textContent === 'Activo' && document.querySelector('.tabla-dispositivos tr[data-id=\"1\"]').children[6].textContent.startsWith('Hoy, ')", 8), 'al terminar: Mi PC «Activo» y comprobado «Hoy, HH:MM»');
comprobar(in_array('Mi PC responde', notificaciones($navegador), true), '  notificación «Mi PC responde»');
cerrarNotificaciones($navegador);

// Solo 127.0.0.1 y 192.0.2.x: las pruebas no dependen de la red local.
peticion('PUT', '/dispositivos/2', ['nombre' => 'Router principal', 'ip' => '192.0.2.30', 'tipo' => 'router', 'ubicacion' => 'Cuarto de telecomunicaciones']);
peticion('PUT', '/dispositivos/3', ['nombre' => 'PC Recepción', 'ip' => '192.0.2.31', 'tipo' => 'computadora', 'ubicacion' => 'Recepción']);
$pendientesAntes = (int) valorBd('SELECT COUNT(*) FROM alertas WHERE atendida = 0');
abrirPagina($navegador, 'dispositivos.html');
$navegador->esperarHasta("document.querySelectorAll('.tabla-dispositivos tbody tr').length === 6 && !document.getElementById('monitorear-todos-escritorio').disabled", 8);

$navegador->interceptar('servicios/monitoreo/', ['retener' => true]);
clic($navegador, '#monitorear-todos-escritorio');
$navegador->esperarHasta("!document.getElementById('progreso-monitoreo').hidden", 3);
$r = $navegador->evaluar(<<<'JS'
    ({
        boton: document.getElementById('monitorear-todos-escritorio').textContent.trim(),
        deshabilitado: document.getElementById('monitorear-todos-escritorio').disabled,
        posicion: document.querySelector('#progreso-monitoreo .progreso-monitoreo__cabecera > .progreso-monitoreo__detalle').textContent,
        fichas: document.querySelectorAll('#progreso-monitoreo .ficha').length,
        enEspera: document.querySelectorAll('#progreso-monitoreo .ficha--espera').length,
        anuncio: document.querySelector('#progreso-monitoreo [aria-live]').textContent,
        filasMonitoreando: document.querySelectorAll('.tabla-dispositivos .fila-monitoreando').length,
        agregar: document.getElementById('agregar-dispositivo').disabled,
    })
JS);
comprobarIgual(['boton' => 'Monitoreando 1 de 6…', 'deshabilitado' => true, 'posicion' => '1 de 6', 'fichas' => 6, 'enEspera' => 5, 'anuncio' => 'Comprobando Impresora Laboratorio, 1 de 6', 'filasMonitoreando' => 1, 'agregar' => false], $r,
    'en curso: botón deshabilitado «Monitoreando 1 de 6…», progreso «1 de 6» con 6 fichas (5 en espera), aviso aria-live y la fila en «monitoreando»');
$navegador->liberar('servicios/monitoreo/');
$navegador->quitarIntercepciones();
comprobar($navegador->esperarHasta("!document.getElementById('monitorear-todos-escritorio').disabled && document.getElementById('progreso-monitoreo').hidden", 40), 'termina, se oculta el progreso y el botón vuelve a habilitarse');
$titulos = notificaciones($navegador);
$textos = $navegador->evaluar("[...document.querySelectorAll('.toast .notificacion__texto')].map((t) => t.textContent)");
$nuevas = (int) valorBd('SELECT COUNT(*) FROM alertas WHERE atendida = 0') - $pendientesAntes;
comprobarIgual(4, $nuevas, '  4 alertas nuevas en la base (Impresora, Router, PC Recepción y Servidor de respaldo; el Servidor principal ya tenía una)');
comprobar(in_array('Monitoreo completado', $titulos, true) && in_array('1 de 6 dispositivos responden.', $textos, true), '  notificación «Monitoreo completado · 1 de 6 dispositivos responden.»', json_encode([$titulos, $textos], JSON_UNESCAPED_UNICODE));
comprobar(in_array('4 dispositivos no respondieron', $titulos, true), '  y una sola notificación «4 dispositivos no respondieron» con enlace a Alertas', json_encode($titulos, JSON_UNESCAPED_UNICODE));
comprobarIgual('alertas.html', $navegador->evaluar("[...document.querySelectorAll('.toast')].find((t) => t.textContent.includes('no respondieron'))?.querySelector('a')?.getAttribute('href')"), '    el enlace lleva a alertas.html');
comprobar($navegador->esperarHasta("document.querySelector('#barra-lateral .js-contador-alertas').textContent === '" . ($pendientesAntes + 4) . "'", 5), '  el contador del menú pasa a ' . ($pendientesAntes + 4));
$r = $navegador->evaluar("[...document.querySelectorAll('.tabla-dispositivos tbody tr')].map((tr) => tr.querySelector('.insignia-estado').textContent + ' · ' + tr.children[6].textContent.slice(0, 5))");
comprobar(count(array_filter($r ?? [], fn($t) => str_ends_with($t, 'Hoy, '))) === 6 && in_array('Activo · Hoy, ', $r, true), '  la tabla se actualiza: los 6 comprobados hoy (Mi PC activo)', json_encode($r, JSON_UNESCAPED_UNICODE));
cerrarNotificaciones($navegador);

$navegador->interceptar('servicios/monitoreo/1', ['codigo' => 502, 'cuerpo' => '{"ok":false,"error":"No se pudo comunicar con el Servicio de Alertas"}']);
clic($navegador, '#monitorear-todos-escritorio');
$navegador->esperarHasta("document.getElementById('monitorear-todos-escritorio').disabled", 3);
$navegador->esperarHasta("[...document.querySelectorAll('#progreso-monitoreo .ficha--fallida')].some((f) => f.title.startsWith('No se pudo comprobar'))", 15);
comprobarIgual('No se pudo comprobar: No se pudo comunicar con el Servicio de Alertas', $navegador->evaluar("[...document.querySelectorAll('#progreso-monitoreo .ficha')].find((f) => f.textContent.includes('Mi PC'))?.title"), 'si un dispositivo responde 502, su ficha queda fallida y el recorrido sigue');
comprobar($navegador->esperarHasta("!document.getElementById('monitorear-todos-escritorio').disabled", 40), '  el recorrido termina con los demás');
$textos = $navegador->evaluar("[...document.querySelectorAll('.toast .notificacion__texto')].map((t) => t.textContent)");
comprobar(in_array('0 de 6 dispositivos responden. 1 no se pudo comprobar.', $textos, true), '  y el resumen indica cuántos no se pudieron comprobar', json_encode($textos, JSON_UNESCAPED_UNICODE));
$navegador->quitarIntercepciones();
cerrarNotificaciones($navegador);

// =====================================================================
seccion('6.3 · Dispositivos: errores al cargar (servicio caído, MySQL apagado)');

$casosError = [
    ['fallar' => true, 'No se pudo conectar con el servidor. Revisa que Apache esté encendido.', 'sin conexión'],
    ['codigo' => 500, 'cuerpo' => '{"ok":false,"error":"Error interno del servidor"}', 'Error interno del servidor', 'MySQL apagado (500)'],
];
foreach ($casosError as $caso) {
    $descripcion = array_pop($caso);
    $mensaje = array_pop($caso);
    $navegador->interceptar('servicios/dispositivos', $caso);
    abrirPagina($navegador, 'dispositivos.html');
    $navegador->esperarHasta("document.querySelector('#contenido-dispositivos .vista-error')", 8);
    $r = $navegador->evaluar("({ rol: document.querySelector('#contenido-dispositivos .vista-error')?.getAttribute('role'), titulo: document.querySelector('.vista-error__titulo')?.textContent, texto: document.querySelector('.vista-error__texto')?.textContent, boton: document.querySelector('.vista-error button')?.textContent.trim(), pie: document.getElementById('pie-dispositivos').hidden, barra: !!document.querySelector('#barra-lateral .nav-lateral__enlace') })");
    comprobarIgual(['rol' => 'alert', 'titulo' => 'No se pudieron cargar los dispositivos', 'texto' => $mensaje, 'boton' => 'Reintentar', 'pie' => true, 'barra' => true], $r, "$descripcion → bloque de error con el mensaje y «Reintentar» (la estructura sigue visible, nunca en blanco)");
}
capturar($navegador, 'dispositivos-error');
$navegador->quitarIntercepciones();
clic($navegador, '.vista-error button');
comprobar($navegador->esperarHasta("document.querySelectorAll('.tabla-dispositivos tbody tr').length === 6", 8), '«Reintentar», con el servicio de vuelta, carga la tabla');

// =====================================================================
seccion('6.3 · Dispositivos en celular (390 px)');

recargarBaseDeDatos();
$navegador->tamano(390, 844, true);
abrirPagina($navegador, 'dispositivos.html');
$navegador->esperarHasta("document.querySelectorAll('.tarjeta-dispositivo-movil').length === 6", 8);
$r = $navegador->evaluar(<<<'JS'
    ({
        tabla: getComputedStyle(document.querySelector('.tabla-dispositivos')).display,
        tarjetas: document.querySelectorAll('.tarjeta-dispositivo-movil').length,
        altoBuscar: Math.round(document.getElementById('buscar-dispositivo').getBoundingClientRect().height),
        altoFicha: Math.round(document.querySelector('[for="estado-activo"]').getBoundingClientRect().height),
        botonMovil: getComputedStyle(document.getElementById('monitorear-todos-movil')).display !== 'none',
        botonEscritorio: getComputedStyle(document.getElementById('monitorear-todos-escritorio')).display,
        inactivaConBorde: document.querySelector('.tarjeta-dispositivo-movil--inactivo .tarjeta-dispositivo-movil__nombre')?.textContent,
        datos: [...document.querySelector('.tarjeta-dispositivo-movil[data-id="4"] dd')?.parentElement.parentElement.querySelectorAll('dd') ?? []].map((d) => d.textContent),
        desborde: document.documentElement.scrollWidth > innerWidth,
    })
JS);
comprobarIgual(['tabla' => 'none', 'tarjetas' => 6, 'altoBuscar' => 44, 'altoFicha' => 44, 'botonMovil' => true, 'botonEscritorio' => 'none', 'inactivaConBorde' => 'Servidor principal', 'datos' => ['Sala de servidores', 'Sin resp.', '27/09 09:45'], 'desborde' => false], $r,
    'tarjetas en lugar de tabla, controles de 44 px, «Monitorear todos» junto al tipo, borde rojo en la inactiva, datos compactos y sin desplazamiento horizontal');
clic($navegador, '[for="estado-inactivo"]');
comprobarIgual(['Servidor principal'], $navegador->evaluar("[...document.querySelectorAll('.tarjeta-dispositivo-movil__nombre')].map((n) => n.textContent)"), 'en celular también se filtra por estado (fichas)');
clic($navegador, '[for="estado-todos"]');
capturar($navegador, 'dispositivos-390');
clic($navegador, '#agregar-dispositivo');
esperarModal($navegador, 'modal-dispositivo', true);
$navegador->procesarEventos(0.4);
$r = $navegador->evaluar("(() => { const c = document.querySelector('#modal-dispositivo .modal-content').getBoundingClientRect(); return { abajo: Math.round(c.bottom), alto: innerHeight, ancho: Math.round(c.width), asa: getComputedStyle(document.querySelector('.modal-formulario__asa')).display }; })()");
comprobarIgual(['abajo' => 844, 'alto' => 844, 'ancho' => 390, 'asa' => 'block'], $r, 'el modal de agregar es una hoja inferior a todo el ancho, con su asa (2f)');
capturar($navegador, 'dispositivos-390-hoja');
$navegador->tecla('Escape');
esperarModal($navegador, 'modal-dispositivo', false);

// =====================================================================
seccion('6.3 · Seguridad: un nombre con HTML se muestra como texto');

$navegador->tamano(1440, 900);
peticion('POST', '/dispositivos', ['nombre' => XSS, 'ip' => '192.0.2.99', 'tipo' => 'servidor', 'ubicacion' => XSS]);
$idXss = (int) valorBd("SELECT id FROM dispositivos WHERE ip = '192.0.2.99'");
abrirPagina($navegador, 'dispositivos.html');
$navegador->esperarHasta("document.querySelectorAll('.tabla-dispositivos tbody tr').length === 7", 8);
$r = $navegador->evaluar("(() => { const tr = document.querySelector('.tabla-dispositivos tr[data-id=\"$idXss\"]'); return { nombre: tr.querySelector('.tabla-nombre').textContent, ubicacion: tr.children[4].textContent, imagenes: document.querySelectorAll('#contenido img').length }; })()");
comprobarIgual(['nombre' => XSS, 'ubicacion' => XSS, 'imagenes' => 0], $r, 'Dispositivos: nombre y ubicación como texto, ninguna <img> creada');
clic($navegador, ".tabla-dispositivos tr[data-id=\"$idXss\"] [data-accion=\"eliminar\"]");
esperarModal($navegador, 'modal-eliminar', true);
comprobarIgual([XSS, 0], [$navegador->evaluar("document.getElementById('eliminar-nombre').textContent"), $navegador->evaluar("document.querySelectorAll('#modal-eliminar img').length")], '  también en el modal de eliminar');
$navegador->tecla('Escape');
esperarModal($navegador, 'modal-eliminar', false);
clic($navegador, ".tabla-dispositivos tr[data-id=\"$idXss\"] [data-accion=\"monitorear\"]");
$navegador->esperarHasta("[...document.querySelectorAll('.toast .notificacion__titulo')].some((t) => t.textContent.includes('no respondió'))", 8);
comprobarIgual([XSS . ' no respondió', 0], [notificaciones($navegador)[0] ?? null, $navegador->evaluar("document.querySelectorAll('.toast img').length")], '  y en las notificaciones');
$navegador->procesarEventos(0.5);
comprobarIgual([], $navegador->dialogos, '  no se ejecutó ningún alert()');

// =====================================================================
seccion('6.4 · Dashboard: indicadores, estado, alertas y gráfica');

/**
 * Espera a que el Dashboard termine de cargar sus tres paneles (y la animación de la gráfica).
 */
function esperarDashboard(Navegador $navegador): bool
{
    $listo = $navegador->esperarHasta("!document.getElementById('panel-estado').hasAttribute('aria-busy') && !document.getElementById('panel-alertas').hasAttribute('aria-busy') && !document.getElementById('panel-grafica').hasAttribute('aria-busy')", 12);
    $navegador->procesarEventos(0.8);
    return $listo;
}

recargarBaseDeDatos();
$navegador->tamano(1440, 900);
$navegador->interceptar('servicios/dispositivos', ['retener' => true]);
abrirPagina($navegador, 'index.html');
$navegador->esperarRetenida('servicios/dispositivos'); // sale después de confirmar la sesión
comprobarIgual([4, 'true'], [$navegador->evaluar("document.querySelectorAll('.indicadores .esqueleto').length"), $navegador->evaluar("document.getElementById('panel-estado').getAttribute('aria-busy')")], 'mientras cargan: esqueleto en los indicadores y en el estado de dispositivos');
$navegador->liberar('servicios/dispositivos');
$navegador->quitarIntercepciones();
comprobar(esperarDashboard($navegador), 'los tres paneles terminan de cargar');

$r = $navegador->evaluar(<<<'JS'
    (() => {
        const t = (id) => document.getElementById(id).textContent;
        return {
            indicadores: [t('indicador-total'), t('indicador-total-detalle'), t('indicador-activos'), t('indicador-activos-detalle'), t('indicador-inactivos'), t('indicador-inactivos-detalle'), t('indicador-alertas'), t('indicador-alertas-detalle')],
            ultima: t('ultima-comprobacion'),
            resumen: t('resumen-estado'),
        };
    })()
JS);
comprobarIgual(['6', '4 tipos · 4 ubicaciones', '4', '67 % de la red responde', '1', 'Servidor principal', '1', 'Última: 27/09/2026 09:30'], $r['indicadores'] ?? null, 'indicadores y subtítulos calculados como en el diseño');
comprobarIgual(['27/09/2026 · 09:45:03', '4 activos · 1 inactivo · 1 sin monitorear'], [$r['ultima'] ?? '', $r['resumen'] ?? ''], '«Última comprobación» es la más reciente de los dispositivos, y el resumen del panel');

$r = $navegador->evaluar(<<<'JS'
    [...document.querySelectorAll('.rejilla-estado .tarjeta-estado')].map((t) => [
        t.querySelector('.tarjeta-estado__nombre').textContent,
        t.className.replace('tarjeta-estado ', ''),
        t.querySelector('.tarjeta-estado__tiempo').textContent,
        t.querySelector('.tarjeta-estado__fecha').textContent,
    ])
JS);
comprobarIgual([
    ['Impresora Laboratorio', 'tarjeta-estado--activo', '2.75 ms', '27/09 09:45'],
    ['Mi PC', 'tarjeta-estado--activo', '0.06 ms', '27/09 09:45'],
    ['PC Recepción', 'tarjeta-estado--activo', '0.07 ms', '27/09 09:45'],
    ['Router principal', 'tarjeta-estado--activo', '32.9 ms', '27/09 09:45'],
    ['Servidor de respaldo', 'tarjeta-estado--desconocido', '—', 'Nunca comprobado'],
    ['Servidor principal', 'tarjeta-estado--inactivo', 'Sin respuesta', '27/09 09:45'],
], $r, '6 tarjetas de estado: variante, tiempo y fecha compacta (sin monitorear → «Nunca comprobado»)');
comprobarIgual(['monitoreos.html?dispositivo=6', 'dashed'], [$navegador->evaluar("document.querySelector('.tarjeta-estado--desconocido a').getAttribute('href')"), $navegador->evaluar("getComputedStyle(document.querySelector('.tarjeta-estado--desconocido')).borderTopStyle")], '  la tarjeta enlaza al historial; la de «Sin monitorear» tiene borde punteado');

$r = $navegador->evaluar("({ contador: document.getElementById('contador-panel-alertas').textContent, items: [...document.querySelectorAll('.alerta-reciente')].map((a) => [a.querySelector('.alerta-reciente__nombre').textContent, a.querySelector('.insignia-estado').textContent, a.querySelector('.alerta-reciente__fecha').textContent]) })");
comprobarIgual(['contador' => '1', 'items' => [
    ['Servidor principal', 'Pendiente', '27/09/2026 09:30'],
    ['Router principal', 'Atendida', '25/09/2026 14:10'],
    ['Impresora Laboratorio', 'Atendida', '24/09/2026 11:05'],
    ['Servidor principal', 'Atendida', '22/09/2026 08:15'],
]], $r, 'últimas 4 alertas con el nombre del dispositivo, su estado y fecha; contador de pendientes en el panel');

$r = $navegador->evaluar(<<<'JS'
    (() => {
        const c = Chart.getChart(document.querySelector('#panel-grafica canvas'));
        const token = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
        return {
            series: c.data.datasets.map((d) => d.label),
            etiquetas: c.data.labels,
            colores: c.data.datasets.map((d) => d.borderColor),
            tokens: ['--color-grafica-1', '--color-grafica-2', '--color-grafica-3', '--color-grafica-4'].map(token),
            leyenda: [...document.querySelectorAll('#leyenda-grafica li')].map((l) => l.textContent),
            ultimoPunto: c.getDatasetMeta(0).data.map((p) => p.options.radius),
            animacion: c.options.animation !== false,
        };
    })()
JS);
comprobarIgual(['Impresora Laboratorio', 'Mi PC', 'PC Recepción', 'Router principal'], $r['series'] ?? null, 'la gráfica muestra solo los dispositivos que responden (4 activos)');
comprobarIgual($r['series'] ?? 'x', $r['leyenda'] ?? null, '  con su leyenda');
comprobarIgual(['09:00', '09:05', '09:10', '09:15', '09:20', '09:25', '09:30', '09:35', '09:40', '09:45'], $r['etiquetas'] ?? null, '  las últimas 10 comprobaciones, en orden, con la hora');
comprobarIgual($r['tokens'] ?? 'x', $r['colores'] ?? null, '  los colores de las series son los tokens --color-grafica-1 a 4');
comprobarIgual([0, 0, 0, 0, 0, 0, 0, 0, 0, 4], $r['ultimoPunto'] ?? null, '  solo el último punto de cada serie lleva círculo');
comprobarIgual(true, $r['animacion'] ?? null, '  con animación cuando no se pide movimiento reducido');
comprobarIgual([], $navegador->erroresJs, 'Dashboard sin errores de JavaScript');
capturar($navegador, 'dashboard-1440');

$navegador->movimientoReducido(true);
abrirPagina($navegador, 'index.html');
esperarDashboard($navegador);
comprobarIgual(false, $navegador->evaluar("Chart.getChart(document.querySelector('#panel-grafica canvas')).options.animation"), 'con prefers-reduced-motion, Chart.js no anima');
$navegador->movimientoReducido(false);

// =====================================================================
seccion('6.4 · Dashboard: cada panel falla por separado');

$navegador->interceptar('monitoreo/recientes', ['codigo' => 502, 'cuerpo' => '{"ok":false,"error":"No se pudo comunicar con el Servicio de Monitoreo"}']);
abrirPagina($navegador, 'index.html');
esperarDashboard($navegador);
comprobarIgual(['No se pudo cargar la gráfica', 6, 4], [$navegador->evaluar("document.querySelector('#panel-grafica .vista-error__titulo')?.textContent"), $navegador->evaluar("document.querySelectorAll('.rejilla-estado .tarjeta-estado').length"), $navegador->evaluar("document.querySelectorAll('.alerta-reciente').length")], 'si falla la gráfica, los dispositivos y las alertas siguen visibles');
$navegador->quitarIntercepciones();

$navegador->interceptar('servicios/alertas', ['fallar' => true]);
abrirPagina($navegador, 'index.html');
esperarDashboard($navegador);
comprobarIgual(['No se pudieron cargar las alertas', '—', 6, false], [$navegador->evaluar("document.querySelector('#panel-alertas .vista-error__titulo')?.textContent"), $navegador->evaluar("document.getElementById('indicador-alertas').textContent"), $navegador->evaluar("document.querySelectorAll('.rejilla-estado .tarjeta-estado').length"), $navegador->evaluar("document.getElementById('monitorear-todos').disabled")], 'si falla Alertas: su panel muestra el error, el indicador «—», y lo demás funciona');
$navegador->quitarIntercepciones();

$navegador->interceptar('servicios/dispositivos', ['codigo' => 500, 'cuerpo' => '{"ok":false,"error":"Error interno del servidor"}']);
abrirPagina($navegador, 'index.html');
esperarDashboard($navegador);
comprobarIgual(['No se pudieron cargar los dispositivos', 'Error interno del servidor', '—', true, 4], [$navegador->evaluar("document.querySelector('#panel-estado .vista-error__titulo')?.textContent"), $navegador->evaluar("document.querySelector('#panel-estado .vista-error__texto')?.textContent"), $navegador->evaluar("document.getElementById('indicador-total').textContent"), $navegador->evaluar("document.getElementById('monitorear-todos').disabled"), $navegador->evaluar("document.querySelectorAll('.alerta-reciente').length")], 'con MySQL apagado para Dispositivos: error con el mensaje, indicadores «—», «Monitorear todos» deshabilitado, las alertas se ven');
capturar($navegador, 'dashboard-error');
$navegador->quitarIntercepciones();
clic($navegador, '#panel-estado .vista-error button');
comprobar($navegador->esperarHasta("document.querySelectorAll('.rejilla-estado .tarjeta-estado').length === 6", 8), '«Reintentar» recarga el Dashboard');

// =====================================================================
seccion('6.4 · Dashboard: «Monitorear todos»');

peticion('PUT', '/dispositivos/2', ['nombre' => 'Router principal', 'ip' => '192.0.2.30', 'tipo' => 'router', 'ubicacion' => 'Cuarto de telecomunicaciones']);
peticion('PUT', '/dispositivos/3', ['nombre' => 'PC Recepción', 'ip' => '192.0.2.31', 'tipo' => 'computadora', 'ubicacion' => 'Recepción']);
abrirPagina($navegador, 'index.html');
esperarDashboard($navegador);
$navegador->interceptar('servicios/monitoreo/', ['retener' => true]);
clic($navegador, '#monitorear-todos');
$navegador->esperarHasta("!document.getElementById('progreso-monitoreo').hidden", 3);
comprobarIgual(['Monitoreando 1 de 6…', true, '1 de 6', 1], [
    $navegador->evaluar("document.getElementById('monitorear-todos').textContent.trim()"),
    $navegador->evaluar("document.getElementById('monitorear-todos').disabled"),
    $navegador->evaluar("document.querySelector('#progreso-monitoreo .progreso-monitoreo__cabecera > .progreso-monitoreo__detalle').textContent"),
    $navegador->evaluar("document.querySelectorAll('.rejilla-estado .tarjeta-estado--monitoreando').length"),
], 'en curso: botón deshabilitado «Monitoreando 1 de 6…», progreso «1 de 6» y la tarjeta en «monitoreando»');
capturar($navegador, 'dashboard-monitoreando');
$navegador->liberar('servicios/monitoreo/');
$navegador->quitarIntercepciones();
comprobar($navegador->esperarHasta("!document.getElementById('monitorear-todos').disabled && document.getElementById('progreso-monitoreo').hidden", 40), 'termina y el botón vuelve a habilitarse');
esperarDashboard($navegador);
comprobar(in_array('Monitoreo completado', notificaciones($navegador), true) && in_array('4 dispositivos no respondieron', notificaciones($navegador), true), '  notificaciones de resumen y de alertas nuevas', json_encode(notificaciones($navegador), JSON_UNESCAPED_UNICODE));
$r = $navegador->evaluar("({ activos: document.getElementById('indicador-activos').textContent, alertas: document.getElementById('indicador-alertas').textContent, menu: document.querySelector('#barra-lateral .js-contador-alertas').textContent, ultima: document.getElementById('ultima-comprobacion').textContent, series: Chart.getChart(document.querySelector('#panel-grafica canvas'))?.data.datasets.map((d) => d.label) })");
comprobarIgual(['1', '5', '5', ['Mi PC']], campos($r, ['activos', 'alertas', 'menu', 'series']), '  se actualizan los indicadores, el contador del menú y la gráfica (solo responde Mi PC)');
$hoy = date('d/m/Y');
comprobar(str_starts_with($r['ultima'] ?? '', $hoy), "  «Última comprobación» pasa a hoy ($hoy)", (string) ($r['ultima'] ?? ''));
cerrarNotificaciones($navegador);

// =====================================================================
seccion('6.4 · Dashboard en celular (390 px) y seguridad');

recargarBaseDeDatos();
$navegador->tamano(390, 844, true);
abrirPagina($navegador, 'index.html');
esperarDashboard($navegador);
$r = $navegador->evaluar(<<<'JS'
    (() => {
        const alto = (s) => Math.round(document.querySelector(s).getBoundingClientRect().height);
        const arriba = (s) => document.querySelector(s).getBoundingClientRect().top;
        return {
            rejilla: getComputedStyle(document.querySelector('.rejilla-estado')).display,
            filas: document.querySelectorAll('.lista-estado .fila-estado').length,
            detalle: getComputedStyle(document.getElementById('indicador-total-detalle')).display,
            boton: alto('#monitorear-todos'),
            anchoBoton: Math.round(document.getElementById('monitorear-todos').getBoundingClientRect().width),
            grafica: alto('.grafica'),
            graficaAntesDeAlertas: arriba('.panel--grafica') < arriba('.panel--alertas'),
            desborde: document.documentElement.scrollWidth > innerWidth,
        };
    })()
JS);
comprobarIgual(['rejilla' => 'none', 'filas' => 6, 'detalle' => 'none', 'boton' => 44, 'anchoBoton' => 358, 'grafica' => 190, 'graficaAntesDeAlertas' => true, 'desborde' => false], $r,
    'a 390 px (1c): filas en lugar de tarjetas, indicadores compactos, botón de 44 px a todo el ancho, gráfica de 190 px antes de las alertas, sin desplazamiento horizontal');
capturar($navegador, 'dashboard-390');

$navegador->tamano(1440, 900);
peticion('POST', '/dispositivos', ['nombre' => XSS, 'ip' => '192.0.2.99', 'tipo' => 'servidor', 'ubicacion' => 'Laboratorio']);
$idXss = (int) valorBd("SELECT id FROM dispositivos WHERE ip = '192.0.2.99'");
peticion('POST', "/monitoreo/$idXss");
abrirPagina($navegador, 'index.html');
esperarDashboard($navegador);
$r = $navegador->evaluar("({ tarjeta: [...document.querySelectorAll('.tarjeta-estado__nombre')].some((n) => n.textContent === " . json_encode(XSS) . "), inactivos: document.getElementById('indicador-inactivos-detalle').textContent, alerta: document.querySelector('.alerta-reciente__nombre').textContent, imagenes: document.querySelectorAll('#contenido img').length })");
comprobarIgual(['tarjeta' => true, 'inactivos' => XSS . ', Servidor principal', 'alerta' => XSS, 'imagenes' => 0], $r, 'Dashboard: el nombre con HTML se muestra como texto en la tarjeta, el indicador y la alerta, sin crear <img>');
$navegador->procesarEventos(0.5);
comprobarIgual([], $navegador->dialogos, '  no se ejecutó ningún alert()');

// =====================================================================
seccion('6.5 · Monitoreos: rango inicial, tabla y paginación');

/**
 * Espera a que Monitoreos termine de cargar la tabla y la gráfica.
 */
function esperarMonitoreos(Navegador $navegador): bool
{
    $listo = $navegador->esperarHasta("!document.getElementById('contenido-historial').hasAttribute('aria-busy') && !document.getElementById('contenido-historial').classList.contains('actualizando') && !document.getElementById('panel-grafica').hasAttribute('aria-busy')", 12);
    $navegador->procesarEventos(0.3);
    return $listo;
}

/**
 * Filas visibles del historial: [fecha, dispositivo, IP, resultado, tiempo].
 */
function filasHistorial(Navegador $navegador): array
{
    return $navegador->evaluar("[...document.querySelectorAll('.tabla-historial tbody tr')].map((tr) => [...tr.children].map((td) => td.textContent))") ?? [];
}

/**
 * Estado visible de la página: filtros, URL, conteo, paginación, resumen y gráfica.
 */
function estadoMonitoreos(Navegador $navegador): array
{
    return $navegador->evaluar(<<<'JS'
        (() => {
            const $ = (id) => document.getElementById(id);
            return {
                filtros: [$('filtro-dispositivo').value, $('filtro-estado').value, $('filtro-desde').value, $('filtro-hasta').value],
                url: location.search,
                conteo: $('conteo-historial').textContent,
                paginacion: $('paginacion').hidden ? null : [$('texto-paginacion').textContent, $('pagina-anterior').disabled, $('pagina-siguiente').disabled],
                resumen: ['promedio', 'maximo', 'disponibilidad'].map((c) => $(`resumen-${c}`).textContent),
                claseDisponibilidad: $('resumen-disponibilidad').className,
                grafica: !!document.querySelector('#panel-grafica canvas'),
                vacioGrafica: document.querySelector('#panel-grafica .estado-vacio__titulo')?.textContent ?? null,
                leyenda: !$('leyenda-historial').hidden,
                titulo: $('titulo-grafica').textContent,
                subtitulo: $('subtitulo-grafica').textContent,
                vacio: document.querySelector('#contenido-historial .estado-vacio__titulo')?.textContent ?? null,
                error: document.querySelector('#contenido-historial .vista-error__titulo')?.textContent ?? null,
            };
        })()
    JS) ?? [];
}

recargarBaseDeDatos();
$navegador->tamano(1440, 900);
$navegador->interceptar('servicios/monitoreo?', ['retener' => true]);
abrirPagina($navegador, 'monitoreos.html');
$navegador->esperarRetenida('servicios/monitoreo?'); // sale después de confirmar la sesión
$navegador->esperarHasta("document.getElementById('filtro-dispositivo').options.length > 1", 8);
comprobarIgual(['true', 3, 'true'], [
    $navegador->evaluar("document.getElementById('contenido-historial').getAttribute('aria-busy')"),
    $navegador->evaluar("document.querySelectorAll('#contenido-historial .esqueleto-tabla__fila').length"),
    $navegador->evaluar("document.getElementById('panel-grafica').getAttribute('aria-busy')"),
], 'mientras carga: esqueleto en la tabla y en la gráfica');
$navegador->liberar('servicios/monitoreo?');
$navegador->quitarIntercepciones();
comprobar(esperarMonitoreos($navegador), 'la tabla y la gráfica terminan de cargar');

$desdeInicial = date('Y-m-d', strtotime('-6 days'));
$hoyCampo = date('Y-m-d');
$totalInicial = (int) valorBd("SELECT COUNT(*) FROM monitoreos WHERE fecha >= '$desdeInicial 00:00:00' AND fecha <= '$hoyCampo 23:59:59'");
$r = estadoMonitoreos($navegador);
comprobarIgual([['', '', $desdeInicial, $hoyCampo], ''], campos($r, ['filtros', 'url']), "rango inicial: los últimos 7 días ($desdeInicial a $hoyCampo), sin parámetros en la URL");
comprobarIgual($totalInicial === 1 ? '1 comprobación' : "$totalInicial comprobaciones", $r['conteo'] ?? null, '  el conteo coincide con la base de datos para ese rango');
comprobarIgual([false, 'Elige un dispositivo para ver su gráfica', false, 'Tiempos de respuesta'], campos($r, ['grafica', 'vacioGrafica', 'leyenda', 'titulo']), 'sin dispositivo elegido, la gráfica pide elegir uno y no muestra leyenda');
comprobarIgual([], $navegador->erroresJs, 'Monitoreos sin errores de JavaScript');

// Con un rango fijo, los datos del seed no dependen del día en que se ejecuten las pruebas.
abrirPagina($navegador, 'monitoreos.html?desde=2026-09-22&hasta=2026-09-29');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
comprobarIgual(['56 comprobaciones', ['Mostrando 1–10 de 56 · página 1 de 6', true, false]], campos($r, ['conteo', 'paginacion']), 'desde y hasta se leen de la URL: 56 comprobaciones en 6 páginas de 10; «Anterior» deshabilitado en la primera');
comprobarIgual([['4.99 ms', '54.7 ms', '87.5 %'], 'texto-error'], campos($r, ['resumen', 'claseDisponibilidad']), 'resumen del rango: promedio, máximo y disponibilidad (roja por debajo de 95 %)');
$filas = filasHistorial($navegador);
comprobarIgual(10, count($filas), '  10 filas por página');
comprobarIgual(['27/09/2026 09:45', 'Impresora Laboratorio', '192.0.2.20', 'Activo', '2.75 ms'], $filas[0] ?? null, '  la primera fila es la más reciente, con nombre, IP, resultado y tiempo');
comprobarIgual(['27/09/2026 09:45', 'Servidor principal', '192.0.2.10', 'Inactivo', 'Sin respuesta'], $filas[1] ?? null, '  una comprobación sin respuesta dice «Sin respuesta»');
comprobarIgual('texto-mono fw-normal texto-error', $navegador->evaluar("document.querySelectorAll('.tabla-historial tbody tr')[1].children[4].firstElementChild.className"), '  … en rojo');

clic($navegador, '#pagina-siguiente');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
$hastaEnUrl = $hoyCampo === '2026-09-29' ? '' : '&hasta=2026-09-29'; // hoy es el valor por defecto y no se escribe
comprobarIgual(["?desde=2026-09-22$hastaEnUrl&pagina=2", ['Mostrando 11–20 de 56 · página 2 de 6', false, false]], campos($r, ['url', 'paginacion']), '«Siguiente» pasa a la página 2 y la guarda en la URL');
clic($navegador, '#pagina-anterior');
esperarMonitoreos($navegador);
comprobarIgual(['Mostrando 1–10 de 56 · página 1 de 6', true, false], estadoMonitoreos($navegador)['paginacion'] ?? null, '«Anterior» vuelve a la página 1');

abrirPagina($navegador, 'monitoreos.html?desde=2026-09-22&hasta=2026-09-29&pagina=6');
esperarMonitoreos($navegador);
comprobarIgual([['Mostrando 51–56 de 56 · página 6 de 6', false, true], 6], [estadoMonitoreos($navegador)['paginacion'] ?? null, count(filasHistorial($navegador))], 'la página se lee de la URL; en la última, «Siguiente» deshabilitado y 6 filas');

abrirPagina($navegador, 'monitoreos.html?desde=2026-09-22&hasta=2026-09-29&pagina=40');
esperarMonitoreos($navegador);
comprobarIgual('Mostrando 51–56 de 56 · página 6 de 6', estadoMonitoreos($navegador)['paginacion'][0] ?? null, 'una página que ya no existe lleva a la última');

abrirPagina($navegador, 'monitoreos.html?dispositivo=abc&estado=roto&desde=ayer&pagina=-3');
esperarMonitoreos($navegador);
comprobarIgual([['', '', $desdeInicial, $hoyCampo], ''], campos(estadoMonitoreos($navegador), ['filtros', 'url']), 'parámetros no válidos en la URL se ignoran y se limpian');
abrirPagina($navegador, 'monitoreos.html?dispositivo=999');
esperarMonitoreos($navegador);
comprobarIgual(['', ''], [$navegador->evaluar("document.getElementById('filtro-dispositivo').value"), $navegador->evaluar('location.search')], 'un dispositivo que no existe se ignora');

// =====================================================================
seccion('6.5 · Monitoreos: un dispositivo, su gráfica y el resumen');

abrirPagina($navegador, 'monitoreos.html?desde=2026-09-22&hasta=2026-09-29&pagina=3');
esperarMonitoreos($navegador);
escribir($navegador, '#filtro-dispositivo', '4');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
comprobarIgual("?dispositivo=4&desde=2026-09-22$hastaEnUrl", $r['url'] ?? null, 'elegir un dispositivo lo guarda en la URL y vuelve a la página 1');
comprobarIgual(['12 comprobaciones', ['Mostrando 1–10 de 12 · página 1 de 2', true, false]], campos($r, ['conteo', 'paginacion']), '  la tabla muestra solo sus comprobaciones');
comprobarIgual([['1.19 ms', '2.4 ms', '58.3 %'], 'texto-error'], campos($r, ['resumen', 'claseDisponibilidad']), '  resumen del dispositivo');
comprobarIgual([true, true, 'Tiempos de respuesta · Servidor principal', '192.0.2.10 · últimas 12 comprobaciones del rango'], campos($r, ['grafica', 'leyenda', 'titulo', 'subtitulo']), '  gráfica con su título, IP, número de comprobaciones y leyenda');
$g = $navegador->evaluar(<<<'JS'
    (() => {
        const c = Chart.getChart(document.querySelector('#panel-grafica canvas'));
        const token = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
        return {
            etiquetas: [c.data.labels[0], c.data.labels.at(-1)],
            tiempos: c.data.datasets[0].data,
            sinRespuesta: c.data.datasets[1].data.filter((v) => v !== null).length,
            colores: [c.data.datasets[0].borderColor, c.data.datasets[1].pointBackgroundColor],
            tokens: [token('--color-acento'), token('--color-inactivo')],
            etiquetaAccesible: document.querySelector('#panel-grafica canvas').getAttribute('aria-label'),
            animacion: c.options.animation !== false,
        };
    })()
JS);
comprobarIgual(['22/09 08:15', '27/09 09:45'], $g['etiquetas'] ?? null, '  la gráfica va de la más antigua a la más reciente; con varios días, la etiqueta lleva la fecha');
comprobarIgual([null, 0.92, 0.85, 1.2, 0.92, 2.4, 1.05, 0.98, null, null, null, null], $g['tiempos'] ?? null, '  los tiempos en ms; sin respuesta, un hueco en la línea…');
comprobarIgual(5, $g['sinRespuesta'] ?? null, '  … y un punto rojo sobre el eje');
comprobarIgual($g['tokens'] ?? 'x', $g['colores'] ?? null, '  colores de los tokens --color-acento y --color-inactivo');
comprobarIgual('Tiempos de respuesta de Servidor principal en sus últimas 12 comprobaciones', $g['etiquetaAccesible'] ?? null, '  la gráfica tiene una descripción accesible');
comprobarIgual(true, $g['animacion'] ?? null, '  con animación cuando no se pide movimiento reducido');

escribir($navegador, '#filtro-estado', 'inactivo');
esperarMonitoreos($navegador);
$filas = filasHistorial($navegador);
comprobarIgual([5, ['Inactivo'], "?dispositivo=4&estado=inactivo&desde=2026-09-22$hastaEnUrl"], [count($filas), array_values(array_unique(array_column($filas, 3))), $navegador->evaluar('location.search')], 'filtrar por estado «Inactivo»: solo sus 5 comprobaciones sin respuesta, y el estado en la URL');
escribir($navegador, '#filtro-estado', '');

abrirPagina($navegador, 'monitoreos.html?dispositivo=1&desde=2026-09-22&hasta=2026-09-29');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
comprobarIgual([['0.07 ms', '0.2 ms', '100 %'], 'texto-activo'], campos($r, ['resumen', 'claseDisponibilidad']), 'disponibilidad de 99 % o más, en verde (Mi PC)');
comprobarIgual(['Tiempos de respuesta · Mi PC', '127.0.0.1 · últimas 10 comprobaciones del rango'], campos($r, ['titulo', 'subtitulo']), '  un solo día: 10 comprobaciones');
comprobarIgual(['09:00', '09:45'], $navegador->evaluar("(() => { const c = Chart.getChart(document.querySelector('#panel-grafica canvas')); return [c.data.labels[0], c.data.labels.at(-1)]; })()"), '  con un solo día, la etiqueta es solo la hora');

$resumenAmbar = json_encode(['ok' => true, 'data' => ['items' => [], 'total' => 0, 'pagina' => 1, 'por_pagina' => 10, 'total_paginas' => 0, 'resumen' => ['promedio_ms' => 1.5, 'maximo_ms' => 3.1, 'disponibilidad' => 96.4]]]);
$navegador->interceptar('servicios/monitoreo?', ['codigo' => 200, 'cuerpo' => $resumenAmbar]);
abrirPagina($navegador, 'monitoreos.html?dispositivo=1');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
comprobarIgual([['1.5 ms', '3.1 ms', '96.4 %'], 'texto-alerta'], campos($r, ['resumen', 'claseDisponibilidad']), 'disponibilidad entre 95 % y 99 %, en ámbar');
comprobarIgual(['Sin comprobaciones en el rango', false], campos($r, ['vacioGrafica', 'leyenda']), 'un dispositivo sin comprobaciones en el rango: la gráfica lo dice, sin leyenda');
$navegador->quitarIntercepciones();

$navegador->movimientoReducido(true);
abrirPagina($navegador, 'monitoreos.html?dispositivo=4&desde=2026-09-22&hasta=2026-09-29');
esperarMonitoreos($navegador);
comprobarIgual(false, $navegador->evaluar("Chart.getChart(document.querySelector('#panel-grafica canvas')).options.animation"), 'con prefers-reduced-motion, la gráfica no anima');
$navegador->movimientoReducido(false);
abrirPagina($navegador, 'monitoreos.html?dispositivo=4');
esperarMonitoreos($navegador);
capturar($navegador, 'monitoreos-1440');

// =====================================================================
seccion('6.5 · Monitoreos: fechas no válidas, restablecer, vacíos y errores');

abrirPagina($navegador, 'monitoreos.html?dispositivo=4&estado=inactivo&desde=2026-09-22&hasta=2026-09-29');
esperarMonitoreos($navegador);
escribir($navegador, '#filtro-desde', '2026-09-29');
esperarMonitoreos($navegador);
escribir($navegador, '#filtro-hasta', '2026-09-23');
esperarMonitoreos($navegador);
$r = $navegador->evaluar(<<<'JS'
    ({
        mensaje: document.getElementById('error-hasta').hidden ? null : document.getElementById('error-hasta').textContent,
        invalido: [document.getElementById('filtro-hasta').classList.contains('is-invalid'), document.getElementById('filtro-hasta').getAttribute('aria-invalid')],
        desdeValido: document.getElementById('filtro-desde').classList.contains('is-invalid'),
        visible: getComputedStyle(document.getElementById('error-hasta')).display,
        bloque: document.querySelector('#contenido-historial .estado-vacio__titulo')?.textContent,
        accion: document.querySelector('#contenido-historial .estado-vacio button')?.textContent.trim(),
        paginacion: document.getElementById('paginacion').hidden,
        grafica: [document.querySelector('#panel-grafica .estado-vacio__titulo')?.textContent, document.querySelectorAll('#panel-grafica button').length, document.getElementById('titulo-grafica').textContent],
    })
JS);
comprobarIgual(['mensaje' => 'La fecha final debe ser igual o posterior a la inicial', 'invalido' => [true, 'true'], 'desdeValido' => false, 'visible' => 'block', 'bloque' => 'Revisa los filtros', 'accion' => 'Restablecer filtros', 'paginacion' => true, 'grafica' => ['Sin gráfica con estos filtros', 0, 'Tiempos de respuesta · Servidor principal']], $r, '«Hasta» antes de «Desde»: el mensaje de la API aparece bajo «Hasta», la tabla pide revisar los filtros y la gráfica no ofrece «Reintentar»');
capturar($navegador, 'monitoreos-fechas');

clic($navegador, '#restablecer-filtros');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
comprobarIgual([['', '', $desdeInicial, $hoyCampo], '', false], [$r['filtros'] ?? null, $r['url'] ?? null, $navegador->evaluar("document.getElementById('filtro-hasta').classList.contains('is-invalid')")], '«Restablecer filtros» vuelve a los últimos 7 días, limpia la URL y el error');

abrirPagina($navegador, 'monitoreos.html?desde=2026-09-28&hasta=2026-09-28');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
comprobarIgual(['Ninguna comprobación coincide', 'Restablecer filtros', '0 comprobaciones', null, ['—', '—', '—']], [...campos($r, ['vacio']), $navegador->evaluar("document.querySelector('#contenido-historial .estado-vacio button')?.textContent.trim()"), ...campos($r, ['conteo', 'paginacion', 'resumen'])], 'filtros sin resultados: «Ninguna comprobación coincide» con «Restablecer filtros», sin paginación y resumen «—»');
clic($navegador, '#contenido-historial .estado-vacio button');
esperarMonitoreos($navegador);
comprobarIgual('', $navegador->evaluar('location.search'), '  su botón restablece los filtros');

$vacio = json_encode(['ok' => true, 'data' => ['items' => [], 'total' => 0, 'pagina' => 1, 'por_pagina' => 10, 'total_paginas' => 0, 'resumen' => ['promedio_ms' => null, 'maximo_ms' => null, 'disponibilidad' => null]]]);
$navegador->interceptar('servicios/monitoreo?', ['codigo' => 200, 'cuerpo' => $vacio]);
abrirPagina($navegador, 'monitoreos.html');
esperarMonitoreos($navegador);
comprobarIgual(['Aún no hay comprobaciones en los últimos 7 días', 'index.html'], [estadoMonitoreos($navegador)['vacio'] ?? null, $navegador->evaluar("document.querySelector('#contenido-historial .estado-vacio a')?.getAttribute('href')")], 'sin comprobaciones y sin filtros: otro vacío, que lleva al Dashboard');
$navegador->quitarIntercepciones();

$navegador->interceptar('servicios/monitoreo?', ['fallar' => true]);
abrirPagina($navegador, 'monitoreos.html?dispositivo=4');
esperarMonitoreos($navegador);
$r = estadoMonitoreos($navegador);
comprobarIgual('Tiempos de respuesta · Servidor principal', $navegador->evaluar("document.getElementById('titulo-grafica').textContent"), '  la gráfica con error conserva el nombre del dispositivo');
comprobarIgual(['No se pudo cargar el historial', 'No se pudo cargar la gráfica', 'Reintentar', 'alert', true], [$r['error'] ?? null, $navegador->evaluar("document.querySelector('#panel-grafica .vista-error__titulo')?.textContent"), $navegador->evaluar("document.querySelector('#contenido-historial .vista-error button')?.textContent.trim()"), $navegador->evaluar("document.querySelector('#contenido-historial .vista-error')?.getAttribute('role')"), $navegador->evaluar("!!document.querySelector('#barra-lateral .nav-lateral__enlace')")], 'sin conexión: error en la tabla y en la gráfica, con «Reintentar» (la estructura sigue visible)');
capturar($navegador, 'monitoreos-error');
$navegador->quitarIntercepciones();
clic($navegador, '#contenido-historial .vista-error button');
esperarMonitoreos($navegador);
comprobarIgual([true, 'Tiempos de respuesta · Servidor principal'], campos(estadoMonitoreos($navegador), ['grafica', 'titulo']), '«Reintentar», con el servicio de vuelta, carga la tabla y la gráfica');

$navegador->interceptar('servicios/dispositivos', ['codigo' => 500, 'cuerpo' => '{"ok":false,"error":"Error interno del servidor"}']);
abrirPagina($navegador, 'monitoreos.html?desde=2026-09-22&hasta=2026-09-29');
esperarMonitoreos($navegador);
comprobarIgual(['Dispositivo 5', 10], [filasHistorial($navegador)[0][1] ?? null, count(filasHistorial($navegador))], 'si Dispositivos falla, el historial se ve igual (con «Dispositivo N» en lugar del nombre)');
$navegador->quitarIntercepciones();

// =====================================================================
seccion('6.5 · Monitoreos en celular (390 px) y seguridad');

/**
 * Filas compactas visibles del historial: [línea 1 (fecha, resultado, tiempo), línea 2 o null].
 */
function filasCompactas(Navegador $navegador): array
{
    return $navegador->evaluar("[...document.querySelectorAll('.lista-historial .fila-historial')].map((f) => [[...f.querySelector('.fila-historial__linea').children].map((c) => c.textContent), f.querySelector('.fila-historial__dispositivo')?.textContent ?? null])") ?? [];
}

$navegador->tamano(390, 844, true);
abrirPagina($navegador, 'monitoreos.html?dispositivo=4');
esperarMonitoreos($navegador);
$r = $navegador->evaluar(<<<'JS'
    (() => {
        const caja = (id) => document.getElementById(id).getBoundingClientRect();
        return {
            desbordePagina: document.documentElement.scrollWidth > innerWidth,
            fechasJuntas: caja('filtro-desde').top === caja('filtro-hasta').top,
            dispositivoAncho: Math.round(caja('filtro-dispositivo').width) === Math.round(caja('filtro-estado').width),
            altoControl: Math.round(caja('filtro-dispositivo').height),
            grafica: Math.round(document.querySelector('.grafica').getBoundingClientRect().height),
        };
    })()
JS);
comprobarIgual(['desbordePagina' => false, 'fechasJuntas' => true, 'dispositivoAncho' => true, 'altoControl' => 44, 'grafica' => 190], $r, 'a 390 px: filtros apilados (Desde y Hasta juntos), controles de 44 px y sin desplazamiento horizontal');

abrirPagina($navegador, 'monitoreos.html?desde=2026-09-22&hasta=2026-09-29');
esperarMonitoreos($navegador);
$r = $navegador->evaluar(<<<'JS'
    (() => {
        const tiempo = document.querySelector('.fila-historial__tiempo').getBoundingClientRect();
        const tarjeta = document.querySelector('.panel-historial').getBoundingClientRect();
        return {
            tabla: getComputedStyle(document.querySelector('.tabla-historial')).display,
            filas: document.querySelectorAll('.lista-historial .fila-historial').length,
            tiempoVisible: tiempo.right <= tarjeta.right && tiempo.width > 0,
        };
    })()
JS);
comprobarIgual(['tabla' => 'none', 'filas' => 10, 'tiempoVisible' => true], $r, 'a 390 px, el historial se muestra en filas compactas (sin tabla que se desplace) y el tiempo queda a la vista');
$filas = filasCompactas($navegador);
comprobarIgual([['27/09 09:45', 'Activo', '2.75 ms'], 'Impresora Laboratorio192.0.2.20'], $filas[0] ?? null, '  primera línea: fecha, resultado y tiempo; segunda: dispositivo e IP');
comprobarIgual([['27/09 09:45', 'Inactivo', 'Sin respuesta'], 'Servidor principal192.0.2.10'], $filas[1] ?? null, '  una comprobación sin respuesta dice «Sin respuesta»');
comprobarIgual('fila-historial__tiempo texto-error', $navegador->evaluar("document.querySelectorAll('.fila-historial__tiempo')[1].className"), '  … en rojo');
capturar($navegador, 'monitoreos-390-todos');

escribir($navegador, '#filtro-dispositivo', '4');
esperarMonitoreos($navegador);
$filas = filasCompactas($navegador);
comprobarIgual([10, [null], ['27/09 09:45', 'Inactivo', 'Sin respuesta']], [count($filas), array_values(array_unique(array_column($filas, 1))), $filas[0][0] ?? null], '  con un dispositivo elegido en el filtro, se omite la segunda línea');
capturar($navegador, 'monitoreos-390');

$navegador->tamano(1440, 900);
peticion('POST', '/dispositivos', ['nombre' => XSS, 'ip' => '192.0.2.99', 'tipo' => 'servidor', 'ubicacion' => 'Laboratorio']);
$idXss = (int) valorBd("SELECT id FROM dispositivos WHERE ip = '192.0.2.99'");
peticion('POST', "/monitoreo/$idXss");
abrirPagina($navegador, "monitoreos.html?dispositivo=$idXss");
esperarMonitoreos($navegador);
$r = $navegador->evaluar("({ opcion: document.querySelector('#filtro-dispositivo option[value=\"$idXss\"]').textContent, fila: document.querySelector('.tabla-historial tbody tr').children[1].textContent, titulo: document.getElementById('titulo-grafica').textContent, etiqueta: document.querySelector('#panel-grafica canvas')?.getAttribute('aria-label'), imagenes: document.querySelectorAll('#contenido img').length })");
comprobarIgual(['opcion' => XSS, 'fila' => XSS, 'titulo' => 'Tiempos de respuesta · ' . XSS, 'etiqueta' => 'Tiempos de respuesta de ' . XSS . ' en sus últimas 1 comprobaciones', 'imagenes' => 0], $r, 'Monitoreos: el nombre con HTML se muestra como texto en el filtro, la tabla y la gráfica, sin crear <img>');
$navegador->tamano(390, 844, true);
abrirPagina($navegador, 'monitoreos.html');
esperarMonitoreos($navegador);
comprobarIgual([XSS, 0], [$navegador->evaluar("document.querySelector('.fila-historial__nombre').textContent"), $navegador->evaluar("document.querySelectorAll('#contenido img').length")], '  también en las filas compactas del celular');
$navegador->tamano(1440, 900);
$navegador->procesarEventos(0.5);
comprobarIgual([], $navegador->dialogos, '  no se ejecutó ningún alert()');

// =====================================================================
seccion('6.6 · Alertas: pestañas, lista y contadores');

/**
 * Espera a que la lista de alertas termine de cargar.
 */
function esperarAlertas(Navegador $navegador): bool
{
    $listo = $navegador->esperarHasta("!document.getElementById('lista-alertas').hasAttribute('aria-busy')", 10);
    $navegador->procesarEventos(0.2);
    return $listo;
}

/**
 * Alertas visibles: [nombre, IP, mensaje, fecha, insignia, botón].
 */
function alertasVisibles(Navegador $navegador): array
{
    return $navegador->evaluar(<<<'JS'
        [...document.querySelectorAll('#lista-alertas .alerta')].map((a) => [
            a.querySelector('.alerta__nombre').textContent,
            a.querySelector('.alerta__ip')?.textContent ?? null,
            a.querySelector('.alerta__mensaje').textContent,
            a.querySelector('.alerta__fecha').textContent,
            a.querySelector('.alerta__acciones .insignia-estado').textContent,
            a.querySelector('.alerta__boton')?.textContent ?? null,
        ])
    JS) ?? [];
}

/**
 * Pestañas y contadores: activa, [pendientes, atendidas], clase del contador de pendientes, menú y URL.
 */
function estadoAlertas(Navegador $navegador): array
{
    return $navegador->evaluar(<<<'JS'
        (() => {
            const contador = (p) => { const c = document.getElementById(`contador-${p}`); return c.hidden ? null : c.textContent; };
            const menu = document.querySelector('#barra-lateral .js-contador-alertas');
            return {
                activa: document.querySelector('[role="tab"][aria-selected="true"]').dataset.pestana,
                contadores: [contador('pendientes'), contador('atendidas')],
                suave: document.getElementById('contador-pendientes').classList.contains('contador--suave'),
                menu: menu.hidden ? null : menu.textContent,
                url: location.search,
                panel: document.getElementById('lista-alertas').getAttribute('aria-labelledby'),
            };
        })()
    JS) ?? [];
}

recargarBaseDeDatos();
$navegador->tamano(1440, 900);
$navegador->interceptar('servicios/alertas?', ['retener' => true]);
abrirPagina($navegador, 'alertas.html');
$navegador->esperarRetenida('servicios/alertas?'); // sale después de confirmar la sesión
comprobarIgual(['true', 2], [$navegador->evaluar("document.getElementById('lista-alertas').getAttribute('aria-busy')"), $navegador->evaluar("document.querySelectorAll('#lista-alertas .alerta--esqueleto').length")], 'mientras carga: esqueleto de dos alertas');
$navegador->liberar('servicios/alertas?');
$navegador->quitarIntercepciones();
comprobar(esperarAlertas($navegador), 'la lista termina de cargar');

comprobarIgual(['activa' => 'pendientes', 'contadores' => ['1', '3'], 'suave' => true, 'menu' => '1', 'url' => '', 'panel' => 'pestana-pendientes'], estadoAlertas($navegador), 'pestaña Pendientes activa con los contadores de GET /alertas/conteo (ámbar si hay pendientes) y el del menú');
comprobarIgual([['Servidor principal', '192.0.2.10', 'El dispositivo Servidor principal no respondió al monitoreo.', '27/09/2026 09:30', 'Pendiente', 'Marcar como atendida']], alertasVisibles($navegador), '  la alerta pendiente con nombre e IP (de Dispositivos), mensaje, fecha, «Pendiente» y su botón');
comprobarIgual('Sin respuesta', $navegador->evaluar("document.querySelector('.alerta .insignia-estado--sin-respuesta').textContent"), '  y el tipo «Sin respuesta»');
comprobarIgual([], $navegador->erroresJs, 'Alertas sin errores de JavaScript');
capturar($navegador, 'alertas-1440');

clic($navegador, '#pestana-atendidas');
esperarAlertas($navegador);
comprobarIgual(['activa' => 'atendidas', 'contadores' => ['1', '3'], 'suave' => true, 'menu' => '1', 'url' => '?pestana=atendidas', 'panel' => 'pestana-atendidas'], estadoAlertas($navegador), 'la pestaña Atendidas se activa y se guarda en la URL');
comprobarIgual([
    ['Router principal', '192.168.0.1', 'El dispositivo Router principal no respondió al monitoreo.', '25/09/2026 14:10Atendida el 25/09/2026 14:32', 'Atendida', null],
    ['Impresora Laboratorio', '192.0.2.20', 'El dispositivo Impresora Laboratorio no respondió al monitoreo.', '24/09/2026 11:05Atendida el 24/09/2026 11:40', 'Atendida', null],
    ['Servidor principal', '192.0.2.10', 'El dispositivo Servidor principal no respondió al monitoreo.', '22/09/2026 08:15Atendida el 22/09/2026 09:00', 'Atendida', null],
], alertasVisibles($navegador), '  las 3 atendidas, de la más reciente a la más antigua, con la fecha de atención y sin botón');
capturar($navegador, 'alertas-atendidas');

$navegador->evaluar("document.getElementById('pestana-atendidas').focus()");
$navegador->tecla('ArrowLeft');
esperarAlertas($navegador);
comprobarIgual(['pendientes', 'pestana-pendientes', '0', '-1'], [estadoAlertas($navegador)['activa'] ?? null, $navegador->evaluar('document.activeElement.id'), $navegador->evaluar("document.getElementById('pestana-pendientes').getAttribute('tabindex')"), $navegador->evaluar("document.getElementById('pestana-atendidas').getAttribute('tabindex')")], 'con el teclado: la flecha izquierda cambia de pestaña y la enfoca (solo la activa está en el orden de tabulación)');
$navegador->tecla('End');
esperarAlertas($navegador);
comprobarIgual(['atendidas', 'pestana-atendidas'], [estadoAlertas($navegador)['activa'] ?? null, $navegador->evaluar('document.activeElement.id')], '  «Fin» va a la última pestaña');

abrirPagina($navegador, 'alertas.html?pestana=atendidas');
esperarAlertas($navegador);
comprobarIgual(['atendidas', 3], [estadoAlertas($navegador)['activa'] ?? null, count(alertasVisibles($navegador))], 'la pestaña se lee de la URL');
abrirPagina($navegador, 'alertas.html?pestana=otra');
esperarAlertas($navegador);
comprobarIgual(['pendientes', ''], campos(estadoAlertas($navegador), ['activa', 'url']), 'una pestaña no válida en la URL se ignora y se limpia');

// =====================================================================
seccion('6.6 · Alertas: marcar como atendida');

peticionInterna('POST', '/alertas', ['dispositivo_id' => 5, 'tipo' => 'sin_respuesta', 'mensaje' => 'El dispositivo Impresora Laboratorio no respondió al monitoreo.']);
peticionInterna('POST', '/alertas', ['dispositivo_id' => 2, 'tipo' => 'sin_respuesta', 'mensaje' => 'El dispositivo Router principal no respondió al monitoreo.']);
$idRouter = (int) valorBd('SELECT id FROM alertas WHERE dispositivo_id = 2 AND atendida = 0');
abrirPagina($navegador, 'alertas.html');
esperarAlertas($navegador);
comprobarIgual([['Router principal', 'Impresora Laboratorio', 'Servidor principal'], ['3', '3'], '3'], [array_column(alertasVisibles($navegador), 0), estadoAlertas($navegador)['contadores'] ?? null, estadoAlertas($navegador)['menu'] ?? null], 'con 3 pendientes: la más reciente primero, y los contadores en 3');

$navegador->interceptar('/atender', ['retener' => true]);
clic($navegador, "#lista-alertas .alerta[data-id=\"$idRouter\"] .alerta__boton");
$navegador->procesarEventos(0.2);
clic($navegador, "#lista-alertas .alerta[data-id=\"$idRouter\"] .alerta__boton");
$r = $navegador->evaluar("(() => { const b = document.querySelector('#lista-alertas .alerta[data-id=\"$idRouter\"] .alerta__boton'); return [b.textContent, b.disabled, b.getAttribute('aria-busy'), !!b.querySelector('.girador')]; })()");
comprobarIgual(['Marcando…', true, 'true', true], $r, 'en curso: el botón dice «Marcando…», con spinner, deshabilitado (el doble clic no repite la petición)');
capturar($navegador, 'alertas-marcando');
$navegador->liberar('/atender');
$navegador->quitarIntercepciones();
$navegador->esperarHasta("!document.querySelector('#lista-alertas .alerta[data-id=\"$idRouter\"]') && document.getElementById('contador-pendientes').textContent === '2'", 8);
$navegador->procesarEventos(0.3);
comprobarIgual([['Impresora Laboratorio', 'Servidor principal'], ['2', '4'], '2'], [array_column(alertasVisibles($navegador), 0), estadoAlertas($navegador)['contadores'] ?? null, estadoAlertas($navegador)['menu'] ?? null], 'al terminar: la alerta sale de Pendientes y se actualizan los contadores de las pestañas y del menú');
comprobarIgual([1, 1], [(int) valorBd("SELECT atendida FROM alertas WHERE id = $idRouter"), (int) valorBd("SELECT COUNT(*) FROM alertas WHERE dispositivo_id = 2 AND atendida = 1 AND fecha_atendida IS NOT NULL AND id = $idRouter")], '  la base de datos la guarda como atendida, con su fecha');
$textos = $navegador->evaluar("[...document.querySelectorAll('.toast')].map((t) => [t.querySelector('.notificacion__titulo').textContent, t.querySelector('.notificacion__texto').textContent, t.getAttribute('role')])");
comprobarIgual([['Alerta atendida', 'La alerta de Router principal pasó a Atendidas.', 'status']], $textos, '  notificación de éxito (role="status")');
comprobarIgual('Marcar como atendida la alerta de Impresora Laboratorio', $navegador->evaluar("document.activeElement.getAttribute('aria-label')"), '  el foco pasa al botón de la siguiente alerta');
cerrarNotificaciones($navegador);

$idImpresora = (int) valorBd('SELECT id FROM alertas WHERE dispositivo_id = 5 AND atendida = 0');
$navegador->interceptar('/atender', ['codigo' => 500, 'cuerpo' => '{"ok":false,"error":"Error interno del servidor"}']);
clic($navegador, "#lista-alertas .alerta[data-id=\"$idImpresora\"] .alerta__boton");
$navegador->esperarHasta("document.querySelector('.toast')", 5);
$r = $navegador->evaluar("(() => { const b = document.querySelector('#lista-alertas .alerta[data-id=\"$idImpresora\"] .alerta__boton'); return [b?.textContent, b?.disabled, document.querySelector('.toast .notificacion__titulo')?.textContent, document.querySelector('.toast .notificacion__texto')?.textContent, document.querySelector('.toast')?.getAttribute('role')]; })()");
comprobarIgual(['Marcar como atendida', false, 'No se pudo marcar la alerta', 'Error interno del servidor', 'alert'], $r, 'si falla (500): notificación de error con el mensaje, la alerta sigue en la lista y su botón vuelve a estar disponible');
$navegador->quitarIntercepciones();
cerrarNotificaciones($navegador);

peticion('DELETE', '/dispositivos/5'); // sus alertas (la pendiente y una atendida del seed) se borran en cascada
clic($navegador, "#lista-alertas .alerta[data-id=\"$idImpresora\"] .alerta__boton");
$navegador->esperarHasta("document.querySelector('.toast') && !document.querySelector('#lista-alertas .alerta[data-id=\"$idImpresora\"]')", 8);
$navegador->procesarEventos(0.3);
comprobarIgual(['La alerta no existe', ['Servidor principal'], ['1', '3']], [$navegador->evaluar("document.querySelector('.toast .notificacion__texto')?.textContent"), array_column(alertasVisibles($navegador), 0), estadoAlertas($navegador)['contadores'] ?? null], 'una alerta que ya no existe (404): se avisa, se quita de la lista y se actualizan los contadores');
cerrarNotificaciones($navegador);

clic($navegador, '#lista-alertas .alerta__boton');
$navegador->esperarHasta("document.querySelector('#lista-alertas .estado-vacio')", 8);
$navegador->procesarEventos(0.3);
$r = $navegador->evaluar("({ titulo: document.querySelector('#lista-alertas .estado-vacio__titulo')?.textContent, exito: !document.querySelector('#lista-alertas .estado-vacio').classList.contains('estado-vacio--neutro'), boton: document.querySelector('#lista-alertas .estado-vacio button')?.textContent.trim(), foco: document.activeElement.id })");
comprobarIgual(['titulo' => 'Toda la red responde', 'exito' => true, 'boton' => 'Ver atendidas', 'foco' => 'pestana-pendientes'], $r, 'al atender la última: vacío positivo «Toda la red responde» con «Ver atendidas»; el foco vuelve a la pestaña');
comprobarIgual([['0', '4'], false, null], campos(estadoAlertas($navegador), ['contadores', 'suave', 'menu']), '  contador de pendientes en 0 (gris) y el del menú oculto');
capturar($navegador, 'alertas-vacio');
cerrarNotificaciones($navegador);
clic($navegador, '#lista-alertas .estado-vacio button');
esperarAlertas($navegador);
comprobarIgual(['atendidas', 'pestana-atendidas', 4], [estadoAlertas($navegador)['activa'] ?? null, $navegador->evaluar('document.activeElement.id'), count(alertasVisibles($navegador))], '«Ver atendidas» abre la pestaña Atendidas');

// =====================================================================
seccion('6.6 · Alertas: vacíos y errores');

recargarBaseDeDatos();
$navegador->interceptar('servicios/alertas?', ['codigo' => 200, 'cuerpo' => '{"ok":true,"data":[]}']);
$navegador->interceptar('alertas/conteo', ['codigo' => 200, 'cuerpo' => '{"ok":true,"data":{"pendientes":0,"atendidas":0}}']);
abrirPagina($navegador, 'alertas.html');
esperarAlertas($navegador);
comprobarIgual(['Toda la red responde', 0], [$navegador->evaluar("document.querySelector('#lista-alertas .estado-vacio__titulo')?.textContent"), $navegador->evaluar("document.querySelectorAll('#lista-alertas .estado-vacio button').length")], 'sin alertas de ningún tipo: «Toda la red responde» sin «Ver atendidas»');
clic($navegador, '#pestana-atendidas');
esperarAlertas($navegador);
comprobarIgual(['Aún no hay alertas atendidas', true], [$navegador->evaluar("document.querySelector('#lista-alertas .estado-vacio__titulo')?.textContent"), $navegador->evaluar("document.querySelector('#lista-alertas .estado-vacio').classList.contains('estado-vacio--neutro')")], 'Atendidas vacía: su propio vacío (neutro)');
$navegador->quitarIntercepciones();

$navegador->interceptar('servicios/alertas?', ['fallar' => true]);
abrirPagina($navegador, 'alertas.html');
esperarAlertas($navegador);
comprobarIgual(['No se pudieron cargar las alertas', 'No se pudo conectar con el servidor. Revisa que Apache esté encendido.', 'Reintentar', 'alert', true], [
    $navegador->evaluar("document.querySelector('#lista-alertas .vista-error__titulo')?.textContent"),
    $navegador->evaluar("document.querySelector('#lista-alertas .vista-error__texto')?.textContent"),
    $navegador->evaluar("document.querySelector('#lista-alertas .vista-error button')?.textContent.trim()"),
    $navegador->evaluar("document.querySelector('#lista-alertas .vista-error')?.getAttribute('role')"),
    $navegador->evaluar("!!document.querySelector('#barra-lateral .nav-lateral__enlace') && !document.getElementById('pestana-pendientes').hidden"),
], 'sin conexión: error con el mensaje y «Reintentar»; la estructura y las pestañas siguen visibles');
capturar($navegador, 'alertas-error');
$navegador->quitarIntercepciones();
clic($navegador, '#lista-alertas .vista-error button');
esperarAlertas($navegador);
comprobarIgual(1, count(alertasVisibles($navegador)), '«Reintentar», con el servicio de vuelta, carga la lista');

$navegador->interceptar('servicios/dispositivos', ['codigo' => 500, 'cuerpo' => '{"ok":false,"error":"Error interno del servidor"}']);
$navegador->interceptar('alertas/conteo', ['fallar' => true]);
abrirPagina($navegador, 'alertas.html');
esperarAlertas($navegador);
comprobarIgual([[['Dispositivo 4', null, 'El dispositivo Servidor principal no respondió al monitoreo.', '27/09/2026 09:30', 'Pendiente', 'Marcar como atendida']], [null, null]], [alertasVisibles($navegador), estadoAlertas($navegador)['contadores'] ?? null], 'si fallan Dispositivos y el conteo: la alerta se ve con su mensaje («Dispositivo 4», sin IP) y los contadores se ocultan');
$navegador->quitarIntercepciones();

// =====================================================================
seccion('6.6 · Alertas en celular (390 px) y seguridad');

$navegador->tamano(390, 844, true);
abrirPagina($navegador, 'alertas.html');
esperarAlertas($navegador);
$r = $navegador->evaluar(<<<'JS'
    (() => {
        const caja = (s) => document.querySelector(s).getBoundingClientRect();
        return {
            desborde: document.documentElement.scrollWidth > innerWidth,
            botonDebajo: caja('.alerta__boton').top >= caja('.alerta__fecha').bottom,
            altoBoton: Math.round(caja('.alerta__boton').height),
        };
    })()
JS);
comprobarIgual(['desborde' => false, 'botonDebajo' => true, 'altoBoton' => 44], $r, 'a 390 px: la tarjeta se apila, el botón de 44 px queda debajo del texto y no hay desplazamiento horizontal');
capturar($navegador, 'alertas-390');
clic($navegador, '#pestana-atendidas');
esperarAlertas($navegador);
comprobarIgual(false, $navegador->evaluar('document.documentElement.scrollWidth > innerWidth'), '  también en Atendidas');
capturar($navegador, 'alertas-390-atendidas');

$navegador->tamano(1440, 900);
peticion('POST', '/dispositivos', ['nombre' => XSS, 'ip' => '192.0.2.99', 'tipo' => 'servidor', 'ubicacion' => 'Laboratorio']);
$idXss = (int) valorBd("SELECT id FROM dispositivos WHERE ip = '192.0.2.99'");
peticion('POST', "/monitoreo/$idXss");
abrirPagina($navegador, 'alertas.html');
esperarAlertas($navegador);
$r = $navegador->evaluar("(() => { const a = document.querySelector('#lista-alertas .alerta'); return { nombre: a.querySelector('.alerta__nombre').textContent, mensaje: a.querySelector('.alerta__mensaje').textContent, etiqueta: a.querySelector('.alerta__boton').getAttribute('aria-label'), imagenes: document.querySelectorAll('#contenido img').length }; })()");
comprobarIgual(['nombre' => XSS, 'mensaje' => 'El dispositivo ' . XSS . ' no respondió al monitoreo.', 'etiqueta' => 'Marcar como atendida la alerta de ' . XSS, 'imagenes' => 0], $r, 'Alertas: el nombre con HTML se muestra como texto en el título, el mensaje y la etiqueta del botón, sin crear <img>');
clic($navegador, '#lista-alertas .alerta .alerta__boton');
$navegador->esperarHasta("document.querySelector('.toast')", 8);
comprobarIgual(['La alerta de ' . XSS . ' pasó a Atendidas.', 0], [$navegador->evaluar("document.querySelector('.toast .notificacion__texto').textContent"), $navegador->evaluar("document.querySelectorAll('.toast img').length")], '  también en la notificación');
$navegador->procesarEventos(0.5);
comprobarIgual([], $navegador->dialogos, '  no se ejecutó ningún alert()');

// =====================================================================
seccion('6.7 · Teclado: foco visible en todas las pantallas');

recargarBaseDeDatos();
$navegador->tamano(1440, 900);
$paginasTeclado = [
    'index.html' => 'esperarDashboard',
    'dispositivos.html' => null,
    'monitoreos.html?dispositivo=4' => 'esperarMonitoreos',
    'alertas.html' => 'esperarAlertas',
];
foreach ($paginasTeclado as $pagina => $espera) {
    abrirPagina($navegador, $pagina);
    if ($espera !== null) {
        $espera($navegador);
    } else {
        $navegador->esperarHasta("document.querySelectorAll('.tabla-dispositivos tbody tr').length === 6", 8);
    }
    $sinFoco = [];
    $recorridos = [];
    for ($i = 0; $i < 40; $i++) {
        $navegador->tecla('Tab');
        $navegador->procesarEventos(0.2); // la sombra de foco de los campos tiene una transición de 0.15 s
        // Un indicador visible: contorno, sombra de foco o un cambio de borde propio del control.
        $r = $navegador->evaluar(<<<'JS'
            (() => {
                const el = document.activeElement;
                if (!el || el === document.body) return null;
                const e = getComputedStyle(el);
                const visible = (e.outlineStyle !== 'none' && parseFloat(e.outlineWidth) > 0) || /\b[1-9]\d*(\.\d+)?px\b/.test(e.boxShadow.replace(/0px/g, ''));
                // En un campo de fecha, el foco puede estar en su botón de calendario (interno del
                // navegador): el campo no coincide con :focus y el anillo nativo lo lleva ese botón.
                const calendario = el.type === 'date' && !el.matches(':focus');
                return { nombre: `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} «${(el.getAttribute('aria-label') || el.textContent || el.value || '').trim().slice(0, 30)}»`, visible: visible || calendario, focoVisible: calendario || el.matches(':focus-visible') };
            })()
        JS);
        if ($r === null) {
            break; // volvió al inicio
        }
        $recorridos[] = $r['nombre'];
        if (!$r['visible'] || !$r['focoVisible']) {
            $sinFoco[] = $r['nombre'];
        }
    }
    comprobar(count($recorridos) >= 5 && $sinFoco === [], "$pagina: los " . count($recorridos) . ' elementos que se alcanzan con Tab muestran el foco', implode(', ', $sinFoco));
}
$navegador->evaluar('document.activeElement.blur()');

// =====================================================================
seccion('8.1 · Títulos de pestaña, favicon y resoluciones de proyector');

$titulos = ['login.html' => 'Iniciar sesión', 'index.html' => 'Dashboard', 'dispositivos.html' => 'Dispositivos', 'monitoreos.html' => 'Monitoreos', 'alertas.html' => 'Alertas', 'componentes.html' => 'Componentes'];
foreach ($titulos as $pagina => $titulo) {
    $html = (string) file_get_contents(__DIR__ . "/../web/$pagina");
    comprobar(str_contains($html, "<title>$titulo · Monitoreo de Red SOA</title>") && str_contains($html, '<link rel="icon" href="favicon.svg" type="image/svg+xml">'), "$pagina: título «{$titulo} · Monitoreo de Red SOA» y favicon");
}

// La sesión de las secciones anteriores sigue abierta.
foreach ([[1024, 768], [1280, 720]] as [$ancho, $alto]) {
    $navegador->tamano($ancho, $alto);
    foreach (['index.html', 'dispositivos.html', 'monitoreos.html?dispositivo=4', 'alertas.html', 'login.html?volver=x'] as $pagina) {
        abrirPagina($navegador, $pagina);
        $navegador->procesarEventos(1.2);
        comprobarIgual(false, $navegador->evaluar('document.documentElement.scrollWidth > innerWidth'), "{$ancho}×{$alto} " . strtok($pagina, '?') . ': sin desplazamiento horizontal');
    }
    abrirPagina($navegador, 'dispositivos.html');
    $navegador->esperarHasta("document.querySelectorAll('.tabla-dispositivos tbody tr').length === 6", 8);
    $r = $navegador->evaluar("(() => { const t = document.querySelector('.tabla-dispositivos'); const b = document.querySelector('.tabla-dispositivos tbody tr:first-child .tabla-acciones').getBoundingClientRect(); return { cabe: t.scrollWidth <= t.clientWidth, acciones: b.right <= t.getBoundingClientRect().right }; })()");
    comprobarIgual(['cabe' => true, 'acciones' => true], $r, "  Dispositivos a {$ancho} px: la tabla cabe y los botones Monitorear/Editar/Eliminar quedan a la vista");
    abrirPagina($navegador, 'monitoreos.html');
    $navegador->esperarHasta("!document.getElementById('contenido-historial').hasAttribute('aria-busy')", 8);
    $r = $navegador->evaluar("(() => { const h = document.getElementById('filtro-hasta').getBoundingClientRect(); const b = document.getElementById('restablecer-filtros').getBoundingClientRect(); return h.right <= b.left || h.bottom <= b.top || b.bottom <= h.top; })()");
    comprobar($r === true, "  Monitoreos a {$ancho} px: «Hasta» no se encima con «Restablecer filtros»");
    capturar($navegador, "proyector-$ancho-monitoreos");
}
$navegador->tamano(1440, 900);

// =====================================================================
seccion('7.2 · «Salir» cierra la sesión (barra lateral y menú del celular)');

$navegador->tamano(1440, 900);
abrirPagina($navegador, 'alertas.html');
esperarEstructura($navegador);
clic($navegador, '#barra-lateral .usuario-barra__salir');
comprobar($navegador->esperarHasta("location.pathname.endsWith('/login.html')", 8), '«Salir» de la barra lateral lleva al login');
comprobarIgual('', $navegador->evaluar('location.search'), '  sin «volver»');
abrirPagina($navegador, 'alertas.html');
comprobar($navegador->esperarHasta("location.pathname.endsWith('/login.html') && location.search.includes('alertas.html')", 8), '  la sesión quedó cerrada: Alertas vuelve a pedir el login');

$navegador->tamano(390, 844, true);
comprobar(iniciarSesionEnLaPantalla($navegador), 'otra vez con sesión, en el celular');
clic($navegador, '#encabezado-movil .encabezado-movil__boton');
comprobarIgual(['Administrador', 'admin'], [$navegador->evaluar("document.querySelector('#menu-movil .js-usuario-nombre').textContent"), $navegador->evaluar("document.querySelector('#menu-movil .js-usuario-usuario').textContent")], '  el menú del celular también muestra el usuario real');
clic($navegador, '#menu-movil .menu-movil__salir');
comprobar($navegador->esperarHasta("location.pathname.endsWith('/login.html') && location.search === ''", 8), '«Salir» del menú del celular lleva al login');
$navegador->tamano(1440, 900);

$navegador->cerrar();
echo "\nCapturas: $carpetaCapturas\n";
terminarPruebas();
