<?php
/**
 * El nombre de cada equipo encontrado, por cuatro métodos a la vez:
 *
 * - UPnP (upnp.php): SSDP a IP:1900 y la descripción por HTTP.
 * - mDNS (mdns.php): PTR inverso a IP:5353.
 * - NetBIOS (netbios.php): estado del nodo a IP:137.
 * - DNS inverso (dns.php): PTR al DNS del sistema y a la puerta de enlace.
 *
 * Todas las consultas son unicast (a la IP de cada equipo o al servidor DNS):
 * las respuestas llegan desde la misma IP y puerto a los que se preguntó y el
 * firewall local las deja pasar. Se envían de golpe y se atienden en un solo
 * stream_select() con un límite total (LIMITE_SEGUNDOS_NOMBRES): un método que
 * no contesta no retrasa a los demás, solo no aporta su nombre. Las respuestas
 * de NetBIOS, mDNS y SSDP solo cuentan si llegan desde la IP consultada.
 *
 * Si un equipo da nombres por varios métodos, gana el primero de
 * METODOS_POR_PREFERENCIA: el que mejor se entiende. UPnP trae el nombre para
 * mostrar de impresoras, televisiones y módems (su nombre mDNS suele ser un
 * código); mDNS y NetBIOS, el nombre que el dueño le puso al equipo; el DNS lo
 * da el router y puede ser genérico.
 */

declare(strict_types=1);

require_once __DIR__ . '/dns.php';
require_once __DIR__ . '/mdns.php';
require_once __DIR__ . '/netbios.php';
require_once __DIR__ . '/upnp.php';

const LIMITE_SEGUNDOS_NOMBRES = 0.8;
const METODOS_POR_PREFERENCIA = ['upnp', 'mdns', 'netbios', 'dns'];
const PUERTOS_NOMBRES = ['netbios' => PUERTO_NETBIOS, 'mdns' => PUERTO_MDNS, 'ssdp' => PUERTO_SSDP];
const TAMANO_PAQUETE_UDP = 1500;

/**
 * Busca los nombres de cada IP. $puertos: los de PUERTOS_NOMBRES (las pruebas
 * usan otros); un método sin puerto no se consulta. $prefijoRed es la /24
 * escaneada ("192.168.0"), para validar las URLs de UPnP.
 * Devuelve [ip => [metodo => nombre]] solo con los que contestaron a tiempo.
 */
function buscarNombresDeEquipos(array $ips, array $servidoresDns, string $prefijoRed, array $puertos = PUERTOS_NOMBRES, float $limiteSegundos = LIMITE_SEGUNDOS_NOMBRES): array
{
    $ips = array_values($ips);
    if ($ips === []) {
        return [];
    }

    $busqueda = [
        'ips' => $ips,
        'prefijo' => $prefijoRed,
        'nombres' => [],
        'pendientes' => [], // ip → [metodo → respuestas que faltan]
        'canales' => [],    // id del socket → ['socket', 'metodo']
        'consultas' => [],  // metodo → [id de la consulta → ip]
        'descargas' => [],  // id del socket → descarga de UPnP en curso
        'descargas_por_ip' => [], // ip → true: una sola descarga por equipo
    ];

    abrirCanalDns($busqueda, $servidoresDns);
    foreach (['netbios', 'mdns', 'ssdp'] as $metodo) {
        if (isset($puertos[$metodo])) {
            abrirCanalUnicast($busqueda, $metodo, $puertos[$metodo]);
        }
    }

    esperarRespuestas($busqueda, hrtime(true) + (int) ($limiteSegundos * 1e9));

    foreach ($busqueda['canales'] as ['socket' => $socket]) {
        fclose($socket);
    }
    foreach ($busqueda['descargas'] as ['socket' => $socket]) {
        fclose($socket);
    }

    return $busqueda['nombres'];
}

/**
 * El nombre que se muestra: el del método preferido que haya contestado.
 * Devuelve ['nombre' => ?string, 'origen' => ?string].
 */
function elegirNombre(array $nombresPorMetodo): array
{
    foreach (METODOS_POR_PREFERENCIA as $metodo) {
        if (isset($nombresPorMetodo[$metodo])) {
            return ['nombre' => $nombresPorMetodo[$metodo], 'origen' => $metodo];
        }
    }

    return ['nombre' => null, 'origen' => null];
}

/**
 * Un socket "conectado" por servidor DNS (solo recibe de ese servidor) y la
 * consulta PTR de cada IP a cada uno.
 */
