<?php /** @var array $groups */ ?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">AgentConf</a> /</p>
    <h1>Group Checks</h1>
  </div>
  <a class="btn btn-primary" href="<?= e(url('/agentconf/groups/new')) ?>">New group</a>
</div>
<p class="muted">A group is a set of checks to add to an agent in one go (e.g. “Web server” = nginx, php-fpm, certificate): on the agent
  page, <strong>Add group checks</strong> copies them into it. The copies are independent: changing a group later does not change the agents.</p>

<?php if (!$groups): ?>
  <div class="card muted">No groups yet. <a href="<?= e(url('/agentconf/groups/new')) ?>">Create the first one</a>.</div>
<?php else: ?>
<table class="table">
  <thead><tr><th>Group</th><th>Description</th><th class="num">Checks</th><th>Updated</th></tr></thead>
  <tbody>
  <?php foreach ($groups as $g): ?>
    <tr>
      <td class="nowrap"><a class="strong" href="<?= e(url('/agentconf/groups/' . $g['id'])) ?>"><?= e($g['name']) ?></a></td>
      <td><?= e($g['description']) ?></td>
      <td class="num"><?= e($g['enabled']) ?><?php if ((int) $g['checks'] !== (int) $g['enabled']): ?> <span class="muted small">(+<?= (int) $g['checks'] - (int) $g['enabled'] ?> disabled)</span><?php endif ?></td>
      <td class="nowrap muted"><?= e(fmt_dt($g['updated_at'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
