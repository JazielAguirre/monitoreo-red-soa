<?php
/**
 * Genera historial real antes de la demo: hace varias rondas de «Monitorear todos»
 * con un intervalo fijo, para que el historial y las gráficas tengan datos.
 *
 *   /opt/lampp/bin/php herramientas/monitorear-rondas.php --rondas=12 --cada=60   (12 minutos)
 *   /opt/lampp/bin/php herramientas/monitorear-rondas.php --rondas=12 --cada=300  (una hora)
 *   (Windows: C:/xampp/php/php.exe herramientas/monitorear-rondas.php …)
 *
 * Es un cliente más de los servicios, como el navegador: en cada ronda pide la lista
 * con GET /dispositivos y monitorea uno por uno con POST /monitoreo/{id}. No usa SQL.
 * Se autentica con la clave interna de comun/config.php (como un servicio), así que
 * no depende de una sesión del navegador. Los dispositivos que no responden generan
 * alertas, igual que con «Monitorear todos».
 *
 * Los teléfonos registrados deben tener la pantalla encendida: muchos no responden
 * al ping con la pantalla apagada.
 *
 * Si Apache o un servicio deja de responder, si la clave se rechaza, si una ronda
 * falla completa o si la computadora se queda sin red, lo dice y termina (nunca se
 * queda esperando: cada petición espera como máximo TIEMPO_ESPERA_PETICION segundos).
 * Al terminar (también con Ctrl+C) muestra el resumen: cuántos monitoreos guardó y
 * qué dispositivos respondieron en cada ronda.
 *
 * Termina con código 0 si hizo todas las rondas, 1 si se detuvo antes y 2 si las
 * opciones no son válidas.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../servicios/comun/config.php';
require_once __DIR__ . '/../servicios/comun/http.php'; // cabeceraDePruebas(): solo marca el tráfico si la lanzan las pruebas
require_once __DIR__ . '/red.php'; // detectarIpPropia(): para saber si la computadora sigue en una red

const RONDAS_MAXIMAS = 100;
const CADA_MINIMO = 5;      // segundos
const CADA_MAXIMO = 3600;
const TIEMPO_ESPERA_PETICION = 15; // un monitoreo tarda ~1 s; con margen para las llamadas entre servicios

$opciones = leerOpciones($argv);
if ($opciones === null) {
    fwrite(STDERR, 'Uso: ' . (PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/php/php.exe' : '/opt/lampp/bin/php') . " herramientas/monitorear-rondas.php --rondas=N --cada=SEGUNDOS\n"
        . '  --rondas: de 1 a ' . RONDAS_MAXIMAS . ' (12 si no se indica)' . "\n"
        . '  --cada:   segundos entre el inicio de una ronda y el de la siguiente, de ' . CADA_MINIMO . ' a ' . CADA_MAXIMO . " (60 si no se indica)\n"
        . "  Ejemplo: --rondas=12 --cada=60 deja 12 minutos de historial.\n");
    exit(2);
}

$GLOBALS['resultado'] = ['rondas' => [], 'nombres' => [], 'guardados' => 0, 'alertas' => 0, 'detenido' => null, 'total_rondas' => $opciones['rondas']];
terminarConResumenAlInterrumpir();

printf("Monitoreo en rondas: %d rondas, una cada %s. Ctrl+C para detener (muestra el resumen).\n", $opciones['rondas'], duracion($opciones['cada']));
echo "Recuerda: los teléfonos registrados deben tener la pantalla encendida.\n\n";

$conRed = detectarIpPropia() !== null;
if (!$conRed) {
    echo "⚠ Esta computadora no tiene red: solo responderán los dispositivos de la propia computadora (127.x).\n\n";
}

for ($ronda = 1; $ronda <= $opciones['rondas']; $ronda++) {
    $inicio = microtime(true);

    if ($conRed && detectarIpPropia() === null) {
        detener('La computadora se quedó sin red (se desconectó del WiFi o del hotspot). Conéctala y vuelve a correr la herramienta.');
    }

    hacerRonda($ronda, $opciones['rondas']);

    if ($ronda < $opciones['rondas']) {
        esperar($opciones['cada'] - (microtime(true) - $inicio));
    }
}

mostrarResumen();
exit(0);

// =====================================================================

/**
 * --rondas=N y --cada=SEGUNDOS (enteros). null si algo no es válido.
 */
