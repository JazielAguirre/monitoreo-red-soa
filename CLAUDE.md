Sistema de Monitoreo de Red SOA

Aplicación web local para registrar dispositivos de red (computadoras, servidores, impresoras, routers), comprobar con ping si responden, guardar el historial de monitoreos y generar alertas cuando un dispositivo no responde.

Es un proyecto escolar cuyo objetivo es demostrar una Arquitectura Orientada a Servicios (SOA). El sistema debe ser funcional y visualmente cuidado: la estética es parte de la evaluación.

Idioma del proyecto: español (código, comentarios, commits y textos de la interfaz).

Entorno
Linux + XAMPP para Linux en /opt/lampp (Apache, PHP 8.2, MySQL/MariaDB). El código debe funcionar también en Windows y macOS.
Apache corre como el usuario daemon, no como tu usuario: daemon necesita permiso de paso (x) en cada carpeta del camino hasta el proyecto.
Ruta del proyecto: ~/Proyectos/monitoreo-red-soa, enlazado desde /opt/lampp/htdocs/monitoreo-red-soa (enlace simbólico).
Interfaz: http://localhost/monitoreo-red-soa/web/
Servicios: http://localhost/monitoreo-red-soa/servicios/{servicio}/
Base de datos: monitoreo_red_soa, usuario root sin contraseña (solo desarrollo local).
Apache y MySQL deben estar encendidos (ver Comandos).
MySQL en Linux con pam_tmpdir: al encenderlo con sudo, MariaDB hereda /tmp/user/0 como carpeta temporal y el usuario mysql no puede escribir ahí, así que no arranca. Solución: agregar tmpdir = /tmp debajo de [mysqld] en /opt/lampp/etc/my.cnf.
Sin Composer, sin npm, sin frameworks de PHP ni de JavaScript.
Las librerías del frontend se guardan en web/vendor/, no se cargan desde CDN (la demo puede hacerse sin internet).
Otros integrantes en Windows: el proyecto va en C:\xampp\htdocs\monitoreo-red-soa, los binarios son C:/xampp/php/php.exe y C:/xampp/mysql/bin/mysql.exe, Apache y MySQL se encienden con herramientas\iniciar-servicios.bat (doble clic) o desde el panel de XAMPP, y en PowerShell se usa curl.exe (ahí "curl" es otro comando). Guía paso a paso: docs/GUIA-EQUIPO.md.
Comandos
bash
# Encender Apache y MySQL de XAMPP
sudo /opt/lampp/lampp startapache
sudo /opt/lampp/lampp startmysql
# Todo de una vez (Apache, MySQL, Tailscale, cortafuegos y comprobación previa)
sudo bash herramientas/iniciar-servicios.sh      # Linux
herramientas\iniciar-servicios.bat               # Windows: doble clic; sin administrador; nunca ejecuta el comando del firewall

# Preparar la demo: NO cambia la base; comprueba Apache, servicios, tus dispositivos, historial reciente, router y esta PC
/opt/lampp/bin/php herramientas/preparar-demo.php            # --router=IP --pc=IP
/opt/lampp/bin/php herramientas/preparar-demo.php --datos-de-ejemplo   # reemplaza tu base por los de ejemplo: respalda y pide SI (--sin-red: plan B)
/opt/lampp/bin/php herramientas/ver-llamadas.php             # llamadas entre servicios en vivo
/opt/lampp/bin/php herramientas/monitorear-rondas.php --rondas=12 --cada=60   # historial antes de la demo (12 min); resumen al final o con Ctrl+C

# Base de uso diario (vacía: solo el usuario admin). Si ya existe, no la toca.
/opt/lampp/bin/php herramientas/crear-base.php                 # --reemplazar la vacía: respalda en respaldos/ y pide SI
/opt/lampp/bin/php herramientas/restaurar-respaldo.php         # lista los respaldos; con ARCHIVO, lo restaura (respalda y pide SI)

# Pruebas automáticas (usan su propia base, monitoreo_red_soa_pruebas; nunca tocan la del usuario y al final comprueban que quedó idéntica)
/opt/lampp/bin/php pruebas/dispositivos.php
/opt/lampp/bin/php pruebas/alertas.php
/opt/lampp/bin/php pruebas/monitoreo.php
/opt/lampp/bin/php pruebas/auth.php       # login, cookie, 401/403, sesión sin bloqueo
/opt/lampp/bin/php pruebas/descubrimiento.php  # escáner de red con la red simulada; nunca envía nada a la red local
/opt/lampp/bin/php pruebas/interfaz.php   # abre su propio Chrome headless (perfil temporal); se omite si no hay Chrome

