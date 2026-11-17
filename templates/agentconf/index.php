<?php /** @var array $agents @var array $counts @var bool $all @var int $offlineCount @var string $q @var string $sort @var string $dir @var array $updates @var bool $adv @var array $advFields */
$th = static fn (string $key, string $label, string $class = ''): string
    => sort_th('/agentconf', $key, $label, $sort, $dir, $class);
?>
<div class="page-head">
  <h1>Agent</h1>
  <?php if ($offlineCount > 0 || !$all): ?>
  <a class="btn" href="<?= e(url('/agentconf') . query_with(['active' => $all ? '1' : null])) ?>">
    <?= $all ? 'Hide offline agents (' . e($offlineCount) . ')' : 'Show also offline agents (' . e($offlineCount) . ')' ?>
  </a>
  <?php endif ?>
</div>
<p class="muted">Prepare the Monit checks of each agent from templates, edit them, and download them as a zip to install in <code><?= e(\Bmon\Web\AgentChecks::monitDir()) ?>/</code>.</p>

<?php $advActive = $adv && implode('', $advFields) !== ''; ?>
<div class="toolbar">
  <form method="get" action="<?= e(url('/agentconf')) ?>" class="search search-adv<?= $adv ? ' adv-open' : '' ?>" role="search" data-adv-search>
    <?php foreach (['active', 'sort', 'dir'] as $keep): if (!empty($_GET[$keep]) && is_string($_GET[$keep])): ?>
      <input type="hidden" name="<?= e($keep) ?>" value="<?= e($_GET[$keep]) ?>">
    <?php endif; endforeach ?>
    <div class="search-row">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search agent, address, OS, Monit version" aria-label="Search agents">
      <button type="submit" class="btn">Search</button>
      <?php if ($q !== '' || $advActive): ?><a class="btn" href="<?= e(url('/agentconf') . query_with(array_fill_keys(array_merge(['q', 'adv'], array_keys($advFields)), null))) ?>">Clear</a><?php endif ?>
      <label class="check-inline"><input type="checkbox" name="adv" value="1" data-adv-toggle<?= $adv ? ' checked' : '' ?>> Advanced search</label>
    </div>
    <fieldset class="adv-fields"<?= $adv ? '' : ' hidden' ?>>
      <legend class="sr-only">Advanced search: every filled field must match, also partially</legend>
      <?php foreach (\Bmon\Web\Controllers\AgentConfController::ADVANCED as $f => [$label, $ph]): ?>
        <label><span><?= e($label) ?></span>
          <input type="search" name="<?= e($f) ?>" value="<?= e($advFields[$f]) ?>" placeholder="<?= e($ph) ?>"<?= $adv ? '' : ' disabled' ?>></label>
      <?php endforeach ?>
      <p class="hint">Every filled field must match (also part of the text). OS: name, version or codename from the OS information check;
        Inventory: item name; Check: a check prepared in Bmon or a service reported by Monit.</p>
    </fieldset>
  </form>
</div>

<?php if (!$agents): ?>
  <div class="card muted"><?= $q !== '' || $advActive ? 'No agents match the search.' : 'No ' . ($all ? '' : 'active ') . 'agents.' ?></div>
<?php else: ?>
<?php $anyOffline = (bool) array_filter($agents, static fn ($a) => $a['offline_since'] !== null); ?>
<form method="get" action="<?= e(url('/agentconf/delete')) ?>" id="bulk" class="bulk-bar" data-bulk<?= $anyOffline ? '' : ' hidden' ?>>
  <button type="submit" class="btn btn-danger" data-bulk-submit disabled>Delete selected agents <span data-bulk-count>(0)</span></button>
  <span class="muted small">Only offline agents can be selected: an agent still reporting would come back at its next report.</span>
</form>
<table class="table">
  <thead><tr><th class="col-check"><input type="checkbox" data-bulk-all form="bulk" aria-label="Select all offline agents in the list" title="Select all offline agents in the list"></th><?= $th('agent', 'Agent') . $th('address', 'Address') . $th('monit', 'Monit') . $th('services', 'Reported services', 'num')
    . $th('checks', 'Bmon checks', 'num') . $th('update', 'Auto-update') . $th('seen', 'Last report') ?></tr></thead>
  <tbody>
  <?php foreach ($agents as $a):
      $c = $counts[(int) $a['id']] ?? null; ?>
    <tr class="<?= $a['offline_since'] !== null ? 'row-off' : '' ?>">
      <td class="col-check">
        <?php if ($a['offline_since'] !== null): ?>
          <input type="checkbox" name="ids[]" value="<?= e($a['id']) ?>" form="bulk" data-bulk-item aria-label="Select <?= e($a['hostname']) ?>">
        <?php else: ?>
          <input type="checkbox" disabled title="Still reporting: only offline agents can be deleted" aria-label="<?= e($a['hostname']) ?> is still reporting">
        <?php endif ?>
      </td>
      <td>
        <?php if ($a['offline_since'] !== null): ?><span class="dot dot-off" title="Offline"></span><?php else: ?><span class="dot dot-ok" title="Active"></span><?php endif ?>
        <a class="strong" href="<?= e(url('/agentconf/' . $a['id'])) ?>"><?= e($a['hostname']) ?></a>
        <?php $os = \Bmon\Web\OsInfo::parse($a['os_info']); ?>
        <div class="muted small"><?= e($os && $os['pretty'] !== '' ? $os['pretty'] . ' · ' . $a['os_release'] : trim($a['os_name'] . ' ' . $a['os_release'])) ?></div>
      </td>
      <td class="mono"><?= e($a['remote_addr']) ?></td>
      <td><?= e($a['monit_version']) ?></td>
      <td class="num"><?= e($a['services_total']) ?></td>
      <td class="num">
        <?php if ($c === null): ?><span class="muted">—</span>
        <?php else: ?><?= e($c['enabled']) ?><?php if ((int) $c['n'] !== (int) $c['enabled']): ?> <span class="muted small">(+<?= (int) $c['n'] - (int) $c['enabled'] ?> disabled)</span><?php endif ?><?php endif ?>
      </td>
      <td><?php [, $ul, $uc] = \Bmon\Web\Controllers\AgentConfController::updateBadge($updates[(int) $a['id']] ?? null); ?>
        <?= $uc !== '' ? '<span class="badge ' . e($uc) . '">' . e($ul) . '</span>' : '<span class="muted">—</span>' ?></td>
      <td class="nowrap muted"><?= e(since($a['last_seen'])) ?> ago
        <?php if ($a['offline_since'] !== null): ?><span class="status status-off">Offline</span><?php endif ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted small"><?= count($agents) ?> agent<?= count($agents) === 1 ? '' : 's' ?>.</p>
<?php endif ?>
