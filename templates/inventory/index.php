<?php /** @var array $items @var int $total @var string $q @var bool $canEdit */ ?>
<div class="page-head">
  <h1>Inventory</h1>
  <?php if ($canEdit): ?>
    <a class="btn btn-primary" href="<?= e(url('/inventory/new')) ?>">New item</a>
  <?php endif ?>
</div>

<?php if ($total > 0): ?>
<div class="toolbar">
  <form method="get" action="<?= e(url('/inventory')) ?>" class="search" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search item or description" aria-label="Search inventory items">
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= e(url('/inventory')) ?>">Clear</a><?php endif ?>
  </form>
</div>
<?php endif ?>

<?php if (!$items && $q !== ''): ?>
  <div class="card muted">No items match “<?= e($q) ?>”.</div>
<?php elseif (!$items): ?>
  <div class="card muted">
    The inventory is empty.
    <?php if ($canEdit): ?><a href="<?= e(url('/inventory/new')) ?>">Create the first item</a>, then add it to hosts from their agent page (Agent → Manage).<?php endif ?>
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
<p class="muted small"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?><?= $q !== '' ? ' of ' . e($total) : '' ?>. Items are added to hosts from their agent page (Agent → Manage → the agent).</p>
<?php endif ?>
