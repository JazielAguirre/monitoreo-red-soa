<?php
/**
 * Pruebas del Servicio de Monitoreo (docs/PLAN.md, Fase 4 · "Listo cuando").
 * Recarga la base de datos al empezar: borra los datos agregados a mano.
 *
 *   /opt/lampp/bin/php pruebas/monitoreo.php
 *
 * Solo usa 127.0.0.1 (siempre responde) y 192.0.2.x (nunca responde), para que
 * las pruebas pasen en cualquier red o sin red.
 *
 * Para simular que Dispositivos o Alertas no responde (502) escribe el archivo
 * de fallo simulado de comun/http.php, que caduca solo y se borra al terminar.
 */

declare(strict_types=1);

require_once __DIR__ . '/ayudas.php';
require_once __DIR__ . '/../servicios/comun/http.php';

const CLAVES_MONITOREO = ['id', 'dispositivo_id', 'ip', 'estado', 'tiempo_respuesta_ms', 'fecha'];
const CLAVES_HISTORIAL = ['items', 'total', 'pagina', 'por_pagina', 'total_paginas', 'resumen'];

// ---------------------------------------------------------------- Cálculos de referencia

/**
 * Monitoreos que cumplen un WHERE, calculados con SQL propio de la prueba,
 * del más reciente al más antiguo.
 */
function monitoreosEsperados(string $donde = '1 = 1', array $parametros = []): array
{
    return filasBd(
        "SELECT id, dispositivo_id, estado, tiempo_respuesta_ms, fecha FROM monitoreos WHERE $donde ORDER BY fecha DESC, id DESC",
        $parametros
    );
}

/**
 * Resumen calculado a mano en PHP a partir de las filas (independiente del SQL del servicio).
 */
function resumenManual(array $filas): array
{
    if ($filas === []) {
        return ['promedio_ms' => null, 'maximo_ms' => null, 'disponibilidad' => null];
    }

    $tiempos = array_map(
        fn($f) => (float) $f['tiempo_respuesta_ms'],
        array_filter($filas, fn($f) => $f['estado'] === 'activo')
    );

    return [
        'promedio_ms' => $tiempos === [] ? null : round(array_sum($tiempos) / count($tiempos), 2),
        'maximo_ms' => $tiempos === [] ? null : max($tiempos),
        'disponibilidad' => round(count($tiempos) * 100 / count($filas), 1),
    ];
}

/**
 * Compara dos resúmenes sin distinguir 2 de 2.0 (en JSON son el mismo número).
 */
function comprobarResumen(array $esperado, mixed $obtenido, string $descripcion): bool
{
    $normalizar = fn($r) => is_array($r) ? array_map(fn($v) => $v === null ? null : round((float) $v, 2), $r) : $r;

    return comprobarIgual($normalizar($esperado), $normalizar($obtenido), $descripcion);
}

function idsItems(array $respuesta): array
{
    return array_column($respuesta['json']['data']['items'] ?? [], 'id');
}

function contarMonitoreosBd(?int $dispositivoId = null): int
{
    return $dispositivoId === null
        ? (int) valorBd('SELECT COUNT(*) FROM monitoreos')
        : (int) valorBd('SELECT COUNT(*) FROM monitoreos WHERE dispositivo_id = ?', [$dispositivoId]);
}

function ultimoMonitoreoBd(int $dispositivoId): array
{
    return filasBd('SELECT id, estado, fecha FROM monitoreos WHERE dispositivo_id = ? ORDER BY id DESC LIMIT 1', [$dispositivoId])[0] ?? [];
}

// =====================================================================

echo "Pruebas del Servicio de Monitoreo\n";
quitarFalloSimulado();
register_shutdown_function('quitarFalloSimulado');
echo "Recargando la base de datos (schema.sql + seed.sql)...\n";
recargarBaseDeDatos();
iniciarSesionDePrueba();

// =====================================================================
seccion('1. GET /monitoreo respeta cada filtro; desde > hasta → 400; la paginación da total y total_paginas correctos');

$r = peticion('GET', '/monitoreo');
comprobarRespuesta($r, 200, 'GET /monitoreo');
$datos = $r['json']['data'] ?? [];
comprobarIgual(CLAVES_HISTORIAL, is_array($datos) ? array_keys($datos) : null, '  data = { items, total, pagina, por_pagina, total_paginas, resumen }');
comprobarIgual([56, 1, 10, 6], campos($datos, ['total', 'pagina', 'por_pagina', 'total_paginas']), '  56 monitoreos del seed: página 1 de 6, 10 por página');
comprobarIgual(array_slice(array_column(monitoreosEsperados(), 'id'), 0, 10), idsItems($r), '  los 10 más recientes, en orden');
comprobarIgual([
    'id' => 56, 'dispositivo_id' => 5, 'ip' => '192.0.2.20', 'estado' => 'activo',
    'tiempo_respuesta_ms' => 2.75, 'fecha' => '2026-09-27 09:45:03',
], $datos['items'][0] ?? null, '  el primero es idéntico al seed (tiempo como número)');
comprobarIgual(
    ['id' => 55, 'dispositivo_id' => 4, 'ip' => '192.0.2.10', 'estado' => 'inactivo', 'tiempo_respuesta_ms' => null, 'fecha' => '2026-09-27 09:45:01'],
    $datos['items'][1] ?? null,
    '  un inactivo lleva tiempo_respuesta_ms null'
);
$formas = array_unique(array_map(fn($i) => serialize(is_array($i) ? array_keys($i) : $i), $datos['items'] ?? []));
comprobar(count($formas) === 1 && unserialize(reset($formas)) === CLAVES_MONITOREO, '  cada elemento tiene exactamente las 6 claves del objeto monitoreo');

$casosFiltros = [
    ['dispositivo=4', 'dispositivo_id = 4', [], 'del Servidor principal'],
    ['estado=inactivo', "estado = 'inactivo'", [], 'solo inactivos'],
    ['estado=activo&dispositivo=2', "estado = 'activo' AND dispositivo_id = 2", [], 'activos del Router'],
    ['desde=2026-09-27', "fecha >= '2026-09-27 00:00:00'", [], 'desde el 27 de septiembre'],
    ['hasta=2026-09-25', "fecha < '2026-09-26 00:00:00'", [], 'hasta el 25 de septiembre (incluido)'],
    ['desde=2026-09-24&hasta=2026-09-25', "fecha >= '2026-09-24' AND fecha < '2026-09-26'", [], 'del 24 al 25'],
    ['desde=2026-09-22&hasta=2026-09-22', "DATE(fecha) = '2026-09-22'", [], 'un solo día, incluido en ambos extremos'],
    ['dispositivo=4&estado=inactivo&desde=2026-09-27', "dispositivo_id = 4 AND estado = 'inactivo' AND fecha >= '2026-09-27'", [], 'tres filtros a la vez'],
];
foreach ($casosFiltros as [$consulta, $donde, $parametros, $descripcion]) {
    $esperados = array_column(monitoreosEsperados($donde, $parametros), 'id');
    $r = peticion('GET', "/monitoreo?$consulta&por_pagina=100");
    comprobarRespuesta($r, 200, "GET /monitoreo?$consulta");
    comprobarIgual($esperados, idsItems($r), '  ' . count($esperados) . " monitoreos $descripcion (igual que el SQL de la prueba)");
    comprobarIgual(count($esperados), $r['json']['data']['total'] ?? null, '  total = ' . count($esperados));
}
comprobarIgual(2, count(idsItems(peticion('GET', '/monitoreo?desde=2026-09-22&hasta=2026-09-22'))), 'el 22 de septiembre tiene exactamente 2 monitoreos');
foreach (['dispositivo=6' => 'dispositivo sin monitoreos', 'desde=2026-09-28' => 'rango sin monitoreos', 'dispositivo=999' => 'dispositivo que no existe'] as $consulta => $descripcion) {
    $r = peticion('GET', "/monitoreo?$consulta");
    comprobarIgual([[], 0, 0], campos($r['json']['data'] ?? null, ['items', 'total', 'total_paginas']), "GET ?$consulta ($descripcion) → items [], total 0, total_paginas 0");
}

$todos = array_column(monitoreosEsperados(), 'id');
$paginas = [];
foreach ([1, 2, 3] as $pagina) {
    $r = peticion('GET', "/monitoreo?por_pagina=24&pagina=$pagina");
    $paginas[$pagina] = idsItems($r);
    comprobarIgual([56, $pagina, 24, 3], campos($r['json']['data'] ?? null, ['total', 'pagina', 'por_pagina', 'total_paginas']), "GET ?por_pagina=24&pagina=$pagina → total 56, 3 páginas");
}
comprobarIgual([24, 24, 8], array_map('count', array_values($paginas)), '  las páginas traen 24, 24 y 8');
comprobarIgual($todos, array_merge(...array_values($paginas)), '  juntas, sin repetidos ni huecos, son los 56 en orden');
$r = peticion('GET', '/monitoreo?por_pagina=24&pagina=4');
comprobarIgual([[], 56, 4, 3], campos($r['json']['data'] ?? null, ['items', 'total', 'pagina', 'total_paginas']), 'página 4 (fuera de rango) → 200 con items []');
$r = peticion('GET', '/monitoreo?por_pagina=100');
comprobarIgual([56, 1], [count(idsItems($r)), $r['json']['data']['total_paginas'] ?? null], 'por_pagina=100 → los 56 en una página');
$r = peticion('GET', '/monitoreo?dispositivo=&estado=&desde=&hasta=&pagina=&por_pagina=');
comprobarIgual([56, 1, 10], campos($r['json']['data'] ?? null, ['total', 'pagina', 'por_pagina']), 'parámetros vacíos → sin filtro, valores por defecto');

