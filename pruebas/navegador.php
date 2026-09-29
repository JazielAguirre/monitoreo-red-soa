<?php
/**
 * Cliente mínimo del protocolo de depuración de Chrome (Chrome DevTools Protocol)
 * para las pruebas de interfaz. Solo PHP, sin librerías.
 *
 * Seguridad y limpieza:
 * - Cada ejecución abre su PROPIO Chrome con un perfil temporal (--user-data-dir en
 *   una carpeta nueva) y --remote-debugging-port=0: Chrome elige un puerto libre y lo
 *   escribe en <perfil>/DevToolsActivePort. Nunca se conecta a un Chrome ya abierto.
 * - Al terminar (también si la prueba falla o se interrumpe con Ctrl+C) se cierra ese
 *   Chrome y se borra el perfil temporal (register_shutdown_function).
 */

declare(strict_types=1);

/**
 * Conexión WebSocket (RFC 6455) solo para 127.0.0.1: lo justo para hablar con Chrome.
 */
final class ConexionWebSocket
{
    /** @var resource */
    private $socket;

    public function __construct(int $puerto, string $ruta)
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:$puerto", $codigo, $mensaje, 5);

        if ($socket === false) {
            throw new RuntimeException("No se pudo conectar con Chrome en 127.0.0.1:$puerto ($mensaje)");
        }

        $this->socket = $socket;
        $clave = base64_encode(random_bytes(16));
        $this->escribir(
            "GET $ruta HTTP/1.1\r\nHost: 127.0.0.1:$puerto\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $clave\r\nSec-WebSocket-Version: 13\r\n\r\n"
        );

        $cabecera = '';
        while (!str_contains($cabecera, "\r\n\r\n")) {
            $cabecera .= $this->leerExacto(1, 5);
        }

        if (!preg_match('/^HTTP\/1\.1 101/', $cabecera)) {
            throw new RuntimeException('Chrome rechazó la conexión WebSocket: ' . strtok($cabecera, "\r\n"));
        }
    }

    public function enviarTexto(string $texto): void
    {
        $this->enviarMarco(0x1, $texto);
    }

    /**
     * Devuelve el siguiente mensaje de texto completo, o null si no llega nada en $espera segundos.
     */
    public function recibirTexto(float $espera): ?string
    {
        $mensaje = '';

        while (true) {
            if ($mensaje === '' && !$this->hayDatos($espera)) {
                return null;
            }

            $b = $this->leerExacto(2, 30);
            $fin = (ord($b[0]) & 0x80) !== 0;
            $opcode = ord($b[0]) & 0x0F;
            $longitud = ord($b[1]) & 0x7F;

            if ($longitud === 126) {
                $longitud = unpack('n', $this->leerExacto(2, 30))[1];
            } elseif ($longitud === 127) {
                $longitud = unpack('J', $this->leerExacto(8, 30))[1];
            }

            $mascara = (ord($b[1]) & 0x80) !== 0 ? $this->leerExacto(4, 30) : '';
            $datos = $longitud > 0 ? $this->leerExacto($longitud, 60) : '';

            if ($mascara !== '') {
                $datos = $datos ^ substr(str_repeat($mascara, intdiv($longitud, 4) + 1), 0, $longitud);
            }

            if ($opcode === 0x8) {
                throw new RuntimeException('Chrome cerró la conexión');
            }
            if ($opcode === 0x9) {
                $this->enviarMarco(0xA, $datos);
                continue;
            }
            if ($opcode === 0xA) {
                continue;
            }

            $mensaje .= $datos;
            if ($fin) {
                return $mensaje;
            }
        }
    }

    public function cerrar(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    private function enviarMarco(int $opcode, string $datos): void
    {
        $longitud = strlen($datos);
        $cabecera = chr(0x80 | $opcode);

        if ($longitud < 126) {
            $cabecera .= chr(0x80 | $longitud);
        } elseif ($longitud < 65536) {
            $cabecera .= chr(0x80 | 126) . pack('n', $longitud);
        } else {
            $cabecera .= chr(0x80 | 127) . pack('J', $longitud);
        }

        // Los marcos del cliente van enmascarados (RFC 6455, 5.3).
        $mascara = random_bytes(4);
        $enmascarado = $datos ^ substr(str_repeat($mascara, intdiv($longitud, 4) + 1), 0, $longitud);
        $this->escribir($cabecera . $mascara . $enmascarado);
    }

    private function escribir(string $datos): void
    {
        while ($datos !== '') {
            $escritos = @fwrite($this->socket, $datos);
            if ($escritos === false || $escritos === 0) {
                throw new RuntimeException('No se pudo escribir en la conexión con Chrome');
            }
            $datos = substr($datos, $escritos);
        }
    }

    private function hayDatos(float $espera): bool
    {
        $lectura = [$this->socket];
        $nada = null;
        $segundos = (int) floor($espera);
        $micro = (int) (($espera - $segundos) * 1_000_000);

        return @stream_select($lectura, $nada, $nada, $segundos, $micro) > 0;
    }

    private function leerExacto(int $bytes, float $espera): string
    {
        $datos = '';

        while (strlen($datos) < $bytes) {
            if (!$this->hayDatos($espera)) {
                throw new RuntimeException('Chrome dejó de responder');
            }
            $trozo = fread($this->socket, $bytes - strlen($datos));
            if ($trozo === false || ($trozo === '' && feof($this->socket))) {
                throw new RuntimeException('Se perdió la conexión con Chrome');
            }
            $datos .= $trozo;
        }

        return $datos;
    }
}

