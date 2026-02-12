<?php
declare(strict_types=1);

/**
 * lib_imap_blog.php — Librería principal (motor del “blog de correos”)
 * ------------------------------------------------------------------
 * Este archivo NO imprime HTML.
 * Su trabajo es ser una “caja de herramientas” para:
 *  - Abrir la conexión IMAP (Gmail)
 *  - Extraer el cuerpo (HTML o texto) de un correo
 *  - Detectar la primera imagen para usarla como portada
 *  - Listar adjuntos (no imágenes) y listarlos para descarga
 *  - Listar imágenes para descarga
 *  - Crear extractos (excerpt) para la portada del blog
 *  - Helpers de URL (conservar parámetros)
 *  - Helpers de stats: remitentes, contar imágenes/adjuntos por estructura
 *
 * Importante:
 *  - Este motor lee la estructura MIME del correo.
 *  - La “imagen destacada” se obtiene buscando la primera parte tipo imagen.
 *  - Para adjuntos e imágenes NO descargamos todo el correo en portada,
 *    solo lo necesario cuando se pide.
 */

/* =========================================================
   1) IMAP helpers
   ========================================================= */

/**
 * Abre conexión IMAP usando constantes definidas en config.php:
 *  - IMAP_HOSTNAME (ej. {imap.gmail.com:993/imap/ssl}INBOX)
 *  - IMAP_USERNAME (tu email)
 *  - IMAP_PASSWORD (app password)
 *
 * Si falla: muere y muestra error IMAP.
 */
function imapOpenOrDie() {
    $inbox = @imap_open(IMAP_HOSTNAME, IMAP_USERNAME, IMAP_PASSWORD);
    if (!$inbox) {
        die('Error IMAP: ' . imap_last_error());
    }
    return $inbox;
}

/**
 * decodePart:
 * IMAP puede entregarte el contenido codificado según $encoding.
 * Los más comunes:
 *  - 3 => BASE64
 *  - 4 => QUOTED-PRINTABLE
 *  - otros => texto “tal cual”
 *
 * Esto se usa para:
 *  - cuerpos HTML / texto
 *  - binarios de adjuntos
 *  - binarios de imágenes
 */
function decodePart(string $content, int $encoding): string {
    switch ($encoding) {
        case 3: return (string)base64_decode($content);           // BASE64
        case 4: return (string)quoted_printable_decode($content); // QUOTED-PRINTABLE
        default: return $content;
    }
}

/**
 * getPartFilename:
 * Busca el nombre real del archivo de una “parte MIME”.
 * IMAP puede traer el nombre en:
 *  - dparameters (filename=...)
 *  - parameters  (name=...)
 *
 * Retorna string o null si no hay nombre.
 */
function getPartFilename($part): ?string {
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $dp) {
            if (isset($dp->attribute) && strtolower((string)$dp->attribute) === 'filename') {
                return (string)$dp->value;
            }
        }
    }
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (isset($p->attribute) && strtolower((string)$p->attribute) === 'name') {
                return (string)$p->value;
            }
        }
    }
    return null;
}

/**
 * safeFileName:
 * Limpia un nombre para que sea seguro en descargas (header).
 * - elimina saltos de línea (prevención de header injection)
 * - trim
 * - si queda vacío -> archivo.bin
 * - basename() evita rutas tipo ../../
 */
function safeFileName(string $name): string {
    $name = str_replace(["\r", "\n"], '', $name);
    $name = trim($name);
    if ($name === '') return 'archivo.bin';
    return basename($name);
}

/**
 * isImagePart:
 * Decide si una parte MIME debe ser tratada como “imagen”.
 * Reglas:
 *  1) Si IMAP marca type === 5 -> imagen (lo más directo)
 *  2) Si viene raro como application, se intenta por extensión de filename
 */