$mensajeFecha = 'Fecha no válida, usa el formato YYYY-MM-DD';
$filtrosInvalidos = [
    ['dispositivo=abc', 'dispositivo', 'Id de dispositivo no válido'],
    ['dispositivo=0', 'dispositivo', 'Id de dispositivo no válido'],
    ['estado=desconocido', 'estado', 'Estado no válido'],
    ['estado=ACTIVO', 'estado', 'Estado no válido'],
    ['desde=2026-13-01', 'desde', $mensajeFecha],
    ['desde=27/09/2026', 'desde', $mensajeFecha],
    ['desde=2026-09-27%2010:00:00', 'desde', $mensajeFecha],
    ['hasta=2026-02-30', 'hasta', $mensajeFecha],
    ['desde=2026-09-27&hasta=2026-09-26', 'hasta', 'La fecha final debe ser igual o posterior a la inicial'],
    ['pagina=0', 'pagina', 'La página debe ser un número entero mayor o igual a 1'],
    ['pagina=-1', 'pagina', 'La página debe ser un número entero mayor o igual a 1'],
    ['pagina=1.5', 'pagina', 'La página debe ser un número entero mayor o igual a 1'],
    ['pagina=abc', 'pagina', 'La página debe ser un número entero mayor o igual a 1'],
    ['por_pagina=0', 'por_pagina', 'Debe ser un número entre 1 y 100'],
    ['por_pagina=101', 'por_pagina', 'Debe ser un número entre 1 y 100'],
    ['por_pagina=diez', 'por_pagina', 'Debe ser un número entre 1 y 100'],
];
foreach ($filtrosInvalidos as [$consulta, $campo, $mensaje]) {
    comprobarErrorCampo(peticion('GET', "/monitoreo?$consulta"), 400, $campo, $mensaje, "GET /monitoreo?$consulta");
}
comprobarIgual('Filtros no válidos', peticion('GET', '/monitoreo?pagina=0')['json']['error'] ?? null, 'mensaje general: "Filtros no válidos"');

// =====================================================================
seccion('2. El resumen ignora el filtro de estado y la paginación, y coincide con un cálculo manual');

$casosResumen = [
    ['', '1 = 1', 'sin filtros'],
    ['dispositivo=4', 'dispositivo_id = 4', 'Servidor principal'],
    ['dispositivo=2', 'dispositivo_id = 2', 'Router'],
    ['desde=2026-09-27', "fecha >= '2026-09-27'", 'desde el 27'],
    ['dispositivo=5&hasta=2026-09-24', "dispositivo_id = 5 AND fecha < '2026-09-25'", 'Impresora hasta el 24'],
];
foreach ($casosResumen as [$consulta, $donde, $descripcion]) {
    $esperado = resumenManual(monitoreosEsperados($donde));
    comprobarResumen($esperado, peticion('GET', "/monitoreo?$consulta")['json']['data']['resumen'] ?? null, "resumen $descripcion = " . exportar($esperado));
    foreach (['estado=activo', 'estado=inactivo', 'pagina=2', 'estado=activo&pagina=3&por_pagina=5'] as $extra) {
        comprobarResumen($esperado, peticion('GET', "/monitoreo?$consulta&$extra")['json']['data']['resumen'] ?? null, "  igual con $extra");
    }
}
comprobarResumen(['promedio_ms' => 1.19, 'maximo_ms' => 2.4, 'disponibilidad' => 58.3],
    peticion('GET', '/monitoreo?dispositivo=4')['json']['data']['resumen'] ?? null, 'Servidor principal: 7 de 12 activos → promedio 8.32 / 7 = 1.19, máximo 2.4, disponibilidad 58.3');
comprobarResumen(['promedio_ms' => null, 'maximo_ms' => null, 'disponibilidad' => null],
    peticion('GET', '/monitoreo?dispositivo=6')['json']['data']['resumen'] ?? null, 'sin monitoreos → los tres en null');

// =====================================================================
seccion('3. GET /monitoreo/recientes: cada lista en orden cronológico ascendente y respeta limite');

/**
 * Recientes calculados a mano: los últimos $limite de cada dispositivo, en orden ascendente.
 */
function recientesManual(int $limite): array
{
    $grupos = [];
    foreach (filasBd('SELECT id, dispositivo_id FROM monitoreos ORDER BY dispositivo_id, fecha DESC, id DESC') as $fila) {
        $grupos[(int) $fila['dispositivo_id']][] = (int) $fila['id'];
    }

    return array_map(fn($ids) => array_reverse(array_slice($ids, 0, $limite)), $grupos);
}

function recientesObtenidos(array $respuesta): array
{
    $resultado = [];
    foreach ($respuesta['json']['data'] ?? [] as $grupo) {
        $resultado[$grupo['dispositivo_id'] ?? '?'] = array_column($grupo['monitoreos'] ?? [], 'id');
    }

    return $resultado;
}

$r = peticion('GET', '/monitoreo/recientes');
comprobarRespuesta($r, 200, 'GET /monitoreo/recientes');
comprobarIgual([1, 2, 3, 4, 5], array_column($r['json']['data'] ?? [], 'dispositivo_id'), '  dispositivos 1 a 5 (el Servidor de respaldo no tiene monitoreos y no aparece)');
comprobarIgual(['dispositivo_id', 'monitoreos'], array_keys($r['json']['data'][0] ?? []), '  cada grupo = { dispositivo_id, monitoreos }');
comprobarIgual(recientesManual(10), recientesObtenidos($r), '  los últimos 10 de cada uno, igual que el cálculo manual');
$ascendente = true;
foreach ($r['json']['data'] ?? [] as $grupo) {
    $fechas = array_column($grupo['monitoreos'] ?? [], 'fecha');
    $ordenadas = $fechas;
    sort($ordenadas);
    $ascendente = $ascendente && $fechas === $ordenadas;
}
comprobar($ascendente, '  cada lista va en orden cronológico ascendente (lista para graficar)');
comprobarIgual(CLAVES_MONITOREO, array_keys($r['json']['data'][0]['monitoreos'][0] ?? []), '  los elementos son objetos monitoreo');
$router = $r['json']['data'][1]['monitoreos'] ?? [];
comprobarIgual(['2026-09-27 09:00:00', '2026-09-27 09:45:00'], [$router[0]['fecha'] ?? null, $router[9]['fecha'] ?? null], '  el Router tiene 12 y devuelve los 10 últimos (09:00 a 09:45)');

foreach ([3, 1, 50] as $limite) {
    $r = peticion('GET', "/monitoreo/recientes?limite=$limite");
    comprobarIgual(recientesManual($limite), recientesObtenidos($r), "GET /monitoreo/recientes?limite=$limite → los últimos $limite de cada uno");
}
foreach (['0', '51', 'abc', '2.5', '-1'] as $limite) {
    comprobarErrorCampo(peticion('GET', "/monitoreo/recientes?limite=$limite"), 400, 'limite', 'El límite debe ser un número entre 1 y 50', "GET /monitoreo/recientes?limite=$limite");
}

// =====================================================================
seccion('4. Monitorear Mi PC (127.0.0.1) → 201 activo con tiempo; el dispositivo queda con el mismo tiempo y fecha');

$antes = contarMonitoreosBd(1);
$r = peticion('POST', '/monitoreo/1');
comprobarRespuesta($r, 201, 'POST /monitoreo/1');
$monitoreo = $r['json']['data']['monitoreo'] ?? [];
comprobarIgual(['monitoreo', 'alerta_creada'], array_keys($r['json']['data'] ?? []), '  data = { monitoreo, alerta_creada }');
comprobarIgual(CLAVES_MONITOREO, is_array($monitoreo) ? array_keys($monitoreo) : null, '  monitoreo con las 6 claves del objeto');
$tiempo = $monitoreo['tiempo_respuesta_ms'] ?? null;
comprobar((is_int($tiempo) || is_float($tiempo)) && $tiempo >= 0, '  activo con un tiempo numérico ≥ 0', exportar($monitoreo));
comprobarIgual([1, '127.0.0.1', 'activo', false], [$monitoreo['dispositivo_id'] ?? null, $monitoreo['ip'] ?? null, $monitoreo['estado'] ?? null, $r['json']['data']['alerta_creada'] ?? null], '  dispositivo 1, IP 127.0.0.1, activo y alerta_creada false');
$fechaMonitoreo = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($monitoreo['fecha'] ?? ''), new DateTimeZone('America/Mazatlan'));
comprobar($fechaMonitoreo !== false && abs($fechaMonitoreo->getTimestamp() - time()) <= 60, '  fecha generada por PHP, en hora de America/Mazatlan (±60 s)', exportar($monitoreo['fecha'] ?? null));
comprobarIgual($antes + 1, contarMonitoreosBd(1), '  quedó guardado en la tabla monitoreos');
$d = peticion('GET', '/dispositivos/1')['json']['data'] ?? [];
comprobarIgual(['activo', $monitoreo['fecha'] ?? 'x'], [$d['estado'] ?? null, $d['ultima_comprobacion'] ?? null], '  el dispositivo queda activo, con ultima_comprobacion = fecha del monitoreo');
comprobar(is_numeric($d['ultimo_tiempo_ms'] ?? null) && (float) $d['ultimo_tiempo_ms'] === (float) $tiempo, '  y ultimo_tiempo_ms = tiempo del monitoreo', exportar([$d['ultimo_tiempo_ms'] ?? null, $tiempo]));
comprobarIgual($monitoreo['id'] ?? null, idsItems(peticion('GET', '/monitoreo?dispositivo=1'))[0] ?? null, '  aparece primero en el historial de Mi PC');

// =====================================================================
seccion('5. Monitorear la Impresora (192.0.2.20) → inactivo, alerta_creada: true; una segunda vez → false');

