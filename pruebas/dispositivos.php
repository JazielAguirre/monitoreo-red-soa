<?php
/**
 * Pruebas del Servicio de Dispositivos (docs/PLAN.md, Fase 2 · "Listo cuando").
 * Recarga la base de datos al empezar: borra los datos agregados a mano.
 *
 *   /opt/lampp/bin/php pruebas/dispositivos.php
 *
 * La prueba con MySQL apagado es manual y no está aquí.
 */

declare(strict_types=1);

require_once __DIR__ . '/ayudas.php';

const CLAVES_DISPOSITIVO = [
    'id', 'nombre', 'ip', 'tipo', 'ubicacion', 'estado',
    'ultimo_tiempo_ms', 'ultima_comprobacion', 'fecha_registro',
];
const FORMATO_FECHA = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

/**
 * Cuerpo válido para POST y PUT, con los cambios indicados.
 */
function dispositivoValido(array $cambios = []): array
{
    return array_merge(
        ['nombre' => 'Switch de pruebas', 'ip' => '10.0.0.50', 'tipo' => 'router', 'ubicacion' => 'Laboratorio'],
        $cambios
    );
}

/**
 * Comprueba que el objeto tenga exactamente las claves y los tipos del objeto dispositivo.
 */
function comprobarFormaDispositivo(mixed $d, string $descripcion): bool
{
    $problemas = [];

    if (!is_array($d) || array_keys($d) !== CLAVES_DISPOSITIVO) {
        return comprobar(false, $descripcion, 'claves: ' . exportar(is_array($d) ? array_keys($d) : $d));
    }

    foreach (['nombre', 'ip', 'tipo', 'ubicacion', 'estado'] as $campo) {
        if (!is_string($d[$campo])) {
            $problemas[] = "$campo no es texto";
        }
    }
    if (!is_int($d['id'])) {
        $problemas[] = 'id no es entero';
    }
    if ($d['ultimo_tiempo_ms'] !== null && !is_int($d['ultimo_tiempo_ms']) && !is_float($d['ultimo_tiempo_ms'])) {
        $problemas[] = 'ultimo_tiempo_ms no es número ni null';
    }
    if ($d['ultima_comprobacion'] !== null && preg_match(FORMATO_FECHA, $d['ultima_comprobacion']) !== 1) {
        $problemas[] = 'ultima_comprobacion con formato incorrecto';
    }
    if (preg_match(FORMATO_FECHA, $d['fecha_registro']) !== 1) {
        $problemas[] = 'fecha_registro con formato incorrecto';
    }

    return comprobar($problemas === [], $descripcion, implode('; ', $problemas));
}

echo "Pruebas del Servicio de Dispositivos\n";
echo "Recargando la base de datos (schema.sql + seed.sql)...\n";
recargarBaseDeDatos();

// =====================================================================
seccion('1. Los 6 endpoints responden como dice API.md, con los tipos correctos');

$r = peticion('GET', '/dispositivos');
comprobarRespuesta($r, 200, 'GET /dispositivos');
$lista = $r['json']['data'] ?? [];
comprobarIgual(6, count($lista), '  devuelve los 6 dispositivos del seed');
comprobarIgual(
    ['Impresora Laboratorio', 'Mi PC', 'PC Recepción', 'Router principal', 'Servidor de respaldo', 'Servidor principal'],
    array_column($lista, 'nombre'),
    '  ordenados por nombre'
);
$todosConForma = true;
foreach ($lista as $d) {
    $todosConForma = $todosConForma && is_array($d) && array_keys($d) === CLAVES_DISPOSITIVO;
}
comprobar($todosConForma, '  cada elemento tiene exactamente las 9 claves del objeto dispositivo');

$r = peticion('GET', '/dispositivos/1');
comprobarRespuesta($r, 200, 'GET /dispositivos/1');
comprobarIgual([
    'id' => 1,
    'nombre' => 'Mi PC',
    'ip' => '127.0.0.1',
    'tipo' => 'computadora',
    'ubicacion' => 'Laboratorio',
    'estado' => 'activo',
    'ultimo_tiempo_ms' => 0.06,
    'ultima_comprobacion' => '2026-09-27 09:45:00',
    'fecha_registro' => '2026-09-20 09:00:00',
], $r['json']['data'] ?? null, '  objeto idéntico al seed (ultimo_tiempo_ms es el número 0.06, no "0.06")');

