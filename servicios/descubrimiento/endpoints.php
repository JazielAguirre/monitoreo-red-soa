<?php
/**
 * Endpoints del Servicio de Descubrimiento (docs/API.md, "Servicio de Descubrimiento").
 * Cada función atiende una ruta y termina respondiendo.
 *
 * No hay datos.php: el servicio no tiene tablas. Sus datos vienen del sistema
 * operativo (ping y tabla ARP) y, los registrados, del Servicio de Dispositivos.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/red.php';
require_once __DIR__ . '/escaneo.php';
require_once __DIR__ . '/arp.php';
require_once __DIR__ . '/dns.php';
require_once __DIR__ . '/clientes.php';
require_once __DIR__ . '/simulacion.php';

const MENSAJE_SIN_RED = 'No se detectó una red local. Conecta la computadora a una red WiFi o cableada.';
const MENSAJE_RED_NO_PRIVADA = 'Solo se pueden escanear redes privadas (10.x, 172.16–31.x o 192.168.x).';
const MENSAJE_LOTE_NO_EXISTE = 'El lote no existe';

/**
 * GET /descubrimiento/red [Interfaz]: la red que se escanea. No envía paquetes.
 */
function consultarRed(): never
{
    responder(obtenerRedOResponder503());
}

/**
 * POST /descubrimiento/red/lotes/{n} [Interfaz]: ping en paralelo a las
 * direcciones del lote, tabla ARP, nombres y registrados.
 */
function escanearLote(int $numero): never
{
    $red = obtenerRedOResponder503();
    $direcciones = direccionesDelLote(prefijoDeRed($red['ip_propia']), $numero)
        ?? responderError(MENSAJE_LOTE_NO_EXISTE, 404);

    // Primero Dispositivos: si no responde, el 502 llega sin haber enviado nada a la red.
    $registrados = obtenerDispositivosRegistrados();

    $pings = hacerPingsEnParalelo($direcciones);
    $arp = array_intersect_key(tablaArpDelServicio(), array_flip($direcciones));
    $encontradas = array_values(array_filter(
        $direcciones,
        fn(string $ip): bool => $pings[$ip]['responde'] || isset($arp[$ip])
    ));
    $nombres = buscarNombres($encontradas, servidoresDns($red['puerta_enlace']));

    responder([
        'lote' => $numero,
        'desde' => $direcciones[0],
        'hasta' => $direcciones[count($direcciones) - 1],
        'encontrados' => array_map(
            fn(string $ip): array => armarEquipo($ip, $red, $pings[$ip], $arp[$ip] ?? null, $nombres[$ip] ?? null, $registrados[$ip] ?? null),
            $encontradas
        ),
    ]);
}

/**
 * La red de esta computadora (o la simulada de las pruebas), o 503 si no hay
 * red o la IP propia no es privada.
 */
function obtenerRedOResponder503(): array
{
    $simulada = leerRedSimulada();
    $ipPropia = $simulada !== null ? $simulada['ip_propia'] : detectarIpPropia();

    if ($ipPropia === null) {
        responderError(MENSAJE_SIN_RED, 503);
    }

    $esRedDePrueba = $simulada !== null && in_array($ipPropia, REDES_DE_PRUEBA, true);
    if (!$esRedDePrueba && !esIpPrivada($ipPropia)) {
        responderError(MENSAJE_RED_NO_PRIVADA, 503);
    }

    return describirRed($ipPropia, $simulada !== null ? $simulada['puerta_enlace'] : detectarPuertaDeEnlace());
}

/**
 * El objeto equipo de la API.
 */
function armarEquipo(string $ip, array $red, array $ping, ?string $mac, ?string $nombre, ?array $dispositivo): array
{
    return [
        'ip' => $ip,
        'mac' => $mac,
        'nombre' => $nombre,
        'responde_ping' => $ping['responde'],
        'tiempo_respuesta_ms' => $ping['tiempo_respuesta_ms'],
        'en_arp' => $mac !== null,
        'es_esta_computadora' => $ip === $red['ip_propia'],
        'es_puerta_enlace' => $ip === $red['puerta_enlace'],
        'dispositivo' => $dispositivo,
    ];
}
