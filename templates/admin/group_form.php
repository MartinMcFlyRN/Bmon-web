<?php
/** @var bool $isNew @var array $form @var array $errors @var array|null $group @var array $checks */
$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$action = $isNew ? url('/admin/groups') : url('/admin/groups/' . $group['id']);
?>
<div class="page-head">
  <h1><?= $isNew ? 'New service group' : 'Service group <span class="chip chip-group chip-lg">' . e($group['name']) . '</span>' ?></h1>
  <a class="btn" href="<?= e(url('/admin/groups')) ?>">Back to service groups</a>
</div>

<div class="grid-2">
  <form method="post" action="<?= e($action) ?>" class="card">
    <?= csrf_field() ?>
    <label for="name">Name</label>
    <input id="name" name="name" type="text" maxlength="64" required value="<?= e($form['name']) ?>" spellcheck="false"<?= $isNew ? ' autofocus' : '' ?><?= $err('name') ?>>
    <?php $field = 'name'; require __DIR__ . '/../partials/field_error.php' ?>
    <p class="hint">As written in monitrc: letters, digits, dot, dash, underscore; no spaces.</p>
    <label for="description">Description</label>
    <textarea id="description" name="description" rows="3" maxlength="1000"<?= $err('description') ?>><?= e($form['description']) ?></textarea>
    <?php $field = 'description'; require __DIR__ . '/../partials/field_error.php' ?>
    <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create group' : 'Save' ?></button>
  </form>

  <?php if (!$isNew): ?>
  <aside class="card">
    <h2>AgentConf checks in this group <span class="muted">(<?= count($checks) ?>)</span></h2>
    <?php if (!$checks): ?>
      <p class="muted">None. Choose the group when adding or editing a check in AgentConf.</p>
    <?php else: ?>
      <ul class="host-list">
        <?php foreach ($checks as $c): ?>
          <li><a class="mono" href="<?= e(url('/agentconf/' . $c['host_id'] . '/checks/' . $c['id'])) ?>">bmon-<?= e($c['name']) ?>.conf</a>
            <span class="muted small">on <?= e($c['hostname'] ?? ('host ' . $c['host_id'])) ?></span></li>
        <?php endforeach ?>
      </ul>
      <p class="hint">Renaming the group changes it in all these checks from the next zip.</p>
    <?php endif ?>
    <form method="post" action="<?= e(url('/admin/groups/' . $group['id'] . '/delete')) ?>"
          data-confirm="Delete the service group “<?= e($group['name']) ?>”?<?= $checks ? ' It will be removed from ' . count($checks) . ' check' . (count($checks) === 1 ? '' : 's') . '.' : '' ?>">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-danger">Delete group</button>
    </form>
  </aside>
  <?php endif ?>
</div>