$inicio = microtime(true);
$r = peticion('POST', '/monitoreo/5');
$duracion = microtime(true) - $inicio;
comprobarRespuesta($r, 201, 'POST /monitoreo/5');
$monitoreo = $r['json']['data']['monitoreo'] ?? [];
comprobarIgual(['inactivo', null, true], [$monitoreo['estado'] ?? null, array_key_exists('tiempo_respuesta_ms', $monitoreo) ? $monitoreo['tiempo_respuesta_ms'] : 'falta', $r['json']['data']['alerta_creada'] ?? null], '  inactivo, tiempo null y alerta_creada true');
comprobar($duracion < 3, sprintf('  tardó %.2f s, menos de 3 (ping de 1 s como máximo)', $duracion));
$d = peticion('GET', '/dispositivos/5')['json']['data'] ?? [];
comprobarIgual(['inactivo', null, $monitoreo['fecha'] ?? 'x'], campos($d, ['estado', 'ultimo_tiempo_ms', 'ultima_comprobacion']), '  la Impresora queda inactiva, sin tiempo y con la fecha del monitoreo');
$alertas = peticion('GET', '/alertas?dispositivo=5&atendida=0')['json']['data'] ?? [];
comprobarIgual(1, count($alertas), '  tiene una alerta pendiente');
comprobarIgual('El dispositivo Impresora Laboratorio no respondió al monitoreo.', $alertas[0]['mensaje'] ?? null, '  con el mensaje de API.md');
$diferencia = abs(strtotime($alertas[0]['fecha'] ?? '2000-01-01') - strtotime($monitoreo['fecha'] ?? '2000-01-01'));
comprobar($diferencia <= 5, '  la fecha de la alerta la genera Alertas, segundos después del monitoreo', "diferencia: $diferencia s");

$r = peticion('POST', '/monitoreo/5');
comprobarRespuesta($r, 201, 'POST /monitoreo/5 otra vez');
comprobarIgual(['inactivo', false], [$r['json']['data']['monitoreo']['estado'] ?? null, $r['json']['data']['alerta_creada'] ?? null], '  inactivo y alerta_creada false');
comprobarIgual(1, count(peticion('GET', '/alertas?dispositivo=5&atendida=0')['json']['data'] ?? []), '  sigue con una sola alerta pendiente');

$r = peticion('POST', '/monitoreo/4');
comprobarIgual(['inactivo', false], [$r['json']['data']['monitoreo']['estado'] ?? null, $r['json']['data']['alerta_creada'] ?? null], 'Servidor principal (ya tenía la pendiente del seed) → alerta_creada false');
$r = peticion('POST', '/monitoreo/6');
comprobarIgual(['inactivo', true], [$r['json']['data']['monitoreo']['estado'] ?? null, $r['json']['data']['alerta_creada'] ?? null], 'Servidor de respaldo (desconocido) → inactivo y alerta_creada true');
comprobarIgual('inactivo', peticion('GET', '/dispositivos/6')['json']['data']['estado'] ?? null, '  deja de estar desconocido');

// =====================================================================
seccion('6. Dispositivo inexistente → 404; rutas y métodos');

$total = contarMonitoreosBd();
comprobarRespuesta(peticion('POST', '/monitoreo/999'), 404, 'POST /monitoreo/999', 'El dispositivo no existe');
comprobarIgual($total, contarMonitoreosBd(), '  no se guardó ningún monitoreo');

require_once __DIR__ . '/../servicios/monitoreo/datos.php';
try {
    insertarMonitoreo(999, '192.0.2.99', 'inactivo', null, '2026-09-28 10:00:00');
    comprobar(false, 'INSERT de un dispositivo borrado entre el paso 1 y el 3 → error 1452', 'el INSERT no falló');
} catch (PDOException $error) {
    comprobarIgual(1452, codigoErrorMysql($error), 'INSERT de un dispositivo borrado entre el paso 1 y el 3 → error 1452 (el endpoint responde 404; probado en datos.php, no de punta a punta)');
}

foreach (['/monitoreo/abc', '/monitoreo/0', '/monitoreo/1/x'] as $ruta) {
    comprobarRespuesta(peticion('POST', $ruta), 404, "POST $ruta", 'La ruta no existe');
}
foreach ([['GET', '/monitoreo/1', 'POST'], ['DELETE', '/monitoreo/1', 'POST'], ['PUT', '/monitoreo', 'GET'],
          ['POST', '/monitoreo', 'GET'], ['POST', '/monitoreo/recientes', 'GET']] as [$metodo, $ruta, $permitidos]) {
    $r = peticion($metodo, $ruta);
    comprobarRespuesta($r, 405, "$metodo $ruta", 'Método no permitido');
    comprobarIgual($permitidos, $r['cabeceras']['allow'] ?? null, "  cabecera Allow: $permitidos");
}

// =====================================================================
seccion('7. Si Alertas o Dispositivos no responde → 502, y el monitoreo queda guardado');

// a) Alertas: conexión rechazada, en el paso 5.
simularFallo('Alertas', 'sin_respuesta', null, puertoCerrado());
$antes = contarMonitoreosBd(6);
$r = peticion('POST', '/monitoreo/6');
comprobarRespuesta($r, 502, 'Alertas sin respuesta (conexión rechazada) al monitorear el Servidor de respaldo', 'No se pudo comunicar con el Servicio de Alertas');
$ultimo = ultimoMonitoreoBd(6);
comprobarIgual($antes + 1, contarMonitoreosBd(6), '  el monitoreo quedó guardado (paso 3)');
comprobarIgual($ultimo['fecha'] ?? 'x', peticion('GET', '/dispositivos/6')['json']['data']['ultima_comprobacion'] ?? null, '  y el dispositivo se actualizó (paso 4, antes de fallar Alertas)');
comprobarRespuesta(peticion('GET', '/alertas/conteo'), 200, '  el propio Servicio de Alertas sigue funcionando (el fallo solo afecta a Monitoreo)');

// b) Dispositivos: se agota el tiempo en el PATCH /estado (paso 4).
[$socket, $puerto] = socketQueNoResponde();
simularFallo('Dispositivos', 'sin_respuesta', 'PATCH', $puerto);
$antes = contarMonitoreosBd(1);
$comprobacionAntes = peticion('GET', '/dispositivos/1')['json']['data']['ultima_comprobacion'] ?? null;
$inicio = microtime(true);
$r = peticion('POST', '/monitoreo/1');
$duracion = microtime(true) - $inicio;
fclose($socket);
comprobarRespuesta($r, 502, 'Dispositivos no responde al PATCH /estado (tiempo agotado)', 'No se pudo comunicar con el Servicio de Dispositivos');
comprobar($duracion >= TIEMPO_ESPERA_SERVICIOS - 0.5 && $duracion < TIEMPO_ESPERA_SERVICIOS + 5,
    '  esperó el tiempo máximo (' . TIEMPO_ESPERA_SERVICIOS . ' s) y no más', sprintf('%.2f s', $duracion));
comprobarIgual($antes + 1, contarMonitoreosBd(1), '  el monitoreo quedó guardado (paso 3)');
quitarFalloSimulado();
comprobarIgual($comprobacionAntes, peticion('GET', '/dispositivos/1')['json']['data']['ultima_comprobacion'] ?? null, '  el dispositivo no se actualizó');

// c) Dispositivos: responde HTML en el GET (paso 1).
simularFallo('Dispositivos', 'respuesta_invalida', 'GET');
$total = contarMonitoreosBd();
comprobarRespuesta(peticion('POST', '/monitoreo/1'), 502, 'Dispositivos responde HTML en lugar de JSON (paso 1)', 'No se pudo comunicar con el Servicio de Dispositivos');
comprobarIgual($total, contarMonitoreosBd(), '  sin IP no hay ping: no se guardó nada');

// d) Dispositivos: conexión rechazada en el GET (paso 1).
simularFallo('Dispositivos', 'sin_respuesta', null, puertoCerrado());
comprobarRespuesta(peticion('POST', '/monitoreo/1'), 502, 'Dispositivos sin respuesta en el paso 1', 'No se pudo comunicar con el Servicio de Dispositivos');
comprobarIgual($total, contarMonitoreosBd(), '  no se guardó nada');

// e) Alertas: responde HTML (paso 5).
simularFallo('Alertas', 'respuesta_invalida');
$antes = contarMonitoreosBd(4);
comprobarRespuesta(peticion('POST', '/monitoreo/4'), 502, 'Alertas responde HTML en lugar de JSON (paso 5)', 'No se pudo comunicar con el Servicio de Alertas');
comprobarIgual($antes + 1, contarMonitoreosBd(4), '  el monitoreo quedó guardado');
$r = @file_get_contents(dirname(URL_SERVICIOS) . '/pruebas/.fallo-simulado.json', false, stream_context_create(['http' => ['ignore_errors' => true]]));
comprobarIgual(404, codigoHttp($http_response_header ?? []), '  mientras está activo, el archivo de fallo simulado no es accesible por HTTP (404)');

// f) El sistema se recupera solo.
quitarFalloSimulado();
comprobarRespuesta(peticion('POST', '/monitoreo/1'), 201, 'sin archivo de fallo → POST /monitoreo/1 funciona');
escribirArchivoFallo(['servicio' => 'Dispositivos', 'modo' => 'respuesta_invalida', 'expira' => time() - 1]);
comprobarRespuesta(peticion('POST', '/monitoreo/1'), 201, 'archivo caducado (la prueba se interrumpió) → se ignora');
escribirArchivoFallo(['servicio' => 'Dispositivos', 'modo' => 'respuesta_invalida', 'expira' => time() + 3600]);
comprobarRespuesta(peticion('POST', '/monitoreo/1'), 201, 'archivo que expira en más de 60 s → se ignora (no puede quedar activo indefinidamente)');
escribirArchivoFallo(['servicio' => 'Dispositivos', 'modo' => 'redirigir', 'url' => 'http://example.com', 'expira' => time() + 30]);
comprobarRespuesta(peticion('POST', '/monitoreo/1'), 201, 'archivo con un modo no permitido (URL libre) → se ignora');
escribirArchivoFallo(['servicio' => 'Dispositivos', 'modo' => 'sin_respuesta', 'puerto' => 80, 'expira' => time() + 30]);
comprobarRespuesta(peticion('POST', '/monitoreo/1'), 201, 'archivo con un puerto menor a 1024 → se ignora');
quitarFalloSimulado();

// =====================================================================
seccion('8. Regla SOA: servicios/monitoreo/ no tiene SQL sobre dispositivos ni alertas');

