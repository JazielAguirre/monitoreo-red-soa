# Plan de desarrollo

Este documento define las fases del proyecto, qué se entrega en cada una y cuándo se considera terminada. El contrato técnico está en `docs/API.md` y las reglas de trabajo en `CLAUDE.md`.

## Cómo se usa

- Se trabaja **una fase a la vez**. Antes de escribir código, se presenta el plan de la fase y se espera aprobación.
- Cada fase tiene una lista **"Listo cuando"**. Una fase solo se da por terminada, y su rama solo se fusiona a `main`, cuando **todas** sus casillas se cumplen y se comprobaron.
- Desde la Fase 2, cada fase va en su propia rama (`servicio-dispositivos`, `servicio-alertas`, etc.). Las Fases 0 y 1 se hicieron en `main`, porque son la base común.
- Al cerrar una fase: marcar sus casillas aquí, actualizar "Estado actual" en `CLAUDE.md` y hacer commit.

## Pipeline

```
Fase 0 → Fase 1 ─┬─ Backend:  Fase 2 → Fase 3 → Fase 4 ──┬─→ Fase 7 → Fase 8 → Fase 9
                 └─ Frontend: Fase 5 → Fase 6 ────────────┘
```

La Fase 9 es una ampliación posterior a la versión que se presenta (`v1.0`): se trabaja en su rama y `v1.0` queda intacta como respaldo.

Después de la Fase 1, el backend y el frontend pueden avanzar en paralelo. La Fase 6 empieza con datos de ejemplo que siguen `API.md` y se conecta a los servicios reales cuando estén listos.

| Fase | Qué | Responsable sugerido | Estado |
|---|---|---|---|
| 0 | Preparación y prueba de ping | Todo el equipo | ✅ Completa |
| 1 | Base de datos y contrato | Integrante 1 | ✅ Completa |
| 2 | Servicio de Dispositivos | Integrante 2 | ✅ Completa |
| 3 | Servicio de Alertas | Integrante 4 | ✅ Completa |
| 4 | Servicio de Monitoreo | Integrante 3 | ✅ Completa |
| 5 | Sistema de diseño | Integrante 4 | ✅ Completa |
| 6 | Pantallas | Integrante 4 | ✅ Completa |
| 7 | Inicio de sesión | Todo el equipo | ✅ Completa |
| 8 | Integración, pruebas y demo | Todo el equipo | ✅ Completa (quedan el ensayo en vivo y la prueba en Windows) |
| 9 | Descubrimiento de red | Todo el equipo | 🔄 En curso (rama `descubrimiento-red`) |

## Pruebas automáticas

Desde la Fase 2, cada servicio entrega un script de pruebas en `pruebas/`, escrito en PHP de línea de comandos para que funcione igual en el XAMPP de Linux y de Windows.

- `pruebas/ayudas.php`: funciones comunes para hacer peticiones a través de Apache, comprobar resultados y recargar la base de datos (`schema.sql` + `seed.sql`).
- Un archivo por servicio: `pruebas/dispositivos.php`, `pruebas/alertas.php`, `pruebas/monitoreo.php`, `pruebas/auth.php` y, desde la Fase 9, `pruebas/descubrimiento.php`.
- Cada script recarga la base de datos al empezar, para que los resultados no dependan de pruebas anteriores. **Borra los datos que hayas agregado a mano.**
- Se ejecutan con `/opt/lampp/bin/php pruebas/<servicio>.php` (en Windows, `C:/xampp/php/php.exe`) y terminan con código de salida distinto de 0 si algo falla.
- `pruebas/` no debe ser accesible por HTTP.
- `pruebas/interfaz.php` prueba las pantallas con un Chrome headless propio (`pruebas/navegador.php`, cliente del protocolo de depuración escrito en PHP): perfil temporal, puerto elegido por Chrome, nunca se conecta al Chrome del usuario, y se cierra y borra el perfil al terminar, también ante un error o Ctrl+C. Emula 390 px y `prefers-reduced-motion`, y simula servicios caídos interceptando las peticiones de la página. Si no hay Chrome, Chromium o Edge, se omite con un aviso.