$r = peticion('GET', '/dispositivos/4');
comprobarIgual([null], campos($r['json']['data'] ?? null, ['ultimo_tiempo_ms']), '  Servidor principal: ultimo_tiempo_ms es null');
$r = peticion('GET', '/dispositivos/6');
comprobarIgual(
    ['desconocido', null, null],
    campos($r['json']['data'] ?? null, ['estado', 'ultimo_tiempo_ms', 'ultima_comprobacion']),
    '  Servidor de respaldo: desconocido, sin datos de monitoreo'
);

$r = peticion('GET', '/dispositivos?estado=activo');
comprobarRespuesta($r, 200, 'GET /dispositivos?estado=activo');
comprobarIgual(4, count($r['json']['data'] ?? []), '  4 activos');
$r = peticion('GET', '/dispositivos?tipo=servidor');
comprobarIgual(2, count($r['json']['data'] ?? []), 'GET ?tipo=servidor → 2 servidores');
$r = peticion('GET', '/dispositivos?estado=activo&tipo=computadora');
comprobarIgual(['Mi PC', 'PC Recepción'], array_column($r['json']['data'] ?? [], 'nombre'), 'GET ?estado=activo&tipo=computadora → Mi PC y PC Recepción');
$r = peticion('GET', '/dispositivos?estado=');
comprobarIgual(6, count($r['json']['data'] ?? []), 'GET ?estado= (vacío) → sin filtro, 6');
comprobarErrorCampo(peticion('GET', '/dispositivos?estado=roto'), 400, 'estado', 'Estado no válido', 'GET ?estado=roto');
comprobarErrorCampo(peticion('GET', '/dispositivos?tipo=switch'), 400, 'tipo', 'Tipo de dispositivo no válido', 'GET ?tipo=switch');

$antes = time();
$r = peticion('POST', '/dispositivos', dispositivoValido());
comprobarRespuesta($r, 201, 'POST /dispositivos');
$creado = $r['json']['data'] ?? [];
comprobarFormaDispositivo($creado, '  el creado tiene la forma del objeto dispositivo');
comprobarIgual(
    ['Switch de pruebas', '10.0.0.50', 'router', 'Laboratorio', 'desconocido', null, null],
    campos($creado, ['nombre', 'ip', 'tipo', 'ubicacion', 'estado', 'ultimo_tiempo_ms', 'ultima_comprobacion']),
    '  datos guardados, estado desconocido y ultimo_* en null'
);
$registro = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $creado['fecha_registro'] ?? '', new DateTimeZone('America/Mazatlan'));
comprobar(
    $registro !== false && abs($registro->getTimestamp() - $antes) <= 60,
    '  fecha_registro generada por PHP, en hora de America/Mazatlan (±60 s)',
    'fecha_registro: ' . ($creado['fecha_registro'] ?? '?')
);
$r = peticion('GET', '/dispositivos/' . ($creado['id'] ?? 0));
comprobarIgual($creado, $r['json']['data'] ?? null, '  GET del creado devuelve lo mismo');

$r = peticion('POST', '/dispositivos', dispositivoValido([
    'ip' => '10.0.0.51', 'estado' => 'activo', 'ultimo_tiempo_ms' => 5, 'ultima_comprobacion' => '2026-09-01 10:00:00',
    'fecha_registro' => '2020-01-01 00:00:00', 'id' => 999,
]));
comprobarRespuesta($r, 201, 'POST con estado, ultimo_*, fecha_registro e id en el cuerpo');
$d = $r['json']['data'] ?? [];
comprobar(
    campos($d, ['estado', 'ultimo_tiempo_ms', 'ultima_comprobacion']) === ['desconocido', null, null]
        && ($d['id'] ?? 999) !== 999 && ($d['fecha_registro'] ?? '2020-01-01 00:00:00') !== '2020-01-01 00:00:00',
    '  esos campos se ignoran: se crea como desconocido, con id y fecha propios',
    exportar($d)
);
$r = peticion('PUT', '/dispositivos/1', ['nombre' => 'Mi PC', 'ip' => '127.0.0.1', 'tipo' => 'computadora',
    'ubicacion' => 'Laboratorio', 'estado' => 'inactivo', 'ultimo_tiempo_ms' => null]);
