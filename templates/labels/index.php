<?php /** @var array $labels @var bool $canEdit */ ?>
<div class="page-head">
  <h1>Labels</h1>
  <?php if ($canEdit): ?>
    <a class="btn btn-primary" href="<?= e(url('/labels/new')) ?>">New label</a>
  <?php endif ?>
</div>

<?php if (!$labels): ?>
  <div class="card muted">
    No labels yet.
    <?php if ($canEdit): ?><a href="<?= e(url('/labels/new')) ?>">Create the first one</a>, then add it to hosts from their status page.<?php endif ?>
  </div>
<?php else: ?>
<table class="table">
  <thead><tr><th>Label</th><th>Description</th><th class="num">Hosts</th><th>Updated</th></tr></thead>
  <tbody>
  <?php foreach ($labels as $l): ?>
    <tr>
      <td class="nowrap"><a class="label-chip" href="<?= e(url('/labels/' . $l['id'])) ?>"><?= e($l['name']) ?></a></td>
      <td class="pre-line"><?= e($l['description']) ?></td>
      <td class="num"><?= e($l['hosts']) ?></td>
      <td class="nowrap muted"><?= e(fmt_dt($l['updated_at'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted small"><?= count($labels) ?> label<?= count($labels) === 1 ? '' : 's' ?>. Labels are added to hosts from the host's status page.</p>
<?php endif ?>
