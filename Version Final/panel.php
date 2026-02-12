<?php
declare(strict_types=1);

/**
 * panel.php — Mini Panel de Estadísticas (IMAP → métricas)
 * -------------------------------------------------------
 * ¿Qué hace este archivo?
 * 1) Se conecta a Gmail (IMAP) usando la misma config del blog.
 * 2) Calcula estadísticas "tipo dashboard":
 *    - Total correos
 *    - No leídos
 *    - Correos por mes (últimos 12)
 *    - Top remitentes
 *    - Correos con imágenes / adjuntos (según estructura MIME)
 *
 * ¿Cómo evita ir lento?
 * - Usa CACHE en /cache/stats.json con un TTL (tiempo de vida).
 * - Si el cache está vigente, NO se conecta a IMAP para recalcular.
 * - Solo recalcula si:
 *   a) El cache expiró
 *   b) O el usuario fuerza refresh con ?refresh=1
 *
 * Seguridad opcional:
 * - Si config.php define PANEL_TOKEN, se exige ?token=... para entrar.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/lib_imap_blog.php';

/* =========================================================
   1) SEGURIDAD OPCIONAL (TOKEN)
   =========================================================
   - Si PANEL_TOKEN está vacío (''), NO se exige token.
   - Si PANEL_TOKEN tiene valor, se exige que el usuario pase:
       panel.php?token=TU_TOKEN
   - hash_equals() evita ataques por timing (comparación segura).
*/
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if (PANEL_TOKEN !== '' && hash_equals(PANEL_TOKEN, $token) === false) {
    http_response_code(403);
    die('Acceso denegado (token inválido).');
}

/* =========================================================
   2) ¿FORZAR RECÁLCULO?
   =========================================================
   - Si el usuario entra con ?refresh=1, se ignora el cache
     y se recalcula todo desde IMAP.
*/
$refresh = isset($_GET['refresh']) ? 1 : 0;

/* =========================================================
   3) CACHE: preparar carpeta/archivo
   =========================================================
   - Guardamos stats en /cache/stats.json
   - ensureDir crea la carpeta si no existe.
*/
ensureDir(__DIR__ . '/cache');
$cacheFile = __DIR__ . '/cache/stats.json';

// Variables de control del cache
$cacheOk = false; // indica si el cache es válido y se va a usar
$cached = null;   // acá guardamos el array leído desde JSON

/* =========================================================
   4) INTENTAR LEER CACHE (si no hay refresh)
   =========================================================
   Reglas:
   - Si el archivo existe y no está vencido (TTL):
       lo leemos y lo usamos.
   - Si está vencido o no existe:
       recalculamos en IMAP.
*/
if (!$refresh && file_exists($cacheFile)) {

    // Edad del cache en segundos
    $age = time() - (int)filemtime($cacheFile);

    // Si no superó el TTL, seguimos
    if ($age <= STATS_CACHE_TTL) {
        $raw = file_get_contents($cacheFile);
        $data = json_decode((string)$raw, true);

        // Solo usamos cache si JSON decodifica bien a array
        if (is_array($data)) {
            $cached = $data;
            $cacheOk = true;
        }
    }
}

