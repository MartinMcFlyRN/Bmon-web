<?php /** @var array $agents @var array $counts @var bool $all @var int $offlineCount @var string $q @var string $sort @var string $dir @var array $updates */
$th = static fn (string $key, string $label, string $class = ''): string
    => sort_th('/agentconf', $key, $label, $sort, $dir, $class);
?>
<div class="page-head">
  <h1>AgentConf</h1>
  <a class="btn" href="<?= e(url('/agentconf') . query_with(['all' => $all ? null : '1'])) ?>">
    <?= $all ? 'Show only active agents' : 'Show also offline agents (' . e($offlineCount) . ')' ?>
  </a>
</div>
<p class="muted">Prepare the Monit checks of each agent from templates, edit them, and download them as a zip to install in <code><?= e(\Bmon\Web\AgentChecks::monitDir()) ?>/</code>.</p>

<div class="toolbar">
  <form method="get" action="<?= e(url('/agentconf')) ?>" class="search" role="search">
    <?php foreach (['all', 'sort', 'dir'] as $keep): if (!empty($_GET[$keep]) && is_string($_GET[$keep])): ?>
      <input type="hidden" name="<?= e($keep) ?>" value="<?= e($_GET[$keep]) ?>">
    <?php endif; endforeach ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search agent, address, OS, Monit version" aria-label="Search agents">
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= e(url('/agentconf') . query_with(['q' => null])) ?>">Clear</a><?php endif ?>
  </form>
</div>

<?php if (!$agents): ?>
  <div class="card muted"><?= $q !== '' ? 'No agents match “' . e($q) . '”.' : 'No ' . ($all ? '' : 'active ') . 'agents.' ?></div>
<?php else: ?>
<table class="table">
  <thead><tr><?= $th('agent', 'Agent') . $th('address', 'Address') . $th('monit', 'Monit') . $th('services', 'Reported services', 'num')
    . $th('checks', 'Bmon checks', 'num') . $th('update', 'Auto-update') . $th('seen', 'Last report') ?></tr></thead>
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
      <td><?php [, $ul, $uc] = \Bmon\Web\Controllers\AgentConfController::updateBadge($updates[(int) $a['id']] ?? null); ?>
        <?= $uc !== '' ? '<span class="badge ' . e($uc) . '">' . e($ul) . '</span>' : '<span class="muted">—</span>' ?></td>
      <td class="nowrap muted"><?= e(since($a['last_seen'])) ?> ago</td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted small"><?= count($agents) ?> agent<?= count($agents) === 1 ? '' : 's' ?>.</p>
<?php endif ?>
