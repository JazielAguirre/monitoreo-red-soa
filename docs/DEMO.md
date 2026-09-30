# Guion de la demo

Unos **5 minutos** para mostrar el sistema de principio a fin: agregar un dispositivo,
monitorearlo, ver cómo se genera la alerta, atenderla, consultar el historial y
eliminarlo, más la parte de SOA (los servicios hablándose por HTTP) y las pruebas
automáticas como evidencia.

Reparto entre 4 integrantes: cambien «Integrante 1–4» por sus nombres.

---

## Antes de empezar (10 minutos antes)

En la computadora que va al proyector:

1. **Apache y MySQL encendidos** (`sudo /opt/lampp/lampp startapache` y `startmysql`,
   o el panel de XAMPP en Windows).
2. **Preparar la demo** en la red donde se va a presentar:
   ```bash
   /opt/lampp/bin/php herramientas/preparar-demo.php
   ```
   Todas las líneas de la comprobación previa deben quedar en ✓. Anoten la **URL para
   el celular** que imprime al final. Si algo sale con ✗, sigan su «Qué hacer» o la
   tabla [Si pasa X, haz Y](#si-pasa-x-haz-y).
3. **Abrir dos ventanas:**
   - El navegador en <http://localhost/monitoreo-red-soa/web/>, con sesión iniciada
     (`admin` / `admin123`), en el **Dashboard**, zoom al 100 %.
   - Una terminal con letra grande, con el registro de llamadas entre servicios:
     ```bash
     /opt/lampp/bin/php herramientas/ver-llamadas.php
     ```
4. **En el celular:** abrir la URL del paso 2 e iniciar sesión.
5. Tener a mano el texto de los datos del dispositivo nuevo:
   **Servidor de pruebas · 192.0.2.50 · Servidor · Laboratorio**
   (192.0.2.50 es de un rango reservado para documentación: nunca responde).

---

## Guion

| Tiempo | Quién | Qué se hace | Qué se dice |
|---|---|---|---|
| 0:00 – 0:50 | **Integrante 1** | Dashboard en pantalla. Pulsar **Monitorear todos**: se ve el progreso ("3 de 6") y al final la notificación con el resumen | "Es un sistema para vigilar los equipos de una red: registra dispositivos, comprueba con ping si responden, guarda el historial y avisa cuando uno deja de responder. Está hecho con arquitectura orientada a servicios: la interfaz no toca la base de datos, le pide todo a cuatro servicios por HTTP, y cada servicio es dueño de su propia tabla." |
| 0:50 – 2:00 | **Integrante 2** | **Dispositivos → Agregar dispositivo**. Escribir primero la IP `192.168.1.300` y guardar: aparece "Dirección IP no válida" bajo el campo. Corregir a `192.0.2.50` y guardar. En su fila, pulsar **Monitorear**: queda **Inactivo** y aparece la notificación "Servidor de pruebas no respondió"; el contador de Alertas del menú sube | "Las validaciones las hace el servicio: el mensaje viene de la API. El dispositivo nuevo empieza sin monitorear. Al monitorearlo, el servicio le hace ping; como no responde, queda inactivo y se genera una alerta. Si se vuelve a monitorear mientras la alerta sigue pendiente, no se duplica." |
| 2:00 – 3:10 | **Integrante 3** | Cambiar a la **terminal** del registro: ya muestra las llamadas del monitoreo anterior (si hace falta, volver al navegador y monitorear otra vez). Luego, en el navegador, **Alertas → Marcar como atendida**: la alerta pasa a Atendidas y el contador del menú baja | "Aquí se ve la arquitectura. El navegador solo llamó a Monitoreo. Monitoreo no consulta las tablas de los demás: le pide la IP a Dispositivos por HTTP, le avisa el resultado y le pide a Alertas que cree la alerta; cada línea es una petición entre servicios, con su código y su tiempo. Entre servicios se autentican con una clave interna; el navegador, con la sesión." |
| 3:10 – 4:20 | **Integrante 4** | **Monitoreos**: elegir *Servidor principal* en el filtro: gráfica de tiempos, puntos rojos donde no respondió y la disponibilidad. Después, **Dispositivos → Eliminar** en *Servidor de pruebas*: aparece el modal de confirmación; confirmar. Por último, mostrar el **celular** con el Dashboard | "El historial guarda cada comprobación: se filtra por dispositivo, estado y fechas, y la disponibilidad sale del porcentaje de respuestas. Eliminar siempre pide confirmación; al borrar el dispositivo, la base borra en cascada su historial y sus alertas. Y es la misma aplicación en el celular, entrando por la IP de esta computadora." |
| 4:20 – 5:00 | **Integrante 1** | En la terminal (Ctrl+C al registro): `/opt/lampp/bin/php pruebas/dispositivos.php` (tarda 2 s y termina en "Resultado: 207 de 207") | "Además de la demo, el sistema tiene cinco suites de pruebas automáticas, más de mil comprobaciones: cada endpoint con sus errores, la regla de que ningún servicio toca tablas ajenas, el login, y las pantallas en un navegador real, en computadora, celular y proyector. Esta es una de ellas en vivo. Gracias." |

Si sobra tiempo o preguntan por SOA, se puede mostrar además qué pasa cuando un
servicio no responde: `pruebas/monitoreo.php` simula que Dispositivos o Alertas no
contesta y comprueba que Monitoreo responde 502 con un mensaje claro sin perder el
monitoreo guardado (sección 7 de la suite).

### Qué debe verse en cada paso (para ensayar)

- **Monitorear todos:** 6 fichas que se van llenando; *Mi PC* y *PC Recepción* activos
  (y el router, si la red lo deja responder al ping); el Servidor principal inactivo.
- **Agregar con IP mala:** mensaje bajo el campo IP, el modal no se cierra.
- **Monitorear el nuevo:** fila en rojo "Inactivo · Sin respuesta", notificación con
  "Ver alerta", contador del menú +1.
- **Registro de llamadas:**
  ```
  [10:05:12] Navegador → Monitoreo: POST /monitoreo/7
  [10:05:12]   Monitoreo → Dispositivos: GET /dispositivos/7 (200, 8 ms)
  [10:05:13]   Monitoreo → Dispositivos: PATCH /dispositivos/7/estado (200, 12 ms)
  [10:05:13]   Monitoreo → Alertas: POST /alertas (201, 10 ms)
  ```
- **Atender:** la alerta desaparece de Pendientes, el contador baja.
- **Eliminar:** modal "¿Eliminar dispositivo?", la tabla vuelve a 6.

---

## Plan B

| Si… | Entonces |
|---|---|
| **No hay WiFi** (o la red de la escuela bloquea los equipos) | `php herramientas/preparar-demo.php --sin-red`. Todo lo que usa `127.0.0.1` y `192.0.2.x` funciona sin red: el router pasa a `192.0.2.30` (no responde, sirve para mostrar la alerta) y PC Recepción a `127.0.0.2` (responde). La demo completa se hace en la computadora; el celular se omite o se usa el siguiente punto |
| **El celular no puede entrar por la red de la escuela** | Activar el **hotspot de un teléfono**, conectar la computadora y el celular a él y volver a correr `preparar-demo.php` (detecta la nueva IP e imprime la nueva URL) |
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

---

## El día anterior

- [ ] Correr las 5 suites en la computadora de la demo (README, sección 6).
- [ ] Ensayar el guion completo al menos una vez, cronometrado.
- [ ] Si es posible, correr `preparar-demo.php` en la red de la escuela para ver qué IPs detecta.
- [ ] Probar el hotspot del teléfono con la computadora y el celular.
- [ ] Grabar el video de respaldo.
