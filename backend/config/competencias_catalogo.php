<?php

// ==========================================================
// Catálogo de Áreas Curriculares → Competencias, extraído
// directamente de las 3 boletas reales que usa el colegio
// (formato oficial MINEDU "Informe de Progreso del Aprendizaje",
// CNEB): Primaria.docx, Secundaria1ro.docx, Secundaria-2-5.docx.
//
// Esto reemplaza el supuesto anterior de "no sabemos los nombres
// de las competencias" — sí los sabemos, son el currículo nacional
// y son iguales para cualquier colegio que use este formato.
//
// AREA_A_CURSO traduce el nombre del "ÁREA CURRICULAR" tal como
// aparece impreso en el PDF (ej. "Desarrollo Personal, Ciudadanía y
// Cívica") al nombre del curso tal como está guardado en la tabla
// `cursos` de este colegio (ej. "DPCC") — la mayoría de nombres
// coinciden literalmente entre el PDF y `cursos` (Comunicación,
// Matemática, Ciencia y Tecnología, Educación Física, Inglés,
// Ciencias Sociales, Arte y Cultura, Educación Religiosa); solo DPCC
// y EPT están abreviados en `cursos`.
//
// Dos áreas del PDF NO se traducen a ningún curso a propósito:
//   - "Castellano como segunda lengua": solo aplica a colegios EIB
//     (Educación Intercultural Bilingüe); este colegio no lo es.
//   - "Competencias Transversales": son transversales a todas las
//     áreas, no tienen curso propio ni "CALIFICATIVO DE AREA" en el
//     boletín — no hay dónde colgarlas en el modelo de datos actual.
// Si esto cambia, hay que agregar el curso correspondiente primero.
// ==========================================================

const AREA_A_CURSO = [

    "SECUNDARIA" => [
        "ARTE Y CULTURA" => "Arte y Cultura",
        "CIENCIAS SOCIALES" => "Ciencias Sociales",
        "CIENCIA Y TECNOLOGIA" => "Ciencia y Tecnología",
        "COMUNICACION" => "Comunicación",
        "DESARROLLO PERSONAL, CIUDADANIA Y CIVICA" => "DPCC",
        "EDUCACION FISICA" => "Educación Física",
        "EDUCACION PARA EL TRABAJO" => "EPT",
        "INGLES COMO LENGUA EXTRANJERA" => "Inglés",
        "MATEMATICA" => "Matemática",
        "EDUCACION RELIGIOSA" => "Educación Religiosa",
    ],

    "PRIMARIA" => [
        "PERSONAL SOCIAL" => "Personal Social",
        "EDUCACION FISICA" => "Educación Física",
        "COMUNICACION" => "Comunicación",
        "ARTE Y CULTURA" => "Arte y Cultura",
        "MATEMATICA" => "Matemática",
        "CIENCIA Y TECNOLOGIA" => "Ciencia y Tecnología",
        "EDUCACION RELIGIOSA" => "Educación Religiosa",
    ],

];

/**
 * Competencias oficiales por curso, en el orden exacto en que
 * aparecen en la boleta (mismo orden usado para `orden` al sembrar
 * `competencias`, y para anclar el parseo del texto extraído).
 */
const COMPETENCIAS_POR_CURSO = [

    "SECUNDARIA" => [
        "Arte y Cultura" => [
            "Aprecia de manera crítica manifestaciones artístico-culturales.",
            "Crea proyectos desde los lenguajes artísticos.",
        ],
        "Ciencias Sociales" => [
            "Construye interpretaciones históricas.",
            "Gestiona responsablemente el espacio y el ambiente.",
            "Gestiona responsablemente los recursos económicos.",
        ],
        "Ciencia y Tecnología" => [
            "Indaga mediante métodos científicos para construir sus conocimientos.",
            "Explica el mundo físico basándose en conocimientos sobre los seres vivos, materia y energía, biodiversidad, tierra y universo.",
            "Diseña y construye soluciones tecnológicas para resolver problemas de su entorno.",
        ],
        "Comunicación" => [
            "Se comunica oralmente en su lengua materna.",
            "Lee diversos tipos de textos escritos en su lengua materna.",
            "Escribe diversos tipos de textos en su lengua materna.",
        ],
        "DPCC" => [
            "Construye su identidad.",
            "Convive y participa democráticamente en la búsqueda del bien común.",
        ],
        "Educación Física" => [
            "Se desenvuelve de manera autónoma a través de su motricidad.",
            "Asume una vida saludable.",
            "Interactúa a través de sus habilidades sociomotrices.",
        ],
        "EPT" => [
            "Gestiona proyectos de emprendimiento económico o social.",
        ],
        "Inglés" => [
            "Se comunica oralmente en inglés como lengua extranjera",
            "Lee diversos tipos de textos escritos en inglés como lengua extranjera.",
            "Escribe diversos tipos de textos en inglés como lengua extranjera.",
        ],
        "Matemática" => [
            "Resuelve problemas de cantidad.",
            "Resuelve problemas de regularidad, equivalencia y cambio.",
            "Resuelve problemas de forma, movimiento y localización",
            "Resuelve problemas de gestión de datos e incertidumbre.",
        ],
        "Educación Religiosa" => [
            "Construye su identidad como persona humana, amada por Dios, digna, libre y trascendente, comprendiendo la doctrina de su propia religión, abierto al dialogo con las que le son cercanas.",
            "Asume la experiencia del encuentro personal y comunitario con Dios en su proyecto de vida en coherencia con su creencia religiosa.",
        ],
    ],

    "PRIMARIA" => [
        "Personal Social" => [
            "Construye su identidad.",
            "Convive y participa democráticamente en la búsqueda del bien común.",
            "Construye interpretaciones históricas.",
            "Gestiona responsablemente el espacio y el ambiente.",
            "Gestiona responsablemente los recursos económicos.",
        ],
        "Educación Física" => [
            "Se desenvuelve de manera autónoma a través de su motricidad.",
            "Asume una vida saludable.",
            "Interactúa a través de sus habilidades sociomotrices.",
        ],
        "Comunicación" => [
            "Se comunica oralmente en su lengua materna.",
            "Lee diversos tipos de textos escritos en su lengua materna.",
            "Escribe diversos tipos de textos en su lengua materna.",
        ],
        "Arte y Cultura" => [
            "Aprecia de manera crítica manifestaciones artístico-culturales.",
            "Crea proyectos desde los lenguajes artísticos.",
        ],
        "Matemática" => [
            "Resuelve problemas de cantidad.",
            "Resuelve problemas de regularidad, equivalencia y cambio.",
            "Resuelve problemas de forma, movimiento y localización",
            "Resuelve problemas de gestión de datos e incertidumbre.",
        ],
        "Ciencia y Tecnología" => [
            "Indaga mediante métodos científicos para construir sus conocimientos.",
            "Explica el mundo físico basándose en conocimientos sobre los seres vivos, materia y energía, biodiversidad, tierra y universo.",
            "Diseña y construye soluciones tecnológicas para resolver problemas de su entorno.",
        ],
        "Educación Religiosa" => [
            "Construye su identidad como persona humana, amada por Dios, digna, libre y trascendente, comprendiendo la doctrina de su propia religión, abierto al dialogo con las que le son cercanas.",
            "Asume la experiencia del encuentro personal y comunitario con Dios en su proyecto de vida en coherencia con su creencia religiosa.",
        ],
    ],

];
