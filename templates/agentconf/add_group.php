<?php
/** @var array $host @var array $groups */
use Bmon\Web\AgentChecks;
use Bmon\Web\AgentTemplates;

$base = '/agentconf/' . $host['id'];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">Agent</a> / <a href="<?= e(url($base)) ?>"><?= e($host['hostname']) ?></a> /</p>
    <h1>Add group checks</h1>
  </div>
  <a class="btn" href="<?= e(url($base)) ?>">Back to the agent</a>
</div>
<p class="muted">Adding a group copies all its checks into <?= e($host['hostname']) ?> (enabled or disabled as in the group). They become
  checks of this agent like the others: later changes to the group do not touch them. If even one of them is already on the agent
  (same name or same script), nothing is added. Groups are managed in <a href="<?= e(url('/agentconf/groups')) ?>">Agent → Group Checks</a>.</p>

<?php if (!$groups): ?>
  <div class="card muted">No groups yet. <a href="<?= e(url('/agentconf/groups/new')) ?>">Create one</a> with the checks you add often.</div>
<?php endif ?>

<?php foreach ($groups as $g): ?>
  <form method="post" action="<?= e(url($base . '/add-group')) ?>" class="card add-group">
    <?= csrf_field() ?>
    <input type="hidden" name="group_id" value="<?= e($g['id']) ?>">
    <h2><?= e($g['name']) ?> <a class="small" href="<?= e(url('/agentconf/groups/' . $g['id'])) ?>">edit group</a></h2>
    <?php if ($g['description'] !== ''): ?><p class="muted"><?= e($g['description']) ?></p><?php endif ?>
    <?php if (!$g['items']): ?>
      <p class="muted">This group has no checks.</p>
    <?php else: ?>
      <table class="table table-compact">
        <thead><tr><th>File</th><th>Created from</th><th>Script</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($g['items'] as $c): $tpl = AgentTemplates::get($c['template']); $clash = $g['clashes'][$c['name']] ?? ''; ?>
          <tr>
            <td class="mono"><?= e(AgentChecks::fileName($c['name'])) ?></td>
            <td class="muted"><?= e($tpl['label'] ?? $c['template']) ?></td>
            <td class="mono small"><?= $c['script_name'] !== '' ? e($c['script_name']) : '<span class="muted">—</span>' ?></td>
            <td><?= (int) $c['enabled'] ? '<span class="badge badge-ok">Enabled</span>' : '<span class="badge badge-off">Disabled</span>' ?></td>
            <td class="small"><?= $clash !== '' ? '<span class="text-fail">' . e($clash) . '</span>' : '' ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php if ($g['clashes']): ?>
        <p class="hint text-fail"><?= count($g['clashes']) ?> of these checks are already on the agent: the group cannot be added until they are deleted or renamed there.</p>
      <?php endif ?>
      <button type="submit" class="btn btn-primary">Add the group (<?= count($g['items']) ?> check<?= count($g['items']) === 1 ? '' : 's' ?>)</button>
    <?php endif ?>
  </form>
<?php endforeach ?>
