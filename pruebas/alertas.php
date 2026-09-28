<?php
/**
 * Pruebas del Servicio de Alertas (docs/PLAN.md, Fase 3 · "Listo cuando").
 * Recarga la base de datos al empezar: borra los datos agregados a mano.
 *
 *   /opt/lampp/bin/php pruebas/alertas.php
 *
 * La prueba de POST simultáneos usa la extensión curl; si no está, se omite con un aviso.
 */

declare(strict_types=1);

require_once __DIR__ . '/ayudas.php';

const CLAVES_ALERTA = ['id', 'dispositivo_id', 'tipo', 'mensaje', 'fecha', 'atendida', 'fecha_atendida'];
const RONDAS_SIMULTANEAS = 5;
const PETICIONES_POR_RONDA = 10;

/**
 * Cuerpo válido para POST /alertas.
 */
function alertaValida(int $dispositivoId, array $cambios = []): array
{
    return array_merge([
        'dispositivo_id' => $dispositivoId,
        'tipo' => 'sin_respuesta',
        'mensaje' => "El dispositivo $dispositivoId no respondió al monitoreo.",
    ], $cambios);
}

/**
 * Comprueba que la fecha tenga el formato acordado y esté a menos de 60 s de
 * la hora actual de America/Mazatlan.
 */
function comprobarFechaActual(mixed $fecha, string $descripcion): bool
{
    $zona = new DateTimeZone('America/Mazatlan');
    $valor = is_string($fecha) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fecha, $zona) : false;

    return comprobar(
        $valor !== false && abs($valor->getTimestamp() - time()) <= 60,
        $descripcion,
        'fecha: ' . exportar($fecha)
    );
}

function idsDe(array $respuesta): array
{
    return array_column($respuesta['json']['data'] ?? [], 'id');
}

function pendientesDelDispositivo(int $dispositivoId): int
{
    return (int) valorBd('SELECT COUNT(*) FROM alertas WHERE dispositivo_id = ? AND atendida = 0', [$dispositivoId]);
}

echo "Pruebas del Servicio de Alertas\n";
echo "Recargando la base de datos (schema.sql + seed.sql)...\n";
recargarBaseDeDatos();

// =====================================================================
seccion('1. GET /alertas filtra por atendida, dispositivo y limite, de la más reciente a la más antigua');

$r = peticion('GET', '/alertas');
comprobarRespuesta($r, 200, 'GET /alertas');
comprobarIgual([4, 3, 2, 1], idsDe($r), '  las 4 alertas del seed, de la más reciente a la más antigua');
$claves = array_map(fn($a) => is_array($a) ? array_keys($a) : null, $r['json']['data'] ?? []);
comprobar($claves !== [] && count(array_unique(array_map('serialize', $claves))) === 1 && $claves[0] === CLAVES_ALERTA,
    '  cada alerta tiene exactamente las 7 claves del objeto alerta (sin dispositivo_pendiente)');
comprobarIgual([
    'id' => 4,
    'dispositivo_id' => 4,
    'tipo' => 'sin_respuesta',
    'mensaje' => 'El dispositivo Servidor principal no respondió al monitoreo.',
    'fecha' => '2026-09-27 09:30:01',
    'atendida' => false,
    'fecha_atendida' => null,
], $r['json']['data'][0] ?? null, '  alerta pendiente idéntica al seed (atendida es el booleano false)');
comprobarIgual([
    'id' => 1,
    'dispositivo_id' => 4,
    'tipo' => 'sin_respuesta',
    'mensaje' => 'El dispositivo Servidor principal no respondió al monitoreo.',
    'fecha' => '2026-09-22 08:15:01',
    'atendida' => true,
    'fecha_atendida' => '2026-09-22 09:00:00',
], $r['json']['data'][3] ?? null, '  alerta atendida idéntica al seed (atendida es el booleano true)');

