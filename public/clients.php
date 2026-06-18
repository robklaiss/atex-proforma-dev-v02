<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$error = null;
$edit = null;
$contactEdit = null;
$currentUser = currentUser();
$countryOptions = countryOptions();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? 'save_client');
        if ($action === 'save_contact') {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $clientId = (int) ($_POST['client_id'] ?? 0);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));

            if ($clientId <= 0) {
                throw new RuntimeException('Selecciona un cliente valido.');
            }
            $clientStmt = db()->prepare('SELECT id FROM clients WHERE id = :id');
            $clientStmt->execute([':id' => $clientId]);
            if (!$clientStmt->fetchColumn()) {
                throw new RuntimeException('El cliente seleccionado no existe.');
            }
            if ($fullName === '') {
                throw new RuntimeException('El nombre del contacto es obligatorio.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo del contacto no tiene un formato valido.');
            }
            if ($contactId > 0) {
                $relation = db()->prepare(
                    'SELECT 1 FROM company_contacts
                     WHERE company_id = :company_id AND contact_id = :contact_id'
                );
                $relation->execute([
                    ':company_id' => $clientId,
                    ':contact_id' => $contactId,
                ]);
                if (!$relation->fetchColumn()) {
                    throw new RuntimeException('El contacto no está asociado a esta empresa.');
                }
            }

            createDatabaseBackup();
            if ($contactId > 0) {
                $stmt = db()->prepare(
                    'UPDATE client_contacts
                     SET full_name = :full_name, normalized_name = :normalized_name,
                         email = :email, phone = :phone, updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':full_name' => $fullName,
                    ':normalized_name' => normalizeContactName($fullName),
                    ':email' => $email,
                    ':phone' => $phone,
                    ':updated_at' => nowIso(),
                    ':id' => $contactId,
                ]);
                associateContactWithCompany(db(), $clientId, $contactId);
                if ($email !== '') {
                    saveContactEmail(db(), $contactId, $email, true);
                }
                setFlash('success', 'Contacto actualizado.');
            } else {
                createOrReuseContactForCompany(db(), $clientId, $fullName, $phone, '', $email);
                setFlash('success', 'Contacto agregado.');
            }
            redirect('/clients.php?edit=' . $clientId);
        }

        if ($action === 'delete_contact') {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $clientId = (int) ($_POST['client_id'] ?? 0);
            if ($contactId <= 0 || $clientId <= 0) {
                throw new RuntimeException('Selecciona un contacto valido.');
            }

            createDatabaseBackup();
            $stmt = db()->prepare(
                'DELETE FROM company_contacts
                 WHERE contact_id = :id AND company_id = :client_id'
            );
            $stmt->execute([
                ':id' => $contactId,
                ':client_id' => $clientId,
            ]);
            setFlash('success', 'Contacto desasociado de la empresa.');
            redirect('/clients.php?edit=' . $clientId);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $empresa = trim((string) ($_POST['empresa'] ?? ''));
        $ruc = trim((string) ($_POST['ruc'] ?? ''));
        $direccion = trim((string) ($_POST['direccion'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));
        $pais = trim((string) ($_POST['pais'] ?? defaultCountry()));
        $normalizedRuc = normalizeRuc($ruc);

        if ($empresa === '') {
            throw new RuntimeException('La empresa es obligatoria.');
        }
        if ($normalizedRuc === '') {
            throw new RuntimeException('El RUC es obligatorio.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El email no tiene un formato valido.');
        }
        if (!isAllowedCountry($pais)) {
            throw new RuntimeException('Selecciona un país valido.');
        }
        $duplicate = findCompanyByRuc(db(), $ruc);
        if ($duplicate && (int) $duplicate['id'] !== $id) {
            throw new RuntimeException('Ya existe una empresa con ese RUC.');
        }
        $countryUnit = findCountryUnitByName(db(), $pais);

        createDatabaseBackup();
        if ($id > 0) {
            $stmt = db()->prepare(
                'UPDATE clients
                 SET empresa = :empresa, ruc = :ruc, ruc_normalized = :ruc_normalized,
                     direccion = :direccion, email = :email, telefono = :telefono, pais = :pais,
                     country_unit_id = :country_unit_id, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':empresa' => $empresa,
                ':ruc' => $ruc,
                ':ruc_normalized' => $normalizedRuc,
                ':direccion' => $direccion,
                ':email' => $email,
                ':telefono' => $telefono,
                ':pais' => $pais,
                ':country_unit_id' => $countryUnit ? (int) $countryUnit['id'] : null,
                ':updated_at' => nowIso(),
                ':id' => $id,
            ]);
            setFlash('success', 'Cliente actualizado.');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO clients
                 (empresa, ruc, ruc_normalized, direccion, email, telefono, pais, country_unit_id,
                  created_at, updated_at, created_by)
                 VALUES
                 (:empresa, :ruc, :ruc_normalized, :direccion, :email, :telefono, :pais, :country_unit_id,
                  :created_at, :updated_at, :created_by)'
            );
            $now = nowIso();
            $stmt->execute([
                ':empresa' => $empresa,
                ':ruc' => $ruc,
                ':ruc_normalized' => $normalizedRuc,
                ':direccion' => $direccion,
                ':email' => $email,
                ':telefono' => $telefono,
                ':pais' => $pais,
                ':country_unit_id' => $countryUnit ? (int) $countryUnit['id'] : null,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':created_by' => (int) ($currentUser['id'] ?? 0),
            ]);
            setFlash('success', 'Cliente creado.');
        }
        redirect('/clients.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

if ($edit && isset($_GET['contact_edit'])) {
    $stmt = db()->prepare(
        'SELECT cc.*
         FROM client_contacts cc
         JOIN company_contacts link ON link.contact_id = cc.id
         WHERE cc.id = :id AND link.company_id = :client_id'
    );
    $stmt->execute([
        ':id' => (int) $_GET['contact_edit'],
        ':client_id' => (int) $edit['id'],
    ]);
    $contactEdit = $stmt->fetch() ?: null;
}

$contactsByClient = [];
if ($edit) {
    $contactsByClient[(int) $edit['id']] = commercialContactsForCompany(db(), (int) $edit['id']);
}

$clients = db()->query(
    'SELECT c.*, COALESCE(u.username, \'Sin registro\') AS created_by_username
     FROM clients c
     LEFT JOIN users u ON u.id = c.created_by
     ORDER BY c.empresa COLLATE NOCASE'
)->fetchAll();

renderHeader('Clientes');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2><?= $edit ? 'Editar cliente' : 'Nuevo cliente' ?></h2>
    <form method="post" class="grid-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_client">
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? 0)) ?>">
        <label>
            Empresa
            <input name="empresa" required value="<?= e($edit['empresa'] ?? '') ?>">
        </label>
        <label>
            RUC
            <input name="ruc" required value="<?= e($edit['ruc'] ?? '') ?>">
        </label>
        <label>
            País
            <select name="pais" required>
                <?php $selectedCountry = (string) ($edit['pais'] ?? defaultCountry()); ?>
                <?php foreach ($countryOptions as $country): ?>
                    <option value="<?= e($country) ?>" <?= $selectedCountry === $country ? 'selected' : '' ?>><?= e($country) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="wide">
            Direccion
            <input name="direccion" value="<?= e($edit['direccion'] ?? '') ?>">
        </label>
        <label>
            Email
            <input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>">
        </label>
        <label>
            Telefono
            <input name="telefono" value="<?= e($edit['telefono'] ?? '') ?>">
        </label>
        <div class="form-actions wide">
            <button class="button primary" type="submit"><?= $edit ? 'Guardar cambios' : 'Crear cliente' ?></button>
            <?php if ($edit): ?><a class="button" href="<?= e(publicPath('/clients.php')) ?>">Cancelar</a><?php endif; ?>
        </div>
    </form>
