<?php
/**
 * Pruebas del Servicio de Descubrimiento (docs/PLAN.md, Fase 9 · "Listo cuando").
 * Recarga la base de datos al empezar: borra los datos agregados a mano.
 *
 *   /opt/lampp/bin/php pruebas/descubrimiento.php
 *
 * No depende de la red de quien la corre ni envía nada a la red local:
 * - Las funciones de lectura (tabla ARP de cada sistema, DNS, NetBIOS, mDNS y
 *   UPnP) se prueban con salidas y paquetes de ejemplo fijos.
 * - Los nombres por NetBIOS, mDNS y UPnP, de punta a punta, los contesta un
 *   respondedor falso (pruebas/respondedor-nombres.php) en 127.0.0.x, con los
 *   puertos que indica la red simulada ("puertos_nombres").
 * - A través de Apache se usa la red simulada (pruebas/.red-simulada.json, ver
 *   servicios/descubrimiento/simulacion.php): 192.0.2.0/24, donde nada responde,
 *   con una tabla ARP de ejemplo, y 127.0.0.0/24, donde todo responde. Los pings
 *   son reales. El archivo caduca solo y se borra al terminar.
 */

declare(strict_types=1);

require_once __DIR__ . '/ayudas.php';
require_once __DIR__ . '/../servicios/descubrimiento/red.php';
require_once __DIR__ . '/../servicios/descubrimiento/escaneo.php';
require_once __DIR__ . '/../servicios/descubrimiento/nombres.php';

const CLAVES_RED = ['red', 'ip_propia', 'puerta_enlace', 'lotes', 'total_direcciones'];
const CLAVES_LOTE = ['lote', 'desde', 'hasta', 'encontrados'];
const CLAVES_EQUIPO = ['ip', 'mac', 'nombre', 'nombre_origen', 'responde_ping', 'tiempo_respuesta_ms', 'en_arp', 'es_esta_computadora', 'es_puerta_enlace', 'dispositivo'];
const MENSAJE_SIN_RED = 'No se detectó una red local. Conecta la computadora a una red WiFi o cableada.';
const MENSAJE_RED_NO_PRIVADA = 'Solo se pueden escanear redes privadas (10.x, 172.16–31.x o 192.168.x).';
const LIMITE_LOTE_SEGUNDOS = 3.0;

// ---------------------------------------------------------------- Ayudas

/**
 * POST a un lote. Devuelve [respuesta, segundos].
 */
