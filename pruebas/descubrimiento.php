<?php
/**
 * Pruebas del Servicio de Descubrimiento (docs/PLAN.md, Fase 9 · "Listo cuando").
 * Recarga la base de datos al empezar: borra los datos agregados a mano.
 *
 *   /opt/lampp/bin/php pruebas/descubrimiento.php
 *
 * No depende de la red de quien la corre ni envía nada a la red local:
 * - Las funciones de lectura (tabla ARP de cada sistema, DNS) se prueban con
 *   salidas de ejemplo fijas.
 * - A través de Apache se usa la red simulada (pruebas/.red-simulada.json, ver
 *   servicios/descubrimiento/simulacion.php): 192.0.2.0/24, donde nada responde,
 *   con una tabla ARP de ejemplo, y 127.0.0.0/24, donde todo responde. Los pings
 *   son reales. El archivo caduca solo y se borra al terminar.
 */

declare(strict_types=1);

require_once __DIR__ . '/ayudas.php';
require_once __DIR__ . '/../servicios/descubrimiento/red.php';
require_once __DIR__ . '/../servicios/descubrimiento/escaneo.php';
require_once __DIR__ . '/../servicios/descubrimiento/arp.php';
require_once __DIR__ . '/../servicios/descubrimiento/dns.php';
require_once __DIR__ . '/../servicios/descubrimiento/simulacion.php';

const CLAVES_RED = ['red', 'ip_propia', 'puerta_enlace', 'lotes', 'total_direcciones'];
const CLAVES_LOTE = ['lote', 'desde', 'hasta', 'encontrados'];
const CLAVES_EQUIPO = ['ip', 'mac', 'nombre', 'responde_ping', 'tiempo_respuesta_ms', 'en_arp', 'es_esta_computadora', 'es_puerta_enlace', 'dispositivo'];
const MENSAJE_SIN_RED = 'No se detectó una red local. Conecta la computadora a una red WiFi o cableada.';
const MENSAJE_RED_NO_PRIVADA = 'Solo se pueden escanear redes privadas (10.x, 172.16–31.x o 192.168.x).';
const LIMITE_LOTE_SEGUNDOS = 3.0;

// ---------------------------------------------------------------- Ayudas

/**
 * Escribe la red simulada por 30 segundos. $datos: ip_propia, puerta_enlace, arp.
 */
function simularRed(array $datos): void
{
    file_put_contents(ARCHIVO_RED_SIMULADA, json_encode($datos + ['expira' => time() + 30]));
}

function quitarRedSimulada(): void
{
    if (is_file(ARCHIVO_RED_SIMULADA)) {
        unlink(ARCHIVO_RED_SIMULADA);
    }
}

/**
 * POST a un lote. Devuelve [respuesta, segundos].
 */
function escanear(int|string $lote): array
{
    $inicio = microtime(true);
    $respuesta = peticion('POST', "/descubrimiento/red/lotes/$lote");

    return [$respuesta, microtime(true) - $inicio];
}

/**
 * Tabla ARP de ejemplo en el formato del sistema operativo donde corre Apache
 * (el mismo que el de esta prueba). $entradas: [ip, mac con ":", estado].
 * Los estados FAILED/INCOMPLETE se escriben como el sistema los muestra.
 */
function tablaArpDeEjemplo(array $entradas): string
{
    $lineas = [];
    $formato = formatoArpDelSistema();

    if ($formato === FORMATO_WINDOWS) {
        $lineas[] = '';
        $lineas[] = 'Interfaz: 192.0.2.1 --- 0xb';
        $lineas[] = '  Dirección de Internet          Dirección física      Tipo';
    }

    foreach ($entradas as [$ip, $mac, $estado]) {
        $incompleta = in_array($estado, ['FAILED', 'INCOMPLETE'], true);
        $lineas[] = match ($formato) {
            FORMATO_WINDOWS => $incompleta ? "  $ip            00-00-00-00-00-00     no válido" : sprintf('  %-22s%-22sdinámico', $ip, str_replace(':', '-', $mac)),
            FORMATO_MAC => $incompleta ? "? ($ip) at (incomplete) on en0 ifscope [ethernet]" : "? ($ip) at $mac on en0 ifscope [ethernet]",
            default => $incompleta ? "$ip dev eth0  $estado" : "$ip dev eth0 lladdr $mac $estado",
        };
    }

    return implode("\n", $lineas) . "\n";
}

function ipsDe(array $equipos): array
{
    return array_map(fn(array $e): string => $e['ip'], $equipos);
}

