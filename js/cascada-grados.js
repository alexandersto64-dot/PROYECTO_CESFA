/*
 * Selección en cascada Nivel -> Grado -> Sección.
 * Uso: <div data-cascada-grados data-opciones='[{"id":1,"nivel":"PRIMARIA","grado":1,"seccion":"A"}, ...]'
 *           data-name="id_grado_seccion" data-valor="3"></div>
 * Los grados y secciones que aparecen son SOLO los que existen en la
 * tabla grados_secciones (los pasa el servidor en data-opciones).
 * Deja un <input type="hidden" name="..."> con el id final; el
 * servidor sigue validando ese id (esto es solo comodidad de UI).
 *
 * Opciones (todas opcionales, sin ellas se comporta igual que siempre):
 *   data-nivel-fijo="SECUNDARIA"  el nivel ya está decidido: no se muestra el
 *                                 selector de Nivel y solo se ofrecen las aulas
 *                                 de ese nivel (ej. profesor de Secundaria).
 *   data-compacto                 una sola fila, sin etiquetas (para tarjetas
 *                                 y listas donde no cabe el formulario grande).
 */
(function () {
    var ORD = { 1: "1ro", 2: "2do", 3: "3ro", 4: "4to", 5: "5to", 6: "6to" };

    function opt(valor, texto, sel) {
        var o = document.createElement("option");
        o.value = valor; o.textContent = texto;
        if (sel) { o.selected = true; }
        return o;
    }

    function campo(id, etiqueta, select, compacto) {
        if (compacto) {
            select.setAttribute("aria-label", etiqueta);
            return select;
        }
        var d = document.createElement("div");
        d.className = "field";
        var l = document.createElement("label");
        l.setAttribute("for", id); l.textContent = etiqueta;
        d.appendChild(l); d.appendChild(select);
        return d;
    }

    var estiloInyectado = false;
    function inyectarEstilo() {
        if (estiloInyectado) { return; }
        estiloInyectado = true;
        var st = document.createElement("style");
        st.textContent =
            ".cascada-compacta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0}" +
            ".cascada-compacta select{font-size:.85rem;width:auto;min-width:0;padding:6px 8px}";
        document.head.appendChild(st);
    }

    function iniciar(raiz) {
        var datos = JSON.parse(raiz.getAttribute("data-opciones") || "[]");
        var nombre = raiz.getAttribute("data-name") || "id_grado_seccion";
        var valorInicial = parseInt(raiz.getAttribute("data-valor") || "0", 10);
        var nivelFijo = (raiz.getAttribute("data-nivel-fijo") || "").toUpperCase();
        var compacto = raiz.hasAttribute("data-compacto");
        if (nivelFijo) {
            datos = datos.filter(function (d) { return d.nivel === nivelFijo; });
        }
        var base = "cg_" + Math.random().toString(36).slice(2, 7);

        var actual = datos.filter(function (d) { return d.id === valorInicial; })[0];

        var oculto = document.createElement("input");
        oculto.type = "hidden"; oculto.name = nombre; oculto.value = valorInicial || "";

        var sNivel = document.createElement("select"); sNivel.id = base + "_n"; sNivel.required = true;
        var sGrado = document.createElement("select"); sGrado.id = base + "_g"; sGrado.required = true;
        var sSec = document.createElement("select"); sSec.id = base + "_s"; sSec.required = true;

        function unicos(lista) {
            return lista.filter(function (v, i, a) { return a.indexOf(v) === i; });
        }

        function llenarNiveles() {
            sNivel.innerHTML = "";
            sNivel.appendChild(opt("", "Selecciona el nivel…"));
            unicos(datos.map(function (d) { return d.nivel; })).forEach(function (n) {
                sNivel.appendChild(opt(n, n.charAt(0) + n.slice(1).toLowerCase(), actual && actual.nivel === n));
            });
        }

        function llenarGrados() {
            sGrado.innerHTML = "";
            sGrado.appendChild(opt("", sNivel.value ? (compacto ? "Grado…" : "Selecciona el grado…") : "Primero elige el nivel"));
            sGrado.disabled = !sNivel.value;
            if (!sNivel.value) { return; }
            var grados = unicos(datos.filter(function (d) { return d.nivel === sNivel.value; })
                .map(function (d) { return d.grado; })).sort(function (a, b) { return a - b; });
            grados.forEach(function (g) {
                sGrado.appendChild(opt(String(g), ORD[g] || (g + "°"), actual && actual.nivel === sNivel.value && actual.grado === g));
            });
        }

        function llenarSecciones() {
            sSec.innerHTML = "";
            sSec.appendChild(opt("", sGrado.value ? (compacto ? "Sección…" : "Selecciona la sección…") : (compacto ? "Sección…" : "Primero elige el grado")));
            sSec.disabled = !sGrado.value;
            if (!sGrado.value) { return; }
            datos.filter(function (d) { return d.nivel === sNivel.value && String(d.grado) === sGrado.value; })
                .sort(function (a, b) { return a.seccion < b.seccion ? -1 : 1; })
                .forEach(function (d) {
                    sSec.appendChild(opt(String(d.id), "Sección " + d.seccion, d.id === valorInicial));
                });
        }

        sNivel.addEventListener("change", function () { oculto.value = ""; actual = null; llenarGrados(); llenarSecciones(); });
        sGrado.addEventListener("change", function () { oculto.value = ""; actual = null; llenarSecciones(); });
        sSec.addEventListener("change", function () { oculto.value = sSec.value; });

        llenarNiveles();
        if (nivelFijo) { sNivel.value = nivelFijo; sNivel.required = false; }
        llenarGrados(); llenarSecciones();

        if (compacto) {
            inyectarEstilo();
            raiz.className = (raiz.className + " cascada-compacta").trim();
        } else {
            raiz.className = (raiz.className + " form-row").trim();
        }
        if (!nivelFijo) { raiz.appendChild(campo(sNivel.id, "Nivel", sNivel, compacto)); }
        raiz.appendChild(campo(sGrado.id, "Grado", sGrado, compacto));
        raiz.appendChild(campo(sSec.id, "Sección", sSec, compacto));
        raiz.appendChild(oculto);
    }

    document.addEventListener("DOMContentLoaded", function () {
        Array.prototype.forEach.call(document.querySelectorAll("[data-cascada-grados]"), iniciar);
    });
})();
