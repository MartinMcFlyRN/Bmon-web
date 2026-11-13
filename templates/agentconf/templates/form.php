<?php
/** @var int|null $id @var array $t @var array $errors @var array|null $preview @var int $usage */
use Bmon\Web\AgentChecks;
use Bmon\Web\CustomTemplates;

$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$action = $id === null ? '/agentconf/templates/new' : '/agentconf/templates/' . $id;
$row = static function (array $f) : string {
    $o = '';
    foreach (CustomTemplates::TYPES as $k => $tp) {
        $o .= '<option value="' . e($k) . '"' . ($f['type'] === $k ? ' selected' : '') . '>' . e($tp[0]) . '</option>';
    }
    return '<tr class="field-row">'
        . '<td><input name="field_key[]" type="text" value="' . e($f['key']) . '" placeholder="port" maxlength="32" spellcheck="false" class="mono" aria-label="Key"></td>'
        . '<td><input name="field_label[]" type="text" value="' . e($f['label']) . '" placeholder="Port" maxlength="80" aria-label="Label"></td>'
        . '<td><select name="field_type[]" aria-label="Type">' . $o . '</select></td>'
        . '<td><input name="field_default[]" type="text" value="' . e($f['default']) . '" maxlength="200" spellcheck="false" aria-label="Default value"></td>'
        . '<td><select name="field_required[]" aria-label="Required"><option value="1"' . ($f['required'] ? ' selected' : '') . '>Required</option>'
        . '<option value="0"' . (!$f['required'] ? ' selected' : '') . '>Optional</option></select></td>'
        . '<td><input name="field_help[]" type="text" value="' . e($f['help']) . '" maxlength="200" aria-label="Help"></td>'
        . '<td><button type="button" class="btn btn-sm" data-remove-row aria-label="Remove field">×</button></td></tr>';
};
$blank = ['key' => '', 'label' => '', 'type' => 'text', 'default' => '', 'required' => true, 'help' => ''];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">AgentConf</a> / <a href="<?= e(url('/agentconf/templates')) ?>">Custom Templates</a> /</p>
    <h1><?= $id === null ? 'New custom template' : e($t['label']) ?></h1>
  </div>
</div>

<form method="post" action="<?= e(url($action)) ?>" class="card tpl-form">
  <?= csrf_field() ?>
  <div class="grid-2">
    <div>
      <label for="label">Name</label>
      <input id="label" name="label" type="text" required maxlength="80" value="<?= e($t['label']) ?>"<?= $err('label') ?>>
      <?php $field = 'label'; require __DIR__ . '/../../partials/field_error.php' ?>
    </div>
    <div>
      <label for="filename">Default check name</label>
      <div class="input-affix"><span>bmon-</span><input id="filename" name="filename" type="text" required maxlength="64" value="<?= e($t['filename']) ?>" spellcheck="false"<?= $err('filename') ?>><span>.conf</span></div>
      <?php $field = 'filename'; require __DIR__ . '/../../partials/field_error.php' ?>
      <p class="hint">Available in the configuration as <span class="mono">{{name}}</span>; it can be changed when adding the check.</p>
    </div>
  </div>
  <label for="description">Description <span class="muted small">(optional, shown in Add check)</span></label>
  <input id="description" name="description" type="text" maxlength="500" value="<?= e($t['description']) ?>"<?= $err('description') ?>>
  <?php $field = 'description'; require __DIR__ . '/../../partials/field_error.php' ?>

  <h2>Fields</h2>
  <p class="hint">Asked when the check is added. Use them in the configuration as <span class="mono">{{key}}</span>; a line starting with
    <span class="mono">?key </span> is kept only when that optional field is filled. The type decides which values are accepted
    (quotes, spaces and new lines are never allowed, so the values cannot break the configuration).</p>
  <div class="table-wrap">
  <table class="table table-compact fields-table">
    <thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Default</th><th></th><th>Help</th><th></th></tr></thead>
    <tbody data-rows>
      <?php foreach ($t['fields'] as $f) echo $row($f + $blank); ?>
    </tbody>
  </table>
  </div>
  <template data-row-template><?= $row($blank) ?></template>
  <p><button type="button" class="btn btn-sm" data-add-row>Add field</button></p>
  <?php $field = 'fields'; require __DIR__ . '/../../partials/field_error.php' ?>

  <label for="body">Monit configuration</label>
  <textarea id="body" name="body" rows="<?= max(8, min(30, substr_count($t['body'], "\n") + 3)) ?>" class="code" spellcheck="false"<?= $err('body') ?>><?= e($t['body']) ?></textarea>
  <?php $field = 'body'; require __DIR__ . '/../../partials/field_error.php' ?>
  <p class="hint">E.g. <span class="mono">check process {{name}} with pidfile {{pidfile}}</span> · <span class="mono">?timeout     with timeout {{timeout}} seconds</span>.
    <span class="mono">$HOST</span> is the host name, as in Monit. Syntax: <a href="https://mmonit.com/monit/documentation/monit.html" target="_blank" rel="noopener noreferrer">Monit manual</a>.</p>

  <fieldset class="script-box">
    <legend>Attached script <span class="muted small">(optional)</span></legend>
    <p class="hint">Installed with the checks in <span class="mono"><?= e(AgentChecks::scriptDir()) ?>/</span>; refer to it as
      <span class="mono"><?= e(AgentChecks::scriptDir()) ?>/<?= e($t['script_name'] !== '' ? $t['script_name'] : 'check_app.sh') ?></span>.</p>
    <label for="script_name">File name</label>
    <input id="script_name" name="script_name" type="text" maxlength="100" value="<?= e($t['script_name']) ?>" placeholder="check_app.sh" spellcheck="false"<?= $err('script_name') ?>>
    <?php $field = 'script_name'; require __DIR__ . '/../../partials/field_error.php' ?>
    <label for="script">Script</label>
    <textarea id="script" name="script" rows="<?= max(5, min(25, substr_count($t['script'], "\n") + 3)) ?>" class="code" spellcheck="false"<?= $err('script') ?>><?= e($t['script']) ?></textarea>
    <?php $field = 'script'; require __DIR__ . '/../../partials/field_error.php' ?>
  </fieldset>

  <button type="submit" class="btn btn-primary"><?= $id === null ? 'Create template' : 'Save' ?></button>
</form>

<?php if ($preview !== null): ?>
<section class="panel">
  <h2>Preview with the default values</h2>
  <?php if ($preview['syntax']['ok'] === true): ?>
    <p class="syntax syntax-ok"><span class="dot dot-ok"></span> monit -t: valid.</p>
  <?php elseif ($preview['syntax']['ok'] === false): ?>
    <div class="syntax syntax-fail"><span class="dot dot-fail"></span> monit -t rejects it with the default values:
      <pre><?= e($preview['syntax']['output']) ?></pre></div>
  <?php endif ?>
  <pre class="config-preview"><?= e($preview['config']) ?></pre>
</section>
<?php endif ?>

<?php if ($id !== null): ?>
<div class="danger-zone">
  <p class="muted small"><?= $usage ?> check<?= $usage === 1 ? '' : 's' ?> made from this template (they are copies and stay).</p>
  <form method="post" action="<?= e(url('/agentconf/templates/' . $id . '/delete')) ?>" data-confirm="Delete the custom template “<?= e($t['label']) ?>”? The checks made from it are kept.">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger">Delete template</button>
  </form>
</div>
<?php endif ?>
