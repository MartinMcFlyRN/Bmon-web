<?php
/**
 * @var array $host @var array $checks @var array $managed @var array $reported
 * @var array $syntax @var bool $monitAvailable
 */
use Bmon\Web\AgentChecks;
use Bmon\Web\AgentTemplates;
use Bmon\Web\Monit;

$enabledCount = count(array_filter($checks, static fn ($c) => (int) $c['enabled']));
?>
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
      <a class="btn btn-primary" href="<?= e(url('/agentconf/' . $host['id'] . '/download')) ?>">Download zip (<?= $enabledCount ?>)</a>
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
  <?php endif ?>

  <?php if (!$checks): ?>
    <div class="card muted">No checks yet. <a href="<?= e(url('/agentconf/' . $host['id'] . '/new')) ?>">Add the first one from a template</a>,
      or create one from a service Monit already reports, below.</div>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>File</th><th>Defines</th><th>Template</th><th>Status</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c):
        $tpl = AgentTemplates::get($c['template']); ?>
      <tr class="<?= (int) $c['enabled'] ? '' : 'row-off' ?>">
        <td class="nowrap"><a class="strong mono" href="<?= e(url('/agentconf/' . $host['id'] . '/checks/' . $c['id'])) ?>"><?= e(AgentChecks::fileName($c['name'])) ?></a></td>
        <td><?php foreach ($c['services'] as $s): ?><span class="chip"><?= e($s) ?></span> <?php endforeach ?>
          <?php if (!$c['services']): ?><span class="muted small">Monit settings</span><?php endif ?></td>
        <td class="muted"><?= e($tpl['label'] ?? $c['template']) ?></td>
        <td><?= (int) $c['enabled'] ? '<span class="badge badge-ok">Enabled</span>' : '<span class="badge badge-off">Disabled</span>' ?></td>
        <td class="nowrap muted"><?= e(fmt_dt($c['updated_at'])) ?> <?= $c['updated_by'] !== '' ? '· ' . e($c['updated_by']) : '' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
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