$filtros = [
    ['?atendida=0', [4], 'solo pendientes'],
    ['?atendida=1', [3, 2, 1], 'solo atendidas'],
    ['?dispositivo=4', [4, 1], 'del Servidor principal'],
    ['?dispositivo=4&atendida=1', [1], 'atendidas del Servidor principal'],
    ['?dispositivo=4&atendida=0', [4], 'pendientes del Servidor principal'],
    ['?limite=2', [4, 3], 'las 2 más recientes'],
    ['?limite=1&atendida=1', [3], 'la atendida más reciente'],
    ['?limite=100', [4, 3, 2, 1], 'límite máximo'],
    ['?dispositivo=999', [], 'dispositivo sin alertas → lista vacía, no 404'],
    ['?dispositivo=1', [], 'Mi PC no tiene alertas'],
    ['?atendida=&dispositivo=&limite=', [4, 3, 2, 1], 'filtros vacíos → sin filtro'],
];
foreach ($filtros as [$consulta, $esperados, $descripcion]) {
    $r = peticion('GET', "/alertas$consulta");
    comprobarRespuesta($r, 200, "GET /alertas$consulta");
    comprobarIgual($esperados, idsDe($r), "  $descripcion");
}

$filtrosInvalidos = [
    ['?atendida=2', 'atendida', 'El filtro atendida debe ser 0 o 1'],
    ['?atendida=true', 'atendida', 'El filtro atendida debe ser 0 o 1'],
    ['?atendida[]=1', 'atendida', 'El filtro atendida debe ser 0 o 1'],
    ['?dispositivo=abc', 'dispositivo', 'Id de dispositivo no válido'],
    ['?dispositivo=0', 'dispositivo', 'Id de dispositivo no válido'],
    ['?dispositivo=-4', 'dispositivo', 'Id de dispositivo no válido'],
    ['?limite=0', 'limite', 'El límite debe ser un número entre 1 y 100'],
    ['?limite=101', 'limite', 'El límite debe ser un número entre 1 y 100'],
    ['?limite=abc', 'limite', 'El límite debe ser un número entre 1 y 100'],
    ['?limite=2.5', 'limite', 'El límite debe ser un número entre 1 y 100'],
];
foreach ($filtrosInvalidos as [$consulta, $campo, $mensaje]) {
    $r = peticion('GET', "/alertas$consulta");
    comprobarErrorCampo($r, 400, $campo, $mensaje, "GET /alertas$consulta");
}
comprobarIgual('Filtros no válidos', peticion('GET', '/alertas?limite=0')['json']['error'] ?? null, 'mensaje general: "Filtros no válidos"');
$r = peticion('GET', '/alertas?atendida=5&dispositivo=x&limite=0');
comprobarIgual(['atendida', 'dispositivo', 'limite'], array_keys($r['json']['campos'] ?? []), 'tres filtros inválidos → los tres en campos');

// =====================================================================
seccion('2. GET /alertas/conteo coincide con el seed (1 pendiente, 3 atendidas)');

$r = peticion('GET', '/alertas/conteo');
comprobarRespuesta($r, 200, 'GET /alertas/conteo');
comprobarIgual(['pendientes' => 1, 'atendidas' => 3], $r['json']['data'] ?? null, '  {"pendientes": 1, "atendidas": 3}, como enteros');

// =====================================================================
seccion('3. POST sin alerta pendiente → 201 creada: true; una segunda vez → 200 creada: false y la misma alerta');

$cuerpo = alertaValida(5, ['mensaje' => '  El dispositivo Impresora Laboratorio no respondió al monitoreo.  ']);
$r = peticion('POST', '/alertas', $cuerpo);
comprobarRespuesta($r, 201, 'POST /alertas para la Impresora (sin pendiente)');
$primera = $r['json']['data']['alerta'] ?? [];
comprobarIgual(true, $r['json']['data']['creada'] ?? null, '  creada: true');
comprobarIgual(['alerta', 'creada'], array_keys($r['json']['data'] ?? []), '  data = { alerta, creada }');
comprobarIgual(CLAVES_ALERTA, is_array($primera) ? array_keys($primera) : null, '  la alerta tiene las 7 claves del objeto alerta');
comprobarIgual(
    [5, 'sin_respuesta', 'El dispositivo Impresora Laboratorio no respondió al monitoreo.', false, null],
    campos($primera, ['dispositivo_id', 'tipo', 'mensaje', 'atendida', 'fecha_atendida']),
    '  dispositivo 5, mensaje recortado, atendida false y fecha_atendida null'
);
comprobarFechaActual($primera['fecha'] ?? null, '  fecha generada por PHP, en hora de America/Mazatlan (±60 s)');

