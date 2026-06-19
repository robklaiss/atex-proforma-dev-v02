<?php

declare(strict_types=1);

function productDefaultCondition(array $product): string
{
    $salePrice = (float) ($product['precio_venta'] ?? 0);
    $rentalPrice = (float) ($product['precio_alquiler'] ?? 0);
    $name = trim((string) ($product['nombre'] ?? ''));

    if ($rentalPrice > 0 && $salePrice <= 0) {
        return 'alquiler';
    }

    return stripos($name, 'ALQUILER') === 0 ? 'alquiler' : 'venta';
}

function readRentalProductCatalogCsv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('No se puede leer el archivo CSV indicado.');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir el archivo CSV.');
    }

    $products = [];
    $headerFound = false;
    $seenCodes = [];

    try {
        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $cells = array_map(static fn(mixed $value): string => trim((string) $value), $row);

            if (!$headerFound) {
                $normalized = array_map(
                    static fn(string $value): string => strtoupper(preg_replace('/\s+/', ' ', $value) ?? $value),
                    $cells
                );
                $codeIndex = array_search('CODIGO', $normalized, true);
                $equipmentIndex = array_search('EQUIPO', $normalized, true);
                $priceIndex = null;
                foreach ($normalized as $index => $value) {
                    if (str_contains($value, 'P.UNITARIO') && str_contains($value, 'ALQUILER')) {
                        $priceIndex = $index;
                        break;
                    }
                }

                if ($codeIndex !== false && $equipmentIndex !== false && $priceIndex !== null) {
                    $headerFound = true;
                    $columns = [
                        'group' => max(0, (int) $codeIndex - 1),
                        'code' => (int) $codeIndex,
                        'equipment' => (int) $equipmentIndex,
                        'price' => (int) $priceIndex,
                    ];
                }
                continue;
            }

            $code = trim((string) ($cells[$columns['code']] ?? ''));
            $equipment = trim((string) ($cells[$columns['equipment']] ?? ''));
            $group = trim((string) ($cells[$columns['group']] ?? ''));
            $rawPrice = trim((string) ($cells[$columns['price']] ?? ''));

            if ($code === '' && $equipment === '' && $rawPrice === '') {
                continue;
            }
            if ($code === '' || $equipment === '' || $rawPrice === '') {
                throw new RuntimeException('El CSV contiene una fila de producto incompleta.');
            }

            $normalizedCode = strtoupper($code);
            if (isset($seenCodes[$normalizedCode])) {
                throw new RuntimeException('El código ' . $code . ' está repetido en el CSV.');
            }

            $price = parseDecimalInput($rawPrice);
            if (!is_finite($price) || $price < 0) {
                throw new RuntimeException('El precio de alquiler de ' . $code . ' no es válido.');
            }

            $seenCodes[$normalizedCode] = true;
            $products[] = [
                'grupo' => $group,
                'codigo' => $code,
                'nombre' => $equipment,
                'precio_alquiler' => $price,
            ];
        }
    } finally {
        fclose($handle);
    }

    if (!$headerFound) {
        throw new RuntimeException('No se encontraron las columnas CODIGO, EQUIPO y precio de alquiler.');
    }
    if ($products === []) {
        throw new RuntimeException('El CSV no contiene productos.');
    }

    return $products;
}

function replaceActiveProductsWithRentalCatalog(PDO $pdo, array $products): array
{
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $now = nowIso();
        $pdo->prepare('UPDATE products SET activo = 0, updated_at = :updated_at WHERE activo = 1')
            ->execute([':updated_at' => $now]);

        $find = $pdo->prepare(
            'SELECT id
             FROM products
             WHERE descripcion = :codigo COLLATE NOCASE
             ORDER BY id
             LIMIT 2'
        );
        $update = $pdo->prepare(
            'UPDATE products
             SET nombre = :nombre,
                 descripcion = :codigo,
                 precio_venta = 0,
                 precio_alquiler = :precio_alquiler,
                 activo = 1,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $insert = $pdo->prepare(
            'INSERT INTO products
             (nombre, descripcion, precio_venta, precio_alquiler, activo, created_at, updated_at)
             VALUES (:nombre, :codigo, 0, :precio_alquiler, 1, :created_at, :updated_at)'
        );

        $created = 0;
        $updated = 0;
        foreach ($products as $product) {
            $find->execute([':codigo' => $product['codigo']]);
            $matches = $find->fetchAll();
            if (count($matches) > 1) {
                throw new RuntimeException('Hay más de un producto con el código ' . $product['codigo'] . '.');
            }

            $params = [
                ':nombre' => $product['nombre'],
                ':codigo' => $product['codigo'],
                ':precio_alquiler' => $product['precio_alquiler'],
                ':updated_at' => $now,
            ];
            if ($matches !== []) {
                $update->execute($params + [':id' => (int) $matches[0]['id']]);
                $updated++;
            } else {
                $insert->execute($params + [':created_at' => $now]);
                $created++;
            }
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        return [
            'total' => count($products),
            'created' => $created,
            'updated' => $updated,
        ];
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
