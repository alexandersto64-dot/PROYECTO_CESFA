<?php

// ==========================================================
// Lector de boletas por POSICIÓN (formato SIAGIE 2026:
// "INFORME DE PROGRESO DE LAS COMPETENCIAS DEL ESTUDIANTE").
//
// Por qué existe: en este formato NO hay fila "CALIFICATIVO DE AREA"
// y las celdas vacías no dejan ningún rastro en el texto plano, así
// que leer "los 4 calificativos que siguen a la competencia" (como
// hace boleta_parsear_texto) mezcla bimestres y falla. Además los
// nombres de las competencias impresos no coinciden letra por letra
// con el catálogo (sin punto final, "Tierra y Universo", cortes de
// línea, "Inglés" a secas, etc.).
//
// Aquí se usan las coordenadas (x, y) de cada texto del PDF:
//   - la columna x del valor decide el BIMESTRE (NL1..NL4),
//   - la fila (y) decide a qué COMPETENCIA pertenece,
//   - el nombre impreso se compara contra el catálogo ignorando
//     tildes, puntuación y espacios.
// Devuelve la misma estructura de "areas" que boleta_parsear_texto().
// ==========================================================

require_once __DIR__ . "/pdf_extraccion.php";
require_once __DIR__ . "/competencias_catalogo.php";

use Smalot\PdfParser\Parser as PdfParserPos;

// Área EIB: aparece impresa en la boleta (con filas vacías) y hay que
// "consumir" sus filas para que no se confundan con las de Inglés,
// pero no se importa (el colegio no es EIB, ver competencias_catalogo.php).
const BOLETA_AREA_CASTELLANO_L2 = [
    "Se comunica oralmente",
    "Lee diversos tipos de textos escritos",
    "Escribe diversos tipos de textos",
];

/** Solo letras/números en mayúscula y sin tildes: para comparar textos sin importar puntuación ni saltos de línea. */
function boleta_pos_clave(string $s): string {
    $s = mb_strtoupper($s);
    $s = strtr($s, ["Á" => "A", "É" => "E", "Í" => "I", "Ó" => "O", "Ú" => "U", "Ü" => "U", "Ñ" => "N"]);
    return preg_replace('/[^A-Z0-9]/', '', $s);
}

