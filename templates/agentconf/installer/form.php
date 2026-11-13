<?php
/** @var int|null $id @var array $p @var array $errors @var array|null $built @var string|null $url @var array $mismatches @var bool $encrypted */
use Bmon\Web\AgentPackages;

$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$action = $id === null ? '/agentconf/installer/new' : '/agentconf/installer/' . $id;
$base = $p['base_dir'] ?: '/usr/local/monit';
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">Agent</a> / <a href="<?= e(url('/agentconf/installer')) ?>">Installer</a> /</p>
    <h1><?= $id === null ? 'Add package' : e($p['name']) ?></h1>
  </div>
</div>

<?php if ($url !== null): ?>
<section class="panel">
  <h2>Link</h2>
  <div class="card">
    <p class="hint">Permanent: it does not expire. Anyone with it gets the private key of the certificate and the httpd password,
      so share it only with who installs the servers, and create a new one if it leaks.</p>
    <div class="copy-row"><input id="pkg-url" type="text" readonly value="<?= e($url) ?>" class="mono" aria-label="Package link">
      <button type="button" class="btn" data-copy="pkg-url">Copy</button></div>
    <h3>Install on a new server</h3>
    <?php $cmd = AgentPackages::installCommands($p, $url); ?>
    <div class="copy-row"><textarea id="pkg-cmd" readonly rows="<?= substr_count($cmd, "\n") + 1 ?>" class="code" aria-label="Install commands"><?= e($cmd) ?></textarea>
      <button type="button" class="btn" data-copy="pkg-cmd">Copy</button></div>
    <p class="hint">Downloaded <?= (int) ($p['downloads'] ?? 0) ?> times<?= !empty($p['last_download_at']) ? ', last ' . e(fmt_dt($p['last_download_at'])) . ' from ' . e($p['last_download_ip']) : '' ?>.
      The last command starts Monit at boot (cron @reboot of root).</p>
    <form method="post" action="<?= e(url('/agentconf/installer/' . $id . '/relink')) ?>" data-confirm="Create a new link? The current one stops working at once.">
      <?= csrf_field() ?><button type="submit" class="btn btn-sm">New link</button>
    </form>
  </div>
</section>
<?php endif ?>

<?php if ($mismatches): ?>
  <div class="alert alert-error">The servers installed with this package keep Monit in <span class="mono"><?= e($base) ?></span>, but Admin → Configuration says otherwise:
    <?php foreach ($mismatches as [$label, $want, $have]): ?><br>· <?= e($label) ?>: <span class="mono"><?= e($have ?: '(empty)') ?></span>, should be <span class="mono"><?= e($want) ?></span><?php endforeach ?>
    <br>The checks of the package use the right paths anyway, but the next zips and publications for these agents use the Configuration: set it as shown.</div>
<?php endif ?>

