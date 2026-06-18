PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    auth_version INTEGER NOT NULL DEFAULT 1,
    role TEXT NOT NULL DEFAULT 'commercial_executive',
    first_name TEXT NOT NULL DEFAULT '',
    last_name TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    unit TEXT NOT NULL DEFAULT 'Paraguay',
    reports_to_id INTEGER,
    commercial_position TEXT NOT NULL DEFAULT '',
    signature_image TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (reports_to_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS country_units (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    currency_symbol TEXT NOT NULL,
    currency_code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS user_country_units (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    country_unit_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (country_unit_id) REFERENCES country_units(id),
    UNIQUE (user_id, country_unit_id)
);

CREATE TABLE IF NOT EXISTS exchange_rates (
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
);

CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa TEXT NOT NULL,
    ruc TEXT,
    ruc_normalized TEXT,
    direccion TEXT,
    email TEXT,
    telefono TEXT,
    pais TEXT NOT NULL DEFAULT 'Paraguay',
    country_unit_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    created_by INTEGER,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (country_unit_id) REFERENCES country_units(id)
);

CREATE TABLE IF NOT EXISTS client_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    full_name TEXT NOT NULL,
    normalized_name TEXT NOT NULL DEFAULT '',
    email TEXT,
    phone TEXT,
    position TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS company_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    is_primary INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (company_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE CASCADE,
    UNIQUE (company_id, contact_id)
);

CREATE TABLE IF NOT EXISTS contact_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    normalized_email TEXT NOT NULL,
    is_primary INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE CASCADE,
    UNIQUE (contact_id, normalized_email)
);

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    descripcion TEXT,
    precio_venta REAL NOT NULL DEFAULT 0,
    precio_alquiler REAL NOT NULL DEFAULT 0,
    activo INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS taxes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    porcentaje REAL NOT NULL,
    paises TEXT NOT NULL DEFAULT 'Paraguay',
    activo INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    normalized_name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    prefix TEXT NOT NULL COLLATE NOCASE UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS proformas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proforma_number TEXT NOT NULL UNIQUE,
    project_id INTEGER NOT NULL,
    parent_proforma_id INTEGER,
    version_number INTEGER NOT NULL DEFAULT 1,
    project_sequence INTEGER NOT NULL,
    client_id INTEGER NOT NULL,
    client_contact_id INTEGER,
    company_id INTEGER,
    contact_id INTEGER,
    contact_email_id INTEGER,
    company_name_snapshot TEXT NOT NULL DEFAULT '',
    contact_name_snapshot TEXT NOT NULL DEFAULT '',
    contact_email_snapshot TEXT NOT NULL DEFAULT '',
    contact_name TEXT NOT NULL DEFAULT '',
    contact_email TEXT NOT NULL DEFAULT '',
    contact_phone TEXT NOT NULL DEFAULT '',
    project_name TEXT NOT NULL DEFAULT '',
    emission_date TEXT NOT NULL,
    expiration_date TEXT NOT NULL,
    validity_days INTEGER NOT NULL DEFAULT 10,
    expires_at TEXT,
    commercial_conditions TEXT,
    currency_code TEXT NOT NULL DEFAULT 'USD',
    currency_mode TEXT NOT NULL DEFAULT 'USD',
    currency_symbol TEXT NOT NULL DEFAULT 'US$',
    country_unit_id INTEGER,
    exchange_rate_used REAL DEFAULT 1,
    exchange_rate_source TEXT NOT NULL DEFAULT 'GLOBAL',
    exchange_rate_authorized_by INTEGER,
    exchange_rate_authorized_at TEXT,
    authorization_status TEXT NOT NULL DEFAULT 'NOT_REQUIRED',
    format_type TEXT NOT NULL DEFAULT 'detallado',
    subtotal REAL NOT NULL DEFAULT 0,
    discount_percent REAL NOT NULL DEFAULT 0,
    discount_amount REAL NOT NULL DEFAULT 0,
    tax_total REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'emitida',
    won_at TEXT,
    won_by INTEGER,
    commercial_status TEXT NOT NULL DEFAULT 'OPEN',
    commercial_status_updated_by INTEGER,
    commercial_status_updated_at TEXT,
    commercial_status_notes TEXT NOT NULL DEFAULT '',
    pdf_path TEXT,
    seller_id INTEGER,
    signer_name TEXT NOT NULL DEFAULT '',
    signer_email TEXT NOT NULL DEFAULT '',
    signer_phone TEXT NOT NULL DEFAULT '',
    signer_unit TEXT NOT NULL DEFAULT '',
    signer_position TEXT NOT NULL DEFAULT '',
    signer_signature_image TEXT NOT NULL DEFAULT '',
    public_token TEXT,
    email_sent_at TEXT,
    email_last_error TEXT NOT NULL DEFAULT '',
    customer_viewed_at TEXT,
    customer_downloaded_at TEXT,
    customer_update_requested_at TEXT,
    customer_update_request_error TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (parent_proforma_id) REFERENCES proformas(id),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (client_contact_id) REFERENCES client_contacts(id),
    FOREIGN KEY (company_id) REFERENCES clients(id),
    FOREIGN KEY (contact_id) REFERENCES client_contacts(id),
    FOREIGN KEY (contact_email_id) REFERENCES contact_emails(id),
    FOREIGN KEY (seller_id) REFERENCES users(id),
    FOREIGN KEY (won_by) REFERENCES users(id),
    FOREIGN KEY (commercial_status_updated_by) REFERENCES users(id),
    FOREIGN KEY (country_unit_id) REFERENCES country_units(id),
    FOREIGN KEY (exchange_rate_authorized_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS proforma_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proforma_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    description TEXT NOT NULL,
    condition_type TEXT NOT NULL,
    quantity REAL NOT NULL DEFAULT 1,
    rental_days INTEGER NOT NULL DEFAULT 1,
    unit_price REAL NOT NULL DEFAULT 0,
    tax_id INTEGER,
    tax_rate REAL NOT NULL DEFAULT 0,
    subtotal REAL NOT NULL DEFAULT 0,
    tax_amount REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    FOREIGN KEY (proforma_id) REFERENCES proformas(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (tax_id) REFERENCES taxes(id)
);

CREATE TABLE IF NOT EXISTS proforma_email_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proforma_id INTEGER,
    event_type TEXT NOT NULL DEFAULT 'customer_proforma',
    to_email TEXT NOT NULL DEFAULT '',
    subject TEXT NOT NULL DEFAULT '',
    success INTEGER NOT NULL DEFAULT 0,
    error_message TEXT NOT NULL DEFAULT '',
    sent_at TEXT NOT NULL,
    FOREIGN KEY (proforma_id) REFERENCES proformas(id)
);

