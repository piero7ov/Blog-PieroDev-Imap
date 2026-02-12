<?php
// ----------------------------------------------------
// CONFIGURACIÓN IMAP
// ----------------------------------------------------
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = 'TU_CORREO@gmail.com';
$password = 'TU_PASSWORD_DE_APLICACION';

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
// FUNCIÓN RECURSIVA: obtener la parte HTML (o texto)
// ----------------------------------------------------
function getHtmlBody($imap, $msgno)
{
    $structure = imap_fetchstructure($imap, $msgno);

    // Correo simple (sin multipart)
    if (!isset($structure->parts)) {
        $body = imap_body($imap, $msgno);
        $body = decodePart($body, $structure->encoding ?? 0);

        // Si el subtipo es HTML lo devolvemos tal cual
        if (isset($structure->subtype) && strtoupper($structure->subtype) === 'HTML') {
            return $body;
        }

        // Si es texto plano, lo convertimos a HTML básico
        return nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    // Correo multipart: buscamos parte HTML
    $htmlBody = null;
    $textBody = null;

    foreach ($structure->parts as $index => $part) {
        $partNumber = $index + 1;

        // Si la parte tiene subpartes (multipart anidado)
        if (isset($part->parts)) {
            // Llamada recursiva: tratamos este subárbol como mensaje
            $subBody = getMultipartBody($imap, $msgno, $part, $partNumber);
            if ($subBody['html'] !== null && $htmlBody === null) {
                $htmlBody = $subBody['html'];
            }
            if ($subBody['text'] !== null && $textBody === null) {
                $textBody = $subBody['text'];
            }
            continue;
        }

        // Solo partes de tipo texto (type 0)
        if ($part->type == 0) {
            $content = imap_fetchbody($imap, $msgno, $partNumber);
            $content = decodePart($content, $part->encoding ?? 0);

            $subtype = isset($part->subtype) ? strtoupper($part->subtype) : '';

            if ($subtype === 'HTML') {
                if ($htmlBody === null) {
                    $htmlBody = $content;
                }
            } elseif ($subtype === 'PLAIN') {
                if ($textBody === null) {
                    $textBody = nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
            }
        }
    }

    // Preferimos HTML; si no, texto plano
    if ($htmlBody !== null) {
        return $htmlBody;
    }
    if ($textBody !== null) {
        return $textBody;
    }

    return '<em>Sin contenido legible.</em>';
}

/**
 * Función auxiliar para recorrer partes multipart anidadas.
 */
function getMultipartBody($imap, $msgno, $structure, $prefix)
{
    $htmlBody = null;
    $textBody = null;

    foreach ($structure->parts as $index => $part) {
        $partNumber = $prefix . '.' . ($index + 1);

        if (isset($part->parts)) {
            $sub = getMultipartBody($imap, $msgno, $part, $partNumber);
            if ($sub['html'] !== null && $htmlBody === null) {
                $htmlBody = $sub['html'];
            }
            if ($sub['text'] !== null && $textBody === null) {
                $textBody = $sub['text'];
            }
            continue;
        }

        if ($part->type == 0) {
            $content = imap_fetchbody($imap, $msgno, $partNumber);
            $content = decodePart($content, $part->encoding ?? 0);
            $subtype = isset($part->subtype) ? strtoupper($part->subtype) : '';

            if ($subtype === 'HTML') {
                if ($htmlBody === null) {
                    $htmlBody = $content;
                }
            } elseif ($subtype === 'PLAIN') {
                if ($textBody === null) {
                    $textBody = nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
            }
        }
    }

    return [
        'html' => $htmlBody,
        'text' => $textBody
    ];
}

// ----------------------------------------------------
// CONEXIÓN IMAP Y LECTURA DE CORREOS
// ----------------------------------------------------
$inbox = @imap_open($hostname, $username, $password);

if (!$inbox) {
    die('Error IMAP: ' . imap_last_error());
}

// Aquí puedes cambiar el criterio, por ejemplo: 'UNSEEN', 'FROM "alguien"', etc.
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
    arsort($emails_with_dates); // Ordenar por fecha descendente
    $emails = array_keys($emails_with_dates);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bandeja de entrada - PIEROOLIVARES</title>
    <style>
        /* Estilos base para la página */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5 !important;
            color: #333 !important;
            margin: 0;
            padding: 1.5rem;
        }

        .email-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .email-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .logo {
            font-weight: bold;
            color: #2c3e50;
            font-size: 1.2rem;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 1.5rem;
        }

        .email-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .email-item {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .email-header-section {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }

        .email-header-section h2 {
            margin: 0;
            font-size: 1.1rem;
            color: #2c3e50;
        }

        .email-meta {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.25rem;
        }

        .email-content {
            padding: 1.5rem;
            color: #333;
            line-height: 1.6;
        }

        /* Estilos para el contenido de los correos */
        .email-content iframe,
        .email-content frame {
            display: none !important;
        }

        .email-content body {
            color: #333 !important;
            background: transparent !important;
            font-family: inherit !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .email-content a {
            color: #1a73e8 !important;
            text-decoration: none !important;
        }

        .email-content a:hover {
            text-decoration: underline !important;
        }

        /* Estilos para la firma en los correos */
        .email-signature {
            font-style: italic;
            color: #666;
            border-top: 1px dashed #ccc;
            padding-top: 1rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <div class="logo">PIEROOLIVARES | Bandeja de entrada</div>
        </div>

        <h1>Correos de la bandeja de entrada</h1>

        <?php if ($emails): ?>
            <div class="email-list">
                <?php
                // Mostrar solo los 20 primeros
                $emails = array_slice($emails, 0, 20);
                foreach ($emails as $email_number): ?>
                    <?php
                    $overview = imap_fetch_overview($inbox, $email_number, 0)[0] ?? null;
                    if (!$overview) continue;

                    $subject = isset($overview->subject) ? imap_utf8($overview->subject) : '(Sin asunto)';
                    $from = isset($overview->from) ? imap_utf8($overview->from) : '(Desconocido)';
                    $date = isset($overview->date) ? $overview->date : '';

                    // Sanitizar para HTML
                    $subject_safe = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $from_safe = htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $date_safe = htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    // Obtener cuerpo
                    $bodyHtml = getHtmlBody($inbox, $email_number);

                    // Procesar el contenido del correo para evitar conflictos de estilo
                    $bodyHtml = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $bodyHtml);
                    $bodyHtml = str_replace('<body', '<div', $bodyHtml);
                    $bodyHtml = str_replace('</body>', '</div>', $bodyHtml);
                    ?>
                    <div class="email-item">
                        <div class="email-header-section">
                            <h2><?php echo $subject_safe; ?></h2>
                            <div class="email-meta">
                                De: <?php echo $from_safe; ?><br>
                                Fecha: <?php echo $date_safe; ?>
                            </div>
                        </div>
                        <div class="email-content">
                            <?php echo $bodyHtml; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No se han encontrado correos.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
imap_close($inbox);
?>