/**
 * Comprueba que cada equipo tenga exactamente las claves del contrato y los tipos correctos.
 */
function comprobarFormaEquipos(array $equipos, string $descripcion): void
{
    $problemas = [];

    foreach ($equipos as $e) {
        if (!is_array($e) || array_keys($e) !== CLAVES_EQUIPO) {
            $problemas[] = 'claves: ' . exportar(is_array($e) ? array_keys($e) : $e);
            continue;
        }
        $tiempoValido = $e['responde_ping']
            ? is_int($e['tiempo_respuesta_ms']) || is_float($e['tiempo_respuesta_ms'])
            : $e['tiempo_respuesta_ms'] === null;
        $dispositivoValido = $e['dispositivo'] === null
            || (is_array($e['dispositivo']) && array_keys($e['dispositivo']) === ['id', 'nombre'] && is_int($e['dispositivo']['id']));
        if (!is_string($e['ip']) || !($e['mac'] === null || is_string($e['mac'])) || !($e['nombre'] === null || is_string($e['nombre']))
            || !is_bool($e['responde_ping']) || !$tiempoValido || !is_bool($e['en_arp']) || $e['en_arp'] !== ($e['mac'] !== null)
            || !is_bool($e['es_esta_computadora']) || !is_bool($e['es_puerta_enlace']) || !$dispositivoValido) {
            $problemas[] = exportar($e);
        }
    }

    comprobar($problemas === [], $descripcion, implode(' | ', array_slice($problemas, 0, 2)));
}

echo "Pruebas del Servicio de Descubrimiento\n";
echo "Recargando la base de datos (schema.sql + seed.sql)...\n";
recargarBaseDeDatos();
quitarRedSimulada();
quitarFalloSimulado();
register_shutdown_function('quitarRedSimulada');
register_shutdown_function('quitarFalloSimulado');
iniciarSesionDePrueba();

// =====================================================================
seccion('1 · Red y lotes (red.php)');

foreach ([
    ['10.0.0.1', true], ['10.255.255.254', true], ['172.16.0.1', true], ['172.31.255.254', true],
    ['192.168.137.66', true], ['172.15.255.254', false], ['172.32.0.1', false], ['192.169.0.1', false],
    ['8.8.8.8', false], ['198.51.100.1', false], ['100.64.0.1', false], ['127.0.0.1', false], ['texto', false],
] as [$ip, $esperado]) {
    comprobarIgual($esperado, esIpPrivada($ip), "esIpPrivada('$ip') → " . ($esperado ? 'sí' : 'no'));
}

comprobarIgual(
    ['red' => '192.168.1.0/24', 'ip_propia' => '192.168.1.57', 'puerta_enlace' => '192.168.1.254', 'lotes' => 4, 'total_direcciones' => 254],
    describirRed('192.168.1.57', '192.168.1.254'),
    'describirRed: la /24 de la IP propia, con la puerta de enlace'
);
comprobarIgual(null, describirRed('192.168.1.57', '10.0.0.1')['puerta_enlace'], 'Una puerta de enlace fuera de la /24 se descarta (null)');
comprobarIgual(null, describirRed('192.168.1.57', null)['puerta_enlace'], 'Sin puerta de enlace → null');

$todas = [];
foreach ([1 => ['.1', '.64', 64], 2 => ['.65', '.128', 64], 3 => ['.129', '.192', 64], 4 => ['.193', '.254', 62]] as $lote => [$desde, $hasta, $cuantas]) {
    $direcciones = direccionesDelLote('10.1.2', $lote);
    comprobarIgual(["10.1.2$desde", "10.1.2$hasta", $cuantas], [$direcciones[0], end($direcciones), count($direcciones)], "Lote $lote: 10.1.2$desde – 10.1.2$hasta ($cuantas direcciones)");
    $todas = [...$todas, ...$direcciones];
}
comprobarIgual(254, count(array_unique($todas)), 'Los 4 lotes cubren .1 a .254 sin repetir (ni .0 ni .255)');
comprobarIgual([null, null], [direccionesDelLote('10.1.2', 0), direccionesDelLote('10.1.2', 5)], 'Los lotes 0 y 5 no existen');

// =====================================================================
seccion('2 · Tabla ARP de cada sistema, sin depender del idioma (arp.php)');

