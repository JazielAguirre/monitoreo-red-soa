# Guion de la demo

Unos **5 minutos y medio** para mostrar el sistema de principio a fin **con nuestros
propios equipos**: ver la red en el Dashboard, una alerta en vivo, buscar los equipos
de la red y agregar uno, monitorearlo, la parte de SOA (los servicios hablándose por
HTTP), atender la alerta, consultar el historial, eliminar y las pruebas automáticas
como evidencia. Si hay que quedarse en 5:00, se omite la recarga del celular del
Integrante 4 (queda en unos 5:05).

La aplicación solo muestra lo que nosotros agregamos: no hay datos de ejemplo. Por
eso el historial y las gráficas se generan antes de la demo (ver
[Antes de empezar](#antes-de-empezar-20-minutos-antes)).

> **Uso responsable del escáner.** «Buscar dispositivos en la red» envía un ping a
> toda la red de la computadora. Solo se usa en redes propias o con permiso: en la
> demo, en el **hotspot de un teléfono del equipo**, nunca en la red de la escuela.

Reparto entre 4 integrantes: cambien «Integrante 1–4» por sus nombres.

Los comandos están escritos para Linux. En Windows, `/opt/lampp/bin/php` es
`C:/xampp/php/php.exe` (en PowerShell, desde `C:\xampp\htdocs\monitoreo-red-soa`).

---

## Los equipos

| Equipo | Quién lo lleva | Papel en la demo | En la aplicación |
|---|---|---|---|
| **Teléfono del hotspot** | Integrante 1 | Crea la red de la demo. **Nunca se pone en modo avión**: se caería la red de todos | Registrado como *Router (hotspot)*, tipo Router. Si bloquea el ping, sale Inactivo (con su alerta) y el escáner lo muestra «Solo en ARP» |
| **Laptop de la presentación** | Integrante 3 | Corre XAMPP y va al proyector | Registrada como *Laptop de la demo*. En el escáner sale marcada «Esta computadora» |
| **Teléfono que se pone en modo avión** | Integrante 2 | Justo antes de empezar se pone en modo avión: así deja de responder y genera la **alerta en vivo** | Registrado como *Teléfono de …* (su dueño) |
| **Equipo nuevo para el escáner** | Integrante 4 | Conectado al hotspot pero **sin registrar**: el escáner lo encuentra y se agrega en vivo. También es el celular que se muestra al final | No está registrado antes de la demo; se agrega y al final se elimina |

> **Pantallas encendidas.** Muchos teléfonos no responden al ping con la pantalla
> apagada (ahorran batería). Durante las rondas del historial y durante toda la demo,
> los teléfonos registrados y el equipo nuevo deben tener la **pantalla encendida**
> (subir el tiempo de apagado de pantalla a 10 minutos o más).

> **En el hotspot solo deben estar conectados equipos del equipo:** los nombres de los
> equipos que encuentra el escáner (por ejemplo «S25-de-Ana») se ven en el proyector.
> Revisen en el teléfono del hotspot la lista de conectados y saquen cualquier equipo
> ajeno (o cambien la contraseña del hotspot).

---

## Una sola vez (el día anterior o antes)

1. **Crear la base vacía** (si ya existe, no la toca):
   ```bash
   /opt/lampp/bin/php herramientas/crear-base.php
   ```
2. **Conectar al hotspot** la laptop, el teléfono que irá en modo avión y el equipo nuevo.
3. En **Dispositivos → Buscar dispositivos en la red**, agregar con **Agregar**:
   - el teléfono del hotspot como *Router (hotspot)* (ya viene con tipo Router),
   - la laptop («Esta computadora») como *Laptop de la demo*,
   - el teléfono que irá en modo avión como *Teléfono de …*.

   **No** agregar el equipo nuevo: ese se agrega en vivo.
4. Ensayar completo (ver [El día anterior](#el-día-anterior)) y anotar cuáles de los
   equipos responden al ping.

---

## Antes de empezar (20 minutos antes)

En la laptop de la presentación:

1. **Apache y MySQL encendidos** (`sudo /opt/lampp/lampp startapache` y `startmysql`,
   o el panel de XAMPP en Windows).
2. **Todos al hotspot** (solo equipos del equipo), con las **pantallas encendidas**.
3. **Generar el historial** (al menos 15 minutos antes; tarda 12 minutos):
   ```bash
   /opt/lampp/bin/php herramientas/monitorear-rondas.php --rondas=12 --cada=60
   ```
   Al terminar muestra cuántos monitoreos guardó y qué equipos respondieron en cada
   ronda. Los tres registrados deberían salir con ✓ (el hotspot puede salir con ✗ si
   bloquea el ping). Si un teléfono sale con ✗, revisen su pantalla y repitan.
4. **Comprobar todo:**
   ```bash
   /opt/lampp/bin/php herramientas/preparar-demo.php
   ```
   No cambia nada: revisa Apache, servicios, los equipos registrados, el historial
   reciente, el router y la laptop. Todas las líneas deben quedar en ✓ (o ⚠). Anoten la
   **URL para el celular**. Si algo sale con ✗ o ⚠, sigan su indicación o la tabla
   [Si pasa X, haz Y](#si-pasa-x-haz-y). Si el hotspot bloquea el ping, el router sale
   con ⚠ «El router bloquea el ping, pero está conectado (aparece en ARP)»: no impide
   la demo.
5. **Contador de alertas en 0:** en **Alertas**, marcar como atendidas las que hayan
   quedado de las rondas (por ejemplo, la del router si bloquea el ping), para que la
   alerta en vivo se note.
6. **Abrir dos ventanas** (se cambia entre ellas con **Alt+Tab**):
   - El navegador en <http://localhost/monitoreo-red-soa/web/>, con sesión iniciada
     (`admin` / `admin123`), en el **Dashboard**, zoom al 100 %.
   - Una terminal con letra grande, con el registro de llamadas entre servicios:
     ```bash
     /opt/lampp/bin/php herramientas/ver-llamadas.php
     ```
7. **En el equipo nuevo** (Integrante 4): abrir la URL del paso 4, iniciar sesión y
   dejarlo en el **Dashboard**.
8. **Justo antes de empezar:** el Integrante 2 pone su teléfono en **modo avión**.

---

## Guion

| Tiempo | Quién | Qué se hace | Qué se dice |
|---|---|---|---|
| 0:00 – 0:50 | **Integrante 1** | Dashboard en pantalla: nuestros equipos y la gráfica de los últimos minutos. Pulsar **Monitorear todos**: se ve el progreso y al final las notificaciones; el **teléfono en modo avión** pasa a Inactivo y el contador de Alertas del menú sube | "Es un sistema para vigilar los equipos de una red: registra dispositivos, comprueba con ping si responden, guarda el historial y avisa cuando uno deja de responder. Está hecho con arquitectura orientada a servicios: Dispositivos, Monitoreo, Alertas, Descubrimiento y Autenticación, y cada servicio es dueño de su propia tabla. Estos son nuestros equipos, conectados al hotspot de un teléfono, con el historial de los últimos minutos. Y aquí pasó algo en vivo: un teléfono está en modo avión, no respondió y se generó una alerta: el contador de Alertas subió." |
| 0:50 – 1:20 | **Integrante 2** | **Dispositivos → Buscar dispositivos en la red** (unos 5 s; la frase se dice mientras avanza). En la lista: el hotspot («Registrado · Router (hotspot)»), la laptop («Esta computadora», «Registrado») y el **equipo nuevo** con su botón **Agregar**. El teléfono en modo avión no aparece | "Ahora busco los equipos de la red. El Servicio de Descubrimiento hace ping a las 254 direcciones en paralelo y lee la tabla ARP: así aparecen hasta los que bloquean el ping. Para saber cuáles ya están registrados, le pregunta a Dispositivos por HTTP. El teléfono en modo avión no aparece; este otro es nuevo. Solo lo usamos en redes propias: este es nuestro hotspot." |
| 1:20 – 2:30 | **Integrante 2** | Pulsar **Agregar** en el equipo nuevo: el formulario trae su IP y su nombre. Pulsar **Agregar dispositivo** sin escribir la ubicación: aparece «La ubicación es obligatoria» bajo el campo. Escribir `Salón` y guardar. Cerrar el panel (✕). En su fila, pulsar **Monitorear**: queda **Activo** con su tiempo | "Con «Agregar», la IP y el nombre ya vienen escritos. La interfaz no toca la base de datos: le pide todo a los servicios por HTTP, y las validaciones las hace el servicio. Si guardo sin ubicación, el mensaje de error viene de la API, no del navegador. Con la ubicación se guarda y empieza sin monitorear; al monitorearlo responde y queda activo con su tiempo." |
| 2:30 – 3:50 | **Integrante 3** | **Alt+Tab** a la terminal: **Ctrl+C**, `clear` (Windows: `cls`) y volver a iniciar `ver-llamadas.php` (flecha arriba + Enter). **Alt+Tab** al navegador y pulsar **Monitorear** en el **teléfono en modo avión**: la notificación dice "Ya tenía una alerta pendiente" y el contador no cambia. **Alt+Tab** a la terminal: aparecen exactamente 4 líneas. Volver al navegador: **Alertas → Marcar como atendida** en ese teléfono: pasa a Atendidas y el contador baja | "Aquí se ve la arquitectura. Vuelvo a monitorear el teléfono en modo avión. El navegador solo le habló a Monitoreo. Monitoreo no consulta tablas ajenas: le pide la IP a Dispositivos, le avisa el resultado y le pide a Alertas que cree la alerta. Cada línea es una petición entre servicios, con su código y su tiempo. Fíjense en la última: 200 y no 201, porque la alerta ya existía y no se duplicó. Entre servicios se autentican con una clave interna; el navegador, con la sesión. Ahora atiendo la alerta: pasa a Atendidas y el contador baja." |
| 3:50 – 5:00 | **Integrante 4** | **Monitoreos**: elegir *Laptop de la demo* (o el teléfono en modo avión, para ver el punto rojo del final) en el filtro: gráfica de los últimos minutos y la disponibilidad. Después, **Dispositivos → Eliminar** en el **equipo nuevo**: aparece el modal de confirmación; confirmar. Por último (**opcional si hay que quedarse en 5:00**: se omite este paso y su última frase), **levantar el equipo nuevo** hacia el público (no sale en el proyector), recargar su Dashboard y mostrar que él mismo ya no aparece | "El historial guarda cada comprobación; este lo generamos antes de la demo, monitoreando cada minuto. Se filtra por dispositivo, estado y fechas; la gráfica muestra los tiempos y los puntos rojos son las veces que no respondió. Eliminar siempre pide confirmación; al borrar el dispositivo, la base elimina en cascada su historial y sus alertas. Y es la misma aplicación en el celular, entrando por la IP de esta computadora: al recargar, el dispositivo ya no aparece, porque la computadora y el celular le piden los datos a los mismos servicios." |
| 5:00 – 5:30 | **Integrante 1** | En la terminal (Ctrl+C al registro): `/opt/lampp/bin/php pruebas/dispositivos.php` (tarda unos 5 s y termina en "Tu base (monitoreo_red_soa) quedó idéntica" y "Resultado: N de N") | "Además de la demo, el sistema tiene seis suites de pruebas automáticas, más de mil comprobaciones: cada endpoint con sus errores, la regla de que ningún servicio toca tablas ajenas, el login y las pantallas en un navegador real. Las pruebas usan su propia base: nunca tocan nuestros datos. Esta es una de ellas en vivo. Gracias." |

**Al terminar:** el Integrante 2 **quita el modo avión** de su teléfono (si no, sigue
generando alertas en cada monitoreo y no recibe llamadas ni mensajes).

Mientras corre la suite del cierre, la aplicación muestra la base de pruebas (los
datos de ejemplo); en cuanto termina, vuelve sola a la nuestra. Si hay preguntas y hay
que navegar la aplicación, basta con recargar la página: los datos siguen ahí y la
sesión sigue abierta.

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
| 1 | 0:00 – 0:50 y 5:00 – 5:30 | 80 s | 148 | 68 s |
| 2 | 0:50 – 2:30 (escáner y agregar) | 100 s | 131 | 60 s |
| 3 | 2:30 – 3:50 | 80 s | 98 | 45 s |
| 4 | 3:50 – 5:00 | 70 s | 91 (55 sin el celular) | 42 s (25 s) |

El Integrante 1 habla más, pero sus acciones corren mientras habla («Monitorear
todos» tarda unos segundos con nuestros 3 equipos y la suite unos 5 s). El Integrante 2
dice la frase del escáner mientras se llena la lista (unos 5 s de búsqueda). Los
integrantes 2, 3 y 4 necesitan el resto de su tramo para escribir, cambiar de ventana
y esperar cada ping.

**Si hay que quedarse en 5:00:** el Integrante 4 omite el paso del celular y su última
frase («Y es la misma aplicación en el celular…»): ahorra unos 25 s y la demo queda en
unos 5:05. El Integrante 1 cierra igual.

### Qué debe verse en cada paso (para ensayar)

- **Dashboard al empezar:** 3 dispositivos (*Router (hotspot)*, *Laptop de la demo* y
  el teléfono), la gráfica «Tiempos de respuesta recientes» con las rondas de los
  últimos minutos y el contador de Alertas en 0.
- **Monitorear todos:** 3 fichas que se van llenando.
  - Activos: la laptop y el hotspot (si responde al ping).
  - Inactivo: el **teléfono en modo avión**: su alerta es la primera en vivo.
  - Contador de Alertas del menú: **de 0 a 1**. Si el hotspot bloquea el ping, también
    sale Inactivo con su alerta: el contador llega a 2 y los siguientes llevan uno más.
- **Buscar dispositivos en la red:** el panel «Equipos en la red» con la barra de
  progreso («Lote 1 de 4» … «Lote 4 de 4»). Al final: el hotspot («Registrado ·
  Router (hotspot)», «Responde al ping» o «Solo en ARP»), la laptop («Esta
  computadora», «Registrado · Laptop de la demo») y el equipo nuevo con **Agregar**.
  El teléfono en modo avión no aparece.
- **Agregar sin ubicación:** «La ubicación es obligatoria» bajo el campo; el modal no se
  cierra. Con la ubicación se guarda: la tabla pasa a 4 y en el panel el equipo nuevo
  queda «Registrado».
- **Monitorear el nuevo:** fila en verde «Activo» con su tiempo y la notificación
  «… responde». Si sale Inactivo, su pantalla se apagó (ver «Si pasa X»).
- **Registro de llamadas** (la terminal recién limpiada, al monitorear otra vez el
  teléfono en modo avión):
  ```
  [10:06:40] Navegador → Monitoreo: POST /monitoreo/3
  [10:06:40]   Monitoreo → Dispositivos: GET /dispositivos/3 (200, 8 ms)
  [10:06:41]   Monitoreo → Dispositivos: PATCH /dispositivos/3/estado (200, 12 ms)
  [10:06:41]   Monitoreo → Alertas: POST /alertas (200, 10 ms)
  ```
  En «Monitorear todos», la última línea fue `201` (alerta creada); esta vez es `200`
  (ya existía y se devolvió la misma). En el navegador: «Ya tenía una alerta
  pendiente.», el contador no cambia.
- **Atender:** la alerta desaparece de Pendientes y el contador baja uno.
- **Eliminar:** modal «¿Eliminar dispositivo?», la tabla vuelve a 3.
- **Celular:** después de recargar, el Dashboard del equipo nuevo muestra los 3
  dispositivos, sin él mismo.
- **Suite del cierre:** «✓ Tu base (monitoreo_red_soa) quedó idéntica» y
  «Resultado: N de N comprobaciones correctas.»

---

## Preguntas que pueden hacer

| Pregunta | Quién | Respuesta corta |
|---|---|---|
| ¿Por qué SOA y no una aplicación monolítica? | Integrante 1 | Cada servicio tiene una sola responsabilidad y es dueño de su tabla; los demás solo lo usan por HTTP con un contrato fijo (`docs/API.md`). Se puede cambiar o mover un servicio a otro servidor sin tocar a los demás (solo su URL en `config.php`), cada integrante pudo trabajar en uno a la vez, y cualquier cliente usa los mismos servicios: por eso el celular funciona sin código aparte. En un monolito todo comparte código y base, y un cambio puede romper cualquier parte |
| ¿Cómo se comunican los servicios y cómo se autentican entre sí? | Integrante 3 | Por HTTP con JSON, siempre con el mismo formato (`ok` y `data`, o `ok` y `error`). Monitoreo usa un cliente común (`comun/http.php`) que espera como máximo 3 segundos. Entre servicios mandan la cabecera `X-Servicio-Clave`, una clave aleatoria de 64 caracteres comparada en tiempo constante; nunca reenvían la cookie del navegador. Los endpoints internos (cambiar el estado de un dispositivo, crear una alerta) responden 403 al navegador aunque tenga sesión |
| ¿Qué pasa si un servicio se cae? | Integrante 3 | Monitoreo responde 502 con un mensaje claro: "No se pudo comunicar con el Servicio de Dispositivos" (o de Alertas). Si el fallo es después del ping, el monitoreo ya quedó guardado: no se pierde el dato. Las pruebas lo simulan con un servicio que no contesta y con uno que tarda más de 3 segundos |
| ¿Cómo funciona el ping? ¿Se puede inyectar un comando por el campo IP? | Integrante 2 | Se ejecuta el `ping` del sistema con un intento y 1 segundo de espera, con las opciones de Linux, Windows o macOS; el dispositivo está activo solo si la respuesta trae `TTL=`, y el tiempo se lee de la salida. No se puede inyectar: la IP se valida como IPv4 al guardar el dispositivo y otra vez antes del ping, así que `127.0.0.1; rm -rf /` se rechaza con 400 sin ejecutar nada; además pasa por `escapeshellarg()`, que la entrega como un solo argumento entre comillas |
| ¿Por qué el teléfono en modo avión no aparece en el escáner? | Integrante 2 | Sin red no contesta ni al ping ni a ARP: para la red, no existe. El escáner muestra solo lo que está conectado en este momento; el monitoreo, en cambio, sigue intentando cada dispositivo registrado y por eso genera la alerta |
| ¿Cómo se evita que una alerta se duplique? | Integrante 4 | En la tabla `alertas`, una columna calculada vale el id del dispositivo si la alerta está pendiente, o nada si ya se atendió, y tiene un índice único. La base no deja guardar dos pendientes del mismo dispositivo, ni con peticiones simultáneas; el servicio devuelve la que ya existía (el 200 del registro). Las atendidas no chocan, por eso después de atender puede nacer una nueva |
| ¿Escanear la red no es un ataque? ¿Cómo encuentra un equipo que no responde al ping? | Integrante 2 | Solo busca en la red de esta computadora (su /24, calculada en el servidor: el navegador no puede pedir otra) y solo si es privada; un ping por dirección, nada más. Aun así, solo lo usamos en redes propias o con permiso, por eso la demo va en nuestro hotspot. Para los que bloquean el ping: antes de enviar un ping, la computadora pregunta por ARP qué tarjeta de red tiene esa IP; el equipo contesta aunque luego ignore el ping, y esa respuesta queda en la tabla ARP con su MAC |
| ¿Por qué el router aparece inactivo si hay internet? | Integrante 2 | Muchos routers y hotspots bloquean el ping por seguridad: siguen funcionando, pero no contestan. El monitoreo usa ping, así que lo marca inactivo y genera una alerta. El escáner, además del ping, lee la tabla ARP de la computadora, donde aparecen los equipos que contestaron en la red aunque ignoren el ping; por eso lo encuentra igual («Solo en ARP»). `preparar-demo.php` también lo revisa y lo marca con ⚠ en lugar de ✗ |
| ¿De dónde sale el historial si los equipos se acaban de agregar? | Integrante 4 | Antes de la demo corrimos `monitorear-rondas.php`: hace «Monitorear todos» cada minuto durante 12 minutos, por los mismos servicios que el botón. No hay datos inventados: todo es de nuestros equipos |
| ¿Las pruebas no borran sus datos? | Integrante 1 | No: usan su propia base (`monitoreo_red_soa_pruebas`). Mientras corren, un archivo que caduca solo hace que los servicios usen esa base, y todo lo que hacen las pruebas va marcado: si algo quedara corriendo, los servicios lo rechazan en lugar de usar nuestra base. Cada suite termina comprobando que nuestra base quedó idéntica |
| ¿Qué prueban las suites? | Integrante 1 | Seis suites, más de mil comprobaciones: Dispositivos (endpoints y la base de pruebas), Alertas, Monitoreo (incluido el 502, la regla de que ningún servicio toca tablas ajenas y las herramientas de la demo), Autenticación (login, cookie, 401/403), Descubrimiento (el escáner con una red simulada, sin tocar la red real) e Interfaz (en un Chrome sin ventana: pantallas con datos y vacías, celular, proyector, teclado y este mismo guion). Cada una recarga su base de pruebas al empezar |

---

## Plan B

Si no se puede usar el hotspot, nuestros equipos no están en la red y la demo con ellos
no funciona. Entonces se presenta con los **datos de ejemplo** (los de la versión 1.0)
y el **guion de la versión 1.0** (`git show v1.0:docs/DEMO.md`), sin el escáner:

| Si… | Entonces |
|---|---|
| **No hay hotspot** (el teléfono no tiene datos o batería) | En la red de la escuela: `/opt/lampp/bin/php herramientas/preparar-demo.php --datos-de-ejemplo` (respalda nuestra base y pide escribir `SI`). **Nunca pulsar «Buscar dispositivos en la red» en la red de la escuela** |
| **No hay WiFi** | `/opt/lampp/bin/php herramientas/preparar-demo.php --datos-de-ejemplo --sin-red`. Todo lo que usa `127.0.0.1` y `192.0.2.x` funciona sin red; el celular se omite |
| **El celular no puede entrar por el hotspot** | Revisar que esté en el hotspot y no en otra red, y volver a correr `preparar-demo.php` (imprime la URL). Si sigue sin entrar, se omite el paso del celular |
| **Nada funciona el día de la demo** | Último recurso: el **video grabado** de la demo (ver abajo) |

**Después del plan B, volver a nuestros datos:** `--datos-de-ejemplo` imprimió el nombre
del respaldo; restaurarlo con

```bash
/opt/lampp/bin/php herramientas/restaurar-respaldo.php            # lista los respaldos
/opt/lampp/bin/php herramientas/restaurar-respaldo.php ARCHIVO    # respalda lo actual y pide SI
```

### Grabar el video de respaldo (hacerlo antes del día)

Grabar el guion completo, una vez que salga bien en un ensayo con los equipos reales:

- **Linux (GNOME):** `Ctrl+Alt+Shift+R` inicia y detiene la grabación de pantalla
  (se guarda en `~/Vídeos/Screencasts`). Para más control, OBS Studio.
- **Windows:** `Win+Alt+R` graba la ventana activa con la Xbox Game Bar (se guarda en
  `Vídeos\Capturas`). Para grabar la pantalla completa, OBS Studio.

Llevarlo en la computadora **y** en una memoria USB.

---

## Si pasa X, haz Y

| Si pasa… | Haz… |
|---|---|
| Todas las pantallas dicen **"No se pudo conectar con el servidor"** / ✗ *Apache responde* | Encender Apache: `sudo /opt/lampp/lampp startapache` (Windows: *Start* de Apache en el panel de XAMPP) |
| ✗ *Los servicios contestan* (Apache sí responde) | La carpeta debe llamarse `monitoreo-red-soa` dentro de `htdocs` y Apache debe leer los `.htaccess` (README, "Instalación") |
| Las pantallas dicen **"Error interno del servidor"** / el script dice que no puede conectar con MySQL | Encender MySQL: `sudo /opt/lampp/lampp startmysql` (Windows: panel). Si no arranca en Linux: `tmpdir = /tmp` debajo de `[mysqld]` en `/opt/lampp/etc/my.cnf` |
| ✗ *La base monitoreo_red_soa no existe* | `/opt/lampp/bin/php herramientas/crear-base.php` y registrar los equipos ([Una sola vez](#una-sola-vez-el-día-anterior-o-antes)) |
| ⚠ *Tu base no tiene dispositivos* / ⚠ *El router o esta computadora no están en tu base* | Agregarlos con **Buscar dispositivos en la red** ([Una sola vez](#una-sola-vez-el-día-anterior-o-antes)) |
| ⚠ *Todavía no hay historial* / *El último monitoreo fue hace…* | `/opt/lampp/bin/php herramientas/monitorear-rondas.php --rondas=12 --cada=60` (12 minutos, pantallas encendidas) |
| ✗ *El router no responde al ping* | La laptop no está en el hotspot: conectarla y volver a correr `preparar-demo.php` |
| ⚠ *El router bloquea el ping, pero está conectado (aparece en ARP)* | Nada que corregir: el hotspot está conectado pero no contesta el ping. En la demo saldrá Inactivo con su alerta (el contador llega a 2) y el escáner lo mostrará «Solo en ARP». Si preguntan, ver «¿Por qué el router aparece inactivo si hay internet?» |
| **El escáner muestra como nuevo un equipo que ya registramos** | El hotspot le dio otra IP (pasa de un día a otro). No agregarlo dos veces: en **Dispositivos → Editar** ese equipo y poner la IP nueva que mostró el escáner |
| **Un teléfono registrado sale Inactivo** sin estar en modo avión | Su pantalla se apagó: encenderla y volver a pulsar Monitorear |
| **El teléfono en modo avión sale Activo** | No está en modo avión (o tiene WiFi encendido en modo avión): apagar el WiFi y volver a pulsar Monitorear |
| **Aparece la pantalla de inicio de sesión** a mitad de la demo | La sesión dura 8 horas; volver a entrar con `admin` / `admin123`: regresa a la pantalla donde estaban |
| **El celular no carga la página** | Mismo hotspot que la laptop; la URL que imprimió `preparar-demo.php`; cortafuegos: `sudo ufw allow from <red>/24 to any port 80 proto tcp` (Linux) o permitir Apache en el Firewall de Windows |
| Se ve **una versión vieja** de la interfaz | Recarga forzada `Ctrl+Shift+R` (en el celular, cerrar la pestaña y abrirla de nuevo). Desde la Fase 8 las páginas se revalidan solas, así que no debería pasar |
| Un monitoreo tarda o sale **"No se pudo comunicar con el Servicio de…"** | Algún servicio no respondió a tiempo (3 s). Volver a pulsar Monitorear; si se repite, revisar la terminal de `ver-llamadas.php` para ver qué llamada falló |
| Durante la suite del cierre alguien recarga y ve **datos de ejemplo** | Es la base de pruebas, solo mientras corre la suite: al terminar, recargar y vuelven nuestros datos |
| **La búsqueda no encuentra el equipo nuevo** | Despertar su pantalla y pulsar «Buscar de nuevo». Si sigue sin aparecer, no insistir: agregar uno a mano con **Agregar dispositivo** y seguir con el guion |
| La búsqueda dice **"No se detectó una red local"** o **"Solo se pueden escanear redes privadas"** | La laptop no está conectada al hotspot: conectarla y «Buscar de nuevo» |

---

## El día anterior

- [ ] **Confirmar con el profesor el tiempo disponible**: el guion completo dura unos
  5:30 (unos 5:05 sin la recarga del celular). Si el límite es estricto en 5:00,
  decidir de antemano qué se omite.
- [ ] **Ensayo completo con los equipos reales en el hotspot**, cronometrado y con las
  pantallas encendidas: crear la base si hace falta, registrar los equipos, correr las
  rondas y el guion completo. Anotar **cuáles responden al ping** (el hotspot puede
  bloquearlo; algunos teléfonos también) y ajustar «Qué debe verse» con lo que pase.
- [ ] Al terminar el ensayo: quitar el modo avión, **eliminar el equipo nuevo** si quedó
  registrado y atender las alertas, para empezar la demo igual que en el ensayo.
- [ ] Correr las 6 suites en la laptop de la demo (README, sección 6): no tocan nuestra
  base.
- [ ] Si es posible, conectar la laptop al **proyector real del salón**: revisar la
  resolución y que la letra de la terminal de `ver-llamadas.php` se lea desde el fondo
  del salón. Hasta ahora el proyector solo se simuló con Chrome sin ventana
  (1024×768 y 1280×720).
- [ ] Probar el plan B una vez (`--datos-de-ejemplo` y después `restaurar-respaldo.php`).
- [ ] Grabar el video de respaldo.
