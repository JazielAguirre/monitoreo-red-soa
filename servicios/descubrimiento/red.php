<?php
/**
 * La red local de esta computadora: IP propia, puerta de enlace, la /24 que se
 * escanea y sus lotes. La red la calcula siempre el servidor; el navegador solo
 * elige el número de lote (docs/API.md, "Servicio de Descubrimiento").
 *
 * La detección de la IP propia y de la puerta de enlace también la usa
 * herramientas/preparar-demo.php (a través de herramientas/red.php).
 * Las funciones de detección devuelven una IPv4 o null; nunca lanzan.
 */

declare(strict_types=1);

const LOTES_POR_RED = 4;
const DIRECCIONES_POR_LOTE = 64;
const ULTIMA_DIRECCION = 254; // .255 es broadcast y .0 es la red

// Rangos privados (RFC 1918): la única clase de red que se escanea.
const RANGOS_PRIVADOS = [
    ['10.0.0.0', 8],
    ['172.16.0.0', 12],
    ['192.168.0.0', 16],
];

/**
 * IP propia en la red local. Se "conecta" un socket UDP a una dirección de
 * documentación (192.0.2.1): con UDP, conectar no envía ningún paquete, solo
 * elige la interfaz de salida, y su dirección local es la IP que buscamos.
 * Funciona igual en Linux, Windows y macOS, sin comandos externos. Sin red
 * (sin ruta hacia afuera) la conexión falla y devuelve null.
 */
function detectarIpPropia(): ?string
{
    $socket = @stream_socket_client('udp://192.0.2.1:9', $codigo, $mensaje, 1);

    if ($socket === false) {
        return null;
    }

    $local = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $ip = substr($local, 0, (int) strrpos($local, ':'));

    return esIpv4DeRed($ip) ? $ip : null;
}

/**
 * Puerta de enlace predeterminada, según el sistema operativo.
 */
function detectarPuertaDeEnlace(): ?string
{
    $ip = match (PHP_OS_FAMILY) {
        'Windows' => puertaDeEnlaceWindows(),
        'Darwin' => puertaDeEnlaceMac(),
        default => puertaDeEnlaceLinux(),
    };

    return $ip !== null && esIpv4DeRed($ip) ? $ip : null;
}

/**
 * Linux: /proc/net/route (no necesita ningún comando). La ruta predeterminada
 * tiene destino 00000000 y la bandera RTF_GATEWAY (0x2); la puerta de enlace
 * está en hexadecimal con los bytes al revés. Si hay varias, la de menor métrica.
 * Respaldo: "ip route show default".
 */
function puertaDeEnlaceLinux(): ?string
{
    $mejor = null;
    $mejorMetrica = PHP_INT_MAX;

    foreach (@file('/proc/net/route', FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        $campos = preg_split('/\s+/', trim($linea));
        if (count($campos) < 7 || $campos[1] !== '00000000' || (hexdec($campos[3]) & 0x2) === 0) {
            continue;
        }
        $metrica = (int) $campos[6];
        if ($metrica < $mejorMetrica) {
            $mejorMetrica = $metrica;
            $mejor = implode('.', array_reverse(array_map('hexdec', str_split($campos[2], 2))));
        }
    }

    if ($mejor !== null) {
        return $mejor;
    }

    $salida = (string) @shell_exec('ip route show default 2>/dev/null');

    return preg_match('/default via (\d+\.\d+\.\d+\.\d+)/', $salida, $c) === 1 ? $c[1] : null;
}

/**
 * Windows: "route print -4 0.0.0.0". Las columnas son números (no dependen del
 * idioma, a diferencia de ipconfig): destino, máscara, puerta de enlace,
 * interfaz y métrica. Se elige la ruta de menor métrica.
 */
function puertaDeEnlaceWindows(): ?string
{
    $salida = (string) @shell_exec('route print -4 0.0.0.0');
    $mejor = null;
    $mejorMetrica = PHP_INT_MAX;

    preg_match_all('/^\s*0\.0\.0\.0\s+0\.0\.0\.0\s+(\d+\.\d+\.\d+\.\d+)\s+\d+\.\d+\.\d+\.\d+\s+(\d+)/m', $salida, $rutas, PREG_SET_ORDER);
    foreach ($rutas as [, $puerta, $metrica]) {
        if ((int) $metrica < $mejorMetrica) {
            $mejorMetrica = (int) $metrica;
            $mejor = $puerta;
        }
    }

    return $mejor;
}

/**
 * macOS: "route -n get default".
 */
function puertaDeEnlaceMac(): ?string
{
    $salida = (string) @shell_exec('route -n get default 2>/dev/null');

    return preg_match('/gateway:\s*(\d+\.\d+\.\d+\.\d+)/', $salida, $c) === 1 ? $c[1] : null;
}

/**
 * Una IPv4 válida que no sea 0.0.0.0 ni de la propia máquina (127.x).
 */
function esIpv4DeRed(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && $ip !== '0.0.0.0'
        && !str_starts_with($ip, '127.');
}

/**
 * Indica si la IPv4 está en un rango privado (10/8, 172.16/12 o 192.168/16).
 */
function esIpPrivada(string $ip): bool
{
    $numero = ip2long($ip);

    if ($numero === false) {
        return false;
    }

    foreach (RANGOS_PRIVADOS as [$base, $prefijo]) {
        $mascara = -1 << (32 - $prefijo);
        if (($numero & $mascara) === (ip2long($base) & $mascara)) {
            return true;
        }
    }

    return false;
}

/**
 * Los tres primeros octetos de la /24 de una IPv4: 192.168.137.66 → 192.168.137
 */
function prefijoDeRed(string $ip): string
{
    return substr($ip, 0, (int) strrpos($ip, '.'));
}

/**
 * Datos de la red que se escanea (el data de GET /descubrimiento/red).
 * La puerta de enlace se descarta si no está dentro de la /24.
 */
function describirRed(string $ipPropia, ?string $puertaEnlace): array
{
    $prefijo = prefijoDeRed($ipPropia);

    return [
        'red' => "$prefijo.0/24",
        'ip_propia' => $ipPropia,
        'puerta_enlace' => $puertaEnlace !== null && prefijoDeRed($puertaEnlace) === $prefijo ? $puertaEnlace : null,
        'lotes' => LOTES_POR_RED,
        'total_direcciones' => ULTIMA_DIRECCION,
    ];
}

/**
 * Las direcciones del lote $numero (1 a LOTES_POR_RED) de la /24 de $prefijo,
 * o null si el lote no existe. Lote 1: .1–.64 … lote 4: .193–.254.
 */
function direccionesDelLote(string $prefijo, int $numero): ?array
{
    if ($numero < 1 || $numero > LOTES_POR_RED) {
        return null;
    }

    $desde = ($numero - 1) * DIRECCIONES_POR_LOTE + 1;
    $hasta = min($numero * DIRECCIONES_POR_LOTE, ULTIMA_DIRECCION);

    return array_map(fn(int $ultimo): string => "$prefijo.$ultimo", range($desde, $hasta));
}
