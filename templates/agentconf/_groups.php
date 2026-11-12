<?php /** @var array $allGroups @var array $selectedGroups */ ?>
<fieldset class="group-picker">
  <legend>Service groups <span class="muted small">(optional)</span></legend>
  <?php if (!$allGroups): ?>
    <p class="hint">No service groups defined yet: create them in <a href="<?= e(url('/admin/groups')) ?>">Admin → Service groups</a>.</p>
  <?php else: ?>
    <div class="group-options">
      <?php foreach ($allGroups as $g): ?>
        <label class="check group-option" title="<?= e($g['description']) ?>">
          <input type="checkbox" name="groups[]" value="<?= e($g['id']) ?>"<?= in_array((int) $g['id'], $selectedGroups, true) ? ' checked' : '' ?>>
          <span class="chip"><?= e($g['name']) ?></span>
        </label>
      <?php endforeach ?>
    </div>
    <p class="hint">Added to the file of the zip as <span class="mono">group &lt;name&gt;</span> lines after the check statement,
      so that the check belongs to these groups in Monit (e.g. <span class="mono">monit -g web restart</span>). Managed in
      <a href="<?= e(url('/admin/groups')) ?>">Admin → Service groups</a>: renaming a group there updates every check.</p>
  <?php endif ?>
</fieldset>