<form method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" class="card pkg-form">
  <?= csrf_field() ?>
  <div class="grid-2">
    <div>
      <label for="name">Name</label>
      <input id="name" name="name" type="text" required maxlength="64" value="<?= e($p['name']) ?>" placeholder="Debian x86-64"<?= $err('name') ?>>
      <?php $field = 'name'; require __DIR__ . '/../../partials/field_error.php' ?>
    </div>
    <div>
      <label for="base_dir">Install directory</label>
      <input id="base_dir" name="base_dir" type="text" required maxlength="200" value="<?= e($p['base_dir']) ?>" class="mono" spellcheck="false"<?= $err('base_dir') ?>>
      <?php $field = 'base_dir'; require __DIR__ . '/../../partials/field_error.php' ?>
      <p class="hint">The zip has bin/, conf/, conf.d/ and script/ for this directory.</p>
    </div>
  </div>

  <div class="grid-2">
    <div>
      <label for="monit_bin">Monit binary <?= $id !== null ? '<span class="muted small">(leave empty to keep ' . e($p['monit_bin_info']) . ', ' . e($p['monit_bin_name']) . ')</span>' : '' ?></label>
      <input id="monit_bin" name="monit_bin" type="file"<?= $id === null ? ' required' : '' ?><?= $err('monit_bin') ?>>
      <?php $field = 'monit_bin'; require __DIR__ . '/../../partials/field_error.php' ?>
      <p class="hint">The <span class="mono">bin/monit</span> of the Monit release for the servers' architecture (max 32 MB).</p>
    </div>
    <div>
      <label for="pem">Certificate monit.pem <?= $id !== null ? '<span class="muted small">(leave empty to keep it)</span>' : '' ?></label>
      <input id="pem" name="pem" type="file" accept=".pem,.crt,.key,text/plain"<?= $id === null ? ' required' : '' ?><?= $err('pem') ?>>
      <?php $field = 'pem'; require __DIR__ . '/../../partials/field_error.php' ?>
      <p class="hint">Private key + certificate in one file (<span class="mono">cat host.key host.crt &gt; monit.pem</span>), for HTTPS of Monit httpd.
        <?php if ($id !== null && !empty($p['pem_info'])): ?><br>Now: <?= e($p['pem_info']) ?><?php endif ?></p>
    </div>
  </div>

  <div class="grid-3">
    <div>
      <label for="httpd_user">httpd user</label>
      <input id="httpd_user" name="httpd_user" type="text" required maxlength="32" value="<?= e($p['httpd_user']) ?>" spellcheck="false"<?= $err('httpd_user') ?>>
      <?php $field = 'httpd_user'; require __DIR__ . '/../../partials/field_error.php' ?>
    </div>
    <div>
      <label for="httpd_password">httpd password <?= $id !== null ? '<span class="muted small">(empty = keep)</span>' : '' ?></label>
      <input id="httpd_password" name="httpd_password" type="text" maxlength="64" value="<?= e($p['httpd_password']) ?>" spellcheck="false" autocomplete="off"<?= $err('httpd_password') ?>>
      <?php $field = 'httpd_password'; require __DIR__ . '/../../partials/field_error.php' ?>
    </div>
    <div>
      <label for="httpd_port">httpd port</label>
      <input id="httpd_port" name="httpd_port" type="number" min="1" max="65535" required value="<?= e($p['httpd_port']) ?>"<?= $err('httpd_port') ?>>
      <?php $field = 'httpd_port'; require __DIR__ . '/../../partials/field_error.php' ?>
    </div>
  </div>
  <p class="hint">The other values of the checks (collector URL and credentials, Bmon-web address, auto-update interval) come from
    Admin → Configuration and the templates when the zip is downloaded.<?= $encrypted ? ' The private key and the password are stored encrypted.' : ' Set monit.credentials_key to store the private key and the password encrypted.' ?></p>
  <button type="submit" class="btn btn-primary">Save</button>
</form>

<?php if ($built !== null): ?>
<section class="panel">
  <h2>Contents of the zip</h2>
  <?php if ($built['errors']): ?>
    <div class="alert alert-error">The zip cannot be built: <?= e(implode(' ', $built['errors'])) ?></div>
  <?php else: ?>
    <?php if (($built['syntax']['ok'] ?? null) === true): ?>
      <p class="syntax syntax-ok"><span class="dot dot-ok"></span> monit -t: the checks are valid.</p>
    <?php elseif (($built['syntax']['ok'] ?? null) === false): ?>
      <div class="syntax syntax-fail"><span class="dot dot-fail"></span> monit -t rejects the checks: <pre><?= e($built['syntax']['output']) ?></pre></div>
    <?php endif ?>
    <ul class="mono small pkg-files">
      <li>bin/monit <span class="muted">(<?= e($p['monit_bin_info']) ?>)</span></li>
      <li>conf/monitrc</li>
      <li>conf/monit.pem</li>
      <?php foreach ($built['checks'] as $c): ?><li>conf.d/<?= e($c['file']) ?></li><?php endforeach ?>
      <?php foreach ($built['scripts'] as $s): ?><li>script/<?= e($s['name']) ?></li><?php endforeach ?>
    </ul>
    <details><summary>conf/monitrc</summary><pre class="config-preview"><?= e(AgentPackages::monitrc($base)) ?></pre></details>
    <?php foreach ($built['checks'] as $c): ?>
      <details><summary>conf.d/<?= e($c['file']) ?></summary><pre class="config-preview"><?= e(preg_replace('/(allow\s+[A-Za-z0-9._-]+:)"[^"]*"/', '$1"••••••"', preg_replace('#(https?://[^:/@\s]+:)[^@\s]+@#', '$1••••••@', $c['config']))) ?></pre></details>
    <?php endforeach ?>
  <?php endif ?>
</section>

<div class="danger-zone">
  <form method="post" action="<?= e(url('/agentconf/installer/' . $id . '/delete')) ?>" data-confirm="Delete the package “<?= e($p['name']) ?>”? Its link stops working.">
    <?= csrf_field() ?><button type="submit" class="btn btn-danger">Delete package</button>
  </form>
</div>
<?php endif ?>
