<?php
/**
 * Nombre legible de televisiones, impresoras, consolas y módems por UPnP:
 *
 * 1. M-SEARCH de SSDP enviado directamente a la IP del equipo, puerto 1900
 *    (no a toda la red). El equipo contesta con LOCATION: la URL de su
 *    descripción en XML.
 * 2. Se descarga esa descripción por HTTP y se lee su friendlyName
 *    ("[TV] Samsung 7 Series (55)", "HP LaserJet M110w").
 *
 * Los nombres y la URL los controla cualquiera que esté en la red, así que:
 * - La URL solo se descarga si es http://, su host es una IPv4 escrita, igual a
 *   la IP que contestó y dentro de la /24 escaneada (ni nombres de dominio ni
 *   usuario@host). No se siguen redirecciones.
 * - La descripción tiene tamaño máximo (TAMANO_MAXIMO_DESCRIPCION) y el tiempo
 *   de todos los métodos (LIMITE_SEGUNDOS_NOMBRES de nombres.php).
 * - Un XML con DOCTYPE o ENTITY se rechaza sin procesarlo (sin entidades
 *   externas ni "billion laughs"); se lee con LIBXML_NONET.
 * - El friendlyName es texto libre: se quitan los caracteres de control y la
 *   interfaz lo muestra siempre como texto, nunca como HTML.
 *
 * Las funciones de aquí solo arman y leen; nombres.php envía, descarga y espera.
 */

declare(strict_types=1);

const PUERTO_SSDP = 1900;
const TAMANO_MAXIMO_DESCRIPCION = 65536; // bytes, con las cabeceras HTTP
const LONGITUD_MAXIMA_NOMBRE_LEGIBLE = 100; // la del campo nombre de Dispositivos

/**
 * M-SEARCH unicast: pregunta solo a ese equipo por su dispositivo raíz.
 */
function construirMSearch(string $ip, int $puerto = PUERTO_SSDP): string
{
    return "M-SEARCH * HTTP/1.1\r\n"
        . "HOST: $ip:$puerto\r\n"
        . "MAN: \"ssdp:discover\"\r\n"
        . "MX: 1\r\n"
        . "ST: upnp:rootdevice\r\n"
        . "\r\n";
}

/**
 * La cabecera LOCATION de la respuesta de SSDP, o null si no es una respuesta
 * "200 OK" o no la trae.
 */
function leerLocationSsdp(string $respuesta): ?string
{
    if (preg_match('~^HTTP/1\.[01] 200\b~', $respuesta) !== 1) {
        return null;
    }

    return preg_match('/^location:[ \t]*(\S+)[ \t]*\r?$/im', $respuesta, $coincidencia) === 1 ? $coincidencia[1] : null;
}

/**
 * Valida la URL de la descripción. Devuelve ['puerto' => int, 'ruta' => string]
 * solo si es http://IP[:puerto][/ruta], con la IP igual a la que contestó y
 * dentro de la /24 escaneada ($prefijoRed, como "192.168.0"); si no, null.
 */
function validarUrlDescripcion(string $url, string $ipQueContesto, string $prefijoRed): ?array
{
    if (preg_match('~^http://(\d{1,3}(?:\.\d{1,3}){3})(?::(\d{1,5}))?(/[\x21-\x7E]*)?$~', $url, $partes) !== 1) {
        return null;
    }

    $host = $partes[1];
    $puerto = ($partes[2] ?? '') === '' ? 80 : (int) $partes[2];
    $esDeLaRed = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && substr($host, 0, (int) strrpos($host, '.')) === $prefijoRed;

    if ($host !== $ipQueContesto || !$esDeLaRed || $puerto < 1 || $puerto > 65535) {
        return null;
    }

    return ['puerto' => $puerto, 'ruta' => ($partes[3] ?? '') === '' ? '/' : $partes[3]];
}

/**
 * Petición HTTP/1.0 (así el equipo no responde "por partes" y cierra al terminar).
 */