</section>

<?php if ($edit): ?>
<section class="panel">
    <h2>Contactos asociados</h2>
    <form method="post" class="grid-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_contact">
        <input type="hidden" name="client_id" value="<?= (int) $edit['id'] ?>">
        <input type="hidden" name="contact_id" value="<?= (int) ($contactEdit['id'] ?? 0) ?>">
        <label>
            Nombre y apellido
            <input name="full_name" required value="<?= e($contactEdit['full_name'] ?? '') ?>">
        </label>
        <label>
            Correo
            <input type="email" name="email" value="<?= e($contactEdit['email'] ?? '') ?>">
        </label>
        <label>
            Celular
            <input name="phone" value="<?= e($contactEdit['phone'] ?? '') ?>">
        </label>
        <div class="form-actions wide">
            <button class="button primary" type="submit"><?= $contactEdit ? 'Guardar contacto' : 'Agregar contacto' ?></button>
            <?php if ($contactEdit): ?><a class="button" href="<?= e(publicPath('/clients.php?edit=' . (int) $edit['id'])) ?>">Cancelar</a><?php endif; ?>
        </div>
    </form>

    <?php $clientContacts = $contactsByClient[(int) $edit['id']] ?? []; ?>
    <?php if (!$clientContacts): ?>
        <p class="muted">Este cliente todavia no tiene contactos asociados.</p>
    <?php else: ?>
        <div class="table-wrap contact-table">
            <table>
                <thead>
                <tr>
                    <th>Nombre y apellido</th>
                    <th>Correo</th>
                    <th>Celular</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clientContacts as $contact): ?>
                    <tr>
                        <td><?= e($contact['full_name']) ?></td>
                        <td><?= e($contact['email'] ?? '') ?></td>
                        <td><?= e($contact['phone'] ?? '') ?></td>
                        <td class="right">
                            <div class="actions-cell">
                                <a class="button small" href="<?= e(publicPath('/clients.php?edit=' . (int) $edit['id'] . '&contact_edit=' . (int) $contact['id'])) ?>">Editar</a>
                                <form method="post" onsubmit="return confirm('Eliminar este contacto?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_contact">
                                    <input type="hidden" name="client_id" value="<?= (int) $edit['id'] ?>">
                                    <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                                    <button class="button danger small" type="submit">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
    <div class="section-title">
        <h2>Clientes cargados</h2>
        <input type="search" id="clients-search" class="list-search" placeholder="Buscar cliente" autocomplete="off">
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Empresa</th>
                <th>RUC</th>
                <th>País</th>
                <th>Direccion</th>
                <th>Email</th>
                <th>Telefono</th>
                <th>Cargado por</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($clients as $client): ?>
                <tr data-filter-search="<?= e($client['empresa'] . ' ' . ($client['ruc'] ?? '') . ' ' . ($client['pais'] ?? '') . ' ' . ($client['direccion'] ?? '') . ' ' . ($client['email'] ?? '') . ' ' . ($client['telefono'] ?? '') . ' ' . ($client['created_by_username'] ?? '')) ?>">
                    <td><?= e($client['empresa']) ?></td>
                    <td><?= e($client['ruc']) ?></td>
                    <td><?= e($client['pais'] ?? '') ?></td>
                    <td><?= e($client['direccion']) ?></td>
                    <td><?= e($client['email']) ?></td>
                    <td><?= e($client['telefono']) ?></td>
                    <td><?= e($client['created_by_username']) ?></td>
                    <td class="right"><a href="<?= e(publicPath('/clients.php?edit=' . (int) $client['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted empty-search" id="clients-empty" hidden>No se encontraron clientes.</p>
</section>
<script>
(function () {
    const search = document.getElementById('clients-search');
    const rows = Array.from(document.querySelectorAll('[data-filter-search]'));
    const empty = document.getElementById('clients-empty');

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
