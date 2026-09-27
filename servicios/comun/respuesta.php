<?php
/**
 * Helpers para enviar respuestas JSON con el formato común de la API.
 * Toda salida de los servicios pasa por estas funciones.
 */

declare(strict_types=1);

/**
 * Envía una respuesta exitosa: { "ok": true, "data": ... } y termina.
 */
function responder(mixed $datos, int $codigo = 200): never
{
    enviarJson(['ok' => true, 'data' => $datos], $codigo);
}

/**
 * Envía una respuesta de error: { "ok": false, "error": "..." } y termina.
 */
function responderError(string $mensaje, int $codigo): never
{
    enviarJson(['ok' => false, 'error' => $mensaje], $codigo);
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
