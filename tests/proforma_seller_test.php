<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/proforma_seller.php';

function sellerAssertSame(int $expected, int $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . PHP_EOL .
            'Esperado: ' . $expected . PHP_EOL .
            'Obtenido: ' . $actual
        );
    }

    echo '[OK] ' . $label . PHP_EOL;
}

$editor = ['id' => 22, 'role' => 'admin'];
$original = ['seller_id' => 7, 'created_by' => 5];
$assistant = ['id' => 31, 'role' => 'assistant'];

sellerAssertSame(
    7,
    resolveProformaSellerId($editor, $original, true, false, true, ['seller_id' => 22]),
    'editar conserva el vendedor original aunque el POST solicite otro'
);

sellerAssertSame(
    5,
    resolveProformaSellerId($editor, ['seller_id' => null, 'created_by' => 5], true, false, true, []),
    'editar usa el creador original para proformas antiguas sin seller_id'
);

sellerAssertSame(
    22,
    resolveProformaSellerId($editor, $original, false, true, true, ['seller_id' => 22]),
    'clonar permite cambiar el vendedor cuando el usuario tiene permiso'
);

sellerAssertSame(
    7,
    resolveProformaSellerId($editor, $original, false, true, true, []),
    'clonar conserva inicialmente el vendedor de origen'
);

sellerAssertSame(
    12,
    resolveProformaSellerId(['id' => 12, 'role' => 'commercial_executive'], $original, false, true, false, ['seller_id' => 7]),
    'un usuario sin permiso no puede reasignar vendedor al clonar'
);

sellerAssertSame(
    7,
    resolveProformaSellerId($assistant, null, false, false, true, ['seller_id' => 7]),
    'el asistente comercial puede seleccionar al vendedor firmante'
);

sellerAssertSame(
    0,
    resolveProformaSellerId($assistant, null, false, false, true, []),
    'el asistente comercial debe seleccionar un vendedor antes de emitir'
);

echo PHP_EOL . 'Pruebas de asignación de vendedor completadas.' . PHP_EOL;
