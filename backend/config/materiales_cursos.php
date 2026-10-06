<?php

// ==========================================
// Cursos/áreas para "PCA / Unidades / Sesiones" del Dashboard del
// Profesor, SEPARADOS POR NIVEL EDUCATIVO.
//
// Estructura de cada módulo (distinta entre sí, a propósito):
//   PCA       -> 1 archivo por curso/grado (solo U1, sin Sem-0X).
//   Unidades  -> 1 archivo por curso/grado (lista plana, sin U1..U10 ni Sem-0X).
//   Sesiones  -> 1 archivo por curso/grado/Unidad(U1..U10)/Sem-0X.
//
// Cada entrada fue verificada contra la tabla real `cursos` de la
// BD (colegio_ie88044): la clave de la izquierda es el identificador
// corto y estable que se guarda en profesor_unidad_archivos.curso /
// profesor_sesion_archivos.curso; el valor es el nombre visible
// (se muestran algunos nombres abreviados a pedido, pero cada uno
// corresponde 1 a 1 a un curso real de esa `nivel` en la BD, nunca
// a un curso inventado). El comentario de cada línea indica el
// id_curso real y el nombre completo tal como está en `cursos`.
//
// PRIMARIA: la tabla `cursos` tiene 9 cursos para este nivel
// (Matemática, Comunicación, Ciencia y Tecnología, Personal Social,
// Inglés, Educación Física, Arte y Cultura, Computación, y ahora
// Educación Religiosa, id_curso 19, código PRI-REL, agregada y
// confirmada en la BD). De esos 9, aquí solo se listan las 7 que
// corresponden a las áreas pedidas — "Inglés" y "Computación" quedan
// fuera porque no fueron pedidas. Primaria = 7/7 cursos confirmados.
// ==========================================

const MATERIALES_CURSOS_PRIMARIA = [
    "arte"               => "Arte y Cultura",       // id_curso 7  · Arte y Cultura (PRIMARIA)
    "ciencia_tecnologia" => "Ciencia y Tecnología",  // id_curso 3  · Ciencia y Tecnología (PRIMARIA)
    "comunicacion"       => "Comunicación",          // id_curso 2  · Comunicación (PRIMARIA)
    "ed_fisica"          => "Educación Física",      // id_curso 6  · Educación Física (PRIMARIA)
    "ed_religiosa"       => "Educación Religiosa",   // id_curso 19 · Educación Religiosa (PRIMARIA)
    "matematica"         => "Matemática",            // id_curso 1  · Matemática (PRIMARIA)
    "personal_social"    => "Personal Social",       // id_curso 4  · Personal Social (PRIMARIA)
];

// SECUNDARIA: la tabla `cursos` tiene exactamente 10 cursos para
// este nivel y los 10 corresponden 1 a 1 a las 10 áreas pedidas
// (3 con nombre abreviado a pedido: Arte, CC.SS y Religión).
const MATERIALES_CURSOS_SECUNDARIA = [
    "arte"               => "Arte",                  // id_curso 16 · Arte y Cultura (SECUNDARIA)
    "ccss"               => "CC.SS",                 // id_curso 12 · Ciencias Sociales (SECUNDARIA)
    "ciencia_tecnologia" => "Ciencia y Tecnología",   // id_curso 11 · Ciencia y Tecnología (SECUNDARIA)
    "comunicacion"       => "Comunicación",           // id_curso 10 · Comunicación (SECUNDARIA)
    "dpcc"               => "DPCC",                   // id_curso 13 · DPCC (SECUNDARIA)
    "ed_fisica"          => "Educación Física",       // id_curso 15 · Educación Física (SECUNDARIA)
    "ept"                => "EPT",                    // id_curso 14 · EPT (SECUNDARIA)
    "ingles"             => "Inglés",                 // id_curso 18 · Inglés (SECUNDARIA)
    "matematica"         => "Matemática",             // id_curso 9  · Matemática (SECUNDARIA)
    "religion"           => "Religión",               // id_curso 17 · Educación Religiosa (SECUNDARIA)
];

// Los totales de Unidades y Sesiones YA NO son constantes fijas:
// se leen de la tabla `configuracion` (Admin > Configuración), por
// nivel educativo, con estos valores de fábrica si todavía no se
// guardó nada. Ver migracion_admin_mejoras.sql.
const MATERIALES_TOTAL_UNIDADES_DEFECTO = 10;
const MATERIALES_TOTAL_SEMANAS_DEFECTO = 5;

// ==========================================
// PCA — SOLO U1 (Primaria y Secundaria).
//
// "Unidades" y "Sesiones" sí usan la escala U1..U-N configurable de
// materiales_total_unidades(); PCA es la excepción: un solo archivo
// por curso/grado, sin subdividir por unidad. Ver pca_total_unidades()
// más abajo, que es la única función que debe usarse desde
// pca.php/dashboard.php/pca_pdf.php para este total — nunca repetir
// el número "1" suelto en cada archivo.
// ==========================================

/**
 * Total de "Unidades" para "Unidades"/"Sesiones" (no para PCA, ver
 * pca_total_unidades()) para un nivel educativo dado. Configurable
 * por Admin (Admin > Configuración), separado por nivel — antes era
 * un número fijo (10) igual para ambos niveles.
 *
 * `global $conexion` en vez de recibirlo por parámetro a propósito:
 * así ninguno de los ~15 lugares que ya llamaban
 * materiales_total_unidades($nivel) en todo el proyecto tuvo que
 * cambiar su firma. $conexion siempre existe en el scope global de
 * la página para cuando esta función se llama (se define en
 * database.php, requerido antes en todas partes). Con caché estática
 * para no repetir la consulta dentro del mismo request.
 */