function abrirCanalDns(array &$busqueda, array $servidores): void
{
    $sockets = abrirSocketsDns($servidores);
    if ($sockets === []) {
        return;
    }

    $ids = idsDeConsulta($busqueda['ips']);
    $busqueda['consultas']['dns'] = $ids;
    foreach ($busqueda['ips'] as $ip) {
        $busqueda['pendientes'][$ip]['dns'] = count($sockets);
    }

    foreach ($sockets as $socket) {
        $busqueda['canales'][get_resource_id($socket)] = ['socket' => $socket, 'metodo' => 'dns'];
        foreach ($ids as $id => $ip) {
            @fwrite($socket, construirConsultaPtr($id, $ip));
        }
    }
}

/**
 * Un socket UDP sin conectar para el método (recibe de cualquiera; el origen
 * se revisa en cada respuesta) y la consulta a IP:puerto de cada equipo.
 */
function abrirCanalUnicast(array &$busqueda, string $metodo, int $puerto): void
{
    $socket = @stream_socket_server('udp://0.0.0.0:0', $codigo, $mensaje, STREAM_SERVER_BIND);
    if ($socket === false) {
        return;
    }
    stream_set_blocking($socket, false);
    $busqueda['canales'][get_resource_id($socket)] = ['socket' => $socket, 'metodo' => $metodo];

    $ids = idsDeConsulta($busqueda['ips']);
    $busqueda['consultas'][$metodo] = $ids;
    $metodoDelNombre = $metodo === 'ssdp' ? 'upnp' : $metodo;

    foreach ($ids as $id => $ip) {
        $paquete = match ($metodo) {
            'netbios' => construirConsultaNetbios($id),
            'mdns' => construirConsultaMdns($id, $ip),
            'ssdp' => construirMSearch($ip, $puerto),
        };
        if (@stream_socket_sendto($socket, $paquete, 0, "$ip:$puerto") > 0) {
            $busqueda['pendientes'][$ip][$metodoDelNombre] = 1;
        }
    }
}

/**
 * [id de consulta → ip], con ids distintos entre sí y difíciles de adivinar.
 */
function idsDeConsulta(array $ips): array
{
    $ids = [];
    $inicio = random_int(0, 65535);
    foreach ($ips as $i => $ip) {
        $ids[($inicio + $i) % 65536] = $ip;
    }

    return $ids;
}

/**
 * Atiende respuestas y descargas hasta que ya no falte nada que pueda cambiar
 * el nombre elegido de algún equipo, o hasta el límite.
 */
function esperarRespuestas(array &$busqueda, int $limite): void
{
    while (quedaAlgoPendiente($busqueda)) {
        $restante = intdiv($limite - hrtime(true), 1000); // microsegundos
        if ($restante <= 0) {
            return;
        }

        $lectura = array_column($busqueda['canales'], 'socket');
        $escritura = [];
        foreach ($busqueda['descargas'] as $descarga) {
            if ($descarga['peticion'] !== null) {
                $escritura[] = $descarga['socket'];
            } else {
                $lectura[] = $descarga['socket'];
            }
        }
        $excepcion = null;
        if ($lectura === [] && $escritura === []) {
            return;
        }
        if (@stream_select($lectura, $escritura, $excepcion, 0, $restante) < 1) {
            continue;
        }

        foreach ($escritura as $socket) {
            enviarPeticionDescripcion($busqueda, get_resource_id($socket));
        }
        foreach ($lectura as $socket) {
            $id = get_resource_id($socket);
            isset($busqueda['canales'][$id]) ? recibirPaquete($busqueda, $busqueda['canales'][$id]) : leerDescripcion($busqueda, $id);
        }
    }
}

/**
 * Falta algo si a algún equipo le falta la respuesta de un método preferido
 * sobre el mejor nombre que ya tiene.
 */
