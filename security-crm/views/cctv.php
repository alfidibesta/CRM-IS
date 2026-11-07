<?php
/**
 * CCTV camera wall.
 *
 * Compact by design: one tile per camera with its latest frame, the counters
 * above it, and every heavy section (form, checks, runbook) inside a
 * collapsible panel that remembers whether you left it open.
 */

$statusIn = (string) ($_GET['status'] ?? '');
$zoneIn   = (string) ($_GET['zone'] ?? '');
$q        = trim((string) ($_GET['q'] ?? ''));

$all  = $pdo->query('SELECT * FROM cameras ORDER BY code')->fetchAll();
$rows = array_values(array_filter($all, static function (array $c) use ($statusIn, $zoneIn, $q): bool {
    if ($statusIn !== '' && (string) $c['status'] !== $statusIn) {
        return false;
    }
    if ($zoneIn !== '' && (string) $c['zone'] !== $zoneIn) {
        return false;
    }
    if ($q !== '') {
        $hay = mb_strtolower($c['code'] . ' ' . $c['name'] . ' ' . $c['location'] . ' ' . $c['zone'] . ' ' . $c['notes']);
        if (mb_strpos($hay, mb_strtolower($q)) === false) {
            return false;
        }
    }
    return true;
}));

$sum    = cctv_summary($all);
$health = cctv_health($pdo);
$hs     = cctv_health_summary($health);

$selId = (int) ($_GET['cam'] ?? 0);
$sel   = $selId > 0 ? cctv_camera($pdo, $selId) : null;

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = null;
foreach ($all as $c) {
    if ((int) $c['id'] === $editId) {
        $edit = $c;
        break;
    }
}

$canCapture = can('cctv.capture');
$canManage  = can('cctv.manage');
$ffmpeg     = cctv_ffmpeg_bin();

function cctv_url(array $over): string
{
    $base = ['page' => 'cctv', 'status' => $_GET['status'] ?? '', 'zone' => $_GET['zone'] ?? '', 'q' => trim($_GET['q'] ?? '')];
    return '?' . http_build_query(array_merge($base, $over));
}
?>

<section class="grid grid--stats">
  <article class="card stat stat--blue">
    <p class="stat__label">Cameras</p>
    <p class="stat__value"><?= (int) $sum['total'] ?></p>
    <p class="stat__hint"><?= (int) $sum['streams'] ?> live source(s) · <?= (int) $sum['demo'] ?> demo</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Offline</p>
    <p class="stat__value"><?= (int) $sum['offline'] ?></p>
    <p class="stat__hint"><?= (int) $sum['maintenance'] ?> in maintenance</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Needs attention</p>
    <p class="stat__value"><?= (int) $hs['warn'] + (int) $hs['bad'] ?></p>
    <p class="stat__hint"><?= (int) $hs['ok'] ?> checks passed · <?= (int) $hs['bad'] ?> blocking</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Frame capture</p>
    <p class="stat__value"><?= $ffmpeg !== null ? 'FFmpeg' : 'Fallback' ?></p>
    <p class="stat__hint"><?= $ffmpeg !== null ? 'streams and snapshots enabled' : 'demo frames + HTTP snapshots only' ?></p>
  </article>
</section>

