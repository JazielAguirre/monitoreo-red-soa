<?php
/**
 * Red simulada, SOLO para las pruebas automáticas (docs/PLAN.md, "Pruebas
 * automáticas"): si existe este archivo, vigente, el servicio usa como IP propia
 * la que indica en lugar de detectarla, y lee la tabla ARP del texto que trae en
 * lugar de ejecutar el comando. Los pings son reales.
 *
 * - Solo se escanean dos redes de prueba: 127.0.0.0/24 (todas responden) y
 *   192.0.2.0/24 (documentación: ninguna responde). No sirve para escanear otras.
 * - Para probar los 503 sin desconectar nada: ip_propia null simula que no hay
 *   red, y 198.51.100.1 (documentación, no privada) debe rechazarse como red pública.
 * - Solo lo crea pruebas/descubrimiento.php; ningún endpoint escribe archivos y
 *   pruebas/ no es accesible por HTTP, así que no se puede activar desde una petición.
 * - Caduca solo: se ignora si "expira" ya pasó o está a más de 60 s en el futuro.
 */

declare(strict_types=1);

const ARCHIVO_RED_SIMULADA = __DIR__ . '/../../pruebas/.red-simulada.json';
const VIGENCIA_MAXIMA_RED_SIMULADA = 60;
const REDES_DE_PRUEBA = ['127.0.0.1', '192.0.2.1']; // se escanean aunque no sean privadas
const IPS_PROPIAS_SIMULADAS = [...REDES_DE_PRUEBA, '198.51.100.1', null];
const LONGITUD_MAXIMA_ARP_SIMULADA = 65536;

/**
 * Lee el archivo de red simulada. Devuelve ['ip_propia' (?string: null = sin red),
 * 'puerta_enlace' (?string), 'arp' (?string)] o null si no existe, no es válido
 * o no está vigente.
 */
function leerRedSimulada(): ?array
{
    if (!is_file(ARCHIVO_RED_SIMULADA)) {
        return null;
    }

    $red = json_decode((string) @file_get_contents(ARCHIVO_RED_SIMULADA), true);

    if (!is_array($red)) {
        return null;
    }

    $ahora = time();
    $expira = $red['expira'] ?? null;
    $ipPropia = $red['ip_propia'] ?? null;
    $puerta = $red['puerta_enlace'] ?? null;
    $arp = $red['arp'] ?? null;

    $valida = is_int($expira) && $expira >= $ahora && $expira <= $ahora + VIGENCIA_MAXIMA_RED_SIMULADA
        && in_array($ipPropia, IPS_PROPIAS_SIMULADAS, true)
        && array_key_exists('ip_propia', $red)
        && ($puerta === null || (is_string($puerta) && is_string($ipPropia) && esDeLaMismaRedSimulada($puerta, $ipPropia)))
        && ($arp === null || (is_string($arp) && strlen($arp) <= LONGITUD_MAXIMA_ARP_SIMULADA));

    return $valida ? ['ip_propia' => $ipPropia, 'puerta_enlace' => $puerta, 'arp' => $arp] : null;
}

/**
 * La puerta de enlace simulada debe estar en la misma /24 de prueba.
 */
function esDeLaMismaRedSimulada(string $ip, string $ipPropia): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && substr($ip, 0, (int) strrpos($ip, '.')) === substr($ipPropia, 0, (int) strrpos($ipPropia, '.'));
}
