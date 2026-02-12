<?php
// ----------------------------------------------------
// CONFIGURACIÓN IMAP
// ----------------------------------------------------
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = 'TU_CORREO@gmail.com';
$password = 'TU_PASSWORD_DE_APLICACION';

// ----------------------------------------------------
// AJUSTES DE PORTADA
// ----------------------------------------------------
$EXCERPT_LEN = 220; // X caracteres del extracto en la portada
$FRONT_LIMIT = 4;   // 2x2 = 4 posts

// ✅ Filtro por asunto (solo asunto)
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// ✅ Paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// ✅ Vista completa
$viewMsg = isset($_GET['msg']) ? (int)$_GET['msg'] : 0;

// ✅ Descarga adjuntos: ?dl_msg=NUM&dl=IDX
$dlMsg = isset($_GET['dl_msg']) ? (int)$_GET['dl_msg'] : 0;
$dlIdx = isset($_GET['dl']) ? (int)$_GET['dl'] : -1;

// ----------------------------------------------------
// FUNCIÓN: decodificar contenido según encoding IMAP
// ----------------------------------------------------
function decodePart($content, $encoding)
{
    switch ($encoding) {
        case 3: // BASE64
            return base64_decode($content);
        case 4: // QUOTED-PRINTABLE
            return quoted_printable_decode($content);
        default:
            return $content;
    }
}

// ----------------------------------------------------
// HELPERS: nombre de archivo y detección de imagen
// ----------------------------------------------------
function getPartFilename($part)
{
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $dp) {
            if (isset($dp->attribute) && strtolower($dp->attribute) === 'filename') {
                return $dp->value;
            }
        }
    }

    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (isset($p->attribute) && strtolower($p->attribute) === 'name') {
                return $p->value;
            }
        }
    }

    return null;
}

function isImagePart($part)
{
    if (isset($part->type) && (int)$part->type === 5) {
        return true;
    }

    $filename = getPartFilename($part);
    if ($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
            return true;
        }
    }

    return false;
}

