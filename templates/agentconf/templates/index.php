<?php /** @var array $templates */ ?>
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
  <thead><tr><th>Template</th><th>Description</th><th class="num">Fields</th><th>Script</th><th class="num">Checks made</th><th>Updated</th></tr></thead>
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
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted" data-filter-empty hidden>No custom template matches.</p>
</div>
<?php endif ?>