function escanear(int|string $lote): array
{
    $inicio = microtime(true);
    $respuesta = peticion('POST', "/descubrimiento/red/lotes/$lote");

    return [$respuesta, microtime(true) - $inicio];
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
        $nombreValido = ($e['nombre'] === null && $e['nombre_origen'] === null)
            || (is_string($e['nombre']) && in_array($e['nombre_origen'], METODOS_POR_PREFERENCIA, true));
        if (!is_string($e['ip']) || !($e['mac'] === null || is_string($e['mac'])) || !$nombreValido
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
comprobarIgual(null, leerRespuestaPtr(respuestaPtr($consulta, "\x08_gateway\x00"), 0x1234), '"_gateway" (lo inventa systemd-resolved, no el equipo) se descarta');
comprobarIgual('mi_equipo.lan', leerRespuestaPtr(respuestaPtr($consulta, "\x09mi_equipo\x03lan\x00"), 0x1234), 'Un guion bajo dentro del nombre sí vale');
comprobarIgual([], buscarNombresDeEquipos(['192.0.2.5'], [], '192.0.2', []), 'Sin servidores DNS ni otros métodos no se consulta nada');

$inicio = microtime(true);
buscarNombresDeEquipos(['192.0.2.5', '192.0.2.6'], ['127.0.0.1'], '192.0.2', [], 0.3);
$segundos = microtime(true) - $inicio;
comprobar($segundos < 0.6, sprintf('Con un servidor que no contesta, respeta el límite de tiempo (%.2f s, límite 0.3 s)', $segundos));

// =====================================================================
seccion('3.1 · Nombres por NetBIOS (netbios.php)');

comprobarIgual(
    '123400000001000000000000' . '20' . '434b' . str_repeat('4141', 15) . '00' . '0021' . '0001',
    bin2hex(construirConsultaNetbios(0x1234)),
    'Consulta de estado del nodo: el nombre "*" codificado (CKAAAA…), tipo NBSTAT, clase IN'
);

$windows = respuestaNetbiosDeEjemplo([
    ['GRUPO-TRABAJO', 0x00, true],
    ['DESKTOP-ANA', 0x00, false],
    ['DESKTOP-ANA', 0x20, false],
]);
$conId = fn(string $paquete, int $id = 0x1234): string => pack('n', $id) . substr($paquete, 2);
comprobarIgual('DESKTOP-ANA', leerRespuestaNetbios($conId($windows), 0x1234), 'Respuesta de Windows → "DESKTOP-ANA" (el único <00>, no el de grupo)');
comprobarIgual('SERVIDOR', leerRespuestaNetbios($conId(respuestaNetbiosDeEjemplo([['GRUPO', 0x00, true], ['SERVIDOR', 0x20, false]])), 0x1234), 'Sin un <00> único, el <20> único');
comprobarIgual(null, leerRespuestaNetbios($conId(respuestaNetbiosDeEjemplo([['GRUPO', 0x00, true]])), 0x1234), 'Solo nombres de grupo → null');
comprobarIgual(null, leerRespuestaNetbios($conId($windows), 0x9999), 'Otro id de consulta → null');
comprobarIgual(null, leerRespuestaNetbios($conId(respuestaNetbiosDeEjemplo([['PC', 0x00, false]], 0x0000)), 0x1234), 'Un paquete que no es respuesta → null');
comprobarIgual(null, leerRespuestaNetbios($conId(respuestaNetbiosDeEjemplo([['PC', 0x00, false]], 0x8403)), 0x1234), 'Una respuesta con error → null');
comprobarIgual(null, leerRespuestaNetbios(substr($conId($windows), 0, 70), 0x1234), 'Un paquete cortado → null');
$cantidadFalsa = $conId($windows);
$cantidadFalsa[56] = chr(200); // dice traer 200 nombres
comprobarIgual(null, leerRespuestaNetbios($cantidadFalsa, 0x1234), 'Una cantidad de nombres mayor que el paquete → null, sin leer de más');
comprobarIgual('<b>hola</b>', leerRespuestaNetbios($conId(respuestaNetbiosDeEjemplo([['<b>hola</b>', 0x00, false]])), 0x1234), 'Nombre malicioso con HTML: llega como texto (la interfaz lo muestra con textContent)');
comprobarIgual(null, leerRespuestaNetbios($conId(respuestaNetbiosDeEjemplo([["PC\x01\x1b[31m", 0x00, false]])), 0x1234), 'Un nombre con bytes de control se descarta');
comprobarIgual(null, leerRespuestaNetbios($conId(respuestaNetbiosDeEjemplo([["CAF\xC9", 0x00, false]])), 0x1234), 'Un nombre que no es ASCII (otra página de códigos) se descarta');

// =====================================================================
seccion('3.2 · Nombres por mDNS (mdns.php)');

$consultaMdns = construirConsultaMdns(0x4321, '192.168.1.20');
comprobarIgual(
    bin2hex(pack('nnnnnn', 0x4321, 0x0000, 1, 0, 0, 0) . "\x0220\x011\x03168\x03192\x07in-addr\x04arpa\x00" . pack('nn', 12, 1)),
    bin2hex($consultaMdns),
    'Consulta PTR inversa de mDNS: como la de DNS, sin "recursión deseada"'
);
$mdns = fn(string $nombre, int $id = 0x4321): string => pack('n', $id) . substr(respuestaMdnsDeEjemplo('192.168.1.20', $nombre), 2);
comprobarIgual('MacBook-de-Ana.local', leerRespuestaMdns($mdns('MacBook-de-Ana.local'), 0x4321), 'Respuesta de mDNS → "MacBook-de-Ana.local"');
comprobarIgual(null, leerRespuestaMdns($mdns('MacBook-de-Ana.local'), 0x1111), 'Otro id de consulta → null');
comprobarIgual(null, leerRespuestaMdns(substr($mdns('MacBook-de-Ana.local'), 0, -5), 0x4321), 'Un paquete cortado → null');
comprobarIgual(null, leerRespuestaMdns($mdns('<img src=x onerror=alert(1)>.local'), 0x4321), 'Nombre malicioso con HTML → se descarta (mismas reglas que el DNS)');

// =====================================================================
seccion('3.3 · Nombres por UPnP (upnp.php)');

$msearch = construirMSearch('192.168.1.20');
comprobar(
    str_starts_with($msearch, "M-SEARCH * HTTP/1.1\r\nHOST: 192.168.1.20:1900\r\n") && str_contains($msearch, "MAN: \"ssdp:discover\"\r\n") && str_ends_with($msearch, "\r\n\r\n"),
    'M-SEARCH unicast dirigido a la IP del equipo (HOST: IP:1900)'
);
comprobarIgual('http://192.168.1.20:49152/desc.xml', leerLocationSsdp(respuestaSsdpDeEjemplo('http://192.168.1.20:49152/desc.xml')), 'LOCATION de la respuesta de SSDP');
comprobarIgual('http://192.168.1.20/d.xml', leerLocationSsdp("HTTP/1.1 200 OK\r\nlocation:http://192.168.1.20/d.xml\r\n\r\n"), '  sin distinguir mayúsculas ni exigir espacio');
comprobarIgual(null, leerLocationSsdp("NOTIFY * HTTP/1.1\r\nLOCATION: http://192.168.1.20/d.xml\r\n\r\n"), '  una respuesta que no es "200 OK" → null');

foreach ([
    ['http://192.168.1.20:49152/desc.xml', ['puerto' => 49152, 'ruta' => '/desc.xml'], 'misma IP, con puerto'],
    ['http://192.168.1.20/rootDesc.xml?x=1', ['puerto' => 80, 'ruta' => '/rootDesc.xml?x=1'], 'sin puerto: 80'],
    ['http://192.168.1.20:8080', ['puerto' => 8080, 'ruta' => '/'], 'sin ruta: /'],
    ['https://192.168.1.20/desc.xml', null, 'https'],
    ['http://192.168.1.21/desc.xml', null, 'otra IP de la red'],
    ['http://8.8.8.8/desc.xml', null, 'IP fuera de la red'],
    ['http://impresora.lan/desc.xml', null, 'nombre de dominio'],
    ['http://192.168.1.20@8.8.8.8/desc.xml', null, 'usuario@host (el host real es otro)'],
    ['http://192.168.1.20:80@8.8.8.8/', null, 'usuario:contraseña@host'],
    ['http://192.168.1.20:99999/desc.xml', null, 'puerto fuera de rango'],
    ['http://192.168.1.20:0/desc.xml', null, 'puerto 0'],
    ["http://192.168.1.20/desc.xml\r\nHost: otro", null, 'saltos de línea (inyección de cabeceras)'],
    ['http://192.168.1.20/a b', null, 'espacios en la ruta'],
    ['file:///etc/passwd', null, 'file://'],
] as [$url, $esperado, $caso]) {
    comprobarIgual($esperado, validarUrlDescripcion($url, '192.168.1.20', '192.168.1'), "URL de la descripción: $caso → " . ($esperado === null ? 'se rechaza' : 'se acepta'));
}
comprobarIgual(null, validarUrlDescripcion('http://192.168.1.20/d.xml', '192.168.1.20', '10.0.0'), '  aunque sea la IP que contestó, si no está en la /24 escaneada se rechaza');
comprobarIgual("GET /desc.xml HTTP/1.0\r\nHost: 192.168.1.20:49152\r\nAccept: text/xml\r\nConnection: close\r\n\r\n", construirPeticionDescripcion('192.168.1.20', 49152, '/desc.xml'), 'Petición HTTP/1.0 de la descripción');

comprobarIgual('[TV] Samsung 7 Series (55)', leerRespuestaDescripcion(respuestaHttpDeEjemplo(descripcionUpnpDeEjemplo('[TV] Samsung 7 Series (55)'))), 'friendlyName de la descripción');
comprobarIgual('Televisión de la sala', leerRespuestaDescripcion(respuestaHttpDeEjemplo(descripcionUpnpDeEjemplo('Televisión de la sala'))), '  con acentos (UTF-8)');
$sinEspacioDeNombres = "<?xml version=\"1.0\"?><root><device><friendlyName>Consola</friendlyName></device></root>";
comprobarIgual('Consola', leerFriendlyName($sinEspacioDeNombres), '  sin el espacio de nombres de UPnP también');
$conServicio = "<root xmlns=\"urn:schemas-upnp-org:device-1-0\"><device><friendlyName>Módem</friendlyName><deviceList><device><friendlyName>WANDevice</friendlyName></device></deviceList></device></root>";
comprobarIgual('Módem', leerFriendlyName($conServicio), '  el del dispositivo raíz, no el de un subdispositivo');
$chunked = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" . implode('', array_map(
    fn(string $parte): string => dechex(strlen($parte)) . "\r\n$parte\r\n",
    str_split(descripcionUpnpDeEjemplo('Impresora HP'), 40)
)) . "0\r\n\r\n";
comprobarIgual('Impresora HP', leerRespuestaDescripcion($chunked), '  respuesta "chunked" (aunque se pidió HTTP/1.0)');
comprobarIgual(null, leerRespuestaDescripcion(respuestaHttpDeEjemplo(descripcionUpnpDeEjemplo('X'), '404 Not Found')), 'Una respuesta 404 → null');
comprobarIgual(null, leerRespuestaDescripcion(respuestaHttpDeEjemplo(descripcionUpnpDeEjemplo('X') . str_repeat(' ', TAMANO_MAXIMO_DESCRIPCION))), 'Una descripción de más de 64 KB → null');
comprobarIgual(null, leerFriendlyName('<root><device><friendlyName>sin cerrar'), 'Un XML mal formado → null');
comprobarIgual(null, leerFriendlyName('<root><device><manufacturer>X</manufacturer></device></root>'), 'Sin friendlyName → null');
$xxe = "<?xml version=\"1.0\"?>\n<!DOCTYPE root [<!ENTITY secreto SYSTEM \"file:///etc/passwd\">]>\n<root><device><friendlyName>&secreto;</friendlyName></device></root>";
comprobarIgual(null, leerFriendlyName($xxe), 'XML con una entidad externa (XXE, file:///etc/passwd) → se rechaza sin procesarlo');
$risas = "<?xml version=\"1.0\"?><!DOCTYPE r [<!ENTITY a \"jaja\"><!ENTITY b \"&a;&a;&a;&a;&a;&a;&a;&a;\">]><root><device><friendlyName>&b;</friendlyName></device></root>";
comprobarIgual(null, leerFriendlyName($risas), 'XML con entidades anidadas ("billion laughs") → se rechaza');
comprobarIgual(null, leerFriendlyName("<!doctype root><root><device><friendlyName>X</friendlyName></device></root>"), '  DOCTYPE en minúsculas también');
comprobarIgual('<img src=x onerror=alert(1)>', leerFriendlyName(descripcionUpnpDeEjemplo('<img src=x onerror=alert(1)>')), 'Nombre malicioso con HTML: llega como texto (la interfaz lo muestra con textContent)');
comprobarIgual('Sala TV', leerFriendlyName(descripcionUpnpDeEjemplo("  Sala\u{202E}\t\n  T\u{200B}V ")), 'Se quitan los caracteres de formato (como el que invierte el texto) y se compactan los espacios');
comprobarIgual('Sala TV', limpiarNombreLegible("Sala \x07\x1b TV\x00"), 'Se quitan los caracteres de control');
comprobarIgual(100, mb_strlen((string) leerFriendlyName(descripcionUpnpDeEjemplo(str_repeat('ñ', 300)))), 'Un nombre larguísimo se recorta a 100 caracteres');
comprobarIgual(null, limpiarNombreLegible("TV \xC3\x28"), 'Un nombre que no es UTF-8 válido → null');

