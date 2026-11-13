<?php /** @var array $templates */
$icon = static fn (string $d): string => '<svg class="icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
    . '<path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$pencil = $icon('M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z');
$trash = $icon('M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6');
$clone = $icon('M9 9h11v11H9zM5 15H4V4h11v1');
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">Agent</a> /</p>
    <h1>Custom Templates</h1>
  </div>
  <a class="btn btn-primary" href="<?= e(url('/agentconf/templates/new')) ?>">New template</a>
</div>
<p class="muted">Your own check templates, with fields like the built-in ones. They appear in <strong>Add check</strong> (for agents and
  Group Checks) under “Custom templates”. A check made from a template is a copy: editing or deleting the template does not change it.</p>

<?php if (!$templates): ?>
  <div class="card muted">No custom templates yet. <a href="<?= e(url('/agentconf/templates/new')) ?>">Create the first one</a>.</div>
<?php else: ?>
<div class="toolbar">
  <input type="search" class="filter-input" data-filter="#ct-list" placeholder="Search by name" aria-label="Search custom templates">
</div>
<div id="ct-list">
<table class="table">
  <thead><tr><th>Template</th><th>Description</th><th class="num">Fields</th><th>Script</th><th class="num">Checks made</th><th>Updated</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($templates as $t): ?>
    <tr data-filter-item data-filter-text="<?= e($t['label'] . ' ' . $t['filename']) ?>">
      <td class="nowrap"><a class="strong" href="<?= e(url('/agentconf/templates/' . $t['id'])) ?>"><?= e($t['label']) ?></a>
        <div class="muted small mono">bmon-<?= e($t['filename']) ?>.conf</div></td>
      <td><?= e($t['description']) ?></td>
      <td class="num"><?= e($t['nfields']) ?></td>
      <td class="mono small"><?= $t['script_name'] !== '' ? e($t['script_name']) : '<span class="muted">—</span>' ?></td>
      <td class="num"><?= e($t['usage']) ?></td>
      <td class="nowrap muted"><?= e(fmt_dt($t['updated_at'])) ?><?= $t['updated_by'] !== '' ? ' · ' . e($t['updated_by']) : '' ?></td>
      <td class="nowrap row-actions">
        <a class="icon-btn" href="<?= e(url('/agentconf/templates/' . $t['id'])) ?>" title="Edit" aria-label="Edit <?= e($t['label']) ?>"><?= $pencil ?></a>
        <form method="post" action="<?= e(url('/agentconf/templates/' . $t['id'] . '/clone')) ?>">
          <?= csrf_field() ?><button type="submit" class="icon-btn" title="Clone" aria-label="Clone <?= e($t['label']) ?>"><?= $clone ?></button>
        </form>
        <form method="post" action="<?= e(url('/agentconf/templates/' . $t['id'] . '/delete')) ?>"
              data-confirm="Delete the custom template “<?= e($t['label']) ?>”? The <?= (int) $t['usage'] ?> check<?= (int) $t['usage'] === 1 ? '' : 's' ?> made from it are kept.">
          <?= csrf_field() ?><button type="submit" class="icon-btn icon-danger" title="Delete" aria-label="Delete <?= e($t['label']) ?>"><?= $trash ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted" data-filter-empty hidden>No custom template matches.</p>
</div>
<?php endif ?>