comprobarIgual(['activo', 0.06], [$r['json']['data']['estado'] ?? '', $r['json']['data']['ultimo_tiempo_ms'] ?? ''], 'PUT con estado en el cuerpo lo ignora: sigue activo con 0.06');

// PUT, DELETE y PATCH se comprueban con detalle en las secciones 6, 7 y 8.

// =====================================================================
seccion('2. Cada regla de validación → 400 con su mensaje en campos; se recortan espacios');

$casos = [
    ['nombre', ['nombre' => null], 'El nombre es obligatorio', 'nombre ausente'],
    ['nombre', ['nombre' => ''], 'El nombre es obligatorio', 'nombre vacío'],
    ['nombre', ['nombre' => '    '], 'El nombre es obligatorio', 'nombre solo con espacios'],
    ['nombre', ['nombre' => 123], 'El nombre es obligatorio', 'nombre que no es texto'],
    ['nombre', ['nombre' => str_repeat('a', 101)], 'Máximo 100 caracteres', 'nombre de 101 caracteres'],
    ['ip', ['ip' => null], 'Dirección IP no válida', 'ip ausente'],
    ['ip', ['ip' => '999.1.1.1'], 'Dirección IP no válida', 'ip 999.1.1.1'],
    ['ip', ['ip' => '10.0.0'], 'Dirección IP no válida', 'ip incompleta'],
    ['ip', ['ip' => '::1'], 'Dirección IP no válida', 'ip IPv6 (solo se acepta IPv4)'],
    ['ip', ['ip' => '10.0.0.1; ls'], 'Dirección IP no válida', 'ip con texto extra'],
    ['tipo', ['tipo' => 'switch'], 'Tipo de dispositivo no válido', 'tipo no permitido'],
    ['tipo', ['tipo' => null], 'Tipo de dispositivo no válido', 'tipo ausente'],
    ['tipo', ['tipo' => 'Router'], 'Tipo de dispositivo no válido', 'tipo con mayúscula'],
    ['ubicacion', ['ubicacion' => null], 'La ubicación es obligatoria', 'ubicacion ausente'],
    ['ubicacion', ['ubicacion' => "  \t "], 'La ubicación es obligatoria', 'ubicacion solo con espacios'],
    ['ubicacion', ['ubicacion' => str_repeat('b', 101)], 'Máximo 100 caracteres', 'ubicacion de 101 caracteres'],
];
foreach ($casos as [$campo, $cambio, $mensaje, $descripcion]) {
    $cuerpo = dispositivoValido($cambio);
    if ($cambio[$campo] === null) {
        unset($cuerpo[$campo]);
    }
    $r = peticion('POST', '/dispositivos', $cuerpo);
    comprobarErrorCampo($r, 400, $campo, $mensaje, "POST con $descripcion");
}

comprobarIgual('Revisa los datos del formulario', peticion('POST', '/dispositivos', ['ip' => 'x'])['json']['error'] ?? null, 'mensaje general del 400: "Revisa los datos del formulario"');
$r = peticion('POST', '/dispositivos', '{}');
comprobarRespuesta($r, 400, 'cuerpo {} (objeto vacío)', 'Revisa los datos del formulario');
comprobarIgual(['nombre', 'ip', 'tipo', 'ubicacion'], array_keys($r['json']['campos'] ?? []), '  con los 4 campos a la vez en campos');

