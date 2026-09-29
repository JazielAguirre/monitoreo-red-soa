/*
 * Único punto de acceso a la API: envía las peticiones HTTP + JSON a los servicios
 * e interpreta { ok, data, error, campos } (docs/API.md).
 *
 * La URL base se calcula desde la ubicación de este archivo, nunca con "localhost":
 * desde el celular, "localhost" sería el propio celular.
 * El manejo del 401 (redirigir a login.html) se agrega en la Fase 7.
 */

const URL_SERVICIOS = new URL('../../servicios/', import.meta.url);
const TIEMPO_MAXIMO_MS = 15000;

/**
 * Error de una petición a la API. codigo es el código HTTP (0 si no hubo
 * conexión) y campos, los mensajes por campo de los errores de validación.
 */
export class ErrorApi extends Error {
    constructor(mensaje, codigo, campos = null) {
        super(mensaje);
        this.name = 'ErrorApi';
        this.codigo = codigo;
        this.campos = campos;
    }
}

/**
 * Hace la petición y devuelve data, o lanza ErrorApi.
 * ruta es relativa a servicios/ (por ejemplo "dispositivos/5").
 */
async function peticion(metodo, ruta, { cuerpo = null, consulta = null } = {}) {
    const url = new URL(ruta, URL_SERVICIOS);
    agregarConsulta(url, consulta);

    const cancelador = new AbortController();
    const temporizador = setTimeout(() => cancelador.abort(), TIEMPO_MAXIMO_MS);
    let respuesta;

    try {
        respuesta = await fetch(url, {
            method: metodo,
            headers: cuerpo === null
                ? { Accept: 'application/json' }
                : { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: cuerpo === null ? undefined : JSON.stringify(cuerpo),
            signal: cancelador.signal,
        });
    } catch (error) {
        throw error.name === 'AbortError'
            ? new ErrorApi('El servidor tardó demasiado en responder. Intenta de nuevo.', 0)
            : new ErrorApi('No se pudo conectar con el servidor. Revisa que Apache esté encendido.', 0);
    } finally {
        clearTimeout(temporizador);
    }

    return interpretarRespuesta(respuesta);
}

/**
 * Agrega los parámetros de consulta, omitiendo los vacíos.
 */
function agregarConsulta(url, consulta) {
    for (const [clave, valor] of Object.entries(consulta ?? {})) {
        if (valor !== null && valor !== undefined && valor !== '') {
            url.searchParams.set(clave, String(valor));
        }
    }
}

async function interpretarRespuesta(respuesta) {
    let json = null;

    try {
        json = await respuesta.json();
    } catch {
        json = null;
    }

    if (json === null || typeof json.ok !== 'boolean') {
        throw new ErrorApi('El servidor respondió algo inesperado. Intenta de nuevo en unos segundos.', respuesta.status);
    }

    if (!json.ok) {
        throw new ErrorApi(json.error || 'Ocurrió un error inesperado.', respuesta.status, json.campos ?? null);
    }

    return json.data;
}

export const api = {
    dispositivos: {
        listar: (filtros = {}) => peticion('GET', 'dispositivos', { consulta: filtros }),
        obtener: (id) => peticion('GET', `dispositivos/${id}`),
        crear: (datos) => peticion('POST', 'dispositivos', { cuerpo: datos }),
        actualizar: (id, datos) => peticion('PUT', `dispositivos/${id}`, { cuerpo: datos }),
        eliminar: (id) => peticion('DELETE', `dispositivos/${id}`),
    },
    alertas: {
        listar: (filtros = {}) => peticion('GET', 'alertas', { consulta: filtros }),
        conteo: () => peticion('GET', 'alertas/conteo'),
        atender: (id) => peticion('PATCH', `alertas/${id}/atender`),
    },
    monitoreo: {
        monitorear: (dispositivoId) => peticion('POST', `monitoreo/${dispositivoId}`),
        historial: (filtros = {}) => peticion('GET', 'monitoreo', { consulta: filtros }),
        recientes: (limite = 10) => peticion('GET', 'monitoreo/recientes', { consulta: { limite } }),
    },
};
