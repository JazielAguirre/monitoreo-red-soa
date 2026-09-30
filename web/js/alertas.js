/*
 * Pantalla Alertas (docs/diseno: 4a y 4b; API.md: "Qué usa cada pantalla").
 * - Pestañas Pendientes y Atendidas con sus contadores (GET /alertas/conteo).
 *   La pestaña activa se guarda en la URL (?pestana=atendidas).
 * - Lista de la pestaña: GET /alertas?atendida=0|1; nombre e IP desde GET /dispositivos.
 * - "Marcar como atendida" (PATCH /alertas/{id}/atender) actualiza la lista,
 *   los contadores de las pestañas y el del menú.
 */

import { api } from './api.js';
import { actualizarContadorAlertas, iniciarPagina } from './modulos/estructura.js';
import { formatearFechaHora } from './modulos/formatos.js';
import { mostrarNotificacion, notificarError } from './modulos/notificaciones.js';
import { crearError, crearVacio, mostrarBloque } from './modulos/vistas.js';
import { elemento, icono } from './modulos/piezas.js';
import { ponerBotonMonitoreando } from './modulos/monitorear-todos.js';

const LIMITE = 100; // máximo de GET /alertas
const PESTANAS = ['pendientes', 'atendidas'];

const $ = (id) => document.getElementById(id);
const lista = $('lista-alertas');
const esqueleto = [...lista.childNodes].map((nodo) => nodo.cloneNode(true));
const estado = {
    pestana: leerPestanaDeLaUrl(),
    dispositivos: null, // Map id → dispositivo; null si no se pudieron cargar
    conteo: null,
    alertas: [],
    peticion: 0, // para descartar respuestas que llegan tarde
};

await iniciarPagina(); // confirma la sesión antes de pedir datos
conectarPestanas();
cargar();

// ------------------------------------------------------------ Pestañas

function leerPestanaDeLaUrl() {
    const valor = new URLSearchParams(location.search).get('pestana');
    return PESTANAS.includes(valor) ? valor : 'pendientes';
}

function guardarPestanaEnLaUrl() {
    history.replaceState(null, '', estado.pestana === 'pendientes' ? location.pathname : `?pestana=${estado.pestana}`);
}

function botonPestana(pestana) {
    return $(`pestana-${pestana}`);
}

function mostrarPestanaActiva() {
    for (const pestana of PESTANAS) {
        const activa = pestana === estado.pestana;
        const boton = botonPestana(pestana);
        boton.classList.toggle('active', activa);
        boton.setAttribute('aria-selected', String(activa));
        boton.tabIndex = activa ? 0 : -1;
    }
    lista.setAttribute('aria-labelledby', botonPestana(estado.pestana).id);
}

function cambiarPestana(pestana, { enfocar = false } = {}) {
    if (enfocar) {
        botonPestana(pestana).focus();
    }
    if (pestana === estado.pestana) {
        return;
    }
    estado.pestana = pestana;
    cargar();
}

/**
 * Clic y teclado de un tablist (WAI-ARIA): flechas, Inicio y Fin cambian de pestaña.
 */
function conectarPestanas() {
    for (const pestana of PESTANAS) {
        botonPestana(pestana).addEventListener('click', () => cambiarPestana(pestana));
        botonPestana(pestana).addEventListener('keydown', (evento) => {
            const indice = PESTANAS.indexOf(pestana);
            const destino = {
                ArrowRight: PESTANAS[(indice + 1) % PESTANAS.length],
                ArrowLeft: PESTANAS[(indice - 1 + PESTANAS.length) % PESTANAS.length],
                Home: PESTANAS[0],
                End: PESTANAS[PESTANAS.length - 1],
            }[evento.key];

            if (destino) {
                evento.preventDefault();
                cambiarPestana(destino, { enfocar: true });
            }
        });
    }
}

