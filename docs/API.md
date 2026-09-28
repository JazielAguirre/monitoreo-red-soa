# Contrato de la API

Este documento es la fuente de verdad de la comunicación entre la interfaz y los servicios, y entre los servicios. Cualquier cambio en un endpoint, un campo o un formato se acuerda en equipo y se actualiza aquí en el mismo commit.

## Convenciones

**URL base:** `http://localhost/monitoreo-red-soa/servicios` (definida en `comun/config.php`).

**Respuesta exitosa:**

```json
{ "ok": true, "data": { } }
```

**Respuesta con error:**

```json
{ "ok": false, "error": "Revisa los datos del formulario", "campos": { "ip": "Dirección IP no válida" } }
```

`campos` es opcional y solo aparece en errores de validación (400 y 409). Sus claves son los nombres de los campos, y la interfaz muestra cada mensaje debajo de su campo.

**Códigos HTTP:**

| Código | Uso |
|---|---|
| 200 | Consulta o modificación correcta |
| 201 | Recurso creado |
| 400 | Datos o filtros inválidos |
| 401 | Sin sesión (desde la Fase 7) |
| 403 | Endpoint interno llamado sin la clave de servicio (desde la Fase 7) |
| 404 | El recurso o la ruta no existe |
| 405 | Método no permitido en esa ruta |
| 409 | Conflicto, por ejemplo una IP duplicada |
| 500 | Error interno (el detalle va a `error_log()`, nunca al cliente) |
| 502 | Otro servicio no respondió o respondió algo inesperado |

**Nombres de campos:** `snake_case`, en español y **sin acentos ni ñ** (`ubicacion`, `contrasena`), iguales a las columnas de la base de datos.

**Tipos en JSON:** los números viajan como números y los booleanos como booleanos. PDO devuelve `DECIMAL` y `TINYINT` como texto, así que se convierten antes de responder: `"0.32"` → `0.32`, `"1"` → `true`.

**Fechas:** formato `YYYY-MM-DD HH:MM:SS`, en hora local de `America/Mazatlan`. La zona se fija en `comun/config.php` con `date_default_timezone_set()`, y **todas las fechas las genera PHP**, nunca `NOW()` de MySQL. El `php.ini` de XAMPP puede traer otra zona configurada y MySQL usa la del sistema; si se mezclan, las horas no coinciden.

**Tiempos de respuesta:** en milisegundos, con hasta 2 decimales (`0.32`, `52.2`). `null` cuando el dispositivo no respondió.

**Errores comunes a todos los servicios** (los genera la infraestructura de `comun/`):

| Caso | Código | `error` |
|---|---|---|
| El cuerpo está vacío o no es JSON válido | 400 | "El cuerpo de la petición no es JSON válido" |
| El cuerpo es JSON pero no un objeto (`[1,2]`, `"texto"`, `null`) | 400 | "El cuerpo de la petición debe ser un objeto JSON" |
| La ruta no existe (incluye un `{id}` que no es un entero positivo) | 404 | "La ruta no existe" |
| La ruta existe pero no acepta ese método | 405 | "Método no permitido", con la cabecera `Allow` que indica los métodos aceptados |
| Error inesperado o base de datos no disponible | 500 | "Error interno del servidor" |

**Acceso:** cada endpoint está marcado como **[Interfaz]** (lo llama el navegador) o **[Interno]** (solo lo llaman otros servicios). Antes de la Fase 7 todos están abiertos. Desde la Fase 7:

- Los endpoints [Interfaz] aceptan sesión activa o la cabecera `X-Servicio-Clave` válida.
- Los endpoints [Interno] solo aceptan `X-Servicio-Clave`; sin ella responden 403.

---

## Modelo de datos