$archivos = glob(__DIR__ . '/../servicios/monitoreo/*.php');
$violaciones = [];
foreach ($archivos as $archivo) {
    foreach (file($archivo) as $numero => $linea) {
        if (preg_match('/\b(FROM|INTO|UPDATE|JOIN|TABLE)\s+`?(dispositivos|alertas)\b/i', $linea)
            || preg_match('/(require|include)(_once)?\b.*\/(dispositivos|alertas)\//i', $linea)) {
            $violaciones[] = basename($archivo) . ':' . ($numero + 1) . ': ' . trim($linea);
        }
    }
}
comprobar(count($archivos) >= 5, 'se revisaron los ' . count($archivos) . ' archivos PHP de servicios/monitoreo/');
comprobarIgual([], $violaciones, '  ninguno consulta las tablas dispositivos o alertas ni hace require de otro servicio');

// =====================================================================
seccion('9. El endpoint temporal /monitoreo/prueba ya no existe');

comprobarRespuesta(peticion('GET', '/monitoreo/prueba?ip=127.0.0.1'), 404, 'GET /monitoreo/prueba?ip=127.0.0.1', 'La ruta no existe');

// =====================================================================
seccion('10. Bordes del rango de fechas: desde y hasta incluyen el día completo, nada más');

$insertar = obtenerConexion()->prepare(
    "INSERT INTO monitoreos (dispositivo_id, ip, estado, tiempo_respuesta_ms, fecha) VALUES (3, '192.168.0.27', 'activo', 0.10, ?)"
);
foreach (['2026-09-09 23:59:59', '2026-09-10 00:00:00', '2026-09-10 23:59:59', '2026-09-11 00:00:00'] as $fecha) {
    $insertar->execute([$fecha]);
}
$fechasDe = fn(array $r) => array_column($r['json']['data']['items'] ?? [], 'fecha');
comprobarIgual(['2026-09-10 23:59:59', '2026-09-10 00:00:00'], $fechasDe(peticion('GET', '/monitoreo?dispositivo=3&desde=2026-09-10&hasta=2026-09-10')),
    'desde=hasta=2026-09-10 → de las 00:00:00 a las 23:59:59, sin el 09 a las 23:59:59 ni el 11 a las 00:00:00');
comprobarIgual(['2026-09-10 23:59:59', '2026-09-10 00:00:00', '2026-09-09 23:59:59'], $fechasDe(peticion('GET', '/monitoreo?dispositivo=3&hasta=2026-09-10')),
    'hasta=2026-09-10 → termina en las 23:59:59 de ese día');
comprobarIgual(['2026-09-11 00:00:00'], $fechasDe(peticion('GET', '/monitoreo?dispositivo=3&desde=2026-09-11&hasta=2026-09-11')),
    'desde=2026-09-11 → empieza en las 00:00:00 de ese día');

// =====================================================================
seccion('11. Acceso (Fase 7): los tres endpoints son [Interfaz]; Monitoreo usa la clave con los demás');

recargarBaseDeDatos();
comprobarAccesoDeRutas([
    ['GET', '/monitoreo', null],
    ['GET', '/monitoreo/recientes', null],
    ['POST', '/monitoreo/1', null],
], []);
$r = peticion('POST', '/monitoreo/6');
comprobarRespuesta($r, 201, 'con sesión, POST /monitoreo/6 completa el flujo (GET y PATCH a Dispositivos, POST a Alertas, con la clave)');
comprobarIgual(true, $r['json']['data']['alerta_creada'] ?? null, '  y crea la alerta a través del endpoint [Interno] POST /alertas');
recargarBaseDeDatos();

// =====================================================================
seccion('12. Registro de llamadas entre servicios (comun/http.php)');

recargarBaseDeDatos();
$registro = archivoRegistroLlamadas();
$antes = is_file($registro) ? (int) filesize($registro) : 0;
peticion('POST', '/monitoreo/6'); // Servidor de respaldo: no responde → también llama a Alertas
clearstatcache(true, $registro);
if (!is_readable($registro)) {
    avisar("Registro de llamadas no comprobado: no se puede leer $registro desde las pruebas.");
} else {
    $nuevo = (string) file_get_contents($registro, false, null, $antes);
    $lineas = array_map(fn($l) => preg_replace('/^\[\d{2}:\d{2}:\d{2}\] /', '', $l), array_values(array_filter(explode("\n", $nuevo))));
    $sinTiempos = array_map(fn($l) => preg_replace('/, \d+ ms\)$/', ')', $l), $lineas);
    comprobarIgual([
        'Navegador → Monitoreo: POST /monitoreo/6',
        '  Monitoreo → Dispositivos: GET /dispositivos/6 (200)',
        '  Monitoreo → Dispositivos: PATCH /dispositivos/6/estado (200)',
        '  Monitoreo → Alertas: POST /alertas (201)',
    ], $sinTiempos, 'POST /monitoreo/6 anota la petición del navegador y, debajo, cada llamada entre servicios con su código');
    comprobar(preg_match('/^\[\d{2}:\d{2}:\d{2}\]   Monitoreo → Alertas: POST \/alertas \(201, \d+ ms\)$/m', $nuevo) === 1, '  cada línea lleva la hora y los milisegundos', $nuevo);
}

// El XAMPP de Windows no trae la carpeta del error_log (C:\xampp\php\logs): sin
// ella, el registro no se escribía y ver-llamadas.php no mostraba nada.
$carpetaSinCrear = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'monitoreo-registro-' . bin2hex(random_bytes(4));
$errorLogAnterior = (string) ini_get('error_log');
ini_set('error_log', $carpetaSinCrear . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php_error_log');
$registroSinCarpeta = archivoRegistroLlamadas();
registrarLlamada('Dispositivos', 'GET', URL_DISPOSITIVOS . '/6', '200', hrtime(true));
ini_set('error_log', $errorLogAnterior);
$contenidoSinCarpeta = is_file($registroSinCarpeta) ? (string) file_get_contents($registroSinCarpeta) : '';
comprobar(str_contains($contenidoSinCarpeta, '→ Dispositivos: GET /dispositivos/6 (200, '), 'Si la carpeta del error_log no existe (XAMPP de Windows), el registro la crea y anota la llamada', $registroSinCarpeta);
@unlink($registroSinCarpeta);
@rmdir(dirname($registroSinCarpeta));
@rmdir($carpetaSinCrear);

// =====================================================================
seccion('13. Preparación de la demo (herramientas/preparar-demo.php)');

require_once __DIR__ . '/../herramientas/red.php';

/**
 * Corre preparar-demo.php: [código de salida, salida]. $entrada es lo que se
 * escribiría en la terminal (la confirmación de --datos-de-ejemplo).
 */
function prepararDemo(string $argumentos, string $entrada = ''): array
{
    return correrHerramienta('herramientas/preparar-demo.php', $argumentos, $entrada);
}

/**
 * Todas las fechas de la base, como segundos desde 1970, por "tabla.columna.id".
 */
function fechasDeLaBase(): array
{
    $fechas = [];
    foreach (['dispositivos' => ['fecha_registro', 'ultima_comprobacion'], 'monitoreos' => ['fecha'], 'alertas' => ['fecha', 'fecha_atendida'], 'usuarios' => ['fecha_registro']] as $tabla => $columnas) {
        foreach ($columnas as $columna) {
            foreach (filasBd("SELECT id, $columna AS f FROM $tabla WHERE $columna IS NOT NULL") as $fila) {
                $fechas["$tabla.$columna.{$fila['id']}"] = strtotime($fila['f']);
            }
        }
    }

    return $fechas;
}

/**
 * Comprueba que la salida incluya cada texto.
 */
function comprobarSalida(string $salida, array $textos, string $prefijo = '  la salida incluye'): void
{
    foreach ($textos as $texto) {
        comprobar(str_contains($salida, $texto), "$prefijo «" . trim($texto) . '»', $salida);
    }
}

$ipPropia = detectarIpPropia();
$puerta = detectarPuertaDeEnlace();
$formaValida = fn(?string $ip): bool => $ip === null || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && !str_starts_with($ip, '127.'));
comprobar($formaValida($ipPropia) && $formaValida($puerta), 'la detección devuelve una IPv4 de red o null (sin red), nunca otra cosa', json_encode([$ipPropia, $puerta]));
register_shutdown_function('quitarRedSimulada');
register_shutdown_function('borrarRespaldosDePrueba');
borrarRespaldosDePrueba();

// --- Sin opciones: comprueba sin cambiar la base. (127.0.0.3 hace de router; 127.0.0.1 es Mi PC.)
recargarBaseDeDatos();
$huella = huellaDeLaBase(DB_NOMBRE_PRUEBAS);
[$codigo, $salida] = prepararDemo('--router=127.0.0.3 --pc=127.0.0.1');
comprobarIgual(0, $codigo, 'sin opciones (--router=127.0.0.3 --pc=127.0.0.1) termina bien (código 0)', $salida);
comprobarIgual($huella, huellaDeLaBase(DB_NOMBRE_PRUEBAS), '  y NO cambia la base: ni fechas, ni IPs, ni datos');
comprobarSalida($salida, [
    'Tu base (' . DB_NOMBRE_PRUEBAS . ') no se modifica.',
    'Dispositivos (6)',
    '✓ Apache responde',
    '✓ Los servicios contestan',
    '✓ Tu base tiene 6 dispositivos, 56 monitoreos y 1 alerta pendiente',
    '⚠ El último monitoreo fue hace',
    'monitorear-rondas.php --rondas=12 --cada=60',
    '✓ El router (127.0.0.3) responde al ping',
    '⚠ El router (127.0.0.3) no está en tu base',
    '✓ Esta computadora (127.0.0.1) está en tu base como «Mi PC»',
    'Los teléfonos registrados deben tener la pantalla encendida',
    'Todo listo para la demo (lee los avisos con ⚠).',
]);
comprobarIgual(0, respaldosDePrueba() === [] ? 0 : count(respaldosDePrueba()), '  sin opciones no hace respaldos: no toca la base');

