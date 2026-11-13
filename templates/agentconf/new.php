<?php
/**
 * @var array $scope @var string $templateKey @var array|null $tpl @var array $values @var array $errors
 * @var array|null $syntax @var string $fromReported @var array $existing
 * @var array $allGroups @var array $selectedGroups
 */
use Bmon\Web\AgentTemplates;

$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$base = $scope['base'];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><?php foreach ($scope['crumbs'] as [$cu, $cl]): ?><a href="<?= e(url($cu)) ?>"><?= e($cl) ?></a> / <?php endforeach ?></p>
    <h1><?= $tpl === null ? 'Add a check' : 'New check: ' . e($tpl['label']) ?></h1>
  </div>
  <?php if ($tpl !== null): ?>
    <a class="btn" href="<?= e(url($base . '/new')) ?>">Other templates</a>
  <?php endif ?>
</div>

<?php if ($tpl === null): ?>
  <p class="muted">Choose a template. After adding it, the configuration can be edited freely.</p>
  <?php
    $groups = [];
    foreach (AgentTemplates::all() as $key => $t) {
        $groups[$t['category']][$key] = $t;
    }
  ?>
  <?php foreach (AgentTemplates::CATEGORIES as $cat => $catLabel): if (empty($groups[$cat])) continue; ?>
    <section class="panel">
      <h2><?= e($catLabel) ?></h2>
      <div class="tpl-grid">
        <?php foreach ($groups[$cat] as $key => $t): ?>
          <a class="tpl-card" href="<?= e(url($base . '/new') . '?template=' . rawurlencode($key)) ?>">
            <span class="tpl-title"><?= e($t['label']) ?></span>
            <span class="tpl-desc"><?= e($t['description']) ?></span>
          </a>
        <?php endforeach ?>
      </div>
    </section>
  <?php endforeach ?>

<?php else: ?>
  <?php if ($fromReported !== ''): ?>
    <div class="alert alert-info">Monit already runs a service called “<?= e($fromReported) ?>” on this agent, defined in a file Bmon does not manage.
      After installing the zip, remove that old definition from the host, otherwise Monit refuses the configuration (service name conflict).</div>
  <?php endif ?>
  <?php if (!empty($errors['_syntax'])): ?>
    <div class="alert alert-error">monit -t rejected the configuration (together with the other enabled checks of <?= e($scope['what']) ?>):
      <pre class="monit-out"><?= e($syntax['output'] ?? '') ?></pre></div>
  <?php endif ?>

  <div class="grid-2">
    <form method="post" action="<?= e(url($base . '/new')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="template" value="<?= e($templateKey) ?>">
      <p class="muted"><?= e($tpl['description']) ?></p>
      <?php foreach ($tpl['fields'] as $f): $k = $f['key']; ?>
        <label for="f-<?= e($k) ?>"><?= e($f['label']) ?><?= empty($f['required']) ? ' <span class="muted small">(optional)</span>' : '' ?></label>
        <?php if (($f['type'] ?? 'text') === 'select'): ?>
          <select id="f-<?= e($k) ?>" name="<?= e($k) ?>"<?= $err($k) ?>>
            <?php foreach ($f['options'] as $ov => $ol): ?>
              <option value="<?= e($ov) ?>"<?= (string) ($values[$k] ?? '') === (string) $ov ? ' selected' : '' ?>><?= e($ol) ?></option>
            <?php endforeach ?>
          </select>
        <?php else: ?>
          <input id="f-<?= e($k) ?>" name="<?= e($k) ?>" type="text" value="<?= e($values[$k] ?? '') ?>"
                 <?= !empty($f['required']) ? 'required' : '' ?> spellcheck="false" autocomplete="off"<?= $err($k) ?>>
        <?php endif ?>
        <?php if (($f['help'] ?? '') !== ''): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
        <?php $field = $k; require __DIR__ . '/../partials/field_error.php' ?>
      <?php endforeach ?>
      <?php if (!in_array('name', array_column($tpl['fields'], 'key'), true)): ?>
        <p class="hint">File name: <span class="mono">bmon-<?= e($tpl['filename'] ?? $templateKey) ?>.conf</span></p>
        <?php $field = 'name'; require __DIR__ . '/../partials/field_error.php' ?>
      <?php endif ?>
      <?php if (($tpl['category'] ?? '') !== 'global') require __DIR__ . '/_groups.php'; ?>
      <?php if (!empty($errors['_syntax'])): ?>
        <label class="check"><input type="checkbox" name="force" value="1"> Save anyway (e.g. the agents run a newer Monit than this server)</label>
      <?php endif ?>
      <button type="submit" class="btn btn-primary">Add check</button>
    </form>

    <aside class="card">
      <h2>Template</h2>
      <pre class="config-preview"><?= e($tpl['body']) ?></pre>
      <p class="hint"><span class="mono">{{field}}</span> is replaced by the value of the field; lines starting with
        <span class="mono">?field</span> are kept only when that optional field is filled.
        After adding the check you can edit the resulting configuration.</p>
      <?php if ($existing): ?>
        <p class="hint">Checks already prepared for <?= e($scope['what']) ?>: <?= e(implode(', ', $existing)) ?></p>
      <?php endif ?>
    </aside>
  </div>
<?php endif ?>
