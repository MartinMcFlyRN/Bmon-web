<?php
/** @var array $scope @var array $group @var array $form @var array $errors @var array $checks @var array $groupsByCheck
 * @var array $syntax @var array $hosts @var array $otherHosts @var bool $monitAvailable */
use Bmon\Web\AgentChecks;
use Bmon\Web\AgentTemplates;

$base = $scope['base'];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">AgentConf</a> / <a href="<?= e(url('/agentconf/groups')) ?>">Group Checks</a> /</p>
    <h1><?= e($group['name']) ?></h1>
    <?php if ($group['description'] !== ''): ?><p class="muted"><?= e($group['description']) ?></p><?php endif ?>
  </div>
  <div class="head-actions">
    <a class="btn btn-primary" href="<?= e(url($base . '/new')) ?>">Add check</a>
  </div>
</div>

<section class="panel">
  <h2>Checks <span class="muted">(<?= count($checks) ?>)</span></h2>
  <?php if ($checks): ?>
    <?php if ($syntax['ok'] === true): ?>
      <p class="syntax syntax-ok"><span class="dot dot-ok"></span> monit -t: the enabled checks of the group are valid (on each agent they are also checked together with its own).</p>
    <?php elseif ($syntax['ok'] === false): ?>
      <div class="syntax syntax-fail"><span class="dot dot-fail"></span> monit -t: the enabled checks of the group are <strong>not valid</strong>:
        <pre><?= e($syntax['output']) ?></pre></div>
    <?php endif ?>
    <table class="table">
      <thead><tr><th>File on the agents</th><th>Defines</th><th>Service groups</th><th>Script</th><th>Created from</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php foreach ($checks as $c): $tpl = AgentTemplates::get($c['template']); ?>
        <tr class="<?= (int) $c['enabled'] ? '' : 'row-off' ?>">
          <td class="nowrap"><a class="strong mono" href="<?= e(url($base . '/checks/' . $c['id'])) ?>"><?= e(AgentChecks::fileName($group['slug'] . '.' . $c['name'])) ?></a></td>
          <td><?php foreach ($c['services'] as $s): ?><span class="chip"><?= e($s) ?></span> <?php endforeach ?>
            <?php if (!$c['services']): ?><span class="muted small">Monit settings</span><?php endif ?></td>
          <td><?php foreach ($groupsByCheck[(int) $c['id']] ?? [] as $gn): ?><span class="chip chip-group"><?= e($gn) ?></span> <?php endforeach ?>
            <?php if (empty($groupsByCheck[(int) $c['id']])): ?><span class="muted">—</span><?php endif ?></td>
          <td><?= $c['script_name'] !== '' ? '<span class="mono small">' . e($c['script_name']) . '</span>' : '<span class="muted">—</span>' ?></td>
          <td class="muted"><?= e($tpl['label'] ?? $c['template']) ?></td>
          <td><?= (int) $c['enabled'] ? '<span class="badge badge-ok">Enabled</span>' : '<span class="badge badge-off">Disabled</span>' ?></td>
          <td class="nowrap muted"><?= e(fmt_dt($c['updated_at'])) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <p class="hint"><span class="mono">$HOST</span> in a check becomes the name of each agent, as in Monit.</p>
  <?php else: ?>
    <div class="card muted">No checks yet. <a href="<?= e(url($base . '/new')) ?>">Add the first one from a template</a>.</div>
  <?php endif ?>
</section>

<section class="panel">
  <h2>Agents <span class="muted">(<?= count($hosts) ?>)</span></h2>
  <?php if ($hosts): ?>
  <table class="table table-compact">
    <thead><tr><th>Agent</th><th>Address</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($hosts as $h): ?>
      <tr class="<?= $h['offline_since'] !== null ? 'row-off' : '' ?>">
        <td><a class="strong" href="<?= e(url('/agentconf/' . $h['id'])) ?>"><?= e($h['hostname']) ?></a>
          <?php if ($h['offline_since'] !== null): ?><span class="status status-off">Offline</span><?php endif ?></td>
        <td class="mono"><?= e($h['remote_addr']) ?></td>
        <td class="nowrap">
          <form method="post" action="<?= e(url($base . '/hosts/' . $h['id'] . '/remove')) ?>" data-confirm="Remove <?= e($h['hostname']) ?> from the group?">
            <?= csrf_field() ?><button type="submit" class="btn btn-sm">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">No agents yet.</p>
  <?php endif ?>
  <?php if ($otherHosts): ?>
    <form method="post" action="<?= e(url($base . '/hosts')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <select name="host_id" aria-label="Agent to add">
        <?php foreach ($otherHosts as $h): ?><option value="<?= e($h['id']) ?>"><?= e($h['hostname']) ?></option><?php endforeach ?>
      </select>
      <button type="submit" class="btn">Add agent</button>
    </form>
  <?php endif ?>
  <p class="hint">The agents get the checks of the group with their next zip, or Publish on each agent page (the Auto-update panel shows “Changes not published”).</p>
</section>

<section class="panel">
  <h2>Group</h2>
  <form method="post" action="<?= e(url($base)) ?>" class="card">
    <?= csrf_field() ?>
    <?php require __DIR__ . '/_form.php' ?>
    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</section>

<div class="danger-zone">
  <form method="post" action="<?= e(url($base . '/delete')) ?>"
        data-confirm="Delete the group “<?= e($group['name']) ?>” and its checks? They go away from its agents with the next zip or publication.">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger">Delete group</button>
  </form>
</div>