// Con historial reciente y el router registrado, todo en ✓.
peticion('POST', '/dispositivos', ['nombre' => 'Router de pruebas', 'ip' => '127.0.0.3', 'tipo' => 'router', 'ubicacion' => 'Laboratorio']);
peticion('POST', '/monitoreo/1');
[$codigo, $salida] = prepararDemo('--router=127.0.0.3 --pc=127.0.0.1');
comprobarSalida($salida, ['✓ Hay historial reciente: el último monitoreo fue hace 0 min', '✓ El router (127.0.0.3) está en tu base como «Router de pruebas»', "Todo listo para la demo.\n"], 'con historial reciente y el router registrado, incluye');

// Base vacía (la de uso diario recién creada).
recrearBase(DB_NOMBRE_PRUEBAS, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL]);
[$codigo, $salida] = prepararDemo('--router=127.0.0.3 --pc=127.0.0.1');
comprobarIgual(0, $codigo, 'con la base vacía termina bien (código 0)', $salida);
comprobarSalida($salida, ['Dispositivos (0)', '(ninguno todavía)', '⚠ Tu base no tiene dispositivos todavía', '«Buscar dispositivos en la red»', '⚠ Todavía no hay historial de monitoreos']);

// Sin base.
ejecutarSentencias(null, 'DROP DATABASE ' . identificador(DB_NOMBRE_PRUEBAS) . ';');
[$codigo, $salida] = prepararDemo('--router=127.0.0.3 --pc=127.0.0.1');
comprobar($codigo === 1 && str_contains($salida, '✗ La base ' . DB_NOMBRE_PRUEBAS . ' no existe') && str_contains($salida, 'Qué hacer: Crea tu base vacía: ' . comandoPhp() . ' herramientas/crear-base.php'), 'si la base no existe → ✗ con «Qué hacer: crear-base.php» y código 1', $salida);
comprobar(!existeBase(DB_NOMBRE_PRUEBAS), '  y no la crea por su cuenta');
recargarBaseDeDatos();

// Router que bloquea el ping pero está en la tabla ARP (como muchos hotspots): ⚠, no ✗.
// La tabla ARP viene de la red simulada (la misma lectura que el escáner): no depende de la red.
simularRed(['ip_propia' => '192.0.2.1', 'arp' => tablaArpDeEjemplo([['192.0.2.40', 'aa:bb:cc:dd:ee:40', 'REACHABLE']])]);
[$codigo, $salida] = prepararDemo('--router=192.0.2.40 --pc=127.0.0.1');
comprobar(
    $codigo === 0 && str_contains($salida, '⚠ El router bloquea el ping, pero está conectado (aparece en ARP): 192.0.2.40, MAC aa:bb:cc:dd:ee:40') && !str_contains($salida, '✗'),
    'router que no responde al ping pero está en ARP → ⚠ «El router bloquea el ping, pero está conectado (aparece en ARP)», código 0',
    $salida
);
quitarRedSimulada();
[$codigo, $salida] = prepararDemo('--router=192.0.2.40 --pc=127.0.0.1');
comprobar(
    $codigo === 1 && str_contains($salida, '✗ El router (192.0.2.40) no responde al ping') && str_contains($salida, 'Qué hacer: El router no responde') && !str_contains($salida, 'aparece en ARP'),
    'si tampoco está en ARP → ✗ con «Qué hacer» y código 1',
    $salida
);

foreach (['--router=300.1.1.1' => 'no es una IPv4 válida', '--router=127.0.0.1 --pc=127.0.0.1' => 'no pueden tener la misma IP', '--otra-opcion' => 'Uso:', '--sin-red' => 'Uso:'] as $argumentos => $mensaje) {
    [$codigo, $salida] = prepararDemo($argumentos);
    comprobar($codigo === 2 && str_contains($salida, $mensaje), "$argumentos → código 2 y «{$mensaje}»", $salida);
}

// --- --datos-de-ejemplo: respalda, pide confirmación y solo entonces reemplaza.
recrearBase(DB_NOMBRE_PRUEBAS, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL]);
peticion('POST', '/dispositivos', ['nombre' => 'Mi equipo real', 'ip' => '127.0.0.9', 'tipo' => 'computadora', 'ubicacion' => 'Casa']);
$huellaMia = huellaDeLaBase(DB_NOMBRE_PRUEBAS);

[$codigo, $salida] = prepararDemo('--datos-de-ejemplo --router=192.0.2.30 --pc=127.0.0.2', "NO\n");
comprobarIgual(1, $codigo, '--datos-de-ejemplo respondiendo NO → código 1', $salida);
comprobarSalida($salida, ['Respaldo de tu base:', '(1 dispositivo, 0 monitoreos, 0 alertas) se reemplazará por los DATOS DE EJEMPLO', 'Escribe SI para continuar', 'Cancelado: la base ' . DB_NOMBRE_PRUEBAS . ' no cambió.']);
comprobarIgual($huellaMia, huellaDeLaBase(DB_NOMBRE_PRUEBAS), '  la base no cambió');
comprobarIgual(1, count(respaldosDePrueba()), '  y el respaldo ya quedó guardado en respaldos/');
[$codigo] = prepararDemo('--datos-de-ejemplo --router=192.0.2.30 --pc=127.0.0.2');
comprobar($codigo === 1 && huellaDeLaBase(DB_NOMBRE_PRUEBAS) === $huellaMia, '  sin respuesta (fin de la entrada) también cancela, sin cambiar la base');
[$codigo, $salida] = prepararDemo('--datos-de-ejemplo --router=192.0.2.40 --pc=127.0.0.1', "SI\n");
comprobar($codigo === 2 && str_contains($salida, 'ya es de «Mi PC» en los datos de ejemplo') && huellaDeLaBase(DB_NOMBRE_PRUEBAS) === $huellaMia, 'una IP que en los datos de ejemplo es de Mi PC → código 2, sin pedir confirmación ni tocar la base', $salida);
borrarRespaldosDePrueba();

$antesFechas = (function (): array {
    recrearBase(DB_NOMBRE_PRUEBAS, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL, ARCHIVO_SEED]);
    return fechasDeLaBase();
})();
recrearBase(DB_NOMBRE_PRUEBAS, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL]);
peticion('POST', '/dispositivos', ['nombre' => 'Mi equipo real', 'ip' => '127.0.0.9', 'tipo' => 'computadora', 'ubicacion' => 'Casa']);
$huellaMia = huellaDeLaBase(DB_NOMBRE_PRUEBAS);
[$codigo, $salida] = prepararDemo('--datos-de-ejemplo --router=192.0.2.30 --pc=127.0.0.2', "SI\n");
comprobarIgual(0, $codigo, '--datos-de-ejemplo --router=192.0.2.30 --pc=127.0.0.2 respondiendo SI termina bien (código 0)', $salida);
$despues = fechasDeLaBase();
$diferencias = array_unique(array_map(fn($clave) => $despues[$clave] - $antesFechas[$clave], array_keys($antesFechas)));
comprobar(count($diferencias) === 1 && array_keys($antesFechas) === array_keys($despues), '  todas las fechas se movieron lo mismo: se conservan las distancias entre ellas', json_encode(array_values($diferencias)));
$ultima = strtotime((string) valorBd('SELECT MAX(fecha) FROM monitoreos'));
comprobar(abs(time() - 600 - $ultima) <= 60, '  la última ronda de monitoreo quedó de hace unos 10 minutos', date('Y-m-d H:i:s', $ultima));
comprobar(strtotime((string) valorBd('SELECT MAX(fecha_atendida) FROM alertas')) < strtotime('today'), '  las alertas atendidas siguen siendo de días anteriores');
comprobarIgual(['192.0.2.30', '127.0.0.2', '127.0.0.1'], [valorBd('SELECT ip FROM dispositivos WHERE id = 2'), valorBd('SELECT ip FROM dispositivos WHERE id = 3'), valorBd('SELECT ip FROM dispositivos WHERE id = 1')], '  el router y PC Recepción tienen las IPs indicadas; los demás, las del seed');
comprobarIgual([['192.0.2.30'], ['127.0.0.2']], [array_column(filasBd('SELECT DISTINCT ip FROM monitoreos WHERE dispositivo_id = 2'), 'ip'), array_column(filasBd('SELECT DISTINCT ip FROM monitoreos WHERE dispositivo_id = 3'), 'ip')], '  también en su historial de monitoreos');
comprobarSalida($salida, ['Router principal ... 192.0.2.30 (indicada)', '✓ La base tiene los datos de ejemplo', 'no responde, como se espera', '✓ PC Recepción (127.0.0.2) responde al ping', "Todo listo para la demo.\n"]);
comprobarIgual(0, (int) valorBd("SELECT COUNT(*) FROM dispositivos WHERE nombre = 'Mi equipo real'"), '  «Mi equipo real» ya no está en la base…');

$respaldo = respaldosDePrueba()[0] ?? '';
[$codigo, $salida] = correrHerramienta('herramientas/restaurar-respaldo.php', escapeshellarg(basename($respaldo)), "SI\n");
comprobar($codigo === 0 && str_contains($salida, '✓ Base ' . DB_NOMBRE_PRUEBAS . ' restaurada: 1 dispositivo, 0 monitoreos, 0 alertas.'), '  …pero el respaldo lo devuelve: restaurar-respaldo.php con SI → código 0', $salida);
comprobarIgual($huellaMia, huellaDeLaBase(DB_NOMBRE_PRUEBAS), '  y la base queda exactamente como antes de los datos de ejemplo');

[$codigo, $salida] = prepararDemo('--datos-de-ejemplo --sin-red', "SI\n");
comprobarIgual(0, $codigo, '--datos-de-ejemplo --sin-red respondiendo SI termina bien (código 0)', $salida);
comprobarIgual(['192.0.2.30', '127.0.0.2'], [valorBd('SELECT ip FROM dispositivos WHERE id = 2'), valorBd('SELECT ip FROM dispositivos WHERE id = 3')], '  con las IPs del plan sin red');
borrarRespaldosDePrueba();

