<?php
/**
 * Nombre de las computadoras con Windows (y Linux con Samba) por NetBIOS: la
 * "consulta de estado del nodo" (RFC 1002, 4.2.17 y 4.2.18) se envía por UDP
 * directamente a la IP del equipo, puerto 137, y él contesta con la lista de
 * nombres que tiene registrados. El que interesa es el de la computadora:
 * sufijo <00> y único (no de grupo, como WORKGROUP).
 *
 * El equipo contesta desde el puerto 137 al puerto de origen de la consulta,
 * así que el firewall local (ufw) deja pasar la respuesta. La envía y espera
 * nombres.php, junto con los demás métodos.
 */

declare(strict_types=1);

require_once __DIR__ . '/dns.php';

const PUERTO_NETBIOS = 137;
const TIPO_NBSTAT = 0x21;
const LONGITUD_NOMBRE_NETBIOS = 15;
const SUFIJO_ESTACION_DE_TRABAJO = 0x00;
const SUFIJO_SERVIDOR_DE_ARCHIVOS = 0x20;
const BANDERA_NOMBRE_DE_GRUPO = 0x8000;

/**
 * Consulta de estado del nodo: el nombre comodín "*" (rellenado con ceros a 16
 * bytes y codificado en "primer nivel": cada medio byte → una letra A–P), tipo
 * NBSTAT, clase IN.
 */
function construirConsultaNetbios(int $id): string
{
    $codificado = '';
    foreach (str_split(str_pad('*', 16, "\0")) as $caracter) {
        $codificado .= chr(0x41 + (ord($caracter) >> 4)) . chr(0x41 + (ord($caracter) & 0x0F));
    }

    return pack('nnnnnn', $id, 0x0000, 1, 0, 0, 0) . "\x20" . $codificado . "\x00" . pack('nn', TIPO_NBSTAT, 1);
}

/**
 * Lee el nombre de la computadora de la respuesta, o null si el paquete no es
 * la respuesta a esta consulta, está mal formado o no trae un nombre válido.
 * Prefiere el nombre único <00>; si no hay, el único <20>.
 */
function leerRespuestaNetbios(string $paquete, int $idEsperado): ?string
{
    if (strlen($paquete) < 12) {
        return null;
    }

    [, $id, $banderas, $preguntas, $respuestas] = unpack('n5', $paquete);
    $esRespuesta = ($banderas & 0x8000) !== 0;
    $sinError = ($banderas & 0x000F) === 0;

    if ($id !== $idEsperado || !$esRespuesta || !$sinError || $respuestas < 1) {
        return null;
    }

    $posicion = 12;
    for ($i = 0; $i < $preguntas; $i++) {
        if (leerNombreDns($paquete, $posicion) === null) {
            return null;
        }
        $posicion += 4;
    }

    if (leerNombreDns($paquete, $posicion) === null || $posicion + 10 > strlen($paquete)) {
        return null;
    }
    ['tipo' => $tipo, 'largo' => $largo] = unpack('ntipo/nclase/Nttl/nlargo', $paquete, $posicion);
    $posicion += 10;

    if ($tipo !== TIPO_NBSTAT || $largo < 1 || $posicion + $largo > strlen($paquete)) {
        return null;
    }

    return elegirNombreNetbios(substr($paquete, $posicion, $largo));
}

/**
 * De la lista de nombres del equipo (1 byte con la cantidad y 18 bytes por
 * nombre: 15 del nombre, 1 de sufijo y 2 de banderas), el de la computadora.
 */
function elegirNombreNetbios(string $datos): ?string
{
    $cantidad = ord($datos[0]);
    if ($cantidad < 1 || 1 + $cantidad * 18 > strlen($datos)) {
        return null;
    }

    $porSufijo = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $entrada = substr($datos, 1 + $i * 18, 18);
        $sufijo = ord($entrada[15]);
        $esDeGrupo = (unpack('n', $entrada, 16)[1] & BANDERA_NOMBRE_DE_GRUPO) !== 0;
        if (!$esDeGrupo && !isset($porSufijo[$sufijo])) {
            $porSufijo[$sufijo] = substr($entrada, 0, LONGITUD_NOMBRE_NETBIOS);
        }
    }

    $nombre = $porSufijo[SUFIJO_ESTACION_DE_TRABAJO] ?? $porSufijo[SUFIJO_SERVIDOR_DE_ARCHIVOS] ?? null;

    return $nombre === null ? null : limpiarNombreNetbios($nombre);
}

/**
 * Quita el relleno (espacios y ceros al final). Solo se acepta ASCII imprimible:
 * un nombre con bytes de control o de otra página de códigos se descarta.
 * Caracteres como < o & sí pasan (NetBIOS los permite): la interfaz los muestra
 * como texto.
 */
function limpiarNombreNetbios(string $nombre): ?string
{
    $nombre = rtrim($nombre, " \0");

    return preg_match('/^[\x21-\x7E](?:[\x20-\x7E]*[\x21-\x7E])?$/', $nombre) === 1 ? $nombre : null;
}