$r = peticion('POST', '/alertas', alertaValida(5, ['mensaje' => 'Otro mensaje que no debe guardarse']));
comprobarRespuesta($r, 200, 'POST otra vez para la Impresora');
comprobarIgual(['alerta' => $primera, 'creada' => false], $r['json']['data'] ?? null, '  creada: false y exactamente la misma alerta (el mensaje nuevo se ignora)');
comprobarIgual(1, pendientesDelDispositivo(5), '  la Impresora tiene una sola alerta pendiente en la base');

$r = peticion('POST', '/alertas', alertaValida(4));
comprobarRespuesta($r, 200, 'POST para el Servidor principal (ya tiene la pendiente del seed)');
comprobarIgual([4, false], [$r['json']['data']['alerta']['id'] ?? null, $r['json']['data']['creada'] ?? null], '  devuelve la alerta 4 del seed con creada: false');
comprobarIgual(['pendientes' => 2, 'atendidas' => 3], peticion('GET', '/alertas/conteo')['json']['data'] ?? null, 'el conteo pasa a 2 pendientes');

// =====================================================================
seccion('4. Después de atender esa alerta, un nuevo POST vuelve a crear una (201)');

$idPrimera = $primera['id'] ?? 0;
comprobarRespuesta(peticion('PATCH', "/alertas/$idPrimera/atender"), 200, "PATCH /alertas/$idPrimera/atender");
$r = peticion('POST', '/alertas', alertaValida(5));
comprobarRespuesta($r, 201, 'POST para la Impresora otra vez');
$segunda = $r['json']['data']['alerta'] ?? [];
comprobar(($r['json']['data']['creada'] ?? null) === true && ($segunda['id'] ?? $idPrimera) !== $idPrimera,
    '  creada: true, con un id nuevo', exportar($r['json']['data'] ?? null));
comprobarIgual([1, 1], [pendientesDelDispositivo(5), (int) valorBd('SELECT COUNT(*) FROM alertas WHERE dispositivo_id = 5 AND atendida = 1 AND id = ?', [$idPrimera])],
    '  una pendiente (la nueva) y la anterior sigue atendida');

// =====================================================================
seccion('5. PATCH /atender guarda fecha_atendida y es idempotente');

$idSegunda = $segunda['id'] ?? 0;
$r = peticion('PATCH', "/alertas/$idSegunda/atender");
comprobarRespuesta($r, 200, "PATCH /alertas/$idSegunda/atender (pendiente)");
$atendida = $r['json']['data'] ?? [];
comprobarIgual(CLAVES_ALERTA, is_array($atendida) ? array_keys($atendida) : null, '  responde el objeto alerta');
comprobarIgual(true, $atendida['atendida'] ?? null, '  atendida: true');
comprobarFechaActual($atendida['fecha_atendida'] ?? null, '  fecha_atendida generada por PHP, en hora de America/Mazatlan (±60 s)');
comprobarIgual(
    campos($segunda, ['id', 'dispositivo_id', 'tipo', 'mensaje', 'fecha']),
    campos($atendida, ['id', 'dispositivo_id', 'tipo', 'mensaje', 'fecha']),
    '  el resto de la alerta no cambia'
);

sleep(1); // Para que una segunda escritura se notara en fecha_atendida.
$r = peticion('PATCH', "/alertas/$idSegunda/atender");
comprobarRespuesta($r, 200, '  segunda llamada');
comprobarIgual($atendida, $r['json']['data'] ?? null, '  la alerta queda idéntica (fecha_atendida no cambia)');

$r = peticion('PATCH', '/alertas/1/atender');
comprobarRespuesta($r, 200, 'PATCH de una alerta del seed ya atendida');
comprobarIgual('2026-09-22 09:00:00', $r['json']['data']['fecha_atendida'] ?? null, '  conserva su fecha_atendida original');
$r = peticion('PATCH', '/alertas/4/atender', '{"cualquier": "cosa"}');
comprobarRespuesta($r, 200, 'PATCH con un cuerpo (se ignora)');
$r = peticion('PATCH', '/alertas/5/atender', 'esto no es JSON');
comprobarRespuesta($r, 200, 'PATCH con un cuerpo que no es JSON (se ignora, no hace falta cuerpo)');
comprobarIgual(['pendientes' => 0, 'atendidas' => 6], peticion('GET', '/alertas/conteo')['json']['data'] ?? null, 'el conteo refleja las alertas atendidas');