$r = peticion('POST', '/dispositivos', dispositivoValido(['nombre' => str_repeat('ñ', 100), 'ip' => '10.0.0.60']));
comprobarRespuesta($r, 201, 'nombre de exactamente 100 caracteres con ñ (se cuentan caracteres, no bytes)');
$r = peticion('POST', '/dispositivos', dispositivoValido(['ubicacion' => str_repeat('á', 101), 'ip' => '10.0.0.61']));
comprobarErrorCampo($r, 400, 'ubicacion', 'Máximo 100 caracteres', 'ubicacion de 101 caracteres con acento');

$r = peticion('POST', '/dispositivos', [
    'nombre' => "  Cámara IP  ", 'ip' => ' 10.0.0.70 ', 'tipo' => ' servidor ', 'ubicacion' => "\tPasillo  ",
]);
comprobarRespuesta($r, 201, 'POST con espacios al inicio y al final en todos los campos');
$d = $r['json']['data'] ?? [];
comprobarIgual(
    ['Cámara IP', '10.0.0.70', 'servidor', 'Pasillo'],
    [$d['nombre'] ?? '', $d['ip'] ?? '', $d['tipo'] ?? '', $d['ubicacion'] ?? ''],
    '  se guardan recortados'
);
comprobarIgual('Cámara IP', valorBd('SELECT nombre FROM dispositivos WHERE ip = ?', ['10.0.0.70']), '  y así quedan en la base de datos');

comprobarErrorCampo(peticion('PUT', '/dispositivos/2', dispositivoValido(['ip' => '300.1.1.1'])), 400, 'ip', 'Dirección IP no válida', 'PUT con ip inválida');
$cuerpo = dispositivoValido(['ip' => '192.168.0.1']);
unset($cuerpo['ubicacion']);
comprobarErrorCampo(peticion('PUT', '/dispositivos/2', $cuerpo), 400, 'ubicacion', 'La ubicación es obligatoria', 'PUT sin ubicacion (todos los campos son obligatorios)');

// =====================================================================
seccion('3. IP duplicada → 409 con campos.ip, al crear y al editar');

$r = peticion('POST', '/dispositivos', dispositivoValido(['ip' => '127.0.0.1']));
comprobarErrorCampo($r, 409, 'ip', 'Ya existe un dispositivo con esa IP', 'POST con la IP de Mi PC');
$r = peticion('POST', '/dispositivos', dispositivoValido(['ip' => '  127.0.0.1 ']));
comprobarErrorCampo($r, 409, 'ip', 'Ya existe un dispositivo con esa IP', 'POST con la IP de Mi PC entre espacios');
$r = peticion('PUT', '/dispositivos/2', ['nombre' => 'Router principal', 'ip' => '127.0.0.1', 'tipo' => 'router', 'ubicacion' => 'Cuarto de telecomunicaciones']);
comprobarErrorCampo($r, 409, 'ip', 'Ya existe un dispositivo con esa IP', 'PUT del Router con la IP de Mi PC');
comprobarIgual('192.168.0.1', valorBd('SELECT ip FROM dispositivos WHERE id = 2'), '  el Router conserva su IP');
$r = peticion('PUT', '/dispositivos/2', ['nombre' => 'Router principal', 'ip' => '192.168.0.1', 'tipo' => 'router', 'ubicacion' => 'Cuarto de telecomunicaciones']);
comprobarRespuesta($r, 200, 'PUT del Router con su propia IP no es conflicto');

// =====================================================================
seccion('4. Id inexistente → 404; ruta desconocida → 404; método no permitido → 405 (en JSON)');

comprobarRespuesta(peticion('GET', '/dispositivos/999'), 404, 'GET /dispositivos/999', 'El dispositivo no existe');
comprobarRespuesta(peticion('PUT', '/dispositivos/999', dispositivoValido(['ip' => '10.0.0.99'])), 404, 'PUT /dispositivos/999', 'El dispositivo no existe');
comprobarRespuesta(peticion('DELETE', '/dispositivos/999'), 404, 'DELETE /dispositivos/999', 'El dispositivo no existe');
comprobarRespuesta(peticion('PATCH', '/dispositivos/999/estado', ['estado' => 'inactivo', 'tiempo_respuesta_ms' => null, 'fecha' => '2026-09-28 10:00:00']), 404, 'PATCH /dispositivos/999/estado', 'El dispositivo no existe');

