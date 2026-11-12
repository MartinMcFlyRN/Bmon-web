<?php
/**
 * @var array $host @var array $check @var array $form @var array $errors @var array|null $syntax
 * @var array|null $tplInfo @var array $services @var bool $monitAvailable
 * @var array|null $scriptSyntax @var string $scriptDir @var string $monitDir
 * @var array $allGroups @var array $selectedGroups
 */
use Bmon\Web\AgentChecks;

$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$base = '/agentconf/' . $host['id'];
?>
<?php if (($cfgProblem = \Bmon\Web\AgentChecks::configProblem()) !== null): ?>
  <div class="alert alert-error"><?= e($cfgProblem) ?></div>
<?php endif ?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">AgentConf</a> / <a href="<?= e(url($base)) ?>"><?= e($host['hostname']) ?></a> /</p>
    <h1 class="mono"><?= e(AgentChecks::fileName($check['name'])) ?></h1>
  </div>
  <a class="btn" href="<?= e(url($base)) ?>">Back to the agent</a>
</div>

<?php if (!empty($errors['_script'])): ?>
  <div class="alert alert-error">The attached script has a syntax error:
    <pre class="monit-out"><?= e($scriptSyntax['output'] ?? '') ?></pre></div>
<?php endif ?>
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
    · created from the template “<?= e($tplInfo['label'] ?? $check['template']) ?>” (this is a copy: editing it changes only this check)
    · <?= $monitAvailable ? 'checked with monit -t when saved' : 'not checked: monit is not installed on this server' ?>.
    Syntax reference: <a href="https://mmonit.com/monit/documentation/monit.html" target="_blank" rel="noopener noreferrer">Monit manual</a>.
  </p>
  <?php if ($services) require __DIR__ . '/_groups.php'; ?>

  <fieldset class="script-box">
    <legend>Attached script <span class="muted small">(optional)</span></legend>
    <p class="hint">A script installed with the zip on the agent, in <span class="mono"><?= e($scriptDir) ?>/</span> (mode 0755).
      Use its path in the configuration above, e.g. <span class="mono">check program … with path "<?= e($scriptDir) ?>/<?= e($form['script_name'] !== '' ? $form['script_name'] : 'check_something.sh') ?>"</span>.</p>
    <label for="script_name">File name</label>
    <div class="input-affix"><span><?= e($scriptDir) ?>/</span><input id="script_name" name="script_name" type="text" maxlength="100"
      value="<?= e($form['script_name'] ?? '') ?>" placeholder="check_something.sh" spellcheck="false"<?= $err('script_name') ?>></div>
    <?php $field = 'script_name'; require __DIR__ . '/../partials/field_error.php' ?>
    <label for="script">Script</label>
    <textarea id="script" name="script" rows="<?= max(6, min(30, substr_count((string) ($form['script'] ?? ''), "\n") + 3)) ?>" class="code"
      spellcheck="false" placeholder="#!/bin/sh&#10;# exit 0 = OK, any other status = alert; the output appears in the alert&#10;"<?= $err('script') ?>><?= e($form['script'] ?? '') ?></textarea>
    <?php $field = 'script'; require __DIR__ . '/../partials/field_error.php' ?>
    <p class="hint">Shell scripts are checked with <span class="mono">sh -n</span> / <span class="mono">bash -n</span> when saved. Windows line endings are converted.
      To remove the script, empty both fields.</p>
  </fieldset>

  <?php if (!empty($errors['_syntax']) || !empty($errors['_script'])): ?>
    <label class="check"><input type="checkbox" name="force" value="1"> Save anyway (e.g. the agents run a newer Monit or shell than this server)</label>
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
