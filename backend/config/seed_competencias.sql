-- Semilla generada a partir de las 3 boletas reales (Primaria.docx,
-- Secundaria1ro.docx, Secundaria-2-5.docx) — nombres oficiales MINEDU/CNEB.
-- Usa subconsultas por nombre+nivel de `cursos` (nombres REALES verificados
-- contra un dump real: Ciencias Sociales, Arte y Cultura, Educación Religiosa,
-- DPCC, EPT tal como están en la BD de este colegio), no IDs fijos.

-- ---- SECUNDARIA ----
-- Arte y Cultura
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Aprecia de manera crítica manifestaciones artístico-culturales.', 1 FROM cursos WHERE nombre = 'Arte y Cultura' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Crea proyectos desde los lenguajes artísticos.', 2 FROM cursos WHERE nombre = 'Arte y Cultura' AND nivel = 'SECUNDARIA';

-- Ciencias Sociales
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Construye interpretaciones históricas.', 1 FROM cursos WHERE nombre = 'Ciencias Sociales' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Gestiona responsablemente el espacio y el ambiente.', 2 FROM cursos WHERE nombre = 'Ciencias Sociales' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Gestiona responsablemente los recursos económicos.', 3 FROM cursos WHERE nombre = 'Ciencias Sociales' AND nivel = 'SECUNDARIA';

-- Ciencia y Tecnología
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Indaga mediante métodos científicos para construir sus conocimientos.', 1 FROM cursos WHERE nombre = 'Ciencia y Tecnología' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Explica el mundo físico basándose en conocimientos sobre los seres vivos, materia y energía, biodiversidad, tierra y universo.', 2 FROM cursos WHERE nombre = 'Ciencia y Tecnología' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Diseña y construye soluciones tecnológicas para resolver problemas de su entorno.', 3 FROM cursos WHERE nombre = 'Ciencia y Tecnología' AND nivel = 'SECUNDARIA';

-- Comunicación
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Se comunica oralmente en su lengua materna.', 1 FROM cursos WHERE nombre = 'Comunicación' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Lee diversos tipos de textos escritos en su lengua materna.', 2 FROM cursos WHERE nombre = 'Comunicación' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Escribe diversos tipos de textos en su lengua materna.', 3 FROM cursos WHERE nombre = 'Comunicación' AND nivel = 'SECUNDARIA';

-- DPCC
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Construye su identidad.', 1 FROM cursos WHERE nombre = 'DPCC' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Convive y participa democráticamente en la búsqueda del bien común.', 2 FROM cursos WHERE nombre = 'DPCC' AND nivel = 'SECUNDARIA';

-- Educación Física
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Se desenvuelve de manera autónoma a través de su motricidad.', 1 FROM cursos WHERE nombre = 'Educación Física' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Asume una vida saludable.', 2 FROM cursos WHERE nombre = 'Educación Física' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Interactúa a través de sus habilidades sociomotrices.', 3 FROM cursos WHERE nombre = 'Educación Física' AND nivel = 'SECUNDARIA';

-- EPT
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Gestiona proyectos de emprendimiento económico o social.', 1 FROM cursos WHERE nombre = 'EPT' AND nivel = 'SECUNDARIA';

-- Inglés
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Se comunica oralmente en inglés como lengua extranjera', 1 FROM cursos WHERE nombre = 'Inglés' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Lee diversos tipos de textos escritos en inglés como lengua extranjera.', 2 FROM cursos WHERE nombre = 'Inglés' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Escribe diversos tipos de textos en inglés como lengua extranjera.', 3 FROM cursos WHERE nombre = 'Inglés' AND nivel = 'SECUNDARIA';

-- Matemática
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de cantidad.', 1 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de regularidad, equivalencia y cambio.', 2 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de forma, movimiento y localización', 3 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de gestión de datos e incertidumbre.', 4 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'SECUNDARIA';