**Archivo de fallo simulado (`pruebas/.fallo-simulado.json`).** Sirve para probar el 502 de Monitoreo cuando Dispositivos o Alertas no responde, sin apagar nada:

- **Qué es:** si existe y está vigente, `comun/http.php` desvía las llamadas al servicio indicado (y, opcionalmente, a un solo método, por ejemplo el `PATCH` de Dispositivos) hacia un destino que no responde (`sin_respuesta`, un puerto de `127.0.0.1`) o que no devuelve JSON (`respuesta_invalida`, `web/index.html`). Solo admite esos dos modos, nunca una URL libre, y el puerto debe ser 1024 o mayor.
- **Solo lo usan las pruebas:** lo crean y lo borran `pruebas/monitoreo.php` y `pruebas/auth.php` (al empezar, al terminar y ante un error fatal), con las funciones de `pruebas/ayudas.php`. Está en `.gitignore`.
- **Caduca solo:** lleva un campo `expira`; `http.php` lo ignora si ya pasó o si está a más de 60 segundos en el futuro. Si la prueba se interrumpe (Ctrl+C, `kill -9`), el sistema vuelve a funcionar en cuanto caduca (las pruebas usan 30 s), y la siguiente corrida lo borra.
- **No puede activarse por HTTP:** ningún endpoint escribe archivos y `pruebas/` responde 404.

**Archivo de red simulada (`pruebas/.red-simulada.json`, Fase 9).** Sirve para probar el Servicio de Descubrimiento a través de Apache sin depender de la red de quien corre las pruebas y sin enviar nada a la red local. Sigue el mismo modelo que el fallo simulado:

- **Qué es:** si existe y está vigente, el Servicio de Descubrimiento usa como IP propia la que indica el archivo en lugar de detectarla, y lee la tabla ARP del texto que trae el archivo (en el formato del sistema operativo: `ip neigh`, `arp -a` o `arp -an`) en lugar de ejecutar el comando. Los pings son reales.
- **Solo escanea dos redes:** IP propia `127.0.0.1` (la /24 `127.0.0.0/24`, donde todas las direcciones responden) o `192.0.2.1` (la /24 de documentación `192.0.2.0/24`, donde ninguna responde). Para probar los 503 sin desconectar nada admite además `null` (sin red) y `198.51.100.1` (documentación, no privada: se rechaza como cualquier red pública). Cualquier otro valor se ignora, así que el archivo no sirve para escanear otras redes.
- **Solo lo usa `pruebas/descubrimiento.php`**, que lo crea y lo borra (al empezar, al terminar y ante un error fatal). Está en `.gitignore`.
- **Caduca solo** (campo `expira`, máximo 60 segundos en el futuro) y **no puede activarse por HTTP**, igual que el fallo simulado.

---

## Fase 0 · Preparación y prueba de ping ✅

- [x] Estructura de carpetas, `CLAUDE.md`, repositorio git.
- [x] Apache sirve solo `web/` y los puntos de entrada de los servicios; en cada servicio solo se ejecuta `index.php`.
- [x] Reescritura de URLs con y sin barra final.
- [x] Librerías del frontend en `web/vendor/`.
- [x] Función de ping en `servicios/monitoreo/ping.php`, probada a través de Apache; las entradas inválidas no ejecutan ningún comando.
- [x] MySQL funcionando (ajuste de `tmpdir` en Linux).

## Fase 1 · Base de datos y contrato ✅

