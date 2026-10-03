<?php
/**
 * Cliente HTTP para que un servicio pida datos o acciones a otro servicio.
 * Usa los streams de PHP (no la extensión curl) para funcionar igual en
 * Windows y Linux, y envía la cabecera X-Servicio-Clave en cada llamada.
 * Nunca reenvía la cookie de sesión del navegador: entre servicios la única
 * credencial es la clave (docs/API.md, "Acceso").
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
    $inicio = hrtime(true);

    try {
        $respuesta = enviarPeticion($servicio, $metodo, aplicarFalloSimulado($servicio, $metodo, $url), $cuerpo);
    } catch (ErrorDeComunicacion $error) {
        $motivo = str_starts_with($error->getMessage(), "[$servicio] Sin respuesta") ? 'sin respuesta' : 'respuesta inesperada';
        registrarLlamada($servicio, $metodo, $url, $motivo, $inicio);
        throw $error;
    }

    registrarLlamada($servicio, $metodo, $url, (string) $respuesta['codigo'], $inicio);

    return $respuesta;
}

/**
 * Envía la petición y valida el formato común de la respuesta.
 *
 * @throws ErrorDeComunicacion
 */
function enviarPeticion(string $servicio, string $metodo, string $url, ?array $cuerpo): array
{

    $contexto = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => "Content-Type: application/json\r\n"
            . "Accept: application/json\r\n"
            . 'X-Servicio-Clave: ' . CLAVE_SERVICIO . "\r\n"
            . cabeceraDePruebas(),
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
 * "X-Pruebas-Automaticas: 1" si la petición que se atiende (o el proceso) es de las
 * pruebas, para que el otro servicio tampoco caiga nunca en la base del usuario
 * (ver comun/db.php). Vacío en el uso normal.
 */
function cabeceraDePruebas(): string
{
    return isset($_SERVER['HTTP_X_PRUEBAS_AUTOMATICAS']) || getenv('MONITOREO_PRUEBAS') === '1' ? "X-Pruebas-Automaticas: 1\r\n" : '';
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

// ---------------------------------------------------------------- Registro de llamadas

/**
 * Registro legible de las llamadas entre servicios, para ver la arquitectura en
 * acción durante la demo (herramientas/ver-llamadas.php lo muestra en vivo):
 *
 *   [10:05:12] Navegador → Monitoreo: POST /monitoreo/7
 *   [10:05:12]   Monitoreo → Dispositivos: GET /dispositivos/7 (200, 8 ms)
 *   [10:05:13]   Monitoreo → Dispositivos: PATCH /dispositivos/7/estado (200, 12 ms)
 *   [10:05:13]   Monitoreo → Alertas: POST /alertas (201, 10 ms)
 *
 * El log de Apache no sirve para esto: el navegador de la laptop y los servicios
 * aparecen con la misma IP (127.0.0.1), y cada petición se anota al terminar, así
 * que la del navegador sale después de sus propias llamadas.
 *
 * Va en la carpeta de registros de PHP (junto al error_log de php.ini), donde
 * Apache ya puede escribir en Linux y en Windows. Si no se puede escribir, se
 * ignora: el registro nunca debe hacer fallar una petición.
 */
function registrarLlamada(string $servicio, string $metodo, string $url, string $resultado, int $inicio): void
{
    static $origenAnotado = false;

    try {
        $hora = date('H:i:s');
        $lineas = '';
        if (!$origenAnotado) {
            $origenAnotado = true;
            $lineas .= "[$hora] " . origenDeLaPeticion() . ' → ' . nombreDeEsteServicio() . ': '
                . ($_SERVER['REQUEST_METHOD'] ?? '¿?') . ' ' . rutaDeServicio((string) ($_SERVER['REQUEST_URI'] ?? '')) . "\n";
        }
        $milisegundos = (int) round((hrtime(true) - $inicio) / 1e6);
        $lineas .= "[$hora]   " . nombreDeEsteServicio() . " → $servicio: $metodo " . rutaDeServicio($url) . " ($resultado, $milisegundos ms)\n";

        @file_put_contents(archivoRegistroLlamadas(), $lineas, FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        // El registro es solo para mirar; si falla, la petición sigue.
    }
}

/**
 * Ruta del archivo de registro: llamadas-servicios.log en la carpeta del error_log
 * de PHP (XAMPP: /opt/lampp/logs o C:\xampp\php\logs), o la carpeta temporal.
 */
function archivoRegistroLlamadas(): string
{
    $errorLog = (string) ini_get('error_log');
    $carpeta = $errorLog !== '' && $errorLog !== 'syslog' ? dirname($errorLog) : sys_get_temp_dir();

    return $carpeta . DIRECTORY_SEPARATOR . 'llamadas-servicios.log';
}

/**
 * "Navegador" si la petición llegó con sesión; "Servicio" si trae la clave de servicio.
 */
function origenDeLaPeticion(): string
{
    return isset($_SERVER['HTTP_X_SERVICIO_CLAVE']) ? 'Servicio' : 'Navegador';
}

/**
 * Nombre del servicio que atiende la petición (NOMBRE_SERVICIO de su index.php).
 */
function nombreDeEsteServicio(): string
{
    return defined('NOMBRE_SERVICIO') ? ucfirst((string) constant('NOMBRE_SERVICIO')) : 'Servicio';
}

/**
 * /monitoreo-red-soa/servicios/dispositivos/7?x=1 → /dispositivos/7?x=1
 */
function rutaDeServicio(string $url): string
{
    $ruta = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $consulta = parse_url($url, PHP_URL_QUERY);
    $base = (string) parse_url(URL_SERVICIOS, PHP_URL_PATH);

    if ($base !== '' && str_starts_with($ruta, $base)) {
        $ruta = substr($ruta, strlen($base));
    }

    return $ruta . ($consulta ? "?$consulta" : '');
}
