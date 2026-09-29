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
/opt/lampp/bin/php pruebas/alertas.php
/opt/lampp/bin/php pruebas/monitoreo.php
/opt/lampp/bin/php pruebas/interfaz.php   # abre su propio Chrome headless (perfil temporal); se omite si no hay Chrome

# Colores fuera de tokens.css, solo en estilos (CSS, JS, style="" y <style>). Debe no imprimir nada.
# Los hexadecimales como texto visible en componentes.html son documentación y no se revisan.
grep -rnE '#[0-9a-fA-F]{3,8}\b|\b(rgba?|hsla?)\(' web/css web/js --include='*.css' --include='*.js' | grep -v '^web/css/tokens\.css:'
grep -nE 'style="[^"]*(#[0-9a-fA-F]{3,8}|(rgba?|hsla?)\()|<style' web/*.html

# URLs externas en la interfaz (fuera de vendor/). Debe no imprimir nada.
grep -rnE 'https?://' web --exclude-dir=vendor

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
├── comun/       config.php, db.php, respuesta.php, peticion.php, enrutador.php, http.php (cliente entre servicios), auth.php (.htaccess: Require all denied)
├── dispositivos/  index.php + .htaccess, endpoints.php, validacion.php, datos.php → dueño de la tabla dispositivos
├── alertas/       index.php + .htaccess, endpoints.php, validacion.php, datos.php → dueño de la tabla alertas
├── monitoreo/     index.php + .htaccess, endpoints.php, validacion.php, datos.php, clientes.php (llamadas HTTP a Dispositivos y Alertas), ping.php → dueño de la tabla monitoreos
└── auth/          index.php               → dueño de la tabla usuarios
web/
├── css/         tokens.css (variables de diseño, único archivo con colores), app.css (componentes y Bootstrap), componentes.css (solo la página de muestra)
├── js/          api.js (único punto de acceso a la API, URL base relativa) + modulos/ (formatos, notificaciones, vistas, estructura, gráficas…) + un módulo ES por página
├── vendor/      bootstrap, bootstrap-icons, chart.js, fuentes Inter y JetBrains Mono
└── *.html       login, index (dashboard), dispositivos, monitoreos, alertas, componentes
pruebas/         ayudas.php + un script por servicio (dispositivos.php, ...) + interfaz.php con navegador.php (cliente del protocolo de Chrome). Solo línea de comandos; no accesible por HTTP
Reglas SOA (no negociables)
Cada servicio solo lee y escribe sus propias tablas.
Si un servicio necesita datos o acciones de otro, los pide por HTTP usando servicios/comun/http.php. Nunca hace require del código de otro servicio ni consultas SQL a tablas ajenas.
Ejemplo: Monitoreo obtiene la IP con GET /dispositivos/{id}, actualiza el estado con PATCH /dispositivos/{id}/estado y crea alertas con POST /alertas.
comun/http.php: llamarServicio() usa streams de PHP (no curl), espera TIEMPO_ESPERA_SERVICIOS (config.php) y envía X-Servicio-Clave. Si el otro servicio no responde o no devuelve el JSON del formato común, lanza ErrorDeComunicacion, que el index.php responde como 502 "No se pudo comunicar con el Servicio de {nombre}".
Archivo de fallo simulado (pruebas/.fallo-simulado.json, leído por comun/http.php): solo lo usan las pruebas para simular que Dispositivos o Alertas no responde. Caduca solo (máximo 60 s), solo admite modos fijos hacia 127.0.0.1 o web/index.html, y no puede activarse por HTTP (ningún endpoint escribe archivos y pruebas/ responde 404). Detalle en docs/PLAN.md, "Pruebas automáticas".
La interfaz nunca accede a la base de datos: todo pasa por web/js/api.js.
Seguridad en la interfaz: los datos de la API se insertan con textContent o pasan por escaparHtml() de web/js/modulos/formatos.js; nunca directo en innerHTML. Las fechas de la API se separan con partesFecha(), nunca con new Date(texto) (Safari del iPhone las toma como inválidas). Los formularios usan novalidate: los mensajes de validación vienen solo de la API.
comun/ contiene solo infraestructura (conexión, respuestas, cliente HTTP, autenticación). Nunca lógica de negocio.
Las URLs base y la clave interna viven en comun/config.php. Nada hardcodeado en los servicios.
Patrón de un servicio (lo fija Dispositivos): index.php declara las rutas y llama a despachar() de comun/enrutador.php dentro de try/catch; endpoints.php tiene una función por endpoint; validacion.php las reglas; datos.php todo el SQL y la conversión de tipos. comun/peticion.php lee método, ruta, parámetros de la URL (filtroOpcional()) y cuerpo JSON.
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
Solo se crea una alerta si el dispositivo no tiene ya una alerta pendiente, para evitar alertas repetidas en cada ping fallido. La regla vive en el Servicio de Alertas (POST /alertas) y la garantiza la base de datos con el índice único de alertas.dispositivo_pendiente, también con peticiones simultáneas.
IPs de prueba: 127.0.0.1 siempre responde. 192.0.2.1 (rango reservado para documentación) nunca responde. Las pruebas automáticas usan solo 127.0.0.1 y 192.0.2.x, nunca IPs de la red local, para pasar en cualquier red o sin red.
Prueba el ping a través de Apache (con curl al endpoint), no solo con php en la terminal: Apache corre como daemon y puede tener permisos distintos a tu usuario para ejecutar ping.
Autenticación
Login con password_hash() / password_verify() y sesiones de PHP.
Peticiones del navegador: requieren sesión activa. Sin sesión, el servicio responde 401 y la interfaz redirige a login.html.
Peticiones entre servicios: no llevan sesión. Se autentican con la cabecera X-Servicio-Clave, cuyo valor está en comun/config.php.
Se implementa en la Fase 7. Antes de eso, los servicios funcionan sin login.
Diseño de la interfaz
Tokens: colores, tipografía, espaciados, radios y sombras son variables CSS en web/css/tokens.css. Ningún otro archivo usa valores hexadecimales sueltos.
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
Si cambias la estructura de la base de datos, actualiza database/schema.sql (y seed.sql si hace falta) en el mismo cambio.
Git:
Las Fases 0 y 1 van en main, porque son la base que todos necesitan.
Desde la Fase 2: una rama por servicio o pantalla (por ejemplo servicio-dispositivos o interfaz-dashboard), que se fusiona a main cuando cumple el "Listo cuando" de su fase en docs/PLAN.md.
Commits pequeños con mensajes en español que digan qué cambió, por ejemplo: Agrega endpoint para marcar alertas como atendidas.
Estado actual

Actualiza esta sección al cerrar cada fase.

Fase actual: 6 — Pantallas, en la rama interfaz-pantallas (no se fusiona a main hasta que el equipo la revise).
Progreso de la Fase 6 (un commit por subpaso):
- 6.1 hecho: api.js (URL base relativa, ErrorApi), modulos/formatos.js, notificaciones.js, vistas.js; arnés pruebas/navegador.php + pruebas/interfaz.php.
- 6.2 hecho: modulos/estructura.js (barra lateral, encabezado móvil con menú, contador de alertas) y encabezado de página.
- 6.3 hecho: Dispositivos (filtros, tabla y tarjetas de celular, monitorear una fila y todos, agregar/editar con hoja inferior en celular, eliminar), modulos/piezas.js, monitorear-todos.js, formulario-dispositivo.js, modales.js; piezas en componentes.html (secciones 18–22). Revisado por el equipo.
- Siguiente: 6.4 Dashboard, 6.5 Monitoreos, 6.6 Alertas, 6.7 verificación final.
Completado:
- Fase 0 — Preparación y prueba de ping: estructura del proyecto, comun/config.php y comun/respuesta.php, puntos de entrada de dispositivos, alertas y monitoreo con reescritura de URLs, esqueletos HTML, librerías en web/vendor/, repositorio git, .htaccess que solo expone web/ y los servicios, y servicios/monitoreo/ping.php probado a través de Apache (127.0.0.1 y la puerta de enlace activos, 192.0.2.1 inactivo en ~1 s, entradas inválidas → 400 sin ejecutar comandos).
- Fase 1 — Base de datos: database/schema.sql con las 4 tablas de docs/API.md (FK con ON DELETE CASCADE, índices, utf8mb4_unicode_ci), database/seed.sql (6 dispositivos, 56 monitoreos, 1 alerta pendiente y 3 atendidas, usuario admin) y comun/db.php (PDO con EMULATE_PREPARES en false y colación utf8mb4_unicode_ci). Verificado: carga desde cero repetible, coherencia de ultimo_* con el último monitoreo, UNIQUE, FK y cascada (con ROLLBACK).
- Fase 2 — Servicio de Dispositivos: los 6 endpoints de docs/API.md; infraestructura en comun/ (peticion.php, enrutador.php con 404/405 y cabecera Allow, configurarErrores()); pruebas/ayudas.php y pruebas/dispositivos.php (186 comprobaciones). Con MySQL apagado, los 6 endpoints responden 500 en JSON sin detalles (prueba manual).
- Fase 3 — Servicio de Alertas: los 4 endpoints de docs/API.md con el patrón de la Fase 2; columna generada alertas.dispositivo_pendiente con índice único (una sola pendiente por dispositivo, también con POST simultáneos: 20 de 20 rondas de 10 POST resolvieron la carrera con el 1062, sin 500 ni deadlocks); pruebas/alertas.php (141 comprobaciones; la de concurrencia se omite con aviso si PHP no tiene curl).
- Fase 4 — Servicio de Monitoreo: POST /monitoreo/{id} con el flujo de 5 pasos solo por HTTP (comun/http.php), GET /monitoreo con filtros, paginación y resumen en SQL, GET /monitoreo/recientes con ROW_NUMBER(); endpoint temporal /monitoreo/prueba eliminado; pruebas/monitoreo.php (188 comprobaciones, incluidos los 502 con el archivo de fallo simulado y la regla SOA). Las tres suites pasan.
- Fase 5 — Sistema de diseño: tokens.css (único archivo con colores, agrupado por categoría, fuentes locales), app.css (Bootstrap por componente y componentes propios de las secciones 01–14, :focus-visible, prefers-reduced-motion) y componentes.html con componentes.css y componentes.js. Comparado con la referencia a 1440 y 390 px, sin peticiones externas, contrastes AA documentados. Aprobado por el equipo.
Pendientes o decisiones abiertas:
- Con EMULATE_PREPARES en false, PDO ya devuelve INT como int, pero DECIMAL sigue como texto y TINYINT como int (1): cada servicio convierte a número y booleano antes de responder (Dispositivos lo hace en formatearDispositivo()).
- El 1452 de POST /monitoreo (dispositivo borrado entre el paso 1 y el 3) se prueba en datos.php, no de punta a punta: provocar esa carrera por HTTP no es confiable.
- La columna generada VIRTUAL con índice único y FK en cascada está probada en MariaDB 10.4 (la del XAMPP); no se ha probado en MySQL 8.
- Las fechas del seed son fijas (última ronda: 2026-09-27 09:45); en la demo se refrescan con "Monitorear todos".
- Los .htaccess de los servicios usan RewriteOptions AllowNoSlash junto con DirectorySlash Off; sin él, las URLs sin barra final dan 403/404.
