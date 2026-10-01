<?php
/**
 * Detección de la red donde se presenta la demo (herramientas/preparar-demo.php):
 * la puerta de enlace (hace de "Router principal") y la IP propia de esta
 * computadora (hace de "PC Recepción" y es la dirección para el celular).
 *
 * Las funciones (detectarIpPropia(), detectarPuertaDeEnlace()) viven desde la
 * Fase 9 en el Servicio de Descubrimiento, que las usa para calcular la red que
 * escanea: así la demo y el escáner ven la misma red.
 *
 * Solo línea de comandos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../servicios/descubrimiento/red.php';