function guessImageMime($part, $filename = null)
{
    if (isset($part->subtype) && $part->subtype) {
        $sub = strtolower($part->subtype);
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

function guessPartMime($part, $filename = null)
{
    $types = [
        0 => 'text',
        1 => 'multipart',
        2 => 'message',
        3 => 'application',
        4 => 'audio',
        5 => 'image',
        6 => 'video',
        7 => 'other'
    ];

    $type = isset($part->type) ? (int)$part->type : 3;
    $base = $types[$type] ?? 'application';

    $sub = '';
    if (isset($part->subtype) && $part->subtype) {
        $sub = strtolower($part->subtype);
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

function safeFileName($name)
{
    $name = (string)$name;
    $name = str_replace(["\r", "\n"], '', $name);
    $name = trim($name);
    if ($name === '') return 'adjunto.bin';
    return basename($name);
}

// ----------------------------------------------------
// FUNCIONES PARA EXTRAER CUERPO E IMAGEN DESTACADA
// ----------------------------------------------------
function walkBodyParts($imap, $msgno, $parts, $prefix, &$out)
{
    foreach ($parts as $index => $part) {
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        if (isset($part->parts)) {
            walkBodyParts($imap, $msgno, $part->parts, $partNumber, $out);
            continue;
        }

        if (isset($part->type) && (int)$part->type === 0) {
            $raw = imap_fetchbody($imap, $msgno, $partNumber);
            $raw = decodePart($raw, $part->encoding ?? 0);

            $subtype = isset($part->subtype) ? strtoupper($part->subtype) : '';

            if ($subtype === 'HTML' && $out['html'] === null) {
                $out['html'] = $raw;
            }

            if ($subtype === 'PLAIN' && $out['text'] === null) {
                $out['text'] = nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
        }
    }
}

function extractBodies($imap, $msgno)
{
    $structure = imap_fetchstructure($imap, $msgno);

    $out = [
        'html' => null,
        'text' => null
    ];

    if (!$structure) return $out;

    if (!isset($structure->parts)) {
        $raw = imap_body($imap, $msgno);
        $raw = decodePart($raw, $structure->encoding ?? 0);

        $subtype = isset($structure->subtype) ? strtoupper($structure->subtype) : '';

        if ($subtype === 'HTML') $out['html'] = $raw;
        else $out['text'] = nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return $out;
    }

    walkBodyParts($imap, $msgno, $structure->parts, '', $out);
    return $out;
}

function findFirstImagePart($structure, $prefix = '')
{
    if (!isset($structure->parts)) return null;

    foreach ($structure->parts as $index => $part) {
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        if (isset($part->parts)) {
            $deep = findFirstImagePart($part, $partNumber);
            if ($deep) return $deep;
            continue;
        }

        if (isImagePart($part)) {
            return [
                'part' => $part,
                'partNumber' => $partNumber
            ];
        }
    }

    return null;
}

function getFirstImageDataUri($imap, $msgno)
{
    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) return null;

    $found = findFirstImagePart($structure);
    if (!$found) return null;

    $part = $found['part'];
    $partNumber = $found['partNumber'];

    $raw = imap_fetchbody($imap, $msgno, $partNumber);
    if ($raw === false || $raw === null) return null;

    $bin = decodePart($raw, $part->encoding ?? 0);

    $filename = getPartFilename($part);
    $mime = guessImageMime($part, $filename);

    return [
        'dataUri' => 'data:' . $mime . ';base64,' . base64_encode($bin),
        'filename' => $filename
    ];
}

function extractEmailPost($imap, $msgno)
{
    $bodies = extractBodies($imap, $msgno);
    $image  = getFirstImageDataUri($imap, $msgno);

    return [
        'html'  => $bodies['html'],
        'text'  => $bodies['text'],
        'image' => $image
    ];
}

// ----------------------------------------------------
// ADJUNTOS (no imagen): listar + descargar
// ----------------------------------------------------
function isAttachmentCandidate($part)
{
    $filename = getPartFilename($part);
    if (!$filename) return false;

    // No queremos contar imágenes aquí (las tratamos como "Imagen")
    if (isImagePart($part)) return false;

    // texto no
    if (isset($part->type) && (int)$part->type === 0) return false;

    return true;
}

function walkAttachments($structure, $prefix, &$out)
{
    if (!isset($structure->parts)) return;

    foreach ($structure->parts as $index => $part) {
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        if (isset($part->parts)) {
            walkAttachments($part, $partNumber, $out);
            continue;
        }

        if (isAttachmentCandidate($part)) {
            $fn = getPartFilename($part);
            $fn = $fn ? imap_utf8($fn) : 'adjunto.bin';
            $fn = safeFileName($fn);

            $mime = guessPartMime($part, $fn);

            $out[] = [
                'filename'   => $fn,
                'partNumber' => $partNumber,
                'mime'       => $mime,
                'encoding'   => $part->encoding ?? 0
            ];
        }
    }
}

function listAttachments($imap, $msgno)
{
    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) return [];

    $out = [];
    walkAttachments($structure, '', $out);
    return $out;
}

// ----------------------------------------------------
// HELPERS: extracto (primeros X caracteres)
// ----------------------------------------------------
function cleanTextForExcerpt($htmlOrText)
{
    $plain = strip_tags($htmlOrText);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $plain = preg_replace('/\s+/', ' ', $plain);
    return trim($plain);
}

function cutText($text, $limit)
{
    if ($text === '') return '';

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
        return mb_substr($text, 0, $limit, 'UTF-8') . '…';
    }

    if (strlen($text) <= $limit) return $text;
    return substr($text, 0, $limit) . '...';
}

// ----------------------------------------------------
// HELPERS: construir URLs conservando q/page
// ----------------------------------------------------
function buildUrl($base, $params)
{
    $qs = http_build_query(array_filter($params, function ($v) {
        return $v !== null && $v !== '';
    }));
    return $qs ? ($base . '?' . $qs) : $base;
}

// ----------------------------------------------------
// CONEXIÓN IMAP
// ----------------------------------------------------
$inbox = @imap_open($hostname, $username, $password);
if (!$inbox) {
    die('Error IMAP: ' . imap_last_error());
}

$self = basename($_SERVER['PHP_SELF']);

// ----------------------------------------------------
// ✅ DESCARGA DE ADJUNTO (antes de HTML)
// ----------------------------------------------------
if ($dlMsg > 0 && $dlIdx >= 0) {
    $atts = listAttachments($inbox, $dlMsg);

    if (!isset($atts[$dlIdx])) {
        http_response_code(404);
        echo "Adjunto no encontrado.";
        imap_close($inbox);
        exit;
    }

    $att = $atts[$dlIdx];

    $raw = imap_fetchbody($inbox, $dlMsg, $att['partNumber']);
    if ($raw === false || $raw === null) {
        http_response_code(500);
        echo "No se pudo leer el adjunto.";
        imap_close($inbox);
        exit;
    }

    $bin = decodePart($raw, $att['encoding'] ?? 0);

    while (ob_get_level()) { @ob_end_clean(); }

    header('Content-Type: ' . ($att['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . safeFileName($att['filename']) . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;

    imap_close($inbox);
    exit;
}

// ----------------------------------------------------
// LECTURA DE CORREOS + filtro por asunto + orden por fecha
// ----------------------------------------------------
$emails = imap_search($inbox, 'ALL');

if ($emails) {
    $emails_with_dates = [];

    foreach ($emails as $email_number) {
        $overview = imap_fetch_overview($inbox, $email_number, 0)[0] ?? null;
        if (!$overview) continue;

        if ($q !== '') {
            $subj = isset($overview->subject) ? imap_utf8($overview->subject) : '';
            if (stripos($subj, $q) === false) continue;
        }

        $emails_with_dates[$email_number] = $overview->udate;
    }

    arsort($emails_with_dates);
    $emails = array_keys($emails_with_dates);
} else {
    $emails = [];
}

// Paginación
$totalPages = 1;
if (!empty($emails)) {
    $totalPages = (int)ceil(count($emails) / $FRONT_LIMIT);
    if ($totalPages < 1) $totalPages = 1;
    if ($page > $totalPages) $page = $totalPages;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- ✅ CLAVE PARA MÓVILES -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correos como blog - PIEROOLIVARES</title>

    <style>
        :root{
            --pad: 1.5rem;
            --gap: 1.25rem;
            --radius: 14px;
        }

        *{ box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #FFFACD;
            color: #222;
            margin: 0;
            padding: var(--pad);
        }

        .wrap { max-width: 980px; margin: 0 auto; }

        /* ---------------------------------------------
           HEADER CORPORATIVO + REDES
        ---------------------------------------------- */
        .site-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap: 1rem;
            padding: 1rem 1.1rem;
            margin-bottom: 1rem;

            background: rgba(255,255,255,.85);
            border: 1px solid rgba(44,62,80,.10);
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.06);
            backdrop-filter: blur(6px);
        }

        .corporativo{ min-width: 0; }

        .site-title{
            margin: 0;
            font-weight: 800;
            color: #2c3e50;
            font-size: clamp(1.1rem, 3.8vw, 1.6rem);
            line-height: 1.1;
            overflow-wrap: anywhere;
        }

        .site-subtitle{
            margin: .35rem 0 0 0;
            color: #6b7280;
            font-size: .95rem;
            line-height: 1.25;
        }

        .social{
            display:flex;
            align-items:center;
            gap: .55rem;
            flex-wrap: wrap;
            justify-content:flex-end;
        }

        .social a{
            width: 40px;
            height: 40px;
            display:grid;
            place-items:center;

            border-radius: 12px;
            background: rgba(255,255,255,.9);
            border: 1px solid rgba(44,62,80,.12);
            box-shadow: 0 8px 18px rgba(0,0,0,.06);

            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        }

        .social a:hover{
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(0,0,0,.10);
            background: #fff;
        }

        .social a:active{ transform: scale(.98); }

        .social img{
            width: 20px;
            height: 20px;
            object-fit: contain;
            filter: grayscale(1) opacity(.85);
            transition: transform .15s ease, filter .15s ease;
        }

        .social a:hover img{
            filter: grayscale(0) opacity(1);
            transform: scale(1.06);
        }

        @media (max-width: 700px){
            .site-header{
                flex-direction: column;
                align-items: flex-start;
            }
            .social{
                width:100%;
                justify-content:flex-start;
            }
        }

        @media (max-width: 520px){
            .social a{ width: 36px; height: 36px; border-radius: 10px; }
            .social img{ width: 18px; height: 18px; }
        }

        /* ---------------------------------------------
           BUSCADOR (solo portada)
        ---------------------------------------------- */
        .searchbar{
            display:flex;
            gap: .6rem;
            align-items:center;
            margin: 0 0 1.25rem 0;
            flex-wrap: wrap;

            background: rgba(255,255,255,.75);
            border: 1px solid rgba(44,62,80,.10);
            border-radius: 14px;
            padding: .75rem .85rem;
            box-shadow: 0 8px 18px rgba(0,0,0,.05);
        }

        .searchbar input{
            flex: 1;
            min-width: 220px;
            padding: .65rem .8rem;
            border-radius: 12px;
            border: 1px solid rgba(44,62,80,.18);
            outline: none;
            font-size: .95rem;
            background: #fff;
        }

        .searchbar input:focus{
            border-color: rgba(85,107,47,.55);
            box-shadow: 0 0 0 3px rgba(85,107,47,.15);
        }

        .searchbar button{
            padding: .65rem .95rem;
            border-radius: 12px;
            border: 0;
            background: #556B2F;
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }

        .searchbar button:hover{ opacity: .92; }

        .searchbar .clear{
            padding: .65rem .9rem;
            border-radius: 12px;
            background: transparent;
            border: 1px solid rgba(44,62,80,.18);
            color: #2c3e50;
            text-decoration: none;
            font-weight: 800;
        }

        .searchbar .hint{
            width: 100%;
            color:#6b7280;
            font-size:.92rem;
            font-weight:700;
        }

        /* ---------------------------------------------
           TÍTULOS / GRID
        ---------------------------------------------- */
        h1 {
            margin: 0 0 1.25rem 0;
            color: #2c3e50;
            font-size: clamp(1.35rem, 4.5vw, 2rem);
            line-height: 1.15;
        }

        .posts-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: var(--gap);
        }

        @media (max-width: 860px) {
            .posts-grid { grid-template-columns: 1fr; }
        }

        /* Post tipo blog */
        .post {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            overflow: hidden;
            min-width: 0;
        }

        /* Imagen destacada */
        .post-cover {
            position: relative;
            background: #e5e7eb;
            overflow: hidden;
        }

        .post-cover img {
            width: 100%;
            height: clamp(180px, 34vw, 280px);
            object-fit: cover;
            display: block;

            transform: scale(1);
            transition: transform .35s ease, filter .35s ease;
            filter: saturate(1.05) contrast(1.02);
        }

        .post-cover::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,0.00), rgba(0,0,0,0.18));
            pointer-events: none;
        }

        .post:hover .post-cover img {
            transform: scale(1.04);
            filter: saturate(1.08) contrast(1.05);
        }

        .post-header {
            padding: 1.1rem 1.35rem .75rem 1.35rem;
        }

        .post-title {
            margin: 0;
            font-size: clamp(1.05rem, 3.2vw, 1.25rem);
            line-height: 1.2;
            color: #2c3e50;
            overflow-wrap: anywhere;
        }

        .post-meta {
            margin: .55rem 0 0 0;
            font-size: .9rem;
            color: #6b7280;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        /* ✅ CHIPS (etiquetas visuales) */
        .post-tags{
            margin-top: .7rem;
            display:flex;
            flex-wrap: wrap;
            gap: .45rem;
        }

        .badge{
            display:inline-flex;
            align-items:center;
            gap:.35rem;
            padding: .25rem .6rem;
            border-radius: 999px;
            font-weight: 800;
            font-size: .78rem;
            border: 1px solid rgba(44,62,80,.15);
            background: rgba(255,255,255,.9);
            color: #2c3e50;
            line-height: 1;
            user-select: none;
        }

        .badge-img{
            border-color: rgba(85,107,47,.28);
            background: rgba(85,107,47,.10);
            color: #3f4f22;
        }

        .badge-att{
            border-color: rgba(44,62,80,.18);
            background: rgba(44,62,80,.06);
            color: #2c3e50;
        }

        /* Portada: extracto */
        .post-excerpt {
            padding: 0 1.35rem 1.35rem 1.35rem;
            color: #1f2937;
            line-height: 1.65;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .btn-read {
            display: inline-block;
            margin-top: .85rem;
            padding: .55rem .9rem;
            border-radius: 10px;
            background: #556B2F;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: .92rem;
            transition: transform .15s ease, opacity .15s ease;
        }

        .btn-read:hover { opacity: .92; }
        .btn-read:active { transform: scale(.98); }

        @media (max-width: 520px){
            :root{ --pad: .9rem; --gap: .9rem; }
            .post-header{ padding: .95rem 1rem .65rem 1rem; }
            .post-excerpt{ padding: 0 1rem 1rem 1rem; }
            .post-content{ padding: 0 1rem 1rem 1rem; }
            .btn-read{
                display:block;
                width:100%;
                text-align:center;
            }
        }

        /* Vista completa */
        .post-content {
            padding: 0 1.35rem 1.35rem 1.35rem;
            color: #1f2937;
            line-height: 1.65;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .post-content table{
            display:block;
            max-width:100%;
            overflow-x:auto;
        }
        .post-content img{
            max-width:100%;
            height:auto;
        }

        .post-content iframe,
        .post-content frame {
            display: none !important;
        }

        .post-content a {
            color: #1a73e8 !important;
            text-decoration: none !important;
        }

        .post-content a:hover {
            text-decoration: underline !important;
        }

        /* Ocultar SOLO imágenes con src="cid:" */
        .post-content img[src^="cid:"],
        .post-content img[src^="CID:"] {
            display: none !important;
        }

        /* ✅ Lista de adjuntos (post completo) */
        .attachments{
            padding: 0 1.35rem 1.35rem 1.35rem;
            margin-top: -0.25rem;
        }

        .attachments h3{
            margin: .5rem 0 .55rem 0;
            color: #2c3e50;
            font-size: 1rem;
        }

        .att-list{
            list-style: none;
            padding: 0;
            margin: 0;
            display:flex;
            flex-direction: column;
            gap: .5rem;
        }

        .att-item a{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: .75rem;

            padding: .65rem .75rem;
            border-radius: 12px;
            background: rgba(255,255,255,.85);
            border: 1px solid rgba(44,62,80,.12);
            text-decoration:none;
            color:#2c3e50;
            font-weight: 800;
        }

        .att-item a:hover{
            background: #fff;
        }

        .att-meta{
            font-weight: 700;
            color: #6b7280;
            font-size: .85rem;
            white-space: nowrap;
        }

        .empty {
            padding: 1rem;
            background: #fff;
            border-radius: 12px;
            color: #6b7280;
            font-weight: 700;
        }

        .back {
            display: inline-block;
            margin: 0 0 1rem 0;
            color: #1a73e8;
            text-decoration: none;
            font-weight: 600;
        }
        .back:hover { text-decoration: underline; }

        /* Paginación */
        .pagination{
            display:flex;
            align-items:center;
            justify-content:center;
            gap:.75rem;
            margin-top: 1.25rem;
            flex-wrap: wrap;
        }
        .page-btn{
            display:inline-block;
            padding: .55rem .9rem;
            border-radius: 10px;
            background: #556B2F;
            color:#fff;
            text-decoration:none;
            font-weight:700;
            transition: opacity .15s ease, transform .15s ease;
        }
        .page-btn:hover{ opacity:.92; }
        .page-btn:active{ transform: scale(.98); }
        .page-info{
            color:#2c3e50;
            font-weight:800;
        }
        .page-btn.disabled{
            opacity:.45;
            pointer-events:none;
        }
    </style>
</head>

<body>
<div class="wrap">

    <header class="site-header">
        <div class="corporativo">
            <h1 class="site-title">PIERODEV | Piero Olivares Velasquez</h1>
            <p class="site-subtitle">Programador en formación</p>
        </div>

        <div class="social">
            <a href="mailto:pieroolivaresdev@gmail.com">
                <img src="logos/email.png" alt="Email">
            </a>
            <a href="https://github.com/piero7ov" target="_blank" rel="noopener noreferrer">
                <img src="logos/github.png" alt="GitHub">
            </a>
            <a href="https://piero7ov.github.io/Portafolio/" target="_blank" rel="noopener noreferrer">
                <img src="logos/home.png" alt="Home">
            </a>
            <a href="https://www.linkedin.com/in/piero7ov/" target="_blank" rel="noopener noreferrer">
                <img src="logos/linkedin.png" alt="LinkedIn">
            </a>
            <a href="https://www.youtube.com/@piero7ov" target="_blank" rel="noopener noreferrer">
                <img src="logos/youtube.png" alt="YouTube">
            </a>
        </div>
    </header>

    <?php if ($viewMsg > 0): ?>
        <?php
            $backUrl = buildUrl($self, [
                'page' => ($page > 1 ? $page : null),
                'q'    => ($q !== '' ? $q : null),
            ]);
        ?>
        <a class="back" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">← Volver</a>
        <h1>Post completo</h1>

        <?php
        $overview = imap_fetch_overview($inbox, $viewMsg, 0)[0] ?? null;

        if ($overview):
            $subject = isset($overview->subject) ? imap_utf8($overview->subject) : '(Sin asunto)';
            $from    = isset($overview->from) ? imap_utf8($overview->from) : '(Desconocido)';
            $date    = isset($overview->date) ? $overview->date : '';

            $subject_safe = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $from_safe    = htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $date_safe    = htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $post = extractEmailPost($inbox, $viewMsg);

            $attachments = listAttachments($inbox, $viewMsg);
            $attCount = count($attachments);

            $contentHtml = null;
            if (!empty($post['html'])) {
                $contentHtml = $post['html'];
                $contentHtml = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $contentHtml);
                $contentHtml = str_replace('<body', '<div', $contentHtml);
                $contentHtml = str_replace('</body>', '</div>', $contentHtml);
            } elseif (!empty($post['text'])) {
                $contentHtml = $post['text'];
            } else {
                $contentHtml = '<em>Sin contenido legible.</em>';
            }
        ?>
            <article class="post">
                <?php if (!empty($post['image']) && !empty($post['image']['dataUri'])): ?>
                    <div class="post-cover">
                        <img
                            src="<?php echo htmlspecialchars($post['image']['dataUri'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                            alt="<?php echo htmlspecialchars($post['image']['filename'] ?? 'Imagen destacada', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                        >
                    </div>
                <?php endif; ?>

                <header class="post-header">
                    <h2 class="post-title"><?php echo $subject_safe; ?></h2>
                    <p class="post-meta">
                        De: <?php echo $from_safe; ?><br>
                        Fecha: <?php echo $date_safe; ?>
                    </p>

                    <div class="post-tags">
                        <?php if (!empty($post['image']) && !empty($post['image']['dataUri'])): ?>
                            <span class="badge badge-img">🖼️ Imagen</span>
                        <?php endif; ?>

                        <?php if ($attCount > 0): ?>
                            <span class="badge badge-att">📎 Adjuntos (<?php echo (int)$attCount; ?>)</span>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="post-content">
                    <?php echo $contentHtml; ?>
                </div>

                <?php if ($attCount > 0): ?>
                    <div class="attachments">
                        <h3>Adjuntos</h3>
                        <ul class="att-list">
                            <?php foreach ($attachments as $i => $att): ?>
                                <?php
                                    $dlUrl = buildUrl($self, [
                                        'dl_msg' => (int)$viewMsg,
                                        'dl'     => (int)$i,
                                    ]);
                                ?>
                                <li class="att-item">
                                    <a href="<?php echo htmlspecialchars($dlUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                        <span><?php echo htmlspecialchars($att['filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                        <span class="att-meta">Descargar</span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </article>
        <?php else: ?>
            <div class="empty">No se encontró ese correo.</div>
        <?php endif; ?>

    <?php else: ?>
        <h1>Programador en formación</h1>

        <!-- ✅ Buscador por asunto -->
        <form class="searchbar" method="get" action="<?php echo htmlspecialchars($self, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <input
                type="text"
                name="q"
                value="<?php echo htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                placeholder="Buscar por asunto…"
            >
            <button type="submit">Buscar</button>

            <?php if ($q !== ''): ?>
                <a class="clear" href="<?php echo htmlspecialchars($self, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Limpiar</a>
                <div class="hint">Mostrando resultados para: “<?php echo htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>”</div>
            <?php endif; ?>
        </form>

        <?php if (!empty($emails)): ?>
            <div class="posts-grid">
                <?php
                $start = ($page - 1) * $FRONT_LIMIT;
                $frontEmails = array_slice($emails, $start, $FRONT_LIMIT);

                foreach ($frontEmails as $email_number):
                    $overview = imap_fetch_overview($inbox, $email_number, 0)[0] ?? null;
                    if (!$overview) continue;

                    $subject = isset($overview->subject) ? imap_utf8($overview->subject) : '(Sin asunto)';
                    $from    = isset($overview->from) ? imap_utf8($overview->from) : '(Desconocido)';
                    $date    = isset($overview->date) ? $overview->date : '';

                    $subject_safe = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $from_safe    = htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $date_safe    = htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    $post = extractEmailPost($inbox, $email_number);

                    // ✅ adjuntos (solo para etiqueta visual y conteo, no descarga aquí)
                    $attachments = listAttachments($inbox, $email_number);
                    $attCount = count($attachments);

                    $rawForExcerpt = '';
                    if (!empty($post['html'])) $rawForExcerpt = $post['html'];
                    elseif (!empty($post['text'])) $rawForExcerpt = $post['text'];

                    $plain = cleanTextForExcerpt($rawForExcerpt);
                    $excerpt = cutText($plain, $EXCERPT_LEN);
                    $excerpt_safe = htmlspecialchars($excerpt ?: 'Sin contenido.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    $link = buildUrl($self, [
                        'msg'  => (int)$email_number,
                        'page' => (int)$page,
                        'q'    => ($q !== '' ? $q : null),
                    ]);
                ?>
                    <article class="post">
                        <?php if (!empty($post['image']) && !empty($post['image']['dataUri'])): ?>
                            <div class="post-cover">
                                <img
                                    src="<?php echo htmlspecialchars($post['image']['dataUri'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    alt="<?php echo htmlspecialchars($post['image']['filename'] ?? 'Imagen destacada', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <header class="post-header">
                            <h2 class="post-title"><?php echo $subject_safe; ?></h2>
                            <p class="post-meta">
                                De: <?php echo $from_safe; ?><br>
                                Fecha: <?php echo $date_safe; ?>
                            </p>

                            <div class="post-tags">
                                <?php if (!empty($post['image']) && !empty($post['image']['dataUri'])): ?>
                                    <span class="badge badge-img">🖼️ Imagen</span>
                                <?php endif; ?>

                                <?php if ($attCount > 0): ?>
                                    <span class="badge badge-att">📎 Adjuntos (<?php echo (int)$attCount; ?>)</span>
                                <?php endif; ?>
                            </div>
                        </header>

                        <div class="post-excerpt">
                            <?php echo $excerpt_safe; ?><br>
                            <a class="btn-read" href="<?php echo htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Leer más</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                        $prev = $page - 1;
                        $next = $page + 1;

                        $prevUrl = buildUrl($self, [
                            'page' => $prev,
                            'q'    => ($q !== '' ? $q : null),
                        ]);

                        $nextUrl = buildUrl($self, [
                            'page' => $next,
                            'q'    => ($q !== '' ? $q : null),
                        ]);
                    ?>

                    <a class="page-btn <?php echo ($page <= 1 ? 'disabled' : ''); ?>"
                       href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        ← Anterior
                    </a>

                    <span class="page-info">Página <?php echo (int)$page; ?> de <?php echo (int)$totalPages; ?></span>

                    <a class="page-btn <?php echo ($page >= $totalPages ? 'disabled' : ''); ?>"
                       href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        Siguiente →
                    </a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty">
                <?php if ($q !== ''): ?>
                    No hay correos que coincidan con ese asunto.
                <?php else: ?>
                    No se han encontrado correos.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>
</body>
</html>
<?php
imap_close($inbox);
?>