function mostrarContadores() {
    for (const pestana of PESTANAS) {
        const contador = $(`contador-${pestana}`);
        const valor = estado.conteo?.[pestana] ?? null;

        contador.hidden = valor === null;
        contador.textContent = valor === null ? '' : String(valor);
        contador.className = `contador ${pestana === 'pendientes' && valor > 0 ? 'contador--suave' : 'contador--neutro'}`;
    }
}

// ------------------------------------------------------------ Carga

async function cargarDispositivos() {
    try {
        const dispositivos = await api.dispositivos.listar();
        estado.dispositivos = new Map(dispositivos.map((d) => [d.id, d]));
    } catch {
        estado.dispositivos = null; // la alerta se ve igual, con su mensaje
    }
}

async function cargarConteo() {
    try {
        estado.conteo = await api.alertas.conteo();
    } catch {
        estado.conteo = null;
    }
    mostrarContadores();
    actualizarContadorAlertas(estado.conteo?.pendientes ?? null);
}

async function cargar() {
    const numero = ++estado.peticion;

    mostrarPestanaActiva();
    guardarPestanaEnLaUrl();
    lista.replaceChildren(...esqueleto.map((nodo) => nodo.cloneNode(true)));
    lista.setAttribute('aria-busy', 'true');
    $('nota-limite').hidden = true;

    const alertas = api.alertas.listar({ atendida: estado.pestana === 'atendidas' ? 1 : 0, limite: LIMITE });
    const extras = Promise.all([estado.dispositivos === null ? cargarDispositivos() : null, cargarConteo()]);

    try {
        const [items] = await Promise.all([alertas, extras]);
        if (numero !== estado.peticion) {
            return; // llegó otra pestaña más reciente
        }
        estado.alertas = items;
        mostrarLista();
    } catch (error) {
        if (numero !== estado.peticion) {
            return;
        }
        mostrarBloque(lista, crearError({ titulo: 'No se pudieron cargar las alertas', mensaje: error.message, alReintentar: () => cargar(), compacto: true }));
    }
}

// ------------------------------------------------------------ Lista

function mostrarLista() {
    if (estado.alertas.length === 0) {
        mostrarBloque(lista, crearVacioDeLaPestana());
        return;
    }

    const ul = elemento('ul', 'lista-alertas-completa');
    ul.append(...estado.alertas.map(crearAlerta));
    lista.replaceChildren(ul);
    lista.removeAttribute('aria-busy');

    const total = estado.conteo?.[estado.pestana] ?? 0;
    $('nota-limite').textContent = `Se muestran las ${LIMITE} alertas más recientes de ${total}.`;
    $('nota-limite').hidden = total <= estado.alertas.length;
}

function crearVacioDeLaPestana() {
    if (estado.pestana === 'pendientes') {
        const hayAtendidas = (estado.conteo?.atendidas ?? 0) > 0;
        return crearVacio({
            icono: 'bi-check2-circle',
            variante: 'exito',
            titulo: 'Toda la red responde',
            texto: 'No hay alertas pendientes. Cuando un dispositivo deje de responder, aparecerá aquí.',
            accion: hayAtendidas ? { texto: 'Ver atendidas', alHacerClic: () => cambiarPestana('atendidas', { enfocar: true }) } : null,
            compacto: true,
        });
    }

    return crearVacio({
        icono: 'bi-inbox',
        titulo: 'Aún no hay alertas atendidas',
        texto: 'Las alertas que marques como atendidas se guardan aquí.',
        compacto: true,
    });
}

