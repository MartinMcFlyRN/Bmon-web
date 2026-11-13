<?php /** @var array $form @var array $errors */ ?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">AgentConf</a> / <a href="<?= e(url('/agentconf/groups')) ?>">Group Checks</a> /</p>
    <h1>New group</h1>
  </div>
</div>
<form method="post" action="<?= e(url('/agentconf/groups/new')) ?>" class="card">
  <?= csrf_field() ?>
  <?php require __DIR__ . '/_form.php' ?>
  <button type="submit" class="btn btn-primary">Create group</button>
</form>
