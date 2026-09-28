<?php
/**
 * Cliente HTTP para que un servicio pida datos o acciones a otro servicio.
 * Usa los streams de PHP (no la extensión curl) para funcionar igual en
 * Windows y Linux, y envía la cabecera X-Servicio-Clave en cada llamada.
 *
 * Distingue tres casos:
 * - El servicio respondió con el formato común (con cualquier código): se
 *   devuelve la respuesta y quien llama decide qué hacer con el código.
 * - No hubo respuesta o se agotó el tiempo: ErrorDeComunicacion (→ 502).
 * - La respuesta no es el JSON esperado: ErrorDeComunicacion (→ 502).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Fallo simulado, SOLO para las pruebas automáticas (docs/PLAN.md, "Pruebas
 * automáticas"): si existe este archivo, vigente, las llamadas al servicio que
 * indica se desvían a un destino que no responde o que no devuelve JSON.
 * - Solo lo crea pruebas/monitoreo.php; ningún endpoint escribe archivos y
 *   pruebas/ no es accesible por HTTP, así que no se puede activar desde una petición.
 * - Caduca solo: se ignora si "expira" ya pasó o está a más de 60 s en el futuro.
 * - Solo admite modos fijos hacia 127.0.0.1 o la propia interfaz, nunca URLs libres.
 */
const ARCHIVO_FALLO_SIMULADO = __DIR__ . '/../../pruebas/.fallo-simulado.json';
const VIGENCIA_MAXIMA_FALLO_SIMULADO = 60;

/**
 * Otro servicio no respondió o respondió algo inesperado. Lleva el nombre del
 * servicio para el mensaje "No se pudo comunicar con el Servicio de {nombre}".
 */
final class ErrorDeComunicacion extends RuntimeException
{
    public function __construct(public readonly string $servicio, string $detalle)
    {
        parent::__construct("[$servicio] $detalle");
    }
}

/**
 * Llama a otro servicio y devuelve ['codigo', 'ok', 'data', 'error'].
 *
 * @throws ErrorDeComunicacion si no hay respuesta, se agota el tiempo o la
 *                             respuesta no tiene el formato común.
 */
function llamarServicio(string $servicio, string $metodo, string $url, ?array $cuerpo = null): array
{
    $url = aplicarFalloSimulado($servicio, $metodo, $url);

    $contexto = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => "Content-Type: application/json\r\n"
            . "Accept: application/json\r\n"
            . 'X-Servicio-Clave: ' . CLAVE_SERVICIO . "\r\n",
        'content' => $cuerpo === null ? '' : json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'timeout' => TIEMPO_ESPERA_SERVICIOS,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);

    $texto = @file_get_contents($url, false, $contexto);

    if ($texto === false) {
        $motivo = error_get_last()['message'] ?? 'sin detalle';
        throw new ErrorDeComunicacion($servicio, "Sin respuesta de $metodo $url: $motivo");
    }

    $codigo = codigoHttp($http_response_header ?? []);
    $json = json_decode($texto, true);

    if (!esRespuestaDelFormatoComun($json)) {
        throw new ErrorDeComunicacion($servicio, "Respuesta inesperada ($codigo) de $metodo $url: " . substr($texto, 0, 200));
    }

    return ['codigo' => $codigo, 'ok' => $json['ok'], 'data' => $json['data'] ?? null, 'error' => $json['error'] ?? null];
}

/**
 * Código HTTP de la primera línea de las cabeceras de respuesta.
 */
function codigoHttp(array $cabeceras): int
{
    return preg_match('/^HTTP\/\S+\s+(\d{3})/', $cabeceras[0] ?? '', $coincidencia) === 1 ? (int) $coincidencia[1] : 0;
}

/**
 * { "ok": true, "data": ... } o { "ok": false, "error": "..." }.
 */
function esRespuestaDelFormatoComun(mixed $json): bool
{
    if (!is_array($json) || !is_bool($json['ok'] ?? null)) {
        return false;
    }

    return $json['ok'] ? array_key_exists('data', $json) : is_string($json['error'] ?? null);
}

/**
 * Devuelve la URL a la que hay que llamar: la original, o el destino del
 * fallo simulado si hay uno vigente para este servicio (y método, si lo indica).
 */
function aplicarFalloSimulado(string $servicio, string $metodo, string $url): string
{
    $fallo = leerFalloSimulado();

    if ($fallo === null || $fallo['servicio'] !== $servicio
        || (isset($fallo['metodo']) && $fallo['metodo'] !== $metodo)) {
        return $url;
    }

    return $fallo['modo'] === 'sin_respuesta'
        ? 'http://127.0.0.1:' . $fallo['puerto'] . '/'
        : URL_INTERFAZ . '/index.html';
}

/**
 * Lee el archivo de fallo simulado. Devuelve null si no existe, no es válido
 * o no está vigente.
 */
function leerFalloSimulado(): ?array
{
    if (!is_file(ARCHIVO_FALLO_SIMULADO)) {
        return null;
    }

    $fallo = json_decode((string) @file_get_contents(ARCHIVO_FALLO_SIMULADO), true);

    if (!is_array($fallo) || !is_string($fallo['servicio'] ?? null)) {
        return null;
    }

    $ahora = time();
    $expira = $fallo['expira'] ?? null;
    $vigente = is_int($expira) && $expira >= $ahora && $expira <= $ahora + VIGENCIA_MAXIMA_FALLO_SIMULADO;
    $metodoValido = !isset($fallo['metodo']) || is_string($fallo['metodo']);
    $modo = $fallo['modo'] ?? null;
    $puerto = $fallo['puerto'] ?? null;
    $modoValido = $modo === 'respuesta_invalida'
        || ($modo === 'sin_respuesta' && is_int($puerto) && $puerto >= 1024 && $puerto <= 65535);

    return $vigente && $metodoValido && $modoValido ? $fallo : null;
}
