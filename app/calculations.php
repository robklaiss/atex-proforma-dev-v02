<?php

declare(strict_types=1);

function calculateLineItem(string $conditionType, float $quantity, int $rentalDays, float $unitPrice, float $taxRate): array
{
    $days = $conditionType === 'alquiler' ? max(1, $rentalDays) : 1;
    $subtotal = round(max(0, $quantity) * max(0, $unitPrice) * $days, 2);
    $taxAmount = round($subtotal * max(0, $taxRate) / 100, 2);
    $total = round($subtotal + $taxAmount, 2);

    return [
        'rental_days' => $days,
        'subtotal' => $subtotal,
        'tax_amount' => $taxAmount,
        'total' => $total,
    ];
}

function normalizeDiscountPercent(float $discountPercent): float
{
    return round(min(100.0, max(0.0, $discountPercent)), 2);
}

function calculateProformaTotals(array $items, float $discountPercent): array
{
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) ($item['subtotal'] ?? 0);
    }

    $subtotal = round($subtotal, 2);
    $discountPercent = normalizeDiscountPercent($discountPercent);
    $discountAmount = round($subtotal * $discountPercent / 100, 2);
    $discountFactor = $subtotal > 0 ? max(0.0, ($subtotal - $discountAmount) / $subtotal) : 1.0;
    $taxTotal = 0.0;

    foreach ($items as $item) {
        $lineSubtotal = max(0.0, (float) ($item['subtotal'] ?? 0));
        $taxRate = max(0.0, (float) ($item['tax_rate'] ?? 0));
        $taxTotal += round($lineSubtotal * $discountFactor * $taxRate / 100, 2);
    }

    $taxTotal = round($taxTotal, 2);
    $total = round(max(0.0, $subtotal - $discountAmount) + $taxTotal, 2);

    return [
        'subtotal' => $subtotal,
        'discount_percent' => $discountPercent,
        'discount_amount' => $discountAmount,
        'tax_total' => $taxTotal,
        'total' => $total,
    ];
}

function proformaPdfFilename(string $number): string
{
    $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', str_replace('-', '_', trim($number))) ?? '';
    $safeNumber = trim($safeNumber, '_');
    if ($safeNumber === '') {
        $safeNumber = 'proforma';
    }

    return 'proforma_' . $safeNumber . '.pdf';
}

function proformaFormatOptions(): array
{
    return [
        'detallado' => 'Detallado',
        'generico' => 'Genérico',
    ];
}

function normalizeProformaFormat(?string $format): string
{
    $format = trim((string) $format);
    return array_key_exists($format, proformaFormatOptions()) ? $format : 'detallado';
}

function buildTaxSummary(array $items, float $discountPercent = 0.0): array
{
    $summary = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) ($item['subtotal'] ?? 0);
    }
    $subtotal = round($subtotal, 2);
    $discountAmount = round($subtotal * normalizeDiscountPercent($discountPercent) / 100, 2);
    $discountFactor = $subtotal > 0 ? max(0.0, ($subtotal - $discountAmount) / $subtotal) : 1.0;

    foreach ($items as $item) {
        $rate = (float) ($item['tax_rate'] ?? 0);
        $key = number_format($rate, 2, '.', '');
        if (!isset($summary[$key])) {
            $summary[$key] = [
                'label' => formatNumber($rate) . '%',
                'rate' => $rate,
                'amount' => 0.0,
            ];
        }
        $lineSubtotal = max(0.0, (float) ($item['subtotal'] ?? 0));
        $summary[$key]['amount'] += round($lineSubtotal * $discountFactor * max(0.0, $rate) / 100, 2);
    }

    return array_values($summary);
}
