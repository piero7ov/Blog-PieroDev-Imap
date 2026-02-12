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

// Si viene ?msg=NUM, se muestra el post completo
$viewMsg = isset($_GET['msg']) ? (int)$_GET['msg'] : 0;

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
    // filename en dparameters
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $dp) {
            if (isset($dp->attribute) && strtolower($dp->attribute) === 'filename') {
                return $dp->value;
            }
        }
    }

    // name en parameters
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
    // IMAP: type 5 suele ser IMAGE
    if (isset($part->type) && (int)$part->type === 5) {
        return true;
    }

    // A veces una imagen llega como "application" con nombre .png/.jpg, etc.
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
    // Si el subtype viene claro (png/jpeg/gif/etc.)
    if (isset($part->subtype) && $part->subtype) {
        $sub = strtolower($part->subtype);
        if ($sub === 'jpg') $sub = 'jpeg';
        return 'image/' . $sub;
    }

    // Si no hay subtype, lo intentamos por extensión
    if ($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'jpg') $ext = 'jpeg';
        return 'image/' . ($ext ?: 'jpeg');
    }

    return 'image/jpeg';
}

// ----------------------------------------------------
// FUNCIONES PARA EXTRAER CUERPO E IMAGEN DESTACADA
// ----------------------------------------------------
/**
 * Extrae cuerpo HTML / texto y primera imagen encontrada.
 * Devuelve:
 * [
 *   'html'  => string|null,
 *   'text'  => string|null (HTML-safe),
 *   'image' => ['dataUri' => string, 'filename' => string]|null
 * ]
 */

