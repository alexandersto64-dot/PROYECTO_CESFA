<?php

function profesor_nivel_permitido(string $rol): ?string
{
    return match ($rol) {
        "PROFESOR_PRIMARIA"   => "PRIMARIA",
        "PROFESOR_SECUNDARIO" => "SECUNDARIA",
        default               => null,
    };
}

function profesor_tiene_acceso_nivel(string $rol, string $nivel): bool
{
    $nivelPermitido = profesor_nivel_permitido($rol);

    return $nivelPermitido !== null && $nivelPermitido === $nivel;
}