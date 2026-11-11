<?php /** @var array $items @var bool $canEdit */ ?>
<div class="page-head">
  <h1>Inventory</h1>
  <?php if ($canEdit): ?>
    <a class="btn btn-primary" href="<?= e(url('/inventory/new')) ?>">New item</a>
  <?php endif ?>
</div>

<?php if (!$items): ?>
  <div class="card muted">
    The inventory is empty.
    <?php if ($canEdit): ?><a href="<?= e(url('/inventory/new')) ?>">Create the first item</a>, then add it to hosts from their status page.<?php endif ?>
  </div>
<?php else: ?>
<table class="table">
  <thead><tr><th>Item</th><th>Description</th><th class="num">Hosts</th><th>Updated</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr>
      <td class="nowrap"><a class="inv-chip" href="<?= e(url('/inventory/' . $i['id'])) ?>"><?= e($i['name']) ?></a></td>
      <td class="pre-line"><?= e($i['description']) ?></td>
      <td class="num"><?= e($i['hosts']) ?></td>
      <td class="nowrap muted"><?= e(fmt_dt($i['updated_at'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted small"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>. Items are added to hosts from the host's status page.</p>
<?php endif ?>
