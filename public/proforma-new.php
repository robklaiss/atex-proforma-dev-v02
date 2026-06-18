<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/pdf.php';
require_once APP_PATH . '/layout.php';
require_once APP_PATH . '/proforma_seller.php';

requireAuth();

$pdo = db();
$currentUser = currentUser();
if (!canCreateProformas($currentUser)) {
    redirect(userHomePath($currentUser));
}
$canChooseSeller = canChooseProformaSeller($currentUser);
$isCurrentAdmin = isAdmin($currentUser);
$editId = max(0, (int) ($_POST['edit_id'] ?? $_GET['edit_id'] ?? 0));
$cloneId = $editId > 0 ? 0 : max(0, (int) ($_POST['clone_id'] ?? $_GET['clone_id'] ?? 0));
$sourceProforma = null;
$sourceProject = null;
$sourceItems = [];

if ($editId > 0 || $cloneId > 0) {
    $sourceId = $editId > 0 ? $editId : $cloneId;
    $sourceAction = $editId > 0 ? 'editar' : 'clonar';
    [$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'source_visible');
    $sourceStmt = $pdo->prepare(
        'SELECT p.*, c.empresa AS source_client_empresa
         FROM proformas p
         JOIN clients c ON c.id = p.client_id
         LEFT JOIN users seller ON seller.id = p.seller_id
         WHERE p.id = :id
           AND ' . $visibilitySql
    );
    $sourceStmt->execute([':id' => $sourceId] + $visibilityParams);
    $sourceProforma = $sourceStmt->fetch() ?: null;

    if (!$sourceProforma) {
        setFlash('error', 'No se encontro la proforma para ' . $sourceAction . '.');
        redirect('/proformas.php');
    }
    $sourceProject = findProjectById($pdo, (int) ($sourceProforma['project_id'] ?? 0));
    if (!$sourceProject) {
        setFlash('error', 'La proforma seleccionada no tiene un proyecto valido.');
        redirect('/proformas.php');
    }
    if ($editId > 0 && (string) ($sourceProforma['status'] ?? 'emitida') === 'venta_ganada' && !$isCurrentAdmin) {
        setFlash('error', 'La proforma esta marcada como venta ganada y solo un administrador puede editarla.');
        redirect('/proformas.php');
    }

    $sourceItemsStmt = $pdo->prepare('SELECT * FROM proforma_items WHERE proforma_id = :id ORDER BY id');
    $sourceItemsStmt->execute([':id' => $sourceId]);
    $sourceItems = $sourceItemsStmt->fetchAll();
}

$clients = $pdo->query('SELECT * FROM clients ORDER BY empresa COLLATE NOCASE')->fetchAll();
$products = $pdo->query('SELECT * FROM products WHERE activo = 1 ORDER BY nombre COLLATE NOCASE')->fetchAll();
$allProducts = $pdo->query('SELECT * FROM products ORDER BY nombre COLLATE NOCASE')->fetchAll();
$taxes = $pdo->query('SELECT * FROM taxes WHERE activo = 1 ORDER BY porcentaje DESC, nombre COLLATE NOCASE')->fetchAll();
$signerRoleParams = [];
$signerRolePlaceholders = [];
foreach (salesSignerRoles() as $index => $role) {
    $key = ':signer_role_' . $index;
    $signerRoleParams[$key] = $role;
    $signerRolePlaceholders[] = $key;
}
$signersStmt = $pdo->prepare(
    'SELECT id, username, role, first_name, last_name, email, phone, unit,
            commercial_position, signature_image
     FROM users
     WHERE role IN (' . implode(', ', $signerRolePlaceholders) . ')
     ORDER BY first_name COLLATE NOCASE, last_name COLLATE NOCASE, username COLLATE NOCASE'
);
$signersStmt->execute($signerRoleParams);
$signers = $signersStmt->fetchAll();
$availableCountryUnits = availableCountryUnitsForUser($pdo, $currentUser);
$countryUnitMap = [];
$countryUnitConfig = [];
foreach ($availableCountryUnits as $countryUnit) {
    $countryUnitId = (int) $countryUnit['id'];
    $activeRate = findActiveExchangeRate($pdo, $countryUnitId);
    $countryUnitMap[$countryUnitId] = $countryUnit;
    $countryUnitConfig[$countryUnitId] = [
        'id' => $countryUnitId,
        'name' => (string) $countryUnit['name'],
        'currency_code' => (string) $countryUnit['currency_code'],
        'currency_symbol' => (string) $countryUnit['currency_symbol'],
        'rate_from_usd' => $activeRate ? (float) $activeRate['rate_from_usd'] : null,
    ];
}
$contactsByClient = [];
$productMap = [];
$taxMap = [];
$signerMap = [];
$defaultTaxIdByCountry = [];
$defaultTaxLabelByCountry = [];
foreach ($clients as $client) {
    $clientId = (int) $client['id'];
    $contactsByClient[$clientId] = commercialContactsForCompany($pdo, $clientId);
}
foreach ($allProducts as $product) {
    $productMap[(int) $product['id']] = $product;
}
foreach ($signers as $signer) {
    $signerMap[(int) $signer['id']] = $signer;
}
foreach ($taxes as $tax) {
    $taxId = (int) $tax['id'];
    $taxCountry = trim((string) ($tax['paises'] ?? ''));
    $taxMap[$taxId] = $tax;

    if ($taxCountry !== '' && !isset($defaultTaxIdByCountry[$taxCountry])) {
        $defaultTaxIdByCountry[$taxCountry] = $taxId;
        $defaultTaxLabelByCountry[$taxCountry] = (string) $tax['nombre'] . ' (' . formatNumber((float) $tax['porcentaje']) . '%)';
    }
}

$itemPayload = static function (array $item) use ($productMap): array {
    $productId = (int) ($item['product_id'] ?? 0);
    $product = $productMap[$productId] ?? null;
    $productName = is_array($product) ? (string) $product['nombre'] : (string) ($item['description'] ?? '');
    $salePrice = is_array($product) ? (float) $product['precio_venta'] : (float) ($item['unit_price'] ?? 0);
    $storedRentalPrice = is_array($product) ? (float) $product['precio_alquiler'] : 0.0;
    $defaultCondition = stripos($productName, 'ALQUILER') === 0 ? 'alquiler' : 'venta';
    $rentalPrice = $storedRentalPrice > 0 ? $storedRentalPrice : ($defaultCondition === 'alquiler' ? $salePrice : 0.0);

    return [
        'product_id' => $productId,
        'description' => $productName,
        'condition_type' => (string) ($item['condition_type'] ?? $defaultCondition),
        'quantity' => parseDecimalInput((string) ($item['quantity'] ?? '1')),
        'rental_days' => (int) ($item['rental_days'] ?? 1),
        'unit_price' => parseDecimalInput((string) ($item['unit_price'] ?? '0')),
        'tax_id' => (int) ($item['tax_id'] ?? 0),
        'sale_price' => $salePrice,
        'rental_price' => $rentalPrice,
        'default_condition' => $defaultCondition,
    ];
};

$error = null;
$isEditMode = $editId > 0 && is_array($sourceProforma);
$isCloneMode = $cloneId > 0 && !$isEditMode && is_array($sourceProforma);
$emissionDefault = (string) ($_POST['emission_date'] ?? ($isEditMode ? $sourceProforma['emission_date'] : date('Y-m-d')));
$validityDaysDefault = (int) (
    $_POST['validity_days']
    ?? (($isEditMode || $isCloneMode) ? ($sourceProforma['validity_days'] ?? 10) : 10)
);
if (!array_key_exists($validityDaysDefault, commercialValidityOptions())) {
    $validityDaysDefault = 10;
}
$expirationDefault = (string) (
    $_POST['expiration_date']
    ?? (($isEditMode || $isCloneMode)
        ? ($sourceProforma['expiration_date'] ?? date('Y-m-d', strtotime('+' . $validityDaysDefault . ' days')))
        : date('Y-m-d', strtotime('+' . $validityDaysDefault . ' days')))
);
$conditionsDefault = (string) ($_POST['commercial_conditions'] ?? (($isEditMode || $isCloneMode) ? $sourceProforma['commercial_conditions'] : ''));
$projectNameDefault = (string) ($_POST['project_name'] ?? (($isEditMode || $isCloneMode) ? ($sourceProject['name'] ?? $sourceProforma['project_name'] ?? '') : ''));
$selectedProjectId = (int) ($_POST['project_id'] ?? (($isEditMode || $isCloneMode) ? ($sourceProject['id'] ?? 0) : 0));
$selectedProject = findProjectById($pdo, $selectedProjectId);
$formatDefault = normalizeProformaFormat((string) ($_POST['format_type'] ?? (($isEditMode || $isCloneMode) ? ($sourceProforma['format_type'] ?? 'detallado') : 'detallado')));
$fallbackCountryUnit = findCountryUnitByName($pdo, (string) ($currentUser['unit'] ?? defaultCountry()));
$countryUnitDefault = (int) (
    $_POST['country_unit_id']
    ?? (($isEditMode || $isCloneMode) ? ($sourceProforma['country_unit_id'] ?? 0) : 0)
);
if ($countryUnitDefault <= 0 || !isset($countryUnitMap[$countryUnitDefault])) {
    $countryUnitDefault = (int) ($fallbackCountryUnit['id'] ?? array_key_first($countryUnitMap) ?? 0);
}
$currencyModeDefault = normalizeProformaCurrencyMode((string) (
    $_POST['currency_mode']
    ?? (($isEditMode || $isCloneMode) ? ($sourceProforma['currency_mode'] ?? 'USD') : 'USD')
));
$currencyDefault = $currencyModeDefault === 'LOCAL' && isset($countryUnitMap[$countryUnitDefault])
    ? normalizeCurrencyCode((string) $countryUnitMap[$countryUnitDefault]['currency_code'])
    : 'USD';