function materiales_total_unidades(string $nivel): int {

    static $cache = [];

    if (isset($cache[$nivel])) {
        return $cache[$nivel];
    }

    global $conexion;

    $clave = $nivel === "SECUNDARIA" ? "total_unidades_secundaria" : "total_unidades_primaria";
    $valor = configuracion_obtener_entero($conexion, $clave, MATERIALES_TOTAL_UNIDADES_DEFECTO);

    $cache[$nivel] = $valor;

    return $valor;
}

/**
 * Total de "Semanas"/"Sesiones" por Unidad, para un nivel educativo
 * dado. Antes era la constante fija MATERIALES_TOTAL_SEMANAS (5,
 * igual para ambos niveles) — mismo criterio de configuración y
 * caché que materiales_total_unidades().
 */
function materiales_total_semanas(string $nivel): int {

    static $cache = [];

    if (isset($cache[$nivel])) {
        return $cache[$nivel];
    }

    global $conexion;

    $clave = $nivel === "SECUNDARIA" ? "total_semanas_secundaria" : "total_semanas_primaria";
    $valor = configuracion_obtener_entero($conexion, $clave, MATERIALES_TOTAL_SEMANAS_DEFECTO);

    $cache[$nivel] = $valor;

    return $valor;
}

/**
 * Lee una clave numérica de `configuracion` con un valor de
 * respaldo si la fila todavía no existe o no es un número válido.
 * Centraliza el acceso para que Unidades/Sesiones nunca queden
 * desincronizados si en el futuro se agrega otra clave numérica.
 */
function configuracion_obtener_entero(PDO $conexion, string $clave, int $porDefecto): int {

    $stmt = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = ?");
    $stmt->execute([$clave]);
    $valor = $stmt->fetchColumn();

    if ($valor === false || !ctype_digit((string) $valor) || (int) $valor < 1) {
        return $porDefecto;
    }

    return (int) $valor;
}

/**
 * Total de CURSOS de un nivel (7 en Primaria, 10 en Secundaria) —
 * este es el total que debe usarse para el denominador de
 * "Unidades" (1 archivo por curso). NO confundir con
 * materiales_total_unidades(), que es la escala pedagógica U1..U10
 * y vale 10 para ambos niveles.
 */
function materiales_total_cursos(string $nivel): int {
    return count(materiales_cursos_por_nivel($nivel));
}

/**
 * Total de unidades del PCA para un nivel educativo dado: siempre 1
 * (solo U1), en Primaria y en Secundaria — un único archivo por
 * curso/grado, independiente de materiales_total_unidades().
 */
function pca_total_unidades(string $nivel): int {
    return 1;
}


/**
 * Cursos/áreas visibles para un nivel educativo dado, en el orden
 * alfabético exacto en que deben mostrarse (el orden de declaración
 * de las constantes de arriba ya es alfabético por nombre visible).
 * Nunca mezcla PRIMARIA y SECUNDARIA.
 *
 * @return array<string,string> clave => nombre visible
 */
function materiales_cursos_por_nivel(string $nivel): array {
    return match ($nivel) {
        "PRIMARIA" => MATERIALES_CURSOS_PRIMARIA,
        "SECUNDARIA" => MATERIALES_CURSOS_SECUNDARIA,
        default => [],
    };
}

/**
 * Nombre visible de una clave de curso, validado SIEMPRE contra el
 * nivel educativo del grado en el que se está trabajando (nunca
 * contra la lista completa): una clave válida en SECUNDARIA (p.ej.
 * "ept") debe rechazarse si el grado actual es de PRIMARIA, y
 * viceversa. Devuelve null si la clave no existe para ese nivel.
 */
function materiales_curso_nombre(string $clave, string $nivel): ?string {
    return materiales_cursos_por_nivel($nivel)[$clave] ?? null;
}

function materiales_semana_label(int $semana): string {
    return "Semana " . str_pad((string) $semana, 2, "0", STR_PAD_LEFT);
}

function materiales_sem_label(int $semana): string {
    return "Sem-" . str_pad((string) $semana, 2, "0", STR_PAD_LEFT);
}

/**
 * Igual que materiales_semana_label(), pero adaptado al nivel
 * educativo: en SECUNDARIA la etiqueta larga dice "Sesión 0X" (el
 * módulo se llama "Sesiones", no "Semanas"); en PRIMARIA se deja
 * igual que antes ("Semana 0X"), sin tocar su comportamiento.
 */
function materiales_semana_label_nivel(string $nivel, int $semana): string {
    $numero = str_pad((string) $semana, 2, "0", STR_PAD_LEFT);
    if ($nivel === "SECUNDARIA") {
        return "Sesión " . $numero;
    }
    return "Semana " . $numero;
}

/**
 * Igual que materiales_sem_label(), pero adaptado al nivel educativo:
 * en SECUNDARIA la etiqueta corta dice "Ses-0X"; en PRIMARIA se deja
 * igual que antes ("Sem-0X").
 */
function materiales_sem_label_nivel(string $nivel, int $semana): string {
    $numero = str_pad((string) $semana, 2, "0", STR_PAD_LEFT);
    if ($nivel === "SECUNDARIA") {
        return "Ses-" . $numero;
    }
    return "Sem-" . $numero;
}

/**
 * Clase CSS de "semáforo" (ver .progress-bar-fill.is-* en
 * css/dashboard.css) según el % de avance de una barra de progreso
 * (PCA, Unidades o Sesiones). Mismo criterio en todos lados donde
 * se muestre una barra de avance, para no duplicar umbrales:
 * rojo  < 40%, ámbar 40-79%, verde >= 80%.
 */
function materiales_color_progreso(int $porcentaje): string {
    if ($porcentaje >= 80) {
        return "is-green";
    }
    if ($porcentaje >= 40) {
        return "is-amber";
    }
    return "is-red";
}