- [x] `docs/API.md` completo.
- [x] `database/schema.sql` recrea la base desde cero, con llaves foráneas en cascada, índices y `utf8mb4`.
- [x] `database/seed.sql` con datos coherentes y predecibles en la red local.
- [x] `servicios/comun/db.php` con conexión PDO.
- [x] Sistema de diseño de referencia en `docs/diseno/` y JetBrains Mono en `web/vendor/`.

## Fase 2 · Servicio de Dispositivos ✅

**Objetivo:** el primer servicio completo, que fija el patrón que copiarán los demás.

**Tareas:**

- Infraestructura compartida en `comun/`: leer el cuerpo JSON, obtener método y segmentos de la ruta, y responder 404 o 405 con el formato JSON. Solo infraestructura, nunca reglas de negocio.
- Los 6 endpoints de la sección "Servicio de Dispositivos" de `API.md`.
- Conversión de tipos antes de responder: `DECIMAL` → número, `null` se conserva.
- `pruebas/ayudas.php` y `pruebas/dispositivos.php`.

**Listo cuando:**

- [x] Los 6 endpoints responden exactamente como dice `API.md`, con los tipos correctos en JSON (números, `null`).
- [x] Cada regla de validación devuelve 400 con su mensaje en `campos`; los espacios al inicio y al final se recortan.
- [x] IP duplicada → 409 con `campos.ip`, tanto al crear como al editar.
- [x] Id inexistente → 404; ruta desconocida → 404; método no permitido → 405. Siempre en JSON.
- [x] Cuerpo que no es JSON válido → 400.
- [x] `PUT` que cambia la IP deja el dispositivo en `desconocido`, con `ultimo_*` en `null`; `PUT` sin cambiar la IP conserva el estado.
- [x] `DELETE` borra el dispositivo, y sus monitoreos y alertas desaparecen por la cascada.
- [x] `PATCH /estado` aplica sus reglas: `activo` exige tiempo ≥ 0, `inactivo` exige `null`, la fecha es obligatoria.
- [x] Los acentos llegan intactos de ida y vuelta ("Recepción").
- [x] Con MySQL apagado, cualquier endpoint responde 500 en JSON sin mostrar detalles internos. Esta prueba es manual.
- [x] `pruebas/dispositivos.php` cubre todos los puntos anteriores, excepto el de MySQL apagado, y pasa completo.

## Fase 3 · Servicio de Alertas ✅

**Objetivo:** gestionar alertas con la regla contra duplicados dentro del propio servicio.

**Tareas:**

- Los 4 endpoints de la sección "Servicio de Alertas" de `API.md`.
- Conversión de tipos: `atendida` → booleano.
- `pruebas/alertas.php`.

**Listo cuando:**

- [x] `GET /alertas` filtra por `atendida`, `dispositivo` y `limite`, del más reciente al más antiguo.
- [x] `GET /alertas/conteo` coincide con los datos del seed (`1` pendiente, `3` atendidas).
- [x] `POST /alertas` para un dispositivo sin alerta pendiente → 201 con `creada: true`. Una segunda vez → 200 con `creada: false` y la misma alerta.
- [x] Después de atender esa alerta, un nuevo `POST` vuelve a crear una (201).
- [x] `PATCH /atender` guarda `fecha_atendida` y es idempotente: una segunda llamada responde 200 sin cambios.
- [x] Dispositivo inexistente → 404; datos faltantes o tipo inválido → 400.
- [x] `pruebas/alertas.php` pasa completo.

## Fase 4 · Servicio de Monitoreo ✅

**Objetivo:** el flujo completo de SOA. Monitoreo coordina a Dispositivos y Alertas **solo por HTTP**.

**Tareas:**

- `comun/http.php`: cliente HTTP entre servicios, con tiempo de espera y la cabecera `X-Servicio-Clave`, que se envía desde ahora aunque se valide hasta la Fase 7.
- `POST /monitoreo/{id}` con el flujo de 5 pasos de `API.md`.
- `GET /monitoreo` con filtros, paginación y `resumen` calculado en SQL.
- `GET /monitoreo/recientes`.
- Eliminar el endpoint temporal `GET /monitoreo/prueba` y su sección en `API.md`.
- `pruebas/monitoreo.php`.