function leerOpciones(array $argumentos): ?array
{
    $opciones = ['rondas' => 12, 'cada' => 60];

    foreach (array_slice($argumentos, 1) as $argumento) {
        if (preg_match('/^--(rondas|cada)=([1-9][0-9]{0,4})$/', $argumento, $c) !== 1) {
            return null;
        }
        $opciones[$c[1]] = (int) $c[2];
    }

    $valido = $opciones['rondas'] <= RONDAS_MAXIMAS && $opciones['cada'] >= CADA_MINIMO && $opciones['cada'] <= CADA_MAXIMO;

    return $valido ? $opciones : null;
}

/**
 * Una ronda: pide la lista y monitorea cada dispositivo. Se detiene si la lista no
 * llega o si ningún monitoreo de la ronda se pudo hacer.
 */
function hacerRonda(int $ronda, int $total): void
{
    $lista = pedir('GET', URL_DISPOSITIVOS);
    if (!$lista['ok'] || !is_array($lista['data'])) {
        detener('No se pudo pedir la lista de dispositivos: ' . $lista['error']);
    }

    $dispositivos = $lista['data'];
    printf('Ronda %d de %d · %s · %d dispositivos', $ronda, $total, date('H:i:s'), count($dispositivos));
    if ($dispositivos === []) {
        echo "\n  ⚠ No hay dispositivos registrados: agrégalos en la pantalla Dispositivos.\n";
        $GLOBALS['resultado']['rondas'][$ronda] = [];
        return;
    }
    echo "\n";

    $resultados = [];
    $errores = [];
    foreach ($dispositivos as $d) {
        $GLOBALS['resultado']['nombres'][$d['id']] = $d['nombre'];
        $r = pedir('POST', URL_MONITOREO . '/' . (int) $d['id']);

        if ($r['ok']) {
            $responde = ($r['data']['monitoreo']['estado'] ?? '') === 'activo';
            $resultados[$d['id']] = $responde ? '✓' : '✗';
            $GLOBALS['resultado']['guardados']++;
            $GLOBALS['resultado']['alertas'] += ($r['data']['alerta_creada'] ?? false) === true ? 1 : 0;
            printf("  %s %-28s %s\n", $resultados[$d['id']], $d['nombre'], $responde ? formatearMs($r['data']['monitoreo']['tiempo_respuesta_ms'] ?? null) : 'sin respuesta');
        } else {
            $resultados[$d['id']] = '!';
            $errores[] = $r['error'];
            printf("  ! %-28s no se pudo monitorear: %s\n", $d['nombre'], $r['error']);
            if (in_array($r['codigo'], [401, 403], true)) {
                $GLOBALS['resultado']['rondas'][$ronda] = $resultados;
                detener('Los servicios rechazaron la clave interna (' . $r['codigo'] . '). Revisa CLAVE_SERVICIO en servicios/comun/config.php.');
            }
        }
    }

    $GLOBALS['resultado']['rondas'][$ronda] = $resultados;

    if (count($errores) === count($dispositivos)) {
        detener('Ningún dispositivo se pudo monitorear en esta ronda: ' . $errores[0]);
    }
}

/**
 * Petición a un servicio con la clave interna y un tiempo de espera máximo.
 * Devuelve ['ok', 'codigo', 'data', 'error'] y nunca se queda esperando.
 */
function pedir(string $metodo, string $url): array
{
    $contexto = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => "Accept: application/json\r\nContent-Type: application/json\r\nX-Servicio-Clave: " . CLAVE_SERVICIO . "\r\n" . cabeceraDePruebas(),
        'content' => '',
        'timeout' => TIEMPO_ESPERA_PETICION,
        'ignore_errors' => true,
    ]]);
    $texto = @file_get_contents($url, false, $contexto);

    if ($texto === false) {
        return ['ok' => false, 'codigo' => 0, 'data' => null, 'error' => 'Apache no respondió (¿está encendido?)'];
    }

    preg_match('/^HTTP\/\S+\s+(\d{3})/', $http_response_header[0] ?? '', $c);
    $codigo = (int) ($c[1] ?? 0);
    $json = json_decode($texto, true);

    if (!is_array($json) || !is_bool($json['ok'] ?? null)) {
        return ['ok' => false, 'codigo' => $codigo, 'data' => null, 'error' => "respuesta inesperada (HTTP $codigo)"];
    }

    return ['ok' => $json['ok'], 'codigo' => $codigo, 'data' => $json['data'] ?? null, 'error' => $json['ok'] ? null : ($json['error'] ?? "HTTP $codigo")];
}

