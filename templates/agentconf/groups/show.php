<?php
/** @var array $scope @var array $group @var array $form @var array $errors @var array $checks @var array $groupsByCheck
 * @var array $syntax @var bool $monitAvailable */
use Bmon\Web\AgentChecks;
use Bmon\Web\AgentTemplates;

$base = $scope['base'];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">Agent</a> / <a href="<?= e(url('/agentconf/groups')) ?>">Group Checks</a> /</p>
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
      <p class="syntax syntax-ok"><span class="dot dot-ok"></span> monit -t: the enabled checks of the group are valid together (on an agent they are checked again with its own when copied).</p>
    <?php elseif ($syntax['ok'] === false): ?>
      <div class="syntax syntax-fail"><span class="dot dot-fail"></span> monit -t: the enabled checks of the group are <strong>not valid</strong>:
        <pre><?= e($syntax['output']) ?></pre></div>
    <?php endif ?>
    <form method="post" action="<?= e(url($base . '/checks/delete')) ?>" id="bulk-checks" class="bulk-bar" data-bulk
          data-confirm-template="Delete {n} check(s) of this group? Agents that already received a copy keep it.">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-danger" data-bulk-submit disabled>Delete selected checks <span data-bulk-count>(0)</span></button>
    </form>
    <table class="table">
      <thead><tr><th class="col-check"><input type="checkbox" data-bulk-all form="bulk-checks" aria-label="Select all checks" title="Select all checks"></th><th>File</th><th>Defines</th><th>Service groups</th><th>Script</th><th>Created from</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php foreach ($checks as $c): $tpl = AgentTemplates::get($c['template']); ?>
        <tr class="<?= (int) $c['enabled'] ? '' : 'row-off' ?>">
          <td class="col-check"><input type="checkbox" name="ids[]" value="<?= e($c['id']) ?>" form="bulk-checks" data-bulk-item aria-label="Select <?= e(AgentChecks::fileName($c['name'])) ?>"></td>
          <td class="nowrap"><a class="strong mono" href="<?= e(url($base . '/checks/' . $c['id'])) ?>"><?= e(AgentChecks::fileName($c['name'])) ?></a></td>
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
    <p class="hint">On an agent, <strong>Add group checks</strong> copies the chosen checks into it with the same names; the copies are
      independent. <span class="mono">$HOST</span> in a check becomes the name of the agent, as in Monit.</p>
  <?php else: ?>
    <div class="card muted">No checks yet. <a href="<?= e(url($base . '/new')) ?>">Add the first one from a template</a>.</div>
  <?php endif ?>
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
        data-confirm="Delete the group “<?= e($group['name']) ?>” and its checks? The checks already copied into agents stay.">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger">Delete group</button>
  </form>
</div>
