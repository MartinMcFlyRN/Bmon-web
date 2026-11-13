<?php /** @var array $form @var array $errors */
$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : ''; ?>
<div class="grid-2">
  <div>
    <label for="g-name">Name</label>
    <input id="g-name" name="name" type="text" required maxlength="64" value="<?= e($form['name']) ?>" placeholder="Web server"<?= $err('name') ?>>
    <?php $field = 'name'; require __DIR__ . '/../../partials/field_error.php' ?>
  </div>
  <div>
    <label for="g-slug">Prefix of the files <span class="muted small"><?= $form['slug'] === '' ? '(empty = from the name)' : '' ?></span></label>
    <div class="input-affix"><span>bmon-</span><input id="g-slug" name="slug" type="text" maxlength="32" value="<?= e($form['slug']) ?>" placeholder="web" spellcheck="false"<?= $err('slug') ?>><span>.&lt;check&gt;.conf</span></div>
    <?php $field = 'slug'; require __DIR__ . '/../../partials/field_error.php' ?>
  </div>
</div>
<label for="g-description">Description <span class="muted small">(optional)</span></label>
<input id="g-description" name="description" type="text" maxlength="255" value="<?= e($form['description']) ?>"<?= $err('description') ?>>
<?php $field = 'description'; require __DIR__ . '/../../partials/field_error.php' ?>