/** 0..1 — qué tan bien el texto impreso ($impreso) corresponde a la competencia del catálogo ($catalogo). */
function boleta_pos_similitud(string $impreso, string $catalogo): float {
    $a = boleta_pos_clave($impreso);
    $b = boleta_pos_clave($catalogo);
    if ($a === "" || $b === "") {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    // el PDF a veces imprime solo el comienzo ("Se comunica oralmente" para Inglés)
    if (strlen($a) >= 15 && (str_starts_with($b, $a) || str_starts_with($a, $b))) {
        return 0.95;
    }
    similar_text($a, $b, $pct);
    return $pct / 100;
}

/** Agrupa líneas de texto contiguas (mismo bloque de celda) → [["texto","y","pagina"], ...] */
function boleta_pos_agrupar_lineas(array $lineas): array {
    // $lineas: [["p"=>int,"y"=>float,"t"=>string], ...] ya ordenadas por página y y descendente
    $bloques = [];
    $actual = null;
    foreach ($lineas as $l) {
        if ($actual && $actual["p"] === $l["p"] && ($actual["y_ultima"] - $l["y"]) <= 9.5) {
            $actual["textos"][] = $l["t"];
            $actual["y_ultima"] = $l["y"];
        } else {
            if ($actual) {
                $bloques[] = $actual;
            }
            $actual = ["p" => $l["p"], "y_primera" => $l["y"], "y_ultima" => $l["y"], "textos" => [$l["t"]]];
        }
    }
    if ($actual) {
        $bloques[] = $actual;
    }
    foreach ($bloques as &$b) {
        $b["texto"] = trim(implode(" ", $b["textos"]));
        $b["y"] = ($b["y_primera"] + $b["y_ultima"]) / 2; // las celdas están centradas verticalmente
    }
    unset($b);
    return $bloques;
}

/**
 * @return array  Lista de áreas en el mismo formato que boleta_parsear_texto()["areas"]
 *                (vacía si el PDF no tiene el formato esperado → el llamador usa el método por texto).
 */
function boleta_parsear_posiciones(string $rutaAbsoluta, ?string $nivelTexto): array {

    $nivel = boleta_normalizar_nivel($nivelTexto) ?? "SECUNDARIA";
    $catalogoNivel = COMPETENCIAS_POR_CURSO[$nivel] ?? [];
    $aliasArea = AREA_A_CURSO[$nivel] ?? [];

    try {
        $pdf = (new PdfParserPos())->parseFile($rutaAbsoluta);
        $paginas = $pdf->getPages();
    } catch (\Throwable $e) {
        return [];
    }

    // ---- 1) Items de texto con posición, por página ----
    $items = [];
    foreach ($paginas as $numPagina => $pagina) {
        try {
            $datos = $pagina->getDataTm();
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($datos as $d) {
            $t = trim(preg_replace('/\s+/u', ' ', (string) ($d[1] ?? "")));
            if ($t === "") {
                continue;
            }
            $items[] = ["p" => $numPagina, "x" => (float) $d[0][4], "y" => (float) $d[0][5], "t" => $t];
        }
    }

    // ---- 2) Columnas (NL de cada bimestre) y límite área|competencia, por página ----
    $colsPorPagina = [];
    $limitesPorPagina = [];
    $yEncabezado = [];
    foreach (array_unique(array_column($items, "p")) as $p) {
        $nl = array_filter($items, fn($i) => $i["p"] === $p && $i["t"] === "NL" && $i["x"] < 500);
        $porY = [];
        foreach ($nl as $i) {
            $porY[(string) round($i["y"], 1)][] = $i["x"];
        }
        $mejorY = null;
        foreach ($porY as $y => $xs) {
            if (count($xs) >= 4 && ($mejorY === null || (float) $y > (float) $mejorY)) {
                $mejorY = $y; // la tabla de áreas es la de arriba
            }
        }
        if ($mejorY === null) {
            continue;
        }
        $xs = $porY[$mejorY];
        sort($xs);
        $colsPorPagina[$p] = array_slice($xs, 0, 4);
        $yEncabezado[$p] = (float) $mejorY;

        $xArea = null;
        $xComp = null;
        foreach ($items as $i) {
            if ($i["p"] !== $p) continue;
            if ($i["t"] === "Área curricular") $xArea = $i["x"];
            if ($i["t"] === "Competencias") $xComp = $i["x"];
        }
        $limitesPorPagina[$p] = ($xArea !== null && $xComp !== null) ? ($xArea + $xComp) / 2 : 60.0;
    }

    if (count($colsPorPagina) === 0) {
        return [];
    }

    // ---- 3) Etiquetas de área (col. izquierda) y bloques de competencia (col. central) ----
    $lineasArea = [];
    $lineasComp = [];
    $valores = [];
    foreach ($items as $i) {
        $p = $i["p"];
        if (!isset($colsPorPagina[$p]) || $i["y"] >= $yEncabezado[$p] - 3) {
            continue; // por encima o dentro del encabezado
        }
        $cols = $colsPorPagina[$p];
        $limite = $limitesPorPagina[$p];

        if ($i["x"] < $limite) {
            $lineasArea[] = ["p" => $p, "y" => $i["y"], "t" => $i["t"]];
        } elseif ($i["x"] < $cols[0] - 4) {
            $lineasComp[] = ["p" => $p, "y" => $i["y"], "t" => $i["t"]];
        } elseif (preg_match('/^(AD|A|B|C|[0-9]|1[0-9]|20)$/', $i["t"])) {
            foreach ($cols as $idx => $cx) {
                if (abs($i["x"] - $cx) <= 10) {
                    $valores[] = ["p" => $p, "y" => $i["y"], "bim" => $idx + 1, "v" => $i["t"]];
                    break;
                }
            }
        }
    }

    $ordenar = fn($a, $b) => [$a["p"], -$a["y"]] <=> [$b["p"], -$b["y"]];
    usort($lineasArea, $ordenar);
    usort($lineasComp, $ordenar);
    $bloquesArea = boleta_pos_agrupar_lineas($lineasArea);
    $bloquesComp = boleta_pos_agrupar_lineas($lineasComp);

    // ---- 4) Orden de las áreas tal como salen impresas ----
    $areasEnOrden = []; // [["curso" => nombre|null, "clave" => ..., "competencias" => [...]]]
    foreach ($bloquesArea as $b) {
        $k = boleta_pos_clave($b["texto"]);
        if (strlen($k) < 5) {
            continue;
        }
        if (str_starts_with("CASTELLANOCOMOSEGUNDALENGUA", $k)) {
            $areasEnOrden[] = ["curso" => null, "competencias" => BOLETA_AREA_CASTELLANO_L2];
            continue;
        }
        foreach ($aliasArea as $alias => $curso) {
            $ka = boleta_pos_clave($alias);
            if ($ka === $k || str_starts_with($ka, $k) || str_starts_with($k, $ka)) {
                $areasEnOrden[] = ["curso" => $curso, "competencias" => $catalogoNivel[$curso] ?? []];
                break;
            }
        }
    }

    if (count($areasEnOrden) === 0) {
        return [];
    }

    // ---- 5) Cada bloque de competencia → competencia del catálogo (avanzando por las áreas en orden) ----
    $usadas = []; // "idxArea|idxComp" ya asignadas
    $asignacion = []; // idxBloque => ["area" => idx, "comp" => idx]
    $puntero = 0;
    foreach ($bloquesComp as $iBloque => $bloque) {
        for ($j = $puntero; $j < min($puntero + 3, count($areasEnOrden)); $j++) {
            $mejor = null;
            $mejorScore = 0.85;
            foreach ($areasEnOrden[$j]["competencias"] as $iComp => $textoCat) {
                if (isset($usadas["$j|$iComp"])) {
                    continue;
                }
                $s = boleta_pos_similitud($bloque["texto"], $textoCat);
                if ($s > $mejorScore) {
                    $mejorScore = $s;
                    $mejor = $iComp;
                }
            }
            if ($mejor !== null) {
                $usadas["$j|$mejor"] = true;
                $asignacion[$iBloque] = ["area" => $j, "comp" => $mejor];
                $puntero = $j;
                break;
            }
        }
    }

    // ---- 6) Cada valor NL → el bloque de competencia más cercano en su misma página/fila ----
    $valoresPorBloque = [];
    foreach ($valores as $v) {
        $mejor = null;
        $mejorDist = 14.0; // tolerancia vertical (pt)
        foreach ($bloquesComp as $iBloque => $bloque) {
            if ($bloque["p"] !== $v["p"]) continue;
            $dist = abs($bloque["y"] - $v["y"]);
            if ($dist < $mejorDist) {
                $mejorDist = $dist;
                $mejor = $iBloque;
            }
        }
        if ($mejor !== null) {
            $valoresPorBloque[$mejor][(string) $v["bim"]] = $v["v"];
        }
    }

    // ---- 7) Armar la estructura de salida ----
    $porArea = [];
    foreach ($asignacion as $iBloque => $a) {
        $area = $areasEnOrden[$a["area"]];
        if ($area["curso"] === null) {
            continue; // Castellano como segunda lengua: no se importa
        }
        $bim = ["1" => null, "2" => null, "3" => null, "4" => null];
        foreach ($valoresPorBloque[$iBloque] ?? [] as $n => $val) {
            $bim[$n] = $val;
        }
        $porArea[$a["area"]][] = [
            "texto" => $area["competencias"][$a["comp"]], // texto del catálogo → el matching contra la BD sigue igual
            "bimestres" => $bim,
        ];
    }

    $areas = [];
    ksort($porArea);
    foreach ($porArea as $idxArea => $competencias) {
        $areas[] = [
            "texto_curso" => $areasEnOrden[$idxArea]["curso"],
            "bimestres" => ["1" => null, "2" => null, "3" => null, "4" => null], // este formato no trae total por área
            "competencias" => $competencias,
        ];
    }

    return $areas;

}
