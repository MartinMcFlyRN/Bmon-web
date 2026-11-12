<?php
/**
 * @var array $host @var array $check @var array $form @var array $errors @var array|null $syntax
 * @var array|null $template @var array $services @var bool $monitAvailable
 */
use Bmon\Web\AgentChecks;

$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$base = '/agentconf/' . $host['id'];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">AgentConf</a> / <a href="<?= e(url($base)) ?>"><?= e($host['hostname']) ?></a> /</p>
    <h1 class="mono"><?= e(AgentChecks::fileName($check['name'])) ?></h1>
  </div>
  <a class="btn" href="<?= e(url($base)) ?>">Back to the agent</a>
</div>

<?php if (!empty($errors['_syntax'])): ?>
  <div class="alert alert-error">monit -t rejected the configuration<?= (int) $form['enabled'] ? ' (together with the other enabled checks of this agent)' : '' ?>:
    <pre class="monit-out"><?= e($syntax['output'] ?? '') ?></pre></div>
<?php endif ?>

<form method="post" action="<?= e(url($base . '/checks/' . $check['id'])) ?>" class="card edit-check">
  <?= csrf_field() ?>
  <div class="edit-row">
    <div>
      <label for="name">File name</label>
      <div class="input-affix"><span>bmon-</span><input id="name" name="name" type="text" maxlength="64" required value="<?= e($form['name']) ?>" spellcheck="false"<?= $err('name') ?>><span>.conf</span></div>
      <?php $field = 'name'; require __DIR__ . '/../partials/field_error.php' ?>
    </div>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= (int) $form['enabled'] ? ' checked' : '' ?>> Enabled (included in the zip)</label>
  </div>

  <label for="config">Monit configuration</label>
  <textarea id="config" name="config" rows="<?= max(8, min(30, substr_count($form['config'], "\n") + 3)) ?>" class="code" spellcheck="false"<?= $err('config') ?>><?= e($form['config']) ?></textarea>
  <?php $field = 'config'; require __DIR__ . '/../partials/field_error.php' ?>
  <p class="hint">
    Defines: <?= $services ? e(implode(', ', $services)) : 'no service (Monit settings)' ?>
    · from template “<?= e($template['label'] ?? $check['template']) ?>”
    · <?= $monitAvailable ? 'checked with monit -t when saved' : 'not checked: monit is not installed on this server' ?>.
    Syntax reference: <a href="https://mmonit.com/monit/documentation/monit.html" target="_blank" rel="noopener noreferrer">Monit manual</a>.
  </p>
  <?php if (!empty($errors['_syntax'])): ?>
    <label class="check"><input type="checkbox" name="force" value="1"> Save anyway (e.g. the agents run a newer Monit than this server)</label>
  <?php endif ?>
  <button type="submit" class="btn btn-primary">Save</button>
</form>

<div class="danger-zone">
  <p class="muted small">Created <?= e(fmt_dt($check['created_at'])) ?> · updated <?= e(fmt_dt($check['updated_at'])) ?><?= $check['updated_by'] !== '' ? ' by ' . e($check['updated_by']) : '' ?></p>
  <form method="post" action="<?= e(url($base . '/checks/' . $check['id'] . '/delete')) ?>"
        data-confirm="Delete <?= e(AgentChecks::fileName($check['name'])) ?>? It disappears from the host when you install the next zip.">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger">Delete check</button>
  </form>
</div>
