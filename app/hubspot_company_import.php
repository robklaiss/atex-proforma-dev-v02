<?php

declare(strict_types=1);

function cleanHubspotRuc(string $ruc): string
{
    $ruc = trim($ruc);
    if (preg_match('/^(\d+)\.0+$/', $ruc, $matches) === 1) {
        return $matches[1];
    }

    return $ruc;
}

function hubspotCompanyAddress(string $address, string $city): string
{
    $address = trim($address);
    $city = trim($city);

    if ($address === '') {
        return $city;
    }
    if ($city === '' || str_contains(normalizeProjectName($address), normalizeProjectName($city))) {
        return $address;
    }

    return $address . ', ' . $city;
}

function parseHubspotAssociatedContacts(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['contacts' => [], 'warnings' => []];
    }

    $contacts = [];
    $warnings = [];
    $segments = preg_split('/\s*;\s*/u', $value) ?: [];

    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }

        if (preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $segment, $matches) === 1) {
            $name = normalizeProjectDisplayName($matches[1]);
            $email = normalizeContactEmail($matches[2]);
            if ($name === '') {
                $warnings[] = 'Se omitió un contacto sin nombre: ' . $segment;
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $warnings[] = 'Se omitió un correo inválido para ' . $name . ': ' . $email;
                $email = '';
            }
            $contacts[] = ['name' => $name, 'email' => $email];
            continue;
        }

        if (filter_var(normalizeContactEmail($segment), FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'Se omitió un correo sin nombre de contacto: ' . normalizeContactEmail($segment);
            continue;
        }

        $name = normalizeProjectDisplayName($segment);
        if ($name !== '') {
            $contacts[] = ['name' => $name, 'email' => ''];
        }
    }

    return ['contacts' => $contacts, 'warnings' => $warnings];
}

function readHubspotCompaniesCsv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('No se puede leer el archivo CSV indicado.');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir el archivo CSV.');
    }

    try {
        $firstRow = fgetcsv($handle, null, ',', '"', '');
        if ($firstRow === false) {
            throw new RuntimeException('El archivo CSV está vacío.');
        }
        $firstRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($firstRow[0] ?? '')) ?? '';

        if (count($firstRow) === 1 && trim((string) $firstRow[0]) === 'Table 1') {
            $headers = fgetcsv($handle, null, ',', '"', '');
        } else {
            $headers = $firstRow;
        }

        $expected = [
            'Nombre de la empresa',
            'NIT/RUC/RCN',
            'Dirección',
            'Ciudad',
            'País/región',
            'Número de teléfono',
            'Associated Contact',
        ];
        if ($headers === false || array_map('trim', $headers) !== $expected) {
            throw new RuntimeException('El CSV no tiene las columnas esperadas del export de HubSpot.');
        }

        $rows = [];
        $line = 2;
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($values === [null] || $values === []) {
                continue;
            }
            $values = array_pad($values, count($expected), '');
            $row = array_combine($expected, array_slice($values, 0, count($expected)));
            if (!is_array($row)) {
                throw new RuntimeException('No se pudo interpretar la línea ' . $line . ' del CSV.');
            }
            if (trim((string) $row['Nombre de la empresa']) === '') {
                continue;
            }
            $row['_line'] = $line;
            $rows[] = $row;
        }
    } finally {
        fclose($handle);
    }

    if ($rows === []) {
        throw new RuntimeException('El CSV no contiene empresas para importar.');
    }

    return $rows;
}

function findHubspotCompanyByName(PDO $pdo, string $name): ?array
{
    $normalizedName = normalizeProjectName($name);
    $stmt = $pdo->query('SELECT * FROM clients ORDER BY id');
    foreach ($stmt->fetchAll() as $company) {
        if (normalizeProjectName((string) $company['empresa']) === $normalizedName) {
            return $company;
        }
    }

    return null;
}

function findHubspotContactForCompany(PDO $pdo, int $companyId, string $name): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cc.*
         FROM client_contacts cc
         WHERE cc.normalized_name = :normalized_name
           AND (
               cc.client_id = :company_id
               OR EXISTS (
                   SELECT 1 FROM company_contacts link
                   WHERE link.company_id = :company_id AND link.contact_id = cc.id
               )
           )
         ORDER BY cc.id
         LIMIT 1'
    );
    $stmt->execute([
        ':normalized_name' => normalizeContactName($name),
        ':company_id' => $companyId,
    ]);
    $contact = $stmt->fetch();

    return is_array($contact) ? $contact : null;
}