$ipNeigh = <<<TXT
192.168.1.1 dev wlan0 lladdr BE:1E:A4:DA:96:D3 REACHABLE
192.168.1.20 dev wlan0 lladdr 00:11:22:33:44:55 STALE
192.168.1.21 dev wlan0 lladdr 00:11:22:33:44:56 DELAY
192.168.1.22 dev wlan0 lladdr 00:11:22:33:44:57 PROBE
192.168.1.30 dev wlan0  FAILED
192.168.1.31 dev wlan0  INCOMPLETE
192.168.1.32 dev wlan0 lladdr 00:11:22:33:44:58 FAILED
192.168.1.40 dev wlan0 lladdr 00:11:22:33:44:59 PERMANENT
192.168.1.255 dev wlan0 lladdr ff:ff:ff:ff:ff:ff PERMANENT
224.0.0.251 dev wlan0 lladdr 01:00:5e:00:00:fb NOARP
TXT;
comprobarIgual(
    ['192.168.1.1' => 'be:1e:a4:da:96:d3', '192.168.1.20' => '00:11:22:33:44:55', '192.168.1.21' => '00:11:22:33:44:56', '192.168.1.22' => '00:11:22:33:44:57', '192.168.1.40' => '00:11:22:33:44:59'],
    interpretarTablaArp(FORMATO_IP_NEIGH, $ipNeigh),
    'Linux, ip neigh: REACHABLE, STALE, DELAY, PROBE y PERMANENT; sin FAILED, INCOMPLETE, broadcast ni multicast'
);

$procNetArp = <<<TXT
IP address       HW type     Flags       HW address            Mask     Device
192.168.1.1      0x1         0x2         be:1e:a4:da:96:d3     *        wlan0
192.168.1.20     0x1         0x6         00:11:22:33:44:55     *        wlan0
192.168.1.30     0x1         0x0         00:00:00:00:00:00     *        wlan0
TXT;
comprobarIgual(
    ['192.168.1.1' => 'be:1e:a4:da:96:d3', '192.168.1.20' => '00:11:22:33:44:55'],
    interpretarTablaArp(FORMATO_PROC_NET_ARP, $procNetArp),
    'Linux, /proc/net/arp (respaldo): solo las entradas completas (bandera 0x2)'
);

$windowsIngles = <<<TXT

Interface: 192.168.1.57 --- 0xb
  Internet Address      Physical Address      Type
  192.168.1.1           be-1e-a4-da-96-d3     dynamic
  192.168.1.20          00-11-22-33-44-55     dynamic
  192.168.1.255         ff-ff-ff-ff-ff-ff     static
  224.0.0.22            01-00-5e-00-00-16     static
  239.255.255.250       01-00-5e-7f-ff-fa     static

Interface: 172.20.10.4 --- 0x12
  Internet Address      Physical Address      Type
  172.20.10.1           aa-bb-cc-00-11-22     dynamic
TXT;
$windowsEspanol = <<<TXT

Interfaz: 192.168.1.57 --- 0xb
  Dirección de Internet          Dirección física      Tipo
  192.168.1.1           be-1e-a4-da-96-d3     dinámico
  192.168.1.20          00-11-22-33-44-55     dinámico
  192.168.1.255         ff-ff-ff-ff-ff-ff     estático
  224.0.0.22            01-00-5e-00-00-16     estático

Interfaz: 172.20.10.4 --- 0x12
  Dirección de Internet          Dirección física      Tipo
  172.20.10.1           aa-bb-cc-00-11-22     dinámico
TXT;
$esperadoWindows = ['192.168.1.1' => 'be:1e:a4:da:96:d3', '192.168.1.20' => '00:11:22:33:44:55', '172.20.10.1' => 'aa:bb:cc:00:11:22'];
comprobarIgual($esperadoWindows, interpretarTablaArp(FORMATO_WINDOWS, $windowsIngles), 'Windows, arp -a en inglés: dos interfaces, sin broadcast ni multicast');
comprobarIgual($esperadoWindows, interpretarTablaArp(FORMATO_WINDOWS, $windowsEspanol), 'Windows, arp -a en español: el mismo resultado');

$mac = <<<TXT
? (192.168.1.1) at be:1e:a4:da:96:d3 on en0 ifscope [ethernet]
? (192.168.1.20) at 0:11:22:3:44:5 on en0 ifscope [ethernet]
? (192.168.1.30) at (incomplete) on en0 ifscope [ethernet]
? (192.168.1.255) at ff:ff:ff:ff:ff:ff on en0 ifscope [ethernet]
? (224.0.0.251) at 1:0:5e:0:0:fb on en0 ifscope permanent [ethernet]
TXT;
comprobarIgual(
    ['192.168.1.1' => 'be:1e:a4:da:96:d3', '192.168.1.20' => '00:11:22:03:44:05'],
    interpretarTablaArp(FORMATO_MAC, $mac),
    'macOS, arp -an: octetos sin cero a la izquierda se completan; sin incompletas, broadcast ni multicast'
);

