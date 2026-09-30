<?php
/**
 * Nombre de los equipos por DNS inverso (consultas PTR), con un tiempo total
 * corto. gethostbyaddr() no sirve aquí: no tiene límite de tiempo y consulta
 * una IP a la vez, así que diez equipos sin nombre podían tardar un minuto.
 *
 * Se arma la consulta DNS a mano (formato de RFC 1035) y se envían todas a la
 * vez por UDP, sin bloquear, al DNS del sistema (/etc/resolv.conf en Linux y
 * macOS) y a la puerta de enlace (el router suele conocer los nombres que
 * asignó por DHCP). La primera respuesta válida de cada IP gana.
 */

declare(strict_types=1);

const LIMITE_SEGUNDOS_DNS = 0.8;
const PUERTO_DNS = 53;
const TIPO_PTR = 12;
const ARCHIVO_RESOLV_CONF = '/etc/resolv.conf';
const LONGITUD_MAXIMA_NOMBRE = 253;

/**
 * Busca el nombre de cada IP. Devuelve [ip => nombre] solo con las que
 * respondieron a tiempo; las demás no aparecen.
 */
function buscarNombres(array $ips, array $servidores, float $limiteSegundos = LIMITE_SEGUNDOS_DNS): array
{
    if ($ips === [] || $servidores === []) {
        return [];
    }

    $consultas = []; // id de la consulta → ip
    $paquetes = [];
    foreach (array_values($ips) as $i => $ip) {
        $id = ($i + random_int(1, 1000)) % 65536; // ids distintos entre sí y difíciles de adivinar
        while (isset($consultas[$id])) {
            $id = ($id + 1) % 65536;
        }
        $consultas[$id] = $ip;
        $paquetes[] = construirConsultaPtr($id, $ip);
    }

    $sockets = abrirSocketsDns($servidores);
    foreach ($sockets as $socket) {
        foreach ($paquetes as $paquete) {
            @fwrite($socket, $paquete);
        }
    }

    $nombres = recibirRespuestasPtr($sockets, $consultas, $limiteSegundos);
    array_map('fclose', $sockets);

    return $nombres;
}

/**
 * Espera respuestas hasta que cada IP tenga nombre o una respuesta de todos los
 * servidores (aunque sea "no existe"), o hasta agotar el tiempo.
 */
function recibirRespuestasPtr(array $sockets, array $consultas, float $limiteSegundos): array
{
    $nombres = [];
    $sinContestar = array_fill_keys(array_keys($consultas), count($sockets)); // id → servidores que faltan
    $contestadas = []; // "socket:id" ya contadas
    $limite = hrtime(true) + (int) ($limiteSegundos * 1e9);

    while (array_filter($sinContestar) !== []) {
        $restante = ($limite - hrtime(true)) / 1e3; // microsegundos
        if ($restante <= 0) {
            break;
        }

        $lectura = $sockets;
        $escritura = $excepcion = null;
        if (@stream_select($lectura, $escritura, $excepcion, 0, (int) $restante) < 1) {
            continue;
        }

        foreach ($lectura as $socket) {
            $paquete = @fread($socket, 1500);
            if (!is_string($paquete) || strlen($paquete) < 12) {
                continue;
            }
            $id = unpack('n', $paquete)[1];
            $clave = get_resource_id($socket) . ":$id";
            if (!isset($consultas[$id]) || isset($contestadas[$clave])) {
                continue;
            }
            $contestadas[$clave] = true;
            $sinContestar[$id]--;

            $ip = $consultas[$id];
            $nombre = leerRespuestaPtr($paquete, $id);
            if ($nombre !== null && !isset($nombres[$ip])) {
                $nombres[$ip] = $nombre;
                $sinContestar[$id] = 0;
            }
        }
    }

    return $nombres;
}

/**
 * Un socket UDP "conectado" a cada servidor (conectar UDP no envía nada) y sin bloqueo.
 */
function abrirSocketsDns(array $servidores): array
{
    $sockets = [];

    foreach (array_unique($servidores) as $servidor) {
        $socket = @stream_socket_client('udp://' . $servidor . ':' . PUERTO_DNS, $codigo, $mensaje, 1);
        if ($socket !== false) {
            stream_set_blocking($socket, false);
            $sockets[] = $socket;
        }
    }

    return $sockets;
}

/**
 * Servidores DNS a los que se pregunta: los "nameserver" IPv4 de /etc/resolv.conf
 * (Linux y macOS; en Windows no existe) y la puerta de enlace, si la hay.
 */
