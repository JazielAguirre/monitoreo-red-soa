<?php
/**
 * Helpers para enviar respuestas JSON con el formato común de la API.
 * Toda salida de los servicios pasa por estas funciones.
 */

declare(strict_types=1);

/**
 * Evita que un aviso de PHP se imprima como texto y rompa el JSON: no muestra
 * errores (el php.ini de XAMPP para Windows trae display_errors=On) y convierte
 * los warnings en excepciones, que el punto de entrada responde como 500.
 */
function configurarErrores(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    set_error_handler(function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
        if (!(error_reporting() & $nivel)) {
            return false;
        }

        throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
    });
}

/**
 * Envía una respuesta exitosa: { "ok": true, "data": ... } y termina.
 */
function responder(mixed $datos, int $codigo = 200): never
{
    enviarJson(['ok' => true, 'data' => $datos], $codigo);
}

/**
 * Envía una respuesta de error: { "ok": false, "error": "..." } y termina.
 * En errores de validación, $campos agrega un mensaje por campo:
 * { "ok": false, "error": "...", "campos": { "ip": "Dirección IP no válida" } }
 */
function responderError(string $mensaje, int $codigo, ?array $campos = null): never
{
    $cuerpo = ['ok' => false, 'error' => $mensaje];

    if ($campos !== null) {
        $cuerpo['campos'] = $campos;
    }

    enviarJson($cuerpo, $codigo);
}

/**
 * Escribe el cuerpo JSON con su código HTTP y cabecera, y termina la ejecución.
 */
function enviarJson(array $cuerpo, int $codigo): never
{
    // XAMPP trae serialize_precision=100: sin esto 0.18 sale como 0.17999999999999999333...
    ini_set('serialize_precision', '-1');
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
