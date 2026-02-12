<?php
declare(strict_types=1);

// ============================================
// CONFIG IMAP (Gmail)
// ============================================
const IMAP_HOSTNAME = '{imap.gmail.com:993/imap/ssl}INBOX';
const IMAP_USERNAME = 'TU_CORREO@gmail.com';
const IMAP_PASSWORD = 'TU_PASSWORD_DE_APLICACION';

// ============================================
// AJUSTES DEL BLOG
// ============================================
const EXCERPT_LEN  = 220; // extracto portada
const FRONT_LIMIT  = 4;   // 2x2 = 4 posts por página

// ============================================
// AJUSTES DEL PANEL (stats)
// ============================================
const STATS_MAX_EMAILS   = 250; // escanea últimos N (más = más lento)
const STATS_CACHE_TTL    = 600; // 10 min cache
const PANEL_TOKEN        = '';  // opcional: pon algo tipo "mi_token_123" para proteger panel

// Zona horaria (ajusta si quieres)
date_default_timezone_set('Europe/Madrid');
