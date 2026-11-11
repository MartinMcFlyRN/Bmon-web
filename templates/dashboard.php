<?php /** @var array|null $stats @var string|null $collectorError */ ?>
<h1>Dashboard</h1>

<?php if ($collectorError !== null): ?>
  <div class="alert alert-error"><?= e($collectorError) ?></div>
<?php else: ?>
  <?php
    $hostsOk = (int) $stats['hosts'] - (int) $stats['hosts_offline'] - (int) $stats['hosts_failing'];
    $servicesOk = (int) $stats['services'] - (int) $stats['services_failed'];
  ?>
  <div class="tiles">
    <section class="tile">
      <h2>Hosts</h2>
      <p class="tile-value"><?= e($stats['hosts']) ?></p>
      <ul class="tile-breakdown">
        <li><span class="dot dot-ok"></span><?= e($hostsOk) ?> OK</li>
        <li><span class="dot dot-fail"></span><?= e($stats['hosts_failing']) ?> with failing services</li>
        <li><span class="dot dot-off"></span><?= e($stats['hosts_offline']) ?> offline</li>
      </ul>
    </section>
    <section class="tile">
      <h2>Services</h2>
      <p class="tile-value"><?= e($stats['services']) ?></p>
      <ul class="tile-breakdown">
        <li><span class="dot dot-ok"></span><?= e($servicesOk) ?> OK</li>
        <li><span class="dot dot-fail"></span><?= e($stats['services_failed']) ?> failing</li>
      </ul>
    </section>
  </div>
  <p class="muted">Host status, events and reports are coming in the next versions.</p>
<?php endif ?>