<?php if ($sel): ?>
<section class="card cctv-detail">
  <div class="cctv-detail__shot">
    <img src="<?= e(cctv_thumb($pdo, $sel)) ?>" alt="Latest frame from <?= e($sel['code']) ?>" width="480" height="270">
  </div>
  <div class="cctv-detail__meta">
    <div class="row-actions">
      <h2><?= e($sel['code']) ?> · <?= e($sel['name']) ?></h2>
      <?= badge((string) $sel['status'], cctv_status_tone((string) $sel['status'])) ?>
      <a class="btn btn--ghost btn--xs" href="?page=cctv">Close</a>
    </div>
    <dl class="cctv-facts">
      <div><dt>Location</dt><dd><?= e($sel['location'] ?: '—') ?></dd></div>
      <div><dt>Zone</dt><dd><?= e($sel['zone']) ?></dd></div>
      <div><dt>Source</dt><dd><?= e(cctv_modes()[cctv_effective_mode($sel)]) ?></dd></div>
      <div><dt>URL</dt><dd class="mono"><?= e($sel['stream_url'] ?: 'none — demo frames') ?></dd></div>
      <div><dt>Resolution</dt><dd><?= e($sel['resolution']) ?></dd></div>
      <div><dt>Retention</dt><dd><?= (int) $sel['retention_days'] ?> days</dd></div>
      <div><dt>Last frame</dt><dd><?= e(cctv_age_label($sel['last_frame_at'])) ?></dd></div>
    </dl>
    <?php if ($sel['notes'] !== ''): ?>
      <p class="cctv-note"><?= e($sel['notes']) ?></p>
    <?php endif; ?>
    <div class="row-actions">
      <?php if ($canCapture): ?>
        <form method="post" action="index.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="camera_capture">
          <input type="hidden" name="id" value="<?= (int) $sel['id'] ?>">
          <button type="submit" class="btn btn--primary btn--sm">Capture frame</button>
        </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
        <a class="btn btn--ghost btn--sm" href="?page=cctv&amp;edit=<?= (int) $sel['id'] ?>">Edit camera</a>
      <?php endif; ?>
      <a class="btn btn--ghost btn--sm" href="?page=issues">Issue register</a>
    </div>
  </div>
</section>
<?php endif; ?>

<form class="filters filters--tight" method="get" action="index.php">
  <input type="hidden" name="page" value="cctv">
  <div class="chips">
    <a class="chip <?= $statusIn === '' ? 'is-on' : '' ?>" href="<?= e(cctv_url(['status' => ''])) ?>">All <b><?= (int) $sum['total'] ?></b></a>
    <?php foreach (cctv_status_list() as $s): ?>
      <?php $n = (int) ($sum[strtolower($s)] ?? 0); ?>
      <a class="chip chip--<?= cctv_status_tone($s) ?> <?= $statusIn === $s ? 'is-on' : '' ?>" href="<?= e(cctv_url(['status' => $s])) ?>">
        <?= e($s) ?> <b><?= $n ?></b>
      </a>
    <?php endforeach; ?>
  </div>
  <select name="zone" data-autosubmit>
    <option value="">Every zone</option>
    <?php foreach (cctv_zones() as $z): ?>
      <option value="<?= e($z) ?>" <?= $zoneIn === $z ? 'selected' : '' ?>><?= e($z) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Code, name, location…">
  <button type="submit" class="btn btn--ghost btn--sm">Filter</button>
  <?php if ($canCapture): ?>
    <span class="filters__sep"></span>
  <?php endif; ?>
</form>

<?php if ($canCapture): ?>
<form class="cctv-bar" method="post" action="index.php" data-confirm="Capture a fresh frame from every active camera?">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="camera_capture_all">
  <button type="submit" class="btn btn--primary btn--sm">Capture all frames</button>
  <span class="muted"><?= $ffmpeg !== null ? 'FFmpeg available' : 'FFmpeg not installed — demo and HTTP snapshot cameras only' ?></span>
</form>
<?php endif; ?>

<details class="panel cctv-block" data-collapse="cctv-wall" open>
  <summary class="cctv-block__head">
    <h2>Camera wall</h2>
    <span class="muted"><?= count($rows) ?> of <?= count($all) ?> shown</span>
  </summary>

  <?php if (!$rows): ?>
    <p class="empty empty--sm">No camera matches that filter.</p>
  <?php else: ?>
    <div class="cctv-wall">
      <?php foreach ($rows as $c): ?>
        <article class="cam cam--<?= e(strtolower((string) $c['status'])) ?>">
          <a class="cam__shot" href="?page=cctv&amp;cam=<?= (int) $c['id'] ?>" aria-label="Open <?= e($c['code']) ?>">
            <img src="<?= e(cctv_thumb($pdo, $c)) ?>" alt="Latest frame from <?= e($c['code']) ?>" loading="lazy" width="480" height="270">
            <span class="cam__badge"><span class="cam__led"></span><?= e($c['status']) ?></span>
            <span class="cam__code"><?= e($c['code']) ?></span>
          </a>
          <div class="cam__meta">
            <strong><?= e($c['name']) ?></strong>
            <span><?= e($c['zone']) ?> · <?= e($c['resolution']) ?> · <?= (int) $c['retention_days'] ?>d · <?= e(cctv_age_label($c['last_frame_at'])) ?></span>
          </div>
          <?php if ($canCapture): ?>
            <div class="cam__acts">
              <form method="post" action="index.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="camera_capture">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button type="submit" class="btn btn--ghost btn--xs">Capture</button>
              </form>
              <?php if ($canManage): ?>
                <a class="btn btn--ghost btn--xs" href="?page=cctv&amp;edit=<?= (int) $c['id'] ?>#camera-form">Edit</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</details>