comprobarIgual([], interpretarTablaArp(FORMATO_IP_NEIGH, "texto cualquiera\n\n"), 'Una salida sin entradas → tabla vacía');
foreach ([['BE-1E-A4-DA-96-D3', 'be:1e:a4:da:96:d3'], ['0:1c:42:0:0:8', '00:1c:42:00:00:08'], ['00:11:22:33:44', null], ['00:11:22:33:44:gg', null]] as [$entrada, $esperado]) {
    comprobarIgual($esperado, normalizarMac($entrada), "normalizarMac('$entrada') → " . exportar($esperado));
}

// =====================================================================
seccion('3 · Nombres por DNS inverso (dns.php)');

$consulta = construirConsultaPtr(0x1234, '192.168.1.20');
comprobarIgual(
    bin2hex(pack('nnnnnn', 0x1234, 0x0100, 1, 0, 0, 0) . "\x0220\x011\x03168\x03192\x07in-addr\x04arpa\x00" . pack('nn', 12, 1)),
    bin2hex($consulta),
    'Consulta PTR de 192.168.1.20: 20.1.168.192.in-addr.arpa, tipo PTR, clase IN'
);

/**
 * Respuesta DNS de ejemplo a $consulta, con un registro PTR cuyo nombre es $datos
 * (ya codificado). $banderas: 0x8180 (respuesta sin error) por defecto.
 */
function respuestaPtr(string $consulta, string $datos, int $banderas = 0x8180, int $respuestas = 1, ?int $id = null): string
{
    $encabezado = pack('nnnnnn', $id ?? unpack('n', $consulta)[1], $banderas, 1, $respuestas, 0, 0);
    $pregunta = substr($consulta, 12);
    $registro = "\xC0\x0C" . pack('nnNn', 12, 1, 3600, strlen($datos)) . $datos; // nombre: puntero a la pregunta

    return $encabezado . $pregunta . ($respuestas > 0 ? $registro : '');
}

$nombreCodificado = "\x09impresora\x03lan\x00";
comprobarIgual('impresora.lan', leerRespuestaPtr(respuestaPtr($consulta, $nombreCodificado), 0x1234), 'Respuesta PTR → "impresora.lan" (sin el punto final)');
// "impresora" + puntero al "in-addr.arpa" de la pregunta (posición 12 + 3 + 2 + 4 + 4 = 25).
comprobarIgual('impresora.in-addr.arpa', leerRespuestaPtr(respuestaPtr($consulta, "\x09impresora\xC0\x19"), 0x1234), 'Nombre comprimido con un puntero dentro del registro');
comprobarIgual(null, leerRespuestaPtr(respuestaPtr($consulta, $nombreCodificado), 0x9999), 'Otro id de consulta → null');
comprobarIgual(null, leerRespuestaPtr(respuestaPtr($consulta, $nombreCodificado, 0x8183), 0x1234), '"No existe" (NXDOMAIN) → null');
comprobarIgual(null, leerRespuestaPtr(respuestaPtr($consulta, '', 0x8180, 0), 0x1234), 'Respuesta sin registros → null');
comprobarIgual(null, leerRespuestaPtr(respuestaPtr($consulta, $nombreCodificado, 0x0100), 0x1234), 'Un paquete que no es respuesta → null');
$ciclo = respuestaPtr($consulta, "\xC0\x00");
$posicionDatos = strlen($ciclo) - 2;
$ciclo = substr($ciclo, 0, $posicionDatos) . "\xC0" . chr($posicionDatos); // puntero a sí mismo
comprobarIgual(null, leerRespuestaPtr($ciclo, 0x1234), 'Un puntero que apunta a sí mismo (ciclo) → null, sin colgarse');
comprobarIgual(null, leerRespuestaPtr(substr(respuestaPtr($consulta, $nombreCodificado), 0, -4), 0x1234), 'Un paquete cortado → null');
comprobarIgual(null, leerRespuestaPtr(respuestaPtr($consulta, "\x0d<img src=x>ab\x00"), 0x1234), 'Un nombre con caracteres raros (HTML) se descarta');
comprobarIgual([], buscarNombres(['192.0.2.5'], []), 'Sin servidores DNS no se consulta nada');

