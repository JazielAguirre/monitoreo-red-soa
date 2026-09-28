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
Otros integrantes en Windows: el proyecto va en C:\xampp\htdocs\monitoreo-red-soa, los binarios son C:/xampp/php/php.exe y C:/xampp/mysql/bin/mysql.exe, Apache y MySQL se encienden desde el panel de XAMPP, y en PowerShell se usa curl.exe (ahí "curl" es otro comando).
Comandos
bash
# Encender Apache y MySQL de XAMPP
sudo /opt/lampp/lampp startapache
sudo /opt/lampp/lampp startmysql

# Recrear la base de datos desde cero y cargar datos de prueba
/opt/lampp/bin/mysql -u root < database/schema.sql
/opt/lampp/bin/mysql -u root monitoreo_red_soa < database/seed.sql

# Pruebas automáticas de un servicio (recargan la base de datos al empezar)
/opt/lampp/bin/php pruebas/dispositivos.php

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
database/        schema.sql (recrea la BD completa), seed.sql (datos de prueba)
docs/            PLAN.md (fases del proyecto), API.md (contrato de endpoints), diseno/ (referencia visual)
servicios/
├── comun/       config.php, db.php, respuesta.php, peticion.php, enrutador.php, http.php, auth.php (.htaccess: Require all denied)
├── dispositivos/  index.php + .htaccess, endpoints.php, validacion.php, datos.php → dueño de la tabla dispositivos
├── alertas/       index.php + .htaccess   → dueño de la tabla alertas
├── monitoreo/     index.php + .htaccess + ping.php → dueño de la tabla monitoreos
└── auth/          index.php               → dueño de la tabla usuarios
web/
├── css/         tokens.css (variables de diseño), app.css (componentes)
├── js/          api.js (único punto de acceso a la API) + un archivo por página
├── vendor/      bootstrap, bootstrap-icons, chart.js, fuentes Inter y JetBrains Mono
└── *.html       login, index (dashboard), dispositivos, monitoreos, alertas, componentes
pruebas/         ayudas.php + un script por servicio (dispositivos.php, ...). Solo línea de comandos; no accesible por HTTP
Reglas SOA (no negociables)
Cada servicio solo lee y escribe sus propias tablas.
Si un servicio necesita datos o acciones de otro, los pide por HTTP usando servicios/comun/http.php. Nunca hace require del código de otro servicio ni consultas SQL a tablas ajenas.
Ejemplo: Monitoreo obtiene la IP con GET /dispositivos/{id}, actualiza el estado con PATCH /dispositivos/{id}/estado y crea alertas con POST /alertas.
La interfaz nunca accede a la base de datos: todo pasa por web/js/api.js.
comun/ contiene solo infraestructura (conexión, respuestas, cliente HTTP, autenticación). Nunca lógica de negocio.
Las URLs base y la clave interna viven en comun/config.php. Nada hardcodeado en los servicios.
Patrón de un servicio (lo fija Dispositivos): index.php declara las rutas y llama a despachar() de comun/enrutador.php dentro de try/catch; endpoints.php tiene una función por endpoint; validacion.php las reglas; datos.php todo el SQL y la conversión de tipos. comun/peticion.php lee método, ruta y cuerpo JSON.
Por HTTP, cada servicio solo ejecuta su index.php: su .htaccess envía ahí todas las rutas, incluidos los archivos que existen (por ejemplo ping.php). No agregues RewriteCond %{REQUEST_FILENAME} !-f.
Contrato de la API

La fuente de verdad es @docs/API.md. Si una tarea requiere cambiar un endpoint, un campo o un formato, pregunta antes y actualiza docs/API.md en el mismo cambio.

