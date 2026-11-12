<?php /** @var array $agents @var array $counts @var bool $all @var int $offlineCount */ ?>
<div class="page-head">
  <h1>AgentConf</h1>
  <a class="btn" href="<?= e(url('/agentconf') . ($all ? '' : '?all=1')) ?>">
    <?= $all ? 'Show only active agents' : 'Show also offline agents (' . e($offlineCount) . ')' ?>
  </a>
</div>
<p class="muted">Prepare the Monit checks of each agent from templates, edit them, and download them as a zip to install in <code>/etc/monit/conf.d/</code>.</p>

<?php if (!$agents): ?>
  <div class="card muted">No <?= $all ? '' : 'active ' ?>agents.</div>
<?php else: ?>
<table class="table">
  <thead><tr><th>Agent</th><th>Address</th><th>Monit</th><th class="num">Reported services</th><th class="num">Bmon checks</th><th>Last report</th></tr></thead>
  <tbody>
  <?php foreach ($agents as $a):
      $c = $counts[(int) $a['id']] ?? null; ?>
    <tr class="<?= $a['offline_since'] !== null ? 'row-off' : '' ?>">
      <td>
        <?php if ($a['offline_since'] !== null): ?><span class="dot dot-off" title="Offline"></span><?php else: ?><span class="dot dot-ok" title="Active"></span><?php endif ?>
        <a class="strong" href="<?= e(url('/agentconf/' . $a['id'])) ?>"><?= e($a['hostname']) ?></a>
        <div class="muted small"><?= e(trim($a['os_name'] . ' ' . $a['os_release'])) ?></div>
      </td>
      <td class="mono"><?= e($a['remote_addr']) ?></td>
      <td><?= e($a['monit_version']) ?></td>
      <td class="num"><?= e($a['services_total']) ?></td>
      <td class="num">
        <?php if ($c === null): ?><span class="muted">—</span>
        <?php else: ?><?= e($c['enabled']) ?><?php if ((int) $c['n'] !== (int) $c['enabled']): ?> <span class="muted small">(+<?= (int) $c['n'] - (int) $c['enabled'] ?> disabled)</span><?php endif ?><?php endif ?>
      </td>
      <td class="nowrap muted"><?= e(since($a['last_seen'])) ?> ago</td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted small"><?= count($agents) ?> agent<?= count($agents) === 1 ? '' : 's' ?>.</p>
<?php endif ?>
