<?php

// ==========================================
// Sistema de iconos SVG del Intranet.
//
// Reemplaza los emojis decorativos por un set consistente de
// iconos de línea (estilo Feather, stroke=currentColor). No es una
// librería externa: son los mismos ~30 trazados que ya se usaban
// como emoji, convertidos a SVG para que el tamaño, el color y el
// grosor sean uniformes en todo el panel (sidebar, header, tarjetas,
// badges, alertas, estados vacíos).
//
// Uso: icon("home")  o  icon("home", "nav-icon")
// ==========================================

function icon(string $nombre, string $class = "icon"): string
{
    $paths = [
        "home"          => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>',
        "book"          => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/>',
        "layers"        => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        "file-text"     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
        "folder"        => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
        "users"         => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16.2 5.3a3.2 3.2 0 0 1 0 6.2"/><path d="M17.5 14.3a6.5 6.5 0 0 1 4 5.7"/>',
        "briefcase"     => '<rect x="2.5" y="7" width="19" height="13" rx="2"/><path d="M8 7V5.5A2.5 2.5 0 0 1 10.5 3h3A2.5 2.5 0 0 1 16 5.5V7"/><path d="M2.5 12.5h19"/>',
        "send"          => '<path d="M21.5 2.5 11 13"/><path d="M21.5 2.5 15 21.5l-4-8.5-8.5-4 19-6.5Z"/>',
        "user"          => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        "log-out"       => '<path d="M9 20H5.5A1.5 1.5 0 0 1 4 18.5v-13A1.5 1.5 0 0 1 5.5 4H9"/><path d="m15.5 16 4.5-4-4.5-4"/><path d="M20 12H9"/>',
        "bell"          => '<path d="M6 9a6 6 0 1 1 12 0c0 4.2 1.2 6 2 7H4c.8-1 2-2.8 2-7Z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        "chevron-down"  => '<path d="m6 9 6 6 6-6"/>',
        "chevron-left"  => '<path d="m15 18-6-6 6-6"/>',
        "menu"          => '<path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/>',
        "x"             => '<path d="m5 5 14 14"/><path d="m19 5-14 14"/>',
        "search"        => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.4-4.4"/>',
        "check-circle"  => '<circle cx="12" cy="12" r="9.5"/><path d="m8 12.5 2.5 2.5 5.5-6"/>',
        "alert-triangle"=> '<path d="M10.6 3.7 2.3 18a1.6 1.6 0 0 0 1.4 2.4h16.6a1.6 1.6 0 0 0 1.4-2.4L13.4 3.7a1.6 1.6 0 0 0-2.8 0Z"/><path d="M12 9.5v4.2"/><path d="M12 17.2h.01"/>',
        "clock"         => '<circle cx="12" cy="12" r="9.5"/><path d="M12 6.5V12l3.5 2"/>',
        "backpack"      => '<path d="M8 8V6a4 4 0 0 1 8 0v2"/><path d="M6 8h12a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2Z"/><path d="M9 12h6"/><path d="M9.5 8v3.5"/><path d="M14.5 8v3.5"/>',
        "settings"      => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 13.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V19a2 2 0 1 1-4 0v-.2a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.2a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.2a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.2a1.6 1.6 0 0 0-1.4 1Z"/>',
        "bar-chart"     => '<path d="M4 20V10"/><path d="M12 20V4"/><path d="M20 20v-7"/>',
        "megaphone"     => '<path d="M3 11v2a2 2 0 0 0 2 2h1l4 5v-9"/><path d="M10 6 20 3v14l-10-3"/>',
        "calendar"      => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9.5h18"/><path d="M8 2.5v4"/><path d="M16 2.5v4"/>',
        "clipboard"     => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1"/><path d="M9 11h6"/><path d="M9 15h6"/>',
        "shield-check"  => '<path d="M12 2.5 4 5.5v6c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10v-6L12 2.5Z"/><path d="m9 12 2 2 4-4.5"/>',
        "arrow-right"   => '<path d="M4 12h16"/><path d="m13.5 5.5 6.5 6.5-6.5 6.5"/>',
        "building"      => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 8h.01"/><path d="M15 8h.01"/><path d="M9 12h.01"/><path d="M15 12h.01"/><path d="M9 16h.01"/><path d="M15 16h.01"/>',
        "upload"        => '<path d="M12 16V4"/><path d="m7 8.5 5-5 5 5"/><path d="M4 16.5V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2.5"/>',
        "trash"         => '<path d="M4 6.5h16"/><path d="M9 6.5V4.8A1.8 1.8 0 0 1 10.8 3h2.4A1.8 1.8 0 0 1 15 4.8v1.7"/><path d="M6.5 6.5 7.3 19a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12.5"/>',
        "pencil"        => '<path d="M14.5 4.5 19 9l-9.5 9.5H5v-4.5Z"/><path d="m13 6 4.5 4.5"/>',
        "graduation"    => '<path d="m2.5 9 9.5-4.5L21.5 9 12 13.5 2.5 9Z"/><path d="M6.5 11v5c0 1.4 2.5 3 5.5 3s5.5-1.6 5.5-3v-5"/>',
        "rotate-ccw"    => '<path d="M3 3v6h6"/><path d="M3.5 13.5a8.5 8.5 0 1 0 2.6-7.8L3 9"/>',
        "hard-drive"    => '<rect x="2.5" y="4" width="19" height="16" rx="2"/><path d="M2.5 14h19"/><path d="M6 18h.01"/><path d="M10 18h.01"/>',
        "history"       => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3 3.5V8h4.5"/><path d="M12 7.5V12l3 2"/>',
        "smile"         => '<circle cx="12" cy="12" r="9.5"/><path d="M8 13.5s1.5 2 4 2 4-2 4-2"/><path d="M8.5 9.5h.01"/><path d="M15.5 9.5h.01"/>',
        "file-plus"     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M12 12.5v6"/><path d="M9 15.5h6"/>',
    ];

    $d = $paths[$nombre] ?? $paths["file-text"];

    return '<svg class="' . htmlspecialchars($class) . '" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

}
