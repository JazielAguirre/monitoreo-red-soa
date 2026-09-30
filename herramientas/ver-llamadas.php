<?php
/**
 * Muestra en vivo las llamadas entre servicios (registro de comun/http.php) para
 * la parte de SOA de la demo. Funciona igual en Linux, Windows y macOS.
 *
 *   /opt/lampp/bin/php herramientas/ver-llamadas.php          (Windows: C:/xampp/php/php.exe …)
 *   /opt/lampp/bin/php herramientas/ver-llamadas.php --todo   (muestra también lo anterior)
 *
 * Ctrl+C para salir.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../servicios/comun/http.php';

const PAUSA_MICROSEGUNDOS = 250000;

$archivo = archivoRegistroLlamadas();
$desdeElInicio = in_array('--todo', $argv, true);

echo "Llamadas entre servicios ($archivo)\n";
echo "Monitorea un dispositivo en la interfaz para verlas. Ctrl+C para salir.\n\n";

$posicion = null;

while (true) {
    clearstatcache(true, $archivo);

    if (!is_file($archivo)) {
        usleep(PAUSA_MICROSEGUNDOS); // se crea con la primera llamada
        continue;
    }

    $tamano = (int) filesize($archivo);
    if ($posicion === null) {
        $posicion = $desdeElInicio ? 0 : $tamano;
    }
    if ($tamano < $posicion) {
        $posicion = 0; // el archivo se vació o se reemplazó
    }

    if ($tamano > $posicion) {
        $manejador = fopen($archivo, 'rb');
        if ($manejador !== false) {
            fseek($manejador, $posicion);
            echo stream_get_contents($manejador);
            $posicion = ftell($manejador);
            fclose($manejador);
        }
    }

    usleep(PAUSA_MICROSEGUNDOS);
}