**Listo cuando:**

- [x] Monitorear Mi PC (`127.0.0.1`) → 201, `activo` con tiempo. El dispositivo queda actualizado con el mismo tiempo y fecha que el monitoreo.
- [x] Monitorear la Impresora (`192.0.2.20`) → `inactivo`, `alerta_creada: true`. Una segunda vez → `alerta_creada: false`.
- [x] Dispositivo inexistente → 404.
- [x] Si Alertas o Dispositivos no responde (se simula con el archivo de fallo simulado, ver "Pruebas automáticas") → 502, y el monitoreo queda guardado.
- [x] `GET /monitoreo` respeta cada filtro; `desde` posterior a `hasta` → 400; la paginación da `total` y `total_paginas` correctos.
- [x] El `resumen` ignora el filtro de estado y la paginación, y coincide con un cálculo manual en SQL.
- [x] `GET /monitoreo/recientes` devuelve cada lista en orden cronológico ascendente y respeta `limite`.
- [x] **Regla SOA:** el código de `servicios/monitoreo/` no contiene SQL sobre las tablas `dispositivos` ni `alertas` (se revisa con `grep`).
- [x] `/monitoreo/prueba` ya no existe (404).
- [x] `pruebas/monitoreo.php` pasa completo, y las pruebas de las Fases 2 y 3 siguen pasando.

## Fase 5 · Sistema de diseño ✅

**Objetivo:** los tokens y componentes del diseño aprobado, listos para armar pantallas.

**Tareas:**

- `web/css/tokens.css`: todos los valores de la sección "Diseño de la interfaz" de `CLAUDE.md`, los `@font-face` de Inter y JetBrains Mono, y la personalización de las variables de Bootstrap.
- `web/css/app.css`: los componentes de las secciones 01–14 de `docs/diseno/Monitoreo de Red SOA.dc.html`.
- `web/componentes.html`: todos los componentes con todos sus estados, como en el diseño.

**Listo cuando:**

- [x] `componentes.html` muestra cada componente y estado del sistema de diseño, y se ve igual que la referencia cuando se comparan lado a lado.
- [x] Ningún archivo fuera de `tokens.css` tiene colores hexadecimales (se revisa con `grep`).
- [x] Las fuentes cargan desde `web/vendor/`, sin peticiones a internet (se revisa en la pestaña Red del navegador).
- [x] El punto pulsa al monitorear y queda fijo con `prefers-reduced-motion`.
- [x] Se ve bien a 1440 px y a 390 px.
- [x] El equipo aprueba el resultado.

## Fase 6 · Pantallas ✅

**Objetivo:** las cinco pantallas del diseño funcionando con los servicios reales.

**Tareas:**

- `web/js/api.js`: único punto de acceso a la API. Maneja la URL base, convierte los errores en notificaciones y consulta el contador de alertas del menú.
- Menú lateral y estructura común generados desde un solo módulo de JavaScript, no copiados en cada HTML.
- Dashboard, Dispositivos, Monitoreos y Alertas, siguiendo `docs/diseno/` y la tabla "Qué usa cada pantalla" de `API.md`. El login se conecta en la Fase 7.
- Ajustes acordados en la revisión del diseño:
  - "Monitorear todos" muestra la posición actual ("3 de 6").
  - En celular, Dispositivos también permite filtrar por estado.
  - En Monitoreos sin dispositivo elegido, la gráfica muestra "Elige un dispositivo para ver su gráfica".
  - En Monitoreos, el rango de fechas inicial es de los **últimos 7 días**, para que el historial del seed sea visible.

**Listo cuando:**

