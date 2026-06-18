<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$error = null;
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $precioVenta = parseDecimalInput((string) ($_POST['precio_venta'] ?? '0'));
        $precioAlquiler = parseDecimalInput((string) ($_POST['precio_alquiler'] ?? '0'));

        if ($nombre === '') {
            throw new RuntimeException('El nombre del producto es obligatorio.');
        }
        if ($precioVenta < 0 || $precioAlquiler < 0) {
            throw new RuntimeException('Los precios no pueden ser negativos.');
        }

        createDatabaseBackup();
        if ($id > 0) {
            $stmt = db()->prepare(
                'UPDATE products
                 SET nombre = :nombre, descripcion = :descripcion, precio_venta = :precio_venta,
                     precio_alquiler = :precio_alquiler, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':precio_venta' => $precioVenta,
                ':precio_alquiler' => $precioAlquiler,
                ':updated_at' => nowIso(),
                ':id' => $id,
            ]);
            setFlash('success', 'Producto actualizado.');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO products (nombre, descripcion, precio_venta, precio_alquiler, created_at, updated_at)
                 VALUES (:nombre, :descripcion, :precio_venta, :precio_alquiler, :created_at, :updated_at)'
            );
            $now = nowIso();
            $stmt->execute([
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':precio_venta' => $precioVenta,
                ':precio_alquiler' => $precioAlquiler,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            setFlash('success', 'Producto creado.');
        }
        redirect('/products.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$products = db()->query('SELECT * FROM products WHERE activo = 1 ORDER BY nombre COLLATE NOCASE')->fetchAll();

renderHeader('Productos');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2><?= $edit ? 'Editar producto' : 'Nuevo producto' ?></h2>
    <form method="post" class="grid-form">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? 0)) ?>">
        <label>
            Nombre
            <input name="nombre" required value="<?= e($edit['nombre'] ?? '') ?>">
        </label>
        <label>
            Precio venta (US$)
            <input type="number" min="0" step="0.01" name="precio_venta" value="<?= e((string) ($edit['precio_venta'] ?? '0')) ?>">
        </label>
        <label>
            Precio alquiler diario (US$)
            <input type="number" min="0" step="0.01" name="precio_alquiler" value="<?= e((string) ($edit['precio_alquiler'] ?? '0')) ?>">
        </label>
        <label class="wide">
            Referencia
            <textarea name="descripcion" rows="3"><?= e($edit['descripcion'] ?? '') ?></textarea>
        </label>
        <div class="form-actions wide">
            <button class="button primary" type="submit"><?= $edit ? 'Guardar cambios' : 'Crear producto' ?></button>
            <?php if ($edit): ?><a class="button" href="<?= e(publicPath('/products.php')) ?>">Cancelar</a><?php endif; ?>
        </div>
    </form>
</section>

<section class="panel">
    <div class="section-title">
        <h2>Productos cargados</h2>
        <input type="search" id="products-search" class="list-search" placeholder="Buscar producto" autocomplete="off">
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Nombre</th>
                <th>Referencia</th>
                <th class="right">Venta (US$)</th>
                <th class="right product-rental-column">Alquiler diario (US$)</th>
                <th class="right"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr data-filter-search="<?= e($product['nombre'] . ' ' . ($product['descripcion'] ?? '') . ' ' . formatMoney((float) $product['precio_venta'], 'USD') . ' ' . formatMoney((float) $product['precio_alquiler'], 'USD')) ?>">
                    <td><?= e($product['nombre']) ?></td>
                    <td><?= e($product['descripcion']) ?></td>
                    <td class="right"><?= e(formatMoney((float) $product['precio_venta'], 'USD')) ?></td>
                    <td class="right product-rental-column"><?= e(formatMoney((float) $product['precio_alquiler'], 'USD')) ?></td>
                    <td class="right"><a href="<?= e(publicPath('/products.php?edit=' . (int) $product['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted empty-search" id="products-empty" hidden>No se encontraron productos.</p>
</section>
<script>
(function () {
    const search = document.getElementById('products-search');
    const rows = Array.from(document.querySelectorAll('[data-filter-search]'));
    const empty = document.getElementById('products-empty');

    function normalizeSearch(value) {
        return value
            .toString()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function filterRows() {
        const term = normalizeSearch(search.value);
        let visibleRows = 0;

        rows.forEach((row) => {
            const matches = term === '' || normalizeSearch(row.dataset.filterSearch || row.textContent).includes(term);
            row.hidden = !matches;
            if (matches) {
                visibleRows++;
            }
        });

        empty.hidden = term === '' || visibleRows > 0;
    }

    search.addEventListener('input', filterRows);
})();
</script>
<?php renderFooter(); ?>