// =====================================================================
seccion('3.4 · Preferencia y búsqueda en paralelo (nombres.php)');

$todos = ['dns' => 'pc.lan', 'netbios' => 'PC-WIN', 'mdns' => 'pc.local', 'upnp' => 'Impresora HP'];
comprobarIgual(['nombre' => 'Impresora HP', 'origen' => 'upnp'], elegirNombre($todos), 'Orden de preferencia: UPnP primero');
unset($todos['upnp']);
comprobarIgual(['nombre' => 'pc.local', 'origen' => 'mdns'], elegirNombre($todos), '  sin UPnP: mDNS');
unset($todos['mdns']);
comprobarIgual(['nombre' => 'PC-WIN', 'origen' => 'netbios'], elegirNombre($todos), '  sin mDNS: NetBIOS');
unset($todos['netbios']);
comprobarIgual(['nombre' => 'pc.lan', 'origen' => 'dns'], elegirNombre($todos), '  y al final, DNS');
comprobarIgual(['nombre' => null, 'origen' => null], elegirNombre([]), '  sin nombres: null y null');

// Con el respondedor falso, sin Apache. Un método sin respondedor nunca contesta.
$puertosFalsos = ['netbios' => puertoLibreLocal(), 'mdns' => puertoLibreLocal(), 'ssdp' => puertoLibreLocal()];
$puertoHttp = puertoLibreLocal();
$respondedor = iniciarRespondedorDeNombres([
    '127.0.0.2' => ['netbios' => respuestaNetbiosDeEjemplo([['PC-SOLO-NETBIOS', 0x00, false]])],
    '127.0.0.3' => [
        'ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.3:$puertoHttp/desc.xml"),
        'http' => ['puerto' => $puertoHttp, 'respuesta' => respuestaHttpDeEjemplo(descripcionUpnpDeEjemplo('Impresora rápida'))],
    ],
], $puertosFalsos, 20);