function isImagePart($part): bool {
    // IMAP: type 5 suele ser IMAGE
    if (isset($part->type) && (int)$part->type === 5) return true;

    // A veces viene como application con nombre .png/.jpg, etc.
    $filename = getPartFilename($part);
    if ($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true);
    }
    return false;
}

/**
 * guessImageMime:
 * Intenta adivinar un Content-Type para imágenes:
 * - si part->subtype existe: image/<subtype>
 * - si no, por extensión del filename
 * - fallback: image/jpeg
 */
function guessImageMime($part, ?string $filename = null): string {
    if (isset($part->subtype) && $part->subtype) {
        $sub = strtolower((string)$part->subtype);
        if ($sub === 'jpg') $sub = 'jpeg';
        return 'image/' . $sub;
    }
    if ($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'jpg') $ext = 'jpeg';
        return 'image/' . ($ext ?: 'jpeg');
    }
    return 'image/jpeg';
}

/**
 * guessPartMime:
 * Igual que guessImageMime pero genérico para adjuntos.
 * - construye base por type (0..7)
 * - subtype por part->subtype o por extensión
 * - fallback: application/octet-stream
 *
 * Nota:
 *  - multipart no es descargable, por eso lo forzamos a octet-stream.
 */
function guessPartMime($part, ?string $filename = null): string {
    $types = [
        0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
        4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'
    ];
    $type = isset($part->type) ? (int)$part->type : 3;
    $base = $types[$type] ?? 'application';

    $sub = '';
    if (isset($part->subtype) && $part->subtype) {
        $sub = strtolower((string)$part->subtype);
        if ($sub === 'jpg') $sub = 'jpeg';
    } elseif ($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'jpg') $ext = 'jpeg';
        $sub = $ext;
    }

    if ($base === 'multipart') return 'application/octet-stream';
    if ($sub === '') return 'application/octet-stream';
    return $base . '/' . $sub;
}

/* =========================================================
   2) Body extraction (HTML / TEXT)
   ========================================================= */

/**
 * walkBodyParts:
 * Recorre recursivamente el árbol MIME para encontrar:
 *  - text/html
 *  - text/plain
 *
 * $out tiene forma:
 *   ['html' => string|null, 'text' => string|null]
 *
 * Reglas:
 *  - si encuentra HTML por primera vez, lo guarda
 *  - si encuentra PLAIN por primera vez, lo guarda (convertido a HTML seguro)
 *
 * ¿Por qué recursivo?
 *  - Muchos correos vienen multipart/alternative (HTML + PLAIN)
 *  - Otros vienen multipart/mixed (cuerpo + adjuntos)
 *  - Y dentro puede haber multiparts anidados.
 */
