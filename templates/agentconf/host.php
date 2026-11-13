<?php
/**
 * @var array $host @var array $checks @var array $managed @var array $reported
 * @var array $syntax @var bool $monitAvailable @var array $missingScripts
 * @var array|null $link @var array|null $newLink @var array $groupsByCheck @var array $update
 */
use Bmon\Web\AgentChecks;
use Bmon\Web\AgentTemplates;
use Bmon\Web\Monit;

$enabledCount = count(array_filter($checks, static fn ($c) => (int) $c['enabled']));
?>
<?php if (($cfgProblem = \Bmon\Web\AgentChecks::configProblem()) !== null): ?>
  <div class="alert alert-error"><?= e($cfgProblem) ?></div>
<?php endif ?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">AgentConf</a> /</p>
    <h1 class="host-title"><?= e($host['hostname']) ?>
      <?php if ($host['offline_since'] !== null): ?><span class="status status-off">Offline</span><?php endif ?>
    </h1>
    <p class="muted small"><?= e($host['remote_addr']) ?> · Monit <?= e($host['monit_version']) ?>
      · <a href="<?= e(url('/status/' . $host['id'])) ?>">Status page</a></p>
  </div>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/agentconf/' . $host['id'] . '/new')) ?>">Add check</a>
    <?php if ($enabledCount): ?>
      <a class="btn btn-primary" href="#download-dialog" data-open-dialog="download-dialog">Download zip (<?= $enabledCount ?>)</a>
    <?php else: ?>
      <span class="btn btn-disabled" title="No enabled checks">Download zip</span>
    <?php endif ?>
  </div>
</div>