# Colores fuera de tokens.css, solo en estilos (CSS, JS, style="" y <style>). Debe no imprimir nada.
# Los hexadecimales como texto visible en componentes.html son documentación y no se revisan.
# Excepción: web/favicon.svg lleva sus colores escritos (un favicon no puede usar variables CSS);
# este grep no lo revisa porque solo mira web/css, web/js y los HTML.
grep -rnE '#[0-9a-fA-F]{3,8}\b|\b(rgba?|hsla?)\(' web/css web/js --include='*.css' --include='*.js' | grep -v '^web/css/tokens\.css:'
grep -nE 'style="[^"]*(#[0-9a-fA-F]{3,8}|(rgba?|hsla?)\()|<style' web/*.html

# URLs externas en la interfaz (fuera de vendor/). Debe no imprimir nada.
# favicon.svg se excluye: su xmlns="http://www.w3.org/2000/svg" es obligatorio y no es una petición.
grep -rnE 'https?://' web --exclude-dir=vendor --exclude=favicon.svg

# Revisar la sintaxis de un archivo PHP (con el PHP de XAMPP, el mismo que usa Apache)
/opt/lampp/bin/php -l servicios/dispositivos/index.php

# Probar endpoints
curl -s http://localhost/monitoreo-red-soa/servicios/dispositivos
curl -s -X POST -H "Content-Type: application/json" \
  -d '{"nombre":"Mi PC","ip":"127.0.0.1","tipo":"computadora","ubicacion":"Laboratorio"}' \
  http://localhost/monitoreo-red-soa/servicios/dispositivos
Arquitectura
Interfaz web (web/)  →  HTTP + JSON  →  Servicios (servicios/)  →  MySQL
.htaccess        solo expone por HTTP web/ y los servicios; el resto responde 404, / redirige a web/
database/        schema.sql (solo las tablas: no crea ni borra la base), inicial.sql (usuario admin: la base de uso diario es schema + inicial), seed.sql (datos de ejemplo: solo base de pruebas o preparar-demo.php --datos-de-ejemplo)
docs/            PLAN.md (fases del proyecto), API.md (contrato de endpoints), diseno/ (referencia visual)
servicios/
├── comun/       config.php, db.php, respuesta.php, peticion.php, enrutador.php (acceso por ruta), http.php (cliente entre servicios), auth.php (sesión y clave de servicio), ping.php (ping del sistema; lo usan Monitoreo y Descubrimiento) (.htaccess: Require all denied)
├── dispositivos/  index.php + .htaccess, endpoints.php, validacion.php, datos.php → dueño de la tabla dispositivos
├── alertas/       index.php + .htaccess, endpoints.php, validacion.php, datos.php → dueño de la tabla alertas
├── monitoreo/     index.php + .htaccess, endpoints.php, validacion.php, datos.php, clientes.php (llamadas HTTP a Dispositivos y Alertas) → dueño de la tabla monitoreos
├── auth/          index.php + .htaccess, endpoints.php, validacion.php, datos.php → dueño de la tabla usuarios
└── descubrimiento/ index.php + .htaccess, endpoints.php, red.php (IP propia, puerta de enlace, /24 y lotes), escaneo.php (pings en paralelo con proc_open), arp.php, nombres.php (los 4 métodos de nombres en paralelo y su preferencia), dns.php (PTR por UDP), mdns.php, netbios.php, upnp.php (SSDP y descripción), clientes.php (GET /dispositivos), simulacion.php (red simulada de las pruebas) → sin tablas (Fase 9)
web/
├── css/         tokens.css (variables de diseño, único archivo con colores), app.css (componentes y Bootstrap), componentes.css (solo la página de muestra)
├── js/          api.js (único punto de acceso a la API, URL base relativa; con 401 lleva al login) + modulos/ (formatos, notificaciones, vistas, estructura, sesion, gráficas, busqueda-red…) + un módulo ES por página
├── vendor/      bootstrap, bootstrap-icons, chart.js, fuentes Inter y JetBrains Mono
└── *.html       login, index (dashboard), dispositivos, monitoreos, alertas, componentes
herramientas/    base-de-datos.php (crear, recrear, respaldar con mysqldump y confirmar con SI; la usan las pruebas y las herramientas), crear-base.php, restaurar-respaldo.php, red.php (detecta puerta de enlace e IP propia con las funciones de servicios/descubrimiento/red.php), preparar-demo.php, ver-llamadas.php, iniciar-servicios.sh (Linux) e iniciar-servicios.bat con iniciar-servicios.ps1 (Windows; el .ps1 en UTF-8 con BOM, y .gitattributes fija LF para .sh y CRLF para .ps1/.bat). Solo línea de comandos; no accesible por HTTP
pruebas/         ayudas.php (peticiones con la cookie de iniciarSesionDePrueba() o con la clave en peticionInterna(), fallo simulado) + un script por servicio (dispositivos.php, ..., auth.php, descubrimiento.php) + interfaz.php con navegador.php (cliente del protocolo de Chrome); la red simulada del escáner (simularRed(), tablaArpDeEjemplo()) y los paquetes de ejemplo de nombres con el respondedor falso (respondedor-nombres.php, iniciarRespondedorDeNombres()) también están en ayudas.php. Solo línea de comandos; no accesible por HTTP
Reglas SOA (no negociables)
Cada servicio solo lee y escribe sus propias tablas.
Excepción: herramientas/ (como schema.sql y seed.sql) son herramientas de base de datos y pueden tocar todas las tablas; preparar-demo.php --datos-de-ejemplo mueve fechas e IPs directamente en MySQL. Ninguna herramienta borra ni reemplaza la base del usuario sin respaldarla antes en respaldos/ y pedir SI. La regla sigue valiendo para todo lo que está en servicios/.
Si un servicio necesita datos o acciones de otro, los pide por HTTP usando servicios/comun/http.php. Nunca hace require del código de otro servicio ni consultas SQL a tablas ajenas.
Ejemplo: Monitoreo obtiene la IP con GET /dispositivos/{id}, actualiza el estado con PATCH /dispositivos/{id}/estado y crea alertas con POST /alertas.
comun/http.php: llamarServicio() usa streams de PHP (no curl), espera TIEMPO_ESPERA_SERVICIOS (config.php) y envía X-Servicio-Clave. Cada llamada se anota en llamadas-servicios.log, en la carpeta del error_log de PHP (XAMPP: /opt/lampp/logs o C:\xampp\php\logs, donde Apache ya puede escribir): «Monitoreo → Dispositivos: PATCH /dispositivos/7/estado (200, 12 ms)», precedida por la petición que la originó. Sirve para la demo (herramientas/ver-llamadas.php) porque el log de Apache no distingue al navegador de la laptop (127.0.0.1) de los servicios y anota cada petición al terminar. Si no se puede escribir, se ignora. Si el otro servicio no responde o no devuelve el JSON del formato común, lanza ErrorDeComunicacion, que el index.php responde como 502 "No se pudo comunicar con el Servicio de {nombre}".
Base de pruebas (pruebas/.base-de-pruebas.json, leído por comun/db.php, Fase 10): mientras existe y está vigente, todos (servicios por Apache y herramientas de terminal) usan DB_NOMBRE_PRUEBAS (monitoreo_red_soa_pruebas) en lugar de DB_NOMBRE. Lo crea, renueva cada 15 s y borra pruebas/ayudas.php; caduca en 60 s; no se activa por HTTP; cargarSchemaYSeed() se niega a recrear otra base. El tráfico de las pruebas va marcado (cabecera X-Pruebas-Automaticas, reenviada por comun/http.php; variable MONITOREO_PRUEBAS=1 en los procesos que lanzan): si llega con la base de pruebas inactiva, comun/db.php no se conecta. La marca solo puede provocar un error: nunca elige la base de pruebas ni otra (la elige solo el archivo), ni activa o renueva el interruptor (probado desde fuera por HTTP en dispositivos.php §11). Las pruebas nunca deben tocar la base del usuario: procesos hijos siempre con tope de tiempo y, si hay que mandarles señales, con proc_open en forma de arreglo (sin sh de por medio). Detalle en docs/PLAN.md.
Archivo de red simulada (pruebas/.red-simulada.json, leído por servicios/descubrimiento/simulacion.php): solo para pruebas/descubrimiento.php e interfaz.php. Cambia la IP propia (solo 127.0.0.1 o 192.0.2.1 se escanean; null y 198.51.100.1 sirven para probar los 503) y el texto de la tabla ARP; los pings son reales. Con la red simulada los nombres solo se piden por DNS, salvo que traiga puertos_nombres (≥1024, solo con 127.0.0.1), los del respondedor falso en 127.0.0.x. Caduca en 60 s como máximo. Detalle en docs/PLAN.md.
Archivo de fallo simulado (pruebas/.fallo-simulado.json, leído por comun/http.php): solo lo usan las pruebas para simular que Dispositivos o Alertas no responde. Caduca solo (máximo 60 s), solo admite modos fijos hacia 127.0.0.1 o web/index.html, y no puede activarse por HTTP (ningún endpoint escribe archivos y pruebas/ responde 404). Detalle en docs/PLAN.md, "Pruebas automáticas".
La interfaz nunca accede a la base de datos: todo pasa por web/js/api.js.
Seguridad en la interfaz: los datos de la API se insertan con textContent o pasan por escaparHtml() de web/js/modulos/formatos.js; nunca directo en innerHTML. Las fechas de la API se separan con partesFecha(), nunca con new Date(texto) (Safari del iPhone las toma como inválidas). Los formularios usan novalidate: los mensajes de validación vienen solo de la API.
comun/ contiene solo infraestructura (conexión, respuestas, cliente HTTP, autenticación, ping del sistema). Nunca lógica de negocio.
Las URLs base y la clave interna viven en comun/config.php. Nada hardcodeado en los servicios.
Patrón de un servicio (lo fija Dispositivos): index.php declara las rutas y llama a despachar() de comun/enrutador.php dentro de try/catch; endpoints.php tiene una función por endpoint; validacion.php las reglas; datos.php todo el SQL y la conversión de tipos. comun/peticion.php lee método, ruta, parámetros de la URL (filtroOpcional()) y cuerpo JSON.
Por HTTP, cada servicio solo ejecuta su index.php: su .htaccess envía ahí todas las rutas, incluidos los archivos que existen (por ejemplo endpoints.php). No agregues RewriteCond %{REQUEST_FILENAME} !-f.
Contrato de la API

La fuente de verdad es @docs/API.md. Si una tarea requiere cambiar un endpoint, un campo o un formato, pregunta antes y actualiza docs/API.md en el mismo cambio.

Respuesta exitosa: { "ok": true, "data": ... }
Error: { "ok": false, "error": "Mensaje claro para el usuario" }
Errores de validación (400 y 409): pueden llevar el campo opcional "campos", con un mensaje por campo: { "ok": false, "error": "Revisa los datos del formulario", "campos": { "ip": "Dirección IP no válida" } }
Códigos HTTP: 200 OK, 201 creado, 400 datos inválidos, 401 sin sesión, 403 endpoint interno sin clave de servicio (desde la Fase 7), 404 no existe, 405 método no permitido, 409 conflicto (por ejemplo, IP duplicada), 500 error interno, 502 otro servicio no respondió o respondió algo inesperado, 503 el servicio no puede trabajar en esta computadora (Descubrimiento sin red local o con IP no privada, Fase 9).
Siempre Content-Type: application/json; charset=utf-8, también en los errores.
Campos en snake_case, en español y sin acentos ni ñ (ubicacion, contrasena), iguales a las columnas: id, nombre, ip, tipo, ubicacion, estado, fecha_registro, tiempo_respuesta_ms, atendida, etc.
Tipos en JSON: números como números y booleanos como booleanos. PDO devuelve DECIMAL y TINYINT como texto: se convierten antes de responder ("0.32" → 0.32, "1" → true).
Fechas en formato YYYY-MM-DD HH:MM:SS, hora local de America/Mazatlan. La zona se fija en comun/config.php con date_default_timezone_set() (el php.ini de XAMPP trae Europe/Berlin). Todas las fechas las genera PHP, nunca NOW() de MySQL, que usa la zona del sistema.
Tiempos de respuesta en ms con hasta 2 decimales (DECIMAL(7,2)); null si el dispositivo no respondió.
Valores de estado: activo, inactivo, desconocido (dispositivo recién registrado que aún no se monitorea).
La interfaz y los servicios comparten origen (mismo Apache): no agregues cabeceras CORS.
Convenciones de PHP
declare(strict_types=1); al inicio de cada archivo.
PDO con consultas preparadas siempre. Nunca concatenar valores en SQL. Conexión con utf8mb4, ERRMODE_EXCEPTION y FETCH_ASSOC.
Toda salida pasa por los helpers de comun/respuesta.php (responder(), responderError($mensaje, $codigo, $campos = null)). Ningún echo, print_r o var_dump suelto que rompa el JSON.
Las excepciones se capturan en el punto de entrada de cada servicio y se responde 500 con un mensaje genérico. El detalle va a error_log(), nunca al cliente.
Validar todas las entradas: campos requeridos, longitudes, valores permitidos e IP con filter_var($ip, FILTER_VALIDATE_IP).
Variables, funciones y tablas con nombres en español. Funciones cortas, cada una con una sola responsabilidad.
Ping (Servicios de Monitoreo y Descubrimiento)
Implementación: servicios/comun/ping.php (hacerPing() devuelve responde, tiempo_respuesta_ms y salida). Estaba en servicios/monitoreo/ hasta la Fase 9; se movió a comun/ porque también lo usa Descubrimiento.
Seguridad: la IP se valida con FILTER_VALIDATE_IP y pasa por escapeshellarg() antes de exec(). Nunca ejecutes un comando con texto que no haya sido validado.
Solo IPv4 (FILTER_VALIDATE_IP con FILTER_FLAG_IPV4): así no hay que manejar las diferencias de ping con IPv6 entre sistemas operativos.
El sistema operativo se detecta con PHP_OS_FAMILY:
Windows: ping -n 1 -w 1000 {ip}
Linux: ping -c 1 -W 1 {ip}
macOS (Darwin): ping -c 1 -t 1 {ip}
En Windows no confíes en el código de salida: con "Host de destino inaccesible" puede devolver 0. El dispositivo está activo solo si la salida contiene TTL= (sin distinguir mayúsculas).
El tiempo de respuesta se extrae aceptando salida en inglés y en español: time=2ms, time<1ms, tiempo=2ms, tiempo<1m, time=0.032 ms. Se redondea a 2 decimales. Si la salida indica <1, guarda 0 y la interfaz lo muestra como < 1 ms.
Espera máxima: 1 segundo por dispositivo.
Solo se crea una alerta si el dispositivo no tiene ya una alerta pendiente, para evitar alertas repetidas en cada ping fallido. La regla vive en el Servicio de Alertas (POST /alertas) y la garantiza la base de datos con el índice único de alertas.dispositivo_pendiente, también con peticiones simultáneas.
Escáner de red (Servicio de Descubrimiento, Fase 9): solo la /24 de la IP propia, calculada en el servidor y solo si es privada; el navegador solo elige el lote (1–4). Ping en paralelo con proc_open (máximo 64 a la vez, tope de 3 s por lote) usando comun/ping.php, tabla ARP sin depender del idioma y nombres (rama nombres-escaner) por UPnP, mDNS, NetBIOS y DNS inverso a la vez, con 0.8 s como máximo en total (nombres.php). Consultas siempre unicast a la IP del equipo (las respuestas multicast las bloquearía ufw); las de UPnP/mDNS/NetBIOS solo cuentan si vienen de la IP consultada. Preferencia UPnP → mDNS → NetBIOS → DNS (el nombre que mejor se entiende), en nombre_origen. Los nombres los controla cualquiera en la red: DNS/mDNS solo caracteres de nombre de host (nada que empiece con _, como el _gateway de systemd-resolved), NetBIOS ASCII imprimible, UPnP texto sin caracteres de control; la descripción UPnP solo si la URL es http:// a la misma IP que contestó y dentro de la /24, con 64 KB máximo, sin redirecciones y rechazando DOCTYPE/ENTITY. Uso responsable: solo en redes propias o con permiso; en la demo, el hotspot del equipo, nunca la red de la escuela. Las pruebas nunca escanean la red local: usan la red simulada.
IPs de prueba: 127.0.0.1 siempre responde. 192.0.2.1 (rango reservado para documentación) nunca responde. Las pruebas automáticas usan solo 127.0.0.1 y 192.0.2.x, nunca IPs de la red local, para pasar en cualquier red o sin red.
Prueba el ping a través de Apache (con curl al endpoint), no solo con php en la terminal: Apache corre como daemon y puede tener permisos distintos a tu usuario para ejecutar ping.
Autenticación
Login con password_hash() / password_verify() y sesiones de PHP.
Peticiones del navegador: requieren sesión activa. Sin sesión, el servicio responde 401 y api.js lleva a login.html?volver=<pantalla actual>; tras el login se vuelve ahí solo si destinoSeguro() (web/js/modulos/sesion.js) confirma que es una pantalla de web/, si no, al Dashboard.
Cada pantalla arranca con await iniciarPagina() (estructura.js): confirma la sesión con GET /auth/sesion antes de pedir datos (se ve el esqueleto), muestra el usuario real y conecta «Salir» (POST /auth/logout → login). componentes.html es pública y no pide datos.
Peticiones entre servicios: no llevan sesión ni reenvían la cookie. Se autentican con la cabecera X-Servicio-Clave (CLAVE_SERVICIO en comun/config.php, 64 caracteres aleatorios), comparada con hash_equals().
Cada ruta del enrutador declara su acceso (4.º elemento): ACCESO_INTERFAZ (sesión o clave; si no, 401), ACCESO_INTERNO (solo clave; si no, 403 aunque haya sesión) o ACCESO_PUBLICO (los tres de auth). Una clave presente pero incorrecta → 403.
La sesión se lee con session_start(['read_and_close' => true]) (leerUsuarioDeLaSesion() en comun/auth.php): no bloquea las peticiones en paralelo. Solo POST /auth/login y POST /auth/logout escriben en ella. Leer nunca crea sesiones ni envía cookies.
Cookie monitoreo_soa_sesion: HttpOnly, SameSite=Lax, ruta /monitoreo-red-soa/, 8 horas (DURACION_SESION; también session.gc_maxlifetime y la hora de expiración guardada en la sesión). session_regenerate_id(true) al iniciar sesión, con use_strict_mode.
Login: mismo mensaje y tiempo similar exista o no el usuario (password_verify() contra HASH_FICTICIO si no existe).
Diseño de la interfaz
Tokens: colores, tipografía, espaciados, radios y sombras son variables CSS en web/css/tokens.css. Ningún otro archivo usa valores hexadecimales sueltos (única excepción: web/favicon.svg, con los valores de --color-base, --color-base-icono y --color-activo escritos, porque un favicon no puede leer variables CSS).
Caché: web/.htaccess envía Cache-Control: no-cache a .html, .js y .css (menos vendor/), dentro de <IfModule mod_headers.c>. El navegador revalida cada vez y recibe 304 si nada cambió. Existe porque en la Fase 6 un celular siguió usando los JavaScript viejos hasta que se cambió de navegador; el día de la demo puede pasar en cualquier equipo que ya abrió la aplicación. Lo comprueba el inicio de pruebas/interfaz.php.
Proyector: la interfaz se revisa también a 1024×768 y 1280×720. La tabla de Dispositivos oculta Ubicación, Tipo y Comprobado (en ese orden) con una container query cuando la tarjeta no alcanza; nombre, IP, estado, respuesta y acciones siempre quedan a la vista. El panel «Equipos en la red» (Fase 9) oculta la MAC por debajo de 960 px de tarjeta y, por debajo de 820, el tiempo; la marca baja debajo de la IP, los nombres se recortan y «Registrado ·» queda solo para lectores de pantalla.
Referencia visual: docs/diseno/. "Monitoreo de Red SOA.dc.html" es el sistema de diseño (valores finales), "Monitoreo de Red SOA · Pantallas.dc.html" y el PDF muestran las pantallas. Es solo referencia: no copies su código (usa React internamente y carga recursos desde internet).
Tokens: web/css/tokens.css es el único archivo con colores (hexadecimales o rgb/rgba), agrupados por categoría: paleta base, estados, superficies y bordes, barra lateral, acento, gráficas, transparencias, tipografía y medidas. Además de la paleta del :root del diseño, tokeniza los colores que el diseño escribía directamente (bordes suaves, hover de fila, esqueleto, variantes del acento, colores de la barra lateral, series de la gráfica, fondos de modal y anillos de foco).
Paleta base (valores finales del sistema de diseño):
Base: --color-base #0B1D2E, --color-base-2 #16324F. Texto: --color-texto #1E293B, --color-texto-2 #5B6B80 (reemplaza a #64748B, que no llegaba a AA sobre el fondo), --color-marcador #66778C (placeholder).
Fondo y superficies: --color-fondo #F3F6F9, --color-superficie #FFFFFF, --color-borde #E2E8F0, --color-borde-suave #EEF2F6, --color-borde-control #CBD5E1, --color-superficie-hover #F8FAFC.
Acciones: --color-acento #2F6FB0 (foco, enlaces, gráfica, pulso), --color-peligro #DC2626.
Estados (color / -texto / -fondo):
  activo       #22C55E / #15803D / #F0FDF4
  inactivo     #EF4444 / #B91C1C / #FEF2F2
  alerta       #F59E0B / #B45309 / #FFFBEB
  desconocido  #94A3B8 / #475569 / #F1F5F9
El color de estado es para el punto; el texto de estado usa la variante -texto. El verde #22C55E sobre blanco da 2.3:1: nunca como texto.
Gráficas: --color-grafica-1 #16324F, -2 #2F6FB0, -3 #14958A, -4 #5A92C9 (el diseño traía #6FA3D6, 2.66:1; se oscureció para llegar a 3:1).
Radios: --radio-sm 6px, --radio 8px, --radio-icono 10px, --radio-lg 12px, --radio-pastilla 999px.
Sombras: --sombra-1 (tarjetas), --sombra-2 (hover, menú), --sombra-3 (modal), --sombra-notificacion.
Medidas: controles 38 px (sm 32), fila de tabla 56, encabezado de tabla 40, notificación 340 px de ancho (arriba 24, derecha 32), modal 420 px.
Tiempos: pulso 1.2 s (ease-out), esqueleto 1.4 s (opacidad 1 → .55), spinner 0.7 s, notificación 4.5 s (--duracion-notificacion; JavaScript lo lee del token).
Tipografía:
Inter para la interfaz (web/vendor/inter/), con respaldo system-ui, sans-serif.
JetBrains Mono para IPs, horas y tiempos (web/vendor/jetbrains-mono/, pesos 400, 500 y 600), con respaldo ui-monospace, monospace y font-variant-numeric: slashed-zero (clase .mono o .texto-mono).
Escala (tamaño / interlineado en px · peso):
  Display (login)       28 / 36 · 600 · letter-spacing -1%
  Título de página      22 / 30 · 600
  Título de tarjeta     16 / 24 · 600
  Indicador             32 / 36 · 600 · tabular
  Cuerpo                14 / 20 · 400
  Secundario            13 / 18 · 400
  Encabezado de tabla   11 / 16 · 600 · +6% · mayúsculas
  Monoespaciada         13 / 20 · 500 · JetBrains Mono · cero con barra
Bootstrap: las variables globales (--bs-primary, --bs-primary-rgb, --bs-body-*, --bs-border-*, --bs-link-*, --bs-focus-ring-*) están en tokens.css; las de cada componente (--bs-btn-*, --bs-modal-*, --bs-toast-*, --bs-table-*, --bs-nav-underline-*, --bs-card-*) en app.css. .btn-secondary es el "Secundario" del diseño (blanco con borde), no el gris de Bootstrap. El chevron del select y el ícono de error del campo son íconos de Bootstrap Icons dentro de .campo-icono, no las imágenes SVG de Bootstrap (traen colores escritos).
Foco: botones, acciones de tabla, pestañas y navegación usan :focus-visible (el anillo aparece con el teclado, no con el mouse). Los campos de formulario muestran el foco siempre. El campo con error muestra su anillo rojo también sin foco.
Movimiento reducido: con prefers-reduced-motion el pulso y el esqueleto quedan fijos, y el spinner gira más lento (1.5 s) en lugar de detenerse.
Contrastes (WCAG AA: texto ≥ 4.5:1; íconos y gráficas ≥ 3:1; los elementos deshabilitados están exentos):
  --color-base-etiqueta #7D93A8 sobre base #0B1D2E ........ 5.38 (el diseño traía #6B8299: 4.29)
  --color-base-texto-2 #8FA3B8 sobre base / base-2 ........ 6.59 / 5.05
  --color-base-texto-claro #D5E1EC sobre base / base-2 .... 12.86 / 9.86
  --color-base-icono #8CBCEB (ícono) sobre base / base-2 .. 8.55 / 6.55
  --color-base-texto #B6C4D3 sobre base / hover #1A2B3B ... 9.62 / 8.14
  blanco sobre ítem activo #16324F / hover #1A2B3B ........ 13.10 / 14.46
  --color-acento-texto #1E4F80 sobre acento-fondo / tenue . 7.76 / 8.14
  --color-alerta-texto #B45309 sobre alerta-suave #FEF3C7 . 4.51
  --color-base sobre contador ámbar #F59E0B ............... 7.95
  --color-base-2 sobre icono-fondo / botón-texto-hover .... 11.73 / 11.93
  --color-texto-2 sobre superficie-hover / fila / fondo ... 5.20 / 5.29 / 5.02
  --color-desconocido-texto sobre desconocido-fondo ....... 6.92
  --color-marcador #66778C (placeholder) sobre blanco ..... 4.58 (el diseño usaba #94A3B8: 2.56)
  cerrar notificación y "—" sin dato → --color-texto-2 .... 5.44 (el diseño usaba #94A3B8: 2.56)
  --color-inactivo #EF4444 (ícono de error) sobre blanco .. 3.76
  --color-grafica-1 / -2 / -3 / -4 sobre blanco ........... 13.10 / 5.22 / 3.69 / 3.29
Motivo visual: el punto de estado de color. Aparece en tablas, tarjetas y dashboard, y pulsa mientras se ejecuta un ping.
Bootstrap se personaliza con sus variables CSS. El resultado no debe verse como Bootstrap por defecto.
Iconos y gráficas: Bootstrap Icons para los iconos y Chart.js para las gráficas, usando los colores de los tokens.
Estados de datos: toda vista que carga datos tiene tres estados diseñados.
Cargando: esqueleto.
Vacío: mensaje con una acción sugerida.
Error: notificación con un mensaje claro.
Accesibilidad:
El estado nunca se comunica solo con color: siempre va el punto más el texto ("Activo").
Contraste de texto AA.
Todos los campos de formulario tienen etiqueta.
Responsive: desde 360 px de ancho.
Acciones destructivas: eliminar siempre pide confirmación en un modal.
Página de muestra: cada componente nuevo se agrega también a web/componentes.html, la página de muestra del sistema de diseño. Sus estados hover y foco se muestran quietos con las clases .estado-hover y .estado-foco de web/css/componentes.css; por eso los componentes propios definen sus colores de hover con variables (--accion-*, --nav-*).
Flujo de trabajo
El proyecto avanza por las fases de docs/PLAN.md. Trabaja solo en la fase o tarea indicada y no adelantes funciones de otras fases.
Antes de un cambio grande, propón un plan y espera aprobación.
Al terminar una tarea, verifícala tú mismo:
Ejecuta php -l en los archivos modificados.
Prueba con curl (curl.exe en Windows) los endpoints afectados, incluyendo los casos de error.
Reporta qué probaste y qué resultado dio.
No instales librerías, frameworks ni herramientas nuevas sin preguntar.
Si cambias la estructura de la base de datos, actualiza database/schema.sql (y inicial.sql o seed.sql si hace falta) en el mismo cambio. Las bases que ya existen no se recrean solas: el cambio necesita una migración que conserve los datos.
Git:
Las Fases 0 y 1 van en main, porque son la base que todos necesitan.
Desde la Fase 2: una rama por servicio o pantalla (por ejemplo servicio-dispositivos o interfaz-dashboard), que se fusiona a main cuando cumple el "Listo cuando" de su fase en docs/PLAN.md.
Commits pequeños con mensajes en español que digan qué cambió, por ejemplo: Agrega endpoint para marcar alertas como atendidas.
Estado actual

Actualiza esta sección al cerrar cada fase.

Fase actual: ninguna. La mejora «Windows y guía del equipo» está completa y fusionada a main: v1.4 es la versión actual. La computadora con Linux quedó fuera de servicio y el desarrollo siguió en Windows 11 (XAMPP 8.2.12 en C:\xampp, PHP 8.2.12, MariaDB 10.4.32, Apache 2.4.58, idioma es-MX, consola CP850). Objetivo cumplido: el proyecto funciona en Windows siguiendo docs/GUIA-EQUIPO.md, sin quitar nada de Linux (las suites no se han vuelto a correr en Linux).
Vista del login en GitHub Pages (28 oct 2026, para una revisión): vista-login/ es una copia estática del inicio de sesión, solo HTML y CSS (sin Bootstrap ni JavaScript; Inter y Bootstrap Icons copiadas en vista-login/fuentes/, solo 4 íconos por código), con los valores de tokens.css copiados en su :root (si cambian allí, actualizarlos aquí) y la nota «Vista de muestra: no inicia sesión». La publica .github/workflows/pages-login.yml (solo esa carpeta). No es responsiva (tarjeta de 400 px fija) y no forma parte de la aplicación: el login real sigue siendo web/login.html.
Windows y guía del equipo (fusionada a main, etiqueta v1.4), resumen por subpaso:
- Subpaso 1 · Diagnóstico (hecho): ping real en español ("tiempo<1m", "Tiempo de espera agotado", 0.98 s; CP850 → UTF-8 correcto), route print -4 y arp -a en español leídos bien, .htaccess y mod_rewrite/mod_headers bien con CRLF, puertos 80/443/3306 solo de XAMPP, Firewall con «Apache HTTP Server» solo en redes privadas, Tailscale sin instalar, git sin identidad (se configuró en el repositorio: JazielAguirre <jazielxd99@gmail.com>). Correcciones: comun/http.php crea la carpeta del registro de llamadas si no existe (el XAMPP de Windows no trae C:\xampp\php\logs: ver-llamadas.php no mostraba nada; prueba en monitoreo.php §12); las suites se detienen al empezar con «Qué hacer» si tu base no existe o MySQL no responde (antes, error fatal a la mitad; problemaConTuBase() en ayudas.php, prueba en dispositivos.php §11); correrPhp() de dispositivos.php usa un script temporal (en Windows, escapeshellarg() cambia las comillas dobles por espacios: 3 comprobaciones fallaban). Cada corrección se rompió a propósito y su prueba lo detectó.
- Subpaso 2 · Funcionamiento básico (hecho): crear-base.php creó monitoreo_red_soa vacía (schema.sql e inicial.sql en CRLF se cargan bien; utf8mb4_unicode_ci). Con curl.exe: web/ 200, /servicios/auth/sesion 401 en JSON, login admin/admin123 200 con la cookie (HttpOnly, SameSite=Lax, 8 h), contraseña incorrecta 401. Bloqueado por HTTP (404, o 403 en servicios/comun/ y .htaccess): CLAUDE.md, README.md, .git/, database/, docs/, pruebas/, herramientas/, respaldos/, también con mayúsculas (/Database/Schema.sql), nombres cortos 8.3 (HERRAM~1, CONFIG~1.PHP), ::$DATA, punto o espacio al final y ..%5c. /WEB/index.html da 200: Apache de Windows lo lleva al nombre real (web/, pública). phpMyAdmin, licenses y webalizer de XAMPP: Require local.
- Subpaso 3 · Partes de Windows (hecho): las 6 suites con C:\xampp\php\php.exe: 1420 de 1420 (dispositivos 263, alertas 155, monitoreo 300, auth 127, descubrimiento 203, interfaz 372), un aviso justificado (sin /etc/resolv.conf no hay DNS local para el nombre de 127.0.0.1). Ping real: "tiempo<1m" → 0, "tiempo=3ms" → 3, "Tiempo de espera agotado" en ~0.8 s, y la salida real de "Host de destino inaccesible" (código 0, «recibidos = 1», sin TTL=) → inactivo; CP850 → UTF-8 bien; sección 15 de monitoreo.php con esas salidas reales y las de Windows en inglés, Linux y macOS (no había pruebas de la lectura del ping). Escáner real por Apache en la red de casa (permiso del usuario): 7 equipos en 7.1 s, módem por UPnP, dos por NetBIOS, teléfonos sin nombre, igual que en Linux. Chrome 156 y Edge 155 (CHROME_BIN): cierran todos sus procesos y borran el perfil temporal. crear-base.php, restaurar-respaldo.php, preparar-demo.php (sin cambiar la base) y ver-llamadas.php en vivo funcionan. Correcciones: en Windows un pipe de proc_open no se puede leer sin bloquear (stream_select lo da por listo vacío y la lectura espera hasta ~32 s o a que el proceso termine): el respondedor de nombres y monitorear-rondas escriben su salida en un archivo temporal (descubrimiento.php pasó de 110 s a 26 s); el manejador de Ctrl+C de Windows solo corre cuando termina el usleep() en curso: monitorear-rondas.php espera en pasos de 0.2 s y Ctrl+C muestra el resumen al instante (prueba con Ctrl+Break, antes omitida en Windows); conectar a un puerto cerrado tarda ~2 s en Windows (reintenta el SYN): la sección 9 de descubrimiento.php lo descuenta; exportar() de ayudas.php ya no se cae con texto que no es UTF-8. Fuera de Windows: interfaz.php suponía datos del seed en «los últimos 7 días» (fechas fijas del 27/09): desde el 4/10 fallaba; ahora el rango va en la URL. Roturas a propósito detectadas: espera de un golpe, detener que espera al respondedor, pings antes de preguntar a Dispositivos, sin CP850, «responde» por «recibidos = 1», sin «<» en el tiempo, «Reintentar» sin gráfica.
- Subpaso 4 · Encendido en un clic (hecho): herramientas/iniciar-servicios.bat (doble clic; ejecuta el .ps1 con -ExecutionPolicy Bypass y, con doble clic, espera una tecla al final) e iniciar-servicios.ps1 (UTF-8 con BOM, sin administrador): enciende Apache (httpd.exe) y MySQL (mysqld --defaults-file=mysql\bin\my.ini --standalone) con Start-Process oculto solo si no están, y confirma por HTTP y con mysqladmin ping; si otro programa tiene el 80, 443 o 3306 dice cuál (programa, servicio, Windows/http.sys o Docker) y cómo detenerlo; Tailscale: estado e IP 100.x con tailscale status --json (no ejecuta tailscale up); Firewall: reglas de entrada de httpd.exe contra los perfiles Private y Public (un Block gana) y el comando New-NetFirewallRule exacto, que nunca ejecuta (✗ si falta el perfil de una red conectada, ⚠ si no); preparar-demo.php con tope de 90 s; resumen con localhost, la WiFi, Tailscale y Live Server. .gitattributes: *.sh eol=lf, *.ps1 y *.bat eol=crlf (iniciar-servicios.sh vuelve a LF en la copia de Windows). Probado en vivo: ya encendidos (8 s), Apache y MySQL apagados → los enciende, intruso en el 80 y en el 3306 → ✗ con programa y PID. monitoreo.php §16: funciones con datos de ejemplo y el .bat de punta a punta sin la política de ejecución heredada (328 de 328). Roturas detectadas: .bat sin Bypass, Block ignorado, Apache nunca encendido, IPv6 como IP de Tailscale. Hallazgo: un mysqld lanzado desde un Start-Job (sin consola) cerró con «InnoDB: Operating system error number 6» o se colgó al apagarlo; lanzado desde una consola o con la salida a un pipe, como lo usa el equipo, cerró limpio 6 de 6 veces (solo el aviso «aria_log_control … Errcode: 9», que también da el MySQL que abre el panel de XAMPP).
- Subpaso 5 · Guía del equipo (hecho): docs/GUIA-EQUIPO.md (13 secciones: cómo usarla, qué es y la arquitectura SOA, requisitos con enlaces y versiones, instalación en Windows desde cero con git o GitHub Desktop, Linux resumido, recorrido de prueba con 127.0.0.1 y 192.0.2.20, celular con Tailscale y Share, encendido en un clic, las 6 suites, problemas comunes, mapa del repositorio, git en equipo, qué se comprobó y qué falta), enlazada al inicio del README. docs/WINDOWS.md reescrito con lo comprobado, lo corregido y lo pendiente; su comando de las 6 suites ahora pone la consola en UTF-8 (sin eso, Select-String no veía ningún ✓ ni ✗: probado 0 contra 155). Tailscale instalado por el usuario (1.104.2, la red Tailscale queda como Privada): el .bat termina en «Todo encendido.» y el celular del usuario, con datos móviles, cargó la pantalla de inicio de sesión por la IP 100.x (registro de Apache); no inició sesión desde ahí. Live Server no está instalado: según su código (ritwickdey 5.7.10), sin configurar escucha solo en 127.0.0.1; la guía y el resumen de los dos scripts dicen poner liveServer.settings.host en 0.0.0.0 (no comprobado).
- Subpaso 6 · Verificación final (hecho, con una parte sin comprobar): las 6 suites en la carpeta del usuario, con el comando exacto de la guía: 1448 de 1448 en 6.4 min (dispositivos 263, alertas 155, monitoreo 328, auth 127, descubrimiento 203, interfaz 372), un aviso justificado; el patrón de ese comando quedó en '^Resultado|^\s*✗|! Aviso' (sin anclar mostraba líneas ✓ que mencionan ✗, «avisos» y «resultado»). Sin comprobar: seguir la guía en una copia recién clonada dentro de htdocs. Para renombrar la carpeta hay que soltarla: la tienen abierta la sesión de Claude Code y la pestaña de la Terminal del panel (un script aparte lo intentó 10 veces sin cambiar nada), y el usuario prefirió no hacerlo. Un clon nuevo fuera de htdocs sí se revisó: .sh LF, .ps1/.bat CRLF, .ps1 con BOM, sin errores de sintaxis, git status limpio. Fusión con --no-ff a main, etiqueta v1.4 y push de main y la etiqueta (credencial de GitHub ya guardada en el administrador de credenciales de Windows).
Antes (Linux): las Fases 0–10 y la mejora «Nombres en el escáner» están completas y fusionadas a main. Etiquetas: v1.4 es la versión actual (Windows y guía del equipo); v1.3 tiene el escáner con el nombre que anuncia cada equipo; v1.2 tiene los datos reales con el escáner solo por DNS; v1.1 tiene el escáner con los datos de ejemplo; v1.0 es la versión sin escáner. Repositorio: https://github.com/JazielAguirre/monitoreo-red-soa (en Linux, remoto origin por SSH; en esta computadora con Windows, por HTTPS con el administrador de credenciales de Git).
Nombres en el escáner (fusionada a main, etiqueta v1.3), resumen: el escáner pide el nombre por UPnP, mDNS, NetBIOS y DNS a la vez (nombres.php, unicast, 0.8 s en total), con preferencia UPnP → mDNS → NetBIOS → DNS y el campo nuevo nombre_origen; la interfaz muestra el origen pequeño junto al nombre y «—» con ayuda si no hay. Pruebas con paquetes fijos y respondedor falso en 127.0.0.x (puertos_nombres en la red simulada); las 6 suites: 1398 de 1398 (dispositivos 260, alertas 155, monitoreo 282, auth 127, descubrimiento 202, interfaz 372). Prueba real en 192.168.0.0/24 por Apache con ufw activo: módem por UPnP, dos equipos por NetBIOS, la laptop por mDNS; tres teléfonos sin nombre (no contestan la consulta inversa; ufw solo bloqueó IGMP, ninguna respuesta de nombres).
La base de uso diario (monitoreo_red_soa) tiene los datos reales del usuario (en Linux la vació y registró sus equipos; en la computadora con Windows se creó vacía el 20 de oct de 2026 con crear-base.php y ahí empieza de cero). Nunca recrearla ni vaciarla por iniciativa propia; las pruebas usan solo monitoreo_red_soa_pruebas. Respaldo de precaución anterior: respaldos/monitoreo_red_soa-20261002-085732-antes-de-datos-reales.sql (los datos de ejemplo).
Fase 10 (fusionada a main, etiqueta v1.2), resumen: inicial.sql (admin), schema.sql sin DROP, seed.sql solo ejemplo; crear-base.php, restaurar-respaldo.php; base de pruebas monitoreo_red_soa_pruebas con interruptor que caduca y tráfico marcado; preparar-demo.php sin cambiar la base (--datos-de-ejemplo con respaldo y SI); monitorear-rondas.php; estados vacíos para una base sin dispositivos; DEMO.md con los equipos reales (tabla de equipos, pantallas encendidas, modo avión y quitarlo al final, ensayo completo el día anterior) y su ensayo en interfaz.php 8.4. Dos incidentes durante el desarrollo escribieron en la base del usuario (código viejo con git stash a las 09:09; proceso de prueba que no recibió Ctrl+C a las 23:06); las dos veces se restauró del respaldo de las 08:57 sin pérdida, y el segundo llevó a marcar el tráfico de las pruebas.
Las Fases 0–9 están completas y fusionadas a main. Etiquetas: v1.1 es la versión actual, con el escáner de red (Fase 9); v1.0 queda como respaldo sin el escáner (la que se presenta si el escáner no puede usarse). Repositorio: https://github.com/JazielAguirre/monitoreo-red-soa (remoto origin por SSH).
Pendientes del equipo (no requieren código):
- Ensayar en vivo el tramo de «Buscar dispositivos en la red» con un segundo teléfono en el hotspot (en Windows ya se probó en una red de casa).
- Correr las 6 suites en Linux con v1.4 (se cambiaron pruebas y herramientas en Windows sin poder probarlas en Linux).
- Probar en una red pública (hotspot nuevo) con la regla del Firewall para redes públicas (New-NetFirewallRule … -Profile Public, como administrador: el .bat la muestra), Live Server con liveServer.settings.host en 0.0.0.0, iniciar sesión desde el celular por Tailscale y Share con la cuenta de otro integrante.
- Seguir docs/GUIA-EQUIPO.md en una copia recién clonada (no se pudo renombrar la carpeta de trabajo).
- Confirmar con el profesor el tiempo disponible (el guion dura ~5:30; ~5:05 sin la recarga del celular).
- Ensayar docs/DEMO.md en vivo, cronometrado, y grabar el video de respaldo.
- Repetir las 6 suites en la computadora de otro integrante (Windows: docs/WINDOWS.md, lista para la primera vez).
- Si es posible, conectar la computadora al proyector real del salón (hasta ahora se simuló con Chrome a 1024×768 y 1280×720).
Completado:
- Fase 0 — Preparación y prueba de ping: estructura del proyecto, comun/config.php y comun/respuesta.php, puntos de entrada de dispositivos, alertas y monitoreo con reescritura de URLs, esqueletos HTML, librerías en web/vendor/, repositorio git, .htaccess que solo expone web/ y los servicios, y servicios/monitoreo/ping.php probado a través de Apache (127.0.0.1 y la puerta de enlace activos, 192.0.2.1 inactivo en ~1 s, entradas inválidas → 400 sin ejecutar comandos).
- Fase 1 — Base de datos: database/schema.sql con las 4 tablas de docs/API.md (FK con ON DELETE CASCADE, índices, utf8mb4_unicode_ci), database/seed.sql (6 dispositivos, 56 monitoreos, 1 alerta pendiente y 3 atendidas, usuario admin) y comun/db.php (PDO con EMULATE_PREPARES en false y colación utf8mb4_unicode_ci). Verificado: carga desde cero repetible, coherencia de ultimo_* con el último monitoreo, UNIQUE, FK y cascada (con ROLLBACK).
- Fase 2 — Servicio de Dispositivos: los 6 endpoints de docs/API.md; infraestructura en comun/ (peticion.php, enrutador.php con 404/405 y cabecera Allow, configurarErrores()); pruebas/ayudas.php y pruebas/dispositivos.php (186 comprobaciones). Con MySQL apagado, los 6 endpoints responden 500 en JSON sin detalles (prueba manual).
- Fase 3 — Servicio de Alertas: los 4 endpoints de docs/API.md con el patrón de la Fase 2; columna generada alertas.dispositivo_pendiente con índice único (una sola pendiente por dispositivo, también con POST simultáneos: 20 de 20 rondas de 10 POST resolvieron la carrera con el 1062, sin 500 ni deadlocks); pruebas/alertas.php (141 comprobaciones; la de concurrencia se omite con aviso si PHP no tiene curl).
- Fase 4 — Servicio de Monitoreo: POST /monitoreo/{id} con el flujo de 5 pasos solo por HTTP (comun/http.php), GET /monitoreo con filtros, paginación y resumen en SQL, GET /monitoreo/recientes con ROW_NUMBER(); endpoint temporal /monitoreo/prueba eliminado; pruebas/monitoreo.php (188 comprobaciones, incluidos los 502 con el archivo de fallo simulado y la regla SOA). Las tres suites pasan.
- Fase 5 — Sistema de diseño: tokens.css (único archivo con colores, agrupado por categoría, fuentes locales), app.css (Bootstrap por componente y componentes propios de las secciones 01–14, :focus-visible, prefers-reduced-motion) y componentes.html con componentes.css y componentes.js. Comparado con la referencia a 1440 y 390 px, sin peticiones externas, contrastes AA documentados. Aprobado por el equipo.
- Fase 6 — Pantallas: Dashboard, Dispositivos, Monitoreos y Alertas con los servicios reales. api.js (URL base relativa) y módulos compartidos en web/js/modulos/ (formatos, notificaciones, vistas, estructura, piezas, gráficas, monitorear-todos, formulario-dispositivo, modales); estados de carga, vacío y error en cada vista; filtros en la URL; adaptaciones de celular (tarjetas en Dispositivos, filas en el Dashboard, filas compactas del historial en Monitoreos con una container query, tarjetas apiladas en Alertas); piezas en componentes.html (secciones 15–31). pruebas/interfaz.php con su propio Chrome headless (235 comprobaciones, incluidos el nombre con HTML en las cuatro pantallas y el foco visible con el teclado). Revisado por el equipo contra docs/diseno/, probado en un celular real por la WiFi y con MySQL apagado de verdad.
- Fase 7 — Inicio de sesión: Servicio de Autenticación (login, logout, sesión) con el patrón de los demás; comun/auth.php con acceso por ruta ([Interfaz]: sesión o clave → 401; [Interno]: solo clave → 403; clave aleatoria comparada con hash_equals); sesión leída con read_and_close (sin bloqueo: 3 peticiones que esperan 3 s tardan 3 s juntas, no 9); cookie HttpOnly, SameSite=Lax, ruta del proyecto, 8 h; login con el mismo mensaje y tiempo exista o no el usuario. login.html (5a y 5b), redirección con «volver» validado, iniciarPagina() en cada pantalla, usuario real y «Salir». pruebas/auth.php (126) y las demás suites con sesión; interfaz.php entra por la pantalla real. «Monitorear todos» tarda lo mismo que antes (5.30 s contra 5.29 s). Probado a mano por el equipo en la computadora y en el celular.
- Fase 8 — Integración, pruebas y demo: web/.htaccess con Cache-Control: no-cache (vendor/ sin cambios), favicon.svg, ajustes para proyector (columnas de Dispositivos por container query, filtros de Monitoreos, fecha compacta). herramientas/preparar-demo.php (fechas movidas a hace 10 min, IPs detectadas, indicadas o --sin-red, comprobación previa con ✓/✗ y «Qué hacer»), registro de llamadas entre servicios con herramientas/ver-llamadas.php. README completo y docs/WINDOWS.md. docs/DEMO.md: guion de 5 min para 4 integrantes (70–80 s cada uno, frases a 130 palabras por minuto), preguntas con quién responde, plan B y «Si pasa X, haz Y», revisado por el equipo. Con el seed, «Monitorear todos» crea 2 alertas en vivo (Impresora y Servidor de respaldo): el contador va de 1 a 3 (a 4 si el router no responde o con --sin-red). Las 5 suites pasan (dispositivos 207, alertas 154, monitoreo 221, auth 126, interfaz 319, con el ensayo completo del guion) y pasaron en una copia recién clonada siguiendo el README. Al cambiar el enlace de htdocs, PHP sigue un rato con la carpeta anterior (realpath_cache_ttl = 120 s): reiniciar Apache o esperar 130 s antes de probar y antes de borrar la carpeta anterior.
- Fase 9 — Descubrimiento de red (fusionada a main, etiqueta v1.1): Servicio de Descubrimiento sin tablas (GET /descubrimiento/red y POST /descubrimiento/red/lotes/{n}): /24 de la IP propia calculada en el servidor y solo privada, ping en paralelo con proc_open (64 direcciones en ~1 s, no 64 s), tabla ARP de Linux/Windows/macOS sin depender del idioma, DNS inverso por UDP con 0.8 s como máximo y registrados pedidos a Dispositivos por HTTP; los servicios existentes no cambiaron (en Monitoreo solo el require de ping.php). Pantalla Dispositivos: «Buscar dispositivos en la red» con progreso por lote, lista y «Agregar» prellenado; sección 33 de componentes.html. pruebas/descubrimiento.php (112) con red simulada, secciones 9.x de interfaz.php y el tramo en el ensayo del guion (352). Probado en el hotspot real a través de Apache: 7 equipos en ~4.4 s, 2 solo por ARP. 12 roturas a propósito detectadas. Tras la revisión: preparar-demo.php marca ⚠ «El router bloquea el ping, pero está conectado (aparece en ARP)» en lugar de ✗ (lee la tabla ARP con tablaArpDelServicio() de arp.php; probado con la red simulada en pruebas/monitoreo.php) y DEMO.md suma la pregunta del router inactivo y que en el hotspot solo haya equipos del equipo. Las 6 suites: 1174 de 1174 (dispositivos 207, alertas 154, monitoreo 223, auth 126, descubrimiento 112, interfaz 352).
Pendientes o decisiones abiertas:
- Con EMULATE_PREPARES en false, PDO ya devuelve INT como int, pero DECIMAL sigue como texto y TINYINT como int (1): cada servicio convierte a número y booleano antes de responder (Dispositivos lo hace en formatearDispositivo()).
- El 1452 de POST /monitoreo (dispositivo borrado entre el paso 1 y el 3) se prueba en datos.php, no de punta a punta: provocar esa carrera por HTTP no es confiable.
- La columna generada VIRTUAL con índice único y FK en cascada está probada en MariaDB 10.4 (la del XAMPP); no se ha probado en MySQL 8.
- Las fechas del seed son fijas (última ronda: 2026-09-27 09:45); en la demo se refrescan con "Monitorear todos".
- Los .htaccess de los servicios usan RewriteOptions AllowNoSlash junto con DirectorySlash Off; sin él, las URLs sin barra final dan 403/404.