-- Educación Religiosa
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Construye su identidad como persona humana, amada por Dios, digna, libre y trascendente, comprendiendo la doctrina de su propia religión, abierto al dialogo con las que le son cercanas.', 1 FROM cursos WHERE nombre = 'Educación Religiosa' AND nivel = 'SECUNDARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Asume la experiencia del encuentro personal y comunitario con Dios en su proyecto de vida en coherencia con su creencia religiosa.', 2 FROM cursos WHERE nombre = 'Educación Religiosa' AND nivel = 'SECUNDARIA';

-- ---- PRIMARIA ----
-- Personal Social
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Construye su identidad.', 1 FROM cursos WHERE nombre = 'Personal Social' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Convive y participa democráticamente en la búsqueda del bien común.', 2 FROM cursos WHERE nombre = 'Personal Social' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Construye interpretaciones históricas.', 3 FROM cursos WHERE nombre = 'Personal Social' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Gestiona responsablemente el espacio y el ambiente.', 4 FROM cursos WHERE nombre = 'Personal Social' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Gestiona responsablemente los recursos económicos.', 5 FROM cursos WHERE nombre = 'Personal Social' AND nivel = 'PRIMARIA';

-- Educación Física
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Se desenvuelve de manera autónoma a través de su motricidad.', 1 FROM cursos WHERE nombre = 'Educación Física' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Asume una vida saludable.', 2 FROM cursos WHERE nombre = 'Educación Física' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Interactúa a través de sus habilidades sociomotrices.', 3 FROM cursos WHERE nombre = 'Educación Física' AND nivel = 'PRIMARIA';

-- Comunicación
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Se comunica oralmente en su lengua materna.', 1 FROM cursos WHERE nombre = 'Comunicación' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Lee diversos tipos de textos escritos en su lengua materna.', 2 FROM cursos WHERE nombre = 'Comunicación' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Escribe diversos tipos de textos en su lengua materna.', 3 FROM cursos WHERE nombre = 'Comunicación' AND nivel = 'PRIMARIA';

-- Arte y Cultura
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Aprecia de manera crítica manifestaciones artístico-culturales.', 1 FROM cursos WHERE nombre = 'Arte y Cultura' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Crea proyectos desde los lenguajes artísticos.', 2 FROM cursos WHERE nombre = 'Arte y Cultura' AND nivel = 'PRIMARIA';

-- Matemática
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de cantidad.', 1 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de regularidad, equivalencia y cambio.', 2 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de forma, movimiento y localización', 3 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Resuelve problemas de gestión de datos e incertidumbre.', 4 FROM cursos WHERE nombre = 'Matemática' AND nivel = 'PRIMARIA';

-- Ciencia y Tecnología
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Indaga mediante métodos científicos para construir sus conocimientos.', 1 FROM cursos WHERE nombre = 'Ciencia y Tecnología' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Explica el mundo físico basándose en conocimientos sobre los seres vivos, materia y energía, biodiversidad, tierra y universo.', 2 FROM cursos WHERE nombre = 'Ciencia y Tecnología' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Diseña y construye soluciones tecnológicas para resolver problemas de su entorno.', 3 FROM cursos WHERE nombre = 'Ciencia y Tecnología' AND nivel = 'PRIMARIA';

-- Educación Religiosa
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Construye su identidad como persona humana, amada por Dios, digna, libre y trascendente, comprendiendo la doctrina de su propia religión, abierto al dialogo con las que le son cercanas.', 1 FROM cursos WHERE nombre = 'Educación Religiosa' AND nivel = 'PRIMARIA';
INSERT IGNORE INTO competencias (id_curso, nombre, orden) SELECT id_curso, 'Asume la experiencia del encuentro personal y comunitario con Dios en su proyecto de vida en coherencia con su creencia religiosa.', 2 FROM cursos WHERE nombre = 'Educación Religiosa' AND nivel = 'PRIMARIA';