/**
 * Espera entre rondas. Con Ctrl+C se interrumpe la espera y se muestra el resumen.
 * En pasos cortos: en Linux la señal corta el usleep(), pero en Windows el manejador
 * de Ctrl+C solo corre cuando termina el usleep() en curso (con uno solo, hasta 60 s).
 */
function esperar(float $segundos): void
{
    if ($segundos <= 0) {
        return;
    }
    printf("  (siguiente ronda en %s)\n\n", duracion((int) round($segundos)));
    $fin = microtime(true) + $segundos;
    while (($restante = $fin - microtime(true)) > 0) {
        usleep((int) (min($restante, 0.2) * 1e6));
    }
}

/**
 * Detiene las rondas con un mensaje claro, muestra el resumen y termina con código 1.
 */
function detener(string $motivo): never
{
    $GLOBALS['resultado']['detenido'] = $motivo;
    echo "\n✗ Se detuvieron las rondas: $motivo\n";
    mostrarResumen();
    exit(1);
}

/**
 * Resumen: monitoreos guardados, alertas nuevas y, por dispositivo, si respondió
 * en cada ronda (✓ respondió, ✗ no respondió, ! no se pudo monitorear, · no estaba).
 */
function mostrarResumen(): void
{
    $resultado = $GLOBALS['resultado'];
    $rondas = $resultado['rondas'];
    $hechas = count($rondas);

    echo "\nResumen\n";
    printf("  Rondas hechas ............ %d de %d%s\n", $hechas, $resultado['total_rondas'], $resultado['detenido'] !== null ? ' (se detuvo antes)' : '');
    printf("  Monitoreos guardados ..... %d\n", $resultado['guardados']);
    printf("  Alertas nuevas ........... %d\n", $resultado['alertas']);

    if ($resultado['nombres'] === []) {
        return;
    }

    echo "\n  Respondieron en cada ronda (✓ sí, ✗ no, ! error, · no estaba registrado)\n";
    printf("  %-28s %s\n", 'Dispositivo', implode(' ', array_map(fn($n) => str_pad((string) $n, 2, ' ', STR_PAD_LEFT), array_keys($rondas))));
    foreach ($resultado['nombres'] as $id => $nombre) {
        $marcas = array_map(fn(array $ronda): string => ' ' . ($ronda[$id] ?? '·'), $rondas);
        $respondio = count(array_filter($rondas, fn(array $ronda): bool => ($ronda[$id] ?? '') === '✓'));
        printf("  %-28s %s   %d de %d\n", mb_strimwidth($nombre, 0, 28, '…'), implode(' ', $marcas), $respondio, $hechas);
    }
}

/**
 * Con Ctrl+C (o SIGTERM) muestra el resumen antes de salir. En Windows, sin pcntl,
 * con el manejador de la consola (Ctrl+C y Ctrl+Break).
 */
function terminarConResumenAlInterrumpir(): void
{
    $alInterrumpir = function (): void {
        echo "\n\nDetenido con Ctrl+C.\n";
        mostrarResumen();
        exit(1);
    };

    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $alInterrumpir);
        pcntl_signal(SIGTERM, $alInterrumpir);
    } elseif (function_exists('sapi_windows_set_ctrl_handler')) {
        sapi_windows_set_ctrl_handler($alInterrumpir);
    }
}

function formatearMs(mixed $ms): string
{
    return !is_numeric($ms) ? '—' : ((float) $ms === 0.0 ? '< 1 ms' : round((float) $ms, 2) . ' ms');
}

/**
 * 90 → "1 min 30 s"; 60 → "1 min"; 45 → "45 s".
 */
function duracion(int $segundos): string
{
    $minutos = intdiv($segundos, 60);
    $resto = $segundos % 60;

    return trim(($minutos > 0 ? "$minutos min " : '') . ($resto > 0 || $minutos === 0 ? "$resto s" : ''));
}