- [x] Todas las pantallas funcionan con los servicios reales y coinciden con `docs/diseno/`.
- [x] Cada vista tiene sus estados de carga, vacío y error. Con MySQL apagado se ve un error claro, nunca una pantalla en blanco.
- [x] "Monitorear todos" comprueba los dispositivos uno por uno, con progreso visible, y al final muestra una notificación con el resumen.
- [x] El contador de alertas del menú se actualiza después de monitorear y después de atender una alerta.
- [x] Probado en un celular real, entrando por la IP de la computadora en la red local.
- [x] Se puede usar solo con el teclado: foco visible y los modales se cierran con Escape.

## Fase 7 · Inicio de sesión ✅

**Objetivo:** proteger la aplicación sin romper la comunicación entre servicios.

**Tareas:**

- Endpoints de la sección "Autenticación" de `API.md`, con sesiones de PHP.
- `comun/auth.php`: los endpoints [Interfaz] exigen sesión o clave de servicio; los [Interno] exigen clave de servicio.
- Cookie de sesión con `HttpOnly` y `SameSite=Lax`, y `session_regenerate_id()` al iniciar sesión.
- `web/login.html` conectado. `api.js` redirige al login cuando recibe 401. Botón "Salir" en el menú.
- `pruebas/auth.php`.

**Listo cuando:**

- [x] Sin sesión, cualquier página redirige al login y cualquier endpoint [Interfaz] responde 401.
- [x] Un endpoint [Interno] llamado sin clave → 403, aunque haya sesión.
- [x] Credenciales incorrectas → siempre el mismo mensaje, exista o no el usuario.
- [x] "Monitorear todos" sigue funcionando con sesión: los servicios se comunican con la clave interna.
- [x] "Salir" cierra la sesión.
- [x] Todas las pruebas automáticas pasan, ajustadas para iniciar sesión.

## Fase 8 · Integración, pruebas y demo ✅

**Objetivo:** que la demo funcione de principio a fin sin tocar código.

**Tareas:**

- Correr todas las pruebas automáticas y una lista de pruebas manuales por pantalla.
- Script de preparación de la demo: recarga la base de datos y **desplaza las fechas del seed para que la última ronda sea de hace unos minutos**. Así "Última comprobación" y el historial no se ven antiguos el día de la presentación.
- Actualizar el README: instalación en Linux y Windows, carga de la base de datos y cómo correr las pruebas.
- Guion de la demo. Antes de presentar, editar las IPs del router y de "PC Recepción" para la red de la escuela.
- Pulido final: textos, transiciones y estados vacíos.

**Listo cuando:**

- [x] Todas las pruebas automáticas pasan en una copia recién clonada del repositorio (Linux, siguiendo el README: 1023 de 1023 comprobaciones).
  - [ ] Pendiente del equipo: repetirlo en la computadora de otro integrante, en Windows con `docs/WINDOWS.md`.
- [x] El guion de la demo se ejecuta completo sin errores: agregar, monitorear, ver la alerta, atenderla, consultar el historial y eliminar (ensayo automático en `pruebas/interfaz.php`, a 1280×720).
  - [x] El equipo revisó `docs/DEMO.md` (servicios, alertas en vivo, registro de llamadas, celular, preguntas y tiempos por integrante).
  - [ ] Pendiente del equipo: ensayarlo en vivo, cronometrado.
- [x] La aplicación se ve bien en el proyector y en un celular (`interfaz.php` a 1024×768, 1280×720 y 390 px; celular real en las Fases 6 y 7).

## Fase 9 · Descubrimiento de red 🔄

**Objetivo:** que el sistema encuentre los equipos conectados a la red local, aunque no estén registrados, y que el usuario elija cuáles agregar. Un servicio nuevo que muestra el bajo acoplamiento: los servicios existentes no cambian.

**Uso responsable:** el escaneo solo se usa en redes propias o con permiso. En la demo, en el hotspot de un teléfono del equipo, nunca en la red de la escuela.

**Tareas:**

