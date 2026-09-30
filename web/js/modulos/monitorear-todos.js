/*
 * "Monitorear todos": comprueba los dispositivos uno por uno (POST /monitoreo/{id}),
 * muestra el progreso ("3 de 6", barra y una ficha por dispositivo) y al final
 * notifica el resumen. Lo usan el Dashboard y Dispositivos.
 *
 * Si un dispositivo no se puede comprobar (por ejemplo 502), su ficha queda como
 * fallida y el recorrido sigue con los demás.
 */

import { api } from '../api.js';
import { formatearMs, pluralizar } from './formatos.js';
import { mostrarNotificacion } from './notificaciones.js';
import { elemento, icono, crearPunto } from './piezas.js';

/**
 * Tarjeta de progreso dentro de `contenedor` (queda oculta hasta empezar).
 * Devuelve { mostrar(dispositivos), actualizar(indice, resultados), ocultar() }.
 */
export function crearProgreso(contenedor) {
    const tarjeta = elemento('div', 'progreso-monitoreo');
    const cabecera = elemento('div', 'progreso-monitoreo__cabecera');
    const actual = elemento('span', 'progreso-monitoreo__actual');
    const posicion = elemento('span', 'progreso-monitoreo__detalle');
    cabecera.append(actual, posicion);

    const barra = elemento('div', 'progreso-monitoreo__barra');
    barra.setAttribute('role', 'progressbar');
    barra.setAttribute('aria-label', 'Progreso de Monitorear todos');
    barra.setAttribute('aria-valuemin', '0');
    const relleno = elemento('div', 'progreso-monitoreo__relleno');
    barra.append(relleno);

    const fichas = elemento('div', 'progreso-monitoreo__fichas');
    // Texto para lectores de pantalla (aria-live): solo cambia una vez por dispositivo.
    const anuncio = elemento('p', 'visually-hidden');
    anuncio.setAttribute('aria-live', 'polite');

    tarjeta.append(cabecera, barra, fichas, anuncio);
    contenedor.replaceChildren(tarjeta);
    contenedor.hidden = true;

    let lista = [];

    function crearFicha(dispositivo, indice, estadoActual, resultados) {
        const resultado = resultados[indice];

        if (indice === estadoActual) {
            const ficha = elemento('span', 'ficha ficha--actual');
            ficha.append(crearPunto('monitoreando', true), dispositivo.nombre);
            return ficha;
        }

        if (resultado === undefined) {
            const ficha = elemento('span', 'ficha ficha--espera');
            ficha.append(icono('bi-circle'), dispositivo.nombre);
            return ficha;
        }

        if (resultado.error) {
            const ficha = elemento('span', 'ficha ficha--fallida');
            ficha.title = `No se pudo comprobar: ${resultado.error.message}`;
            ficha.append(icono('bi-exclamation-lg'), dispositivo.nombre);
            return ficha;
        }

        const activo = resultado.monitoreo.estado === 'activo';
        const ficha = elemento('span', `ficha ${activo ? 'ficha--hecha' : 'ficha--fallida'}`);
        ficha.append(icono(activo ? 'bi-check2' : 'bi-x-lg'), dispositivo.nombre);
        if (activo) {
            ficha.append(elemento('span', 'ficha__ms', formatearMs(resultado.monitoreo.tiempo_respuesta_ms)));
        }
        return ficha;
    }

    return {
        mostrar(dispositivos) {
            lista = dispositivos;
            barra.setAttribute('aria-valuemax', String(lista.length));
            contenedor.hidden = false;
        },

        /** indice: el que se está comprobando (o lista.length al terminar). */
        actualizar(indice, resultados) {
            const terminado = indice >= lista.length;
            const dispositivo = lista[Math.min(indice, lista.length - 1)];
            const hechos = Object.keys(resultados).length;

            actual.replaceChildren();
            if (!terminado) {
                actual.append(crearPunto('monitoreando'), `Comprobando ${dispositivo.nombre} `, elemento('span', 'progreso-monitoreo__detalle', dispositivo.ip));
            } else {
                actual.append(icono('bi-check2'), 'Comprobación terminada');
            }

            posicion.textContent = terminado ? `${lista.length} de ${lista.length}` : `${indice + 1} de ${lista.length}`;
            relleno.style.setProperty('--progreso', `${Math.round((hechos / lista.length) * 100)}%`);
            barra.setAttribute('aria-valuenow', String(hechos));
            barra.setAttribute('aria-valuetext', posicion.textContent);
            fichas.replaceChildren(...lista.map((d, i) => crearFicha(d, i, terminado ? -1 : indice, resultados)));
            anuncio.textContent = terminado ? 'Comprobación terminada' : `Comprobando ${dispositivo.nombre}, ${indice + 1} de ${lista.length}`;
        },

        ocultar() {
            contenedor.hidden = true;
        },
    };
}

