<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/product_catalog.php';

function productCatalogAssertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . PHP_EOL
            . 'Esperado: ' . var_export($expected, true) . PHP_EOL
            . 'Obtenido: ' . var_export($actual, true)
        );
    }

    echo '[OK] ' . $label . PHP_EOL;
}

$csvPath = tempnam(sys_get_temp_dir(), 'atex-products-');
if ($csvPath === false) {
    throw new RuntimeException('No se pudo crear el CSV temporal.');
}
file_put_contents(
    $csvPath,
    "Table 1\n,,,,\n,GRUPO,CODIGO,EQUIPO,\"P.UNITARIO USD LISTA\nAlquiler\"\n"
    . ",PLANEX,PTPLANEX,TRABA PLANEX,0.0001\n"
    . ",ATEX 800,C8072520,CASETON ATEX 800X725X200,0.17\n"
);

try {
    $catalog = readRentalProductCatalogCsv($csvPath);
} finally {
    unlink($csvPath);
}

productCatalogAssertSame(2, count($catalog), 'lee todos los productos del CSV');
productCatalogAssertSame(0.0001, $catalog[0]['precio_alquiler'], 'conserva precios USD de cuatro decimales');
productCatalogAssertSame('US$ 0.0001', formatUsdUnitPrice(0.0001), 'muestra precios unitarios menores a un centavo');
productCatalogAssertSame('US$ 0.10', formatUsdUnitPrice(0.1), 'mantiene dos decimales en precios normales');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        descripcion TEXT,
        precio_venta REAL NOT NULL DEFAULT 0,
        precio_alquiler REAL NOT NULL DEFAULT 0,
        activo INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);
$pdo->exec(
    "INSERT INTO products
     (nombre, descripcion, precio_venta, precio_alquiler, activo, created_at, updated_at)
     VALUES ('Producto anterior', 'OLD', 20, 0, 1, '2026-01-01', '2026-01-01')"
);

$result = replaceActiveProductsWithRentalCatalog($pdo, $catalog);
$active = $pdo->query(
    'SELECT nombre, descripcion, precio_venta, precio_alquiler, activo
     FROM products
     WHERE activo = 1
     ORDER BY descripcion'
)->fetchAll();

productCatalogAssertSame(2, $result['created'], 'crea los productos nuevos');
productCatalogAssertSame(2, count($active), 'reemplaza la lista activa');
productCatalogAssertSame(0.0, (float) $active[0]['precio_venta'], 'no coloca alquiler en precio de venta');
productCatalogAssertSame(0.17, (float) $active[0]['precio_alquiler'], 'coloca el importe en precio de alquiler');
productCatalogAssertSame(
    'alquiler',
    productDefaultCondition($active[0]),
    'selecciona alquiler cuando solo existe precio de alquiler'
);
productCatalogAssertSame(
    0,
    (int) $pdo->query("SELECT activo FROM products WHERE descripcion = 'OLD'")->fetchColumn(),
    'desactiva el catálogo anterior sin borrar históricos'
);

echo PHP_EOL . 'Pruebas del catálogo de productos completadas.' . PHP_EOL;