$inicio = microtime(true);
buscarNombres(['192.0.2.5', '192.0.2.6'], ['127.0.0.1'], 0.3);
$segundos = microtime(true) - $inicio;
comprobar($segundos < 0.6, sprintf('Con un servidor que no contesta, respeta el límite de tiempo (%.2f s, límite 0.3 s)', $segundos));

// =====================================================================
seccion('4 · Ping en paralelo (escaneo.php, comandos reales)');

$sinRespuesta = array_map(fn(int $i): string => "192.0.2.$i", range(1, 64));
$inicio = microtime(true);
$resultados = hacerPingsEnParalelo($sinRespuesta);
$segundos = microtime(true) - $inicio;
comprobar($segundos < LIMITE_LOTE_SEGUNDOS, sprintf('64 direcciones que no responden tardan %.2f s (en paralelo; una tras otra serían 64 s)', $segundos));
comprobarIgual([64, 0], [count($resultados), count(array_filter($resultados, fn($r) => $r['responde']))], '  un resultado por dirección y ninguna responde');
comprobarIgual(['responde' => false, 'tiempo_respuesta_ms' => null], $resultados['192.0.2.7'], '  sin respuesta: tiempo null');

$locales = array_map(fn(int $i): string => "127.0.0.$i", range(1, 10));
$resultados = hacerPingsEnParalelo($locales);
comprobarIgual(10, count(array_filter($resultados, fn($r) => $r['responde'] && is_float($r['tiempo_respuesta_ms']) && $r['tiempo_respuesta_ms'] >= 0)), '127.0.0.1 a .10 responden, con tiempo numérico');

$resultados = hacerPingsEnParalelo(['127.0.0.1', '127.0.0.2', '127.0.0.3'], 1);
comprobarIgual(3, count(array_filter($resultados, fn($r) => $r['responde'])), 'Con 1 ping a la vez también termina y responden los 3');

$inicio = microtime(true);
$resultados = hacerPingsEnParalelo(['192.0.2.1'], 64, 0.2);
$segundos = microtime(true) - $inicio;
comprobar($segundos < 0.6 && $resultados['192.0.2.1']['responde'] === false, sprintf('Un ping que pasa del límite se termina y cuenta como sin respuesta (%.2f s, límite 0.2 s)', $segundos));

foreach (['127.0.0.1; touch /tmp/inyectado', '192.168.1.300', ''] as $entrada) {
    try {
        hacerPingsEnParalelo(['127.0.0.1', $entrada]);
        comprobar(false, 'Una IP no válida (' . exportar($entrada) . ') lanza InvalidArgumentException');
    } catch (InvalidArgumentException) {
        comprobar(true, 'Una IP no válida (' . exportar($entrada) . ') lanza InvalidArgumentException antes de ejecutar nada');
    }
}
comprobar(!file_exists('/tmp/inyectado'), '  no se ejecutó ningún comando inyectado');

// =====================================================================
seccion('5 · Acceso, rutas y métodos');

// Con la red simulada: las rutas que "pasan" con la clave no envían nada a la red local.
simularRed(['ip_propia' => '192.0.2.1']);
comprobarAccesoDeRutas([
    ['GET', '/descubrimiento/red', null],
    ['POST', '/descubrimiento/red/lotes/4', null],
], []);

comprobarRespuesta(peticion('POST', '/descubrimiento/red/lotes/5'), 404, 'POST /descubrimiento/red/lotes/5 (no existe)', 'El lote no existe');
comprobarRespuesta(peticion('POST', '/descubrimiento/red/lotes/0'), 404, 'POST /descubrimiento/red/lotes/0 (no es un entero positivo)', 'La ruta no existe');
comprobarRespuesta(peticion('POST', '/descubrimiento/red/lotes/abc'), 404, 'POST /descubrimiento/red/lotes/abc', 'La ruta no existe');
comprobarRespuesta(peticion('GET', '/descubrimiento'), 404, 'GET /descubrimiento (sin ruta)', 'La ruta no existe');
$r = peticion('GET', '/descubrimiento/red/lotes/1');
comprobarRespuesta($r, 405, 'GET /descubrimiento/red/lotes/1', 'Método no permitido');
comprobarIgual('POST', $r['cabeceras']['allow'] ?? null, '  con la cabecera Allow: POST');
$r = peticion('POST', '/descubrimiento/red');
comprobarRespuesta($r, 405, 'POST /descubrimiento/red', 'Método no permitido');
comprobarIgual('GET', $r['cabeceras']['allow'] ?? null, '  con la cabecera Allow: GET');
comprobarRespuesta(peticion('GET', '/descubrimiento/escaneo.php'), 404, 'GET /descubrimiento/escaneo.php (solo se ejecuta index.php)', 'La ruta no existe');

