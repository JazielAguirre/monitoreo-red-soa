/*
 * Modal de agregar y editar dispositivo (Dispositivos, 2b y 2f del diseño).
 * - Los mensajes de validación vienen solo de la API ("campos"); el formulario usa
 *   novalidate para que el navegador no muestre sus propios globos.
 * - Un 409 (IP duplicada) también llega en campos.ip.
 * - Mientras se guarda, el botón queda deshabilitado (no se envía dos veces).
 * Espera en la página el marcado de #modal-dispositivo (ver dispositivos.html).
 */

import { api } from '../api.js';
import { elemento } from './piezas.js';
import { abrirModal, cerrarModal } from './modales.js';

const CAMPOS = ['nombre', 'ip', 'tipo', 'ubicacion'];

const TEXTOS = {
    agregar: {
        titulo: 'Agregar dispositivo',
        subtitulo: 'Registra un equipo de la red para monitorearlo.',
        guardar: 'Agregar dispositivo',
        guardando: 'Agregando…',
    },
    editar: {
        titulo: 'Editar dispositivo',
        subtitulo: 'Si cambias la IP, el dispositivo vuelve a «Sin monitorear».',
        guardar: 'Guardar cambios',
        guardando: 'Guardando…',
    },
};

let actual = null; // { modo, dispositivo, alGuardar }

function nodos() {
    const modal = document.getElementById('modal-dispositivo');

    return {
        modal,
        formulario: modal.querySelector('form'),
        titulo: modal.querySelector('.modal-formulario__titulo'),
        subtitulo: modal.querySelector('.modal-formulario__subtitulo'),
        guardar: modal.querySelector('[type="submit"]'),
        errorGeneral: modal.querySelector('.modal-formulario__error'),
    };
}

function campo(nombre) {
    const formulario = nodos().formulario;

    return nombre === 'tipo' ? formulario.querySelector('.selector-tipo') : formulario.elements[nombre];
}

function limpiarErrores() {
    const { formulario, errorGeneral } = nodos();

    for (const nombre of CAMPOS) {
        marcarError(nombre, null);
    }
    errorGeneral.hidden = true;
    errorGeneral.textContent = '';
    formulario.querySelectorAll('.campo-icono__error').forEach((i) => { i.hidden = true; });
}

/**
 * Muestra (o quita, con mensaje null) el error de un campo, debajo de él.
 */
function marcarError(nombre, mensaje) {
    const control = campo(nombre);
    const error = document.getElementById(`error-${nombre}`);
    const ayuda = document.getElementById(`ayuda-${nombre}`);

    control.classList.toggle('is-invalid', mensaje !== null);
    control.setAttribute('aria-invalid', mensaje !== null ? 'true' : 'false');
    error.textContent = mensaje ?? '';
    error.hidden = mensaje === null;

    if (ayuda) {
        ayuda.hidden = mensaje !== null;
    }

    const iconoError = control.parentElement.querySelector('.campo-icono__error');
    if (iconoError) {
        iconoError.hidden = mensaje === null;
    }
}

function leerDatos() {
    const { formulario } = nodos();

    return {
        nombre: formulario.elements.nombre.value,
        ip: formulario.elements.ip.value,
        tipo: formulario.elements.tipo.value,
        ubicacion: formulario.elements.ubicacion.value,
    };
}

function llenar(dispositivo) {
    const { formulario } = nodos();

    formulario.elements.nombre.value = dispositivo?.nombre ?? '';
    formulario.elements.ip.value = dispositivo?.ip ?? '';
    formulario.elements.ubicacion.value = dispositivo?.ubicacion ?? '';
    formulario.elements.tipo.value = dispositivo?.tipo ?? 'computadora';
}

function ponerGuardando(activo) {
    const { guardar } = nodos();
    const textos = TEXTOS[actual.modo];

    guardar.disabled = activo;
    guardar.replaceChildren();
    if (activo) {
        const girador = elemento('span', 'girador');
        girador.setAttribute('aria-hidden', 'true');
        guardar.append(girador);
        guardar.setAttribute('aria-busy', 'true');
    } else {
        guardar.removeAttribute('aria-busy');
    }
    guardar.append(activo ? textos.guardando : textos.guardar);
}

async function enviar(evento) {
    evento.preventDefault();

    const { guardar, modal, errorGeneral } = nodos();
    if (guardar.disabled) {
        return; // Ya hay un envío en curso.
    }

    limpiarErrores();
    ponerGuardando(true);

    try {
        const datos = leerDatos();
        const guardado = actual.modo === 'agregar'
            ? await api.dispositivos.crear(datos)
            : await api.dispositivos.actualizar(actual.dispositivo.id, datos);

        const alGuardar = actual.alGuardar;
        const modo = actual.modo;
        cerrarModal(modal);
        alGuardar?.(guardado, modo);
    } catch (error) {
        if (error.campos) {
            for (const [nombre, mensaje] of Object.entries(error.campos)) {
                if (CAMPOS.includes(nombre)) {
                    marcarError(nombre, mensaje);
                }
            }
            const primero = CAMPOS.find((nombre) => error.campos[nombre]);
            (primero === 'tipo' ? campo('tipo').querySelector('input') : campo(primero))?.focus();
        } else {
            errorGeneral.textContent = error.message;
            errorGeneral.hidden = false;
        }
    } finally {
        if (actual) {
            ponerGuardando(false);
        }
    }
}

let conectado = false;

function conectarUnaVez() {
    if (conectado) {
        return;
    }
    conectado = true;

    const { modal, formulario } = nodos();
    formulario.addEventListener('submit', enviar);

    // Al corregir un campo, su error desaparece.
    formulario.addEventListener('input', (evento) => {
        const nombre = evento.target.name;
        if (CAMPOS.includes(nombre)) {
            marcarError(nombre, null);
        }
    });

    modal.addEventListener('shown.bs.modal', () => formulario.elements.nombre.focus());
    modal.addEventListener('hidden.bs.modal', () => { actual = null; });
}

/**
 * Abre el modal. modo: "agregar" o "editar" (con dispositivo).
 * alGuardar(dispositivoGuardado, modo) se llama al guardar con éxito.
 */
export function abrirFormulario({ modo = 'agregar', dispositivo = null, alGuardar = null, origen = null, alternativa = null } = {}) {
    conectarUnaVez();

    const { modal, titulo, subtitulo } = nodos();
    const textos = TEXTOS[modo];

    actual = { modo, dispositivo, alGuardar };
    titulo.textContent = textos.titulo;
    subtitulo.textContent = textos.subtitulo;
    limpiarErrores();
    llenar(dispositivo);
    ponerGuardando(false);

    abrirModal(modal, { origen, alternativa });
}
