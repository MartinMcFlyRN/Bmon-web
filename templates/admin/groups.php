<?php /** @var array $groups @var array $reported */ ?>
<div class="page-head">
  <h1>Service groups</h1>
  <a class="btn btn-primary" href="<?= e(url('/admin/groups/new')) ?>">New group</a>
</div>
<p class="muted">Monit service groups (<span class="mono">group &lt;name&gt;</span>), assigned to the checks in AgentConf. They let you act on
  several services at once on an agent, e.g. <span class="mono">monit -g web restart</span>.</p>

<?php if (!$groups): ?>
  <div class="card muted">No service groups yet. <a href="<?= e(url('/admin/groups/new')) ?>">Create the first one</a>.</div>
<?php else: ?>
<table class="table">
  <thead><tr><th>Group</th><th>Description</th><th class="num">AgentConf checks</th><th class="num">Reported by Monit</th><th>Updated</th></tr></thead>
  <tbody>
  <?php foreach ($groups as $g): ?>
    <tr>
      <td class="nowrap"><a class="chip chip-group" href="<?= e(url('/admin/groups/' . $g['id'])) ?>"><?= e($g['name']) ?></a></td>
      <td class="pre-line"><?= e($g['description']) ?></td>
      <td class="num"><?= e($g['checks']) ?></td>
      <td class="num"><?= $g['reported_services'] ? e($g['reported_services']) . ' <span class="muted small">on ' . e($g['reported_hosts']) . ' host' . ($g['reported_hosts'] === 1 ? '' : 's') . '</span>' : '<span class="muted">—</span>' ?></td>
      <td class="nowrap muted"><?= e(fmt_dt($g['updated_at'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>

<?php if ($reported): ?>
<section class="panel">
  <h2>Groups reported by the agents, not defined here</h2>
  <p class="hint">Monit already uses these groups on some agents (from their own configuration files).</p>
  <table class="table table-compact">
    <thead><tr><th>Group</th><th class="num">Services</th><th class="num">Hosts</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reported as $r): ?>
      <tr>
        <td class="mono"><?= e($r['name']) ?></td>
        <td class="num"><?= e($r['services']) ?></td>
        <td class="num"><?= e($r['hosts']) ?></td>
        <td class="nowrap">
          <?php if (preg_match(\Bmon\Web\ServiceGroups::NAME_PATTERN, $r['name'])): ?>
            <a class="btn btn-sm" href="<?= e(url('/admin/groups/new') . '?name=' . rawurlencode($r['name'])) ?>">Add</a>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endif ?>
