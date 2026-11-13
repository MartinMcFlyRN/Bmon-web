<?php /** @var array $packages */ ?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">Agent</a> /</p>
    <h1>Installer</h1>
  </div>
  <a class="btn btn-primary" href="<?= e(url('/agentconf/installer/new')) ?>">Add package</a>
</div>
<p class="muted">Packages to set up Monit on a new server: the Monit binary, a monitrc, your HTTPS certificate and the required checks
  (Reporting to Bmon, Bmon auto-update, OS information) with their scripts. Each package has a permanent link: download it with
  <span class="mono">wget</span> on the server, extract it in the install directory and start Monit.</p>

<?php if (!$packages): ?>
  <div class="card muted">No packages yet. <a href="<?= e(url('/agentconf/installer/new')) ?>">Add the first one</a>.</div>
<?php else: ?>
<table class="table">
  <thead><tr><th>Package</th><th>Install directory</th><th>Monit binary</th><th>Certificate</th><th class="num">Downloads</th><th>Last download</th></tr></thead>
  <tbody>
  <?php foreach ($packages as $p): ?>
    <tr>
      <td class="nowrap"><a class="strong" href="<?= e(url('/agentconf/installer/' . $p['id'])) ?>"><?= e($p['name']) ?></a></td>
      <td class="mono"><?= e($p['base_dir']) ?></td>
      <td class="small"><?= e($p['monit_bin_info']) ?> · <?= e(number_format((int) $p['monit_bin_size'] / 1048576, 1)) ?> MB</td>
      <td class="small"><?= e($p['pem_info']) ?></td>
      <td class="num"><?= e($p['downloads']) ?></td>
      <td class="nowrap muted"><?= $p['last_download_at'] ? e(fmt_dt($p['last_download_at'])) . ' · ' . e($p['last_download_ip']) : '—' ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
