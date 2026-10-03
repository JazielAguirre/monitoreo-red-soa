<?php
/**
 * Respondedor falso de nombres para las pruebas del escáner (lo lanzan
 * pruebas/descubrimiento.php y pruebas/interfaz.php con iniciarRespondedorDeNombres()
 * de ayudas.php). Contesta NetBIOS, mDNS y SSDP, y sirve descripciones UPnP por
 * HTTP, con bytes fijos, en direcciones 127.0.0.x: nunca escucha ni envía nada
 * fuera de la propia computadora.
 *
 * Lee la configuración en JSON de la entrada estándar:
 *   {
 *     "expira": 1790000000,                       // termina solo (máximo 60 s)
 *     "puertos": {"netbios": 40137, "mdns": 45353, "ssdp": 41900},
 *     "equipos": {
 *       "127.0.0.2": {
 *         "netbios": "<base64>", "mdns": "<base64>",   // respuesta; sus 2 primeros bytes se cambian por el id de la consulta
 *         "ssdp": "<base64>",                          // respuesta tal cual
 *         "desde": "127.0.0.20",                       // opcional: contesta desde otra IP (respuesta falsificada)
 *         "http": {"puerto": 48080, "respuesta": "<base64>"} o {"puerto": 48080, "colgar": true}
 *       }
 *     }
 *   }
 * Escribe "listo" cuando ya escucha y una línea "http IP LÍNEA-DE-PETICIÓN" por
 * cada petición HTTP recibida (así la prueba sabe qué se descargó).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const VIGENCIA_MAXIMA_RESPONDEDOR = 60;

$configuracion = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($configuracion) || !is_int($configuracion['expira'] ?? null) || !is_array($configuracion['equipos'] ?? null)) {
    fwrite(STDOUT, "error: configuración no válida\n");
    exit(1);
}
$expira = min($configuracion['expira'], time() + VIGENCIA_MAXIMA_RESPONDEDOR);

$escuchas = []; // id del socket → ['socket', 'tipo' (udp|tcp), 'ip', 'metodo', 'equipo']
$clientes = []; // id del socket → ['socket', 'ip', 'equipo', 'peticion']

/**
 * Solo direcciones 127.0.0.x: el respondedor nunca escucha en la red.
 */
function esDireccionLocal(mixed $ip): bool
{
    return is_string($ip) && preg_match('/^127\.0\.0\.\d{1,3}$/', $ip) === 1;
}

function escuchar(string $url, int $banderas): mixed
{
    $socket = @stream_socket_server($url, $codigo, $mensaje, $banderas);
    if ($socket === false) {
        fwrite(STDOUT, "error: no se pudo escuchar en $url ($mensaje)\n");
        exit(1);
    }
    stream_set_blocking($socket, false);

    return $socket;
}

foreach ($configuracion['equipos'] as $ip => $equipo) {
    if (!esDireccionLocal($ip) || (isset($equipo['desde']) && !esDireccionLocal($equipo['desde']))) {
        fwrite(STDOUT, "error: solo se admiten direcciones 127.0.0.x\n");
        exit(1);
    }
    foreach (['netbios', 'mdns', 'ssdp'] as $metodo) {
        if (isset($equipo[$metodo])) {
            $socket = escuchar("udp://$ip:" . (int) $configuracion['puertos'][$metodo], STREAM_SERVER_BIND);
            $escuchas[get_resource_id($socket)] = ['socket' => $socket, 'tipo' => 'udp', 'ip' => $ip, 'metodo' => $metodo, 'equipo' => $equipo];
        }
    }
    if (isset($equipo['desde'])) {
        $configuracion['equipos'][$ip]['socket_desde'] = escuchar("udp://{$equipo['desde']}:0", STREAM_SERVER_BIND);
    }
    if (isset($equipo['http'])) {
        $socket = escuchar("tcp://$ip:" . (int) $equipo['http']['puerto'], STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        $escuchas[get_resource_id($socket)] = ['socket' => $socket, 'tipo' => 'tcp', 'ip' => $ip, 'metodo' => 'http', 'equipo' => $equipo];
    }
}

fwrite(STDOUT, "listo\n");
fflush(STDOUT);

while (time() < $expira) {
    $lectura = [...array_column($escuchas, 'socket'), ...array_column($clientes, 'socket')];
    $escritura = $excepcion = null;
    if (@stream_select($lectura, $escritura, $excepcion, 0, 200000) < 1) {
        continue;
    }

    foreach ($lectura as $socket) {
        $id = get_resource_id($socket);
        if (isset($clientes[$id])) {
            atenderCliente($clientes, $id);
            continue;
        }

        $escucha = $escuchas[$id];
        if ($escucha['tipo'] === 'tcp') {
            $cliente = @stream_socket_accept($socket, 0);
            if ($cliente !== false) {
                $clientes[get_resource_id($cliente)] = ['socket' => $cliente, 'ip' => $escucha['ip'], 'equipo' => $escucha['equipo'], 'peticion' => ''];
            }
            continue;
        }

        $consulta = @stream_socket_recvfrom($socket, 1500, 0, $quien);
        if (!is_string($consulta) || $consulta === '' || !is_string($quien)) {
            continue;
        }
        $respuesta = base64_decode($escucha['equipo'][$escucha['metodo']]);
        if ($escucha['metodo'] !== 'ssdp' && strlen($consulta) >= 2) {
            $respuesta = substr($consulta, 0, 2) . substr($respuesta, 2); // el id de la consulta
        }
        $remitente = $configuracion['equipos'][$escucha['ip']]['socket_desde'] ?? $socket;
        @stream_socket_sendto($remitente, $respuesta, 0, $quien);
    }
}

/**
 * Junta la petición HTTP; al recibirla completa, la anota y contesta (o se
 * queda callado si el equipo "cuelga").
 */
function atenderCliente(array &$clientes, int $id): void
{
    $cliente = &$clientes[$id];
    $datos = @fread($cliente['socket'], 8192);

    if (!is_string($datos) || $datos === '') {
        fclose($cliente['socket']);
        unset($clientes[$id]);
        return;
    }

    $yaCompleta = str_contains($cliente['peticion'], "\r\n\r\n");
    $cliente['peticion'] .= $datos;
    if ($yaCompleta || !str_contains($cliente['peticion'], "\r\n\r\n")) {
        return;
    }

    fwrite(STDOUT, "http {$cliente['ip']} " . strtok($cliente['peticion'], "\r\n") . "\n");
    fflush(STDOUT);

    $http = $cliente['equipo']['http'];
    if (!empty($http['colgar'])) {
        return;
    }

    stream_set_blocking($cliente['socket'], true);
    @fwrite($cliente['socket'], base64_decode((string) ($http['respuesta'] ?? '')));
    fclose($cliente['socket']);
    unset($clientes[$id]);
}
