<?php
/** @var array $fields @var array $stored @var array $errors @var array $submitted @var string|null $configFile */
use Bmon\Web\Config;
use Bmon\Web\Settings;

$show = static function ($v): string {
    if (is_bool($v)) {
        return $v ? 'on' : 'off';
    }
    if (is_array($v)) {
        return $v ? implode(', ', $v) : '(empty)';
    }
    return (string) $v === '' ? '(empty)' : (string) $v;
};
$sections = [];
foreach ($fields as $key => $f) {
    $sections[$f['section']][$key] = $f;
}
?>
<div class="page-head">
  <h1>Configuration</h1>
</div>
<p class="muted">Values saved here override <span class="mono"><?= e($configFile ?? 'config.php') ?></span>, which keeps the database connection
  and the settings that must not be changed from the browser (<span class="mono">collector_db</span>, <span class="mono">app.base_path</span>,
  <span class="mono">app.debug</span>, <span class="mono">monit.credentials_key</span>, <span class="mono">agentconf.monit_binary</span>).
  A field set to the same value as config.php keeps following config.php.</p>

<form method="post" action="<?= e(url('/admin/config')) ?>" class="config-form">
  <?= csrf_field() ?>
  <?php foreach (Settings::SECTIONS as $sec => $secLabel): if (empty($sections[$sec])) continue; ?>
    <section class="card config-section">
      <h2><?= e($secLabel) ?></h2>
      <?php foreach ($sections[$sec] as $key => $f):
          $name = str_replace('.', '__', $key);
          $id = str_replace('.', '-', $key);
          $source = Config::source($key);
          $value = array_key_exists($name, $submitted) && $f['type'] !== 'secret'
              ? (string) $submitted[$name]
              : Settings::formValue($key, Config::get($key));
          $invalid = !empty($errors[$key]) ? ' aria-invalid="true"' : '';
      ?>
        <div class="config-field" id="<?= e($id) ?>">
          <div class="config-label">
            <?php if ($f['type'] === 'bool'): ?>
              <label class="check">
                <input type="hidden" name="<?= e($name) ?>" value="0">
                <input type="checkbox" name="<?= e($name) ?>" value="1"<?= $value === '1' ? ' checked' : '' ?>> <?= e($f['label']) ?>
              </label>
            <?php else: ?>
              <label for="c-<?= e($id) ?>"><?= e($f['label']) ?><?= isset($f['unit']) && $f['unit'] !== '' ? ' <span class="muted small">(' . e($f['unit']) . ')</span>' : '' ?></label>
            <?php endif ?>
            <span class="config-key mono"><?= e($key) ?></span>
          </div>

          <?php if ($f['type'] === 'timezone'): ?>
            <select id="c-<?= e($id) ?>" name="<?= e($name) ?>"<?= $invalid ?>>
              <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                <option<?= $tz === $value ? ' selected' : '' ?>><?= e($tz) ?></option>
              <?php endforeach ?>
            </select>
          <?php elseif ($f['type'] === 'pem'): ?>
            <textarea id="c-<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= $value === '' ? 3 : 8 ?>" class="code" spellcheck="false"
                      placeholder="-----BEGIN CERTIFICATE-----&#10;…&#10;-----END CERTIFICATE-----"<?= $invalid ?>><?= e($value) ?></textarea>
            <?php foreach (\Bmon\Web\MonitClient::caSummary((string) \Bmon\Web\Config::get($key)) as $line): ?>
              <p class="hint mono"><?= e($line) ?></p>
            <?php endforeach ?>
          <?php elseif ($f['type'] === 'cidrs'): ?>
            <textarea id="c-<?= e($id) ?>" name="<?= e($name) ?>" rows="3" class="code" spellcheck="false"<?= $invalid ?>><?= e($value) ?></textarea>
          <?php elseif ($f['type'] === 'secret'): ?>
            <?php $isSet = (string) Config::get($key) !== ''; ?>
            <input id="c-<?= e($id) ?>" name="<?= e($name) ?>" type="password" autocomplete="off" spellcheck="false"
                   placeholder="<?= $isSet ? '•••••••• set — type a new value to replace it' : 'not set' ?>"<?= $invalid ?>>
            <?php if ($isSet): ?>
              <label class="check small"><input type="checkbox" name="<?= e($name) ?>__clear" value="1"> Remove</label>
            <?php endif ?>
          <?php elseif ($f['type'] !== 'bool'): ?>
            <input id="c-<?= e($id) ?>" name="<?= e($name) ?>" type="<?= $f['type'] === 'int' ? 'number' : 'text' ?>"
                   value="<?= e($value) ?>" spellcheck="false"
                   <?= $f['type'] === 'int' ? 'min="' . e($f['min']) . '" max="' . e($f['max']) . '"' : '' ?><?= $invalid ?>>
          <?php endif ?>

          <?php if (!empty($errors[$key])): ?><p class="field-error"><?= e($errors[$key]) ?></p><?php endif ?>
          <?php if (($f['help'] ?? '') !== ''): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
          <p class="config-source">
            <?php if ($source === 'database'): ?>
              <span class="badge badge-admin">set here</span>
              <?php if (isset($stored[$key])): ?><span class="muted small"><?= e(fmt_dt($stored[$key]['updated_at'])) ?><?= $stored[$key]['updated_by'] !== '' ? ' by ' . e($stored[$key]['updated_by']) : '' ?></span><?php endif ?>
              <span class="muted small">· config.php/default: <?= $f['type'] === 'secret' ? ((string) Config::inherited($key) !== '' ? 'set' : '(empty)') : e($show(Config::inherited($key))) ?></span>
              <button type="submit" form="reset-<?= e($id) ?>" class="linkbtn small">reset</button>
            <?php elseif ($source === 'file'): ?>
              <span class="badge">from config.php</span>
            <?php else: ?>
              <span class="badge badge-off">default</span>
            <?php endif ?>
          </p>
        </div>
      <?php endforeach ?>
    </section>
  <?php endforeach ?>
  <div class="config-actions"><button type="submit" class="btn btn-primary">Save configuration</button></div>
</form>

<?php foreach ($fields as $key => $f): if (Config::source($key) !== 'database') continue; ?>
  <form method="post" action="<?= e(url('/admin/config/reset')) ?>" id="reset-<?= e(str_replace('.', '-', $key)) ?>" hidden>
    <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>">
  </form>
<?php endforeach ?>