/* =========================================================
   5) FUNCIÓN PRINCIPAL: computeStats($imap)
   =========================================================
   Esta función es la que "hace el trabajo pesado":
   - Busca correos ALL y UNSEEN
   - Ordena correos por fecha
   - Escanea SOLO los últimos N correos (STATS_MAX_EMAILS)
     para evitar lentitud (esto es clave).
   - Calcula métricas:
       - conteo por mes (Y-m)
       - top remitentes
       - conteo de imágenes/adjuntos (sin bajar binarios)
*/
function computeStats($imap): array
{
    /* -------------------------
       5.1) Listas básicas rápidas
       -------------------------
       imap_search devuelve un array de msgno (número de mensaje).
       - ALL: todos
       - UNSEEN: no leídos
    */
    $all = imap_search($imap, 'ALL') ?: [];
    $unseen = imap_search($imap, 'UNSEEN') ?: [];

    /* -------------------------
       5.2) Ordenar por fecha (udate)
       -------------------------
       Necesitamos orden "más nuevo primero".
       Para eso:
       - Por cada msgno obtenemos overview (udate)
       - Lo guardamos en array
       - arsort() para ordenar descendente (nuevo → viejo)
    */
    $emails_with_dates = [];

    foreach ($all as $msgno) {
        // ⚠️ Importante: en tu PHP imap_fetch_overview requiere sequence STRING
        $ov = imap_fetch_overview($imap, (string)$msgno, 0)[0] ?? null;
        if (!$ov) continue;

        // udate es timestamp unix
        $emails_with_dates[(int)$msgno] = (int)($ov->udate ?? 0);
    }

    // Ordenar por fecha desc
    arsort($emails_with_dates);

    // msgnos ya ordenados (más nuevo primero)
    $sortedMsgnos = array_keys($emails_with_dates);

    /* -------------------------
       5.3) LIMITAR ESCANEO (performance)
       -------------------------
       No escaneamos 10.000 correos porque sería lento.
       Escaneamos SOLO los últimos N (STATS_MAX_EMAILS).
    */
    $scanList = array_slice($sortedMsgnos, 0, STATS_MAX_EMAILS);

    /* -------------------------
       5.4) Estructuras donde acumulamos datos
       ------------------------- */
    $byMonth = [];   // 'YYYY-MM' => count
    $bySender = [];  // 'email@dominio.com' => count

    // métricas de “media” (imágenes / adjuntos)
    $media = [
        'with_images' => 0,       // correos que tienen al menos 1 imagen
        'with_attachments' => 0,  // correos que tienen al menos 1 adjunto NO imagen
        'images_total' => 0,      // total imágenes (sumadas)
        'attachments_total' => 0, // total adjuntos no imagen (sumados)
    ];

    /* -------------------------
       5.5) Escaneo por correo
       -------------------------
       En este loop calculamos:
       - mes del correo (Y-m)
       - remitente
       - estructura MIME para detectar imágenes/adjuntos
    */
    foreach ($scanList as $msgno) {

        // overview para obtener from + fecha
        $ov = imap_fetch_overview($imap, (string)$msgno, 0)[0] ?? null;
        if (!$ov) continue;

        // --- A) Conteo por mes ---
        $ts = (int)($ov->udate ?? time());
        $monthKey = date('Y-m', $ts);
        $byMonth[$monthKey] = ($byMonth[$monthKey] ?? 0) + 1;

        // --- B) Conteo por remitente ---
        $fromRaw = isset($ov->from) ? imap_utf8((string)$ov->from) : '(Desconocido)';
        // parseEmailFromFromHeader() convierte "Nombre <email@x.com>" en "email@x.com"
        $senderKey = parseEmailFromFromHeader((string)$fromRaw);
        $bySender[$senderKey] = ($bySender[$senderKey] ?? 0) + 1;

        // --- C) Detección de imágenes/adjuntos ---
        // OJO: imap_fetchstructure trae metadata de partes MIME
        // No descargamos archivos, solo miramos estructura (rápido).
        $structure = imap_fetchstructure($imap, (int)$msgno);

        if ($structure) {
            // scanStructureForMediaFlags() está en lib_imap_blog.php
            // y te devuelve:
            // - has_images (bool), images (int)
            // - has_attachments (bool), attachments (int)
            $flags = scanStructureForMediaFlags($structure);

            if (!empty($flags['has_images'])) {
                $media['with_images']++;
            }
            if (!empty($flags['has_attachments'])) {
                $media['with_attachments']++;
            }

            $media['images_total'] += (int)($flags['images'] ?? 0);
            $media['attachments_total'] += (int)($flags['attachments'] ?? 0);
        }
    }

    /* -------------------------
       5.6) Top remitentes
       -------------------------
       Ordenamos desc y sacamos top 10.
    */
    arsort($bySender);
    $topSenders = array_slice($bySender, 0, 10, true);

    /* -------------------------
       5.7) Últimos 12 meses (aunque haya meses vacíos)
       -------------------------
       Esto hace que siempre se vea un gráfico consistente:
       - Si un mes no tiene correos, aparece con 0.
    */
    $months = [];
    $now = new DateTime('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
        $m = (clone $now)->modify("-$i months")->format('Y-m');
        $months[$m] = $byMonth[$m] ?? 0;
    }

    /* -------------------------
       5.8) Resultado final (array)
       -------------------------
       computed_at sirve para mostrar la última actualización.
    */
    return [
        'computed_at' => time(),
        'total_emails' => count($all),
        'unseen_emails' => count($unseen),
        'scanned_emails' => count($scanList),
        'months' => $months,
        'top_senders' => $topSenders,
        'media' => $media,
    ];
}

/* =========================================================
   6) DECIDIR: ¿Usamos cache o recalculamos?
   ========================================================= */
