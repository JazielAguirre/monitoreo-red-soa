<?php
/**
 * Detección de la red donde se presenta la demo (herramientas/preparar-demo.php):
 * la puerta de enlace (hace de "Router principal") y la IP propia de esta
 * computadora (hace de "PC Recepción" y es la dirección para el celular).
 * Cada función devuelve una IPv4 o null si no la encuentra; nunca lanza.
 *
 * Solo línea de comandos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