Respuesta exitosa: { "ok": true, "data": ... }
Error: { "ok": false, "error": "Mensaje claro para el usuario" }
Errores de validación (400 y 409): pueden llevar el campo opcional "campos", con un mensaje por campo: { "ok": false, "error": "Revisa los datos del formulario", "campos": { "ip": "Dirección IP no válida" } }
Códigos HTTP: 200 OK, 201 creado, 400 datos inválidos, 401 sin sesión, 403 endpoint interno sin clave de servicio (desde la Fase 7), 404 no existe, 405 método no permitido, 409 conflicto (por ejemplo, IP duplicada), 500 error interno, 502 otro servicio no respondió o respondió algo inesperado.
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
Ping (Servicio de Monitoreo)
Implementación: servicios/monitoreo/ping.php (hacerPing() devuelve responde, tiempo_respuesta_ms y salida).
Seguridad: la IP se valida con FILTER_VALIDATE_IP y pasa por escapeshellarg() antes de exec(). Nunca ejecutes un comando con texto que no haya sido validado.
Solo IPv4 (FILTER_VALIDATE_IP con FILTER_FLAG_IPV4): así no hay que manejar las diferencias de ping con IPv6 entre sistemas operativos.
El sistema operativo se detecta con PHP_OS_FAMILY:
Windows: ping -n 1 -w 1000 {ip}
Linux: ping -c 1 -W 1 {ip}
macOS (Darwin): ping -c 1 -t 1 {ip}
En Windows no confíes en el código de salida: con "Host de destino inaccesible" puede devolver 0. El dispositivo está activo solo si la salida contiene TTL= (sin distinguir mayúsculas).
El tiempo de respuesta se extrae aceptando salida en inglés y en español: time=2ms, time<1ms, tiempo=2ms, tiempo<1m, time=0.032 ms. Se redondea a 2 decimales. Si la salida indica <1, guarda 0 y la interfaz lo muestra como < 1 ms.
Espera máxima: 1 segundo por dispositivo.
Solo se crea una alerta si el dispositivo no tiene ya una alerta pendiente, para evitar alertas repetidas en cada ping fallido.
IPs de prueba: 127.0.0.1 siempre responde. 192.0.2.1 (rango reservado para documentación) nunca responde.
Prueba el ping a través de Apache (con curl al endpoint), no solo con php en la terminal: Apache corre como daemon y puede tener permisos distintos a tu usuario para ejecutar ping.
Autenticación
Login con password_hash() / password_verify() y sesiones de PHP.
Peticiones del navegador: requieren sesión activa. Sin sesión, el servicio responde 401 y la interfaz redirige a login.html.
Peticiones entre servicios: no llevan sesión. Se autentican con la cabecera X-Servicio-Clave, cuyo valor está en comun/config.php.
Se implementa en la Fase 7. Antes de eso, los servicios funcionan sin login.
Diseño de la interfaz
Tokens: colores, tipografía, espaciados, radios y sombras son variables CSS en web/css/tokens.css. Ningún otro archivo usa valores hexadecimales sueltos.
Referencia visual: docs/diseno/. "Monitoreo de Red SOA.dc.html" es el sistema de diseño (valores finales), "Monitoreo de Red SOA · Pantallas.dc.html" y el PDF muestran las pantallas. Es solo referencia: no copies su código (usa React internamente y carga recursos desde internet).
Paleta (valores finales del :root del sistema de diseño):
Base: --color-base #0B1D2E, --color-base-2 #16324F, --color-base-texto #B6C4D3 (texto sobre la base azul marino).
Fondo y superficies: --color-fondo #F3F6F9, --color-superficie #FFFFFF, --color-borde #E2E8F0.
Texto: --color-texto #1E293B, --color-texto-2 #5B6B80 (secundario; reemplaza a #64748B, que no llegaba a AA sobre el fondo).
Acciones: --color-acento #2F6FB0, --color-peligro #DC2626 (acciones destructivas).
Estados (color / -texto / -fondo):
  activo       #22C55E / #15803D / #F0FDF4
  inactivo     #EF4444 / #B91C1C / #FEF2F2
  alerta       #F59E0B / #B45309 / #FFFBEB
  desconocido  #94A3B8 / #475569 / #F1F5F9
El color de estado es para el punto; el texto de estado usa la variante -texto (AA sobre -fondo o blanco). El verde #22C55E sobre blanco da 2.3:1: nunca como texto.
Radios: --radio-sm 6px, --radio 8px, --radio-lg 12px.
Sombras:
  --sombra-1: 0 1px 2px rgb(11 29 46 / .05)
  --sombra-2: 0 4px 12px -2px rgb(11 29 46 / .10)
  --sombra-3: 0 20px 40px -8px rgb(11 29 46 / .28)