foreach (['/dispositivos/abc', '/dispositivos/0', '/dispositivos/-1', '/dispositivos/01', '/dispositivos/1.5',
          '/dispositivos/99999999999', '/dispositivos/1/otra', '/dispositivos/1/estado/x', '/dispositivos/estado',
          '/dispositivos/index.php', '/dispositivos/datos.php'] as $ruta) {
    comprobarRespuesta(peticion('GET', $ruta), 404, "GET $ruta", 'La ruta no existe');
}

$metodosNoPermitidos = [
    ['DELETE', '/dispositivos', 'GET, POST'],
    ['PUT', '/dispositivos', 'GET, POST'],
    ['PATCH', '/dispositivos', 'GET, POST'],
    ['POST', '/dispositivos/1', 'GET, PUT, DELETE'],
    ['PATCH', '/dispositivos/1', 'GET, PUT, DELETE'],
    ['GET', '/dispositivos/1/estado', 'PATCH'],
    ['POST', '/dispositivos/1/estado', 'PATCH'],
];
foreach ($metodosNoPermitidos as [$metodo, $ruta, $permitidos]) {
    $r = peticion($metodo, $ruta);
    comprobarRespuesta($r, 405, "$metodo $ruta", 'Método no permitido');
    comprobarIgual($permitidos, $r['cabeceras']['allow'] ?? null, "  cabecera Allow: $permitidos");
}

// =====================================================================
seccion('5. Cuerpo que no es JSON válido → 400');

$totalDispositivos = (int) valorBd('SELECT COUNT(*) FROM dispositivos');

$cuerposInvalidos = [
    ['', 'vacío', 'El cuerpo de la petición no es JSON válido'],
    ['{"nombre": "Mi PC",', 'JSON cortado', 'El cuerpo de la petición no es JSON válido'],
    ["{'nombre': 'x'}", 'comillas simples', 'El cuerpo de la petición no es JSON válido'],
    ['nombre=Mi+PC', 'formulario', 'El cuerpo de la petición no es JSON válido'],
    ['[1, 2]', 'arreglo', 'El cuerpo de la petición debe ser un objeto JSON'],
    ['"texto"', 'texto JSON', 'El cuerpo de la petición debe ser un objeto JSON'],
    ['null', 'null', 'El cuerpo de la petición debe ser un objeto JSON'],
];
foreach ($cuerposInvalidos as [$cuerpo, $descripcion, $mensaje]) {
    comprobarRespuesta(peticion('POST', '/dispositivos', $cuerpo), 400, "POST con cuerpo $descripcion", $mensaje);
}
comprobarRespuesta(peticion('PUT', '/dispositivos/1', '{roto'), 400, 'PUT con JSON inválido', 'El cuerpo de la petición no es JSON válido');
comprobarRespuesta(peticion('PATCH', '/dispositivos/1/estado', '{roto'), 400, 'PATCH con JSON inválido', 'El cuerpo de la petición no es JSON válido');
comprobarIgual($totalDispositivos, (int) valorBd('SELECT COUNT(*) FROM dispositivos'), '  ninguna de estas peticiones creó ni cambió dispositivos');

// =====================================================================
seccion('6. PUT que cambia la IP → desconocido con ultimo_* en null; sin cambiar la IP conserva el estado');

$monitoreosRouter = (int) valorBd('SELECT COUNT(*) FROM monitoreos WHERE dispositivo_id = 2');
$r = peticion('PUT', '/dispositivos/2', ['nombre' => 'Router nuevo', 'ip' => '192.168.0.254', 'tipo' => 'router', 'ubicacion' => 'Oficina']);
comprobarRespuesta($r, 200, 'PUT del Router (activo) con otra IP');
$d = $r['json']['data'] ?? [];
comprobarIgual(
    ['Router nuevo', '192.168.0.254', 'Oficina', 'desconocido', null, null],
    campos($d, ['nombre', 'ip', 'ubicacion', 'estado', 'ultimo_tiempo_ms', 'ultima_comprobacion']),
    '  datos nuevos, estado desconocido y ultimo_* en null'
);
comprobarIgual('2026-09-20 09:05:00', $d['fecha_registro'] ?? null, '  fecha_registro no cambia');
comprobarIgual($monitoreosRouter, (int) valorBd('SELECT COUNT(*) FROM monitoreos WHERE dispositivo_id = 2'), "  el historial se conserva ($monitoreosRouter monitoreos, con la IP anterior)");