function walkBodyParts($imap, int $msgno, array $parts, string $prefix, array &$out): void {
    foreach ($parts as $index => $part) {
        // Numeración de partes: 1, 2, 3 ... o 1.1, 1.2 ...
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        // Si tiene sub-partes -> seguimos bajando (recursión)
        if (isset($part->parts)) {
            walkBodyParts($imap, $msgno, $part->parts, $partNumber, $out);
            continue;
        }

        // type 0 => text/*
        if (isset($part->type) && (int)$part->type === 0) {
            // Traer esta parte del cuerpo
            $raw = imap_fetchbody($imap, $msgno, $partNumber);

            // Decodificar por encoding (base64 / quoted-printable)
            $raw = decodePart((string)$raw, (int)($part->encoding ?? 0));

            // subtype suele ser HTML o PLAIN
            $subtype = isset($part->subtype) ? strtoupper((string)$part->subtype) : '';

            // Guardamos el primer HTML que aparezca
            if ($subtype === 'HTML' && $out['html'] === null) {
                $out['html'] = $raw;
            }

            // Guardamos el primer PLAIN que aparezca
            // Lo convertimos a HTML seguro (escape + saltos de línea)
            if ($subtype === 'PLAIN' && $out['text'] === null) {
                $out['text'] = nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
        }
    }
}

/**
 * extractBodies:
 * Punto de entrada para obtener cuerpo del correo.
 * - Si el correo NO es multipart: se lee con imap_body()
 * - Si es multipart: recorre partes con walkBodyParts()
 */
function extractBodies($imap, int $msgno): array {
    $structure = imap_fetchstructure($imap, $msgno);
    $out = ['html' => null, 'text' => null];
    if (!$structure) return $out;

    // Caso 1: correo simple (no multipart)
    if (!isset($structure->parts)) {
        $raw = imap_body($imap, $msgno);
        $raw = decodePart((string)$raw, (int)($structure->encoding ?? 0));

        $subtype = isset($structure->subtype) ? strtoupper((string)$structure->subtype) : '';
        if ($subtype === 'HTML') $out['html'] = $raw;
        else $out['text'] = nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        return $out;
    }

    // Caso 2: multipart (recursivo)
    walkBodyParts($imap, $msgno, $structure->parts, '', $out);
    return $out;
}

/* =========================================================
   3) Featured image (primera imagen encontrada)
   ========================================================= */

/**
 * findFirstImagePart:
 * Busca la PRIMERA parte MIME que parezca imagen.
 * Se usa para mostrar una “portada” en el blog.
 *
 * Retorna:
 *  - ['part' => $partObj, 'partNumber' => '1.2']
 * o null si no hay imágenes.
 */
function findFirstImagePart($structure, string $prefix = ''): ?array {
    if (!isset($structure->parts)) return null;

    foreach ($structure->parts as $index => $part) {
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        // Si tiene subpartes, bajamos
        if (isset($part->parts)) {
            $deep = findFirstImagePart($part, $partNumber);
            if ($deep) return $deep;
            continue;
        }

        // Si es imagen, paramos y devolvemos
        if (isImagePart($part)) {
            return ['part' => $part, 'partNumber' => $partNumber];
        }
    }
    return null;
}

/**
 * getFirstImageDataUri:
 * - Encuentra la primera imagen del correo
 * - Descarga ese binario con imap_fetchbody
 * - Lo decodifica y lo convierte en data URI (base64)
 *
 * ¿Por qué data URI?
 * - Para que la portada se muestre sin crear archivos en servidor.
 * - OJO: si la imagen es MUY grande, puede afectar rendimiento.
 */
function getFirstImageDataUri($imap, int $msgno): ?array {
    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) return null;

    $found = findFirstImagePart($structure);
    if (!$found) return null;

    $part = $found['part'];
    $partNumber = $found['partNumber'];

    // Traemos el binario de esa parte
    $raw = imap_fetchbody($imap, $msgno, $partNumber);
    if ($raw === false || $raw === null) return null;

    // Decodificar binario
    $bin = decodePart((string)$raw, (int)($part->encoding ?? 0));

    // MIME + filename
    $filename = getPartFilename($part);
    $mime = guessImageMime($part, $filename);

    // data:image/png;base64,....
    return [
        'dataUri' => 'data:' . $mime . ';base64,' . base64_encode($bin),
        'filename' => $filename
    ];
}

/**
 * extractEmailPost:
 * Helper “de alto nivel”:
 * - retorna cuerpo (html/text)
 * - retorna imagen destacada (si hay)
 *
 * Esto es lo que usa index.php para pintar un “post”.
 */
function extractEmailPost($imap, int $msgno): array {
    $bodies = extractBodies($imap, $msgno);
    $image  = getFirstImageDataUri($imap, $msgno);
    return ['html' => $bodies['html'], 'text' => $bodies['text'], 'image' => $image];
}

/* =========================================================
   4) Attachments (NO imágenes) — listar para descarga
   ========================================================= */

/**
 * isAttachmentCandidate:
 * Decide si una parte cuenta como “adjunto descargable”:
 * - Debe tener filename (si no, normalmente es parte técnica)
 * - NO debe ser imagen (las imágenes se listan aparte)
 * - NO debe ser text/* (cuerpos no son adjuntos)
 */