<section class="panel">
  <h2>Checks prepared in Bmon <span class="muted">(<?= count($checks) ?>)</span></h2>
  <?php if ($checks): ?>
    <?php if ($syntax['ok'] === true): ?>
      <p class="syntax syntax-ok"><span class="dot dot-ok"></span> monit -t: the enabled checks together are valid.</p>
    <?php elseif ($syntax['ok'] === false): ?>
      <div class="syntax syntax-fail"><span class="dot dot-fail"></span> monit -t: the enabled checks together are <strong>not valid</strong>; fix them before installing the zip.
        <pre><?= e($syntax['output']) ?></pre></div>
    <?php elseif (!$monitAvailable): ?>
      <p class="hint">Syntax not checked: monit is not installed on this server (see agentconf.monit_binary).</p>
    <?php endif ?>
    <?php foreach ($missingScripts as $checkName => $paths): ?>
      <div class="syntax syntax-fail"><span class="dot dot-fail"></span>
        <span><span class="mono"><?= e(AgentChecks::fileName($checkName)) ?></span> uses <span class="mono"><?= e(implode(', ', $paths)) ?></span>,
        but no enabled check of this agent attaches it: Monit on the agent would refuse the configuration.</span></div>
    <?php endforeach ?>
  <?php endif ?>

  <?php if (!$checks): ?>
    <div class="card muted">No checks yet. <a href="<?= e(url('/agentconf/' . $host['id'] . '/new')) ?>">Add the first one from a template</a>,
      or create one from a service Monit already reports, below.</div>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>File</th><th>Defines</th><th>Groups</th><th>Script</th><th>Created from</th><th>Status</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c):
        $tpl = AgentTemplates::get($c['template']); ?>
      <tr class="<?= (int) $c['enabled'] ? '' : 'row-off' ?>">
        <td class="nowrap"><a class="strong mono" href="<?= e(url('/agentconf/' . $host['id'] . '/checks/' . $c['id'])) ?>"><?= e(AgentChecks::fileName($c['name'])) ?></a></td>
        <td><?php foreach ($c['services'] as $s): ?><span class="chip"><?= e($s) ?></span> <?php endforeach ?>
          <?php if (!$c['services']): ?><span class="muted small">Monit settings</span><?php endif ?></td>
        <td><?php foreach ($groupsByCheck[(int) $c['id']] ?? [] as $gn): ?><span class="chip chip-group"><?= e($gn) ?></span> <?php endforeach ?>
          <?php if (empty($groupsByCheck[(int) $c['id']])): ?><span class="muted">—</span><?php endif ?></td>
        <td><?= $c['script_name'] !== '' ? '<span class="mono small">' . e(AgentChecks::SCRIPTS_SUBDIR . '/' . $c['script_name']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td class="muted"><?= e($tpl['label'] ?? $c['template']) ?></td>
        <td><?= (int) $c['enabled'] ? '<span class="badge badge-ok">Enabled</span>' : '<span class="badge badge-off">Disabled</span>' ?></td>
        <td class="nowrap muted"><?= e(fmt_dt($c['updated_at'])) ?> <?= $c['updated_by'] !== '' ? '· ' . e($c['updated_by']) : '' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <p class="hint">Each check is a copy made from a template when it was created: editing a check changes only that check.</p>
  <?php endif ?>
</section>

<?php
$rel = $update['release'];
$st = $update['state'];
$installed = (string) ($st['installed_version'] ?? '');
$short = static fn (string $v): string => $v === '' ? '—' : substr($v, 0, 12);
?>
<section class="panel" id="auto-update">
  <h2>Auto-update</h2>
  <div class="card update-card">
    <dl class="kv">
      <dt>Published</dt>
      <dd><?php if ($rel === null): ?><span class="muted">Nothing published yet.</span>
        <?php else: ?><span class="mono"><?= e($short($rel['version'])) ?></span> · <?= e($rel['checks']) ?> checks · <?= e(fmt_dt($rel['created_at'])) ?> · <?= e($rel['created_by']) ?>
        <?php endif ?>
        <?php if ($update['changed'] && $enabledCount): ?> <span class="badge badge-warn">Changes not published</span><?php endif ?></dd>
      <dt>On the agent</dt>
      <dd><?php if ($st === null || $st['last_poll_at'] === null): ?><span class="muted">The agent has not asked for updates yet.</span>
        <?php else: ?>
          <span class="mono"><?= e($short($installed)) ?></span>
          <?php if ($rel !== null && $installed === $rel['version']): ?><span class="badge badge-ok">Up to date</span>
          <?php elseif ($rel !== null): ?><span class="badge badge-warn">Update pending</span><?php endif ?>
          <span class="muted small">· last asked <?= e(since($st['last_poll_at'])) ?> ago from <?= e($st['last_poll_ip']) ?></span>
        <?php endif ?></dd>
      <?php if ($st !== null && $st['last_result_at'] !== null): ?>
        <dt>Last install</dt>
        <dd><?= match ($st['last_status']) {
              'installed' => '<span class="badge badge-ok">Installed</span>',
              'rolled_back' => '<span class="badge badge-fail">Rolled back</span>',
              default => '<span class="badge badge-fail">Failed</span>',
            } ?> <span class="mono small"><?= e($short($st['installed_version'] ?? '')) ?></span>
          <span class="muted small">· <?= e(fmt_dt($st['last_result_at'], 'Y-m-d H:i:s')) ?></span>
          <?php if (trim((string) $st['last_message']) !== ''): ?><pre class="update-output"><?= e($st['last_message']) ?></pre><?php endif ?></dd>
      <?php endif ?>
      <?php if ($st !== null && $st['last_auth_error'] !== ''): ?>
        <dt>Refused</dt>
        <dd class="text-fail"><?= e($st['last_auth_error']) ?> <span class="muted small">· <?= e(fmt_dt($st['last_auth_error_at'], 'Y-m-d H:i:s')) ?></span></dd>
      <?php endif ?>
    </dl>
    <?php foreach ($update['warnings'] as $w): ?><p class="hint text-warn"><?= e($w) ?></p><?php endforeach ?>
    <div class="dialog-actions">
      <form method="post" action="<?= e(url('/agentconf/' . $host['id'] . '/publish')) ?>"
            data-confirm="Publish the enabled checks? The agent replaces its whole Bmon configuration with them at its next auto-update run.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary"<?= $update['changed'] && $enabledCount ? '' : ' disabled title="Nothing changed since the last publication"' ?>>Publish</button>
      </form>
      <form method="post" action="<?= e(url('/agentconf/' . $host['id'] . '/update-now')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn"<?= $update['canTrigger'] && $rel !== null ? '' : ' disabled' ?>
          title="<?= e($update['service'] === null ? 'No enabled Bmon auto-update check'
            : (!$update['serviceReported'] ? 'Monit does not report “' . $update['service'] . '” yet: install the configuration once by hand'
            : ($rel === null ? 'Publish first' : 'Runs “' . $update['service'] . '” now through Monit httpd'))) ?>">Update now</button>
      </form>
    </div>
    <p class="hint">The agent installs only what is published: edit the checks, then <strong>Publish</strong>. The
      "Bmon auto-update" check downloads it (signed with the httpd password), replaces the whole content of
      <span class="mono"><?= e(AgentChecks::monitDir()) ?></span> and <span class="mono"><?= e(AgentChecks::scriptDir()) ?></span>,
      runs <span class="mono">monit -t</span> (restoring the previous files if it fails) and reloads Monit.</p>
  </div>
