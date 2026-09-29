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

$ejecutable = Navegador::buscarEjecutable();
if ($ejecutable === null) {
    avisar('Pruebas de interfaz omitidas: no se encontró Chrome, Chromium ni Edge (se puede indicar con CHROME_BIN).');
    terminarPruebas();
}

echo "Recargando la base de datos (schema.sql + seed.sql)...\n";
recargarBaseDeDatos();

$navegador = new Navegador($ejecutable);
echo "Chrome de pruebas: perfil temporal y puerto propio ({$navegador->puerto()}).\n";

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
 * Importa un módulo de web/js/ en la página y evalúa código con él (variable m).
 */
function conModulo(Navegador $navegador, string $modulo, string $codigo): mixed
{
    return $navegador->evaluar("import('./js/$modulo').then(async (m) => { $codigo })");
}

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

$navegador->cerrar();
terminarPruebas();