$discountDefault = (string) ($_POST['discount_percent'] ?? (($isEditMode || $isCloneMode) ? normalizeDiscountPercent((float) ($sourceProforma['discount_percent'] ?? 0)) : '0'));
$selectedClientId = (int) ($_POST['company_id'] ?? $_POST['client_id'] ?? ($isEditMode ? $sourceProforma['client_id'] : 0));
$selectedContactId = (int) ($_POST['contact_id'] ?? ($isEditMode ? ($sourceProforma['contact_id'] ?? $sourceProforma['client_contact_id'] ?? 0) : 0));
$selectedContactEmailId = (int) ($_POST['contact_email_id'] ?? ($isEditMode ? ($sourceProforma['contact_email_id'] ?? 0) : 0));
$selectedCompany = findCompanyById($pdo, $selectedClientId);
$companyRucDefault = (string) ($_POST['company_ruc'] ?? ($selectedCompany['ruc'] ?? ''));
$companyNameDefault = (string) ($_POST['company_name'] ?? ($selectedCompany['empresa'] ?? ''));
$companyAddressDefault = (string) ($_POST['company_address'] ?? ($selectedCompany['direccion'] ?? ''));
$companyPhoneDefault = (string) ($_POST['company_phone'] ?? ($selectedCompany['telefono'] ?? ''));
$companyEmailDefault = (string) ($_POST['company_email'] ?? ($selectedCompany['email'] ?? ''));
$companyCountryUnitDefault = (int) (
    $_POST['company_country_unit_id']
    ?? ($selectedCompany['country_unit_id'] ?? $countryUnitDefault)
);
$contactModeDefault = (string) ($_POST['contact_mode'] ?? ($selectedContactId > 0 ? 'existing' : 'new'));
if (!in_array($contactModeDefault, ['existing', 'new'], true)) {
    $contactModeDefault = $selectedContactId > 0 ? 'existing' : 'new';
}
$newContactNameDefault = (string) ($_POST['contact_full_name'] ?? '');
$newContactPhoneDefault = (string) ($_POST['contact_phone'] ?? '');
$newContactPositionDefault = (string) ($_POST['contact_position'] ?? '');
$newContactPrimaryEmailDefault = (string) ($_POST['contact_primary_email'] ?? '');
$newContactSecondaryEmailsDefault = (string) ($_POST['contact_secondary_emails'] ?? '');
$selectedSellerId = resolveProformaSellerId(
    $currentUser ?? [],
    $sourceProforma,
    $isEditMode,
    $isCloneMode,
    $canChooseSeller,
    $_POST
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();

        $companyRuc = trim((string) ($_POST['company_ruc'] ?? ''));
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $companyAddress = trim((string) ($_POST['company_address'] ?? ''));
        $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));
        $companyEmail = trim((string) ($_POST['company_email'] ?? ''));
        $companyCountryUnitId = (int) ($_POST['company_country_unit_id'] ?? 0);
        $contactMode = (string) ($_POST['contact_mode'] ?? '');
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $contactEmailId = (int) ($_POST['contact_email_id'] ?? 0);
        $contactFullName = trim((string) ($_POST['contact_full_name'] ?? ''));
        $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
        $contactPosition = trim((string) ($_POST['contact_position'] ?? ''));
        $contactPrimaryEmail = trim((string) ($_POST['contact_primary_email'] ?? ''));
        $contactSecondaryEmails = trim((string) ($_POST['contact_secondary_emails'] ?? ''));
        $sellerId = resolveProformaSellerId(
            $currentUser ?? [],
            $sourceProforma,
            $isEditMode,
            $isCloneMode,
            $canChooseSeller,
            $_POST
        );
        $projectIdHint = max(0, (int) ($_POST['project_id'] ?? 0));
        $projectName = normalizeProjectDisplayName((string) ($_POST['project_name'] ?? ''));
        $emissionDate = trim((string) ($_POST['emission_date'] ?? ''));
        $validityDays = validateValidityDays((int) ($_POST['validity_days'] ?? 10));
        $conditions = sanitizePlainText((string) ($_POST['commercial_conditions'] ?? ''));
        $formatType = (string) ($_POST['format_type'] ?? 'detallado');
        $deliveryAction = (string) ($_POST['delivery_action'] ?? 'save');
        $shouldSendEmail = $deliveryAction === 'send';
        $countryUnitId = (int) ($_POST['country_unit_id'] ?? 0);
        $currencyMode = normalizeProformaCurrencyMode((string) ($_POST['currency_mode'] ?? 'USD'));
        $discountPercent = parseDecimalInput((string) ($_POST['discount_percent'] ?? '0'));
        $rawItems = $_POST['items'] ?? [];

        $existingCompany = findCompanyByRuc($pdo, $companyRuc);
        $prospectiveCompanyUnit = findCountryUnitById(
            $pdo,
            $existingCompany ? (int) ($existingCompany['country_unit_id'] ?? 0) : $companyCountryUnitId
        );
        $clientCountry = trim((string) (
            $existingCompany['pais']
            ?? $prospectiveCompanyUnit['name']
            ?? ''
        ));
        if (normalizeRuc($companyRuc) === '') {
            throw new RuntimeException('El RUC de la empresa es obligatorio.');
        }
        if (!$existingCompany && $companyName === '') {
            throw new RuntimeException('Completa los datos de la empresa nueva.');
        }
        if (!$existingCompany && !$prospectiveCompanyUnit) {
            throw new RuntimeException('Selecciona una unidad país válida para la empresa.');
        }
        if ($projectName === '') {
            throw new RuntimeException('Completa el nombre del proyecto.');
        }
        if (textLength($projectName) > 160) {
            throw new RuntimeException('El nombre del proyecto no puede superar 160 caracteres.');
        }
        if (!isset($signerMap[$sellerId])) {
            throw new RuntimeException('Selecciona un vendedor firmante valido.');
        }
        if (!isset($countryUnitMap[$countryUnitId])) {
            throw new RuntimeException('Selecciona una unidad país asignada a tu usuario.');
        }
        $currencySnapshot = resolveProformaCurrency($pdo, $countryUnitId, $currencyMode);
        $currencySnapshot['authorization_status'] = proformaAuthorizationStatusForSigner(
            $currencySnapshot,
            $signerMap[$sellerId]
        );
        if (
            $shouldSendEmail
            && $currencySnapshot['currency_mode'] === 'LOCAL'
            && $currencySnapshot['exchange_rate_used'] === null
        ) {
            throw new RuntimeException('La unidad seleccionada no tiene tipo de cambio vigente. Puedes guardar la proforma sin enviar hasta que se configure.');
        }
        $signature = userSignature($signerMap[$sellerId]);
        $missingSignature = signatureMissingFields($signature);
        if ($missingSignature !== []) {
            throw new RuntimeException('Completa la firma del vendedor seleccionado: ' . implode(', ', $missingSignature) . '.');
        }
        if (!isAllowedCountry($clientCountry)) {
            throw new RuntimeException('El país del cliente no es valido.');
        }
        if (!isset($defaultTaxIdByCountry[$clientCountry])) {
            throw new RuntimeException('No hay un impuesto activo configurado para el país del cliente.');
        }
        if (!isValidDate($emissionDate)) {
            throw new RuntimeException('La fecha de emisión debe tener formato válido.');
        }
        if (textLength($conditions) > 12000) {
            throw new RuntimeException('Las observaciones no pueden superar 12000 caracteres.');
        }
        if (!array_key_exists($formatType, proformaFormatOptions())) {
            throw new RuntimeException('Selecciona un formato de proforma valido.');
        }
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new RuntimeException('El descuento debe estar entre 0 y 100%.');
        }
        if (!is_array($rawItems) || count($rawItems) === 0) {
            throw new RuntimeException('Agrega al menos un producto.');
        }
        if (!in_array($contactMode, ['existing', 'new'], true)) {
            throw new RuntimeException('Toda proforma debe tener un contacto destinatario específico.');
        }
        if ($contactMode === 'existing' && $contactId <= 0) {
            throw new RuntimeException('Selecciona el contacto destinatario de la proforma.');
        }

        $items = [];

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $productId = (int) ($rawItem['product_id'] ?? 0);
            $taxId = (int) ($rawItem['tax_id'] ?? 0);
            $conditionType = (string) ($rawItem['condition_type'] ?? 'venta');
            $quantity = parseDecimalInput((string) ($rawItem['quantity'] ?? '0'));
            $rentalDays = (int) ($rawItem['rental_days'] ?? 1);
            $unitPrice = parseDecimalInput((string) ($rawItem['unit_price'] ?? '0'));

            if (!isset($productMap[$productId])) {
                throw new RuntimeException('Uno de los productos seleccionados no existe.');
            }
            if (!isset($taxMap[$taxId])) {
                throw new RuntimeException('Selecciona un impuesto valido para cada linea.');
            }
            if (trim((string) ($taxMap[$taxId]['paises'] ?? '')) !== $clientCountry) {
                throw new RuntimeException('El impuesto seleccionado no corresponde al país del cliente.');
            }
            if (!in_array($conditionType, ['venta', 'alquiler'], true)) {
                throw new RuntimeException('Condicion invalida.');
            }
            if ($quantity <= 0 || $unitPrice < 0 || $rentalDays < 1) {
                throw new RuntimeException('Cantidad, dias y precios deben ser validos.');
            }

            $taxRate = (float) $taxMap[$taxId]['porcentaje'];
            $calculated = calculateLineItem($conditionType, $quantity, $rentalDays, $unitPrice, $taxRate);
            $item = [
                'product_id' => $productId,
                'description' => $productMap[$productId]['nombre'],
                'condition_type' => $conditionType,
                'quantity' => $quantity,
                'rental_days' => $calculated['rental_days'],
                'unit_price' => round($unitPrice, 2),
                'tax_id' => $taxId,
                'tax_rate' => $taxRate,
                'subtotal' => $calculated['subtotal'],
                'tax_amount' => $calculated['tax_amount'],
                'total' => $calculated['total'],
            ];
            $items[] = $item;
        }

        if ($items === []) {
            throw new RuntimeException('Agrega al menos un producto valido.');
        }

        $totals = calculateProformaTotals($items, $discountPercent);
        $subtotal = $totals['subtotal'];
        $discountPercent = $totals['discount_percent'];
        $discountAmount = $totals['discount_amount'];
        $taxTotal = $totals['tax_total'];
        $grandTotal = $totals['total'];

        createDatabaseBackup();
        $pdo->beginTransaction();
        $pdfPath = null;

        try {
            $company = resolveCompanyFromQuote(
                $pdo,
                $companyRuc,
                $companyName,
                $companyAddress,
                $companyPhone,
                $companyEmail,
                $companyCountryUnitId,
                (int) ($_SESSION['user_id'] ?? 0)
            );
            $selectedContact = null;
            $selectedContactEmail = null;
            if ($contactMode === 'existing' && $contactId > 0) {
                $contactSelection = selectContactForCompany(
                    $pdo,
                    (int) $company['id'],
                    $contactId,
                    $contactEmailId
                );
                $selectedContact = $contactSelection['contact'];
                $selectedContactEmail = $contactSelection['email'];
            } elseif ($contactMode === 'new') {
                $selectedContact = createOrReuseContactForCompany(
                    $pdo,
                    (int) $company['id'],
                    $contactFullName,
                    $contactPhone,
                    $contactPosition,
                    $contactPrimaryEmail,
                    $contactSecondaryEmails
                );
                $selectedContactEmail = findPrimaryContactEmail($pdo, (int) $selectedContact['id']);
            } else {
                throw new RuntimeException('Toda proforma debe tener un contacto destinatario específico.');
            }

            $recipientEmail = trim((string) ($selectedContactEmail['email'] ?? ''));
            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $recipientEmail = trim((string) ($company['email'] ?? ''));
            }
            if ($shouldSendEmail && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('La empresa o el contacto seleccionado necesita un email válido para enviar la propuesta.');
            }

            if ($isEditMode) {
                $project = findProjectById($pdo, (int) ($sourceProforma['project_id'] ?? 0));
                if (!$project) {
                    throw new RuntimeException('La proforma original no tiene un proyecto valido.');
                }
                if (!hash_equals((string) $project['normalized_name'], normalizeProjectName($projectName))) {
                    throw new RuntimeException('Una revision debe permanecer asociada al proyecto original.');
                }
            } else {
                $project = resolveProject($pdo, $projectName, $projectIdHint);
            }

            $projectName = (string) $project['name'];
            $allocation = allocateProjectProformaNumber($pdo, (int) $project['id'], $emissionDate);
            $number = (string) $allocation['number'];
            $projectSequence = (int) $allocation['sequence'];
            $parentProformaId = $isEditMode ? (int) $sourceProforma['id'] : null;
            $versionNumber = $isEditMode ? nextProformaVersionNumber($pdo, (int) $sourceProforma['id']) : 1;
            $createdAt = nowIso();
            $commercialFields = buildProformaCommercialFields(
                $company,
                $selectedContact,
                $selectedContactEmail,
                $validityDays,
                $createdAt
            );
            $expirationDate = (string) $commercialFields['expiration_date'];
            $insert = $pdo->prepare(
                'INSERT INTO proformas
                 (proforma_number, project_id, parent_proforma_id, version_number, project_sequence,
                  client_id, client_contact_id, company_id, contact_id, contact_email_id,
                  company_name_snapshot, contact_name_snapshot, contact_email_snapshot,
                  contact_name, contact_email, contact_phone,
                  project_name, emission_date, expiration_date, validity_days, expires_at, commercial_conditions,
                  currency_code, currency_mode, currency_symbol, country_unit_id, exchange_rate_used,
                  exchange_rate_source, exchange_rate_authorized_by, exchange_rate_authorized_at, authorization_status,
                  format_type, subtotal, discount_percent, discount_amount, tax_total, total,
                  seller_id, signer_role, signer_name, signer_position, signer_email, signer_phone, signer_unit,
                  signer_signature_image, created_by, created_at)
                 VALUES
                 (:proforma_number, :project_id, :parent_proforma_id, :version_number, :project_sequence,
                  :client_id, :client_contact_id, :company_id, :contact_id, :contact_email_id,
                  :company_name_snapshot, :contact_name_snapshot, :contact_email_snapshot,
                  :contact_name, :contact_email, :contact_phone,
                  :project_name, :emission_date, :expiration_date, :validity_days, :expires_at, :commercial_conditions,
                  :currency_code, :currency_mode, :currency_symbol, :country_unit_id, :exchange_rate_used,
                  :exchange_rate_source, NULL, NULL, :authorization_status,
                  :format_type, :subtotal, :discount_percent, :discount_amount, :tax_total, :total,
                  :seller_id, :signer_role, :signer_name, :signer_position, :signer_email, :signer_phone, :signer_unit,
                  :signer_signature_image, :created_by, :created_at)'
            );
            $insert->execute([
                ':proforma_number' => $number,
                ':project_id' => (int) $project['id'],
                ':parent_proforma_id' => $parentProformaId,
                ':version_number' => $versionNumber,
                ':project_sequence' => $projectSequence,
                ':client_id' => $commercialFields['client_id'],
                ':client_contact_id' => $commercialFields['client_contact_id'],
                ':company_id' => $commercialFields['company_id'],
                ':contact_id' => $commercialFields['contact_id'],
                ':contact_email_id' => $commercialFields['contact_email_id'],
                ':company_name_snapshot' => $commercialFields['company_name_snapshot'],
                ':contact_name_snapshot' => $commercialFields['contact_name_snapshot'],
                ':contact_email_snapshot' => $commercialFields['contact_email_snapshot'],
                ':contact_name' => $commercialFields['contact_name'],
                ':contact_email' => $commercialFields['contact_email'],
                ':contact_phone' => $commercialFields['contact_phone'],
                ':project_name' => $projectName,
                ':emission_date' => $emissionDate,
                ':expiration_date' => $expirationDate,
                ':validity_days' => $commercialFields['validity_days'],
                ':expires_at' => $commercialFields['expires_at'],
                ':commercial_conditions' => $conditions,
                ':currency_code' => $currencySnapshot['currency_code'],
                ':currency_mode' => $currencySnapshot['currency_mode'],
                ':currency_symbol' => $currencySnapshot['currency_symbol'],
                ':country_unit_id' => $currencySnapshot['country_unit_id'],
                ':exchange_rate_used' => $currencySnapshot['exchange_rate_used'],
                ':exchange_rate_source' => $currencySnapshot['exchange_rate_source'],
                ':authorization_status' => $currencySnapshot['authorization_status'],
                ':format_type' => $formatType,
                ':subtotal' => round($subtotal, 2),
                ':discount_percent' => $discountPercent,
                ':discount_amount' => round($discountAmount, 2),
                ':tax_total' => round($taxTotal, 2),
                ':total' => round($grandTotal, 2),
                ':seller_id' => $sellerId,
                ':signer_role' => (string) $signerMap[$sellerId]['role'],
                ':signer_name' => $signature['name'],
                ':signer_position' => $signature['position'],
                ':signer_email' => $signature['email'],
                ':signer_phone' => $signature['phone'],
                ':signer_unit' => $signature['unit'],
                ':signer_signature_image' => $signature['signature_image'],
                ':created_by' => (int) ($_SESSION['user_id'] ?? 0),
                ':created_at' => $createdAt,
            ]);
            $proformaId = (int) $pdo->lastInsertId();
            $disclaimerSnapshots = snapshotDefaultProformaDisclaimers($pdo, $proformaId);
            $publicToken = ensureProformaPublicToken($pdo, $proformaId);
            recordProformaEvent(
                $pdo,
                $proformaId,
                (int) ($_SESSION['user_id'] ?? 0),
                'CREATED',
                'Proforma ' . $number . ' creada.'
            );
            if ($isEditMode) {
                recordProformaEvent(
                    $pdo,
                    $proformaId,
                    (int) ($_SESSION['user_id'] ?? 0),
                    'EDITED_FROM',
                    'Nueva versión de ' . (string) $sourceProforma['proforma_number'] . '.'
                );
            }

            $itemInsert = $pdo->prepare(
                'INSERT INTO proforma_items
                 (proforma_id, product_id, description, condition_type, quantity, rental_days, unit_price, tax_id, tax_rate, subtotal, tax_amount, total)
                 VALUES
                 (:proforma_id, :product_id, :description, :condition_type, :quantity, :rental_days, :unit_price, :tax_id, :tax_rate, :subtotal, :tax_amount, :total)'
            );
            foreach ($items as $item) {
                $itemInsert->execute([
                    ':proforma_id' => $proformaId,
                    ':product_id' => $item['product_id'],
                    ':description' => $item['description'],
                    ':condition_type' => $item['condition_type'],
                    ':quantity' => $item['quantity'],
                    ':rental_days' => $item['rental_days'],
                    ':unit_price' => $item['unit_price'],
                    ':tax_id' => $item['tax_id'],
                    ':tax_rate' => $item['tax_rate'],
                    ':subtotal' => $item['subtotal'],
                    ':tax_amount' => $item['tax_amount'],
                    ':total' => $item['total'],
                ]);
            }

            $proformaData = [
                'proforma_number' => $number,
                'project_name' => $projectName,
                'emission_date' => $emissionDate,
                'expiration_date' => $expirationDate,
                'validity_days' => $commercialFields['validity_days'],
                'expires_at' => $commercialFields['expires_at'],
                'commercial_conditions' => $conditions,
                'disclaimers' => $disclaimerSnapshots,
                'currency_code' => $currencySnapshot['currency_code'],
                'currency_mode' => $currencySnapshot['currency_mode'],
                'currency_symbol' => $currencySnapshot['currency_symbol'],
                'country_unit_id' => $currencySnapshot['country_unit_id'],
                'exchange_rate_used' => $currencySnapshot['exchange_rate_used'],
                'exchange_rate_source' => $currencySnapshot['exchange_rate_source'],
                'authorization_status' => $currencySnapshot['authorization_status'],
                'format_type' => $formatType,
                'subtotal' => round($subtotal, 2),
                'discount_percent' => $discountPercent,
                'discount_amount' => round($discountAmount, 2),
                'tax_total' => round($taxTotal, 2),
                'total' => round($grandTotal, 2),
                'signer_role' => (string) $signerMap[$sellerId]['role'],
                'contact_name' => $commercialFields['contact_name'],
                'contact_email' => $commercialFields['contact_email'],
                'contact_phone' => $commercialFields['contact_phone'],
                'signer_name' => $signature['name'],
                'signer_position' => $signature['position'],
                'signer_email' => $signature['email'],
                'signer_phone' => $signature['phone'],
                'signer_unit' => $signature['unit'],
                'signer_signature_image' => $signature['signature_image'],
                'public_token' => $publicToken,
            ];
            $pdfFilename = proformaPdfFilename($number);
            $pdfPath = PROFORMA_STORAGE_PATH . '/' . $pdfFilename;
            generateProformaPdf($proformaData, $company, $items, buildTaxSummary($items, $discountPercent), $pdfPath);

            $update = $pdo->prepare('UPDATE proformas SET pdf_path = :pdf_path WHERE id = :id');
            $update->execute([
                ':pdf_path' => storageRelativePath($pdfPath),
                ':id' => $proformaId,
            ]);
            $pdo->commit();

            if ($shouldSendEmail) {
                try {
                    sendProformaCustomerEmail($pdo, $proformaId);
                    setFlash('success', 'Proforma guardada y enviada al email del cliente.');
                } catch (Throwable $mailException) {
                    setFlash('error', 'La proforma se guardo, pero no se pudo enviar el email: ' . $mailException->getMessage());
                }
            } else {
                setFlash('success', 'Proforma guardada sin enviar al cliente.');
            }
            redirect('/proforma-preview.php?id=' . $proformaId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($pdfPath && is_file($pdfPath)) {
                unlink($pdfPath);
            }
            throw $exception;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $projectNameDefault = (string) ($_POST['project_name'] ?? $projectNameDefault);
        $emissionDefault = (string) ($_POST['emission_date'] ?? $emissionDefault);
        $expirationDefault = (string) ($_POST['expiration_date'] ?? $expirationDefault);
        $validityDaysDefault = (int) ($_POST['validity_days'] ?? $validityDaysDefault);
        $countryUnitDefault = (int) ($_POST['country_unit_id'] ?? $countryUnitDefault);
        $currencyModeDefault = normalizeProformaCurrencyMode((string) ($_POST['currency_mode'] ?? $currencyModeDefault));
        $currencyDefault = $currencyModeDefault === 'LOCAL' && isset($countryUnitMap[$countryUnitDefault])
            ? normalizeCurrencyCode((string) $countryUnitMap[$countryUnitDefault]['currency_code'])
            : 'USD';
        $discountDefault = (string) ($_POST['discount_percent'] ?? $discountDefault);
    }
}