function isAttachmentCandidate($part): bool {
    $filename = getPartFilename($part);
    if (!$filename) return false;

    if (isImagePart($part)) return false;
    if (isset($part->type) && (int)$part->type === 0) return false; // no texto

    return true;
}

/**
 * walkAttachments:
 * Recorre estructura MIME recursivamente y acumula adjuntos.
 *
 * Cada adjunto guardado tiene:
 *  - filename
 *  - partNumber  (ej. "2.1")
 *  - mime        (para Content-Type al descargar)
 *  - encoding    (para decodificar binario)
 */
function walkAttachments($structure, string $prefix, array &$out): void {
    if (!isset($structure->parts)) return;

    foreach ($structure->parts as $index => $part) {
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        if (isset($part->parts)) {
            walkAttachments($part, $partNumber, $out);
            continue;
        }

        if (isAttachmentCandidate($part)) {
            // filename a UTF-8 “bonito” y seguro
            $fn = getPartFilename($part);
            $fn = $fn ? imap_utf8($fn) : 'adjunto.bin';
            $fn = safeFileName((string)$fn);

            $out[] = [
                'filename'   => $fn,
                'partNumber' => $partNumber,
                'mime'       => guessPartMime($part, $fn),
                'encoding'   => (int)($part->encoding ?? 0),
            ];
        }
    }
}

/**
 * listAttachments:
 * Punto de entrada: devuelve array de adjuntos (NO imágenes).
 * Luego index.php usa esto para mostrar la lista + links de descarga.
 */
function listAttachments($imap, int $msgno): array {
    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) return [];
    $out = [];
    walkAttachments($structure, '', $out);
    return $out;
}

/* =========================================================
   5) Images — listar para descarga
   ========================================================= */

/**
 * walkImages:
 * Similar a walkAttachments, pero solo guarda partes imagen.
 *
 * Si una imagen no trae filename:
 * - inventamos uno (imagen-1.jpg, imagen-2.png, etc)
 */
function walkImages($structure, string $prefix, array &$out): void {
    if (!isset($structure->parts)) return;

    foreach ($structure->parts as $index => $part) {
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        if (isset($part->parts)) {
            walkImages($part, $partNumber, $out);
            continue;
        }

        if (isImagePart($part)) {
            $fn = getPartFilename($part);
            $fn = $fn ? imap_utf8($fn) : null;

            $mime = guessImageMime($part, $fn);

            // Si no hay filename, lo inventamos por mime
            if (!$fn) {
                $ext = 'jpg';
                if (strpos($mime, 'image/') === 0) {
                    $ext = substr($mime, 6);
                    if ($ext === 'jpeg') $ext = 'jpg';
                    if ($ext === '') $ext = 'jpg';
                }
                $fn = 'imagen-' . (count($out) + 1) . '.' . $ext;
            }

            $fn = safeFileName((string)$fn);

            $out[] = [
                'filename'   => $fn,
                'partNumber' => $partNumber,
                'mime'       => $mime,
                'encoding'   => (int)($part->encoding ?? 0),
            ];
        }
    }
}

/**
 * listImages:
 * Punto de entrada: lista imágenes del correo.
 * Se usa en:
 * - “Imágenes” dentro del post (para descargarlas)
 * - y en la portada para contar cuántas imágenes tiene.
 */
function listImages($imap, int $msgno): array {
    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) return [];
    $out = [];
    walkImages($structure, '', $out);
    return $out;
}

/* =========================================================
   6) Excerpt helpers (extracto para portada)
   ========================================================= */

/**
 * cleanTextForExcerpt:
 * Convierte HTML a texto “limpio”:
 * - strip_tags: quita HTML
 * - html_entity_decode: convierte &amp; → &
 * - normaliza espacios
 */