$r = peticion('PUT', '/dispositivos/1', ['nombre' => 'Mi PC renombrada', 'ip' => '127.0.0.1', 'tipo' => 'servidor', 'ubicacion' => 'Sala de servidores']);
comprobarRespuesta($r, 200, 'PUT de Mi PC sin cambiar la IP');
$d = $r['json']['data'] ?? [];
comprobarIgual(
    ['Mi PC renombrada', 'servidor', 'Sala de servidores', 'activo', 0.06, '2026-09-27 09:45:00'],
    [$d['nombre'] ?? '', $d['tipo'] ?? '', $d['ubicacion'] ?? '', $d['estado'] ?? '', $d['ultimo_tiempo_ms'] ?? '', $d['ultima_comprobacion'] ?? ''],
    '  datos nuevos, conserva activo, 0.06 y la última comprobación'
);
$r = peticion('PUT', '/dispositivos/4', ['nombre' => 'Servidor principal', 'ip' => ' 192.0.2.10 ', 'tipo' => 'servidor', 'ubicacion' => 'Sala de servidores']);
comprobarIgual('inactivo', $r['json']['data']['estado'] ?? null, 'PUT con la misma IP entre espacios no cuenta como cambio (sigue inactivo)');

// =====================================================================
seccion('7. DELETE borra el dispositivo y, por la cascada, sus monitoreos y alertas');

$totalMonitoreos = (int) valorBd('SELECT COUNT(*) FROM monitoreos');
$totalAlertas = (int) valorBd('SELECT COUNT(*) FROM alertas');
$monitoreos4 = (int) valorBd('SELECT COUNT(*) FROM monitoreos WHERE dispositivo_id = 4');
$alertas4 = (int) valorBd('SELECT COUNT(*) FROM alertas WHERE dispositivo_id = 4');
comprobarIgual([12, 2], [$monitoreos4, $alertas4], 'antes: Servidor principal tiene 12 monitoreos y 2 alertas');

$r = peticion('DELETE', '/dispositivos/4');
comprobarRespuesta($r, 200, 'DELETE /dispositivos/4');
comprobarIgual(['id' => 4], $r['json']['data'] ?? null, '  data = {"id": 4}');
comprobarRespuesta(peticion('GET', '/dispositivos/4'), 404, '  GET /dispositivos/4 después de borrarlo', 'El dispositivo no existe');
comprobarIgual(
    [0, 0],
    [(int) valorBd('SELECT COUNT(*) FROM monitoreos WHERE dispositivo_id = 4'), (int) valorBd('SELECT COUNT(*) FROM alertas WHERE dispositivo_id = 4')],
    '  sus monitoreos y alertas ya no existen'
);
comprobarIgual(
    [$totalMonitoreos - 12, $totalAlertas - 2],
    [(int) valorBd('SELECT COUNT(*) FROM monitoreos'), (int) valorBd('SELECT COUNT(*) FROM alertas')],
    '  los de los demás dispositivos siguen ahí'
);
comprobarRespuesta(peticion('DELETE', '/dispositivos/4'), 404, '  borrarlo otra vez', 'El dispositivo no existe');

// =====================================================================
seccion('8. PATCH /estado: activo exige tiempo ≥ 0, inactivo exige null, la fecha es obligatoria');

