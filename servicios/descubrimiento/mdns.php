<?php
/**
 * Nombre de iPhone, Mac, Linux (Avahi) e impresoras por mDNS: la misma consulta
 * PTR inversa que el DNS (192.168.1.20 → 20.1.168.192.in-addr.arpa), pero
 * enviada directamente a la IP del equipo, puerto 5353, en lugar de a toda la
 * red (multicast).
 *
 * Como la consulta sale de un puerto distinto del 5353, el equipo la trata como
 * "consulta unicast heredada" (RFC 6762, 6.7): contesta solo a quien preguntó,
 * desde el 5353 y con el mismo id. Por eso el firewall local (ufw) deja pasar la
 * respuesta, que no pasaría si llegara por multicast. La respuesta tiene el
 * formato DNS y se lee con leerRespuestaPtr() de dns.php. La envía y espera
 * nombres.php, junto con los demás métodos.
 */

declare(strict_types=1);

require_once __DIR__ . '/dns.php';

const PUERTO_MDNS = 5353;
const BANDERAS_CONSULTA_MDNS = 0x0000; // mDNS no usa "recursión deseada"

function construirConsultaMdns(int $id, string $ip): string
{
    return construirConsultaPtr($id, $ip, BANDERAS_CONSULTA_MDNS);
}

/**
 * El nombre de la respuesta, con las mismas reglas que el DNS inverso
 * (esNombreDeEquipo()): "MacBook-de-Ana.local". null si no es válida.
 */
function leerRespuestaMdns(string $paquete, int $idEsperado): ?string
{
    return leerRespuestaPtr($paquete, $idEsperado);
}