function cleanTextForExcerpt(string $htmlOrText): string {
    $plain = strip_tags($htmlOrText);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $plain = preg_replace('/\s+/', ' ', $plain);
    return trim((string)$plain);
}

/**
 * cutText:
 * Corta el texto a X caracteres.
 * - Si existe mb_* usa UTF-8 correcto
 * - Si no, usa substr normal (menos exacto con tildes/emoji)
 */
function cutText(string $text, int $limit): string {
    if ($text === '') return '';

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
        return mb_substr($text, 0, $limit, 'UTF-8') . '…';
    }

    if (strlen($text) <= $limit) return $text;
    return substr($text, 0, $limit) . '...';
}

/* =========================================================
   7) URL helper (conservar parámetros)
   ========================================================= */

/**
 * buildUrl:
 * Construye un URL con querystring, ignorando null/''
 * Ej:
 *   buildUrl('index.php', ['page'=>2,'q'=>'hola'])
 *   => index.php?page=2&q=hola
 */
function buildUrl(string $base, array $params): string {
    $qs = http_build_query(array_filter($params, function ($v) {
        return $v !== null && $v !== '';
    }));
    return $qs ? ($base . '?' . $qs) : $base;
}

/* =========================================================
   8) Stats helpers
   ========================================================= */

/**
 * parseEmailFromFromHeader:
 * Convierte el "From" del correo a una key utilizable para stats.
 *
 * Casos:
 * - "Nombre <mail@dominio.com>" -> mail@dominio.com
 * - "mail@dominio.com" -> mail@dominio.com
 * - cosa rara -> lo devuelve normalizado a minúsculas
 */
function parseEmailFromFromHeader(string $fromRaw): string {
    if (preg_match('/<([^>]+)>/', $fromRaw, $m)) {
        return strtolower(trim($m[1]));
    }
    $fromRaw = trim($fromRaw);
    if (filter_var($fromRaw, FILTER_VALIDATE_EMAIL)) return strtolower($fromRaw);
    return strtolower($fromRaw);
}

/**
 * scanStructureForMediaFlags:
 * Escanea la estructura MIME para saber si el correo tiene:
 * - imágenes (partes tipo imagen)
 * - adjuntos no imagen (candidatos de attachment)
 *
 * Nota:
 * - NO descarga archivos.
 * - Solo mira la estructura (rápido).
 *
 * Retorna:
 *  [
 *    'has_images' => bool,
 *    'has_attachments' => bool,
 *    'images' => int,
 *    'attachments' => int
 *  ]
 */
function scanStructureForMediaFlags($structure): array {
    $hasImages = false;
    $hasAttachments = false;
    $imgCount = 0;
    $attCount = 0;

    /**
     * Recorremos recursivamente porque hay multiparts anidados.
     * Usamos un closure recursivo ($walk) que se llama a sí mismo.
     */
    $walk = function($node) use (&$walk, &$hasImages, &$hasAttachments, &$imgCount, &$attCount) {
        if (!isset($node->parts)) return;

        foreach ($node->parts as $part) {

            // Si tiene subpartes, bajamos un nivel
            if (isset($part->parts)) {
                $walk($part);
                continue;
            }

            // Si es imagen => contamos imagen
            if (isImagePart($part)) {
                $hasImages = true;
                $imgCount++;

            // Si no es imagen, pero sí adjunto descargable => contamos adjunto
            } elseif (isAttachmentCandidate($part)) {
                $hasAttachments = true;
                $attCount++;
            }
        }
    };

    $walk($structure);

    return [
        'has_images'      => $hasImages,
        'has_attachments' => $hasAttachments,
        'images'          => $imgCount,
        'attachments'     => $attCount,
    ];
}

/* =========================================================
   9) Filesystem helper
   ========================================================= */

/**
 * ensureDir:
 * Crea una carpeta si no existe.
 * Se usa para /cache o cualquier carpeta auxiliar.
 */
function ensureDir(string $dir): void {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}
