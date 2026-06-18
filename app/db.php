<?php

declare(strict_types=1);

function db(): PDO
{
    if (($GLOBALS['app_pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['app_pdo'];
    }

    if (!is_dir(DATABASE_PATH)) {
        mkdir(DATABASE_PATH, 0775, true);
    }

    $GLOBALS['app_pdo'] = new PDO('sqlite:' . SQLITE_PATH);
    $GLOBALS['app_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['app_pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $GLOBALS['app_pdo']->exec('PRAGMA foreign_keys = ON');
    createProjectMigrationBackupIfNeeded($GLOBALS['app_pdo']);
    createCurrencyMigrationBackupIfNeeded($GLOBALS['app_pdo']);
    createCommercialMigrationBackupIfNeeded($GLOBALS['app_pdo']);
    createAuthorizationMigrationBackupIfNeeded($GLOBALS['app_pdo']);
    createNotesMigrationBackupIfNeeded($GLOBALS['app_pdo']);
    createDashboardMigrationBackupIfNeeded($GLOBALS['app_pdo']);
    ensureSchemaCompatibility($GLOBALS['app_pdo']);

    return $GLOBALS['app_pdo'];
}

function closeDb(): void
{
    $GLOBALS['app_pdo'] = null;
}

function runMigrations(PDO $pdo): void
{
    $migration = ROOT_PATH . '/migrations/init.sql';
    if (!is_file($migration)) {
        throw new RuntimeException('No se encontro migrations/init.sql.');
    }

    $pdo->exec((string) file_get_contents($migration));
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
    $stmt->execute([':name' => $table]);
    return (bool) $stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        throw new RuntimeException('Nombre de tabla invalido.');
    }

    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') ?: [] as $info) {
        if (($info['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function createProjectMigrationBackupIfNeeded(PDO $pdo): ?string
{
    if (!tableExists($pdo, 'proformas') || columnExists($pdo, 'proformas', 'project_id') || !is_file(SQLITE_PATH)) {
        return null;
    }

    ensureStorageDirectories();
    $base = BACKUP_PATH . '/backup_' . date('Y-m-d_H-i-s') . '_pre-projects-stage1.sqlite';
    $target = $base;
    $counter = 1;
    while (is_file($target)) {
        $target = substr($base, 0, -7) . '_' . $counter . '.sqlite';
        $counter++;
    }

    if (!copy(SQLITE_PATH, $target)) {
        throw new RuntimeException('No se pudo crear el backup previo a la migracion de proyectos.');
    }

    return $target;
}

function createCurrencyMigrationBackupIfNeeded(PDO $pdo): ?string
{
    if (
        !tableExists($pdo, 'proformas')
        || columnExists($pdo, 'proformas', 'currency_mode')
        || !is_file(SQLITE_PATH)
    ) {
        return null;
    }

    ensureStorageDirectories();
    $base = BACKUP_PATH . '/backup_' . date('Y-m-d_H-i-s') . '_pre-currency-stage2.sqlite';
    $target = $base;
    $counter = 1;
    while (is_file($target)) {
        $target = substr($base, 0, -7) . '_' . $counter . '.sqlite';
        $counter++;
    }

    if (!copy(SQLITE_PATH, $target)) {
        throw new RuntimeException('No se pudo crear el backup previo a la migración de monedas.');
    }

    return $target;
}

function createCommercialMigrationBackupIfNeeded(PDO $pdo): ?string
{
    if (
        !tableExists($pdo, 'proformas')
        || columnExists($pdo, 'proformas', 'validity_days')
        || !is_file(SQLITE_PATH)
    ) {
        return null;
    }

    ensureStorageDirectories();
    $base = BACKUP_PATH . '/backup_' . date('Y-m-d_H-i-s') . '_pre-commercial-stage3.sqlite';
    $target = $base;
    $counter = 1;
    while (is_file($target)) {
        $target = substr($base, 0, -7) . '_' . $counter . '.sqlite';
        $counter++;
    }

    if (!copy(SQLITE_PATH, $target)) {
        throw new RuntimeException('No se pudo crear el backup previo a la migración comercial.');
    }

    return $target;
}

function createAuthorizationMigrationBackupIfNeeded(PDO $pdo): ?string
{
    if (
        !tableExists($pdo, 'proformas')
        || tableExists($pdo, 'proforma_authorizations')
        || !is_file(SQLITE_PATH)
    ) {
        return null;
    }

    ensureStorageDirectories();
    $base = BACKUP_PATH . '/backup_' . date('Y-m-d_H-i-s') . '_pre-authorization-stage4.sqlite';
    $target = $base;
    $counter = 1;
    while (is_file($target)) {
        $target = substr($base, 0, -7) . '_' . $counter . '.sqlite';
        $counter++;
    }

    if (!copy(SQLITE_PATH, $target)) {
        throw new RuntimeException('No se pudo crear el backup previo a la migración de autorizaciones.');
    }

    return $target;
}

function createNotesMigrationBackupIfNeeded(PDO $pdo): ?string
{
    if (
        !tableExists($pdo, 'proformas')
        || tableExists($pdo, 'proforma_disclaimers')
        || !is_file(SQLITE_PATH)
    ) {
        return null;
    }

    ensureStorageDirectories();
    $base = BACKUP_PATH . '/backup_' . date('Y-m-d_H-i-s') . '_pre-notes-stage5.sqlite';
    $target = $base;
    $counter = 1;
    while (is_file($target)) {
        $target = substr($base, 0, -7) . '_' . $counter . '.sqlite';
        $counter++;
    }
    if (!copy(SQLITE_PATH, $target)) {
        throw new RuntimeException('No se pudo crear el backup previo a la migración de notas.');
    }
    return $target;
}

function createDashboardMigrationBackupIfNeeded(PDO $pdo): ?string
{
    if (
        !tableExists($pdo, 'proformas')
        || columnExists($pdo, 'proformas', 'commercial_status')
        || !is_file(SQLITE_PATH)
    ) {
        return null;
    }

    ensureStorageDirectories();
    $base = BACKUP_PATH . '/backup_' . date('Y-m-d_H-i-s') . '_pre-dashboard-stage6.sqlite';
    $target = $base;
    $counter = 1;
    while (is_file($target)) {
        $target = substr($base, 0, -7) . '_' . $counter . '.sqlite';
        $counter++;
    }
    if (!copy(SQLITE_PATH, $target)) {
        throw new RuntimeException('No se pudo crear el backup previo al dashboard gerencial.');
    }
    return $target;
}

function ensureSchemaCompatibility(PDO $pdo): void
{
    $isCurrencyStage2Migration = tableExists($pdo, 'proformas')
        && !columnExists($pdo, 'proformas', 'currency_mode');
    $isAuthorizationStage4Migration = tableExists($pdo, 'proformas')
        && !tableExists($pdo, 'proforma_authorizations');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS country_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            currency_symbol TEXT NOT NULL,
            currency_code TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_country_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            country_unit_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (country_unit_id) REFERENCES country_units(id),
            UNIQUE (user_id, country_unit_id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS exchange_rates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            country_unit_id INTEGER NOT NULL,
            currency_symbol TEXT NOT NULL,
            currency_code TEXT NOT NULL,
            rate_to_usd REAL NOT NULL,
            rate_from_usd REAL NOT NULL CHECK (rate_from_usd > 0),
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (country_unit_id) REFERENCES country_units(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )'
    );
    seedCountryUnits($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_country_units_user_id ON user_country_units(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_country_units_country_unit_id ON user_country_units(country_unit_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_exchange_rates_country_unit_id ON exchange_rates(country_unit_id)');
    $pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_exchange_rates_active_country_unit
         ON exchange_rates(country_unit_id)
         WHERE is_active = 1'
    );

    if (tableExists($pdo, 'users')) {
        $userColumns = [
            'first_name' => "TEXT NOT NULL DEFAULT ''",
            'last_name' => "TEXT NOT NULL DEFAULT ''",
            'email' => "TEXT NOT NULL DEFAULT ''",
            'phone' => "TEXT NOT NULL DEFAULT ''",
            'unit' => "TEXT NOT NULL DEFAULT 'Paraguay'",
            'reports_to_id' => 'INTEGER',
            'commercial_position' => "TEXT NOT NULL DEFAULT ''",
            'signature_image' => "TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($userColumns as $column => $definition) {
            if (!columnExists($pdo, 'users', $column)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $pdo->exec("UPDATE users SET role = 'commercial_executive' WHERE role = 'user'");
        $pdo->exec("UPDATE users SET role = 'commercial_executive' WHERE TRIM(COALESCE(role, '')) = ''");
        $pdo->exec('UPDATE users SET reports_to_id = NULL WHERE reports_to_id = id');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_reports_to_id ON users(reports_to_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role_unit ON users(role, unit)');

        $stmt = $pdo->prepare("UPDATE users SET unit = :unit WHERE TRIM(COALESCE(unit, '')) = ''");
        $stmt->execute([':unit' => defaultCountry()]);

        $pdo->exec(
            'INSERT OR IGNORE INTO user_country_units (user_id, country_unit_id, created_at)
             SELECT u.id, cu.id, CURRENT_TIMESTAMP
             FROM users u
             JOIN country_units cu ON cu.name = u.unit
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM user_country_units ucu
                 WHERE ucu.user_id = u.id
             )'
        );
    }

    if (tableExists($pdo, 'clients')) {
        $clientColumns = [
            'pais' => "TEXT NOT NULL DEFAULT 'Paraguay'",
            'ruc_normalized' => 'TEXT',
            'country_unit_id' => 'INTEGER',
        ];
        foreach ($clientColumns as $column => $definition) {
            if (!columnExists($pdo, 'clients', $column)) {
                $pdo->exec('ALTER TABLE clients ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $stmt = $pdo->prepare("UPDATE clients SET pais = :pais WHERE TRIM(COALESCE(pais, '')) = ''");
        $stmt->execute([':pais' => defaultCountry()]);

        $seenRucs = [];
        $rucRows = $pdo->query('SELECT id, ruc FROM clients ORDER BY id')->fetchAll();
        $updateRuc = $pdo->prepare('UPDATE clients SET ruc_normalized = :ruc_normalized WHERE id = :id');
        foreach ($rucRows as $row) {
            $normalizedRuc = normalizeRuc((string) ($row['ruc'] ?? ''));
            $storedRuc = null;
            if ($normalizedRuc !== '' && !isset($seenRucs[$normalizedRuc])) {
                $storedRuc = $normalizedRuc;
                $seenRucs[$normalizedRuc] = true;
            }
            $updateRuc->execute([
                ':ruc_normalized' => $storedRuc,
                ':id' => (int) $row['id'],
            ]);
        }
        $pdo->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_clients_ruc_normalized
             ON clients(ruc_normalized)
             WHERE ruc_normalized IS NOT NULL AND ruc_normalized <> ''"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clients_country_unit_id ON clients(country_unit_id)');
        $pdo->exec(
            'UPDATE clients
             SET country_unit_id = (
                 SELECT cu.id FROM country_units cu WHERE cu.name = clients.pais LIMIT 1
             )
             WHERE country_unit_id IS NULL'
        );
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS client_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            full_name TEXT NOT NULL,
            normalized_name TEXT NOT NULL DEFAULT \'\',
            email TEXT,
            phone TEXT,
            position TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        )'
    );
    foreach ([
        'normalized_name' => "TEXT NOT NULL DEFAULT ''",
        'position' => "TEXT NOT NULL DEFAULT ''",
    ] as $column => $definition) {
        if (!columnExists($pdo, 'client_contacts', $column)) {
            $pdo->exec('ALTER TABLE client_contacts ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
    $contactRows = $pdo->query('SELECT id, full_name FROM client_contacts')->fetchAll();
    $updateContactName = $pdo->prepare(
        'UPDATE client_contacts SET normalized_name = :normalized_name WHERE id = :id'
    );
    foreach ($contactRows as $row) {
        $updateContactName->execute([
            ':normalized_name' => normalizeContactName((string) $row['full_name']),
            ':id' => (int) $row['id'],
        ]);
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_contacts_client_id ON client_contacts(client_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_contacts_normalized_name ON client_contacts(normalized_name)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS company_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            contact_id INTEGER NOT NULL,
            is_primary INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (company_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE CASCADE,
            UNIQUE (company_id, contact_id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contact_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            normalized_email TEXT NOT NULL,
            is_primary INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE CASCADE,
            UNIQUE (contact_id, normalized_email)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_company_contacts_company_id ON company_contacts(company_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_company_contacts_contact_id ON company_contacts(contact_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contact_emails_contact_id ON contact_emails(contact_id)');
    $pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_contact_emails_primary
         ON contact_emails(contact_id)
         WHERE is_primary = 1'
    );
    $pdo->exec(
        'INSERT OR IGNORE INTO company_contacts (company_id, contact_id, is_primary, created_at)
         SELECT cc.client_id, cc.id,
                CASE WHEN cc.id = (
                    SELECT MIN(cc2.id) FROM client_contacts cc2 WHERE cc2.client_id = cc.client_id
                ) THEN 1 ELSE 0 END,
                cc.created_at
         FROM client_contacts cc'
    );
    foreach ($pdo->query("SELECT id, email, created_at FROM client_contacts WHERE TRIM(COALESCE(email, '')) <> ''")->fetchAll() as $row) {
        $email = normalizeContactEmail((string) $row['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $insertEmail = $pdo->prepare(
            'INSERT OR IGNORE INTO contact_emails
             (contact_id, email, normalized_email, is_primary, created_at)
             VALUES (:contact_id, :email, :normalized_email, 1, :created_at)'
        );
        $insertEmail->execute([
            ':contact_id' => (int) $row['id'],
            ':email' => $email,
            ':normalized_email' => $email,
            ':created_at' => (string) $row['created_at'],
        ]);
    }

    if (tableExists($pdo, 'taxes')) {
        if (!columnExists($pdo, 'taxes', 'paises')) {
            $pdo->exec("ALTER TABLE taxes ADD COLUMN paises TEXT NOT NULL DEFAULT ''");
        }

        $taxBackfills = [
            ['country' => 'República Dominicana', 'prefix' => 'ITBIS%'],
            ['country' => 'Panamá', 'prefix' => 'ITBMS%'],
        ];
        foreach ($taxBackfills as $backfill) {
            $stmt = $pdo->prepare(
                "UPDATE taxes
                 SET paises = :country
                 WHERE TRIM(COALESCE(paises, '')) = ''
                   AND UPPER(nombre) LIKE :prefix"
            );
            $stmt->execute([
                ':country' => $backfill['country'],
                ':prefix' => $backfill['prefix'],
            ]);
        }

        $stmt = $pdo->prepare(
            "UPDATE taxes
             SET paises = 'Colombia'
             WHERE TRIM(COALESCE(paises, '')) = ''
               AND UPPER(nombre) = 'IVA'
               AND porcentaje >= 19"
        );
        $stmt->execute();

        $stmt = $pdo->prepare("UPDATE taxes SET paises = :pais WHERE TRIM(COALESCE(paises, '')) = ''");
        $stmt->execute([':pais' => defaultCountry()]);
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            normalized_name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            prefix TEXT NOT NULL COLLATE NOCASE UNIQUE,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    if (tableExists($pdo, 'proformas')) {
        $proformaColumns = [
            'project_id' => 'INTEGER',
            'parent_proforma_id' => 'INTEGER',
            'version_number' => 'INTEGER NOT NULL DEFAULT 1',
            'project_sequence' => 'INTEGER',
            'client_contact_id' => 'INTEGER',
            'contact_name' => "TEXT NOT NULL DEFAULT ''",
            'contact_email' => "TEXT NOT NULL DEFAULT ''",
            'contact_phone' => "TEXT NOT NULL DEFAULT ''",
            'project_name' => "TEXT NOT NULL DEFAULT ''",
            'currency_code' => "TEXT NOT NULL DEFAULT 'USD'",
            'currency_mode' => "TEXT NOT NULL DEFAULT 'USD'",
            'currency_symbol' => "TEXT NOT NULL DEFAULT 'US$'",
            'country_unit_id' => 'INTEGER',
            'exchange_rate_used' => 'REAL DEFAULT 1',
            'exchange_rate_source' => "TEXT NOT NULL DEFAULT 'GLOBAL'",
            'exchange_rate_authorized_by' => 'INTEGER',
            'exchange_rate_authorized_at' => 'TEXT',
            'authorization_status' => "TEXT NOT NULL DEFAULT 'NOT_REQUIRED'",
            'format_type' => "TEXT NOT NULL DEFAULT 'detallado'",
            'discount_percent' => 'REAL NOT NULL DEFAULT 0',
            'discount_amount' => 'REAL NOT NULL DEFAULT 0',
            'status' => "TEXT NOT NULL DEFAULT 'emitida'",
            'won_at' => 'TEXT',
            'won_by' => 'INTEGER',
            'commercial_status' => "TEXT NOT NULL DEFAULT 'OPEN'",
            'commercial_status_updated_by' => 'INTEGER',
            'commercial_status_updated_at' => 'TEXT',
            'commercial_status_notes' => "TEXT NOT NULL DEFAULT ''",
            'seller_id' => 'INTEGER',
            'signer_name' => "TEXT NOT NULL DEFAULT ''",
            'signer_email' => "TEXT NOT NULL DEFAULT ''",
            'signer_phone' => "TEXT NOT NULL DEFAULT ''",
            'signer_unit' => "TEXT NOT NULL DEFAULT ''",
            'signer_position' => "TEXT NOT NULL DEFAULT ''",
            'signer_signature_image' => "TEXT NOT NULL DEFAULT ''",
            'public_token' => 'TEXT',
            'email_sent_at' => 'TEXT',
            'email_last_error' => "TEXT NOT NULL DEFAULT ''",
            'customer_viewed_at' => 'TEXT',
            'customer_downloaded_at' => 'TEXT',
            'customer_update_requested_at' => 'TEXT',
            'customer_update_request_error' => "TEXT NOT NULL DEFAULT ''",
            'company_id' => 'INTEGER',
            'contact_id' => 'INTEGER',
            'contact_email_id' => 'INTEGER',
            'company_name_snapshot' => "TEXT NOT NULL DEFAULT ''",
            'contact_name_snapshot' => "TEXT NOT NULL DEFAULT ''",
            'contact_email_snapshot' => "TEXT NOT NULL DEFAULT ''",
            'validity_days' => 'INTEGER NOT NULL DEFAULT 10',
            'expires_at' => 'TEXT',
        ];
        foreach ($proformaColumns as $column => $definition) {
            if (!columnExists($pdo, 'proformas', $column)) {
                $pdo->exec('ALTER TABLE proformas ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $pdo->exec("UPDATE proformas SET status = 'emitida' WHERE TRIM(COALESCE(status, '')) = ''");
        $pdo->exec(
            "UPDATE proformas
             SET commercial_status = CASE WHEN status = 'venta_ganada' THEN 'WON' ELSE 'OPEN' END
             WHERE TRIM(COALESCE(commercial_status, '')) = ''
                OR commercial_status NOT IN ('OPEN', 'WON', 'LOST', 'CANCELLED')"
        );
        $pdo->exec(
            "UPDATE proformas
             SET commercial_status = 'WON',
                 commercial_status_updated_at = COALESCE(commercial_status_updated_at, won_at),
                 commercial_status_updated_by = COALESCE(commercial_status_updated_by, won_by)
             WHERE status = 'venta_ganada'
               AND commercial_status = 'OPEN'"
        );
        if ($isCurrencyStage2Migration) {
            $pdo->exec(
                "UPDATE proformas
                 SET currency_code = 'USD',
                     currency_mode = 'USD',
                     currency_symbol = 'US$',
                     exchange_rate_used = 1,
                     exchange_rate_source = 'GLOBAL',
                     authorization_status = 'NOT_REQUIRED',
                     exchange_rate_authorized_by = NULL,
                     exchange_rate_authorized_at = NULL"
            );
        } else {
            $pdo->exec("UPDATE proformas SET currency_code = 'USD' WHERE TRIM(COALESCE(currency_code, '')) = '' OR currency_code NOT IN ('USD', 'PYG', 'COP', 'DOP', 'PAB')");
            $pdo->exec("UPDATE proformas SET currency_mode = 'USD' WHERE currency_mode NOT IN ('USD', 'LOCAL') OR TRIM(COALESCE(currency_mode, '')) = ''");
            $pdo->exec("UPDATE proformas SET currency_symbol = 'US$' WHERE TRIM(COALESCE(currency_symbol, '')) = ''");
            $pdo->exec("UPDATE proformas SET exchange_rate_source = 'GLOBAL' WHERE exchange_rate_source NOT IN ('GLOBAL', 'SPECIAL') OR TRIM(COALESCE(exchange_rate_source, '')) = ''");
            $pdo->exec("UPDATE proformas SET authorization_status = 'NOT_REQUIRED' WHERE authorization_status NOT IN ('NOT_REQUIRED', 'PENDING', 'APPROVED', 'REJECTED') OR TRIM(COALESCE(authorization_status, '')) = ''");
            $pdo->exec("UPDATE proformas SET exchange_rate_used = 1 WHERE currency_mode = 'USD' AND (exchange_rate_used IS NULL OR exchange_rate_used <= 0)");
        }
        $pdo->exec("UPDATE proformas SET format_type = 'detallado' WHERE format_type NOT IN ('detallado', 'generico') OR TRIM(COALESCE(format_type, '')) = ''");
        $pdo->exec('UPDATE proformas SET company_id = client_id WHERE company_id IS NULL');
        $pdo->exec('UPDATE proformas SET contact_id = client_contact_id WHERE contact_id IS NULL AND client_contact_id IS NOT NULL');
        $pdo->exec(
            "UPDATE proformas
             SET company_name_snapshot = COALESCE((SELECT empresa FROM clients WHERE clients.id = proformas.client_id), '')
             WHERE TRIM(COALESCE(company_name_snapshot, '')) = ''"
        );
        $pdo->exec(
            "UPDATE proformas
             SET contact_name_snapshot = contact_name
             WHERE TRIM(COALESCE(contact_name_snapshot, '')) = ''"
        );
        $pdo->exec(
            "UPDATE proformas
             SET contact_email_snapshot = contact_email
             WHERE TRIM(COALESCE(contact_email_snapshot, '')) = ''"
        );
        $pdo->exec('UPDATE proformas SET validity_days = 10 WHERE validity_days NOT IN (10, 20, 30, 45)');
        $pdo->exec(
            "UPDATE proformas
             SET expires_at = expiration_date || ' 23:59:59'
             WHERE TRIM(COALESCE(expires_at, '')) = ''
               AND date(expiration_date) IS NOT NULL"
        );
        $pdo->exec('UPDATE proformas SET seller_id = created_by WHERE seller_id IS NULL');
        $pdo->exec(
            "UPDATE proformas
             SET signer_name = COALESCE(
                     NULLIF(TRIM(COALESCE((SELECT first_name FROM users WHERE users.id = proformas.seller_id), '') || ' ' || COALESCE((SELECT last_name FROM users WHERE users.id = proformas.seller_id), '')), ''),
                     (SELECT username FROM users WHERE users.id = proformas.seller_id),
                     ''
                 )
             WHERE TRIM(COALESCE(signer_name, '')) = ''"
        );
        $pdo->exec(
            "UPDATE proformas
             SET signer_email = COALESCE((SELECT email FROM users WHERE users.id = proformas.seller_id), '')
             WHERE TRIM(COALESCE(signer_email, '')) = ''"
        );
        $pdo->exec(
            "UPDATE proformas
             SET signer_phone = COALESCE((SELECT phone FROM users WHERE users.id = proformas.seller_id), '')
             WHERE TRIM(COALESCE(signer_phone, '')) = ''"
        );
        $stmt = $pdo->prepare(
            "UPDATE proformas
             SET signer_unit = COALESCE(NULLIF((SELECT unit FROM users WHERE users.id = proformas.seller_id), ''), :unit)
             WHERE TRIM(COALESCE(signer_unit, '')) = ''"
        );
        $stmt->execute([':unit' => defaultCountry()]);
        if ($isAuthorizationStage4Migration) {
            $pdo->exec(
                "UPDATE proformas
                 SET signer_position = COALESCE((SELECT commercial_position FROM users WHERE users.id = proformas.seller_id), '')
                 WHERE TRIM(COALESCE(signer_position, '')) = ''"
            );
            $pdo->exec(
                "UPDATE proformas
                 SET signer_signature_image = COALESCE((SELECT signature_image FROM users WHERE users.id = proformas.seller_id), '')
                 WHERE TRIM(COALESCE(signer_signature_image, '')) = ''"
            );
        }

        backfillLegacyProjects($pdo);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_project_id ON proformas(project_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_parent_id ON proformas(parent_proforma_id)');
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_proformas_project_sequence
             ON proformas(project_id, project_sequence)
             WHERE project_id IS NOT NULL AND project_sequence IS NOT NULL'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_seller_id ON proformas(seller_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_status ON proformas(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_commercial_status ON proformas(commercial_status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_emission_date ON proformas(emission_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_country_unit_id ON proformas(country_unit_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_company_id ON proformas(company_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_contact_id ON proformas(contact_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_contact_email_id ON proformas(contact_email_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proformas_expires_at ON proformas(expires_at)');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_proformas_public_token ON proformas(public_token) WHERE public_token IS NOT NULL AND public_token <> ''");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS proforma_email_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proforma_id INTEGER,
            event_type TEXT NOT NULL DEFAULT 'customer_proforma',
            to_email TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '',
            success INTEGER NOT NULL DEFAULT 0,
            error_message TEXT NOT NULL DEFAULT '',
            sent_at TEXT NOT NULL,
            FOREIGN KEY (proforma_id) REFERENCES proformas(id)
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proforma_email_logs_sent_at ON proforma_email_logs(sent_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proforma_email_logs_proforma_id ON proforma_email_logs(proforma_id)');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS proforma_authorizations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proforma_id INTEGER NOT NULL,
            requested_by INTEGER NOT NULL,
            requested_to INTEGER NOT NULL,
            requested_role TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'PENDING',
            current_exchange_rate REAL,
            approved_exchange_rate REAL,
            exchange_rate_source TEXT,
            notes TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            decided_at TEXT,
            FOREIGN KEY (proforma_id) REFERENCES proformas(id),
            FOREIGN KEY (requested_by) REFERENCES users(id),
            FOREIGN KEY (requested_to) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            related_entity_type TEXT NOT NULL,
            related_entity_id INTEGER NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS proforma_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proforma_id INTEGER NOT NULL,
            user_id INTEGER,
            event_type TEXT NOT NULL,
            event_detail TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY (proforma_id) REFERENCES proformas(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proforma_authorizations_proforma_id ON proforma_authorizations(proforma_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proforma_authorizations_requested_to ON proforma_authorizations(requested_to, status)');
    $pdo->exec(
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_proforma_authorizations_pending
         ON proforma_authorizations(proforma_id)
         WHERE status = 'PENDING'"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id, is_read, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proforma_events_proforma_id ON proforma_events(proforma_id, created_at)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS proforma_disclaimers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_default INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            updated_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (updated_by) REFERENCES users(id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS proforma_disclaimer_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proforma_id INTEGER NOT NULL,
            disclaimer_id INTEGER,
            title_snapshot TEXT NOT NULL,
            body_snapshot TEXT NOT NULL,
            sort_order_snapshot INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (proforma_id) REFERENCES proformas(id) ON DELETE CASCADE,
            FOREIGN KEY (disclaimer_id) REFERENCES proforma_disclaimers(id) ON DELETE SET NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proforma_disclaimers_order ON proforma_disclaimers(is_active, is_default, sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proforma_disclaimer_snapshots_proforma ON proforma_disclaimer_snapshots(proforma_id, sort_order_snapshot)');
    seedDefaultProformaDisclaimers($pdo);
    if (tableExists($pdo, 'proformas')) {
        $pdo->exec(
            "INSERT INTO proforma_email_logs (proforma_id, event_type, to_email, subject, success, error_message, sent_at)
             SELECT p.id,
                    'customer_proforma',
                    COALESCE(NULLIF(p.contact_email, ''), c.email, ''),
                    'Propuesta ATEX - ' || COALESCE(NULLIF(p.project_name, ''), c.empresa, p.proforma_number, ''),
                    1,
                    '',
                    p.email_sent_at
             FROM proformas p
             JOIN clients c ON c.id = p.client_id
             WHERE TRIM(COALESCE(p.email_sent_at, '')) <> ''
               AND NOT EXISTS (
                   SELECT 1
                   FROM proforma_email_logs l
                   WHERE l.proforma_id = p.id
                     AND l.event_type = 'customer_proforma'
                     AND l.success = 1
                     AND l.sent_at = p.email_sent_at
               )"
        );
    }
}

function ensureStorageDirectories(): void
{
    foreach ([STORAGE_PATH, DATABASE_PATH, BACKUP_PATH, PROFORMA_STORAGE_PATH, SIGNATURE_STORAGE_PATH] as $path) {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}