// =====================================================================
seccion('6 · 503: sin red local o con una IP que no es privada (sin enviar nada)');

simularRed(['ip_propia' => null]);
comprobarRespuesta(peticion('GET', '/descubrimiento/red'), 503, 'Sin red: GET /descubrimiento/red', MENSAJE_SIN_RED);
comprobarRespuesta(peticion('POST', '/descubrimiento/red/lotes/1'), 503, 'Sin red: POST /descubrimiento/red/lotes/1', MENSAJE_SIN_RED);

simularRed(['ip_propia' => '198.51.100.1']);
comprobarRespuesta(peticion('GET', '/descubrimiento/red'), 503, 'IP propia pública (198.51.100.1): GET /descubrimiento/red', MENSAJE_RED_NO_PRIVADA);
[$r, $segundos] = escanear(1);
comprobarRespuesta($r, 503, 'IP propia pública: POST /descubrimiento/red/lotes/1', MENSAJE_RED_NO_PRIVADA);
comprobar($segundos < 0.5, sprintf('  responde al instante (%.2f s): no se hizo ningún ping', $segundos));

// Un archivo que pide otra red, que ya caducó o que caduca demasiado tarde se ignora:
// el servicio usa la red real (GET no envía nada a la red).
foreach ([
    'otra red (10.0.0.1)' => ['ip_propia' => '10.0.0.1', 'expira' => time() + 30],
    'ya caducó' => ['ip_propia' => null, 'expira' => time() - 1],
    'caduca en más de 60 s' => ['ip_propia' => null, 'expira' => time() + 120],
    'sin el campo ip_propia' => ['expira' => time() + 30],
] as $caso => $contenido) {
    file_put_contents(ARCHIVO_RED_SIMULADA, json_encode($contenido));
    $r = peticion('GET', '/descubrimiento/red');
    $ignorado = ($r['codigo'] === 200 && ($r['json']['data']['ip_propia'] ?? null) !== '10.0.0.1')
        || ($r['codigo'] === 503 && ($r['json']['error'] ?? null) === MENSAJE_SIN_RED && $caso === 'otra red (10.0.0.1)');
    comprobar($ignorado, "Red simulada con $caso: se ignora", "código {$r['codigo']}: {$r['texto']}");
}

// =====================================================================
seccion('7 · Red simulada 192.0.2.0/24: nada responde al ping, la tabla ARP encuentra equipos');

simularRed([
    'ip_propia' => '192.0.2.1',
    'puerta_enlace' => '192.0.2.254',
    'arp' => tablaArpDeEjemplo([
        ['192.0.2.20', '00:11:22:33:44:55', 'STALE'],      // Impresora Laboratorio (registrada)
        ['192.0.2.9', '', 'FAILED'],                       // no contestó
        ['192.0.2.77', 'aa:bb:cc:dd:ee:01', 'REACHABLE'],  // equipo nuevo que bloquea el ping
        ['192.0.2.254', 'aa:bb:cc:dd:ee:fe', 'REACHABLE'], // puerta de enlace
        ['198.51.100.7', 'aa:bb:cc:dd:ee:02', 'REACHABLE'], // de otra red: se ignora
    ]),
]);

$r = peticion('GET', '/descubrimiento/red');
comprobarRespuesta($r, 200, 'GET /descubrimiento/red');
comprobarIgual(
    ['red' => '192.0.2.0/24', 'ip_propia' => '192.0.2.1', 'puerta_enlace' => '192.0.2.254', 'lotes' => 4, 'total_direcciones' => 254],
    $r['json']['data'] ?? null,
    '  la /24 de la IP propia, calculada en el servidor'
);