Bootstrap: --bs-primary: var(--color-base-2); --bs-body-bg: var(--color-fondo); --bs-body-font-family: Inter; --bs-border-radius: var(--radio).
Tipografía:
Inter para la interfaz (web/vendor/inter/), con respaldo system-ui, sans-serif.
JetBrains Mono para IPs, horas y tiempos (web/vendor/jetbrains-mono/, pesos 400, 500 y 600), con respaldo ui-monospace, monospace y font-variant-numeric: slashed-zero (cero con barra).
Escala (tamaño / interlineado en px · peso):
  Display (login)     28 / 36 · 600 · letter-spacing -1%
  Título de página    22 / 30 · 600
  Título de tarjeta   16 / 24 · 600
  Cuerpo              14 / 20 · 400
  Monoespaciada       13 / 20 · 500 · JetBrains Mono
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
Página de muestra: cada componente nuevo se agrega también a web/componentes.html, la página de muestra del sistema de diseño.
Flujo de trabajo
El proyecto avanza por las fases de docs/PLAN.md. Trabaja solo en la fase o tarea indicada y no adelantes funciones de otras fases.
Antes de un cambio grande, propón un plan y espera aprobación.
Al terminar una tarea, verifícala tú mismo:
Ejecuta php -l en los archivos modificados.
Prueba con curl (curl.exe en Windows) los endpoints afectados, incluyendo los casos de error.
Reporta qué probaste y qué resultado dio.
No instales librerías, frameworks ni herramientas nuevas sin preguntar.
Si cambias la estructura de la base de datos, actualiza database/schema.sql (y seed.sql si hace falta) en el mismo cambio.
Git:
Las Fases 0 y 1 van en main, porque son la base que todos necesitan.
Desde la Fase 2: una rama por servicio o pantalla (por ejemplo servicio-dispositivos o interfaz-dashboard), que se fusiona a main cuando cumple el "Listo cuando" de su fase en docs/PLAN.md.
Commits pequeños con mensajes en español que digan qué cambió, por ejemplo: Agrega endpoint para marcar alertas como atendidas.
Estado actual

Actualiza esta sección al cerrar cada fase.

Fase actual: 2 (ver docs/PLAN.md)
Completado:
- Fase 0 — Preparación y prueba de ping: estructura del proyecto, comun/config.php y comun/respuesta.php, puntos de entrada de dispositivos, alertas y monitoreo con reescritura de URLs, esqueletos HTML, librerías en web/vendor/, repositorio git, .htaccess que solo expone web/ y los servicios, y servicios/monitoreo/ping.php probado a través de Apache (127.0.0.1 y la puerta de enlace activos, 192.0.2.1 inactivo en ~1 s, entradas inválidas → 400 sin ejecutar comandos).
- Fase 1 — Base de datos: database/schema.sql con las 4 tablas de docs/API.md (FK con ON DELETE CASCADE, índices, utf8mb4_unicode_ci), database/seed.sql (6 dispositivos, 56 monitoreos, 1 alerta pendiente y 3 atendidas, usuario admin) y comun/db.php (PDO con EMULATE_PREPARES en false y colación utf8mb4_unicode_ci). Verificado: carga desde cero repetible, coherencia de ultimo_* con el último monitoreo, UNIQUE, FK y cascada (con ROLLBACK).
Pendientes o decisiones abiertas:
- GET /servicios/monitoreo/prueba es temporal: eliminarlo en la Fase 4 junto con atenderPruebaPing().
- Con EMULATE_PREPARES en false, PDO ya devuelve INT como int, pero DECIMAL sigue como texto y TINYINT como int (1): cada servicio convierte a número y booleano antes de responder.
- Las fechas del seed son fijas (última ronda: 2026-09-27 09:45); en la demo se refrescan con "Monitorear todos".
- Los .htaccess de los servicios usan RewriteOptions AllowNoSlash junto con DirectorySlash Off; sin él, las URLs sin barra final dan 403/404.