if ($cacheOk) {
    // cache válido → usamos datos guardados
    $stats = $cached;
} else {
    // cache vencido o no existe → recalculamos
    $inbox = imapOpenOrDie();
    $stats = computeStats($inbox);
    imap_close($inbox);

    // Guardar en JSON para próximas visitas
    file_put_contents(
        $cacheFile,
        json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/* =========================================================
   7) Helper para barras (porcentaje)
   =========================================================
   Convierte un valor a % respecto a un máximo.
   Ej: value 50, max 200 => 25%
*/
function pct(int $value, int $max): int
{
    if ($max <= 0) return 0;
    return (int)round(($value / $max) * 100);
}

/* =========================================================
   8) URLs del panel
   =========================================================
   - backUrl: regresar al blog
   - refreshUrl: forzar recalcular (incluye token si aplica)
*/
$self = basename($_SERVER['PHP_SELF']);
$backUrl = 'index.php';

$refreshUrl = buildUrl($self, [
    'refresh' => 1,
    'token'   => (PANEL_TOKEN !== '' ? $token : null),
]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel - Estadísticas</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="wrap">

  <!-- =======================================================
       HEADER DEL PANEL: título, última actualización y botones
       ======================================================= -->
  <div class="panel-top">
    <div>
      <h1 style="margin-bottom:.25rem;">Panel · Estadísticas</h1>
      <div class="small-note">
        Última actualización:
        <?php echo htmlspecialchars(date('Y-m-d H:i:s', (int)$stats['computed_at']), ENT_QUOTES, 'UTF-8'); ?>
        · Cache TTL: <?php echo (int)STATS_CACHE_TTL; ?>s
      </div>
    </div>

    <div class="panel-actions">
      <a class="btn secondary" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">← Volver al blog</a>
      <a class="btn" href="<?php echo htmlspecialchars($refreshUrl, ENT_QUOTES, 'UTF-8'); ?>">Actualizar ahora</a>
    </div>
  </div>

  <!-- =======================================================
       TARJETAS RESUMEN (KPIs)
       ======================================================= -->
  <div class="cards">
    <div class="card">
      <div class="label">Total correos (ALL)</div>
      <div class="value"><?php echo (int)$stats['total_emails']; ?></div>
    </div>

    <div class="card">
      <div class="label">No leídos (UNSEEN)</div>
      <div class="value"><?php echo (int)$stats['unseen_emails']; ?></div>
    </div>

    <div class="card">
      <div class="label">Escaneados para stats</div>
      <div class="value"><?php echo (int)$stats['scanned_emails']; ?></div>
    </div>

    <div class="card">
      <div class="label">Correos con imágenes (escaneo)</div>
      <div class="value"><?php echo (int)($stats['media']['with_images'] ?? 0); ?></div>
    </div>
  </div>

  <!-- =======================================================
       BLOQUE 1: Correos por mes (últimos 12)
       ======================================================= -->
  <div class="block">
    <h2>Correos por mes (últimos 12)</h2>

    <?php
      // Lista YYYY-MM => count
      $months = $stats['months'] ?? [];

      // Máximo mensual (para escalar barras al 100%)
      $maxMonth = 0;
      foreach ($months as $k => $v) {
        $maxMonth = max($maxMonth, (int)$v);
      }
    ?>

    <div class="bars">
      <?php foreach ($months as $month => $count): ?>
        <?php
          // Porcentaje relativo respecto al mes con más correos
          $p = pct((int)$count, $maxMonth);
        ?>
        <div class="bar-row">
          <div class="bar-label"><?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="bar"><span style="width: <?php echo (int)$p; ?>%;"></span></div>
          <div class="bar-val"><?php echo (int)$count; ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="small-note">
      Nota: el conteo mensual se calcula sobre los últimos
      <?php echo (int)$stats['scanned_emails']; ?> correos escaneados
      (para no hacer el panel lento).
    </div>
  </div>

  <!-- =======================================================
       BLOQUE 2: Top remitentes
       ======================================================= -->
  <div class="block">
    <h2>Top remitentes (Top 10)</h2>

    <?php
      $senders = $stats['top_senders'] ?? [];
      $maxSender = 0;
      foreach ($senders as $k => $v) {
        $maxSender = max($maxSender, (int)$v);
      }
    ?>

    <div class="bars">
      <?php foreach ($senders as $sender => $count): ?>
        <?php $p = pct((int)$count, $maxSender); ?>
        <div class="bar-row">
          <div class="bar-label"><?php echo htmlspecialchars($sender, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="bar"><span style="width: <?php echo (int)$p; ?>%;"></span></div>
          <div class="bar-val"><?php echo (int)$count; ?></div>
        </div>
      <?php endforeach; ?>

      <?php if (empty($senders)): ?>
        <div class="small-note">No hay datos de remitentes.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- =======================================================
       BLOQUE 3: Media (imagenes/adjuntos)
       ======================================================= -->
  <div class="block">
    <h2>Media (escaneo de estructura)</h2>

    <div class="small-note">
      Con adjuntos: <b><?php echo (int)($stats['media']['with_attachments'] ?? 0); ?></b> ·
      Total adjuntos detectados: <b><?php echo (int)($stats['media']['attachments_total'] ?? 0); ?></b><br>

      Con imágenes: <b><?php echo (int)($stats['media']['with_images'] ?? 0); ?></b> ·
      Total imágenes detectadas: <b><?php echo (int)($stats['media']['images_total'] ?? 0); ?></b>
    </div>
  </div>

</div>
</body>
</html>