[$r, $segundos] = escanear(1);
comprobarRespuesta($r, 200, 'POST /descubrimiento/red/lotes/1');
comprobar($segundos < LIMITE_LOTE_SEGUNDOS, sprintf('  64 direcciones que no responden, a través de Apache: %.2f s (límite %.0f s)', $segundos, LIMITE_LOTE_SEGUNDOS));
$data = $r['json']['data'] ?? [];
comprobarIgual(CLAVES_LOTE, array_keys($data), '  claves del lote');
comprobarIgual([1, '192.0.2.1', '192.0.2.64'], [$data['lote'] ?? null, $data['desde'] ?? null, $data['hasta'] ?? null], '  lote 1: 192.0.2.1 – 192.0.2.64');
comprobarIgual(['192.0.2.20'], ipsDe($data['encontrados'] ?? []), '  solo la IP de la tabla ARP (la FAILED no, la de otra red no)');
comprobarFormaEquipos($data['encontrados'] ?? [], '  cada equipo tiene las claves y tipos del contrato');
comprobarIgual(
    ['192.0.2.20', '00:11:22:33:44:55', false, null, true, false, false, ['id' => 5, 'nombre' => 'Impresora Laboratorio']],
    campos($data['encontrados'][0] ?? null, ['ip', 'mac', 'responde_ping', 'tiempo_respuesta_ms', 'en_arp', 'es_esta_computadora', 'es_puerta_enlace', 'dispositivo']),
    '  192.0.2.20: solo en ARP, con su MAC, registrada como "Impresora Laboratorio"'
);

[$r] = escanear(2);
$equipo = $r['json']['data']['encontrados'][0] ?? null;
comprobarIgual(['192.0.2.77'], ipsDe($r['json']['data']['encontrados'] ?? []), 'Lote 2: el equipo que bloquea el ping aparece por ARP');
comprobarIgual(['aa:bb:cc:dd:ee:01', false, true, null], campos($equipo, ['mac', 'responde_ping', 'en_arp', 'dispositivo']), '  con su MAC y sin registrar (dispositivo null)');

[$r] = escanear(3);
comprobarIgual([200, []], [$r['codigo'], $r['json']['data']['encontrados'] ?? null], 'Lote 3: nada en ese rango → encontrados []');

[$r] = escanear(4);
$equipo = $r['json']['data']['encontrados'][0] ?? null;
comprobarIgual(['192.0.2.193', '192.0.2.254'], [$r['json']['data']['desde'] ?? null, $r['json']['data']['hasta'] ?? null], 'Lote 4: 192.0.2.193 – 192.0.2.254');
comprobarIgual(['192.0.2.254', true, false], campos($equipo, ['ip', 'es_puerta_enlace', 'es_esta_computadora']), '  la puerta de enlace se marca con es_puerta_enlace');

$r = peticion('POST', '/dispositivos', ['nombre' => 'Equipo nuevo', 'ip' => '192.0.2.77', 'tipo' => 'computadora', 'ubicacion' => 'Laboratorio']);
comprobarRespuesta($r, 201, 'Registrar 192.0.2.77 con POST /dispositivos (lo que hace «Agregar»)');
$idNuevo = $r['json']['data']['id'] ?? null;
[$r] = escanear(2);
comprobarIgual(['id' => $idNuevo, 'nombre' => 'Equipo nuevo'], $r['json']['data']['encontrados'][0]['dispositivo'] ?? null, '  el siguiente escaneo ya lo marca como registrado (se lo pregunta a Dispositivos)');

// =====================================================================
seccion('8 · Red simulada 127.0.0.0/24: todo responde al ping');

simularRed(['ip_propia' => '127.0.0.1']);
[$r, $segundos] = escanear(1);
comprobarRespuesta($r, 200, 'POST /descubrimiento/red/lotes/1');
comprobar($segundos < LIMITE_LOTE_SEGUNDOS, sprintf('  %.2f s', $segundos));
$encontrados = $r['json']['data']['encontrados'] ?? [];
$esperadas = array_map(fn(int $i): string => "127.0.0.$i", range(1, 64));
comprobarIgual($esperadas, ipsDe($encontrados), '  las 64 direcciones, ordenadas por IP (127.0.0.2 antes que 127.0.0.10)');
comprobarFormaEquipos($encontrados, '  cada equipo tiene las claves y tipos del contrato');
comprobarIgual(64, count(array_filter($encontrados, fn($e) => $e['responde_ping'] === true && $e['en_arp'] === false && $e['mac'] === null)), '  todas responden al ping; ninguna está en la tabla ARP');
comprobarIgual(
    [true, ['id' => 1, 'nombre' => 'Mi PC']],
    campos($encontrados[0] ?? null, ['es_esta_computadora', 'dispositivo']),
    '  127.0.0.1 es esta computadora y está registrada como "Mi PC"'
);
comprobarIgual(1, count(array_filter($encontrados, fn($e) => $e['es_esta_computadora'])), '  solo una es esta computadora');