/**
 * Un Chrome headless propio, controlado por CDP.
 */
final class Navegador
{
    /** @var resource|null */
    private $proceso = null;
    private string $perfil;
    private int $puerto = 0;
    private string $rutaNavegador = '';
    private ?ConexionWebSocket $conexion = null;
    private string $sesion = '';
    private int $siguienteId = 1;
    private array $respuestas = [];
    private array $eventos = [];
    private array $manejadores = [];
    private array $intercepciones = [];
    private array $retenidas = [];
    private bool $cerrado = false;
    private array $metricas = ['width' => 1440, 'height' => 900, 'mobile' => false];

    /** Errores de JavaScript y de consola de la página. */
    public array $erroresJs = [];
    /** Diálogos alert/confirm/prompt que intentó abrir la página (se cierran solos). */
    public array $dialogos = [];

    /**
     * Busca Chrome, Chromium o Edge. Se puede indicar otro con la variable CHROME_BIN.
     */
    public static function buscarEjecutable(): ?string
    {
        $propio = getenv('CHROME_BIN');
        if (is_string($propio) && $propio !== '' && is_file($propio)) {
            return $propio;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidatos = [];
            foreach (['ProgramFiles', 'ProgramFiles(x86)', 'LOCALAPPDATA'] as $variable) {
                $base = getenv($variable);
                if (is_string($base) && $base !== '') {
                    $candidatos[] = "$base\\Google\\Chrome\\Application\\chrome.exe";
                    $candidatos[] = "$base\\Microsoft\\Edge\\Application\\msedge.exe";
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $candidatos = [
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Chromium.app/Contents/MacOS/Chromium',
            ];
        } else {
            $candidatos = [
                '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
                '/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium',
            ];
        }

        foreach ($candidatos as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    public function __construct(string $ejecutable)
    {
        $this->perfil = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'monitoreo-pruebas-' . bin2hex(random_bytes(6));

        if (!mkdir($this->perfil, 0700)) {
            throw new RuntimeException("No se pudo crear el perfil temporal {$this->perfil}");
        }

        register_shutdown_function([$this, 'cerrar']);
        $this->cerrarAlInterrumpir();

        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proceso = proc_open([
            $ejecutable,
            '--headless=new',
            '--disable-gpu',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-extensions',
            '--disable-sync',
            '--disable-background-networking',
            '--mute-audio',
            '--hide-scrollbars',
            '--user-data-dir=' . $this->perfil,
            '--remote-debugging-port=0',
            '--window-size=1440,900',
            'about:blank',
        ], [0 => ['pipe', 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $tuberias);

        if (!is_resource($this->proceso)) {
            throw new RuntimeException("No se pudo iniciar $ejecutable");
        }

        $this->leerPuerto();
        $this->conectar();
        $this->prepararDominios();
    }

    // ------------------------------------------------------------ Protocolo

    /**
     * Envía un comando y espera su respuesta.
     */
    public function enviar(string $metodo, array $parametros = [], float $espera = 20, bool $enLaPagina = true): array
    {
        $id = $this->enviarSinEsperar($metodo, $parametros, $enLaPagina);
        $limite = microtime(true) + $espera;

        while (!array_key_exists($id, $this->respuestas)) {
            $restante = $limite - microtime(true);
            if ($restante <= 0) {
                throw new RuntimeException("Chrome no respondió a $metodo");
            }
            $this->procesarUno($restante);
        }

        $respuesta = $this->respuestas[$id];
        unset($this->respuestas[$id]);

        if (isset($respuesta['error'])) {
            throw new RuntimeException("$metodo: " . ($respuesta['error']['message'] ?? 'error'));
        }

        return $respuesta['result'] ?? [];
    }

    /**
     * Envía un comando sin esperar la respuesta. Con $enLaPagina va a la sesión de la
     * página; si no, al navegador (Target.*, Browser.*).
     */
    public function enviarSinEsperar(string $metodo, array $parametros = [], bool $enLaPagina = true): int
    {
        $id = $this->siguienteId++;
        $mensaje = ['id' => $id, 'method' => $metodo, 'params' => (object) $parametros];

        if ($enLaPagina && $this->sesion !== '') {
            $mensaje['sessionId'] = $this->sesion;
        }

        $this->conexion->enviarTexto(json_encode($mensaje, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $id;
    }

    /**
     * Procesa los mensajes que lleguen durante $segundos.
     */
    public function procesarEventos(float $segundos): void
    {
        $limite = microtime(true) + $segundos;

        while (($restante = $limite - microtime(true)) > 0) {
            $this->procesarUno($restante);
        }
    }

    public function esperarEvento(string $metodo, float $espera = 20): array
    {
        $limite = microtime(true) + $espera;

        while (true) {
            foreach ($this->eventos as $i => $evento) {
                if ($evento['method'] === $metodo) {
                    unset($this->eventos[$i]);
                    return $evento['params'] ?? [];
                }
            }

            $restante = $limite - microtime(true);
            if ($restante <= 0) {
                throw new RuntimeException("No llegó el evento $metodo");
            }
            $this->procesarUno($restante);
        }
    }

    private function procesarUno(float $espera): void
    {
        $texto = $this->conexion->recibirTexto(min($espera, 0.5));

        if ($texto === null) {
            return;
        }

        $mensaje = json_decode($texto, true);

        if (isset($mensaje['id'])) {
            $this->respuestas[$mensaje['id']] = $mensaje;
            return;
        }

        $metodo = $mensaje['method'] ?? '';
        $parametros = $mensaje['params'] ?? [];

        if (isset($this->manejadores[$metodo])) {
            ($this->manejadores[$metodo])($parametros);
        }

        $this->eventos[] = $mensaje;
        if (count($this->eventos) > 500) {
            array_shift($this->eventos);
        }
    }

    // ------------------------------------------------------------ Acciones de alto nivel

    /**
     * Abre una URL y espera a que termine de cargar.
     */
    public function abrir(string $url, float $espera = 20): void
    {
        $this->eventos = array_values(array_filter($this->eventos, fn($e) => $e['method'] !== 'Page.loadEventFired'));
        $this->enviar('Page.navigate', ['url' => $url]);
        $this->esperarEvento('Page.loadEventFired', $espera);
    }

    /**
     * Evalúa JavaScript en la página (espera las promesas) y devuelve el valor.
     */
    public function evaluar(string $codigo, float $espera = 20): mixed
    {
        $resultado = $this->enviar('Runtime.evaluate', [
            'expression' => $codigo,
            'awaitPromise' => true,
            'returnByValue' => true,
            'userGesture' => true,
        ], $espera);

        if (isset($resultado['exceptionDetails'])) {
            $detalle = $resultado['exceptionDetails']['exception']['description']
                ?? $resultado['exceptionDetails']['text'] ?? 'excepción';
            throw new RuntimeException("Error al evaluar en la página: $detalle");
        }

        return $resultado['result']['value'] ?? null;
    }

    /**
     * Espera hasta que la condición de JavaScript sea verdadera. Devuelve false si se agota el tiempo.
     */
    public function esperarHasta(string $condicion, float $espera = 10): bool
    {
        $limite = microtime(true) + $espera;

        do {
            if ($this->evaluar("Boolean($condicion)") === true) {
                return true;
            }
            $this->procesarEventos(0.1);
        } while (microtime(true) < $limite);

        return false;
    }

    /**
     * Tamaño de la ventana. Con $movil emula un celular (el ancho es exacto, sin el
     * mínimo de 500 px que tiene Chrome headless en la línea de comandos).
     */
    public function tamano(int $ancho, int $alto, bool $movil = false): void
    {
        $this->metricas = ['width' => $ancho, 'height' => $alto, 'mobile' => $movil];
        $this->enviar('Emulation.setDeviceMetricsOverride', $this->metricas + ['deviceScaleFactor' => 1]);
    }

    public function movimientoReducido(bool $activo): void
    {
        $this->enviar('Emulation.setEmulatedMedia', [
            'features' => [['name' => 'prefers-reduced-motion', 'value' => $activo ? 'reduce' : '']],
        ]);
    }

    /**
     * Guarda una captura PNG de la página completa (o solo de la ventana).
     */
    public function captura(string $ruta, bool $paginaCompleta = true): void
    {
        $original = $this->metricas;

        if ($paginaCompleta) {
            // Se agranda la ventana emulada al alto de la página y se espera a que las
            // gráficas terminen de acomodarse (Chart.js anima al cambiar de tamaño).
            $alto = (int) ceil($this->evaluar('document.documentElement.scrollHeight'));
            $this->enviar('Emulation.setDeviceMetricsOverride', ['height' => max($alto, $original['height'])] + $original + ['deviceScaleFactor' => 1]);
            $this->procesarEventos(1.2);
        }

        $resultado = $this->enviar('Page.captureScreenshot', ['format' => 'png'], 60);
        file_put_contents($ruta, base64_decode($resultado['data']));

        if ($paginaCompleta) {
            $this->tamano($original['width'], $original['height'], $original['mobile']);
        }
    }

    /**
     * Pulsa una tecla ("Escape", "Tab", "Enter").
     */
    public function tecla(string $nombre): void
    {
        $codigos = ['Escape' => 27, 'Tab' => 9, 'Enter' => 13, 'End' => 35, 'Home' => 36, 'ArrowLeft' => 37, 'ArrowRight' => 39];
        $base = ['key' => $nombre, 'code' => $nombre, 'windowsVirtualKeyCode' => $codigos[$nombre] ?? 0];
        $this->enviar('Input.dispatchKeyEvent', ['type' => 'keyDown'] + $base);
        $this->enviar('Input.dispatchKeyEvent', ['type' => 'keyUp'] + $base);
    }

    /**
     * Intercepta las peticiones de la página cuya URL contiene $fragmento:
     * ['fallar' => true] corta la conexión; ['codigo' => 500, 'cuerpo' => '...'] responde eso;
     * ['retener' => true] la deja en pausa hasta liberar() (para ver estados intermedios).
     * Sirve para simular un servicio caído o MySQL apagado sin tocar el sistema.
     */
    public function interceptar(string $fragmento, array $respuesta): void
    {
        $this->intercepciones[$fragmento] = $respuesta;
        $this->enviar('Fetch.enable', ['patterns' => [['urlPattern' => '*', 'requestStage' => 'Request']]]);
    }

    /**
     * Deja pasar las peticiones retenidas que contienen $fragmento y deja de retenerlas.
     */
    public function liberar(string $fragmento): void
    {
        unset($this->intercepciones[$fragmento]);

        foreach ($this->retenidas as $i => [$id, $url]) {
            if (str_contains($url, $fragmento)) {
                $this->enviar('Fetch.continueRequest', ['requestId' => $id]);
                unset($this->retenidas[$i]);
            }
        }
    }

    public function quitarIntercepciones(): void
    {
        foreach ($this->retenidas as [$id]) {
            $this->enviarSinEsperar('Fetch.continueRequest', ['requestId' => $id]);
        }
        $this->retenidas = [];
        $this->intercepciones = [];
        $this->enviar('Fetch.disable');
    }

    // ------------------------------------------------------------ Arranque y cierre

    /**
     * Cierra el Chrome de las pruebas y borra el perfil temporal. Se puede llamar varias veces.
     */
    public function cerrar(): void
    {
        if ($this->cerrado) {
            return;
        }
        $this->cerrado = true;

        try {
            $this->conexion?->enviarTexto(json_encode(['id' => $this->siguienteId++, 'method' => 'Browser.close']));
        } catch (Throwable) {
            // Si no se pudo cerrar por el protocolo, se termina el proceso abajo.
        }
        $this->conexion?->cerrar();

        if (is_resource($this->proceso)) {
            $limite = microtime(true) + 5;
            while (proc_get_status($this->proceso)['running'] && microtime(true) < $limite) {
                usleep(100_000);
            }
            if (proc_get_status($this->proceso)['running']) {
                proc_terminate($this->proceso, 9);
            }
            proc_close($this->proceso);
        }

        $this->borrarPerfil();
    }

    private function cerrarAlInterrumpir(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn() => exit(130));
            pcntl_signal(SIGTERM, fn() => exit(143));
        } elseif (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(fn() => exit(130));
        }
    }

    private function leerPuerto(): void
    {
        $archivo = $this->perfil . DIRECTORY_SEPARATOR . 'DevToolsActivePort';
        $limite = microtime(true) + 20;

        while (microtime(true) < $limite) {
            $lineas = is_file($archivo) ? file($archivo, FILE_IGNORE_NEW_LINES) : false;
            if ($lineas !== false && count($lineas) >= 2 && ctype_digit($lineas[0])) {
                $this->puerto = (int) $lineas[0];
                $this->rutaNavegador = $lineas[1];
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('Chrome no arrancó (no apareció DevToolsActivePort en el perfil temporal)');
    }

    /**
     * Se conecta al WebSocket del navegador (ruta de DevToolsActivePort, sin pasar por
     * el HTTP de depuración) y se une a la página con una sesión (Target.attachToTarget).
     */
    private function conectar(): void
    {
        $this->conexion = new ConexionWebSocket($this->puerto, $this->rutaNavegador);

        $destinos = $this->enviar('Target.getTargets', [], 10, false)['targetInfos'] ?? [];
        $pagina = null;
        foreach ($destinos as $destino) {
            if (($destino['type'] ?? '') === 'page') {
                $pagina = $destino['targetId'];
                break;
            }
        }
        $pagina ??= $this->enviar('Target.createTarget', ['url' => 'about:blank'], 10, false)['targetId'];

        $this->sesion = $this->enviar('Target.attachToTarget', ['targetId' => $pagina, 'flatten' => true], 10, false)['sessionId'];
    }

    private function prepararDominios(): void
    {
        $this->manejadores['Runtime.exceptionThrown'] = function (array $p): void {
            $this->erroresJs[] = $p['exceptionDetails']['exception']['description'] ?? $p['exceptionDetails']['text'] ?? 'excepción';
        };
        $this->manejadores['Runtime.consoleAPICalled'] = function (array $p): void {
            if (($p['type'] ?? '') === 'error') {
                $this->erroresJs[] = 'console.error: ' . implode(' ', array_map(fn($a) => $a['value'] ?? $a['description'] ?? '', $p['args'] ?? []));
            }
        };
        $this->manejadores['Log.entryAdded'] = function (array $p): void {
            if (($p['entry']['level'] ?? '') === 'error') {
                $this->erroresJs[] = ($p['entry']['text'] ?? 'error') . ' ' . ($p['entry']['url'] ?? '');
            }
        };
        $this->manejadores['Page.javascriptDialogOpening'] = function (array $p): void {
            $this->dialogos[] = ($p['type'] ?? 'dialogo') . ': ' . ($p['message'] ?? '');
            $this->enviarSinEsperar('Page.handleJavaScriptDialog', ['accept' => false]);
        };
        $this->manejadores['Fetch.requestPaused'] = function (array $p): void {
            $this->atenderPeticionInterceptada($p);
        };

        $this->enviar('Page.enable');
        $this->enviar('Runtime.enable');
        $this->enviar('Log.enable');
    }

    private function atenderPeticionInterceptada(array $p): void
    {
        $url = $p['request']['url'] ?? '';

        foreach ($this->intercepciones as $fragmento => $respuesta) {
            if (!str_contains($url, $fragmento)) {
                continue;
            }

            if (!empty($respuesta['retener'])) {
                $this->retenidas[] = [$p['requestId'], $url];
            } elseif (!empty($respuesta['fallar'])) {
                $this->enviarSinEsperar('Fetch.failRequest', ['requestId' => $p['requestId'], 'errorReason' => 'ConnectionRefused']);
            } else {
                $this->enviarSinEsperar('Fetch.fulfillRequest', [
                    'requestId' => $p['requestId'],
                    'responseCode' => $respuesta['codigo'] ?? 500,
                    'responseHeaders' => [['name' => 'Content-Type', 'value' => $respuesta['tipo'] ?? 'application/json; charset=utf-8']],
                    'body' => base64_encode($respuesta['cuerpo'] ?? ''),
                ]);
            }
            return;
        }

        $this->enviarSinEsperar('Fetch.continueRequest', ['requestId' => $p['requestId']]);
    }

    private function borrarPerfil(): void
    {
        for ($intento = 0; $intento < 10 && is_dir($this->perfil); $intento++) {
            $this->borrarCarpeta($this->perfil);
            if (is_dir($this->perfil)) {
                usleep(300_000); // Chrome puede tardar en soltar los archivos (sobre todo en Windows).
            }
        }
    }

    private function borrarCarpeta(string $carpeta): void
    {
        try {
            $elementos = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($elementos as $elemento) {
                $elemento->isDir() && !$elemento->isLink() ? @rmdir($elemento->getPathname()) : @unlink($elemento->getPathname());
            }
        } catch (UnexpectedValueException) {
            // Algún archivo desapareció o sigue bloqueado: se reintenta en borrarPerfil().
        }
        @rmdir($carpeta);
    }

    public function perfilTemporal(): string
    {
        return $this->perfil;
    }

    public function puerto(): int
    {
        return $this->puerto;
    }
}
