<?php
/** Help box of the {{agent.*}} variables. @var array|null $varsHost agent (values shown) or null (group / template) */
use Bmon\Web\AgentVars;

$rows = AgentVars::table($varsHost ?? null);
?>
<details class="vars-help">
  <summary>Variables filled in by Bmon-web <span class="muted small">— e.g. <span class="mono">{{agent.name}}</span>, <span class="mono">{{agent.ip1}}</span></span></summary>
  <p class="hint">Write them in the configuration as they are: Bmon-web replaces them with the values of each agent when it builds its zip
    (download, deploy) and when it checks it with monit -t. A deploy is refused while one has no value for the agent.
    <?= ($varsHost ?? null) === null ? 'Here they are checked with example values.' : 'The values below are those of this agent.' ?></p>
  <table class="table table-compact vars-table">
    <thead><tr><th>Variable</th><th><?= ($varsHost ?? null) === null ? 'Meaning' : 'Value for this agent' ?></th></tr></thead>
    <tbody>
    <?php if (($varsHost ?? null) === null): ?>
      <?php foreach (AgentVars::describe() as $name => $desc): ?>
        <tr><td class="mono nowrap"><?= e(implode(', ', array_map(static fn ($n) => str_contains($n, '…') ? $n : '{{' . trim($n) . '}}', explode(',', $name)))) ?></td><td><?= e($desc) ?></td></tr>
      <?php endforeach ?>
    <?php else: ?>
      <?php foreach ($rows as $name => $value): ?>
        <tr><td class="mono nowrap">{{<?= e($name) ?>}}</td>
          <td><?= $value !== null ? '<span class="mono">' . e($value) . '</span>' : '<span class="muted">no value</span>' ?></td></tr>
      <?php endforeach ?>
    <?php endif ?>
    </tbody>
  </table>
</details>