if (is_readable(ARCHIVO_RESOLV_CONF) && preg_match('/^\s*nameserver\s/m', (string) file_get_contents(ARCHIVO_RESOLV_CONF)) === 1) {
    comprobarIgual('localhost', $encontrados[0]['nombre'] ?? null, '  nombre de 127.0.0.1 por DNS inverso: "localhost"');
} else {
    avisar('Sin /etc/resolv.conf: no se comprobó el nombre de 127.0.0.1 por DNS inverso.');
}

// =====================================================================
seccion('9 · Dispositivos no responde → 502, sin enviar nada a la red');

simularRed(['ip_propia' => '192.0.2.1']);
simularFallo('Dispositivos', 'sin_respuesta', 'GET', puertoCerrado());
[$r, $segundos] = escanear(1);
comprobarRespuesta($r, 502, 'Dispositivos no responde', 'No se pudo comunicar con el Servicio de Dispositivos');
comprobar($segundos < 0.9, sprintf('  responde en %.2f s: no llegó a hacer los pings (tardan 1 s)', $segundos));

simularFallo('Dispositivos', 'respuesta_invalida', 'GET');
[$r] = escanear(1);
comprobarRespuesta($r, 502, 'Dispositivos responde algo que no es JSON', 'No se pudo comunicar con el Servicio de Dispositivos');
quitarFalloSimulado();

// =====================================================================
seccion('10 · Regla SOA y archivos protegidos');

$codigoServicio = '';
foreach (glob(__DIR__ . '/../servicios/descubrimiento/*.php') as $archivo) {
    $codigoServicio .= file_get_contents($archivo);
}
comprobar(preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b\s|obtenerConexion|comun\/db\.php/i', $codigoServicio) !== 1, 'servicios/descubrimiento/ no tiene SQL ni usa la conexión a la base');
comprobar(preg_match('#require[^;]*/(dispositivos|alertas|monitoreo|auth)/#', $codigoServicio) !== 1, 'servicios/descubrimiento/ no hace require del código de otro servicio');

$raiz = escapeshellarg(dirname(__DIR__));
exec("git -C $raiz rev-parse --verify --quiet main 2>&1", $salida, $codigoGit);
if ($codigoGit === 0) {
    exec("git -C $raiz diff --name-only main -- servicios/dispositivos servicios/alertas servicios/auth 2>&1", $cambiados);
    comprobarIgual([], $cambiados, 'Dispositivos, Alertas y Auth no cambiaron respecto a main (git diff)');
    // ping.php se movió a comun/ en la Fase 9: se compara aparte.
    exec("git -C $raiz diff -U0 main -- servicios/monitoreo ':!servicios/monitoreo/ping.php' 2>&1", $diferencia);
    $lineasCambiadas = array_values(array_filter($diferencia, fn($l) => preg_match('/^[+-](?![+-])/', $l) === 1));
    comprobar(
        array_filter($lineasCambiadas, fn($l) => !str_contains($l, "ping.php';")) === [] && count($lineasCambiadas) <= 2,
        'En Monitoreo solo cambió la línea del require de ping.php',
        implode(' | ', $lineasCambiadas)
    );
    exec("git -C $raiz show main:servicios/monitoreo/ping.php 2>&1", $pingAnterior, $codigoPing);
    if ($codigoPing === 0) {
        $pingActual = file(__DIR__ . '/../servicios/comun/ping.php', FILE_IGNORE_NEW_LINES);
        $agregadas = array_diff($pingActual, $pingAnterior);
        comprobar(
            array_diff($pingAnterior, $pingActual) === [] && array_filter($agregadas, fn($l) => preg_match('/^ \*( |$)/', $l) !== 1) === [],
            'comun/ping.php es el ping.php de Monitoreo sin cambios de código (solo se agregó comentario)',
            implode(' | ', $agregadas)
        );
    }
} else {
    avisar('No hay git o no existe la rama main: no se comparó el código de los otros servicios.');
}

simularRed(['ip_propia' => '192.0.2.1']);
$contexto = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
@file_get_contents(dirname(URL_SERVICIOS) . '/pruebas/.red-simulada.json', false, $contexto);
comprobarIgual(404, leerCabeceras($http_response_header ?? [])['codigo'], 'pruebas/.red-simulada.json no es accesible por HTTP (404)');
@file_get_contents(URL_SERVICIOS . '/comun/ping.php', false, $contexto);
comprobarIgual(403, leerCabeceras($http_response_header ?? [])['codigo'], 'servicios/comun/ping.php no es accesible por HTTP (403)');

quitarRedSimulada();
terminarPruebas();
