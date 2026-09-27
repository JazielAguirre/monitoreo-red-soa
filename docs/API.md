# Contrato de la API

Fuente de verdad de los endpoints de los servicios: rutas, métodos, campos y formatos.

> Pendiente.

## Endpoints temporales

### `GET /servicios/monitoreo/prueba?ip={ipv4}` (Fase 0, se elimina en la Fase 4)

Hace ping a una IPv4 y devuelve el resultado. Sirve solo para comprobar que el ping funciona a través de Apache.

Respuesta 200:

```json
{
  "ok": true,
  "data": {
    "ip": "127.0.0.1",
    "estado": "activo",
    "tiempo_respuesta_ms": 0.12,
    "salida": "PING 127.0.0.1 ... time=0.118 ms ..."
  }
}
```

- `estado`: `activo` o `inactivo`.
- `tiempo_respuesta_ms`: número con hasta 2 decimales; `0` si fue menor a 1 ms; `null` si no respondió.

Errores: `400` si falta `ip` o no es una IPv4 válida (no se ejecuta ningún comando); `405` si el método no es GET.