// =====================================================================
seccion('14. Historial antes de la demo (herramientas/monitorear-rondas.php)');

/**
 * Inicia monitorear-rondas.php sin esperar a que termine. Devuelve ['proceso',
 * 'archivo' (su salida), 'codigo' (null mientras corre)].
 * Con el comando como arreglo no hay un sh de por medio: las señales (Ctrl+C)
 * llegan al proceso de PHP. $grupoPropio (solo Windows): en su propio grupo de
 * procesos, para mandarle un Ctrl+Break solo a él.
 * La salida va a un archivo, no a un pipe: en Windows, leer un pipe espera a que
 * lleguen datos aunque esté sin bloqueo, y la prueba podía quedarse esperando.
 */
function iniciarRondas(array $argumentos, bool $grupoPropio = false): array
{
    renovarBaseDePruebas();
    $archivo = (string) tempnam(sys_get_temp_dir(), 'rondas');
    $proceso = proc_open([PHP_BINARY, dirname(__DIR__) . '/herramientas/monitorear-rondas.php', ...$argumentos], [0 => ['pipe', 'r'], 1 => ['file', $archivo, 'w'], 2 => ['redirect', 1]], $tuberias, null, null, ['create_process_group' => $grupoPropio]);
    fclose($tuberias[0]);

    return ['proceso' => $proceso, 'archivo' => $archivo, 'codigo' => null];
}

/**
 * true si las rondas ya terminaron. Guarda su código de salida: proc_get_status()
 * solo lo da la primera vez que ve el proceso terminado.
 */
function rondasTerminaron(array &$rondas): bool
{
    if ($rondas['codigo'] === null) {
        $estado = proc_get_status($rondas['proceso']);
        $rondas['codigo'] = $estado['running'] ? null : $estado['exitcode'];
    }

    return $rondas['codigo'] !== null;
}

/**
 * Espera hasta que la salida contiene $texto o las rondas terminan, como máximo
 * $espera segundos (nunca se queda bloqueada). Devuelve la salida hasta ahora.
 */
function leerHasta(array &$rondas, ?string $texto, float $espera = 30): string
{
    $limite = microtime(true) + $espera;
    while (true) {
        renovarBaseDePruebas();
        $termino = rondasTerminaron($rondas);
        clearstatcache(true, $rondas['archivo']);
        $leido = (string) @file_get_contents($rondas['archivo']);
        if ($termino || ($texto !== null && str_contains($leido, $texto)) || microtime(true) >= $limite) {
            return $leido;
        }
        usleep(50000);
    }
}

/**
 * Espera a que las rondas terminen (como máximo $espera s); si no, las mata con
 * SIGKILL para que nunca sigan vivas cuando la base de pruebas caduque.
 * Devuelve [código de salida, terminó solo, salida completa].
 */
function esperarFin(array &$rondas, float $espera = 10): array
{
    leerHasta($rondas, null, $espera);
    $termino = rondasTerminaron($rondas);
    if (!$termino) {
        proc_terminate($rondas['proceso'], 9);
    }
    proc_close($rondas['proceso']);
    $leido = (string) @file_get_contents($rondas['archivo']);
    @unlink($rondas['archivo']);

    return $termino ? [$rondas['codigo'], true, $leido] : [-1, false, $leido];
}

recrearBase(DB_NOMBRE_PRUEBAS, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL]);
peticion('POST', '/dispositivos', ['nombre' => 'Equipo local', 'ip' => '127.0.0.1', 'tipo' => 'computadora', 'ubicacion' => 'Laboratorio']);
peticion('POST', '/dispositivos', ['nombre' => 'Equipo apagado', 'ip' => '192.0.2.50', 'tipo' => 'servidor', 'ubicacion' => 'Laboratorio']);

foreach (['--rondas=0', '--rondas=101', '--cada=4', '--cada=3601', '--rondas=abc', '--otra'] as $argumentos) {
    [$codigo, $salida] = correrHerramienta('herramientas/monitorear-rondas.php', $argumentos);
    comprobar($codigo === 2 && str_contains($salida, 'Uso:'), "$argumentos → código 2 con la ayuda", $salida);
}

$inicio = microtime(true);
[$codigo, $salida] = correrHerramienta('herramientas/monitorear-rondas.php', '--rondas=3 --cada=5');
$segundos = microtime(true) - $inicio;
comprobarIgual(0, $codigo, '--rondas=3 --cada=5 hace las 3 rondas (código 0)', $salida);
comprobar($segundos >= 10 && $segundos < 20, sprintf('  respeta el intervalo: 3 rondas cada 5 s tardan %.1f s (entre 10 y 20)', $segundos));
comprobarIgual([6, 1], [(int) valorBd('SELECT COUNT(*) FROM monitoreos'), (int) valorBd('SELECT COUNT(*) FROM alertas WHERE atendida = 0')], '  guardó 6 monitoreos (3 rondas × 2) y una sola alerta para el equipo que no responde');
comprobarSalida($salida, [
    'Ronda 1 de 3', 'Ronda 3 de 3',
    'Rondas hechas ............ 3 de 3',
    'Monitoreos guardados ..... 6',
    'Alertas nuevas ........... 1',
    'Respondieron en cada ronda',
], '  el resumen incluye');
comprobar(preg_match('/Equipo local\s+✓\s+✓\s+✓\s+3 de 3/u', $salida) === 1 && preg_match('/Equipo apagado\s+✗\s+✗\s+✗\s+0 de 3/u', $salida) === 1, '  y qué dispositivos respondieron en cada ronda (✓ ✓ ✓ / ✗ ✗ ✗)', $salida);

// Un servicio deja de responder a la mitad: lo dice claramente, muestra el resumen y termina.
$rondas = iniciarRondas(['--rondas=3', '--cada=5']);
leerHasta($rondas, 'siguiente ronda');
simularFallo('Dispositivos', 'sin_respuesta', 'GET', puertoCerrado());
$inicio = microtime(true);
[$codigo, , $leido] = esperarFin($rondas, 15);
quitarFalloSimulado();
comprobar($codigo === 1 && str_contains($leido, '✗ Se detuvieron las rondas: Ningún dispositivo se pudo monitorear en esta ronda: No se pudo comunicar con el Servicio de Dispositivos'),
    'si Dispositivos deja de responder a la mitad → «Se detuvieron las rondas» con el motivo (código 1)', $leido);
comprobar(microtime(true) - $inicio < 10, '  no se queda esperando: termina en la ronda siguiente');
comprobar(str_contains($leido, 'Rondas hechas ............ 2 de 3 (se detuvo antes)') && str_contains($leido, 'Monitoreos guardados ..... 2') && preg_match('/Equipo local\s+✓\s+!\s+1 de 2/u', $leido) === 1,
    '  y el resumen dice qué se alcanzó a guardar (! = no se pudo monitorear)', $leido);

if (function_exists('pcntl_async_signals')) {
    $rondas = iniciarRondas(['--rondas=3', '--cada=60']);
    leerHasta($rondas, 'siguiente ronda');
    proc_terminate($rondas['proceso'], SIGINT);
    [$codigo, $soloTermino, $leido] = esperarFin($rondas, 5);
    comprobar($soloTermino && $codigo === 1 && str_contains($leido, 'Detenido con Ctrl+C.') && str_contains($leido, 'Rondas hechas ............ 1 de 3'), 'Ctrl+C durante la espera → resumen de lo hecho, sin esperar los 60 s (código 1)', $leido);
} elseif (function_exists('sapi_windows_generate_ctrl_event')) {
    // Windows: un Ctrl+C no se puede dirigir a un solo proceso; un Ctrl+Break a su grupo
    // llega al mismo manejador de la consola (sapi_windows_set_ctrl_handler).
    $rondas = iniciarRondas(['--rondas=3', '--cada=60'], true);
    leerHasta($rondas, 'siguiente ronda');
    if (@sapi_windows_generate_ctrl_event(PHP_WINDOWS_EVENT_CTRL_BREAK, proc_get_status($rondas['proceso'])['pid'])) {
        $inicio = microtime(true);
        [$codigo, $soloTermino, $leido] = esperarFin($rondas, 5);
        comprobar($soloTermino && $codigo === 1 && str_contains($leido, 'Detenido con Ctrl+C.') && str_contains($leido, 'Rondas hechas ............ 1 de 3'), sprintf('Ctrl+C (Ctrl+Break en Windows) durante la espera → resumen de lo hecho en %.1f s, sin esperar los 60 s (código 1)', microtime(true) - $inicio), $leido);
    } else {
        esperarFin($rondas, 0);
        avisar('Sin consola (Windows): no se pudo enviar Ctrl+Break, así que no se probó Ctrl+C en monitorear-rondas.php.');
    }
} else {
    avisar('Sin pcntl ni consola de Windows: no se probó Ctrl+C en monitorear-rondas.php.');
}
// La marca de las pruebas viaja entre servicios: un monitoreo de las pruebas con la base de
// pruebas caducada no llega a la base del usuario ni por Monitoreo ni por Dispositivos.
$GLOBALS['renovacion_en_pausa'] = true;
$monitoreosTuyos = (int) (filasBdUsuario('SELECT COUNT(*) AS n FROM monitoreos')[0]['n'] ?? -1);
file_put_contents(ARCHIVO_BASE_DE_PRUEBAS, json_encode(['expira' => time() - 1]));
$r = peticion('POST', '/monitoreo/1');
escribirArchivoBaseDePruebas();
$GLOBALS['renovacion_en_pausa'] = false;
comprobar($r['codigo'] >= 500, 'un POST /monitoreo de las pruebas con la base de pruebas caducada se rechaza', "código {$r['codigo']}");
comprobarIgual($monitoreosTuyos, (int) (filasBdUsuario('SELECT COUNT(*) AS n FROM monitoreos')[0]['n'] ?? -1), '  y no guardó nada en tu base');
recargarBaseDeDatos();

// =====================================================================
seccion('15. Ping (comun/ping.php): salidas reales de Windows en español, y de Windows en inglés, Linux y macOS');