function servidoresDns(?string $puertaEnlace): array
{
    $servidores = [];
    $texto = is_readable(ARCHIVO_RESOLV_CONF) ? (string) @file_get_contents(ARCHIVO_RESOLV_CONF) : '';

    preg_match_all('/^\s*nameserver\s+(\d{1,3}(?:\.\d{1,3}){3})\s*$/m', $texto, $coincidencias);
    foreach ($coincidencias[1] as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $servidores[] = $ip;
        }
    }

    if ($puertaEnlace !== null) {
        $servidores[] = $puertaEnlace;
    }

    return array_values(array_unique($servidores));
}

/**
 * Consulta PTR de una IPv4: 192.168.1.20 → 20.1.168.192.in-addr.arpa, tipo PTR,
 * clase IN, con recursión deseada.
 */
function construirConsultaPtr(int $id, string $ip): string
{
    $encabezado = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);
    $etiquetas = [...array_reverse(explode('.', $ip)), 'in-addr', 'arpa'];
    $nombre = '';
    foreach ($etiquetas as $etiqueta) {
        $nombre .= chr(strlen($etiqueta)) . $etiqueta;
    }

    return $encabezado . $nombre . "\0" . pack('nn', TIPO_PTR, 1);
}

/**
 * Lee el nombre de la primera respuesta PTR del paquete, o null si el paquete
 * no es la respuesta a esta consulta, trae un error o no tiene un PTR válido.
 */
function leerRespuestaPtr(string $paquete, int $idEsperado): ?string
{
    if (strlen($paquete) < 12) {
        return null;
    }

    [, $id, $banderas, $preguntas, $respuestas] = unpack('n5', $paquete);
    $esRespuesta = ($banderas & 0x8000) !== 0;
    $sinError = ($banderas & 0x000F) === 0;

    if ($id !== $idEsperado || !$esRespuesta || !$sinError || $respuestas < 1) {
        return null;
    }

    $posicion = 12;
    for ($i = 0; $i < $preguntas; $i++) {
        if (leerNombreDns($paquete, $posicion) === null) {
            return null;
        }
        $posicion += 4; // tipo y clase
    }

    for ($i = 0; $i < $respuestas; $i++) {
        if (leerNombreDns($paquete, $posicion) === null || $posicion + 10 > strlen($paquete)) {
            return null;
        }
        ['tipo' => $tipo, 'largo' => $largo] = unpack('ntipo/nclase/Nttl/nlargo', $paquete, $posicion);
        $posicion += 10;

        if ($tipo === TIPO_PTR) {
            $inicioDatos = $posicion;
            $nombre = leerNombreDns($paquete, $inicioDatos);
            return $nombre !== null && esNombreDeEquipo($nombre) ? $nombre : null;
        }
        $posicion += $largo;
    }

    return null;
}

/**
 * Lee un nombre DNS desde $posicion (y la avanza), siguiendo los punteros de
 * compresión. Devuelve el nombre sin el punto final, o null si está mal formado.
 * Los saltos se limitan para que un paquete malicioso no cause un ciclo.
 */
function leerNombreDns(string $paquete, int &$posicion): ?string
{
    $etiquetas = [];
    $actual = $posicion;
    $saltos = 0;
    $finAvance = null; // dónde sigue el paquete después del nombre
    $total = strlen($paquete);

    while (true) {
        if ($actual >= $total) {
            return null;
        }
        $largo = ord($paquete[$actual]);

        if ($largo === 0) {
            $posicion = $finAvance ?? $actual + 1;
            return implode('.', $etiquetas);
        }

        if (($largo & 0xC0) === 0xC0) { // puntero de compresión
            if ($actual + 1 >= $total || ++$saltos > 10) {
                return null;
            }
            $finAvance ??= $actual + 2;
            $actual = (($largo & 0x3F) << 8) | ord($paquete[$actual + 1]);
            continue;
        }

        if ($largo > 63 || $actual + 1 + $largo > $total) {
            return null;
        }
        $etiquetas[] = substr($paquete, $actual + 1, $largo);
        $actual += 1 + $largo;
    }
}

/**
 * Un nombre de equipo razonable: letras, números, guiones, guiones bajos y puntos.
 * Se descarta cualquier otra cosa (la interfaz lo muestra como texto de todos modos).
 */
function esNombreDeEquipo(string $nombre): bool
{
    return $nombre !== '' && strlen($nombre) <= LONGITUD_MAXIMA_NOMBRE
        && preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $nombre) === 1;
}
