<?php
/**
 * Ping a muchas direcciones en paralelo, con el mismo comando y la misma lectura
 * de la salida que el Servicio de Monitoreo (comun/ping.php). Cada ping espera
 * 1 segundo, así que 64 direcciones tardan alrededor de 1 segundo, no 64.
 *
 * Cada ping es un proceso de proc_open. La salida se lee cuando el proceso
 * termina (con un ping, cabe de sobra en el búfer del pipe): en Windows los
 * pipes no admiten lectura sin bloqueo, así que no se leen mientras corre.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/ping.php';

const PINGS_SIMULTANEOS = 64;
const LIMITE_SEGUNDOS_PINGS = 3.0; // tope de todo el lote; cada ping espera 1 s
const PAUSA_ENTRE_REVISIONES_US = 10000;

/**
 * Hace ping a cada IPv4 de la lista, hasta PINGS_SIMULTANEOS a la vez.
 * Devuelve [ip => ['responde' => bool, 'tiempo_respuesta_ms' => ?float]].
 * Un ping que pasa del límite se termina y cuenta como "no responde".
 *
 * @throws InvalidArgumentException si alguna IP no es IPv4 (no se ejecuta nada).
 * @throws RuntimeException si proc_open() no está disponible.
 */
function hacerPingsEnParalelo(array $ips, int $simultaneos = PINGS_SIMULTANEOS, float $limiteSegundos = LIMITE_SEGUNDOS_PINGS): array
{
    foreach ($ips as $ip) {
        if (!is_string($ip) || !esIpv4Valida($ip)) {
            throw new InvalidArgumentException('IP no válida para ping: ' . var_export($ip, true));
        }
    }

    if (!function_exists('proc_open')) {
        throw new RuntimeException('La función proc_open() está deshabilitada en PHP.');
    }

    $pendientes = array_values($ips);
    $enCurso = [];
    $resultados = [];
    $limite = hrtime(true) + (int) ($limiteSegundos * 1e9);

    while ($pendientes !== [] || $enCurso !== []) {
        while ($pendientes !== [] && count($enCurso) < $simultaneos) {
            $ip = array_shift($pendientes);
            $enCurso[$ip] = iniciarPing($ip);
        }

        $vencido = hrtime(true) >= $limite;

        foreach ($enCurso as $ip => $proceso) {
            $salida = $vencido ? terminarPing($proceso) : leerPingTerminado($proceso);

            if ($salida !== null) {
                $resultados[$ip] = interpretarSalidaDePing($salida);
                unset($enCurso[$ip]);
            }
        }

        if ($enCurso !== []) {
            usleep(PAUSA_ENTRE_REVISIONES_US);
        }
    }

    return $resultados;
}

/**
 * Inicia un ping (comando de comun/ping.php, IP ya validada y con escapeshellarg).
 * Devuelve ['recurso', 'salida' (pipe)], o null si el proceso no pudo iniciarse.
 */
function iniciarPing(string $ip): ?array
{
    $recurso = proc_open(construirComandoPing($ip) . ' 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);

    if (!is_resource($recurso)) {
        return null;
    }

    fclose($pipes[0]);

    return ['recurso' => $recurso, 'salida' => $pipes[1]];
}

/**
 * Si el ping ya terminó, devuelve su salida en UTF-8 y cierra el proceso;
 * si sigue corriendo, null. Un proceso que no pudo iniciarse devuelve "".
 */
function leerPingTerminado(?array $proceso): ?string
{
    if ($proceso === null) {
        return '';
    }

    if (proc_get_status($proceso['recurso'])['running']) {
        return null;
    }

    $salida = (string) stream_get_contents($proceso['salida']);
    fclose($proceso['salida']);
    proc_close($proceso['recurso']);

    return convertirAUtf8(trim($salida));
}

/**
 * Termina un ping que pasó del límite. Devuelve "" (cuenta como sin respuesta).
 */
function terminarPing(?array $proceso): string
{
    if ($proceso !== null) {
        proc_terminate($proceso['recurso']);
        fclose($proceso['salida']);
        proc_close($proceso['recurso']);
    }

    return '';
}

/**
 * La misma lectura que hacerPing(): responde solo si la salida trae "TTL=".
 */
function interpretarSalidaDePing(string $salida): array
{
    $responde = salidaIndicaRespuesta($salida);

    return [
        'responde' => $responde,
        'tiempo_respuesta_ms' => $responde ? extraerTiempoMs($salida) : null,
    ];
}