if ($respondedor !== null) {
    $inicio = microtime(true);
    $nombres = buscarNombresDeEquipos(['127.0.0.2', '127.0.0.3'], [], '127.0.0', $puertosFalsos, 0.8);
    $segundos = microtime(true) - $inicio;
    comprobarIgual(
        ['127.0.0.2' => ['netbios' => 'PC-SOLO-NETBIOS'], '127.0.0.3' => ['upnp' => 'Impresora rápida']],
        $nombres,
        'Cada equipo da su nombre por su método; los métodos que no contestan no aportan nada'
    );
    comprobar($segundos < 1.0, sprintf('  y no retrasan a los demás: todo en %.2f s (límite 0.8 s)', $segundos));

    $inicio = microtime(true);
    $nombres = buscarNombresDeEquipos(['127.0.0.3'], [], '127.0.0', $puertosFalsos, 0.8);
    $segundos = microtime(true) - $inicio;
    comprobar(
        ($nombres['127.0.0.3']['upnp'] ?? null) === 'Impresora rápida' && $segundos < 0.4,
        sprintf('Si ya llegó el nombre preferido (UPnP), no espera a los demás métodos: %.2f s', $segundos)
    );
    $peticiones = detenerRespondedorDeNombres($respondedor);
    comprobarIgual(2, count(array_filter($peticiones, fn($p) => $p === '127.0.0.3 GET /desc.xml HTTP/1.0')), '  la descripción se pidió con GET a la IP que contestó (una vez por búsqueda)');

    // Una descripción que nunca termina: la descarga se corta al pasar de 64 KB, no al agotar el tiempo.
    $respondedor = iniciarRespondedorDeNombres([
        '127.0.0.3' => ['ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.3:$puertoHttp/desc.xml"), 'http' => ['puerto' => $puertoHttp, 'sin_fin' => true]],
    ], $puertosFalsos, 20);
    if ($respondedor !== null) {
        $nombres = buscarNombresDeEquipos(['127.0.0.3'], [], '127.0.0', $puertosFalsos, 0.8);
        $intentos = 0;
        do {
            usleep(100000);
            $salida = (string) stream_get_contents($respondedor['salida']);
            $respondedor['pendiente'] = ($respondedor['pendiente'] ?? '') . $salida;
        } while (!str_contains($respondedor['pendiente'], 'enviado ') && ++$intentos < 60);
        detenerRespondedorDeNombres($respondedor);
        preg_match('/^enviado 127\.0\.0\.3 (\d+) (\d+)$/m', $respondedor['pendiente'], $enviado);
        $milisegundos = isset($enviado[2]) ? (int) $enviado[2] : null;
        comprobar(
            ($nombres['127.0.0.3']['upnp'] ?? null) === null && $milisegundos !== null && $milisegundos < 300,
            sprintf(
                'Una descripción sin fin se abandona al pasar de 64 KB, sin esperar al límite de 0.8 s (cerró a los %s ms; el equipo alcanzó a enviar %s KB, lo que cabe en los búferes del sistema)',
                $milisegundos ?? '¿?',
                isset($enviado[1]) ? intdiv((int) $enviado[1], 1024) : '¿?'
            )
        );
    }
} else {
    avisar('No se pudo escuchar en 127.0.0.2/127.0.0.3: no se probó la búsqueda en paralelo.');
}

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
    'puertos de nombres con otra IP propia (192.0.2.1)' => ['ip_propia' => '192.0.2.1', 'puertos_nombres' => ['netbios' => 40137], 'expira' => time() + 30],
    'puerto de nombres privilegiado (137)' => ['ip_propia' => '127.0.0.1', 'puertos_nombres' => ['netbios' => 137], 'expira' => time() + 30],
    'un método de nombres desconocido' => ['ip_propia' => '127.0.0.1', 'puertos_nombres' => ['http' => 40080], 'expira' => time() + 30],
] as $caso => $contenido) {
    file_put_contents(ARCHIVO_RED_SIMULADA, json_encode($contenido));
    $r = peticion('GET', '/descubrimiento/red');
    $ignorado = ($r['codigo'] === 200 && !in_array($r['json']['data']['ip_propia'] ?? null, ['10.0.0.1', '192.0.2.1', '127.0.0.1'], true))
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
    comprobarIgual('dns', $encontrados[0]['nombre_origen'] ?? null, '  con nombre_origen "dns"');
} else {
    avisar('Sin /etc/resolv.conf: no se comprobó el nombre de 127.0.0.1 por DNS inverso.');
}
comprobarIgual(
    [],
    array_values(array_filter($encontrados, fn($e) => !in_array($e['nombre_origen'], [null, 'dns'], true))),
    '  sin "puertos_nombres", la red simulada solo pregunta por DNS (nunca a los puertos reales de UPnP, mDNS ni NetBIOS)'
);