- `servicios/comun/ping.php`: el ping de Monitoreo se mueve a `comun/` sin cambios, porque ahora lo usan dos servicios y un servicio nunca hace `require` del código de otro. En Monitoreo solo cambia la línea del `require`.
- `servicios/descubrimiento/` con el patrón de los demás (sin `datos.php`: no tiene tabla):
  - La red se calcula en el servidor: la /24 de la IP propia, solo si es privada. El rango nunca viene del navegador.
  - Ping en paralelo por lotes con `proc_open` y `escapeshellarg`, reutilizando `comun/ping.php`.
  - Lectura de la tabla ARP en Linux, Windows y macOS, sin depender del idioma.
  - Nombre por DNS inverso con límite de tiempo corto.
  - Registrados: `GET /dispositivos` al Servicio de Dispositivos, por HTTP.
- Los dos endpoints de la sección "Servicio de Descubrimiento" de `API.md`.
- Pantalla Dispositivos: botón "Buscar dispositivos en la red", progreso por lote, lista de equipos y "Agregar" con el formulario prellenado. Las piezas nuevas, también en `componentes.html`.
- `pruebas/descubrimiento.php` y secciones nuevas en `pruebas/interfaz.php`.
- README y `docs/DEMO.md`: uso responsable y el tramo del escáner en el guion.

**Listo cuando:**

- [ ] `GET /descubrimiento/red` devuelve la /24 de la IP propia calculada en el servidor; sin red o con una IP que no es privada responde 503 con su mensaje, sin enviar nada.
- [ ] `POST /descubrimiento/red/lotes/{n}` escanea el lote pedido: un lote de 64 direcciones que no responden tarda menos de 3 segundos a través de Apache (usuario `daemon`), no 64.
- [ ] Los equipos que bloquean el ping aparecen por la tabla ARP ("Solo en ARP") con su MAC. Las entradas incompletas, fallidas, broadcast y multicast se descartan. Los parsers de Linux (`ip neigh` y `/proc/net/arp`), Windows (en inglés y en español) y macOS se prueban con salidas de ejemplo.
- [ ] El nombre por DNS inverso nunca retrasa el lote más de 0.8 segundos; sin respuesta queda en `null`.
- [ ] Los equipos ya registrados se marcan con su dispositivo, pidiéndolo a Dispositivos por HTTP. Si Dispositivos no responde → 502.
- [ ] Lote fuera de rango → 404; método no permitido → 405; sin sesión → 401. Siempre en JSON.
- [ ] **Regla SOA:** `servicios/descubrimiento/` no contiene SQL ni `require` de otros servicios (se revisa con `grep`), y en `servicios/dispositivos/`, `alertas/` y `auth/` no cambió nada (`git diff main`); en `monitoreo/` solo la línea del `require` de `ping.php`.
- [ ] Pantalla Dispositivos: "Buscar dispositivos en la red" muestra el progreso por lote y la lista (IP, MAC, nombre, ping o solo ARP, registrado o no). "Agregar" abre el formulario con la IP y el nombre escritos. Estados de carga, vacío y error; se ve bien a 390 px y en el proyector; colores solo desde `tokens.css`; piezas nuevas en `componentes.html`.
- [ ] `pruebas/descubrimiento.php` pasa sin depender de la red de quien la corre (red simulada), las secciones nuevas de `pruebas/interfaz.php` pasan, y las 5 suites anteriores siguen pasando.
- [ ] Probado a través de Apache en una red propia (hotspot), con un equipo que bloquea el ping si es posible.
- [ ] README y `docs/DEMO.md` explican el uso responsable, y el guion incluye el tramo del escáner.

## Extras (solo si sobra tiempo)

- Monitoreo automático cada cierto tiempo, con cron o el Programador de tareas de Windows.
- Modo oscuro, usando los mismos tokens.
- Exportar el historial de monitoreos a CSV.