function construirPeticionDescripcion(string $ip, int $puerto, string $ruta): string
{
    return "GET $ruta HTTP/1.0\r\nHost: $ip:$puerto\r\nAccept: text/xml\r\nConnection: close\r\n\r\n";
}

/**
 * El friendlyName de la respuesta HTTP completa (cabeceras y cuerpo), o null.
 */
function leerRespuestaDescripcion(string $respuesta): ?string
{
    $cuerpo = cuerpoDeRespuestaHttp($respuesta);

    return $cuerpo === null ? null : leerFriendlyName($cuerpo);
}

/**
 * El cuerpo de una respuesta "200 OK" (deshace "Transfer-Encoding: chunked" si
 * el equipo lo usa aunque se pidió HTTP/1.0), o null.
 */
function cuerpoDeRespuestaHttp(string $respuesta): ?string
{
    if (strlen($respuesta) > TAMANO_MAXIMO_DESCRIPCION || preg_match('~^HTTP/1\.[01] 200\b~', $respuesta) !== 1) {
        return null;
    }

    $separacion = strpos($respuesta, "\r\n\r\n");
    if ($separacion === false) {
        return null;
    }
    $cabeceras = substr($respuesta, 0, $separacion);
    $cuerpo = substr($respuesta, $separacion + 4);

    return preg_match('/^transfer-encoding:[ \t]*chunked[ \t]*$/im', $cabeceras) === 1 ? deshacerChunked($cuerpo) : $cuerpo;
}

/**
 * Une las partes de un cuerpo "chunked", o null si está mal formado.
 */
function deshacerChunked(string $cuerpo): ?string
{
    $resultado = '';
    $posicion = 0;

    while (true) {
        $finLinea = strpos($cuerpo, "\r\n", $posicion);
        if ($finLinea === false || preg_match('/^([0-9a-fA-F]{1,6})(?:;.*)?$/', substr($cuerpo, $posicion, $finLinea - $posicion), $tamano) !== 1) {
            return null;
        }
        $largo = (int) hexdec($tamano[1]);
        if ($largo === 0) {
            return $resultado;
        }
        if ($finLinea + 2 + $largo > strlen($cuerpo)) {
            return null;
        }
        $resultado .= substr($cuerpo, $finLinea + 2, $largo);
        $posicion = $finLinea + 2 + $largo + 2; // datos y su \r\n
    }
}

/**
 * El friendlyName del dispositivo raíz (/root/device/friendlyName, con o sin el
 * espacio de nombres de UPnP), o null si el XML no es válido, trae DOCTYPE o
 * ENTITY, o no tiene un nombre válido.
 */
function leerFriendlyName(string $xml): ?string
{
    if ($xml === '' || strlen($xml) > TAMANO_MAXIMO_DESCRIPCION || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml) === 1) {
        return null;
    }

    $erroresPrevios = libxml_use_internal_errors(true);
    $documento = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($erroresPrevios);

    if ($documento === false) {
        return null;
    }

    $nodos = $documento->xpath('/*[local-name()="root"]/*[local-name()="device"]/*[local-name()="friendlyName"]');

    return is_array($nodos) && $nodos !== [] ? limpiarNombreLegible((string) $nodos[0]) : null;
}

/**
 * Texto libre de un nombre para mostrar: UTF-8 válido, sin caracteres de
 * control ni de formato (como los que invierten la dirección del texto), con
 * los espacios compactados y máximo LONGITUD_MAXIMA_NOMBRE_LEGIBLE caracteres.
 * null si queda vacío o no es UTF-8.
 */
function limpiarNombreLegible(string $texto): ?string
{
    if (preg_match('//u', $texto) !== 1) {
        return null;
    }

    $limpio = trim((string) preg_replace(['/\s+/u', '/[\p{Cc}\p{Cf}\p{Co}\p{Cs}]/u', '/ {2,}/'], [' ', '', ' '], $texto));

    return $limpio === '' ? null : mb_substr($limpio, 0, LONGITUD_MAXIMA_NOMBRE_LEGIBLE);
}