function quedaAlgoPendiente(array $busqueda): bool
{
    foreach ($busqueda['pendientes'] as $ip => $metodos) {
        foreach (METODOS_POR_PREFERENCIA as $metodo) {
            if (isset($busqueda['nombres'][$ip][$metodo])) {
                break;
            }
            if (($metodos[$metodo] ?? 0) > 0) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Un paquete UDP de un canal: lo interpreta según el método.
 */
function recibirPaquete(array &$busqueda, array $canal): void
{
    ['socket' => $socket, 'metodo' => $metodo] = $canal;
    $origen = '';
    $paquete = $metodo === 'dns' ? @fread($socket, TAMANO_PAQUETE_UDP) : @stream_socket_recvfrom($socket, TAMANO_PAQUETE_UDP, 0, $origen);

    if (!is_string($paquete) || $paquete === '') {
        return;
    }
    $ipOrigen = substr((string) $origen, 0, (int) strrpos((string) $origen, ':'));

    if ($metodo === 'ssdp') {
        recibirRespuestaSsdp($busqueda, $paquete, $ipOrigen);
        return;
    }
    if (strlen($paquete) < 12) {
        return;
    }

    $id = unpack('n', $paquete)[1];
    $ip = $busqueda['consultas'][$metodo][$id] ?? null;
    if ($ip === null || ($metodo !== 'dns' && $ipOrigen !== $ip) || ($busqueda['pendientes'][$ip][$metodo] ?? 0) < 1) {
        return;
    }

    $nombre = match ($metodo) {
        'dns' => leerRespuestaPtr($paquete, $id),
        'mdns' => leerRespuestaMdns($paquete, $id),
        'netbios' => leerRespuestaNetbios($paquete, $id),
    };
    if ($metodo !== 'dns' && $nombre === null && !esPaqueteDeRespuesta($paquete)) {
        return; // un paquete que no es respuesta no cuenta como "contestó"
    }

    $busqueda['pendientes'][$ip][$metodo]--;
    if ($nombre !== null) {
        $busqueda['nombres'][$ip][$metodo] = $nombre;
        $busqueda['pendientes'][$ip][$metodo] = 0;
    }
}

/**
 * Un paquete con formato DNS/NetBIOS que es una respuesta (bandera QR), aunque
 * no traiga un nombre válido.
 */
function esPaqueteDeRespuesta(string $paquete): bool
{
    return (unpack('n', $paquete, 2)[1] & 0x8000) !== 0;
}

/**
 * Respuesta de SSDP: si viene de un equipo consultado y su LOCATION es válida,
 * empieza la descarga de la descripción; si no, ese equipo queda sin UPnP.
 */
function recibirRespuestaSsdp(array &$busqueda, string $paquete, string $ip): void
{
    if (($busqueda['pendientes'][$ip]['upnp'] ?? 0) < 1 || isset($busqueda['descargas_por_ip'][$ip])) {
        return;
    }

    $location = leerLocationSsdp($paquete);
    $destino = $location === null ? null : validarUrlDescripcion($location, $ip, $busqueda['prefijo']);
    $socket = $destino === null ? false : @stream_socket_client(
        "tcp://$ip:{$destino['puerto']}", $codigo, $mensaje, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
    );

    if ($socket === false) {
        $busqueda['pendientes'][$ip]['upnp'] = 0;
        return;
    }

    stream_set_blocking($socket, false);
    $busqueda['descargas_por_ip'][$ip] = true;
    $busqueda['descargas'][get_resource_id($socket)] = [
        'socket' => $socket,
        'ip' => $ip,
        'peticion' => construirPeticionDescripcion($ip, $destino['puerto'], $destino['ruta']),
        'respuesta' => '',
    ];
}

/**
 * La conexión ya se puede escribir: envía el GET de la descripción.
 */
function enviarPeticionDescripcion(array &$busqueda, int $id): void
{
    $descarga = &$busqueda['descargas'][$id];
    $escritos = @fwrite($descarga['socket'], $descarga['peticion']);

    if ($escritos === false || $escritos === 0) {
        terminarDescarga($busqueda, $id, null);
        return;
    }
    $descarga['peticion'] = $escritos < strlen($descarga['peticion']) ? substr($descarga['peticion'], $escritos) : null;
}

/**
 * Lee lo que llegó de una descarga; al cerrarse la conexión, interpreta la
 * descripción. Si pasa del tamaño máximo, se abandona.
 */
function leerDescripcion(array &$busqueda, int $id): void
{
    $descarga = &$busqueda['descargas'][$id];
    $datos = @fread($descarga['socket'], 8192);

    if (is_string($datos) && $datos !== '') {
        $descarga['respuesta'] .= $datos;
        if (strlen($descarga['respuesta']) > TAMANO_MAXIMO_DESCRIPCION) {
            terminarDescarga($busqueda, $id, null);
        }
        return;
    }

    if (feof($descarga['socket']) || $datos === false) {
        terminarDescarga($busqueda, $id, leerRespuestaDescripcion($descarga['respuesta']));
    }
}

function terminarDescarga(array &$busqueda, int $id, ?string $nombre): void
{
    ['socket' => $socket, 'ip' => $ip] = $busqueda['descargas'][$id];
    fclose($socket);
    unset($busqueda['descargas'][$id]);

    $busqueda['pendientes'][$ip]['upnp'] = 0;
    if ($nombre !== null) {
        $busqueda['nombres'][$ip]['upnp'] = $nombre;
    }
}
