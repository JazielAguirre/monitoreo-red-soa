# Guion de la demo

Unos **5 minutos y medio** para mostrar el sistema de principio a fin: buscar los
equipos de la red, agregar un dispositivo, monitorearlo, ver cómo se genera la alerta,
atenderla, consultar el historial y eliminarlo, más la parte de SOA (los servicios
hablándose por HTTP) y las pruebas automáticas como evidencia. Si hay que quedarse en
5:00, se omite la recarga del celular del Integrante 4 (queda en unos 5:05).

> **Uso responsable del escáner.** «Buscar dispositivos en la red» envía un ping a
> toda la red de la computadora. Solo se usa en redes propias o con permiso: en la
> demo, en el **hotspot de un teléfono del equipo**, nunca en la red de la escuela.
> Si por algún motivo la computadora termina en la red de la escuela, **ese tramo se
> omite** (ver [Plan B](#plan-b)).

Reparto entre 4 integrantes: cambien «Integrante 1–4» por sus nombres.

Los comandos están escritos para Linux. En Windows, `/opt/lampp/bin/php` es
`C:/xampp/php/php.exe` (en PowerShell, desde `C:\xampp\htdocs\monitoreo-red-soa`).

---

## Antes de empezar (10 minutos antes)

En la computadora que va al proyector:

1. **Apache y MySQL encendidos** (`sudo /opt/lampp/lampp startapache` y `startmysql`,
   o el panel de XAMPP en Windows).
2. **Conectar todo al hotspot** de un teléfono del equipo (la red de la demo): la
   computadora, el celular que se muestra al final y **un segundo teléfono** de otro
   integrante, con la pantalla encendida, para que el escáner tenga un equipo nuevo
   que encontrar. El teléfono del hotspot hace de «Router principal».
3. **Preparar la demo** en el hotspot:
   ```bash
   /opt/lampp/bin/php herramientas/preparar-demo.php
   ```
   Todas las líneas de la comprobación previa deben quedar en ✓. Anoten la **URL para
   el celular** que imprime al final. Si algo sale con ✗, sigan su «Qué hacer» o la
   tabla [Si pasa X, haz Y](#si-pasa-x-haz-y). (Si el hotspot no responde al ping, el
   router sale con ✗: no importa para la demo, el escáner lo encuentra por ARP.)
4. **Abrir dos ventanas** (se cambia entre ellas con **Alt+Tab**):
   - El navegador en <http://localhost/monitoreo-red-soa/web/>, con sesión iniciada
     (`admin` / `admin123`), en el **Dashboard**, zoom al 100 %.
   - Una terminal con letra grande, con el registro de llamadas entre servicios:
     ```bash
     /opt/lampp/bin/php herramientas/ver-llamadas.php
     ```
5. **En el celular:** abrir la URL del paso 3, iniciar sesión y dejarlo en el
   **Dashboard**.
6. Tener a mano el texto de los datos del dispositivo nuevo:
   **Servidor de pruebas · 192.0.2.50 · Servidor · Laboratorio**
   (192.0.2.50 es de un rango reservado para documentación: nunca responde).

---

## Guion

| Tiempo | Quién | Qué se hace | Qué se dice |
|---|---|---|---|
| 0:00 – 0:50 | **Integrante 1** | Dashboard en pantalla. Pulsar **Monitorear todos**: se ve el progreso ("3 de 6") y al final dos notificaciones: el resumen y "2 dispositivos no respondieron". Señalar la **Impresora** en las tarjetas y el contador de Alertas del menú | "Es un sistema para vigilar los equipos de una red: registra dispositivos, comprueba con ping si responden, guarda el historial y avisa cuando uno deja de responder. Está hecho con arquitectura orientada a servicios: tres servicios principales, Dispositivos, Monitoreo y Alertas, más uno de autenticación, y cada servicio es dueño de su propia tabla. Y aquí ya pasó algo en vivo: la Impresora estaba activa y ahora no respondió, así que se generó una alerta. El Servidor de respaldo tampoco responde: el contador de Alertas pasó de 1 a 3." |
| 0:50 – 1:20 | **Integrante 2** | **Dispositivos → Buscar dispositivos en la red**. Mientras avanza el progreso («Lote 2 de 4», unos 5 s) se dice la frase. En la lista: el hotspot («Registrado · Router principal», puede salir como «Solo en ARP»), esta computadora («Esta computadora», «Registrado · PC Recepción») y el **segundo teléfono** con su botón **Agregar**. Pulsar **Agregar** en el teléfono: el formulario aparece con su IP y su nombre ya escritos. **Cancelar** y cerrar el panel (✕) | "Antes de registrar nada, el sistema puede buscar los equipos de la red. Un servicio nuevo, Descubrimiento, hace ping a las 254 direcciones en paralelo y lee la tabla ARP: así aparecen hasta los que bloquean el ping. Para saber cuáles ya están registrados, le pregunta a Dispositivos por HTTP. Solo lo usamos en redes propias: este es el hotspot de nuestro teléfono." |
| 1:20 – 2:30 | **Integrante 2** | **Dispositivos → Agregar dispositivo**. Escribir primero la IP `192.168.1.300` y guardar: aparece "Dirección IP no válida" bajo el campo. Corregir a `192.0.2.50` y guardar. En su fila, pulsar **Monitorear**: queda **Inactivo**, aparece la notificación "Servidor de pruebas no respondió" y el contador del menú sube uno | "La interfaz no toca la base de datos: le pide todo a los servicios por HTTP. Por ejemplo, al agregar un dispositivo, las validaciones las hace el servicio: con una IP imposible, el mensaje de error viene de la API, no del navegador. Con una IP válida se guarda, y el dispositivo empieza sin monitorear. Al monitorearlo, el Servicio de Monitoreo le hace ping; como no responde, queda inactivo y se genera una alerta: el contador del menú sube." |
| 2:30 – 3:50 | **Integrante 3** | **Alt+Tab** a la terminal: **Ctrl+C**, `clear` (Windows: `cls`) y volver a iniciar `ver-llamadas.php` (flecha arriba + Enter). **Alt+Tab** al navegador y pulsar otra vez **Monitorear** en *Servidor de pruebas*: la notificación dice "Ya tenía una alerta pendiente" y el contador no cambia. **Alt+Tab** a la terminal: aparecen exactamente 4 líneas. Volver al navegador: **Alertas → Marcar como atendida** en *Servidor de pruebas*: pasa a Atendidas y el contador baja | "Aquí se ve la arquitectura. Vuelvo a monitorear el mismo dispositivo. El navegador solo le habló a Monitoreo. Monitoreo no consulta tablas ajenas: le pide la IP a Dispositivos, le avisa el resultado y le pide a Alertas que cree la alerta. Cada línea es una petición entre servicios, con su código y su tiempo. Fíjense en la última: 200 y no 201, porque la alerta ya existía y no se duplicó. Entre servicios se autentican con una clave interna; el navegador, con la sesión. Ahora atiendo la alerta: pasa a Atendidas y el contador baja." |
| 3:50 – 5:00 | **Integrante 4** | **Monitoreos**: elegir *Servidor principal* en el filtro: gráfica de tiempos, puntos rojos donde no respondió y la disponibilidad. Después, **Dispositivos → Eliminar** en *Servidor de pruebas*: aparece el modal de confirmación; confirmar. Por último (**opcional si hay que quedarse en 5:00**: se omite este paso y su última frase), **levantar el celular** hacia el público (no sale en el proyector), recargar su Dashboard y mostrar que *Servidor de pruebas* ya no aparece | "El historial guarda cada comprobación: se filtra por dispositivo, estado y fechas; la gráfica muestra los tiempos y los puntos rojos son las veces que no respondió. Eliminar siempre pide confirmación; al borrar el dispositivo, la base elimina en cascada su historial y sus alertas. Y es la misma aplicación en el celular, entrando por la IP de esta computadora: al recargar, el dispositivo ya no aparece, porque la computadora y el celular le piden los datos a los mismos servicios." |
| 5:00 – 5:30 | **Integrante 1** | En la terminal (Ctrl+C al registro): `/opt/lampp/bin/php pruebas/dispositivos.php` (tarda 2 s y termina en "Resultado: 207 de 207") | "Además de la demo, el sistema tiene seis suites de pruebas automáticas, más de mil comprobaciones: cada endpoint con sus errores, la regla de que ningún servicio toca tablas ajenas, el login y las pantallas en un navegador real, en computadora, celular y proyector. Esta es una de ellas en vivo. Gracias." |

**Después del cierre:** las suites recargan la base al empezar y al terminar, así
que la aplicación queda con las fechas viejas del seed ("Última comprobación" del
27/09). Si hay preguntas y hay que volver a navegar la aplicación, primero:

```bash
/opt/lampp/bin/php herramientas/preparar-demo.php
```

(tarda unos segundos) y recargar la página. La sesión sigue abierta: no hay que
volver a entrar.

Si sobra tiempo o preguntan por SOA, se puede mostrar además qué pasa cuando un
servicio no responde: `pruebas/monitoreo.php` simula que Dispositivos o Alertas no
contesta y comprueba que Monitoreo responde 502 con un mensaje claro sin perder el
monitoreo guardado (sección 7 de la suite).

### Tiempo por integrante

Las frases de «Qué se dice» caben en su tramo a ritmo normal (unas 130 palabras por
minuto). Lo que sobra del tramo es para las acciones: escribir, cambiar de ventana y
esperar el ping.

| Integrante | Tramos | Tiempo | Palabras | Hablando a 130 por minuto |
|---|---|---|---|---|
| 1 | 0:00 – 0:50 y 5:00 – 5:30 | 80 s | 142 | 66 s |
| 2 | 0:50 – 2:30 (escáner y agregar) | 100 s | 142 | 66 s |
| 3 | 2:30 – 3:50 | 80 s | 96 | 44 s |
| 4 | 3:50 – 5:00 | 70 s | 81 (45 sin el celular) | 37 s (21 s) |

El Integrante 1 habla más, pero sus acciones corren mientras habla («Monitorear
todos» tarda unos 6 s y la suite 2 s). El Integrante 2 dice la frase del escáner
mientras se llena la lista (unos 5 s de búsqueda). Los integrantes 2, 3 y 4 necesitan
el resto de su tramo para escribir, cambiar de ventana y esperar cada ping.

**Si hay que quedarse en 5:00:** el Integrante 4 omite el paso del celular y su última
frase («Y es la misma aplicación en el celular…»): ahorra unos 25 s y la demo queda en
unos 5:05. El Integrante 1 cierra igual.

### Qué debe verse en cada paso (para ensayar)

- **Buscar dispositivos en la red:** el panel «Equipos en la red» con la barra de
  progreso («Lote 1 de 4» … «Lote 4 de 4»); la lista se llena lote por lote y al final
  el subtítulo dice algo como «192.168.43.0/24 · 3 equipos encontrados · 1 sin
  registrar». Cada fila: «Responde al ping» (verde) o «Solo en ARP» (gris), IP, nombre
  o «—», MAC y «Registrado · …» o **Agregar**. Al pulsar **Agregar**, la IP y el nombre
  ya vienen escritos; se cancela sin guardar.
- **Monitorear todos:** 6 fichas que se van llenando.
  - Activos: *Mi PC*, *PC Recepción* y el router (si la red lo deja responder al ping).
  - Inactivos: *Servidor principal* (ya tenía su alerta pendiente), la **Impresora**
    (estaba activa en los datos: su alerta es la primera en vivo) y el *Servidor de
    respaldo* (nunca se había comprobado).
  - Notificaciones: "Monitoreo completado · 3 de 6 dispositivos responden." y
    "2 dispositivos no respondieron · Se generaron 2 alertas pendientes."
  - Contador de Alertas del menú: **de 1 a 3**. Si el router no responde (o con
    `--sin-red`), queda en 4 y todos los contadores siguientes llevan uno más.
- **Agregar con IP mala:** mensaje bajo el campo IP, el modal no se cierra.
- **Monitorear el nuevo:** fila en rojo "Inactivo · Sin respuesta", notificación
  "Servidor de pruebas no respondió · Se generó una alerta pendiente." con "Ver
  alerta", contador **de 3 a 4**.
- **Registro de llamadas** (la terminal recién limpiada, después de monitorear otra vez):
  ```
  [10:06:40] Navegador → Monitoreo: POST /monitoreo/7
  [10:06:40]   Monitoreo → Dispositivos: GET /dispositivos/7 (200, 8 ms)
  [10:06:41]   Monitoreo → Dispositivos: PATCH /dispositivos/7/estado (200, 12 ms)
  [10:06:41]   Monitoreo → Alertas: POST /alertas (200, 10 ms)
  ```
  La primera vez que se monitoreó, la última línea fue `201` (alerta creada); esta
  vez es `200` (ya existía y se devolvió la misma). En el navegador: "Ya tenía una
  alerta pendiente.", el contador sigue en **4**.
- **Atender:** la alerta desaparece de Pendientes, el contador **de 4 a 3**.
- **Eliminar:** modal "¿Eliminar dispositivo?", la tabla vuelve a 6. El contador
  sigue en 3 (la alerta del dispositivo ya estaba atendida).
- **Celular:** después de recargar, el Dashboard muestra los 6 dispositivos, sin
  *Servidor de pruebas*.

---

## Preguntas que pueden hacer

| Pregunta | Quién | Respuesta corta |
|---|---|---|
| ¿Por qué SOA y no una aplicación monolítica? | Integrante 1 | Cada servicio tiene una sola responsabilidad y es dueño de su tabla; los demás solo lo usan por HTTP con un contrato fijo (`docs/API.md`). Se puede cambiar o mover un servicio a otro servidor sin tocar a los demás (solo su URL en `config.php`), cada integrante pudo trabajar en uno a la vez, y cualquier cliente usa los mismos servicios: por eso el celular funciona sin código aparte. En un monolito todo comparte código y base, y un cambio puede romper cualquier parte |
| ¿Cómo se comunican los servicios y cómo se autentican entre sí? | Integrante 3 | Por HTTP con JSON, siempre con el mismo formato (`ok` y `data`, o `ok` y `error`). Monitoreo usa un cliente común (`comun/http.php`) que espera como máximo 3 segundos. Entre servicios mandan la cabecera `X-Servicio-Clave`, una clave aleatoria de 64 caracteres comparada en tiempo constante; nunca reenvían la cookie del navegador. Los endpoints internos (cambiar el estado de un dispositivo, crear una alerta) responden 403 al navegador aunque tenga sesión |
| ¿Qué pasa si un servicio se cae? | Integrante 3 | Monitoreo responde 502 con un mensaje claro: "No se pudo comunicar con el Servicio de Dispositivos" (o de Alertas). Si el fallo es después del ping, el monitoreo ya quedó guardado: no se pierde el dato. Las pruebas lo simulan con un servicio que no contesta y con uno que tarda más de 3 segundos |
| ¿Cómo funciona el ping? ¿Se puede inyectar un comando por el campo IP? | Integrante 2 | Se ejecuta el `ping` del sistema con un intento y 1 segundo de espera, con las opciones de Linux, Windows o macOS; el dispositivo está activo solo si la respuesta trae `TTL=`, y el tiempo se lee de la salida. No se puede inyectar: la IP se valida como IPv4 al guardar el dispositivo y otra vez antes del ping, así que `127.0.0.1; rm -rf /` se rechaza con 400 sin ejecutar nada; además pasa por `escapeshellarg()`, que la entrega como un solo argumento entre comillas |
| ¿Por qué 192.0.2.x nunca responde? | Integrante 2 | Es el rango TEST-NET-1 (RFC 5737), reservado para documentación: ningún equipo real lo usa y no se enruta. Sirve para mostrar y probar un dispositivo caído en cualquier red, o sin red |
| ¿Cómo se evita que una alerta se duplique? | Integrante 4 | En la tabla `alertas`, una columna calculada vale el id del dispositivo si la alerta está pendiente, o nada si ya se atendió, y tiene un índice único. La base no deja guardar dos pendientes del mismo dispositivo, ni con peticiones simultáneas; el servicio devuelve la que ya existía (el 200 del registro). Las atendidas no chocan, por eso después de atender puede nacer una nueva |
| ¿Escanear la red no es un ataque? ¿Cómo encuentra un equipo que no responde al ping? | Integrante 2 | Solo busca en la red de esta computadora (su /24, calculada en el servidor: el navegador no puede pedir otra) y solo si es privada; un ping por dirección, nada más. Aun así, solo lo usamos en redes propias o con permiso, por eso la demo va en nuestro hotspot. Para los que bloquean el ping: antes de enviar un ping, la computadora pregunta por ARP qué tarjeta de red tiene esa IP; el equipo contesta aunque luego ignore el ping, y esa respuesta queda en la tabla ARP con su MAC |
| ¿Qué prueban las suites? | Integrante 1 | Seis suites, 1172 comprobaciones: Dispositivos (207), Alertas (154), Monitoreo (221, incluido el 502 y la regla de que ningún servicio toca tablas ajenas), Autenticación (126: login, cookie, 401/403), Descubrimiento (112: el escáner con una red simulada, sin tocar la red real) e Interfaz (352, en un Chrome sin ventana: pantallas, celular, proyector, teclado y este mismo guion). Cada una recarga la base al empezar |

---

## Plan B

| Si… | Entonces |
|---|---|
| **No hay hotspot** (el teléfono no tiene datos o batería) | Usar la red de la escuela para todo lo demás y **omitir el tramo del escáner** (0:50 – 1:20): el guion vuelve a unos 5:00. Nunca pulsar «Buscar dispositivos en la red» en la red de la escuela |
| **No hay WiFi** (o la red de la escuela bloquea los equipos) | `/opt/lampp/bin/php herramientas/preparar-demo.php --sin-red` (Windows: `C:/xampp/php/php.exe herramientas/preparar-demo.php --sin-red`). Todo lo que usa `127.0.0.1` y `192.0.2.x` funciona sin red: el router pasa a `192.0.2.30` (no responde: el contador de alertas llega a 4 con «Monitorear todos») y PC Recepción a `127.0.0.2` (responde). La demo completa se hace en la computadora; el celular se omite o se usa el siguiente punto |
| **El celular no puede entrar por el hotspot** | Revisar que el celular esté en el hotspot y no en otra red, y volver a correr `/opt/lampp/bin/php herramientas/preparar-demo.php` (detecta la IP e imprime la URL). Si sigue sin entrar, se omite el paso del celular |
| **Nada funciona el día de la demo** | Último recurso: el **video grabado** de la demo (ver abajo) |

### Grabar el video de respaldo (hacerlo antes del día)

Grabar el guion completo, una vez que salga bien en un ensayo:

- **Linux (GNOME):** `Ctrl+Alt+Shift+R` inicia y detiene la grabación de pantalla
  (se guarda en `~/Vídeos/Screencasts`). Para más control, OBS Studio.
- **Windows:** `Win+Alt+R` graba la ventana activa con la Xbox Game Bar (se guarda en
  `Vídeos\Capturas`). Para grabar la pantalla completa, OBS Studio.

Llevarlo en la computadora **y** en una memoria USB.

---

## Si pasa X, haz Y

Son los mismos casos que revisa la comprobación previa de `preparar-demo.php`.

| Si pasa… | Haz… |
|---|---|
| Todas las pantallas dicen **"No se pudo conectar con el servidor"** / ✗ *Apache responde* | Encender Apache: `sudo /opt/lampp/lampp startapache` (Windows: *Start* de Apache en el panel de XAMPP) |
| ✗ *Los servicios contestan* (Apache sí responde) | La carpeta debe llamarse `monitoreo-red-soa` dentro de `htdocs` y Apache debe leer los `.htaccess` (README, "Instalación") |
| Las pantallas dicen **"Error interno del servidor"** / ✗ *La base tiene los datos* / el script dice que no puede conectar con MySQL | Encender MySQL: `sudo /opt/lampp/lampp startmysql` (Windows: panel). Si no arranca en Linux: `tmpdir = /tmp` debajo de `[mysqld]` en `/opt/lampp/etc/my.cnf` |
| ✗ *El router responde al ping* | La computadora no está en la red de la demo: conectarla, indicar la IP con `--router=IP` o usar `--sin-red` |
| **Aparece la pantalla de inicio de sesión** a mitad de la demo | La sesión dura 8 horas; volver a entrar con `admin` / `admin123`: regresa a la pantalla donde estaban |
| **El celular no carga la página** | Misma WiFi que la computadora; la URL que imprimió `preparar-demo.php`; cortafuegos: `sudo ufw allow from <red>/24 to any port 80 proto tcp` (Linux) o permitir Apache en el Firewall de Windows. Si sigue sin cargar: hotspot (Plan B) |
| Se ve **una versión vieja** de la interfaz | Recarga forzada `Ctrl+Shift+R` (en el celular, cerrar la pestaña y abrirla de nuevo). Desde la Fase 8 las páginas se revalidan solas, así que no debería pasar |
| Un monitoreo tarda o sale **"No se pudo comunicar con el Servicio de…"** | Algún servicio no respondió a tiempo (3 s). Volver a pulsar Monitorear; si se repite, revisar la terminal de `ver-llamadas.php` para ver qué llamada falló |
| Después de correr una suite, las fechas se ven viejas (27/09) | Es normal: las suites recargan el seed. Correr `preparar-demo.php` y recargar la página |
| **La búsqueda no encuentra el segundo teléfono** | Despertar su pantalla (dormido no contesta) y pulsar «Buscar de nuevo». Si sigue sin aparecer, no insistir: mostrar los que sí aparecieron y seguir con el guion |
| La búsqueda dice **"No se detectó una red local"** o **"Solo se pueden escanear redes privadas"** | La computadora no está conectada al hotspot: conectarla y «Buscar de nuevo». Si no se puede, omitir el tramo |
| La búsqueda dice **"No se pudo comunicar con el Servicio de Dispositivos"** | Volver a pulsar «Buscar de nuevo»; si se repite, revisar `ver-llamadas.php` |

---

## El día anterior

- [ ] **Confirmar con el profesor el tiempo disponible**: el guion completo dura unos
  5:30 (unos 5:05 sin la recarga del celular). Si el límite es estricto en 5:00,
  decidir de antemano qué se omite.
- [ ] Correr las 6 suites en la computadora de la demo (README, sección 6).
- [ ] Ensayar el guion completo al menos una vez, cronometrado.
- [ ] Probar «Buscar dispositivos en la red» en el hotspot con el segundo teléfono
  conectado: que aparezca con su botón **Agregar**.
- [ ] Si es posible, conectar la computadora al **proyector real del salón**: revisar
  la resolución y que la letra de la terminal de `ver-llamadas.php` se lea desde el
  fondo del salón. Hasta ahora el proyector solo se simuló con Chrome sin ventana
  (1024×768 y 1280×720).
- [ ] Si es posible, correr `preparar-demo.php` en la red de la escuela para ver qué IPs detecta.
- [ ] Probar el hotspot del teléfono con la computadora, el celular y el segundo
  teléfono.
- [ ] Grabar el video de respaldo.