require_once __DIR__ . '/../servicios/comun/ping.php';

// Salidas reales de un Windows 11 en español (es-MX, octubre de 2026), tal como las
// entrega exec(): en CP850 (\xA1 = í, \xA0 = á), con las líneas unidas por "\n".
$windowsLocal = "Haciendo ping a 127.0.0.1 con 32 bytes de datos:\nRespuesta desde 127.0.0.1: bytes=32 tiempo<1m TTL=128\n\nEstad\xA1sticas de ping para 127.0.0.1:\n    Paquetes: enviados = 1, recibidos = 1, perdidos = 0\n    (0% perdidos),\nTiempos aproximados de ida y vuelta en milisegundos:\n    M\xA1nimo = 0ms, M\xA0ximo = 0ms, Media = 0ms";
$windowsRouter = "Haciendo ping a 192.168.0.1 con 32 bytes de datos:\nRespuesta desde 192.168.0.1: bytes=32 tiempo=3ms TTL=64\n\nEstad\xA1sticas de ping para 192.168.0.1:\n    Paquetes: enviados = 1, recibidos = 1, perdidos = 0\n    (0% perdidos),\nTiempos aproximados de ida y vuelta en milisegundos:\n    M\xA1nimo = 3ms, M\xA0ximo = 3ms, Media = 3ms";
$windowsSinRespuesta = "Haciendo ping a 192.0.2.20 con 32 bytes de datos:\nTiempo de espera agotado para esta solicitud.\n\nEstad\xA1sticas de ping para 192.0.2.20:\n    Paquetes: enviados = 1, recibidos = 0, perdidos = 1\n    (100% perdidos),";
// Una IP libre de la red local, con la entrada ARP ya fallida: la propia computadora
// contesta, "recibidos = 1" y ping termina con código 0.
$windowsInaccesible = "Haciendo ping a 192.168.0.250 con 32 bytes de datos:\nRespuesta desde 192.168.0.19: Host de destino inaccesible.\n\nEstad\xA1sticas de ping para 192.168.0.250:\n    Paquetes: enviados = 1, recibidos = 1, perdidos = 0\n    (0% perdidos),";
$windowsIngles = "Pinging 192.168.1.1 with 32 bytes of data:\nReply from 192.168.1.1: bytes=32 time=12ms TTL=64\n\nPing statistics for 192.168.1.1:\n    Packets: Sent = 1, Received = 1, Lost = 0 (0% loss),\nApproximate round trip times in milli-seconds:\n    Minimum = 12ms, Maximum = 12ms, Average = 12ms";
$windowsInglesMenor = "Pinging 127.0.0.1 with 32 bytes of data:\nReply from 127.0.0.1: bytes=32 time<1ms TTL=128";
$windowsInglesInaccesible = "Pinging 192.168.1.250 with 32 bytes of data:\nReply from 192.168.1.5: Destination host unreachable.\n\nPing statistics for 192.168.1.250:\n    Packets: Sent = 1, Received = 1, Lost = 0 (0% loss),";
$linux = "PING 127.0.0.1 (127.0.0.1) 56(84) bytes of data.\n64 bytes from 127.0.0.1: icmp_seq=1 ttl=64 time=0.032 ms\n\n--- 127.0.0.1 ping statistics ---\n1 packets transmitted, 1 received, 0% packet loss, time 0ms\nrtt min/avg/max/mdev = 0.032/0.032/0.032/0.000 ms";
$linuxSinRespuesta = "PING 192.0.2.20 (192.0.2.20) 56(84) bytes of data.\n\n--- 192.0.2.20 ping statistics ---\n1 packets transmitted, 0 received, 100% packet loss, time 0ms";
$mac = "PING 192.168.1.1 (192.168.1.1): 56 data bytes\n64 bytes from 192.168.1.1: icmp_seq=0 ttl=64 time=3.127 ms\n\n--- 192.168.1.1 ping statistics ---\n1 packets transmitted, 1 packets received, 0.0% packet loss\nround-trip min/avg/max/stddev = 3.127/3.127/3.127/0.000 ms";

$utf8 = convertirAUtf8($windowsLocal);
comprobar(preg_match('//u', $utf8) === 1 && str_contains($utf8, 'Estadísticas de ping') && str_contains($utf8, 'Mínimo = 0ms, Máximo = 0ms'), 'Windows en español: la salida en CP850 se convierte a UTF-8 («Estadísticas», «Mínimo», «Máximo»)', $utf8);
comprobar(json_encode($windowsLocal) === false, '  (sin convertir, json_encode no podría enviarla)');
comprobarIgual($utf8, convertirAUtf8($utf8), '  un texto que ya es UTF-8 no cambia');
comprobarIgual([true, 0.0], [salidaIndicaRespuesta($utf8), extraerTiempoMs($utf8)], '  127.0.0.1: responde y «tiempo<1m» se guarda como 0');
comprobarIgual([true, 3.0], [salidaIndicaRespuesta($windowsRouter), extraerTiempoMs(convertirAUtf8($windowsRouter))], '  el router: responde con «tiempo=3ms» → 3 (no confunde «Tiempos aproximados» ni «Mínimo = 3ms»)');
comprobarIgual(false, salidaIndicaRespuesta(convertirAUtf8($windowsSinRespuesta)), '  «Tiempo de espera agotado para esta solicitud» → no responde');
comprobarIgual(false, salidaIndicaRespuesta(convertirAUtf8($windowsInaccesible)), '  «Host de destino inaccesible» → no responde, aunque ping termine con código 0 y diga «recibidos = 1» (no trae TTL=)');
comprobarIgual([true, 12.0], [salidaIndicaRespuesta($windowsIngles), extraerTiempoMs($windowsIngles)], 'Windows en inglés: «time=12ms» → 12');
comprobarIgual(0.0, extraerTiempoMs($windowsInglesMenor), '  «time<1ms» → 0');
comprobarIgual(false, salidaIndicaRespuesta($windowsInglesInaccesible), '  «Destination host unreachable» → no responde');
comprobarIgual([true, 0.03], [salidaIndicaRespuesta($linux), extraerTiempoMs($linux)], 'Linux: «ttl=64 time=0.032 ms» → responde, 0.03 (redondeado a 2 decimales)');
comprobarIgual(false, salidaIndicaRespuesta($linuxSinRespuesta), '  sin respuesta → no responde');
comprobarIgual([true, 3.13], [salidaIndicaRespuesta($mac), extraerTiempoMs($mac)], 'macOS: «time=3.127 ms» → 3.13');

$comando = construirComandoPing('127.0.0.1');
comprobarIgual(match (PHP_OS_FAMILY) {
    'Windows' => 'ping -n 1 -w 1000 "127.0.0.1"',
    'Darwin' => "ping -c 1 -t 1 '127.0.0.1'",
    default => "ping -c 1 -W 1 '127.0.0.1'",
}, $comando, 'El comando de ' . PHP_OS_FAMILY . ': un intento, 1 s de espera y la IP entre comillas (escapeshellarg)');
try {
    hacerPing('127.0.0.1 & calc');
    comprobar(false, 'Una IP con un comando agregado se rechaza sin ejecutar nada');
} catch (InvalidArgumentException) {
    comprobar(true, 'Una IP con un comando agregado («127.0.0.1 & calc») se rechaza sin ejecutar nada');
}

// El ping real de esta computadora (desde la terminal; a través de Apache, en las secciones 4 y 5).
$local = hacerPing('127.0.0.1');
comprobar($local['responde'] && is_float($local['tiempo_respuesta_ms']) && $local['tiempo_respuesta_ms'] >= 0 && preg_match('//u', $local['salida']) === 1, sprintf('Ping real a 127.0.0.1: responde en %s ms, salida en UTF-8', exportar($local['tiempo_respuesta_ms'])), $local['salida']);
$inicio = microtime(true);
$documentacion = hacerPing('192.0.2.20');
$duracion = microtime(true) - $inicio;
comprobar(!$documentacion['responde'] && $documentacion['tiempo_respuesta_ms'] === null && $duracion < 2.5, sprintf('Ping real a 192.0.2.20: no responde, en %.2f s (1 s de espera)', $duracion), $documentacion['salida']);

// =====================================================================
seccion('16. Encendido en un clic en Windows (herramientas/iniciar-servicios.ps1 y .bat)');

/**
 * Corre un programa (arreglo: sin un shell de por medio) con tope de tiempo y la salida
 * en un archivo (en Windows un pipe no se puede leer sin bloquear). Devuelve
 * [código de salida, salida con "\n", terminó solo, segundos].
 */
function correrConTope(array $comando, float $segundos): array
{
    renovarBaseDePruebas();
    $archivo = (string) tempnam(sys_get_temp_dir(), 'tope');
    $inicio = microtime(true);
    $proceso = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['file', $archivo, 'w'], 2 => ['redirect', 1]], $tuberias);
    fclose($tuberias[0]);
    $codigo = null;
    while ($codigo === null && microtime(true) - $inicio < $segundos) {
        $estado = proc_get_status($proceso);
        $codigo = $estado['running'] ? null : $estado['exitcode'];
        renovarBaseDePruebas();
        usleep(100000);
    }
    if ($codigo === null) {
        proc_terminate($proceso, 9);
    }
    proc_close($proceso);
    $salida = str_replace("\r\n", "\n", (string) file_get_contents($archivo));
    @unlink($archivo);

    return [$codigo ?? -1, $salida, $codigo !== null, microtime(true) - $inicio];
}