$initialItems = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['items']) && is_array($_POST['items'])) {
    foreach ($_POST['items'] as $postedItem) {
        if (is_array($postedItem)) {
            $initialItems[] = $itemPayload($postedItem);
        }
    }
} elseif ($isEditMode || $isCloneMode) {
    foreach ($sourceItems as $sourceItem) {
        $initialItems[] = $itemPayload($sourceItem);
    }
}

$pageTitle = $isEditMode ? 'Editar Proforma' : ($isCloneMode ? 'Clonar Proforma' : 'Nueva Proforma');
$submitLabel = 'Guardar';
$selectedSigner = $signerMap[$selectedSellerId] ?? null;
$sellerCanBeChanged = $canChooseSeller && !$isEditMode;
$formatOptions = proformaFormatOptions();

renderHeader($pageTitle);
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<?php if ($isEditMode): ?>
    <div class="flash info">Editando la proforma <?= e((string) $sourceProforma['proforma_number']) ?>. Al guardar se creara una nueva emision con el siguiente secuencial del proyecto.</div>
<?php endif; ?>
<?php if ($isCloneMode): ?>
    <div class="flash info">Clonando la proforma <?= e((string) $sourceProforma['proforma_number']) ?> de <?= e((string) $sourceProforma['source_client_empresa']) ?>. Selecciona el cliente destino; al guardar se generara un numero nuevo.</div>