$r = peticion('PATCH', '/dispositivos/6/estado', ['estado' => 'activo', 'tiempo_respuesta_ms' => 0.32, 'fecha' => '2026-09-28 10:05:12']);
comprobarRespuesta($r, 200, 'PATCH Servidor de respaldo (desconocido) → activo con 0.32');
$d = $r['json']['data'] ?? [];
comprobarFormaDispositivo($d, '  responde el objeto dispositivo');
comprobarIgual(['activo', 0.32, '2026-09-28 10:05:12'], [$d['estado'] ?? '', $d['ultimo_tiempo_ms'] ?? '', $d['ultima_comprobacion'] ?? ''], '  estado, ultimo_tiempo_ms y ultima_comprobacion actualizados');

$r = peticion('PATCH', '/dispositivos/6/estado', ['estado' => 'activo', 'tiempo_respuesta_ms' => 2, 'fecha' => '2026-09-28 10:10:00']);
comprobarRespuesta($r, 200, 'PATCH activo con un entero (2), como el ping de Windows');
comprobarNumero(2, $r['json']['data']['ultimo_tiempo_ms'] ?? null, '  ultimo_tiempo_ms = 2');
$r = peticion('PATCH', '/dispositivos/6/estado', ['estado' => 'activo', 'tiempo_respuesta_ms' => 2.5, 'fecha' => '2026-09-28 10:12:00']);
comprobarNumero(2.5, $r['json']['data']['ultimo_tiempo_ms'] ?? null, 'PATCH activo con decimal 2.5 → ultimo_tiempo_ms = 2.5');
$r = peticion('PATCH', '/dispositivos/6/estado', ['estado' => 'activo', 'tiempo_respuesta_ms' => 0, 'fecha' => '2026-09-28 10:15:00']);
comprobarRespuesta($r, 200, 'PATCH activo con 0 (ping < 1 ms)');
comprobarNumero(0, $r['json']['data']['ultimo_tiempo_ms'] ?? null, '  ultimo_tiempo_ms = 0');
$r = peticion('PATCH', '/dispositivos/6/estado', ['estado' => 'inactivo', 'tiempo_respuesta_ms' => null, 'fecha' => '2026-09-28 10:20:00']);
comprobarRespuesta($r, 200, 'PATCH inactivo con tiempo null');
comprobarIgual(['inactivo', null, '2026-09-28 10:20:00'], campos($r['json']['data'] ?? null, ['estado', 'ultimo_tiempo_ms', 'ultima_comprobacion']), '  queda inactivo, con tiempo null y la nueva fecha');
$r = peticion('PATCH', '/dispositivos/6/estado', ['estado' => 'inactivo', 'fecha' => '2026-09-28 10:25:00']);
comprobarRespuesta($r, 200, 'PATCH inactivo sin tiempo_respuesta_ms (cuenta como null)');

$mensajeTiempo = 'El tiempo de respuesta debe ser un número mayor o igual a 0';
$casosPatch = [
    [['estado' => 'activo', 'tiempo_respuesta_ms' => -1], 'tiempo_respuesta_ms', $mensajeTiempo, 'activo con tiempo negativo'],
    [['estado' => 'activo', 'tiempo_respuesta_ms' => null], 'tiempo_respuesta_ms', $mensajeTiempo, 'activo con tiempo null'],
    [['estado' => 'activo'], 'tiempo_respuesta_ms', $mensajeTiempo, 'activo sin tiempo'],
    [['estado' => 'activo', 'tiempo_respuesta_ms' => '0.3'], 'tiempo_respuesta_ms', $mensajeTiempo, 'activo con tiempo como texto'],
    [['estado' => 'activo', 'tiempo_respuesta_ms' => true], 'tiempo_respuesta_ms', $mensajeTiempo, 'activo con tiempo booleano'],
    [['estado' => 'activo', 'tiempo_respuesta_ms' => 100000], 'tiempo_respuesta_ms', $mensajeTiempo, 'activo con tiempo que no cabe en DECIMAL(7,2)'],
    [['estado' => 'inactivo', 'tiempo_respuesta_ms' => 5], 'tiempo_respuesta_ms', 'Con estado inactivo, el tiempo de respuesta debe ser null', 'inactivo con tiempo'],
    [['estado' => 'desconocido', 'tiempo_respuesta_ms' => null], 'estado', 'Estado no válido', 'estado desconocido (solo activo o inactivo)'],
    [['estado' => 'ACTIVO', 'tiempo_respuesta_ms' => 1], 'estado', 'Estado no válido', 'estado en mayúsculas'],
    [['tiempo_respuesta_ms' => 1], 'estado', 'Estado no válido', 'sin estado'],
];
foreach ($casosPatch as [$cuerpo, $campo, $mensaje, $descripcion]) {
    $cuerpo += ['fecha' => '2026-09-28 11:00:00'];
    comprobarErrorCampo(peticion('PATCH', '/dispositivos/1/estado', $cuerpo), 400, $campo, $mensaje, "PATCH $descripcion");
}