function importHubspotCompanies(PDO $pdo, array $rows, ?int $createdBy = null): array
{
    $countryUnitStmt = $pdo->prepare(
        'SELECT id, name FROM country_units WHERE name = :name AND is_active = 1 LIMIT 1'
    );
    $insertCompany = $pdo->prepare(
        'INSERT INTO clients
         (empresa, ruc, ruc_normalized, direccion, email, telefono, pais, country_unit_id,
          created_at, updated_at, created_by)
         VALUES
         (:empresa, :ruc, :ruc_normalized, :direccion, \'\', :telefono, :pais, :country_unit_id,
          :created_at, :updated_at, :created_by)'
    );
    $updateCompany = $pdo->prepare(
        'UPDATE clients
         SET empresa = :empresa,
             ruc = CASE WHEN :ruc <> \'\' THEN :ruc ELSE ruc END,
             ruc_normalized = CASE WHEN :ruc_normalized <> \'\' THEN :ruc_normalized ELSE ruc_normalized END,
             direccion = CASE WHEN :direccion <> \'\' THEN :direccion ELSE direccion END,
             telefono = CASE WHEN :telefono <> \'\' THEN :telefono ELSE telefono END,
             pais = :pais,
             country_unit_id = :country_unit_id,
             updated_at = :updated_at
         WHERE id = :id'
    );

    $result = [
        'companies_created' => 0,
        'companies_updated' => 0,
        'contacts_created' => 0,
        'contacts_reused' => 0,
        'emails_saved' => 0,
        'rows_without_contact' => 0,
        'warnings' => [],
    ];

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $line = (int) ($row['_line'] ?? 0);
            $name = normalizeProjectDisplayName((string) $row['Nombre de la empresa']);
            $ruc = cleanHubspotRuc((string) $row['NIT/RUC/RCN']);
            $normalizedRuc = normalizeRuc($ruc);
            $country = normalizeProjectDisplayName((string) $row['País/región']);
            $address = hubspotCompanyAddress((string) $row['Dirección'], (string) $row['Ciudad']);
            $phone = trim((string) $row['Número de teléfono']);

            if ($name === '') {
                $result['warnings'][] = 'Línea ' . $line . ': empresa sin nombre omitida.';
                continue;
            }
            if (!isAllowedCountry($country)) {
                $result['warnings'][] = 'Línea ' . $line . ': país no permitido para ' . $name . '.';
                continue;
            }

            $countryUnitStmt->execute([':name' => $country]);
            $countryUnit = $countryUnitStmt->fetch();
            if (!is_array($countryUnit)) {
                $result['warnings'][] = 'Línea ' . $line . ': no existe una unidad activa para ' . $country . '.';
                continue;
            }

            $company = $normalizedRuc !== '' ? findCompanyByRuc($pdo, $ruc) : null;
            $company ??= findHubspotCompanyByName($pdo, $name);
            $now = nowIso();

            if ($company) {
                $updateCompany->execute([
                    ':empresa' => $name,
                    ':ruc' => $ruc,
                    ':ruc_normalized' => $normalizedRuc,
                    ':direccion' => $address,
                    ':telefono' => $phone,
                    ':pais' => $country,
                    ':country_unit_id' => (int) $countryUnit['id'],
                    ':updated_at' => $now,
                    ':id' => (int) $company['id'],
                ]);
                $companyId = (int) $company['id'];
                $result['companies_updated']++;
            } else {
                $insertCompany->execute([
                    ':empresa' => $name,
                    ':ruc' => $ruc,
                    ':ruc_normalized' => $normalizedRuc,
                    ':direccion' => $address,
                    ':telefono' => $phone,
                    ':pais' => $country,
                    ':country_unit_id' => (int) $countryUnit['id'],
                    ':created_at' => $now,
                    ':updated_at' => $now,
                    ':created_by' => $createdBy,
                ]);
                $companyId = (int) $pdo->lastInsertId();
                $result['companies_created']++;
            }

            $parsed = parseHubspotAssociatedContacts((string) $row['Associated Contact']);
            if ($parsed['contacts'] === []) {
                $result['rows_without_contact']++;
            }
            foreach ($parsed['warnings'] as $warning) {
                $result['warnings'][] = 'Línea ' . $line . ' (' . $name . '): ' . $warning;
            }

            foreach ($parsed['contacts'] as $contactData) {
                $contact = findHubspotContactForCompany($pdo, $companyId, $contactData['name']);
                if ($contact) {
                    associateContactWithCompany($pdo, $companyId, (int) $contact['id']);
                    $result['contacts_reused']++;
                } else {
                    $contact = createOrReuseContactForCompany(
                        $pdo,
                        $companyId,
                        $contactData['name'],
                        '',
                        '',
                        $contactData['email']
                    );
                    $result['contacts_created']++;
                }

                if ($contactData['email'] !== '') {
                    saveContactEmail($pdo, (int) $contact['id'], $contactData['email'], true);
                    $result['emails_saved']++;
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $result;
}