/**
 * Comprueba los dispositivos uno por uno.
 * alComprobar(indice): antes de cada ping · alResultado(indice, resultado): después.
 * Devuelve { resultados, resumen }.
 */
export async function monitorearTodos(dispositivos, { alComprobar = () => {}, alResultado = () => {} } = {}) {
    const resultados = {};

    for (let i = 0; i < dispositivos.length; i++) {
        alComprobar(i, resultados);
        try {
            resultados[i] = await api.monitoreo.monitorear(dispositivos[i].id);
        } catch (error) {
            resultados[i] = { error };
        }
        alResultado(i, resultados);
    }

    return { resultados, resumen: resumir(dispositivos, resultados) };
}

function resumir(dispositivos, resultados) {
    const resumen = { total: dispositivos.length, activos: 0, inactivos: 0, fallidos: [], alertasNuevas: [] };

    dispositivos.forEach((dispositivo, i) => {
        const resultado = resultados[i];
        if (resultado.error) {
            resumen.fallidos.push({ dispositivo, error: resultado.error });
        } else if (resultado.monitoreo.estado === 'activo') {
            resumen.activos++;
        } else {
            resumen.inactivos++;
            if (resultado.alerta_creada) {
                resumen.alertasNuevas.push(dispositivo);
            }
        }
    });

    return resumen;
}

/**
 * Notifica el resumen y, si hubo alertas nuevas, una notificación con enlace a Alertas
 * (una sola aunque sean varias, para no llenar la pantalla).
 */
export function notificarResumen(resumen) {
    const comprobados = resumen.total - resumen.fallidos.length;
    let texto = `${resumen.activos} de ${resumen.total} dispositivos responden.`;

    if (resumen.fallidos.length > 0) {
        texto += ` ${pluralizar(resumen.fallidos.length, 'no se pudo comprobar', 'no se pudieron comprobar')}.`;
    }

    mostrarNotificacion({
        tipo: resumen.fallidos.length > 0 && comprobados === 0 ? 'error' : 'exito',
        titulo: 'Monitoreo completado',
        texto,
    });

    if (resumen.alertasNuevas.length === 1) {
        mostrarNotificacion({
            tipo: 'error',
            icono: 'bi-wifi-off',
            titulo: `${resumen.alertasNuevas[0].nombre} no respondió`,
            texto: 'Se generó una alerta pendiente.',
            enlace: { texto: 'Ver alerta', href: 'alertas.html' },
        });
    } else if (resumen.alertasNuevas.length > 1) {
        mostrarNotificacion({
            tipo: 'error',
            icono: 'bi-wifi-off',
            titulo: `${resumen.alertasNuevas.length} dispositivos no respondieron`,
            texto: `Se generaron ${resumen.alertasNuevas.length} alertas pendientes.`,
            enlace: { texto: 'Ver alertas', href: 'alertas.html' },
        });
    }
}

/**
 * Pone un botón en estado "Monitoreando 3 de 6…" (o lo restaura).
 */
export function ponerBotonMonitoreando(boton, texto, claseGirador = 'girador girador--oscuro') {
    if (!boton.dataset.contenidoOriginal) {
        boton.dataset.contenidoOriginal = '1';
        boton._contenidoOriginal = [...boton.childNodes].map((n) => n.cloneNode(true));
    }

    if (texto === null) {
        boton.replaceChildren(...boton._contenidoOriginal.map((n) => n.cloneNode(true)));
        boton.disabled = false;
        boton.removeAttribute('aria-busy');
        return;
    }

    const girador = elemento('span', claseGirador);
    girador.setAttribute('aria-hidden', 'true');
    boton.replaceChildren(girador, texto);
    boton.disabled = true;
    boton.setAttribute('aria-busy', 'true');
}