$mensajeFecha = 'Fecha no válida, usa el formato YYYY-MM-DD HH:MM:SS';
$fechasInvalidas = [
    [null, 'sin fecha'],
    ['', 'fecha vacía'],
    ['2026-09-28', 'fecha sin hora'],
    ['2026-13-45 10:00:00', 'fecha inexistente'],
    ['2026-02-30 10:00:00', '30 de febrero'],
    ['28/09/2026 10:00:00', 'otro formato'],
    ['2026-09-28T10:00:00', 'formato ISO con T'],
    ['2026-09-28 25:00:00', 'hora 25'],
];
foreach ($fechasInvalidas as [$fecha, $descripcion]) {
    $cuerpo = ['estado' => 'inactivo', 'tiempo_respuesta_ms' => null];
    if ($fecha !== null) {
        $cuerpo['fecha'] = $fecha;
    }
    comprobarErrorCampo(peticion('PATCH', '/dispositivos/1/estado', $cuerpo), 400, 'fecha', $mensajeFecha, "PATCH $descripcion");
}
comprobarIgual(
    ['activo', '0.06'],
    [valorBd('SELECT estado FROM dispositivos WHERE id = 1'), valorBd('SELECT ultimo_tiempo_ms FROM dispositivos WHERE id = 1')],
    'los PATCH rechazados no modificaron a Mi PC'
);

// =====================================================================
seccion('9. Los acentos llegan intactos de ida y vuelta');

$r = peticion('GET', '/dispositivos/3');
comprobarIgual(['PC Recepción', 'Recepción'], [$r['json']['data']['nombre'] ?? '', $r['json']['data']['ubicacion'] ?? ''], 'GET "PC Recepción" del seed');
comprobar(str_contains($r['texto'], 'Recepción'), '  el JSON lleva "ó" sin escapar (ó)');

$r = peticion('POST', '/dispositivos', ['nombre' => 'Ñandú de Recepción', 'ip' => '10.0.0.80', 'tipo' => 'impresora', 'ubicacion' => 'Área de cómputo']);
comprobarRespuesta($r, 201, 'POST "Ñandú de Recepción" en "Área de cómputo"');
$id = $r['json']['data']['id'] ?? 0;
comprobarIgual(['Ñandú de Recepción', 'Área de cómputo'], [$r['json']['data']['nombre'] ?? '', $r['json']['data']['ubicacion'] ?? ''], '  la respuesta los trae intactos');
$r = peticion('GET', "/dispositivos/$id");
comprobarIgual(['Ñandú de Recepción', 'Área de cómputo'], [$r['json']['data']['nombre'] ?? '', $r['json']['data']['ubicacion'] ?? ''], '  GET los devuelve intactos');
comprobarIgual(bin2hex('Ñandú de Recepción'), bin2hex((string) valorBd('SELECT nombre FROM dispositivos WHERE id = ?', [$id])), '  en la base están guardados en UTF-8');
$r = peticion('PUT', "/dispositivos/$id", ['nombre' => 'Pingüino', 'ip' => '10.0.0.80', 'tipo' => 'impresora', 'ubicacion' => 'Dirección']);
comprobarIgual(['Pingüino', 'Dirección'], [$r['json']['data']['nombre'] ?? '', $r['json']['data']['ubicacion'] ?? ''], 'PUT "Pingüino" en "Dirección"');

terminarPruebas();