function crearAlerta(alerta) {
    const dispositivo = estado.dispositivos?.get(alerta.dispositivo_id) ?? null;
    const nombre = dispositivo?.nombre ?? `Dispositivo ${alerta.dispositivo_id}`;
    const item = elemento('li', `alerta${alerta.atendida ? ' alerta--atendida' : ''}`);
    item.dataset.id = String(alerta.id);

    const circulo = elemento('span', 'alerta__icono');
    circulo.append(icono('bi-wifi-off'));

    const cabecera = elemento('div', 'alerta__cabecera');
    cabecera.append(elemento('h2', 'alerta__nombre', nombre));
    if (dispositivo) {
        cabecera.append(elemento('span', 'alerta__ip', dispositivo.ip));
    }
    const tipo = elemento('span', 'insignia-estado insignia-estado--sin-respuesta');
    tipo.append(icono('bi-wifi-off'), 'Sin respuesta');
    cabecera.append(tipo);

    const fecha = elemento('span', 'alerta__fecha');
    fecha.append(icono('bi-clock'), elemento('span', 'mono', formatearFechaHora(alerta.fecha)));
    if (alerta.atendida && alerta.fecha_atendida) {
        fecha.append(elemento('span', 'alerta__atendida', `Atendida el ${formatearFechaHora(alerta.fecha_atendida)}`));
    }

    const cuerpo = elemento('div', 'alerta__cuerpo');
    cuerpo.append(cabecera, elemento('p', 'alerta__mensaje', alerta.mensaje), fecha);

    item.append(circulo, cuerpo, crearAcciones(alerta, nombre));
    return item;
}

function crearAcciones(alerta, nombre) {
    const acciones = elemento('div', 'alerta__acciones');

    if (alerta.atendida) {
        const insignia = elemento('span', 'insignia-estado insignia-estado--atendida');
        insignia.append(icono('bi-check2'), 'Atendida');
        acciones.append(insignia);
        return acciones;
    }

    const insignia = elemento('span', 'insignia-estado insignia-estado--pendiente');
    insignia.append(elemento('span', 'punto-estado punto-estado--alerta'), 'Pendiente');

    const boton = elemento('button', 'btn btn-secondary alerta__boton');
    boton.type = 'button';
    boton.setAttribute('aria-label', `Marcar como atendida la alerta de ${nombre}`);
    boton.append(icono('bi-check2'), 'Marcar como atendida');
    boton.addEventListener('click', () => atender(alerta, nombre, boton));

    acciones.append(insignia, boton);
    return acciones;
}

// ------------------------------------------------------------ Marcar como atendida

async function atender(alerta, nombre, boton) {
    if (boton.disabled) {
        return; // evita el doble clic
    }
    ponerBotonMonitoreando(boton, 'Marcando…');

    try {
        await api.alertas.atender(alerta.id);
    } catch (error) {
        if (error.codigo === 404) {
            // Ya no existe (por ejemplo, se eliminó su dispositivo): se quita de la lista.
            notificarError(error, 'No se pudo marcar la alerta');
            quitarDeLaLista(alerta.id);
            await cargarConteo();
            return;
        }
        ponerBotonMonitoreando(boton, null);
        notificarError(error, 'No se pudo marcar la alerta');
        return;
    }

    mostrarNotificacion({ tipo: 'exito', titulo: 'Alerta atendida', texto: `La alerta de ${nombre} pasó a Atendidas.` });
    quitarDeLaLista(alerta.id);
    await cargarConteo();
    if (estado.alertas.length === 0) {
        mostrarLista(); // el vacío ya conoce el conteo de atendidas ("Ver atendidas")
    }
}

/**
 * Quita la alerta de la lista y deja el foco en la siguiente (o en la pestaña si no quedan).
 */
function quitarDeLaLista(id) {
    const item = lista.querySelector(`.alerta[data-id="${id}"]`);
    const vecino = item?.nextElementSibling ?? item?.previousElementSibling ?? null;

    estado.alertas = estado.alertas.filter((a) => a.id !== id);
    item?.remove();

    if (estado.alertas.length === 0) {
        mostrarLista();
        botonPestana(estado.pestana).focus();
        return;
    }
    (vecino?.querySelector('.alerta__boton') ?? botonPestana(estado.pestana)).focus();
}