// =====================================================================
seccion('8.1 · Nombres por UPnP, mDNS y NetBIOS de punta a punta (respondedor falso en 127.0.0.x)');

$puertosFalsos = ['netbios' => puertoLibreLocal(), 'mdns' => puertoLibreLocal(), 'ssdp' => puertoLibreLocal()];
$http = puertoLibreLocal();
$descripcion = fn(string $nombre): array => ['puerto' => $http, 'respuesta' => respuestaHttpDeEjemplo(descripcionUpnpDeEjemplo($nombre))];
$respondedor = iniciarRespondedorDeNombres([
    '127.0.0.1' => ['mdns' => respuestaMdnsDeEjemplo('127.0.0.1', 'equipo-mdns.local')],
    '127.0.0.2' => ['netbios' => respuestaNetbiosDeEjemplo([['GRUPO', 0x00, true], ['PC-NETBIOS', 0x00, false]])],
    '127.0.0.3' => ['ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.3:$http/desc.xml"), 'http' => $descripcion('Televisión de la sala')],
    '127.0.0.4' => [
        'netbios' => respuestaNetbiosDeEjemplo([['PC-WIN', 0x00, false]]),
        'mdns' => respuestaMdnsDeEjemplo('127.0.0.4', 'hp1a2b3c.local'),
        'ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.4:$http/d.xml"),
        'http' => $descripcion('Impresora HP'),
    ],
    '127.0.0.5' => [
        'mdns' => respuestaMdnsDeEjemplo('127.0.0.5', '<img src=x onerror=alert(1)>.local'),
        'netbios' => respuestaNetbiosDeEjemplo([['<b>hola</b>', 0x00, false]]),
    ],
    '127.0.0.6' => ['ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.7:$http/desc.xml")], // apunta a otra IP
    '127.0.0.7' => ['http' => $descripcion('No debería descargarse')],
    '127.0.0.8' => ['ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.8:$http/desc.xml"), 'http' => $descripcion('<img src=x onerror=alert(1)>')],
    '127.0.0.9' => [
        'netbios' => respuestaNetbiosDeEjemplo([['PC-LENTA', 0x00, false]]),
        'ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.9:$http/desc.xml"),
        'http' => ['puerto' => $http, 'colgar' => true], // acepta y nunca contesta
    ],
    '127.0.0.10' => [
        'ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.10:$http/desc.xml"),
        'http' => ['puerto' => $http, 'respuesta' => respuestaHttpDeEjemplo("<?xml version=\"1.0\"?>\n<!DOCTYPE root [<!ENTITY x SYSTEM \"file:///etc/passwd\">]>\n<root><device><friendlyName>&x;</friendlyName></device></root>")],
    ],
    '127.0.0.11' => [ // contesta desde otra IP (respuesta falsificada)
        'netbios' => respuestaNetbiosDeEjemplo([['PC-FALSA', 0x00, false]]),
        'mdns' => respuestaMdnsDeEjemplo('127.0.0.11', 'falsa.local'),
        'desde' => '127.0.0.12',
    ],
    '127.0.0.13' => [
        'ssdp' => respuestaSsdpDeEjemplo("http://127.0.0.13:$http/desc.xml"),
        'http' => ['puerto' => $http, 'respuesta' => respuestaHttpDeEjemplo(descripcionUpnpDeEjemplo('Enorme') . str_repeat('<!-- relleno -->', 5000))],
    ],
], $puertosFalsos);

if ($respondedor !== null) {
    simularRed(['ip_propia' => '127.0.0.1', 'puertos_nombres' => $puertosFalsos]);
    [$r, $segundos] = escanear(1);
    comprobarRespuesta($r, 200, 'POST /descubrimiento/red/lotes/1 con los respondedores falsos');
    comprobar($segundos < LIMITE_LOTE_SEGUNDOS, sprintf('  %.2f s (límite %.0f s), aunque una descripción nunca termina de llegar', $segundos, LIMITE_LOTE_SEGUNDOS));
    $encontrados = array_column($r['json']['data']['encontrados'] ?? [], null, 'ip');
    comprobarFormaEquipos(array_values($encontrados), '  cada equipo tiene las claves y tipos del contrato (con nombre_origen)');
    $nombreDe = fn(string $ip): array => campos($encontrados[$ip] ?? null, ['nombre', 'nombre_origen']);

    comprobarIgual(['equipo-mdns.local', 'mdns'], $nombreDe('127.0.0.1'), '127.0.0.1: el nombre de mDNS gana al de DNS ("localhost")');
    comprobarIgual(['PC-NETBIOS', 'netbios'], $nombreDe('127.0.0.2'), '127.0.0.2: NetBIOS (el nombre único, no el de grupo)');
    comprobarIgual(['Televisión de la sala', 'upnp'], $nombreDe('127.0.0.3'), '127.0.0.3: UPnP, con acentos');
    comprobarIgual(['Impresora HP', 'upnp'], $nombreDe('127.0.0.4'), '127.0.0.4: con UPnP, mDNS y NetBIOS gana UPnP');
    comprobarIgual(['<b>hola</b>', 'netbios'], $nombreDe('127.0.0.5'), '127.0.0.5: el mDNS malicioso se descarta; el NetBIOS con HTML llega como texto');
    comprobar(!in_array($nombreDe('127.0.0.6')[1], ['upnp', 'mdns', 'netbios'], true), '127.0.0.6: una LOCATION que apunta a otra IP no da nombre');
    comprobarIgual(['<img src=x onerror=alert(1)>', 'upnp'], $nombreDe('127.0.0.8'), '127.0.0.8: el friendlyName con HTML llega como texto');
    comprobarIgual(['PC-LENTA', 'netbios'], $nombreDe('127.0.0.9'), '127.0.0.9: la descripción que nunca llega no impide usar su nombre NetBIOS');
    comprobar(!in_array($nombreDe('127.0.0.10')[1], ['upnp', 'mdns', 'netbios'], true), '127.0.0.10: la descripción con una entidad externa (XXE) no da nombre');
    comprobar(!in_array($nombreDe('127.0.0.11')[1], ['upnp', 'mdns', 'netbios'], true), '127.0.0.11: las respuestas que llegan desde otra IP (127.0.0.12) se ignoran');
    comprobar(!in_array($nombreDe('127.0.0.13')[1], ['upnp', 'mdns', 'netbios'], true), '127.0.0.13: la descripción de más de 64 KB se abandona');
    comprobar(
        !str_contains($r['texto'], 'root:') && !str_contains($r['texto'], 'No debería descargarse'),
        '  la respuesta no trae nada de /etc/passwd ni de la descripción de 127.0.0.7'
    );

    $peticiones = detenerRespondedorDeNombres($respondedor);
    comprobarIgual([], array_values(array_filter($peticiones, fn($p) => str_starts_with($p, '127.0.0.7 '))), 'A 127.0.0.7 no se le pidió nada (la LOCATION de 127.0.0.6 apuntaba ahí)');
    comprobarIgual(1, count(array_filter($peticiones, fn($p) => $p === '127.0.0.3 GET /desc.xml HTTP/1.0')), 'A 127.0.0.3 se le pidió su descripción una vez, con GET');
    comprobarIgual([], array_values(array_filter($peticiones, fn($p) => !str_contains($p, ' GET /'))), '  todas las peticiones fueron GET');
} else {
    avisar('No se pudo escuchar en 127.0.0.x (en macOS solo existe 127.0.0.1): no se probaron los nombres de punta a punta.');
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
exec("git -C $raiz rev-parse --verify --quiet v1.0 2>&1", $salida, $codigoGit); // la versión anterior al escáner
if ($codigoGit === 0) {
    exec("git -C $raiz diff --name-only v1.0 -- servicios/dispositivos servicios/alertas servicios/auth 2>&1", $cambiados);
    comprobarIgual([], $cambiados, 'Dispositivos, Alertas y Auth no cambiaron respecto a v1.0, la versión sin el escáner (git diff)');
    // ping.php se movió a comun/ en la Fase 9: se compara aparte.
    exec("git -C $raiz diff -U0 v1.0 -- servicios/monitoreo ':!servicios/monitoreo/ping.php' 2>&1", $diferencia);
    $lineasCambiadas = array_values(array_filter($diferencia, fn($l) => preg_match('/^[+-](?![+-])/', $l) === 1));
    comprobar(
        array_filter($lineasCambiadas, fn($l) => !str_contains($l, "ping.php';")) === [] && count($lineasCambiadas) <= 2,
        'En Monitoreo solo cambió la línea del require de ping.php',
        implode(' | ', $lineasCambiadas)
    );
    exec("git -C $raiz show v1.0:servicios/monitoreo/ping.php 2>&1", $pingAnterior, $codigoPing);
    if ($codigoPing === 0) {
        $pingActual = file(__DIR__ . '/../servicios/comun/ping.php', FILE_IGNORE_NEW_LINES);
        $agregadas = array_diff($pingActual, $pingAnterior);
        comprobar(
            array_diff($pingAnterior, $pingActual) === [] && array_filter($agregadas, fn($l) => preg_match('/^ \*( |$)/', $l) !== 1) === [],
            'comun/ping.php es el ping.php de Monitoreo sin cambios de código (solo se agregó comentario)',
            implode(' | ', $agregadas)
        );
    } else {
        avisar('No se pudo leer servicios/monitoreo/ping.php de v1.0: no se comparó con comun/ping.php.');
    }
} else {
    avisar('No hay git o no existe la etiqueta v1.0: no se comparó el código de los otros servicios.');
}

simularRed(['ip_propia' => '192.0.2.1']);
$contexto = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
@file_get_contents(dirname(URL_SERVICIOS) . '/pruebas/.red-simulada.json', false, $contexto);
comprobarIgual(404, leerCabeceras($http_response_header ?? [])['codigo'], 'pruebas/.red-simulada.json no es accesible por HTTP (404)');
@file_get_contents(URL_SERVICIOS . '/comun/ping.php', false, $contexto);
comprobarIgual(403, leerCabeceras($http_response_header ?? [])['codigo'], 'servicios/comun/ping.php no es accesible por HTTP (403)');

quitarRedSimulada();
terminarPruebas();