<?php endif; ?>

<?php if (!$products || !$taxes): ?>
    <section class="panel">
        <p class="muted">Carga al menos un producto y un impuesto activo antes de crear una proforma.</p>
        <div class="toolbar">
            <a class="button" href="<?= e(publicPath('/products.php')) ?>">Productos</a>
            <a class="button" href="<?= e(publicPath('/taxes.php')) ?>">Impuestos</a>
        </div>
    </section>
<?php endif; ?>

<form method="post" id="proforma-form">
    <?= csrfField() ?>
    <?php if ($isEditMode): ?>
        <input type="hidden" name="edit_id" value="<?= (int) $editId ?>">
    <?php elseif ($isCloneMode): ?>
        <input type="hidden" name="clone_id" value="<?= (int) $cloneId ?>">
    <?php endif; ?>

    <section class="panel">
        <h2>Datos de emisión</h2>
        <div class="grid-form">
            <label>
                Unidad país
                <select name="country_unit_id" id="country-unit-id" required>
                    <?php foreach ($availableCountryUnits as $countryUnit): ?>
                        <option value="<?= (int) $countryUnit['id'] ?>" <?= $countryUnitDefault === (int) $countryUnit['id'] ? 'selected' : '' ?>>
                            <?= e($countryUnit['name']) ?> · <?= e($countryUnit['currency_symbol']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Moneda de emisión
                <select name="currency_mode" id="currency-mode" required>
                    <option value="USD" <?= $currencyModeDefault === 'USD' ? 'selected' : '' ?>>US$</option>
                    <option value="LOCAL" <?= $currencyModeDefault === 'LOCAL' ? 'selected' : '' ?>>Moneda local de la unidad país</option>
                </select>
            </label>
            <label class="wide project-autocomplete">
                Nombre del proyecto
                <input type="hidden" name="project_id" id="project-id" value="<?= $selectedProjectId ?>">
                <input
                    type="text"
                    name="project_name"
                    id="project-name"
                    maxlength="160"
                    autocomplete="off"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="project-suggestions"
                    aria-expanded="false"
                    required
                    value="<?= e($projectNameDefault) ?>"
                    <?= $isEditMode ? 'readonly' : '' ?>
                >
                <div id="project-suggestions" class="project-suggestions" role="listbox" hidden></div>
                <span class="field-hint" id="project-status">
                    <?php if ($selectedProject): ?>
                        Proyecto existente · Prefijo <?= e((string) $selectedProject['prefix']) ?>
                    <?php else: ?>
                        Escribe para buscar un proyecto existente o crear uno nuevo.
                    <?php endif; ?>
                </span>
            </label>
            <div class="wide flash warning" id="exchange-rate-warning" hidden></div>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <h2><?= $isCloneMode ? 'Empresa destino y contacto' : 'Empresa y contacto cliente' ?></h2>
            <a class="button small" href="<?= e(publicPath('/clients.php')) ?>">Administrar empresas</a>
        </div>
        <input type="hidden" name="company_id" id="company-id" value="<?= $selectedClientId > 0 ? $selectedClientId : '' ?>">
        <div class="grid-form commercial-company-fields">
            <label>
                RUC
                <span class="input-action">
                    <input
                        name="company_ruc"
                        id="company-ruc"
                        required
                        autocomplete="off"
                        value="<?= e($companyRucDefault) ?>"
                        placeholder="Ej. 80012345-6"
                    >
                    <button class="button" type="button" id="company-search-button">Buscar</button>
                </span>
                <span class="field-hint" id="company-search-status">Ingresa el RUC para buscar o crear la empresa.</span>
            </label>
            <label>
                Unidad país de la empresa
                <select name="company_country_unit_id" id="company-country-unit-id" required>
                    <?php foreach ($availableCountryUnits as $countryUnit): ?>
                        <option value="<?= (int) $countryUnit['id'] ?>" <?= $companyCountryUnitDefault === (int) $countryUnit['id'] ? 'selected' : '' ?>>
                            <?= e($countryUnit['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Empresa / Razón social
                <input name="company_name" id="company-name" maxlength="180" value="<?= e($companyNameDefault) ?>">
            </label>
            <label>
                Email general
                <input type="email" name="company_email" id="company-email" value="<?= e($companyEmailDefault) ?>">
            </label>
            <label class="wide">
                Dirección
                <input name="company_address" id="company-address" value="<?= e($companyAddressDefault) ?>">
            </label>
            <label>
                Teléfono
                <input name="company_phone" id="company-phone" value="<?= e($companyPhoneDefault) ?>">
            </label>
        </div>

        <div class="commercial-contact-block">
            <label>
                Contacto cliente
                <select name="contact_mode" id="contact-mode" required>
                    <option value="existing" <?= $contactModeDefault === 'existing' ? 'selected' : '' ?>>Seleccionar contacto existente</option>
                    <option value="new" <?= $contactModeDefault === 'new' ? 'selected' : '' ?>>Crear o asociar contacto</option>
                </select>
            </label>

            <div class="grid-form" id="existing-contact-fields" <?= $contactModeDefault === 'existing' ? '' : 'hidden' ?>>
                <label>
                    Contacto
                    <select name="contact_id" id="contact-id">
                        <option value="">Seleccionar contacto</option>
                    </select>
                    <span class="field-hint" id="contacts-empty">La empresa no tiene contactos asociados.</span>
                </label>
                <label>
                    Email para esta proforma
                    <select name="contact_email_id" id="contact-email-id">
                        <option value="">Email principal</option>
                    </select>
                </label>
            </div>

            <div class="grid-form" id="new-contact-fields" <?= $contactModeDefault === 'new' ? '' : 'hidden' ?>>
                <label>
                    Nombre y apellido
                    <input name="contact_full_name" id="contact-full-name" value="<?= e($newContactNameDefault) ?>">
                </label>
                <label>
                    Cargo
                    <input name="contact_position" value="<?= e($newContactPositionDefault) ?>">
                </label>
                <label>
                    Teléfono
                    <input name="contact_phone" value="<?= e($newContactPhoneDefault) ?>">
                </label>
                <label>
                    Email principal
                    <input type="email" name="contact_primary_email" value="<?= e($newContactPrimaryEmailDefault) ?>">
                </label>
                <label class="wide">
                    Emails secundarios
                    <textarea name="contact_secondary_emails" rows="2" placeholder="Separados por coma, espacio o salto de línea"><?= e($newContactSecondaryEmailsDefault) ?></textarea>
                    <span class="field-hint">Si el mismo nombre y email principal ya existen, se reutilizará el contacto y se asociará a esta empresa.</span>
                </label>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Firmante comercial</h2>
        <div class="grid-form">
            <label>
                Vendedor
                <?php if ($sellerCanBeChanged): ?>
                    <select name="seller_id" id="seller-select" required>
                        <option value="">Seleccionar vendedor</option>
                        <?php foreach ($signers as $signer): ?>
                            <?php $signature = userSignature($signer); ?>
                            <option
                                value="<?= (int) $signer['id'] ?>"
                                data-name="<?= e($signature['name']) ?>"
                                data-position="<?= e($signature['position']) ?>"
                                data-email="<?= e($signature['email']) ?>"
                                data-phone="<?= e($signature['phone']) ?>"
                                data-unit="<?= e($signature['unit']) ?>"
                                data-signature-loaded="<?= $signature['signature_image'] !== '' ? 'Sí' : 'No' ?>"
                                <?= $selectedSellerId === (int) $signer['id'] ? 'selected' : '' ?>
                            ><?= e(userFullName($signer)) ?> - <?= e(userRoleLabel((string) $signer['role'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="seller_id" id="seller-id" value="<?= $selectedSellerId ?>">
                    <input value="<?= e($selectedSigner ? userFullName($selectedSigner) : (string) ($sourceProforma['signer_name'] ?? userFullName($currentUser ?: []))) ?>" readonly>
                <?php endif; ?>
            </label>
            <div class="signature-preview" id="seller-signature">
                <strong>Firma que se adjuntara</strong>
                <?php
                $initialSignature = $selectedSigner
                    ? userSignature($selectedSigner)
                    : ($isEditMode && is_array($sourceProforma)
                        ? [
                            'name' => (string) ($sourceProforma['signer_name'] ?? ''),
                            'position' => (string) ($sourceProforma['signer_position'] ?? ''),
                            'email' => (string) ($sourceProforma['signer_email'] ?? ''),
                            'phone' => (string) ($sourceProforma['signer_phone'] ?? ''),
                            'unit' => (string) ($sourceProforma['signer_unit'] ?? ''),
                            'signature_image' => (string) ($sourceProforma['signer_signature_image'] ?? ''),
                        ]
                        : userSignature($currentUser ?: []));
                ?>
                <span><?= e($initialSignature['name'] !== '' ? $initialSignature['name'] : emptyFieldMarker()) ?></span>
                <span><?= e($initialSignature['position'] !== '' ? $initialSignature['position'] : emptyFieldMarker()) ?></span>
                <span><?= e($initialSignature['email'] !== '' ? $initialSignature['email'] : emptyFieldMarker()) ?></span>
                <span><?= e($initialSignature['phone'] !== '' ? $initialSignature['phone'] : emptyFieldMarker()) ?></span>
                <span><?= e($initialSignature['unit'] !== '' ? $initialSignature['unit'] : emptyFieldMarker()) ?></span>
                <span>Firma manuscrita: <?= $initialSignature['signature_image'] !== '' ? 'Sí' : 'No' ?></span>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Fecha y validez</h2>
        <div class="grid-form compact">
            <label>
                Fecha de emision
                <input type="date" name="emission_date" id="emission-date" required value="<?= e($emissionDefault) ?>">
            </label>
            <label>
                Validez
                <select name="validity_days" id="validity-days" required>
                    <?php foreach (commercialValidityOptions() as $days => $label): ?>
                        <option value="<?= (int) $days ?>" <?= $validityDaysDefault === (int) $days ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Vencimiento estimado
                <input type="date" name="expiration_date" id="expiration-date" readonly value="<?= e($expirationDefault) ?>">
                <span class="field-hint">El link y el documento vencerán según la validez seleccionada.</span>
            </label>
        </div>
    </section>

    <section class="panel">
        <h2>Formato</h2>
        <div class="segmented-control" role="radiogroup" aria-label="Formato de proforma">
            <?php foreach ($formatOptions as $value => $label): ?>
                <label>
                    <input
                        type="radio"
                        name="format_type"
                        value="<?= e($value) ?>"
                        <?= $formatDefault === $value ? 'checked' : '' ?>
                    >
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <h2>Productos</h2>
            <div class="product-picker">
                <input type="search" id="product-search" placeholder="Buscar producto o REF" autocomplete="off">
                <select id="product-picker">
                    <option value="">Seleccionar producto</option>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $productName = (string) $product['nombre'];
                        $salePrice = (float) $product['precio_venta'];
                        $storedRentalPrice = (float) $product['precio_alquiler'];
                        $defaultCondition = stripos($productName, 'ALQUILER') === 0 ? 'alquiler' : 'venta';
                        $rentalPrice = $storedRentalPrice > 0 ? $storedRentalPrice : ($defaultCondition === 'alquiler' ? $salePrice : 0.0);
                        ?>
                        <option
                            value="<?= (int) $product['id'] ?>"
                            data-name="<?= e($productName) ?>"
                            data-search="<?= e($product['nombre'] . ' ' . ($product['descripcion'] ?? '')) ?>"
                            data-sale="<?= e((string) $salePrice) ?>"
                            data-rental="<?= e((string) $rentalPrice) ?>"
                            data-default-condition="<?= e($defaultCondition) ?>"
                        ><?= e($productName) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="button" id="add-product">Agregar</button>
            </div>
        </div>

        <div class="table-wrap">
            <table class="items-table">
                <thead>
                <tr>
                    <th class="items-action-col">Accion</th>
                    <th>Producto</th>
                    <th>Condicion</th>
                    <th>Cantidad</th>
                    <th id="unit-price-header">Precio unitario base (US$)</th>
                    <th>Dias</th>
                    <th>Impuesto</th>
                    <th>Subtotal</th>
                    <th>Imp.</th>
                    <th>Total</th>
                </tr>
                </thead>
                <tbody id="items-body"></tbody>
                <tfoot>
                <tr>
                    <td colspan="7" class="right">Subtotal</td>
                    <td colspan="3" class="right" id="subtotal-display"><?= e(formatMoney(0.0, $currencyDefault)) ?></td>
                </tr>
                <tr>
                    <td colspan="7" class="right">
                        <label class="inline-discount" for="discount-percent">
                            Descuento (%)
                            <input type="number" min="0" max="100" step="0.01" name="discount_percent" id="discount-percent" value="<?= e($discountDefault) ?>">
                        </label>
                    </td>
                    <td colspan="3" class="right" id="discount-display"><?= e(formatMoney(0.0, $currencyDefault)) ?></td>
                </tr>
                <tr>
                    <td colspan="7" class="right">Impuestos</td>
                    <td colspan="3" class="right" id="tax-display"><?= e(formatMoney(0.0, $currencyDefault)) ?></td>
                </tr>
                <tr>
                    <td colspan="7" class="right">Total</td>
                    <td colspan="3" class="right" id="total-display"><?= e(formatMoney(0.0, $currencyDefault)) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section class="panel">
        <label>
            Observaciones y condiciones comerciales
            <textarea class="long-observations" name="commercial_conditions" id="conditions" maxlength="12000" rows="10"><?= e($conditionsDefault) ?></textarea>
        </label>
        <span class="field-hint">Se conservan los saltos de línea. Si el contenido no cabe en la primera hoja, continuará automáticamente.</span>
        <div class="counter"><span id="conditions-count">0</span>/12000</div>
    </section>

    <section class="form-actions">
        <button class="button primary" type="submit" name="delivery_action" value="save"><?= e($submitLabel) ?></button>
    </section>
</form>

<template id="item-template">
    <tr>
        <td class="items-action-col">
            <button class="button danger small remove-row" type="button" aria-label="Eliminar producto de la proforma" title="Eliminar producto">Eliminar</button>
        </td>
        <td class="product-cell">
            <input type="hidden" data-name="product_id">
            <input type="text" data-name="description" readonly>
        </td>
        <td>
            <select data-name="condition_type" class="condition-select">
                <option value="venta">Venta</option>
                <option value="alquiler">Alquiler</option>
            </select>
        </td>
        <td><input type="number" min="0.01" step="0.01" data-name="quantity" value="1" class="calc-input"></td>
        <td><input type="number" min="0" step="0.01" data-name="unit_price" value="0" class="calc-input"></td>
        <td><input type="number" min="1" step="1" data-name="rental_days" value="1" class="calc-input days-input" disabled></td>
        <td>
            <select data-name="tax_id" class="tax-select">
                <?php foreach ($taxes as $tax): ?>
                    <option value="<?= (int) $tax['id'] ?>" data-rate="<?= e((string) $tax['porcentaje']) ?>" data-country="<?= e($tax['paises'] ?? '') ?>"><?= e($tax['nombre']) ?> (<?= e(formatNumber((float) $tax['porcentaje'])) ?>%)</option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="right row-subtotal"><?= e(formatMoney(0.0, $currencyDefault)) ?></td>
        <td class="right row-tax"><?= e(formatMoney(0.0, $currencyDefault)) ?></td>
        <td class="right row-total"><?= e(formatMoney(0.0, $currencyDefault)) ?></td>
    </tr>
</template>

<script>
const currencyConfig = <?= json_encode(currencyOptions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const countryUnitConfig = <?= json_encode($countryUnitConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const countryUnitSelect = document.getElementById('country-unit-id');
const currencyModeSelect = document.getElementById('currency-mode');
const exchangeRateWarning = document.getElementById('exchange-rate-warning');
const unitPriceHeader = document.getElementById('unit-price-header');
const moneyFormatters = {};
const emptyMarker = <?= json_encode(emptyFieldMarker()) ?>;
const itemsBody = document.getElementById('items-body');
const productPicker = document.getElementById('product-picker');
const productSearch = document.getElementById('product-search');
const addProductButton = document.getElementById('add-product');
const template = document.getElementById('item-template');
const discountInput = document.getElementById('discount-percent');
const initialItems = <?= json_encode($initialItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const projectSearchUrl = <?= json_encode(publicPath('/project-search.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const companySearchUrl = <?= json_encode(publicPath('/company-search.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const contactsByCompanyConfig = <?= json_encode($contactsByClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const defaultTaxIdByCountry = <?= json_encode($defaultTaxIdByCountry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const initialContactId = <?= (int) $selectedContactId ?>;
const initialContactEmailId = <?= (int) $selectedContactEmailId ?>;
let itemIndex = 0;

function currentCountryUnit() {
    return countryUnitSelect ? (countryUnitConfig[countryUnitSelect.value] || null) : null;
}

function currentCurrencyMode() {
    return currencyModeSelect && currencyModeSelect.value === 'LOCAL' ? 'LOCAL' : 'USD';
}

function currentCurrencyCode() {
    const unit = currentCountryUnit();
    const code = currentCurrencyMode() === 'LOCAL' && unit ? unit.currency_code : 'USD';
    return /^[A-Z]{3}$/.test(String(code || '').toUpperCase()) ? String(code).toUpperCase() : 'USD';
}

function currentCurrency() {
    const currencyCode = currentCurrencyCode();
    const unit = currentCountryUnit();
    const configured = currencyConfig[currencyCode] || {
        symbol: currencyCode,
        decimals: 2,
        decimal_separator: ',',
        thousands_separator: '.',
    };

    if (currentCurrencyMode() !== 'LOCAL' || !unit || !unit.currency_symbol) {
        return configured;
    }

    return Object.assign({}, configured, {
        symbol: unit.currency_symbol,
    });
}

function currentExchangeRate() {
    if (currentCurrencyMode() === 'USD') {
        return 1;
    }

    const unit = currentCountryUnit();
    const rate = unit ? Number(unit.rate_from_usd) : 0;
    return Number.isFinite(rate) && rate > 0 ? rate : null;
}

function fmt(value) {
    const currencyCode = currentCurrencyCode();
    const currency = currentCurrency();
    const configuredDecimals = Number.parseInt(currency.decimals, 10);
    const decimals = Number.isFinite(configuredDecimals) ? Math.max(0, configuredDecimals) : 2;
    const exchangeRate = currentExchangeRate();
    if (currentCurrencyMode() === 'LOCAL' && exchangeRate === null) {
        return `${String(currency.symbol || '')} pendiente`;
    }
    const displayValue = roundMoney(value) * (exchangeRate || 1);
    const formatterKey = `${currencyCode}:${decimals}`;
    if (!moneyFormatters[formatterKey]) {
        moneyFormatters[formatterKey] = new Intl.NumberFormat(currencyCode === 'USD' ? 'en-US' : 'es-PY', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    return `${String(currency.symbol || '')} ${moneyFormatters[formatterKey].format(roundMoney(displayValue))}`;
}

function updateCurrencyHeader() {
    if (!unitPriceHeader) {
        return;
    }

    unitPriceHeader.textContent = 'Precio unitario base (US$)';
}

function updateExchangeRateWarning() {
    if (!exchangeRateWarning) {
        return;
    }

    const unit = currentCountryUnit();
    if (currentCurrencyMode() !== 'LOCAL') {
        exchangeRateWarning.hidden = true;
        exchangeRateWarning.textContent = '';
        return;
    }

    if (!unit || currentExchangeRate() === null) {
        exchangeRateWarning.hidden = false;
        exchangeRateWarning.textContent = 'No hay un tipo de cambio vigente para esta unidad. La proforma podrá guardarse como PENDING, pero no enviarse al cliente.';
        return;
    }

    exchangeRateWarning.hidden = false;
    exchangeRateWarning.textContent = `Tipo de cambio aplicado: 1 US$ = ${unit.rate_from_usd} ${unit.currency_symbol}. La proforma guardará este valor como snapshot y quedará PENDING.`;
}

function parseDecimal(value) {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }

    let normalized = String(value || '').trim();
    if (normalized === '') {
        return 0;
    }

    normalized = normalized.replace(/\s/g, '').replace(/[^\d,.-]/g, '');
    const lastComma = normalized.lastIndexOf(',');
    const lastDot = normalized.lastIndexOf('.');

    if (lastComma !== -1 && lastDot !== -1) {
        if (lastComma > lastDot) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        } else {
            normalized = normalized.replace(/,/g, '');
        }
    } else if (lastComma !== -1) {
        normalized = normalized.replace(',', '.');
    }

    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
}

function roundMoney(value) {
    return Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
}

function clampPercent(value) {
    return Math.min(100, Math.max(0, parseDecimal(value)));
}

function conditionPrice(row, condition) {
    if (condition === 'alquiler') {
        const rental = parseDecimal(row.dataset.rental);
        if (rental > 0) {
            return row.dataset.rental;
        }
    }

    return row.dataset.sale || '0';
}

function calculateRowValues(row) {
    const condition = row.querySelector('[data-name="condition_type"]').value;
    const quantity = Math.max(0, parseDecimal(row.querySelector('[data-name="quantity"]').value));
    const price = Math.max(0, parseDecimal(row.querySelector('[data-name="unit_price"]').value));
    const daysInput = row.querySelector('[data-name="rental_days"]');
    const days = condition === 'alquiler' ? Math.max(1, Math.trunc(parseDecimal(daysInput.value) || 1)) : 1;
    const taxOption = row.querySelector('[data-name="tax_id"]').selectedOptions[0];
    const taxRate = Math.max(0, parseDecimal(taxOption ? taxOption.dataset.rate : 0));
    const subtotal = roundMoney(quantity * price * days);
    const tax = roundMoney(subtotal * taxRate / 100);

    return {
        condition,
        days,
        subtotal,
        taxRate,
        tax,
        total: roundMoney(subtotal + tax),
    };
}

function assignNames(row, index) {
    row.querySelectorAll('[data-name]').forEach((field) => {
        field.name = `items[${index}][${field.dataset.name}]`;
    });
}

function updateRow(row) {
    const values = calculateRowValues(row);
    const daysInput = row.querySelector('[data-name="rental_days"]');
    daysInput.disabled = values.condition !== 'alquiler';
    if (values.condition !== 'alquiler') {
        daysInput.value = '1';
    } else {
        daysInput.value = String(values.days);
    }
    row.querySelector('.row-subtotal').textContent = fmt(values.subtotal);
    row.querySelector('.row-tax').textContent = fmt(values.tax);
    row.querySelector('.row-total').textContent = fmt(values.total);
    updateTotals();
}

function updateTotals() {
    const rows = Array.from(itemsBody.querySelectorAll('tr')).map((row) => calculateRowValues(row));
    const subtotal = rows.reduce((sum, values) => sum + values.subtotal, 0);
    const discountPercent = clampPercent(discountInput.value);
    const discount = roundMoney(subtotal * discountPercent / 100);
    const taxableSubtotal = Math.max(0, roundMoney(subtotal - discount));
    const discountFactor = subtotal > 0 ? Math.max(0, taxableSubtotal / subtotal) : 1;
    let tax = 0;

    rows.forEach((values) => {
        tax += roundMoney(values.subtotal * discountFactor * values.taxRate / 100);
    });

    tax = roundMoney(tax);
    const total = roundMoney(taxableSubtotal + tax);
    document.getElementById('subtotal-display').textContent = fmt(subtotal);
    document.getElementById('discount-display').textContent = discount > 0 ? '-' + fmt(discount) : fmt(discount);
    document.getElementById('tax-display').textContent = fmt(tax);
    document.getElementById('total-display').textContent = fmt(total);
}

function refreshMoneyDisplays() {
    itemsBody.querySelectorAll('tr').forEach((row) => {
        const values = calculateRowValues(row);
        row.querySelector('.row-subtotal').textContent = fmt(values.subtotal);
        row.querySelector('.row-tax').textContent = fmt(values.tax);
        row.querySelector('.row-total').textContent = fmt(values.total);
    });
    updateCurrencyHeader();
    updateExchangeRateWarning();
    updateTotals();
}

function normalizeSearch(value) {
    return value
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
}

const projectInput = document.getElementById('project-name');
const projectIdInput = document.getElementById('project-id');
const projectSuggestions = document.getElementById('project-suggestions');
const projectStatus = document.getElementById('project-status');
let projectSearchTimer = null;
let projectSearchController = null;

function closeProjectSuggestions() {
    projectSuggestions.hidden = true;
    projectSuggestions.replaceChildren();
    projectInput.setAttribute('aria-expanded', 'false');
}

function selectProject(project) {
    projectIdInput.value = String(project.id);
    projectInput.value = project.name;
    projectStatus.textContent = `Proyecto existente · Prefijo ${project.prefix}`;
    closeProjectSuggestions();
}

function renderProjectSuggestions(projects) {
    projectSuggestions.replaceChildren();

    projects.forEach((project) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'project-suggestion';
        button.setAttribute('role', 'option');

        const name = document.createElement('strong');
        name.textContent = project.name;
        const prefix = document.createElement('span');
        prefix.textContent = project.prefix;
        button.append(name, prefix);
        button.addEventListener('click', () => selectProject(project));
        projectSuggestions.appendChild(button);
    });

    projectSuggestions.hidden = projects.length === 0;
    projectInput.setAttribute('aria-expanded', projects.length > 0 ? 'true' : 'false');
}

async function searchProjects() {
    const query = projectInput.value.trim();
    if (query === '' || projectInput.readOnly) {
        closeProjectSuggestions();
        return;
    }

    if (projectSearchController) {
        projectSearchController.abort();
    }
    projectSearchController = new AbortController();

    try {
        const response = await fetch(`${projectSearchUrl}?q=${encodeURIComponent(query)}`, {
            headers: {'Accept': 'application/json'},
            signal: projectSearchController.signal,
        });
        if (!response.ok) {
            throw new Error('No se pudo buscar proyectos.');
        }
        const payload = await response.json();
        renderProjectSuggestions(Array.isArray(payload.projects) ? payload.projects : []);
    } catch (error) {
        if (error.name !== 'AbortError') {
            closeProjectSuggestions();
        }
    }
}

if (projectInput && !projectInput.readOnly) {
    projectInput.addEventListener('input', () => {
        projectIdInput.value = '';
        projectStatus.textContent = 'Si no seleccionas una coincidencia, se creara un proyecto nuevo.';
        window.clearTimeout(projectSearchTimer);
        projectSearchTimer = window.setTimeout(searchProjects, 180);
    });
    projectInput.addEventListener('focus', searchProjects);
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.project-autocomplete')) {
            closeProjectSuggestions();
        }
    });
}

function filterProductPicker() {
    const term = normalizeSearch(productSearch.value);
    let firstMatch = '';
    const currentValue = productPicker.value;
    let currentVisible = currentValue === '' && term === '';

    Array.from(productPicker.options).forEach((option) => {
        if (!option.value) {
            option.hidden = false;
            return;
        }

        const searchText = normalizeSearch(option.dataset.search || option.textContent);
        const matches = term === '' || searchText.includes(term);
        option.hidden = !matches;

        if (matches) {
            firstMatch ||= option.value;
            if (option.value === currentValue) {
                currentVisible = true;
            }
        }
    });

    if (term !== '' && firstMatch && !currentVisible) {
        productPicker.value = firstMatch;
    } else if (term === '') {
        productPicker.value = '';
    } else if (!firstMatch) {
        productPicker.value = '';
    }
}

function selectedClientCountry() {
    const unit = companyCountryUnitSelect
        ? (countryUnitConfig[companyCountryUnitSelect.value] || null)
        : null;
    return unit ? String(unit.name || '') : '';
}

function selectedClientDefaultTaxId() {
    const taxId = defaultTaxIdByCountry[selectedClientCountry()] || '';
    return String(taxId) === '0' ? '' : String(taxId);
}

function configureTaxSelect(taxSelect, forceDefault) {
    const country = selectedClientCountry();
    const defaultTaxId = selectedClientDefaultTaxId();
    let firstVisible = '';
    let currentVisible = taxSelect.value === '';

    Array.from(taxSelect.options).forEach((option) => {
        const matchesCountry = country === '' || option.dataset.country === country;
        option.hidden = !matchesCountry;

        if (matchesCountry) {
            firstVisible ||= option.value;
            if (option.value === taxSelect.value) {
                currentVisible = true;
            }
        }
    });

    if (defaultTaxId && (forceDefault || !currentVisible)) {
        taxSelect.value = defaultTaxId;
    } else if (!currentVisible) {
        taxSelect.value = firstVisible;
    }
}

function applyClientTaxToRows(forceDefault) {
    itemsBody.querySelectorAll('[data-name="tax_id"]').forEach((taxSelect) => {
        configureTaxSelect(taxSelect, forceDefault);
        const row = taxSelect.closest('tr');
        if (row) {
            updateRow(row);
        }
    });
}

function findProductOption(productId) {
    return Array.from(productPicker.options).find((option) => option.value === String(productId)) || null;
}

function itemValue(item, key, fallback) {
    if (Object.prototype.hasOwnProperty.call(item, key) && item[key] !== null && item[key] !== '') {
        return String(item[key]);
    }

    return fallback;
}

function appendItemRow(item, forceDefaultTax) {
    const productId = itemValue(item, 'product_id', '');
    const option = findProductOption(productId);
    const row = template.content.firstElementChild.cloneNode(true);
    assignNames(row, itemIndex++);
    row.dataset.sale = itemValue(item, 'sale_price', option ? (option.dataset.sale || '0') : itemValue(item, 'unit_price', '0'));
    row.dataset.rental = itemValue(item, 'rental_price', option ? (option.dataset.rental || '0') : '0');
    row.querySelector('[data-name="product_id"]').value = productId;
    row.querySelector('[data-name="description"]').value = itemValue(
        item,
        'description',
        option ? (option.dataset.name || option.textContent.trim()) : ''
    );
    const condition = itemValue(item, 'condition_type', option ? (option.dataset.defaultCondition || 'venta') : 'venta');
    row.querySelector('[data-name="condition_type"]').value = condition;
    row.querySelector('[data-name="quantity"]').value = itemValue(item, 'quantity', '1');
    row.querySelector('[data-name="unit_price"]').value = itemValue(item, 'unit_price', conditionPrice(row, condition));
    row.querySelector('[data-name="rental_days"]').value = itemValue(item, 'rental_days', '1');
    const taxId = itemValue(item, 'tax_id', '');
    if (taxId !== '') {
        row.querySelector('[data-name="tax_id"]').value = taxId;
    }
    itemsBody.appendChild(row);
    configureTaxSelect(row.querySelector('[data-name="tax_id"]'), forceDefaultTax);
    updateRow(row);
}

function addProduct() {
    const option = productPicker.selectedOptions[0];
    if (!option || !option.value) {
        return;
    }
    appendItemRow({
        product_id: option.value,
        description: option.dataset.name || option.textContent.trim(),
        condition_type: option.dataset.defaultCondition || 'venta',
        sale_price: option.dataset.sale || '0',
        rental_price: option.dataset.rental || '0',
    }, true);
    productPicker.value = '';
    productSearch.value = '';
    filterProductPicker();
}

productSearch.addEventListener('input', filterProductPicker);
addProductButton.addEventListener('click', addProduct);
currencyModeSelect.addEventListener('change', refreshMoneyDisplays);
countryUnitSelect.addEventListener('change', () => {
    refreshMoneyDisplays();
});
discountInput.addEventListener('input', updateTotals);
discountInput.addEventListener('change', () => {
    discountInput.value = String(clampPercent(discountInput.value));
    updateTotals();
});
itemsBody.addEventListener('input', (event) => {
    const row = event.target.closest('tr');
    if (row) updateRow(row);
});
itemsBody.addEventListener('change', (event) => {
    const row = event.target.closest('tr');
    if (!row) return;
    if (event.target.matches('[data-name="condition_type"]')) {
        row.querySelector('[data-name="unit_price"]').value = conditionPrice(row, event.target.value);
    }
    updateRow(row);
});
itemsBody.addEventListener('click', (event) => {
    if (event.target.matches('.remove-row')) {
        if (!confirm('Eliminar este producto de la proforma?')) {
            return;
        }
        event.target.closest('tr').remove();
        updateTotals();
    }
});

const companyIdInput = document.getElementById('company-id');
const companyRucInput = document.getElementById('company-ruc');
const companyNameInput = document.getElementById('company-name');
const companyAddressInput = document.getElementById('company-address');
const companyPhoneInput = document.getElementById('company-phone');
const companyEmailInput = document.getElementById('company-email');
const companyCountryUnitSelect = document.getElementById('company-country-unit-id');
const companySearchButton = document.getElementById('company-search-button');
const companySearchStatus = document.getElementById('company-search-status');
const contactModeSelect = document.getElementById('contact-mode');
const existingContactFields = document.getElementById('existing-contact-fields');
const newContactFields = document.getElementById('new-contact-fields');
const contactSelect = document.getElementById('contact-id');
const contactEmailSelect = document.getElementById('contact-email-id');
const contactsEmpty = document.getElementById('contacts-empty');

function companyEditableFields() {
    return [
        companyNameInput,
        companyAddressInput,
        companyPhoneInput,
        companyEmailInput,
        companyCountryUnitSelect,
    ];
}

function setCompanyExistingState(isExisting) {
    companyEditableFields().forEach((field) => {
        if (field) {
            field.disabled = false;
            field.readOnly = isExisting && field.tagName !== 'SELECT';
            if (field.tagName === 'SELECT') {
                field.disabled = isExisting;
            }
        }
    });
}

function contactsForCurrentCompany() {
    return contactsByCompanyConfig[String(companyIdInput.value)]
        || contactsByCompanyConfig[Number(companyIdInput.value)]
        || [];
}

function renderContactEmails(preferredEmailId = 0) {
    contactEmailSelect.replaceChildren();
    const contact = contactsForCurrentCompany().find((item) => String(item.id) === contactSelect.value);
    const emails = contact && Array.isArray(contact.emails) ? contact.emails : [];

    if (emails.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'Sin emails cargados';
        contactEmailSelect.appendChild(option);
        return;
    }

    emails.forEach((email) => {
        const option = document.createElement('option');
        option.value = String(email.id);
        option.textContent = `${email.email}${Number(email.is_primary) === 1 || email.is_primary === true ? ' · Principal' : ' · Secundario'}`;
        if (Number(preferredEmailId) === Number(email.id)) {
            option.selected = true;
        }
        contactEmailSelect.appendChild(option);
    });

    if (!contactEmailSelect.value) {
        const primary = emails.find((email) => Number(email.is_primary) === 1 || email.is_primary === true);
        contactEmailSelect.value = String((primary || emails[0]).id);
    }
}

function renderContacts(preferredContactId = 0, preferredEmailId = 0) {
    const contacts = contactsForCurrentCompany();
    contactSelect.replaceChildren();

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Seleccionar contacto';
    contactSelect.appendChild(placeholder);

    contacts.forEach((contact) => {
        const option = document.createElement('option');
        option.value = String(contact.id);
        option.textContent = `${contact.full_name}${contact.position ? ` · ${contact.position}` : ''}`;
        if (Number(preferredContactId) === Number(contact.id)) {
            option.selected = true;
        }
        contactSelect.appendChild(option);
    });

    contactsEmpty.hidden = contacts.length > 0;
    if (!contactSelect.value && contacts.length === 1) {
        contactSelect.value = String(contacts[0].id);
    }
    renderContactEmails(preferredEmailId);
}

function updateContactMode() {
    const useExisting = contactModeSelect.value === 'existing';
    existingContactFields.hidden = !useExisting;
    newContactFields.hidden = useExisting;
    contactSelect.required = useExisting;
    document.getElementById('contact-full-name').required = !useExisting;
}

function applyFoundCompany(payload) {
    const company = payload.company;
    companyIdInput.value = String(company.id);
    companyRucInput.value = company.ruc || companyRucInput.value;
    companyNameInput.value = company.name || '';
    companyAddressInput.value = company.address || '';
    companyPhoneInput.value = company.phone || '';
    companyEmailInput.value = company.email || '';
    if (company.country_unit_id) {
        companyCountryUnitSelect.value = String(company.country_unit_id);
    }
    contactsByCompanyConfig[String(company.id)] = Array.isArray(payload.contacts) ? payload.contacts : [];
    setCompanyExistingState(true);
    companySearchStatus.textContent = `Empresa encontrada: ${company.name}.`;
    const contacts = Array.isArray(payload.contacts) ? payload.contacts : [];
    renderContacts(initialContactId, initialContactEmailId);
    contactModeSelect.value = contacts.length > 0 ? 'existing' : 'new';
    updateContactMode();
    applyClientTaxToRows(true);
}

function prepareNewCompany() {
    const wasExisting = companyIdInput.value !== '';
    companyIdInput.value = '';
    setCompanyExistingState(false);
    if (wasExisting) {
        companyNameInput.value = '';
        companyAddressInput.value = '';
        companyPhoneInput.value = '';
        companyEmailInput.value = '';
    }
    companySearchStatus.textContent = 'RUC no registrado. Completa los datos para crear la empresa al guardar.';
    renderContacts();
    contactModeSelect.value = 'new';
    updateContactMode();
}

async function searchCompanyByRuc() {
    const ruc = companyRucInput.value.trim();
    if (ruc === '') {
        companySearchStatus.textContent = 'Ingresa el RUC para buscar o crear la empresa.';
        return;
    }

    companySearchButton.disabled = true;
    companySearchStatus.textContent = 'Buscando empresa...';
    try {
        const response = await fetch(`${companySearchUrl}?ruc=${encodeURIComponent(ruc)}`, {
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error('No se pudo buscar la empresa.');
        }
        const payload = await response.json();
        if (payload.found && payload.company) {
            applyFoundCompany(payload);
        } else {
            prepareNewCompany();
        }
    } catch (error) {
        companySearchStatus.textContent = 'No se pudo completar la búsqueda. Puedes reintentar.';
    } finally {
        companySearchButton.disabled = false;
    }
}

companySearchButton.addEventListener('click', searchCompanyByRuc);
companyRucInput.addEventListener('blur', searchCompanyByRuc);
companyRucInput.addEventListener('input', () => {
    companySearchStatus.textContent = 'Busca el RUC para confirmar si la empresa ya existe.';
});
contactModeSelect.addEventListener('change', updateContactMode);
contactSelect.addEventListener('change', () => renderContactEmails());
companyCountryUnitSelect.addEventListener('change', () => applyClientTaxToRows(true));
countryUnitSelect.addEventListener('change', () => {
    if (companyIdInput.value === '') {
        companyCountryUnitSelect.value = countryUnitSelect.value;
        applyClientTaxToRows(true);
    }
});
setCompanyExistingState(Number(companyIdInput.value || 0) > 0);
renderContacts(initialContactId, initialContactEmailId);
updateContactMode();
initialItems.forEach((item) => appendItemRow(item, false));

const validityDaysSelect = document.getElementById('validity-days');
const expirationDateInput = document.getElementById('expiration-date');
function updateEstimatedExpiration() {
    const days = Number.parseInt(validityDaysSelect.value || '10', 10);
    const expiry = new Date();
    expiry.setDate(expiry.getDate() + days);
    const year = expiry.getFullYear();
    const month = String(expiry.getMonth() + 1).padStart(2, '0');
    const day = String(expiry.getDate()).padStart(2, '0');
    expirationDateInput.value = `${year}-${month}-${day}`;
}
validityDaysSelect.addEventListener('change', updateEstimatedExpiration);
updateEstimatedExpiration();

const sellerSelect = document.getElementById('seller-select');
const sellerSignature = document.getElementById('seller-signature');
function updateSellerSignature() {
    if (!sellerSelect || !sellerSignature) {
        return;
    }

    const option = sellerSelect.selectedOptions[0];
    const values = option && option.value ? [
        option.dataset.name || emptyMarker,
        option.dataset.position || emptyMarker,
        option.dataset.email || emptyMarker,
        option.dataset.phone || emptyMarker,
        option.dataset.unit || emptyMarker,
        `Firma manuscrita: ${option.dataset.signatureLoaded || 'No'}`,
    ] : [emptyMarker, emptyMarker, emptyMarker, emptyMarker, emptyMarker, 'Firma manuscrita: No'];
    sellerSignature.querySelectorAll('span').forEach((node, index) => node.textContent = values[index]);
}
if (sellerSelect) {
    sellerSelect.addEventListener('change', updateSellerSignature);
    updateSellerSignature();
}

const conditions = document.getElementById('conditions');
const count = document.getElementById('conditions-count');
function updateCounter() {
    count.textContent = String(conditions.value.length);
}
conditions.addEventListener('input', updateCounter);
updateCounter();
</script>
<?php renderFooter(); ?>
