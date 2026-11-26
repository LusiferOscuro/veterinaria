<?php require "views/plantilla/encabezado.php"; $edit = $tratamiento !== null; ?>
<h2><?= $edit ? 'Editar Tratamiento' : 'Nuevo Tratamiento' ?></h2>
<form method="post" action="?c=tratamiento&a=<?= $edit ? 'actualizar' : 'guardar' ?>">
  <?php if ($edit): ?><input type="hidden" name="id" value="<?= $tratamiento['id'] ?>"><?php endif; ?>

  <label>Mascota:</label>
  <select name="mascota_id" required>
    <option value="">-- Seleccione --</option>
    <?php foreach ($mascotas as $m): ?>
      <option value="<?= $m['id'] ?>" <?= ($tratamiento['mascota_id'] ?? '') == $m['id'] ? 'selected' : '' ?>>
        <?= e($m['nombre']) ?> (<?= e($m['especie']) ?>) - <?= e($m['dueno']) ?></option>
    <?php endforeach; ?>
  </select>

  <label>Servicio:</label>
  <select name="servicio_id" id="servicio" required>
    <option value="">-- Seleccione --</option>
    <?php foreach ($servicios as $s):
          $actual = ($tratamiento['servicio_id'] ?? '') == $s['id'];
          if (!$s['activo'] && !$actual) continue; ?>
      <option value="<?= $s['id'] ?>" data-precio="<?= e($s['precio']) ?>" <?= $actual ? 'selected' : '' ?>>
        <?= e($s['nombre']) ?> - Bs <?= number_format($s['precio'], 2) ?></option>
    <?php endforeach; ?>
  </select>

  <label>Diagnóstico:</label>
  <textarea name="diagnostico" rows="3" required><?= e($tratamiento['diagnostico'] ?? '') ?></textarea>

  <label>Indicaciones / medicación:</label>
  <textarea name="indicaciones" rows="3"><?= e($tratamiento['indicaciones'] ?? '') ?></textarea>

  <label>Costo (Bs):</label>
  <input type="number" name="costo" id="costo" step="0.01" min="0" required value="<?= e($tratamiento['costo'] ?? '') ?>">

  <label>Estado:</label>
  <select name="estado">
    <?php foreach (['En curso', 'Finalizado', 'Cancelado'] as $est): ?>
      <option <?= ($tratamiento['estado'] ?? 'En curso') === $est ? 'selected' : '' ?>><?= $est ?></option>
    <?php endforeach; ?>
  </select>

  <label>Método de pago:</label>
  <select name="metodo_pago_id">
    <option value="">-- Sin definir --</option>
    <?php foreach ($metodos as $mp):
          $actual = ($tratamiento['metodo_pago_id'] ?? '') == $mp['id'];
          if (!$mp['activo'] && !$actual) continue; ?>
      <option value="<?= $mp['id'] ?>" <?= $actual ? 'selected' : '' ?>><?= e($mp['nombre']) ?></option>
    <?php endforeach; ?>
  </select>

  <div class="check">
    <input type="checkbox" id="pagado" name="pagado" <?= ($tratamiento['pagado'] ?? 0) ? 'checked' : '' ?>>
    <label for="pagado">Pago realizado</label>
  </div>

  <div class="acciones">
    <button type="submit"><?= $edit ? 'Guardar cambios' : 'Guardar' ?></button>
    <a class="btn btn-sec" href="?c=tratamiento">Cancelar</a>
  </div>
</form>

<script>
// Al elegir un servicio nuevo, sugiere su precio como costo
document.getElementById('servicio').addEventListener('change', function () {
  var precio = this.options[this.selectedIndex].getAttribute('data-precio');
  if (precio !== null) document.getElementById('costo').value = precio;
});
</script>
<?php require "views/plantilla/pie.php"; ?>
