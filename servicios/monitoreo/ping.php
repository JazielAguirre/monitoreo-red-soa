<?php
/**
 * Ping a un dispositivo: construye el comando según el sistema operativo,
 * lo ejecuta y extrae si respondió y su tiempo de respuesta.
 * Solo acepta IPv4. Espera máxima: 1 segundo.
 */

declare(strict_types=1);

/**
 * Indica si el texto es una dirección IPv4 válida.
 */
function esIpv4Valida(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

/**
 * Hace ping a una IPv4 y devuelve:
 * - responde: true si llegó respuesta.
 * - tiempo_respuesta_ms: tiempo en ms (0 si fue menor a 1 ms), o null si no respondió.
 * - salida: texto que imprimió el comando.
 *
 * @throws InvalidArgumentException si la IP no es una IPv4 válida (no se ejecuta nada).
 * @throws RuntimeException si no se puede ejecutar el comando ping.
 */
function hacerPing(string $ip): array
{
    if (!esIpv4Valida($ip)) {
        throw new InvalidArgumentException('IP no válida para ping: ' . $ip);
    }

    $salida = ejecutarComandoPing(construirComandoPing($ip));
    $responde = salidaIndicaRespuesta($salida);

    return [
        'responde' => $responde,
        'tiempo_respuesta_ms' => $responde ? extraerTiempoMs($salida) : null,
        'salida' => $salida,
    ];
}

/**
 * Arma el comando de ping para el sistema operativo actual. La IP ya validada
 * pasa además por escapeshellarg().
 */
function construirComandoPing(string $ip): string
{
    $ipSegura = escapeshellarg($ip);

    return match (PHP_OS_FAMILY) {
        'Windows' => "ping -n 1 -w 1000 $ipSegura",
        'Darwin' => "ping -c 1 -t 1 $ipSegura",
        default => "ping -c 1 -W 1 $ipSegura",
    };
}

/**
 * Ejecuta el comando y devuelve su salida (stdout y stderr) en UTF-8.
 *
 * @throws RuntimeException si exec() no está disponible o no se encuentra ping.
 */
function ejecutarComandoPing(string $comando): string
{
    if (!function_exists('exec')) {
        throw new RuntimeException('La función exec() está deshabilitada en PHP.');
    }

    $lineas = [];
    $codigoSalida = 0;
    exec($comando . ' 2>&1', $lineas, $codigoSalida);

    // 127: comando no encontrado en sh; 9009: en cmd de Windows.
    if ($codigoSalida === 127 || $codigoSalida === 9009) {
        throw new RuntimeException('No se encontró el comando ping: ' . implode("\n", $lineas));
    }

    return convertirAUtf8(trim(implode("\n", $lineas)));
}

/**
 * La consola de Windows puede devolver texto en CP850 (acentos de
 * "Host de destino inaccesible"); json_encode necesita UTF-8.
 */
function convertirAUtf8(string $texto): string
{
    if (preg_match('//u', $texto) === 1 || !function_exists('iconv')) {
        return $texto;
    }

    $convertido = iconv('CP850', 'UTF-8//IGNORE', $texto);

    return $convertido === false ? $texto : $convertido;
}

/**
 * El dispositivo respondió solo si la salida contiene "TTL=". En Windows el
 * código de salida no es confiable ("Host de destino inaccesible" devuelve 0).
 */
function salidaIndicaRespuesta(string $salida): bool
{
    return stripos($salida, 'TTL=') !== false;
}

/**
 * Extrae el tiempo en ms de la salida, en inglés o español:
 * time=2ms, time<1ms, tiempo=2ms, tiempo<1m, time=0.032 ms.
 * "<1" se guarda como 0. Devuelve null si no encuentra el tiempo.
 */
function extraerTiempoMs(string $salida): ?float
{
    if (preg_match('/(?:time|tiempo)\s*([=<])\s*(\d+(?:[.,]\d+)?)/i', $salida, $coincidencia) !== 1) {
        return null;
    }

    if ($coincidencia[1] === '<') {
        return 0.0;
    }

    return round((float) str_replace(',', '.', $coincidencia[2]), 2);
}