// Recorre partes para capturar html/plain
function walkBodyParts($imap, $msgno, $parts, $prefix, &$out)
{
    foreach ($parts as $index => $part) {
        $partNumber = ($prefix === '') ? (string)($index + 1) : $prefix . '.' . ($index + 1);

        // Multipart anidado
        if (isset($part->parts)) {
            walkBodyParts($imap, $msgno, $part->parts, $partNumber, $out);
            continue;
        }

        // Solo texto
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

    if (!$structure) {
        return $out;
    }

    // Mensaje simple (no multipart)
    if (!isset($structure->parts)) {
        $raw = imap_body($imap, $msgno);
        $raw = decodePart($raw, $structure->encoding ?? 0);

        $subtype = isset($structure->subtype) ? strtoupper($structure->subtype) : '';

        if ($subtype === 'HTML') {
            $out['html'] = $raw;
        } else {
            $out['text'] = nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $out;
    }

    // Multipart
    walkBodyParts($imap, $msgno, $structure->parts, '', $out);

    return $out;
}

// Encuentra la primera parte que sea imagen (first found)
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
    $image = getFirstImageDataUri($imap, $msgno);

    return [
        'html'  => $bodies['html'],
        'text'  => $bodies['text'],
        'image' => $image
    ];
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
// CONEXIÓN IMAP Y LECTURA DE CORREOS
// ----------------------------------------------------
$inbox = @imap_open($hostname, $username, $password);

if (!$inbox) {
    die('Error IMAP: ' . imap_last_error());
}

// Criterio: todos
$emails = imap_search($inbox, 'ALL');

// Ordenar correos por fecha (más recientes primero)
if ($emails) {
    $emails_with_dates = [];
    foreach ($emails as $email_number) {
        $overview = imap_fetch_overview($inbox, $email_number, 0)[0] ?? null;
        if ($overview) {
            $emails_with_dates[$email_number] = $overview->udate;
        }
    }
    arsort($emails_with_dates);
    $emails = array_keys($emails_with_dates);
} else {
    $emails = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Correos como blog - PIEROOLIVARES</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f3f4f6;
            color: #222;
            margin: 0;
            padding: 1.5rem;
        }

        .wrap {
            max-width: 980px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .brand {
            font-weight: bold;
            color: #2c3e50;
            font-size: 1.6rem;
        }

        h1 {
            margin: 0 0 1.25rem 0;
            color: #2c3e50;
            font-size: 2rem;
        }

        /* ✅ PORTADA: GRID 2x2 */
        .posts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }
        @media (max-width: 860px) {
            .posts-grid { grid-template-columns: 1fr; }
        }

        /* Post tipo blog */
        .post {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        /* ✅ Imagen destacada con más CSS (borde/overlay/hover) */
        .post-cover {
            position: relative;
            background: #e5e7eb;
            overflow: hidden; /* importante para el borde redondeado */
        }

        .post-cover img {
            width: 100%;
            height: 280px;
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
            font-size: 1.25rem;
            line-height: 1.2;
            color: #2c3e50;
        }

        .post-meta {
            margin: .55rem 0 0 0;
            font-size: .9rem;
            color: #6b7280;
            line-height: 1.35;
        }

        /* ✅ Portada: solo extracto */
        .post-excerpt {
            padding: 0 1.35rem 1.35rem 1.35rem;
            color: #1f2937;
            line-height: 1.65;
        }

        .btn-read {
            display: inline-block;
            margin-top: .85rem;
            padding: .55rem .9rem;
            border-radius: 10px;
            background: #1a73e8;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: .92rem;
            transition: transform .15s ease, opacity .15s ease;
        }

        .btn-read:hover { opacity: .92; }
        .btn-read:active { transform: scale(.98); }

        /* Vista completa */
        .post-content {
            padding: 0 1.35rem 1.35rem 1.35rem;
            color: #1f2937;
            line-height: 1.65;
        }

        /* Evitar embeds raros dentro del body del email */
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

        /* ✅ Ocultar SOLO imágenes con src="cid:..." (evita icono roto) */
        .post-content img[src^="cid:"],
        .post-content img[src^="CID:"] {
            display: none !important;
        }

        .empty {
            padding: 1rem;
            background: #fff;
            border-radius: 12px;
            color: #6b7280;
        }

        .back {
            display: inline-block;
            margin: 0 0 1rem 0;
            color: #1a73e8;
            text-decoration: none;
            font-weight: 600;
        }
        .back:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="brand">PIERODEV | Piero Olivares Velasquez</div>
    </div>

    <?php if ($viewMsg > 0): ?>
        <!-- ✅ VISTA COMPLETA -->
        <a class="back" href="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">← Volver</a>
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

            // Contenido completo: html > text > mensaje
            $contentHtml = null;
            if (!empty($post['html'])) {
                $contentHtml = $post['html'];
                // Limpieza básica (igual que tu código)
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
                </header>

                <div class="post-content">
                    <?php echo $contentHtml; ?>
                </div>
            </article>
        <?php else: ?>
            <div class="empty">No se encontró ese correo.</div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ✅ PORTADA (GRID 2x2 + EXTRACTO + LEER MÁS) -->
        <h1>Programador en formación</h1>

        <?php if (!empty($emails)): ?>
            <div class="posts-grid">
                <?php
                // Solo 4 posts (2x2)
                $frontEmails = array_slice($emails, 0, $FRONT_LIMIT);

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

                    // Extracto: usar html si existe; si no text
                    $rawForExcerpt = '';
                    if (!empty($post['html'])) $rawForExcerpt = $post['html'];
                    elseif (!empty($post['text'])) $rawForExcerpt = $post['text'];

                    $plain = cleanTextForExcerpt($rawForExcerpt);
                    $excerpt = cutText($plain, $EXCERPT_LEN);
                    $excerpt_safe = htmlspecialchars($excerpt ?: 'Sin contenido.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    $link = basename($_SERVER['PHP_SELF']) . '?msg=' . (int)$email_number;
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
                        </header>

                        <div class="post-excerpt">
                            <?php echo $excerpt_safe; ?><br>
                            <a class="btn-read" href="<?php echo htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Leer más</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty">No se han encontrado correos.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
<?php
imap_close($inbox);
?>
