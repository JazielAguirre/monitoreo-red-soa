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
comprobar($duracion < 3, '  tardó menos de 3 s (ping de 1 s como máximo)', sprintf('%.2f s', $duracion));
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

terminarPruebas();
