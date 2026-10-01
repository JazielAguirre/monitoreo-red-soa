<?php
/**
 * Lectura de la tabla ARP del sistema: qué IPs de la red contestaron hace poco
 * y con qué dirección MAC. Así aparecen también los equipos que bloquean el ping.
 *
 * - Linux: "ip -4 neigh show"; si no existe el comando, /proc/net/arp.
 * - Windows: "arp -a".
 * - macOS: "arp -an".
 *
 * Nunca se buscan palabras que cambien con el idioma del sistema ("dinámico",
 * "dynamic"): solo patrones de IPv4 y de MAC. Los estados de "ip neigh"
 * (REACHABLE, FAILED…) son siempre en inglés.
 */

declare(strict_types=1);

require_once __DIR__ . '/simulacion.php';

const FORMATO_IP_NEIGH = 'ip-neigh';
const FORMATO_PROC_NET_ARP = 'proc-net-arp';
const FORMATO_WINDOWS = 'windows';
const FORMATO_MAC = 'mac';

// Ubicaciones habituales de "ip": Apache puede correr con un PATH sin /usr/sbin.
const RUTAS_COMANDO_IP = ['/usr/sbin/ip', '/sbin/ip', '/usr/bin/ip', '/bin/ip'];

/**
 * Lee la tabla ARP del sistema. Devuelve [ip => mac] con la MAC normalizada
 * (aa:bb:cc:dd:ee:ff), sin entradas incompletas, fallidas, broadcast ni multicast.
 * Si no se puede leer, devuelve [] (el escaneo sigue solo con el ping).
 */
function leerTablaArp(): array
{
    [$formato, $texto] = leerTextoArpDelSistema();

    return $texto === null ? [] : interpretarTablaArp($formato, $texto);
}

/**
 * La tabla ARP que usa el servicio: la del sistema o, si hay una red simulada
 * vigente (solo en las pruebas), la de su texto. También la usa
 * herramientas/preparar-demo.php para saber si el router está conectado.
 */
function tablaArpDelServicio(): array
{
    $simulada = leerRedSimulada();

    if ($simulada !== null) {
        return interpretarTablaArp(formatoArpDelSistema(), $simulada['arp'] ?? '');
    }

    return leerTablaArp();
}

/**
 * Formato de la tabla ARP del sistema operativo actual (sin ejecutar nada).
 */
function formatoArpDelSistema(): string
{
    return match (PHP_OS_FAMILY) {
        'Windows' => FORMATO_WINDOWS,
        'Darwin' => FORMATO_MAC,
        default => FORMATO_IP_NEIGH,
    };
}

/**
 * Ejecuta el comando del sistema. Devuelve [formato, texto], con texto null si
 * no hubo forma de leer la tabla.
 */
function leerTextoArpDelSistema(): array
{
    $formato = formatoArpDelSistema();

    if ($formato === FORMATO_WINDOWS) {
        return [$formato, ejecutarComandoArp('arp -a')];
    }

    if ($formato === FORMATO_MAC) {
        return [$formato, ejecutarComandoArp('arp -an')];
    }

    foreach (RUTAS_COMANDO_IP as $ruta) {
        if (is_file($ruta) && is_executable($ruta)) {
            $texto = ejecutarComandoArp(escapeshellarg($ruta) . ' -4 neigh show');
            if ($texto !== null) {
                return [FORMATO_IP_NEIGH, $texto];
            }
        }
    }

    $texto = @file_get_contents('/proc/net/arp');

    return [FORMATO_PROC_NET_ARP, $texto === false ? null : $texto];
}

/**
 * Salida de un comando fijo (sin datos del usuario), o null si falló.
 */
function ejecutarComandoArp(string $comando): ?string
{
    if (!function_exists('exec')) {
        return null;
    }

    $lineas = [];
    $codigo = 0;
    @exec($comando . ' 2>&1', $lineas, $codigo);

    return $codigo === 0 ? implode("\n", $lineas) : null;
}

/**
 * Convierte el texto de la tabla ARP en [ip => mac].
 */