<?php if ($canManage): ?>
<details class="panel cctv-block" id="camera-form" data-collapse="cctv-form" <?= $edit ? 'open' : '' ?>>
  <summary class="cctv-block__head">
    <h2><?= $edit ? 'Edit ' . e((string) $edit['code']) : 'Add a camera' ?></h2>
    <span class="muted"><?= $edit ? 'change the source, zone or retention' : 'register a new view' ?></span>
  </summary>

  <form class="card form" method="post" action="index.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="camera_save">
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="form__grid">
      <label class="field"><span>Code *</span>
        <input type="text" name="code" required maxlength="20" placeholder="CAM-09" value="<?= e((string) ($edit['code'] ?? '')) ?>">
      </label>
      <label class="field"><span>Name *</span>
        <input type="text" name="name" required maxlength="120" placeholder="North perimeter fence" value="<?= e((string) ($edit['name'] ?? '')) ?>">
      </label>
      <label class="field"><span>Location</span>
        <input type="text" name="location" maxlength="120" placeholder="Plant - North Fence" value="<?= e((string) ($edit['location'] ?? '')) ?>">
      </label>
      <label class="field"><span>Zone</span>
        <select name="zone">
          <?php foreach (cctv_zones() as $z): ?>
            <option value="<?= e($z) ?>" <?= (string) ($edit['zone'] ?? '') === $z ? 'selected' : '' ?>><?= e($z) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Source</span>
        <select name="snapshot_mode">
          <?php foreach (cctv_modes() as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= (string) ($edit['snapshot_mode'] ?? 'demo') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field--wide"><span>Stream or snapshot URL</span>
        <input type="text" name="stream_url" maxlength="255" placeholder="rtsp://192.168.10.21:554/Streaming/Channels/101" value="<?= e((string) ($edit['stream_url'] ?? '')) ?>">
        <small class="muted">Stored server-side; the browser never contacts the camera. Only rtsp, rtmp and http(s) addresses are accepted.</small>
      </label>
      <label class="field"><span>Resolution</span>
        <select name="resolution">
          <?php foreach (cctv_resolution_list() as $r): ?>
            <option value="<?= e($r) ?>" <?= (string) ($edit['resolution'] ?? '1080p') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Retention (days)</span>
        <input type="number" name="retention_days" min="1" max="365" value="<?= (int) ($edit['retention_days'] ?? 30) ?>">
        <small class="muted">Older frames are deleted after this many days; each camera's current frame is always kept.</small>
      </label>
      <label class="field"><span>Status</span>
        <select name="status">
          <?php foreach (cctv_status_list() as $s): ?>
            <option value="<?= e($s) ?>" <?= (string) ($edit['status'] ?? 'Online') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field--wide"><span>Notes</span>
        <textarea name="notes" rows="2" maxlength="500" placeholder="What the view covers and any known blind spot"><?= e((string) ($edit['notes'] ?? '')) ?></textarea>
      </label>
    </div>
    <div class="row-actions">
      <button type="submit" class="btn btn--primary btn--sm"><?= $edit ? 'Save camera' : 'Add camera' ?></button>
      <?php if ($edit): ?>
        <a class="btn btn--ghost btn--sm" href="?page=cctv">Cancel</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($edit): ?>
    <form class="cctv-danger" method="post" action="index.php" data-confirm="Remove <?= e((string) $edit['code']) ?> from the wall?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="camera_delete">
      <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
      <button type="submit" class="btn btn--ghost btn--sm btn--danger">Delete this camera</button>
    </form>
  <?php endif; ?>
</details>
<?php endif; ?>

<details class="panel cctv-block" data-collapse="cctv-health" <?= ((int) $hs['bad'] > 0) ? 'open' : '' ?>>
  <summary class="cctv-block__head">
    <h2>System check</h2>
    <span class="muted"><?= (int) $hs['ok'] ?> ok · <?= (int) $hs['warn'] ?> warning · <?= (int) $hs['bad'] ?> blocking</span>
  </summary>

  <div class="card card--flush">
    <table class="table table--tight">
      <thead><tr><th>Check</th><th>State</th><th>Detail</th><th>Fix</th></tr></thead>
      <tbody>
        <?php foreach ($health as $h): ?>
          <tr class="row">
            <td><strong><?= e((string) $h['label']) ?></strong></td>
            <td><?= badge((string) $h['state'], cctv_health_tone((string) $h['state'])) ?></td>
            <td><?= e((string) $h['detail']) ?></td>
            <td class="muted"><?= e((string) $h['fix']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canManage): ?>
    <div class="cctv-bar">
      <form method="post" action="index.php" data-confirm="Delete frames older than each camera's retention? The current frame of every camera is always kept.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="camera_prune">
        <button type="submit" class="btn btn--ghost btn--sm">Clean up old frames</button>
      </form>
      <span class="muted">Sweeps automatically once a day on this page — no cron needed.</span>
    </div>
  <?php endif; ?>
</details> <?= $ffmpeg === null ? 'open' : '' ?>>
  <summary class="cctv-block__head">
    <h2>Add FFmpeg to Laragon</h2>
    <span class="muted"><?= $ffmpeg !== null ? 'already detected' : 'needed for RTSP / RTMP / HLS cameras' ?></span>
  </summary>

  <div class="card cctv-doc">
    <p>The wall works without FFmpeg: HTTP snapshot cameras are fetched with cURL and every other camera shows a generated demo frame. FFmpeg is only needed to pull a still out of a continuous stream.</p>

    <ol class="cctv-steps">
      <li><b>Download a Windows build.</b> Take the <em>release essentials</em> zip from <span class="mono">gyan.dev/ffmpeg/builds</span> (or <span class="mono">github.com/BtbN/FFmpeg-Builds</span>). No installer, no PATH surgery required.</li>
      <li><b>Unpack it into Laragon.</b> Rename the extracted folder to <span class="mono">ffmpeg</span> and place it so the binary sits at<br><span class="mono">C:\laragon\bin\ffmpeg\bin\ffmpeg.exe</span><br>The app already looks there, so no configuration is needed.</li>
      <li><b>Reload Laragon.</b> Menu → <em>Reload</em> (or stop/start Apache) so the new folder is picked up. This page then shows the FFmpeg version under <em>System check</em>.</li>
      <li><b>Verify from a terminal:</b> <span class="mono">C:\laragon\bin\ffmpeg\bin\ffmpeg.exe -version</span> prints one <span class="mono">ffmpeg version …</span> line.</li>
      <li><b>Different location?</b> Point the app at it instead of moving the file: add <span class="mono">SetEnv SECOPS_FFMPEG "C:/tools/ffmpeg/ffmpeg.exe"</span> to <span class="mono">security-crm/.htaccess</span> (or the vhost), or export <span class="mono">SECOPS_FFMPEG</span> for the built-in server. Use forward slashes.</li>
      <li><b>If capture says “proc_open is disabled”:</b> remove <span class="mono">proc_open</span> from <span class="mono">disable_functions</span> in <span class="mono">php.ini</span> and restart Apache — the capture runs as a short-lived child process with an 8-second cap.</li>
    </ol>

    <p class="muted">The web server, not your browser, must be able to reach the camera: test the stream from the same machine that runs Laragon. Credentials in a URL (<span class="mono">rtsp://user:pass@host/…</span>) are stored in the database — prefer a camera account limited to viewing.</p>
  </div>
</details>
