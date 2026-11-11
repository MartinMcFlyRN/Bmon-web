<?php
/** @var bool $isNew @var array $form @var array $errors @var array|null $label @var array $hosts @var bool|null $canEdit */
$canEdit ??= true;
$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$action = $isNew ? url('/labels') : url('/labels/' . $label['id']);
?>
<div class="page-head">
  <h1><?= $isNew ? 'New label' : 'Label <span class="label-chip label-chip-lg">' . e($label['name']) . '</span>' ?></h1>
  <a class="btn" href="<?= e(url('/labels')) ?>">Back to labels</a>
</div>

<div class="grid-2">
  <?php if ($canEdit): ?>
  <form method="post" action="<?= e($action) ?>" class="card">
    <?= csrf_field() ?>
    <label for="name">Name</label>
    <input id="name" name="name" type="text" maxlength="64" required value="<?= e($form['name']) ?>"<?= $isNew ? ' autofocus' : '' ?><?= $err('name') ?>>
    <?php $field = 'name'; require __DIR__ . '/../partials/field_error.php' ?>

    <label for="description">Description</label>
    <textarea id="description" name="description" rows="4" maxlength="1000"<?= $err('description') ?>><?= e($form['description']) ?></textarea>
    <?php $field = 'description'; require __DIR__ . '/../partials/field_error.php' ?>

    <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create label' : 'Save' ?></button>
  </form>
  <?php else: ?>
  <section class="card">
    <h2>Description</h2>
    <p class="pre-line"><?= $label['description'] !== '' ? e($label['description']) : '<span class="muted">No description.</span>' ?></p>
  </section>
  <?php endif ?>

  <?php if (!$isNew): ?>
  <aside class="card">
    <h2>Hosts with this label <span class="muted">(<?= count($hosts) ?>)</span></h2>
    <?php if (!$hosts): ?>
      <p class="muted">None yet. Add the label from a host's status page.</p>
    <?php else: ?>
      <ul class="host-list">
        <?php foreach ($hosts as $h): ?>
          <li>
            <?php if ($h['offline_since'] !== null): ?><span class="dot dot-off" title="Offline"></span>
            <?php elseif ((int) $h['services_failed'] > 0): ?><span class="dot dot-fail" title="Failing services"></span>
            <?php else: ?><span class="dot dot-ok" title="OK"></span><?php endif ?>
            <a href="<?= e(url('/status/' . $h['id'])) ?>"><?= e($h['hostname']) ?></a>
            <span class="muted small mono"><?= e($h['remote_addr']) ?></span>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>

    <?php if ($canEdit): ?>
      <form method="post" action="<?= e(url('/labels/' . $label['id'] . '/delete')) ?>"
            data-confirm="Delete label “<?= e($label['name']) ?>”?<?= $hosts ? ' It will be removed from ' . count($hosts) . ' host' . (count($hosts) === 1 ? '' : 's') . '.' : '' ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Delete label</button>
      </form>
    <?php endif ?>
  </aside>
  <?php endif ?>
</div>