if (PHP_OS_FAMILY !== 'Windows') {
    avisar('iniciar-servicios.ps1 y .bat son solo para Windows: no se probaron aquí (en Linux se usa herramientas/iniciar-servicios.sh).');
} else {
    $herramientas = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'herramientas' . DIRECTORY_SEPARATOR;
    $ps1 = (string) file_get_contents($herramientas . 'iniciar-servicios.ps1');
    comprobar(str_starts_with($ps1, "\xEF\xBB\xBF"), 'iniciar-servicios.ps1 está en UTF-8 con BOM (Windows PowerShell 5.1 lee un archivo sin BOM como ANSI y rompería ✓, ✗ y los acentos)');
    comprobar(preg_match("/(?<!\r)\n/", $ps1 . (string) file_get_contents($herramientas . 'iniciar-servicios.bat')) === 0, '  el .ps1 y el .bat con finales de línea CRLF (.gitattributes)');
    comprobar(!str_contains((string) file_get_contents($herramientas . 'iniciar-servicios.sh'), "\r"), '  y iniciar-servicios.sh con LF (bash no acepta CRLF)');

    // Las funciones, cargadas con -SoloFunciones, con datos de ejemplo.
    $temporal = (string) tempnam(sys_get_temp_dir(), 'ps');
    $pruebaPs = "$temporal.ps1";
    file_put_contents($pruebaPs, str_replace('__SCRIPT__', $herramientas . 'iniciar-servicios.ps1', <<<'PS'
        [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
        . '__SCRIPT__' -SoloFunciones
        $r = [ordered]@{}
        $r.soloPrivada = @(PerfilesSinPermiso @(@{ Accion = 'Allow'; Perfil = 'Private' }) @('Private', 'Public'))
        $r.sinReglas = @(PerfilesSinPermiso @() @('Private', 'Public'))
        $r.cualquiera = @(PerfilesSinPermiso @(@{ Accion = 'Allow'; Perfil = 'Any' }) @('Private', 'Public'))
        $r.ambas = @(PerfilesSinPermiso @(@{ Accion = 'Allow'; Perfil = 'Private, Public' }) @('Private', 'Public'))
        $r.bloqueo = @(PerfilesSinPermiso @(@{ Accion = 'Allow'; Perfil = 'Any' }, @{ Accion = 'Block'; Perfil = 'Public' }) @('Private', 'Public'))
        $r.apagado = @(PerfilesSinPermiso @(@{ Accion = 'Allow'; Perfil = 'Private' }) @('Private', 'Public') @('Public'))
        $r.comando = ComandoParaPermitirApache @('Public')
        $r.comandoAmbas = ComandoParaPermitirApache @('Private', 'Public')
        $r.conectado = LeerEstadoTailscale '{"BackendState":"Running","Self":{"HostName":"laptop","TailscaleIPs":["fd7a:115c:a1e0::1","100.101.102.103"]}}'
        $r.sinSesion = @(QueHacerConTailscale (LeerEstadoTailscale '{"BackendState":"NeedsLogin","AuthURL":"https://login.tailscale.com/a/abc"}'))
        $r.desconectado = @(QueHacerConTailscale (LeerEstadoTailscale '{"BackendState":"Stopped"}'))
        $r.noJson = LeerEstadoTailscale 'no es JSON'
        $r.sistema = DescribirProceso @{ Pid = 4; Nombre = 'System'; Ruta = $null; Servicio = $null; NombreServicio = $null }
        $r.servicio = @(QueHacerConElPuerto @{ Pid = 1234; Nombre = 'mysqld'; Ruta = 'C:\Program Files\MySQL\bin\mysqld.exe'; Servicio = 'MySQL80'; NombreServicio = 'MySQL80' })
        $r.programa = @(QueHacerConElPuerto @{ Pid = 99; Nombre = 'Skype'; Ruta = 'C:\Skype\Skype.exe'; Servicio = $null; NombreServicio = $null })
        $r.docker = @(QueHacerConElPuerto @{ Pid = 98; Nombre = 'com.docker.backend'; Ruta = $null; Servicio = $null; NombreServicio = $null })
        $r.mayusculas = EsDeXampp @{ Ruta = 'c:\XAMPP\apache\bin\HTTPD.exe'; Nombre = 'httpd' } 'C:\xampp\apache\bin\httpd.exe'
        $r.otroApache = EsDeXampp @{ Ruta = 'C:\Program Files\Apache\bin\httpd.exe'; Nombre = 'httpd' } 'C:\xampp\apache\bin\httpd.exe'
        $r.apache80 = EsDeXampp (ProcesoEnPuerto 80) $HTTPD
        $r | ConvertTo-Json -Compress -Depth 4
        PS));
    [$codigo, $salida] = correrConTope(['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $pruebaPs], 60);
    @unlink($pruebaPs);
    @unlink($temporal);
    $f = json_decode(trim($salida), true);
    if (!is_array($f)) {
        comprobar(false, 'Las funciones de iniciar-servicios.ps1 se cargan con -SoloFunciones', $salida);
        $f = [];
    }
    comprobarIgual([['Public'], ['Private', 'Public'], [], [], ['Public'], []], [$f['soloPrivada'] ?? null, $f['sinReglas'] ?? null, $f['cualquiera'] ?? null, $f['ambas'] ?? null, $f['bloqueo'] ?? null, $f['apagado'] ?? null],
        'Firewall: con Apache solo en privadas falta Public; sin reglas, las dos; «Any» o «Private, Public» bastan; un Block gana al Allow; un perfil con el firewall apagado no falta');
    comprobarIgual('New-NetFirewallRule -DisplayName "Apache HTTP Server (XAMPP, redes públicas)" -Direction Inbound -Action Allow -Program "C:\xampp\apache\bin\httpd.exe" -Protocol TCP -LocalPort 80 -Profile Public', $f['comando'] ?? null, '  el comando exacto para permitirlo (que el script muestra y no ejecuta)');
    comprobar(str_ends_with((string) ($f['comandoAmbas'] ?? ''), '-Profile Private,Public') && str_contains((string) ($f['comandoAmbas'] ?? ''), 'redes privadas y públicas'), '  y para las dos redes a la vez', (string) ($f['comandoAmbas'] ?? ''));
    comprobarIgual(['Running', '100.101.102.103', 'laptop'], [$f['conectado']['Estado'] ?? null, $f['conectado']['Ip'] ?? null, $f['conectado']['Nombre'] ?? null], 'Tailscale conectado: su IPv4 100.x (no la IPv6) y el nombre del equipo');
    comprobar(str_contains((string) ($f['sinSesion'][1] ?? ''), 'https://login.tailscale.com/a/abc'), '  sin sesión: el «Qué hacer» lleva el enlace para iniciarla', exportar($f['sinSesion'] ?? null));
    comprobarIgual('Tailscale está desconectado', $f['desconectado'][0] ?? null, '  desconectado: lo dice y cómo conectarlo');
    comprobarIgual('', $f['noJson']['Estado'] ?? null, '  una salida que no es JSON no rompe el script (estado vacío)');
    comprobar(str_contains((string) ($f['sistema'] ?? ''), 'PID 4') && str_contains((string) ($f['sistema'] ?? ''), 'IIS'), 'Puerto ocupado por Windows (PID 4, http.sys): dice que es un servicio web como IIS', (string) ($f['sistema'] ?? ''));
    comprobar(str_contains((string) ($f['servicio'][0] ?? ''), 'Stop-Service -Name MySQL80') && str_contains((string) ($f['servicio'][1] ?? ''), '-StartupType Manual'), '  por un servicio (otro MySQL): cómo detenerlo y que no vuelva al reiniciar', exportar($f['servicio'] ?? null));
    comprobar(str_contains((string) ($f['programa'][0] ?? ''), 'cierra Skype') && str_contains((string) ($f['programa'][0] ?? ''), 'PID 99'), '  por un programa: su nombre y PID', exportar($f['programa'] ?? null));
    comprobar(str_contains((string) ($f['docker'][0] ?? ''), 'docker stop'), '  por Docker: detener el contenedor', exportar($f['docker'] ?? null));
    comprobarIgual([true, false, true], [$f['mayusculas'] ?? null, $f['otroApache'] ?? null, $f['apache80'] ?? null], 'Reconoce el Apache de XAMPP sin distinguir mayúsculas, no confunde otro Apache, y el del puerto 80 ahora es el de XAMPP');

    // De punta a punta, como con doble clic, con Apache y MySQL encendidos (las pruebas los necesitan).
    // Sin la política de ejecución que pudiera heredar esta terminal: con doble clic no hay ninguna
    // y en Windows rige «Restricted», así que el .bat debe funcionar con su propio -ExecutionPolicy Bypass.
    $politica = getenv('PSExecutionPolicyPreference');
    putenv('PSExecutionPolicyPreference');
    [$codigo, $salida, $termino, $segundos] = correrConTope(['cmd.exe', '/c', $herramientas . 'iniciar-servicios.bat'], 150);
    if ($politica !== false) {
        putenv("PSExecutionPolicyPreference=$politica");
    }
    comprobar($termino, sprintf('iniciar-servicios.bat termina solo, sin quedarse esperando: %.1f s (su «pause» del doble clic no espera sin teclado)', $segundos), $salida);
    comprobarSalida($salida, [
        '✓ Apache ya estaba encendido', '✓ MySQL ya estaba encendido',
        "\nTailscale\n", "\nFirewall de Windows\n",
        'Comprobación previa (herramientas/preparar-demo.php; no cambia tu base)',
        'Tu base (' . DB_NOMBRE_PRUEBAS . ') no se modifica.',
        'Apache ............... encendido', 'MySQL (XAMPP) ........ encendido',
        'En la computadora ................ http://localhost/monitoreo-red-soa/web/',
        ':5500/componentes.html',
    ], '  la salida incluye');
    comprobar(str_contains($salida, '✓ El Firewall de Windows permite a Apache') || str_contains($salida, '-Program "C:\xampp\apache\bin\httpd.exe" -Protocol TCP -LocalPort 80'), '  el Firewall: permitido, o el comando para permitirlo', $salida);
    comprobar(($codigo === 0 && str_contains($salida, 'Todo encendido.')) || ($codigo === 1 && str_contains($salida, 'Falló algo (detalle arriba):') && preg_match('/^  ✗ .+\n      Qué hacer: /mu', $salida) === 1),
        "  código $codigo coherente: 0 con «Todo encendido.», 1 con «Falló algo» y cada ✗ con su «Qué hacer»", $salida);
}

terminarPruebas();
