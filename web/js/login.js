/*
 * Pantalla de inicio de sesión (docs/diseno: 5a y 5b; API.md: "Autenticación").
 * - Si ya hay sesión, lleva directo a la pantalla de "volver" (o al Dashboard).
 * - Los mensajes vienen de la API: 401 → aviso arriba y los dos campos en rojo;
 *   400 → el mensaje de cada campo debajo de él.
 * - Después del login vuelve a "volver" solo si es una pantalla de web/ (sesion.js).
 */

import { api } from './api.js';
import { destinoSeguro } from './modulos/sesion.js';

const PAUSA_ANTES_DE_ENTRAR_MS = 500; // deja leer "Sesión iniciada…"

const $ = (id) => document.getElementById(id);
const formulario = $('formulario-login');
const boton = $('entrar');
const campos = { usuario: $('usuario'), contrasena: $('contrasena') };
const destino = destinoSeguro(new URLSearchParams(location.search).get('volver'));
let enviando = false;

conectar();
irSiYaHaySesion();

function conectar() {
    formulario.addEventListener('submit', (evento) => {
        evento.preventDefault();
        entrar();
    });
    for (const campo of Object.values(campos)) {
        campo.addEventListener('input', limpiarErrores);
    }
    $('mostrar-contrasena').addEventListener('click', alternarContrasena);
}

async function irSiYaHaySesion() {
    try {
        await api.auth.sesion();
        location.replace(destino);
    } catch {
        // Sin sesión (401) o sin conexión: se queda en el formulario.
    }
}

// ------------------------------------------------------------ Envío

async function entrar() {
    if (enviando) {
        return; // evita el doble envío
    }
    limpiarErrores();
    ponerEnviando(true);

    try {
        await api.auth.login({ usuario: campos.usuario.value, contrasena: campos.contrasena.value });
    } catch (error) {
        ponerEnviando(false);
        mostrarError(error);
        return;
    }

    mostrarAviso('exito', destino.endsWith('/index.html')
        ? 'Sesión iniciada. Abriendo el dashboard…'
        : 'Sesión iniciada. Volviendo a la pantalla anterior…');
    setTimeout(() => location.replace(destino), PAUSA_ANTES_DE_ENTRAR_MS);
}

function ponerEnviando(activo) {
    enviando = activo;
    boton.disabled = activo;
    boton.setAttribute('aria-busy', String(activo));

    if (activo) {
        const girador = document.createElement('span');
        girador.className = 'girador';
        girador.setAttribute('aria-hidden', 'true');
        boton.replaceChildren(girador, 'Entrando…');
    } else {
        boton.replaceChildren('Iniciar sesión');
    }
}

// ------------------------------------------------------------ Errores y avisos

function mostrarError(error) {
    if (error.campos) {
        for (const [nombre, mensaje] of Object.entries(error.campos)) {
            marcarCampo(nombre, mensaje);
        }
        campos[Object.keys(error.campos)[0]]?.focus();
        return;
    }

    mostrarAviso('error', error.message);
    if (error.codigo === 401) {
        // Credenciales incorrectas: los dos campos en rojo (5b), sin decir cuál falló.
        marcarCampo('usuario', null);
        marcarCampo('contrasena', null);
        campos.contrasena.focus();
        campos.contrasena.select();
    }
}

/**
 * Pone el campo en rojo; con mensaje, lo muestra debajo.
 */
function marcarCampo(nombre, mensaje) {
    const campo = campos[nombre];
    const error = $(`error-${nombre}`);

    if (!campo) {
        return;
    }
    campo.classList.add('is-invalid');
    campo.setAttribute('aria-invalid', 'true');
    error.textContent = mensaje ?? '';
    error.hidden = !mensaje;
}

function limpiarErrores() {
    for (const [nombre, campo] of Object.entries(campos)) {
        campo.classList.remove('is-invalid');
        campo.removeAttribute('aria-invalid');
        $(`error-${nombre}`).hidden = true;
    }
    $('aviso-error').hidden = true;
}

function mostrarAviso(tipo, texto) {
    $(`aviso-${tipo}-texto`).textContent = texto;
    $(`aviso-${tipo}`).hidden = false;
}

// ------------------------------------------------------------ Mostrar u ocultar la contraseña

function alternarContrasena() {
    const mostrar = campos.contrasena.type === 'password';
    const alternador = $('mostrar-contrasena');

    // Botón de alternar: la etiqueta ("Mostrar contraseña") no cambia; aria-pressed dice si está activo.
    campos.contrasena.type = mostrar ? 'text' : 'password';
    alternador.setAttribute('aria-pressed', String(mostrar));
    alternador.title = mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña';
    alternador.firstElementChild.className = `bi ${mostrar ? 'bi-eye-slash' : 'bi-eye'}`;
}
