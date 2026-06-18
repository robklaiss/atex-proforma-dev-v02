<?php

declare(strict_types=1);

function resolveProformaSellerId(
    array $currentUser,
    ?array $sourceProforma,
    bool $isEditMode,
    bool $isCloneMode,
    bool $canChooseSeller,
    array $input
): int {
    if ($isEditMode && is_array($sourceProforma)) {
        return (int) (($sourceProforma['seller_id'] ?? 0) ?: ($sourceProforma['created_by'] ?? 0));
    }

    if ($canChooseSeller) {
        if (array_key_exists('seller_id', $input)) {
            return (int) $input['seller_id'];
        }
        if ($isCloneMode && is_array($sourceProforma)) {
            return (int) (($sourceProforma['seller_id'] ?? 0) ?: ($sourceProforma['created_by'] ?? 0));
        }
        if (($currentUser['role'] ?? '') === 'assistant') {
            return 0;
        }
    }

    return (int) ($currentUser['id'] ?? 0);
}