function interpretarTablaArp(string $formato, string $texto): array
{
    $entradas = match ($formato) {
        FORMATO_IP_NEIGH => entradasIpNeigh($texto),
        FORMATO_PROC_NET_ARP => entradasProcNetArp($texto),
        FORMATO_WINDOWS => entradasArpWindows($texto),
        FORMATO_MAC => entradasArpMac($texto),
        default => [],
    };

    $tabla = [];
    foreach ($entradas as [$ip, $mac]) {
        $mac = normalizarMac($mac);
        if ($mac !== null && esMacDeEquipo($mac) && !isset($tabla[$ip])
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $tabla[$ip] = $mac;
        }
    }

    return $tabla;
}

/**
 * "192.168.1.1 dev wlan0 lladdr be:1e:a4:da:96:d3 REACHABLE"
 * Se descartan FAILED e INCOMPLETE (el equipo no contestó).
 */
function entradasIpNeigh(string $texto): array
{
    $entradas = [];

    foreach (preg_split('/\R/', $texto) as $linea) {
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s.*\blladdr\s+([0-9a-f:]+)(.*)$/i', trim($linea), $c) === 1
            && preg_match('/\b(FAILED|INCOMPLETE)\b/', $c[3]) !== 1) {
            $entradas[] = [$c[1], $c[2]];
        }
    }

    return $entradas;
}

/**
 * /proc/net/arp: "IP  tipo  banderas  MAC  máscara  interfaz". La bandera 0x2
 * (ATF_COM) indica que la entrada está completa.
 */
function entradasProcNetArp(string $texto): array
{
    $entradas = [];

    foreach (preg_split('/\R/', $texto) as $linea) {
        $campos = preg_split('/\s+/', trim($linea));
        if (count($campos) >= 4 && preg_match('/^0x[0-9a-f]+$/i', $campos[2]) === 1 && (hexdec($campos[2]) & 0x2) !== 0) {
            $entradas[] = [$campos[0], $campos[3]];
        }
    }

    return $entradas;
}

/**
 * "  192.168.1.1           be-1e-a4-da-96-d3     dinámico" (o "dynamic").
 * Las líneas "Interfaz: 192.168.1.5 --- 0xb" no llevan MAC y no coinciden.
 */
function entradasArpWindows(string $texto): array
{
    preg_match_all('/^\s*(\d{1,3}(?:\.\d{1,3}){3})\s+([0-9a-f]{2}(?:-[0-9a-f]{2}){5})\b/im', $texto, $coincidencias, PREG_SET_ORDER);

    return array_map(fn(array $c): array => [$c[1], $c[2]], $coincidencias);
}

/**
 * "? (192.168.1.1) at be:1e:a4:da:96:d3 on en0 ifscope [ethernet]". macOS
 * escribe los octetos sin cero a la izquierda (0:1c:42:0:0:8); las entradas
 * "(incomplete)" no coinciden.
 */
function entradasArpMac(string $texto): array
{
    preg_match_all('/\((\d{1,3}(?:\.\d{1,3}){3})\) at ([0-9a-f]{1,2}(?::[0-9a-f]{1,2}){5})\b/i', $texto, $coincidencias, PREG_SET_ORDER);

    return array_map(fn(array $c): array => [$c[1], $c[2]], $coincidencias);
}

/**
 * "BE-1E-A4-DA-96-D3" o "0:1c:42:0:0:8" → "be:1e:a4:da:96:d3" / "00:1c:42:00:00:08".
 * Devuelve null si no son 6 octetos hexadecimales.
 */
function normalizarMac(string $mac): ?string
{
    $octetos = preg_split('/[:-]/', strtolower(trim($mac)));

    if (count($octetos) !== 6) {
        return null;
    }

    foreach ($octetos as $i => $octeto) {
        if (preg_match('/^[0-9a-f]{1,2}$/', $octeto) !== 1) {
            return null;
        }
        $octetos[$i] = str_pad($octeto, 2, '0', STR_PAD_LEFT);
    }

    return implode(':', $octetos);
}

/**
 * Una MAC de un equipo: ni 00:00:00:00:00:00 (entrada sin resolver) ni una
 * dirección de grupo (primer octeto impar: broadcast ff:ff:… y multicast 01:00:5e:…).
 */
function esMacDeEquipo(string $mac): bool
{
    return $mac !== '00:00:00:00:00:00' && (hexdec(substr($mac, 0, 2)) & 1) === 0;
}
