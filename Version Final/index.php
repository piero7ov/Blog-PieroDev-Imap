<?php
declare(strict_types=1);

/**
 * index.php — “Blog de correos” (Frontend)
 * ---------------------------------------
 * Idea del proyecto:
 * - Tu INBOX de Gmail es la "base de datos".
 * - Cada correo se muestra como si fuera un post del blog:
 *    - Asunto => título
 *    - From + Date => meta
 *    - Cuerpo (HTML o texto) => contenido
 *    - Primera imagen => portada destacada
 *    - Imágenes y adjuntos => secciones de descarga
 *
 * Este archivo hace dos modos principales:
 * 1) Portada (grid) con extractos y paginación.
 * 2) Vista detalle (post completo) cuando vienes con ?msg=NUM
 *
 * También tiene un modo "descarga" antes de imprimir HTML:
 * - Descargar imágenes:   ?dl_msg=NUM&dl_img=IDX
 * - Descargar adjuntos:   ?dl_msg=NUM&dl=IDX 
 * Cambiar el nombre del archivo config.example.php a config.php cuando lo uses
 */

require __DIR__ . '/config.php';
require __DIR__ . '/lib_imap_blog.php';

/* =========================================================
   1) ENTRADAS (GET) — filtros y navegación
   =========================================================
   Todo lo que el usuario hace desde la web llega como GET.
   - q:     filtro por asunto (buscar)
   - page:  paginación (portada)
   - msg:   ver post completo (msgno del correo)
   - dl_*:  descarga de imagen/adjunto
*/

// ✅ Filtro por asunto (buscador)
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// ✅ Paginación (portada)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// ✅ Vista completa (si viene msg>0, entramos al modo "detalle")
$viewMsg = isset($_GET['msg']) ? (int)$_GET['msg'] : 0;

// ✅ Descarga adjuntos NO imagen: ?dl_msg=NUM&dl=IDX
$dlMsg = isset($_GET['dl_msg']) ? (int)$_GET['dl_msg'] : 0;
$dlIdx = isset($_GET['dl']) ? (int)$_GET['dl'] : -1;

// ✅ Descarga imágenes: ?dl_msg=NUM&dl_img=IDX
$dlImgIdx = isset($_GET['dl_img']) ? (int)$_GET['dl_img'] : -1;

/* =========================================================
   2) CONECTAR A IMAP
   =========================================================
   Abrimos una conexión IMAP (Gmail) usando config.php.
   Si falla, imapOpenOrDie() corta el script con el error.
*/
$inbox = imapOpenOrDie();

// Guardamos el nombre del archivo actual para construir URLs
$self = basename($_SERVER['PHP_SELF']);

/* =========================================================
   3) DESCARGAS (ANTES DEL HTML)
   =========================================================
   Muy importante:
   - Para descargar un archivo, hay que mandar headers HTTP.
   - Los headers solo funcionan si NO hemos imprimido HTML antes.
   Por eso este bloque va al inicio.
*/

