/* ============================================================
   AVISO DE COOKIES — I.E.P. 88044
   - Guarda la decisión en localStorage ("ie88044_cookies").
   - Las cookies NECESARIAS (sesión del intranet) siempre están
     activas; las OPCIONALES (analítica/terceros) solo si el
     usuario las acepta. Hoy el sitio no usa opcionales, pero la
     API queda lista:  IECookies.permitido('opcionales')
   - Para volver a abrir el aviso: IECookies.abrir() o cualquier
     elemento con el atributo  data-cookies-config.
============================================================ */
(function () {
  'use strict';

  var CLAVE = 'ie88044_cookies';
  var VERSION = 1;               // sube el número para volver a pedir consentimiento
  var VIGENCIA_MS = 180 * 24 * 60 * 60 * 1000; // 6 meses

  // Ruta base (funciona también desde /secundaria/, /primaria/, etc.)
  var script = document.currentScript;
  var base = script && script.src ? script.src.replace(/js\/cookies\.js.*$/, '') : '';

  function leer() {
    try {
      var d = JSON.parse(localStorage.getItem(CLAVE));
      if (d && d.v === VERSION && d.t && (Date.now() - d.t) < VIGENCIA_MS) return d;
    } catch (e) { /* sin acceso a storage: se vuelve a preguntar */ }
    return null;
  }

  function guardar(opcionales) {
    try {
      localStorage.setItem(CLAVE, JSON.stringify({ v: VERSION, t: Date.now(), opcionales: !!opcionales }));
    } catch (e) { /* ignorado */ }
    document.dispatchEvent(new CustomEvent('cookies:cambio', { detail: { opcionales: !!opcionales } }));
  }

  function cargarCss() {
    if (document.getElementById('ck-css') || !base) return;
    var l = document.createElement('link');
    l.id = 'ck-css'; l.rel = 'stylesheet'; l.href = base + 'css/cookies.css';
    document.head.appendChild(l);
  }

  var banner = null;

  function cerrar(opcionales) {
    guardar(opcionales);
    if (!banner) return;
    banner.classList.add('ck-sale');
    var b = banner; banner = null;
    setTimeout(function () { b.remove(); }, 300);
  }

  function abrir() {
    if (banner) { banner.querySelector('.ck-btn-primary').focus(); return; }
    cargarCss();
    banner = document.createElement('div');
    banner.className = 'ck-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-live', 'polite');
    banner.setAttribute('aria-labelledby', 'ck-title');
    banner.innerHTML =
      '<div class="ck-head">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
          '<path d="M12 3a9 9 0 1 0 9 9 4 4 0 0 1-4-4 4 4 0 0 1-4-4 1 1 0 0 0-1-1Z"/>' +
          '<path d="M8.5 11.5h.01"/><path d="M12 16h.01"/><path d="M15.5 13h.01"/><path d="M8 15.5h.01"/>' +
        '</svg>' +
        '<h2 class="ck-title" id="ck-title">Usamos cookies</h2>' +
      '</div>' +
      '<p class="ck-text">Usamos cookies necesarias para que el intranet y tu sesión funcionen. ' +
      'Las opcionales (mejora del sitio y contenido de terceros) solo se activan si las aceptas. ' +
      'Más información en nuestra <a href="' + base + 'cookies.html">Política de cookies</a>.</p>' +
      '<div class="ck-actions">' +
        '<button type="button" class="ck-btn ck-btn-ghost" data-ck="necesarias">Solo necesarias</button>' +
        '<button type="button" class="ck-btn ck-btn-primary" data-ck="todas">Aceptar todas</button>' +
      '</div>';
    document.body.appendChild(banner);
    banner.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ck]');
      if (!b) return;
      cerrar(b.getAttribute('data-ck') === 'todas');
    });
    banner.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') cerrar(false);
    });
  }

  window.IECookies = {
    abrir: abrir,
    // 'necesarias' siempre true; 'opcionales' solo con consentimiento
    permitido: function (tipo) {
      if (tipo === 'necesarias') return true;
      var d = leer();
      return !!(d && d.opcionales);
    }
  };

  // Contenido de terceros (p. ej. Google Maps): solo se carga con consentimiento
  function actualizarTerceros() {
    var ok = window.IECookies.permitido('opcionales');
    document.querySelectorAll('iframe[data-ck-src]').forEach(function (f) {
      if (ok && !f.getAttribute('src')) f.setAttribute('src', f.getAttribute('data-ck-src'));
      if (!ok && f.getAttribute('src')) f.removeAttribute('src');
    });
  }

  function iniciar() {
    cargarCss();
    actualizarTerceros();
    document.addEventListener('cookies:cambio', actualizarTerceros);
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-cookies-config]');
      if (t) { e.preventDefault(); abrir(); }
    });
    if (!leer()) abrir();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
