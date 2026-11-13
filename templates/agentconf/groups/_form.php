<?php /** @var array $form @var array $errors */
$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : ''; ?>
<label for="g-name">Name</label>
<input id="g-name" name="name" type="text" required maxlength="64" value="<?= e($form['name']) ?>" placeholder="Web server"<?= $err('name') ?>>
<?php $field = 'name'; require __DIR__ . '/../../partials/field_error.php' ?>
<label for="g-description">Description <span class="muted small">(optional)</span></label>
<input id="g-description" name="description" type="text" maxlength="255" value="<?= e($form['description']) ?>"<?= $err('description') ?>>
<?php $field = 'description'; require __DIR__ . '/../../partials/field_error.php' ?>