comprobarRespuesta(peticion('PATCH', '/alertas/999/atender'), 404, 'PATCH /alertas/999/atender', 'La alerta no existe');

// =====================================================================
seccion('6. Dispositivo inexistente → 404; datos faltantes o tipo inválido → 400');

$r = peticion('POST', '/alertas', alertaValida(999));
comprobarRespuesta($r, 404, 'POST para el dispositivo 999', 'El dispositivo no existe');
comprobarIgual(0, (int) valorBd('SELECT COUNT(*) FROM alertas WHERE dispositivo_id = 999'), '  no se guardó nada');

$casos = [
    ['dispositivo_id', ['dispositivo_id' => null], 'Id de dispositivo no válido', 'sin dispositivo_id'],
    ['dispositivo_id', ['dispositivo_id' => '5'], 'Id de dispositivo no válido', 'dispositivo_id como texto'],
    ['dispositivo_id', ['dispositivo_id' => 0], 'Id de dispositivo no válido', 'dispositivo_id 0'],
    ['dispositivo_id', ['dispositivo_id' => -3], 'Id de dispositivo no válido', 'dispositivo_id negativo'],
    ['dispositivo_id', ['dispositivo_id' => 1.5], 'Id de dispositivo no válido', 'dispositivo_id decimal'],
    ['dispositivo_id', ['dispositivo_id' => true], 'Id de dispositivo no válido', 'dispositivo_id booleano'],
    ['tipo', ['tipo' => null], 'Tipo de alerta no válido', 'sin tipo'],
    ['tipo', ['tipo' => 'otro'], 'Tipo de alerta no válido', 'tipo no permitido'],
    ['tipo', ['tipo' => 'SIN_RESPUESTA'], 'Tipo de alerta no válido', 'tipo en mayúsculas'],
    ['mensaje', ['mensaje' => null], 'El mensaje es obligatorio', 'sin mensaje'],
    ['mensaje', ['mensaje' => '   '], 'El mensaje es obligatorio', 'mensaje solo con espacios'],
    ['mensaje', ['mensaje' => 42], 'El mensaje es obligatorio', 'mensaje que no es texto'],
    ['mensaje', ['mensaje' => str_repeat('é', 256)], 'Máximo 255 caracteres', 'mensaje de 256 caracteres'],
];
foreach ($casos as [$campo, $cambio, $mensaje, $descripcion]) {
    $cuerpo = alertaValida(1, $cambio);
    if ($cambio[$campo] === null) {
        unset($cuerpo[$campo]);
    }
    comprobarErrorCampo(peticion('POST', '/alertas', $cuerpo), 400, $campo, $mensaje, "POST $descripcion");
}
comprobarIgual('Revisa los datos de la alerta', peticion('POST', '/alertas', '{}')['json']['error'] ?? null, 'mensaje general: "Revisa los datos de la alerta"');
comprobarIgual(['dispositivo_id', 'tipo', 'mensaje'], array_keys(peticion('POST', '/alertas', '{}')['json']['campos'] ?? []), 'cuerpo {} → los tres campos en campos');
comprobarIgual(0, pendientesDelDispositivo(1), 'ninguno de esos POST creó alertas para Mi PC');

$r = peticion('POST', '/alertas', alertaValida(1, ['mensaje' => str_repeat('é', 255)]));
comprobarRespuesta($r, 201, 'mensaje de exactamente 255 caracteres con acento');
comprobarIgual(255, mb_strlen((string) ($r['json']['data']['alerta']['mensaje'] ?? '')), '  se guarda completo');
peticion('PATCH', '/alertas/' . ($r['json']['data']['alerta']['id'] ?? 0) . '/atender');

comprobarRespuesta(peticion('POST', '/alertas', '{"dispositivo_id": 1,'), 400, 'POST con JSON cortado', 'El cuerpo de la petición no es JSON válido');
comprobarRespuesta(peticion('POST', '/alertas', ''), 400, 'POST sin cuerpo', 'El cuerpo de la petición no es JSON válido');
comprobarRespuesta(peticion('POST', '/alertas', '[1]'), 400, 'POST con un arreglo', 'El cuerpo de la petición debe ser un objeto JSON');