CREATE TABLE IF NOT EXISTS proforma_authorizations (
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
);

CREATE TABLE IF NOT EXISTS notifications (
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
);

CREATE TABLE IF NOT EXISTS proforma_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proforma_id INTEGER NOT NULL,
    user_id INTEGER,
    event_type TEXT NOT NULL,
    event_detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (proforma_id) REFERENCES proformas(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS proforma_disclaimers (
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
);

CREATE TABLE IF NOT EXISTS proforma_disclaimer_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proforma_id INTEGER NOT NULL,
    disclaimer_id INTEGER,
    title_snapshot TEXT NOT NULL,
    body_snapshot TEXT NOT NULL,
    sort_order_snapshot INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (proforma_id) REFERENCES proformas(id) ON DELETE CASCADE,
    FOREIGN KEY (disclaimer_id) REFERENCES proforma_disclaimers(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_proformas_client_id ON proformas(client_id);
CREATE INDEX IF NOT EXISTS idx_proformas_company_id ON proformas(company_id);
CREATE INDEX IF NOT EXISTS idx_proformas_contact_id ON proformas(contact_id);
CREATE INDEX IF NOT EXISTS idx_proformas_contact_email_id ON proformas(contact_email_id);
CREATE INDEX IF NOT EXISTS idx_proformas_expires_at ON proformas(expires_at);
CREATE INDEX IF NOT EXISTS idx_proformas_created_at ON proformas(created_at);
CREATE INDEX IF NOT EXISTS idx_proformas_project_id ON proformas(project_id);
CREATE INDEX IF NOT EXISTS idx_proformas_parent_id ON proformas(parent_proforma_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_proformas_project_sequence ON proformas(project_id, project_sequence);
CREATE INDEX IF NOT EXISTS idx_proformas_seller_id ON proformas(seller_id);
CREATE INDEX IF NOT EXISTS idx_proformas_status ON proformas(status);
CREATE INDEX IF NOT EXISTS idx_proformas_commercial_status ON proformas(commercial_status);
CREATE INDEX IF NOT EXISTS idx_proformas_emission_date ON proformas(emission_date);
CREATE UNIQUE INDEX IF NOT EXISTS idx_proformas_public_token ON proformas(public_token) WHERE public_token IS NOT NULL AND public_token <> '';
CREATE INDEX IF NOT EXISTS idx_proforma_items_proforma_id ON proforma_items(proforma_id);
CREATE INDEX IF NOT EXISTS idx_proforma_email_logs_sent_at ON proforma_email_logs(sent_at);
CREATE INDEX IF NOT EXISTS idx_proforma_email_logs_proforma_id ON proforma_email_logs(proforma_id);
CREATE INDEX IF NOT EXISTS idx_proforma_authorizations_proforma_id ON proforma_authorizations(proforma_id);
CREATE INDEX IF NOT EXISTS idx_proforma_authorizations_requested_to ON proforma_authorizations(requested_to, status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_proforma_authorizations_pending
    ON proforma_authorizations(proforma_id)
    WHERE status = 'PENDING';
CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id, is_read, created_at);
CREATE INDEX IF NOT EXISTS idx_proforma_events_proforma_id ON proforma_events(proforma_id, created_at);
CREATE INDEX IF NOT EXISTS idx_proforma_disclaimers_order ON proforma_disclaimers(is_active, is_default, sort_order);
CREATE INDEX IF NOT EXISTS idx_proforma_disclaimer_snapshots_proforma ON proforma_disclaimer_snapshots(proforma_id, sort_order_snapshot);
CREATE INDEX IF NOT EXISTS idx_client_contacts_client_id ON client_contacts(client_id);
CREATE INDEX IF NOT EXISTS idx_client_contacts_normalized_name ON client_contacts(normalized_name);
CREATE UNIQUE INDEX IF NOT EXISTS idx_clients_ruc_normalized
    ON clients(ruc_normalized)
    WHERE ruc_normalized IS NOT NULL AND ruc_normalized <> '';
CREATE INDEX IF NOT EXISTS idx_clients_country_unit_id ON clients(country_unit_id);
CREATE INDEX IF NOT EXISTS idx_company_contacts_company_id ON company_contacts(company_id);
CREATE INDEX IF NOT EXISTS idx_company_contacts_contact_id ON company_contacts(contact_id);
CREATE INDEX IF NOT EXISTS idx_contact_emails_contact_id ON contact_emails(contact_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_contact_emails_primary
    ON contact_emails(contact_id)
    WHERE is_primary = 1;
CREATE INDEX IF NOT EXISTS idx_users_reports_to_id ON users(reports_to_id);
CREATE INDEX IF NOT EXISTS idx_users_role_unit ON users(role, unit);
CREATE INDEX IF NOT EXISTS idx_user_country_units_user_id ON user_country_units(user_id);
CREATE INDEX IF NOT EXISTS idx_user_country_units_country_unit_id ON user_country_units(country_unit_id);
CREATE INDEX IF NOT EXISTS idx_exchange_rates_country_unit_id ON exchange_rates(country_unit_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_exchange_rates_active_country_unit
    ON exchange_rates(country_unit_id)
    WHERE is_active = 1;
CREATE INDEX IF NOT EXISTS idx_proformas_country_unit_id ON proformas(country_unit_id);