Todas las tablas: InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`. Cada tabla pertenece a un solo servicio.

### `dispositivos` (Servicio de Dispositivos)

| Columna | Tipo | Reglas |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `nombre` | VARCHAR(100) | NOT NULL |
| `ip` | VARCHAR(15) | NOT NULL, UNIQUE |
| `tipo` | ENUM('computadora','servidor','impresora','router') | NOT NULL |
| `ubicacion` | VARCHAR(100) | NOT NULL |
| `estado` | ENUM('activo','inactivo','desconocido') | NOT NULL, DEFAULT 'desconocido' |
| `ultimo_tiempo_ms` | DECIMAL(7,2) | NULL |
| `ultima_comprobacion` | DATETIME | NULL |
| `fecha_registro` | DATETIME | NOT NULL |

`ultimo_tiempo_ms` y `ultima_comprobacion` son una copia del último monitoreo. Los actualiza el Servicio de Monitoreo mediante `PATCH /dispositivos/{id}/estado`, así Dispositivos sigue siendo el único que escribe en su tabla.

### `monitoreos` (Servicio de Monitoreo)

| Columna | Tipo | Reglas |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `dispositivo_id` | INT UNSIGNED | NOT NULL, FK → `dispositivos(id)` ON DELETE CASCADE |
| `ip` | VARCHAR(15) | NOT NULL. La IP usada en ese ping |
| `estado` | ENUM('activo','inactivo') | NOT NULL |
| `tiempo_respuesta_ms` | DECIMAL(7,2) | NULL si no respondió |
| `fecha` | DATETIME | NOT NULL |

Índices: `(dispositivo_id, fecha)` y `(fecha)`.

### `alertas` (Servicio de Alertas)

| Columna | Tipo | Reglas |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `dispositivo_id` | INT UNSIGNED | NOT NULL, FK → `dispositivos(id)` ON DELETE CASCADE |
| `tipo` | ENUM('sin_respuesta') | NOT NULL |
| `mensaje` | VARCHAR(255) | NOT NULL |
| `fecha` | DATETIME | NOT NULL |
| `atendida` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `fecha_atendida` | DATETIME | NULL |
| `dispositivo_pendiente` | INT UNSIGNED | Columna generada VIRTUAL: `IF(atendida = 0, dispositivo_id, NULL)`. UNIQUE. Interna: no viaja en el objeto `alerta` |

Índices: `(dispositivo_id, atendida)`, `(atendida, fecha)` y UNIQUE `(dispositivo_pendiente)`.

**Una sola alerta pendiente por dispositivo, garantizado por la base de datos:** `dispositivo_pendiente` solo tiene valor mientras la alerta está pendiente, y su índice único rechaza una segunda pendiente (error 1062) aunque dos `POST /alertas` lleguen al mismo tiempo. `UNIQUE` admite varios `NULL`, así que las alertas atendidas no chocan entre sí. Probado en MariaDB 10.4 (la del XAMPP).

### `usuarios` (autenticación, Fase 7)

| Columna | Tipo | Reglas |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `usuario` | VARCHAR(50) | NOT NULL, UNIQUE |
| `nombre` | VARCHAR(100) | NOT NULL. Se muestra en el menú ("Administrador") |
| `contrasena_hash` | VARCHAR(255) | NOT NULL. Resultado de `password_hash()` |
| `fecha_registro` | DATETIME | NOT NULL |

**Borrado en cascada:** al eliminar un dispositivo, la base de datos borra sus monitoreos y alertas por medio de las llaves foráneas. Ningún servicio borra filas de tablas ajenas en su código.

---

## Servicio de Dispositivos

### Objeto `dispositivo`

```json
{
  "id": 3,
  "nombre": "Servidor principal",
  "ip": "192.168.1.10",
  "tipo": "servidor",
  "ubicacion": "Sala de servidores",
  "estado": "inactivo",
  "ultimo_tiempo_ms": null,
  "ultima_comprobacion": "2026-09-21 10:05:12",
  "fecha_registro": "2026-09-20 09:00:00"
}
```

### Validaciones (POST y PUT)

Se recortan los espacios al inicio y al final antes de validar. Si hay varios campos inválidos, `campos` los trae todos, y `error` es "Revisa los datos del formulario".

**Solo se toman `nombre`, `ip`, `tipo` y `ubicacion`.** Si el cuerpo trae `id`, `estado`, `ultimo_tiempo_ms`, `ultima_comprobacion` o `fecha_registro`, se ignoran sin error: el estado solo cambia con `PATCH /dispositivos/{id}/estado`.

| Campo | Regla | Mensaje en `campos` |
|---|---|---|
| `nombre` | Obligatorio, 1–100 caracteres | "El nombre es obligatorio" / "Máximo 100 caracteres" |
| `ip` | Obligatoria, IPv4 válida (`FILTER_FLAG_IPV4`) | "Dirección IP no válida" |
| `ip` | Única (409) | "Ya existe un dispositivo con esa IP" |
| `tipo` | `computadora`, `servidor`, `impresora` o `router` | "Tipo de dispositivo no válido" |
| `ubicacion` | Obligatoria, 1–100 caracteres | "La ubicación es obligatoria" / "Máximo 100 caracteres" |

### `GET /dispositivos` [Interfaz]

Lista los dispositivos ordenados por `nombre`.

Filtros opcionales: `estado` (`activo`, `inactivo`, `desconocido`) y `tipo`. Un valor no permitido responde 400 con `error` "Filtros no válidos" y el mensaje en `campos` ("Estado no válido" / "Tipo de dispositivo no válido"). Un filtro vacío (`?estado=`) equivale a no filtrar. La interfaz normalmente pide la lista completa y filtra en el navegador, porque también necesita los contadores de cada filtro.

**200:** `data` es un arreglo de objetos `dispositivo`.

### `GET /dispositivos/{id}` [Interfaz]

**200:** `data` es el objeto `dispositivo`. **404:** "El dispositivo no existe".

### `POST /dispositivos` [Interfaz]

```json
{ "nombre": "Servidor de archivos", "ip": "192.168.1.30", "tipo": "servidor", "ubicacion": "Sala de servidores" }
```

**201:** el dispositivo creado, con `estado` = `desconocido` y los campos `ultimo_*` en `null`. **400** o **409** con `campos`.

### `PUT /dispositivos/{id}` [Interfaz]

Mismo cuerpo que POST; todos los campos son obligatorios.

Si la IP cambia, el dispositivo vuelve a `estado` = `desconocido` y `ultimo_tiempo_ms` y `ultima_comprobacion` pasan a `null`, porque los datos anteriores eran de otra IP. El historial se conserva: cada monitoreo guarda la IP que se usó.

**200:** el dispositivo actualizado. **400**, **404** o **409**.

### `DELETE /dispositivos/{id}` [Interfaz]

**200:** `data` = `{ "id": 3 }`. Se borran también sus monitoreos y alertas (cascada). **404** si no existe.

### `PATCH /dispositivos/{id}/estado` [Interno]

Lo usa el Servicio de Monitoreo después de cada ping.

```json
{ "estado": "activo", "tiempo_respuesta_ms": 0.32, "fecha": "2026-09-21 10:05:12" }
```

Actualiza `estado`, `ultimo_tiempo_ms` (con el valor de `tiempo_respuesta_ms`) y `ultima_comprobacion` (con el valor de `fecha`).

| Campo | Regla | Mensaje en `campos` |
|---|---|---|
| `estado` | `activo` o `inactivo` | "Estado no válido" |
| `tiempo_respuesta_ms` | Con `activo`: número entero o decimal, de 0 a 99999.99 (`2` y `2.5` son válidos, porque el ping de Windows da enteros). Se rechazan texto, booleanos y `null` | "El tiempo de respuesta debe ser un número mayor o igual a 0" |
| `tiempo_respuesta_ms` | Con `inactivo`: `null` (si falta, cuenta como `null`) | "Con estado inactivo, el tiempo de respuesta debe ser null" |
| `fecha` | Obligatoria, `YYYY-MM-DD HH:MM:SS` y una fecha que exista | "Fecha no válida, usa el formato YYYY-MM-DD HH:MM:SS" |

**200:** el dispositivo actualizado. **400** con `campos`, o **404**.

---

## Servicio de Alertas

### Objeto `alerta`

```json
{
  "id": 7,
  "dispositivo_id": 3,
  "tipo": "sin_respuesta",
  "mensaje": "El dispositivo Servidor principal no respondió al monitoreo.",
  "fecha": "2026-09-21 10:05:12",
  "atendida": false,
  "fecha_atendida": null
}
```

### `GET /alertas` [Interfaz]

Lista las alertas de la más reciente a la más antigua.

Filtros opcionales: `atendida` (`0` o `1`), `dispositivo` (id) y `limite` (1–100, 50 por defecto). Un filtro vacío equivale a no filtrar. Un `dispositivo` sin alertas (o que no existe) devuelve `[]`: Alertas no consulta la tabla `dispositivos`. Orden: `fecha` descendente y, a igual fecha, `id` descendente.

Un filtro inválido responde **400** con `error` "Filtros no válidos" y el mensaje en `campos`:

| Filtro | Mensaje en `campos` |
|---|---|
| `atendida` | "El filtro atendida debe ser 0 o 1" |
| `dispositivo` | "Id de dispositivo no válido" |
| `limite` | "El límite debe ser un número entre 1 y 100" |

**200:** `data` es un arreglo de objetos `alerta`.

### `GET /alertas/conteo` [Interfaz]

**200:** `data` = `{ "pendientes": 1, "atendidas": 3 }`.

Alimenta el contador del menú lateral en todas las pantallas y los contadores de las pestañas.

### `POST /alertas` [Interno]

Lo usa el Servicio de Monitoreo cuando un dispositivo no responde.

```json
{ "dispositivo_id": 3, "tipo": "sin_respuesta", "mensaje": "El dispositivo Servidor principal no respondió al monitoreo." }
```

**La regla contra alertas repetidas vive aquí:** si el dispositivo ya tiene una alerta pendiente, no se crea otra (el `mensaje` de la petición se ignora y se devuelve la existente). También se cumple con dos `POST` simultáneos, gracias al índice único de `dispositivo_pendiente` (ver el modelo de datos).

`fecha` la genera el servicio. Se recortan los espacios de `mensaje`.

| Campo | Regla | Mensaje en `campos` |
|---|---|---|
| `dispositivo_id` | Obligatorio, entero positivo (`"5"` como texto no vale) | "Id de dispositivo no válido" |
| `tipo` | `sin_respuesta` | "Tipo de alerta no válido" |
| `mensaje` | Obligatorio, 1–255 caracteres | "El mensaje es obligatorio" / "Máximo 255 caracteres" |

- **201:** `data` = `{ "alerta": { }, "creada": true }`.
- **200:** `data` = `{ "alerta": { }, "creada": false }`, con la alerta pendiente que ya existía.
- **400** con `error` "Revisa los datos de la alerta" y `campos`. **404** "El dispositivo no existe" (lo detecta la llave foránea).

### `PATCH /alertas/{id}/atender` [Interfaz]

Marca la alerta como atendida y guarda `fecha_atendida` (la genera el servicio). Si ya estaba atendida, responde 200 sin cambiarla: conserva su `fecha_atendida` original. No necesita cuerpo; si llega uno, se ignora.

**200:** la alerta actualizada. **404:** "La alerta no existe".

---

## Servicio de Monitoreo

### Objeto `monitoreo`

```json
{
  "id": 120,
  "dispositivo_id": 3,
  "ip": "192.168.1.10",
  "estado": "inactivo",
  "tiempo_respuesta_ms": null,
  "fecha": "2026-09-21 10:05:12"
}
```

El nombre del dispositivo no viaja aquí; la interfaz lo toma de `GET /dispositivos`.

### `POST /monitoreo/{dispositivoId}` [Interfaz]

Ejecuta un ping y registra el resultado. Tarda como máximo un poco más de 1 segundo. "Monitorear todos" llama a este endpoint una vez por dispositivo, uno tras otro, para mostrar el progreso.

Flujo:

1. `GET /dispositivos/{id}` para obtener la IP y el nombre. Si responde 404, se devuelve 404.
2. Ping con la función de `ping.php`.
3. Se genera la fecha **una sola vez** y se guarda el monitoreo en `monitoreos`. Si el INSERT falla por la llave foránea (el dispositivo se eliminó entre el paso 1 y este), se devuelve 404.
4. `PATCH /dispositivos/{id}/estado` con el mismo estado, tiempo y fecha del paso 3. Así el monitoreo y el dispositivo tienen exactamente la misma fecha.
5. Si no respondió: `POST /alertas` con el mensaje "El dispositivo {nombre} no respondió al monitoreo.". La alerta lleva la fecha que genera el Servicio de Alertas (cada servicio es dueño de sus datos, y `POST /alertas` no acepta fechas de quien llama): coincide con la del monitoreo salvo por fracciones de segundo.

Si el paso 4 falla, no se intenta el 5. Con estado `activo` no se llama a Alertas y `alerta_creada` es `false`.

**201:**

```json
{ "ok": true, "data": { "monitoreo": { }, "alerta_creada": false } }
```

**404:** "El dispositivo no existe" (también si Dispositivos o Alertas responden 404 en los pasos 4 o 5).

**502:** "No se pudo comunicar con el Servicio de Dispositivos" o "…de Alertas", cuando ese servicio no responde en `TIEMPO_ESPERA_SERVICIOS` segundos (3, en `comun/config.php`), responde algo que no es el JSON del formato común, o responde un código inesperado (por ejemplo 500). Si el fallo ocurre en el paso 4 o 5, el monitoreo ya quedó guardado.

### `GET /monitoreo` [Interfaz]

Historial paginado, del más reciente al más antiguo.

| Parámetro | Valor | Por defecto |
|---|---|---|
| `dispositivo` | id | todos |
| `estado` | `activo` o `inactivo` | todos |
| `desde` | `YYYY-MM-DD`, incluido | sin límite |
| `hasta` | `YYYY-MM-DD`, incluido | sin límite |
| `pagina` | ≥ 1 | 1 |
| `por_pagina` | 1–100 | 10 |

`hasta` es inclusivo: llega hasta las 23:59:59 de ese día. Un parámetro vacío equivale a no enviarlo. Una `pagina` mayor que `total_paginas` responde 200 con `items: []`. Si no hay monitoreos, `total` y `total_paginas` son `0`.

Un parámetro inválido responde **400** con `error` "Filtros no válidos" y el mensaje en `campos`:

| Parámetro | Mensaje en `campos` |
|---|---|
| `dispositivo` | "Id de dispositivo no válido" |
| `estado` | "Estado no válido" |
| `desde`, `hasta` | "Fecha no válida, usa el formato YYYY-MM-DD" (también si la fecha no existe, como `2026-02-30`) |
| `hasta` anterior a `desde` | `campos.hasta`: "La fecha final debe ser igual o posterior a la inicial" |
| `pagina` | "La página debe ser un número entero mayor o igual a 1" |
| `por_pagina` | "Debe ser un número entre 1 y 100" |

**200:**

```json
{
  "ok": true,
  "data": {
    "items": [ ],
    "total": 26,
    "pagina": 1,
    "por_pagina": 10,
    "total_paginas": 3,
    "resumen": { "promedio_ms": 1.1, "maximo_ms": 2.5, "disponibilidad": 96.2 }
  }
}
```

Reglas del `resumen`:

- Se calcula en SQL con los filtros `dispositivo`, `desde` y `hasta`, **ignorando** `estado` y la paginación. Si no, la disponibilidad con el filtro "Activos" siempre sería 100 %.
- `promedio_ms` y `maximo_ms` usan solo los monitoreos activos; son `null` si no hay ninguno.
- `promedio_ms` se redondea a 2 decimales.
- `disponibilidad` = activos ÷ total × 100, con 1 decimal; `null` si no hay monitoreos.

La gráfica de la pantalla Monitoreos pide `por_pagina=24` con el mismo dispositivo y rango, y la interfaz invierte el orden para dibujarla de izquierda a derecha.

### `GET /monitoreo/recientes` [Interfaz]

Últimas comprobaciones de cada dispositivo, para la gráfica del dashboard.

Parámetro: `limite` (1–50, 10 por defecto). Un valor inválido responde **400** con `error` "Filtros no válidos" y `campos.limite` "El límite debe ser un número entre 1 y 50".

**200:**

```json
{
  "ok": true,
  "data": [
    { "dispositivo_id": 1, "monitoreos": [ { }, { } ] }
  ]
}
```

Solo incluye dispositivos con al menos un monitoreo, ordenados por `dispositivo_id`. Cada lista va en orden cronológico ascendente, lista para graficar.

---

## Autenticación (Fase 7)

### `POST /auth/login`

```json
{ "usuario": "admin", "contrasena": "admin123" }
```

**200:** `data` = `{ "usuario": { "id": 1, "usuario": "admin", "nombre": "Administrador" } }`, y se inicia la sesión de PHP.

**401:** "Usuario o contraseña incorrectos". Es el mismo mensaje si el usuario no existe o si la contraseña está mal.

### `POST /auth/logout`

**200:** cierra la sesión.

### `GET /auth/sesion`

**200:** el mismo `data` que el login. **401** si no hay sesión; la interfaz redirige a `login.html`.

---

## Qué usa cada pantalla

| Pantalla | Llamadas |
|---|---|
| Todas | `GET /alertas/conteo` (menú lateral). Desde la Fase 7, también `GET /auth/sesion` |
| Dashboard | `GET /dispositivos` (indicadores y tarjetas), `GET /monitoreo/recientes`, `GET /alertas?limite=4`, `POST /monitoreo/{id}` para "Monitorear todos". La "Última comprobación" es la `ultima_comprobacion` más reciente de la lista de dispositivos |
| Dispositivos | `GET`, `POST`, `PUT` y `DELETE /dispositivos`, `POST /monitoreo/{id}` |
| Monitoreos | `GET /dispositivos` (filtro y nombres), `GET /monitoreo` |
| Alertas | `GET /alertas?atendida=0` o `=1`, `GET /alertas/conteo`, `PATCH /alertas/{id}/atender`, `GET /dispositivos` (nombre e IP) |
| Inicio de sesión | `POST /auth/login` |