foreach (['/alertas/1', '/alertas/abc/atender', '/alertas/0/atender', '/alertas/1/atender/x', '/alertas/conteo/x', '/alertas/datos.php'] as $ruta) {
    comprobarRespuesta(peticion('GET', $ruta), 404, "GET $ruta", 'La ruta no existe');
}
foreach ([['DELETE', '/alertas', 'GET, POST'], ['PUT', '/alertas', 'GET, POST'], ['POST', '/alertas/conteo', 'GET'],
          ['GET', '/alertas/1/atender', 'PATCH'], ['POST', '/alertas/1/atender', 'PATCH']] as [$metodo, $ruta, $permitidos]) {
    $r = peticion($metodo, $ruta);
    comprobarRespuesta($r, 405, "$metodo $ruta", 'Método no permitido');
    comprobarIgual($permitidos, $r['cabeceras']['allow'] ?? null, "  cabecera Allow: $permitidos");
}

// =====================================================================
seccion('7. Una sola alerta pendiente por dispositivo, también con POST simultáneos');

try {
    obtenerConexion()->exec(
        "INSERT INTO alertas (dispositivo_id, tipo, mensaje, fecha) VALUES (2, 'sin_respuesta', 'a', '2026-09-28 10:00:00')"
    );
    obtenerConexion()->exec(
        "INSERT INTO alertas (dispositivo_id, tipo, mensaje, fecha) VALUES (2, 'sin_respuesta', 'b', '2026-09-28 10:00:01')"
    );
    comprobar(false, 'la base rechaza una segunda pendiente insertada directo por SQL', 'se insertaron dos pendientes');
} catch (PDOException $error) {
    comprobarIgual(1062, $error->errorInfo[1] ?? null, 'la base rechaza una segunda pendiente insertada directo por SQL (error 1062)');
}
comprobarIgual(1, pendientesDelDispositivo(2), '  el Router quedó con una sola pendiente');
obtenerConexion()->exec("UPDATE alertas SET atendida = 1, fecha_atendida = '2026-09-28 10:05:00' WHERE dispositivo_id = 2 AND atendida = 0");

if (!curlDisponible()) {
    avisar('Prueba de POST simultáneos omitida: la extensión curl de PHP no está activada (extension=curl en php.ini).');
} else {
    for ($ronda = 1; $ronda <= RONDAS_SIMULTANEAS; $ronda++) {
        $respuestas = peticionesSimultaneas(array_fill(0, PETICIONES_POR_RONDA, ['POST', '/alertas', alertaValida(1)]));
        $codigos = array_count_values(array_column($respuestas, 'codigo'));
        $ids = array_unique(array_map(fn($r) => $r['json']['data']['alerta']['id'] ?? null, $respuestas));
        $creadas = count(array_filter($respuestas, fn($r) => ($r['json']['data']['creada'] ?? null) === true));

        comprobar(
            ($codigos[201] ?? 0) === 1 && ($codigos[200] ?? 0) === PETICIONES_POR_RONDA - 1
                && $creadas === 1 && count($ids) === 1 && pendientesDelDispositivo(1) === 1,
            "ronda $ronda: " . PETICIONES_POR_RONDA . ' POST simultáneos → un 201, '
                . (PETICIONES_POR_RONDA - 1) . ' × 200 con la misma alerta, una pendiente en la base',
            'códigos: ' . exportar($codigos) . ' | ids: ' . exportar(array_values($ids)) . ' | pendientes: ' . pendientesDelDispositivo(1)
        );

        peticion('PATCH', '/alertas/' . reset($ids) . '/atender');
    }
}

// =====================================================================
seccion('Límite por defecto: 50 alertas');

$insertar = obtenerConexion()->prepare(
    "INSERT INTO alertas (dispositivo_id, tipo, mensaje, fecha, atendida, fecha_atendida)
     VALUES (6, 'sin_respuesta', 'Alerta de relleno', ?, 1, ?)"
);
for ($i = 0; $i < 60; $i++) {
    $fecha = sprintf('2026-09-01 10:%02d:00', $i);
    $insertar->execute([$fecha, $fecha]);
}
$total = (int) valorBd('SELECT COUNT(*) FROM alertas');
comprobarIgual(50, count(peticion('GET', '/alertas')['json']['data'] ?? []), "con $total alertas en la base, GET /alertas devuelve 50");
comprobarIgual(min(100, $total), count(peticion('GET', '/alertas?limite=100')['json']['data'] ?? []), '  y con limite=100 devuelve ' . min(100, $total));

terminarPruebas();