</section>

<section class="panel">
  <h2>Checks currently on the agent <span class="muted">(<?= count($reported) ?>, from Monit's reports)</span></h2>
  <?php if (!$reported): ?>
    <div class="card muted">Monit reports no services for this agent.</div>
  <?php else: ?>
  <table class="table table-compact">
    <thead><tr><th>Service</th><th>Type</th><th>State</th><th>In Bmon</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reported as $r):
        [$cls, $label] = Monit::serviceState($r);
        $m = $managed[$r['name']] ?? null; ?>
      <tr>
        <td class="strong"><?= e($r['name']) ?></td>
        <td class="muted"><?= e(Monit::serviceType((int) $r['type'])) ?></td>
        <td><span class="status status-<?= e($cls) ?>"><?= e($label) ?></span></td>
        <td>
          <?php if ($m !== null): ?>
            <a class="mono small" href="<?= e(url('/agentconf/' . $host['id'] . '/checks/' . $m['id'])) ?>"><?= e(AgentChecks::fileName($m['name'])) ?></a>
          <?php else: ?><span class="muted small">not managed</span><?php endif ?>
        </td>
        <td class="nowrap">
          <?php if ($m === null): ?>
            <a class="btn btn-sm" href="<?= e(url('/agentconf/' . $host['id'] . '/new') . '?' . http_build_query([
                'template' => AgentTemplates::suggest((int) $r['type'], $r['name']), 'name' => $r['name']])) ?>">Create check</a>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <p class="hint">These are the services Monit runs now, from any configuration file on the host. Bmon knows their names and types, not their configuration:
    "Create check" starts from the closest template.</p>
  <?php endif ?>
</section>

<?php if ($enabledCount): ?>
<dialog id="download-dialog" class="dialog"<?= $newLink !== null ? ' open' : '' ?> aria-labelledby="download-title">
  <form method="dialog" class="dialog-close"><button type="submit" aria-label="Close">×</button></form>
  <h2 id="download-title">Download the configuration of <?= e($host['hostname']) ?></h2>

  <p><a class="btn" href="<?= e(url('/agentconf/' . $host['id'] . '/download')) ?>">Download zip now</a>
    <span class="muted small">to this computer, with your session</span></p>

  <h3>Link for the server</h3>
  <p class="hint">A link that works <strong>without signing in</strong>, to download the zip directly on the agent, e.g. with curl.
    It expires after <?= e((int) \Bmon\Web\Config::get('agentconf.link_ttl_hours', 24)) ?> hours, and creating a new one disables the previous one.
    The zip always contains the checks as they are at download time. For security the link is shown only once, now.</p>

  <div data-link-result>
    <?php if ($newLink !== null): require __DIR__ . '/_new_link.php'; endif ?>
  </div>

  <?php if ($link !== null && $newLink === null): ?>
    <div class="link-status" data-link-status>
      <span class="dot dot-ok"></span>
      <span>An active link exists, created <?= e(fmt_dt($link['created_at'])) ?><?= $link['created_by'] !== '' ? ' by ' . e($link['created_by']) : '' ?>,
        expiring <?= e(fmt_dt($link['expires_at'])) ?>;
        <?= (int) $link['uses'] ? 'used ' . e($link['uses']) . ' time' . ((int) $link['uses'] === 1 ? '' : 's') . ', last from ' . e($link['last_used_ip']) . ' at ' . e(fmt_dt($link['last_used_at'])) : 'not used yet' ?>.
        Its address cannot be shown again: create a new link if you need it.</span>
    </div>
  <?php endif ?>

  <div class="dialog-actions">
    <form method="post" action="<?= e(url('/agentconf/' . $host['id'] . '/link')) ?>" data-link-form>
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary"><?= $link !== null || $newLink !== null ? 'Create a new link' : 'Create link' ?></button>
    </form>
    <?php if ($link !== null || $newLink !== null): ?>
      <form method="post" action="<?= e(url('/agentconf/' . $host['id'] . '/link/revoke')) ?>" data-revoke-form
            data-confirm="Revoke the download link? It stops working immediately.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Revoke link</button>
      </form>
    <?php endif ?>
  </div>
</dialog>
<?php endif ?>