/* ---------------------------------------------------------
   3.1) Descargar una imagen del correo
   URL:
     index.php?dl_msg=NUM&dl_img=IDX
   - dl_msg = número del correo (msgno)
   - dl_img = índice dentro del array listImages()
----------------------------------------------------------*/
if ($dlMsg > 0 && $dlImgIdx >= 0) {

    // Listamos imágenes del correo (estructura MIME, no descarga todas)
    $imgs = listImages($inbox, $dlMsg);

    // Validación: si no existe ese índice, 404
    if (!isset($imgs[$dlImgIdx])) {
        http_response_code(404);
        echo "Imagen no encontrada.";
        imap_close($inbox);
        exit;
    }

    // Obtenemos la imagen seleccionada
    $img = $imgs[$dlImgIdx];

    // Bajamos el binario real de ESA parte MIME
    $raw = imap_fetchbody($inbox, $dlMsg, $img['partNumber']);
    if ($raw === false || $raw === null) {
        http_response_code(500);
        echo "No se pudo leer la imagen.";
        imap_close($inbox);
        exit;
    }

    // Decodificamos (base64/quoted-printable) para obtener binario real
    $bin = decodePart((string)$raw, (int)($img['encoding'] ?? 0));

    // Limpieza de buffers (evita corrupción del binario en descargas)
    while (ob_get_level()) { @ob_end_clean(); }

    // Headers de descarga
    header('Content-Type: ' . ($img['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . safeFileName($img['filename']) . '"');
    header('Content-Length: ' . strlen($bin));

    // Enviamos el archivo y terminamos
    echo $bin;
    imap_close($inbox);
    exit;
}

/* ---------------------------------------------------------
   3.2) Descargar un adjunto NO imagen
   URL:
     index.php?dl_msg=NUM&dl=IDX
----------------------------------------------------------*/
if ($dlMsg > 0 && $dlIdx >= 0) {

    // Listamos adjuntos NO imagen del correo
    $atts = listAttachments($inbox, $dlMsg);

    // Validación: si no existe ese índice, 404
    if (!isset($atts[$dlIdx])) {
        http_response_code(404);
        echo "Adjunto no encontrado.";
        imap_close($inbox);
        exit;
    }

    // Adjunto seleccionado
    $att = $atts[$dlIdx];

    // Bajamos el binario real de ESA parte MIME
    $raw = imap_fetchbody($inbox, $dlMsg, $att['partNumber']);
    if ($raw === false || $raw === null) {
        http_response_code(500);
        echo "No se pudo leer el adjunto.";
        imap_close($inbox);
        exit;
    }

    // Decodificamos para obtener el binario
    $bin = decodePart((string)$raw, (int)($att['encoding'] ?? 0));

    while (ob_get_level()) { @ob_end_clean(); }

    // Headers de descarga
    header('Content-Type: ' . ($att['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . safeFileName($att['filename']) . '"');
    header('Content-Length: ' . strlen($bin));

    echo $bin;
    imap_close($inbox);
    exit;
}

/* =========================================================
   4) LISTADO DE CORREOS (PORTADA)
   =========================================================
   Buscamos todos los correos y los ordenamos por fecha (udate).
   Si hay filtro q, se aplica por asunto.
*/
$emails = imap_search($inbox, 'ALL') ?: [];

if (!empty($emails)) {
    $emails_with_dates = [];

    foreach ($emails as $email_number) {

        /**
         * imap_fetch_overview:
         * - devuelve meta del correo (subject/from/date/udate)
         * - IMPORTANTE en tu PHP: el sequence debe ser STRING
         */
        $overview = imap_fetch_overview($inbox, (string)$email_number, 0)[0] ?? null;
        if (!$overview) continue;

        // Si hay búsqueda, filtramos por asunto (case-insensitive)
        if ($q !== '') {
            $subj = isset($overview->subject) ? imap_utf8((string)$overview->subject) : '';
            if (stripos($subj, $q) === false) continue;
        }

        // Guardamos msgno => fecha para ordenar después
        $emails_with_dates[(int)$email_number] = (int)($overview->udate ?? 0);
    }

    // Orden desc (más nuevo primero)
    arsort($emails_with_dates);

    // Nos quedamos con la lista final ordenada de msgnos
    $emails = array_keys($emails_with_dates);
}

/* =========================================================
   5) PAGINACIÓN
   =========================================================
   FRONT_LIMIT viene de config.php (ej. 4 para grid 2x2).
   totalPages = ceil(total / FRONT_LIMIT)
*/
$totalPages = 1;
if (!empty($emails)) {
    $totalPages = (int)ceil(count($emails) / FRONT_LIMIT);
    if ($totalPages < 1) $totalPages = 1;

    // Si alguien pone page muy alto, lo recortamos al máximo
    if ($page > $totalPages) $page = $totalPages;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Correos como blog - PIEROOLIVARES</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="wrap">

  <!-- =======================================================
       HEADER visual (título + redes + acceso al panel)
       ======================================================= -->
  <header class="site-header">
    <div class="corporativo">
      <h1 class="site-title">PIERODEV | Piero Olivares Velasquez</h1>
      <p class="site-subtitle">Programador en formación</p>
    </div>

    <div class="social">
      <a title="Panel" href="<?php echo htmlspecialchars('panel.php', ENT_QUOTES, 'UTF-8'); ?>">
        <img src="logos/home.png" alt="Panel">
      </a>

      <a href="mailto:pieroolivaresdev@gmail.com"><img src="logos/email.png" alt="Email"></a>
      <a href="https://github.com/piero7ov" target="_blank" rel="noopener noreferrer"><img src="logos/github.png" alt="GitHub"></a>
      <a href="https://piero7ov.github.io/Portafolio/" target="_blank" rel="noopener noreferrer"><img src="logos/home.png" alt="Home"></a>
      <a href="https://www.linkedin.com/in/piero7ov/" target="_blank" rel="noopener noreferrer"><img src="logos/linkedin.png" alt="LinkedIn"></a>
      <a href="https://www.youtube.com/@piero7ov" target="_blank" rel="noopener noreferrer"><img src="logos/youtube.png" alt="YouTube"></a>
    </div>
  </header>

  <!-- =======================================================
       6) MODO DETALLE: si viene ?msg=NUM
       ======================================================= -->
  <?php if ($viewMsg > 0): ?>
    <?php
      // URL de vuelta conservando page y q
      $backUrl = buildUrl($self, [
        'page' => ($page > 1 ? $page : null),
        'q'    => ($q !== '' ? $q : null),
      ]);
    ?>
    <a class="back" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">← Volver</a>
    <h1>Post completo</h1>

    <?php
      // Traemos overview del correo a mostrar
      $overview = imap_fetch_overview($inbox, (string)$viewMsg, 0)[0] ?? null;

      if ($overview):

        // “Título” del post = asunto
        $subject = isset($overview->subject) ? imap_utf8((string)$overview->subject) : '(Sin asunto)';

        // “Autor” del post = from
        $from    = isset($overview->from) ? imap_utf8((string)$overview->from) : '(Desconocido)';

        // “Fecha”
        $date    = isset($overview->date) ? (string)$overview->date : '';

        // Extraemos cuerpo + imagen destacada usando la librería
        $post = extractEmailPost($inbox, $viewMsg);

        // Listamos adjuntos e imágenes para mostrarlos en secciones
        $attachments = listAttachments($inbox, $viewMsg);
        $attCount = count($attachments);

        $images = listImages($inbox, $viewMsg);
        $imgCount = count($images);

        /**
         * $contentHtml:
         * - si hay HTML original, lo usamos (pero le quitamos <style>)
         * - si no hay HTML, usamos el texto (ya viene seguro)
         */
        $contentHtml = null;

        if (!empty($post['html'])) {
          $contentHtml = (string)$post['html'];

          // Quitamos estilos embebidos para evitar que rompan tu CSS
          $contentHtml = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $contentHtml);

          // Truco simple: convertir body en div para evitar HTML inválido
          $contentHtml = str_replace('<body', '<div', $contentHtml);
          $contentHtml = str_replace('</body>', '</div>', $contentHtml);

        } elseif (!empty($post['text'])) {
          $contentHtml = (string)$post['text'];

        } else {
          $contentHtml = '<em>Sin contenido legible.</em>';
        }
    ?>
      <article class="post">

        <!-- Portada: primera imagen encontrada -->
        <?php if (!empty($post['image']) && !empty($post['image']['dataUri'])): ?>
          <div class="post-cover">
            <img
              src="<?php echo htmlspecialchars($post['image']['dataUri'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
              alt="<?php echo htmlspecialchars($post['image']['filename'] ?? 'Imagen destacada', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
            >
          </div>
        <?php endif; ?>

        <!-- Header del post -->
        <header class="post-header">
          <h2 class="post-title"><?php echo htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
          <p class="post-meta">
            De: <?php echo htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><br>
            Fecha: <?php echo htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </p>

          <!-- Chips de conteo (visual) -->
          <div class="post-tags">
            <?php if ($imgCount > 0): ?>
              <span class="badge badge-img">🖼️ Imágenes (<?php echo (int)$imgCount; ?>)</span>
            <?php endif; ?>
            <?php if ($attCount > 0): ?>
              <span class="badge badge-att">📎 Adjuntos (<?php echo (int)$attCount; ?>)</span>
            <?php endif; ?>
          </div>
        </header>

        <!-- Contenido del correo -->
        <div class="post-content">
          <?php echo $contentHtml; ?>
        </div>

        <!-- Sección: imágenes descargables -->
        <?php if ($imgCount > 0): ?>
          <div class="attachments">
            <h3>Imágenes</h3>
            <ul class="att-list">
              <?php foreach ($images as $i => $img): ?>
                <?php
                  $dlUrl = buildUrl($self, [
                    'dl_msg' => (int)$viewMsg,
                    'dl_img' => (int)$i
                  ]);
                ?>
                <li class="att-item">
                  <a href="<?php echo htmlspecialchars($dlUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <span><?php echo htmlspecialchars($img['filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                    <span class="att-meta">Descargar</span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- Sección: adjuntos descargables -->
        <?php if ($attCount > 0): ?>
          <div class="attachments">
            <h3>Adjuntos</h3>
            <ul class="att-list">
              <?php foreach ($attachments as $i => $att): ?>
                <?php
                  $dlUrl = buildUrl($self, [
                    'dl_msg' => (int)$viewMsg,
                    'dl'     => (int)$i
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

  <!-- =======================================================
       7) MODO PORTADA (GRID)
       ======================================================= -->
  <?php else: ?>
    <h1>Programador en formación</h1>

    <!-- Buscador por asunto -->
    <form class="searchbar" method="get" action="<?php echo htmlspecialchars($self, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
      <input type="text" name="q"
             value="<?php echo htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
             placeholder="Buscar por asunto…">
      <button type="submit">Buscar</button>

      <?php if ($q !== ''): ?>
        <a class="clear" href="<?php echo htmlspecialchars($self, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Limpiar</a>
        <div class="hint">Mostrando resultados para: “<?php echo htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>”</div>
      <?php endif; ?>
    </form>

    <?php if (!empty($emails)): ?>

      <!-- Grid de posts (portada) -->
      <div class="posts-grid">
        <?php
          // Calculamos el trozo de correos que toca en esta página
          $start = ($page - 1) * FRONT_LIMIT;
          $frontEmails = array_slice($emails, $start, FRONT_LIMIT);

          foreach ($frontEmails as $email_number):

            // Overview (meta)
            $overview = imap_fetch_overview($inbox, (string)$email_number, 0)[0] ?? null;
            if (!$overview) continue;

            // Datos básicos
            $subject = isset($overview->subject) ? imap_utf8((string)$overview->subject) : '(Sin asunto)';
            $from    = isset($overview->from) ? imap_utf8((string)$overview->from) : '(Desconocido)';
            $date    = isset($overview->date) ? (string)$overview->date : '';

            // Extraemos contenido + imagen destacada
            $post = extractEmailPost($inbox, (int)$email_number);

            // Contamos recursos (para chips)
            $attachments = listAttachments($inbox, (int)$email_number);
            $attCount = count($attachments);

            $images = listImages($inbox, (int)$email_number);
            $imgCount = count($images);

            // Para el extracto: preferimos HTML, si no hay, texto
            $rawForExcerpt = '';
            if (!empty($post['html'])) $rawForExcerpt = (string)$post['html'];
            elseif (!empty($post['text'])) $rawForExcerpt = (string)$post['text'];

            // Limpiar + recortar
            $plain = cleanTextForExcerpt($rawForExcerpt);
            $excerpt = cutText($plain, EXCERPT_LEN);

            // Link a vista detalle conservando page y q
            $link = buildUrl($self, [
              'msg'  => (int)$email_number,
              'page' => (int)$page,
              'q'    => ($q !== '' ? $q : null),
            ]);
        ?>
          <article class="post">

            <!-- Portada -->
            <?php if (!empty($post['image']) && !empty($post['image']['dataUri'])): ?>
              <div class="post-cover">
                <img
                  src="<?php echo htmlspecialchars($post['image']['dataUri'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                  alt="<?php echo htmlspecialchars($post['image']['filename'] ?? 'Imagen destacada', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                >
              </div>
            <?php endif; ?>

            <header class="post-header">
              <h2 class="post-title"><?php echo htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
              <p class="post-meta">
                De: <?php echo htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><br>
                Fecha: <?php echo htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
              </p>

              <div class="post-tags">
                <?php if ($imgCount > 0): ?>
                  <span class="badge badge-img">🖼️ Imágenes (<?php echo (int)$imgCount; ?>)</span>
                <?php endif; ?>
                <?php if ($attCount > 0): ?>
                  <span class="badge badge-att">📎 Adjuntos (<?php echo (int)$attCount; ?>)</span>
                <?php endif; ?>
              </div>
            </header>

            <div class="post-excerpt">
              <?php echo htmlspecialchars($excerpt ?: 'Sin contenido.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><br>
              <a class="btn-read" href="<?php echo htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Leer más</a>
            </div>

          </article>
        <?php endforeach; ?>
      </div>

      <!-- Paginación -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php
            $prev = $page - 1;
            $next = $page + 1;

            $prevUrl = buildUrl($self, ['page' => $prev, 'q' => ($q !== '' ? $q : null)]);
            $nextUrl = buildUrl($self, ['page' => $next, 'q' => ($q !== '' ? $q : null)]);
          ?>
          <a class="page-btn <?php echo ($page <= 1 ? 'disabled' : ''); ?>"
             href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">← Anterior</a>

          <span class="page-info">Página <?php echo (int)$page; ?> de <?php echo (int)$totalPages; ?></span>

          <a class="page-btn <?php echo ($page >= $totalPages ? 'disabled' : ''); ?>"
             href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Siguiente →</a>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="empty">
        <?php echo ($q !== '') ? 'No hay correos que coincidan con ese asunto.' : 'No se han encontrado correos.'; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>
</body>
</html>

<?php
// Cerramos conexión IMAP al final (si no se cerró por descargas)
imap_close($inbox);
?>
