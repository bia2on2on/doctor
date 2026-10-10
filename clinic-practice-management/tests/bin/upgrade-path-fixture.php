<?php

declare(strict_types=1);

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\PatientRepository;

$wpHome = getenv('WP_HOME');
if (!is_string($wpHome) || $wpHome === '' || !is_file($wpHome . '/wp-load.php')) {
    fwrite(STDERR, "UPGRADE_PATH_FAILED: WordPress bootstrap is unavailable.\n");
    exit(1);
}

require $wpHome . '/wp-load.php';

$mode = $argv[1] ?? '';
$previousRef = (string) getenv('PREVIOUS_CPMS_REF');
$baselinePath = getenv('UPGRADE_FIXTURE_PATH');
// Schema reached by the PINNED PREVIOUS source at seed time.
$expectedPreviousVersion = '2026_09_26_0023';
// Schema the CANDIDATE artifact must reach after migrating forward.
$expectedCandidateVersion = '2026_10_10_0024';

$fail = static function (string $message): void {
    fwrite(STDERR, 'UPGRADE_PATH_FAILED: ' . $message . "\n");
    exit(1);
};

$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

if (!in_array($mode, ['seed', 'verify'], true)) {
    $fail('expected seed or verify mode.');
}
if (!preg_match('/^[0-9a-f]{40}$/D', $previousRef)) {
    $fail('previous CPMS source must be a full immutable commit SHA.');
}
if (!is_string($baselinePath) || $baselinePath === '') {
    $fail('upgrade fixture path is not configured.');
}

$db = App::db();

$requiredColumns = [
    'cpms_organizations' => ['id', 'name', 'slug', 'status', 'created_at', 'updated_at'],
    'cpms_clinics' => ['id', 'organization_id', 'name', 'slug', 'timezone'],
    'cpms_locations' => ['id', 'clinic_id', 'name', 'slug', 'timezone', 'is_primary', 'is_active'],
    'cpms_clinicians' => ['id', 'clinic_id', 'full_name', 'wp_user_id', 'is_active'],
    'cpms_patients' => ['id', 'clinic_id', 'mrn', 'first_name', 'last_name', 'mobile', 'status'],
    'cpms_visits' => ['id', 'clinic_id', 'location_id', 'clinician_id', 'patient_id', 'status'],
    'cpms_clinical_notes' => ['id', 'clinic_id', 'visit_id', 'patient_id', 'clinician_id', 'content_text'],
    'cpms_invoices' => ['id', 'clinic_id', 'location_id', 'invoice_number', 'patient_id', 'visit_id', 'total', 'balance'],
    'cpms_invoice_items' => ['id', 'invoice_id', 'description', 'quantity', 'unit_price', 'amount'],
    'cpms_payments' => ['id', 'clinic_id', 'location_id', 'invoice_id', 'patient_id', 'amount', 'idempotency_key'],
    'cpms_handwriting_pages' => ['id', 'background_template'],
];

$recordColumns = [
    'cpms_organizations' => ['id', 'name', 'slug', 'status', 'created_at', 'updated_at'],
    'cpms_clinics' => ['id', 'organization_id', 'name', 'slug', 'timezone', 'address', 'created_at', 'updated_at'],
    'cpms_locations' => ['id', 'clinic_id', 'name', 'slug', 'address', 'phone', 'timezone', 'is_primary', 'is_active', 'created_at', 'updated_at'],
    'cpms_clinicians' => ['id', 'clinic_id', 'wp_user_id', 'full_name', 'specialty', 'room', 'is_active', 'created_at', 'updated_at'],
    'cpms_patients' => ['id', 'clinic_id', 'mrn', 'first_name', 'last_name', 'mobile', 'gender', 'medical_history', 'status', 'created_at', 'updated_at'],
    'cpms_visits' => ['id', 'clinic_id', 'location_id', 'clinician_id', 'patient_id', 'source', 'status', 'visit_date', 'check_in_at', 'waiting_since', 'consultation_started_at', 'consultation_completed_at', 'active', 'created_at', 'updated_at'],
    'cpms_clinical_notes' => ['id', 'clinic_id', 'visit_id', 'patient_id', 'clinician_id', 'category', 'visibility', 'content_text', 'version', 'created_by_wp_user_id', 'created_at', 'updated_at'],
    'cpms_invoices' => ['id', 'clinic_id', 'location_id', 'invoice_number', 'patient_id', 'visit_id', 'status', 'subtotal', 'discount', 'tax', 'total', 'currency', 'paid_amount', 'balance', 'issued_by_wp_user_id', 'created_at', 'updated_at'],
    'cpms_invoice_items' => ['id', 'invoice_id', 'description', 'quantity', 'unit_price', 'amount', 'discount'],
    'cpms_payments' => ['id', 'clinic_id', 'location_id', 'payment_number', 'invoice_id', 'patient_id', 'amount', 'method', 'transaction_ref', 'idempotency_key', 'status', 'paid_at', 'received_by_wp_user_id', 'created_at'],
];

$schemaSnapshot = static function () use ($db, $requiredColumns, $assert): array {
    $schema = [];
    foreach ($requiredColumns as $shortTable => $required) {
        $physicalTable = $db->table($shortTable);
        $rows = $db->fetchAll(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            [$physicalTable]
        );
        $assert($rows !== [], 'required table is missing: ' . $shortTable);

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) $row['COLUMN_NAME'];
            $columns[$name] = [
                'type' => (string) $row['COLUMN_TYPE'],
                'nullable' => (string) $row['IS_NULLABLE'],
            ];
        }
        foreach ($required as $name) {
            $assert(isset($columns[$name]), 'required column is missing: ' . $shortTable . '.' . $name);
        }
        $schema[$shortTable] = $columns;
    }

    $assert(
        $schema['cpms_clinics']['organization_id']['nullable'] === 'NO',
        'Clinic-to-Organization must remain required.'
    );
    $assert(
        $schema['cpms_visits']['location_id']['nullable'] === 'NO',
        'Visit-to-Location must remain required.'
    );
    $assert(
        str_contains(strtolower($schema['cpms_handwriting_pages']['background_template']['type']), 'prescription'),
        'current 0023 handwriting schema is not present.'
    );

    foreach ([
        ['cpms_clinics', 'organization_id', 'cpms_organizations'],
        ['cpms_locations', 'clinic_id', 'cpms_clinics'],
        ['cpms_patients', 'clinic_id', 'cpms_clinics'],
        ['cpms_visits', 'location_id', 'cpms_locations'],
    ] as [$source, $column, $target]) {
        $foreignKeyCount = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
               AND REFERENCED_TABLE_NAME = %s AND REFERENCED_COLUMN_NAME = %s',
            [$db->table($source), $column, $db->table($target), 'id']
        );
        $assert($foreignKeyCount > 0, 'required tenant foreign key is missing.');
    }

    return $schema;
};

$assertTenantRelationships = static function (array $tenants) use ($db, $assert): void {
    $organizationIds = [];
    $clinicIds = [];
    $locationIds = [];

    foreach ($tenants as $tenant) {
        $organizationId = (int) $tenant['organization_id'];
        $clinicId = (int) $tenant['clinic_id'];
        $locationId = (int) $tenant['location_id'];
        $organizationIds[] = $organizationId;
        $clinicIds[] = $clinicId;
        $locationIds[] = $locationId;

        $path = $db->fetchRow(
            'SELECT o.id AS organization_id, c.id AS clinic_id, l.id AS location_id
             FROM ' . $db->table('cpms_organizations') . ' o
             INNER JOIN ' . $db->table('cpms_clinics') . ' c ON c.organization_id = o.id
             INNER JOIN ' . $db->table('cpms_locations') . ' l ON l.clinic_id = c.id
             WHERE o.id = %d AND c.id = %d AND l.id = %d',
            [$organizationId, $clinicId, $locationId]
        );
        $assert($path !== null, 'Organization → Clinic → Location relationship changed.');
        $assert(
            (int) $path['organization_id'] === $organizationId
                && (int) $path['clinic_id'] === $clinicId
                && (int) $path['location_id'] === $locationId,
            'tenant relationship resolved to a different scope.'
        );
    }

    $assert(count($tenants) === 2, 'fixture must contain exactly two explicit tenant scopes.');
    $assert(count(array_unique($organizationIds)) === 2, 'Organization scopes collapsed.');
    $assert(count(array_unique($clinicIds)) === 2, 'Clinic scopes collapsed.');
    $assert(count(array_unique($locationIds)) === 2, 'Location scopes collapsed.');
};

$captureRecords = static function (array $tenants) use ($db, $recordColumns, $assert): array {
    $snapshot = [];
    foreach ($tenants as $scope => $tenant) {
        foreach ($recordColumns as $shortTable => $columns) {
            $id = (int) $tenant['record_ids'][$shortTable];
            $row = $db->fetchRow(
                'SELECT ' . implode(', ', $columns) . ' FROM ' . $db->table($shortTable) . ' WHERE id = %d',
                [$id]
            );
            $assert($row !== null, 'representative record is missing from ' . $shortTable . '.');
            $snapshot[$scope][$shortTable] = $row;
        }
    }

    return $snapshot;
};

$insertRecord = static function (string $shortTable, array $row) use ($db, $assert): int {
    $assert($db->insert($shortTable, $row), 'could not seed synthetic row in ' . $shortTable . '.');
    $id = $db->wpdb_last_insert_id();
    $assert($id > 0, 'synthetic insert did not return a row identifier.');

    return $id;
};

if ($mode === 'seed') {
    $assert(!is_file($baselinePath), 'refusing to replace an existing pre-upgrade snapshot.');
    $schemaVersion = App::migrations()->currentVersion();
    $assert($schemaVersion === $expectedPreviousVersion, 'pinned previous source did not reach its expected schema.');

    $admin = get_user_by('login', 'up_admin');
    $assert(is_object($admin) && isset($admin->ID), 'synthetic WordPress actor is missing.');
    $actorId = (int) $admin->ID;
    $fixedTime = '2026-09-26 12:34:56.000';
    $visitTime = '2026-09-25 09:00:00.000';
    $visitDate = '2026-09-25';
    $fixtureCases = [
        'alpha' => [
            'name' => 'Synthetic Upgrade Alpha',
            'slug' => 'synthetic-upgrade-alpha',
            'mrn' => 'SYN-UPG-ALPHA-001',
            'mobile' => 'synthetic-alpha',
            'invoice' => 'SYN-UPG-ALPHA-INV-001',
            'payment' => 'SYN-UPG-ALPHA-PAY-001',
            'payment_key' => 'synthetic-upgrade-alpha-payment',
        ],
        'beta' => [
            'name' => 'Synthetic Upgrade Beta',
            'slug' => 'synthetic-upgrade-beta',
            'mrn' => 'SYN-UPG-BETA-001',
            'mobile' => 'synthetic-beta',
            'invoice' => 'SYN-UPG-BETA-INV-001',
            'payment' => 'SYN-UPG-BETA-PAY-001',
            'payment_key' => 'synthetic-upgrade-beta-payment',
        ],
    ];

    $tenants = [];
    foreach ($fixtureCases as $scope => $fixture) {
        $organizationId = $insertRecord('cpms_organizations', [
            'name' => $fixture['name'] . ' Organization',
            'slug' => $fixture['slug'] . '-org',
            'status' => 'active',
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $clinicId = $insertRecord('cpms_clinics', [
            'organization_id' => $organizationId,
            'name' => $fixture['name'] . ' Clinic',
            'slug' => $fixture['slug'] . '-clinic',
            'timezone' => 'UTC',
            'address' => 'Synthetic fixture address only',
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $locationId = $insertRecord('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $fixture['name'] . ' Location',
            'slug' => $fixture['slug'] . '-location',
            'address' => 'Synthetic fixture location only',
            'phone' => null,
            'timezone' => 'UTC',
            'is_primary' => 1,
            'is_active' => 1,
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $clinicianId = $insertRecord('cpms_clinicians', [
            'clinic_id' => $clinicId,
            'wp_user_id' => null,
            'full_name' => $fixture['name'] . ' Clinician',
            'specialty' => 'Synthetic specialty',
            'room' => 'SYN-' . strtoupper($scope),
            'is_active' => 1,
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $patientId = $insertRecord('cpms_patients', [
            'clinic_id' => $clinicId,
            'mrn' => $fixture['mrn'],
            'first_name' => 'Synthetic',
            'last_name' => 'Upgrade ' . ucfirst($scope),
            'mobile' => $fixture['mobile'],
            'gender' => 'unknown',
            'medical_history' => 'Synthetic fixture only; contains no patient information.',
            'status' => 'active',
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $visitId = $insertRecord('cpms_visits', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'patient_id' => $patientId,
            'source' => 'walk_in',
            'status' => 'consultation_completed',
            'visit_date' => $visitDate,
            'check_in_at' => $visitTime,
            'waiting_since' => $visitTime,
            'consultation_started_at' => '2026-09-25 09:10:00.000',
            'consultation_completed_at' => '2026-09-25 09:30:00.000',
            'active' => 0,
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $noteId = $insertRecord('cpms_clinical_notes', [
            'clinic_id' => $clinicId,
            'visit_id' => $visitId,
            'patient_id' => $patientId,
            'clinician_id' => $clinicianId,
            'category' => 'clinical_note',
            'visibility' => 'doctor_private',
            'content_text' => 'Synthetic clinical note for upgrade preservation test.',
            'version' => 1,
            'created_by_wp_user_id' => $actorId,
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $invoiceId = $insertRecord('cpms_invoices', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'invoice_number' => $fixture['invoice'],
            'patient_id' => $patientId,
            'visit_id' => $visitId,
            'status' => 'partial',
            'subtotal' => '100.00',
            'discount' => '10.00',
            'tax' => '0.00',
            'total' => '90.00',
            'currency' => 'IRR',
            'paid_amount' => '30.00',
            'balance' => '60.00',
            'issued_by_wp_user_id' => $actorId,
            'created_at' => $fixedTime,
            'updated_at' => $fixedTime,
        ]);
        $itemId = $insertRecord('cpms_invoice_items', [
            'invoice_id' => $invoiceId,
            'description' => 'Synthetic service item',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'amount' => '100.00',
            'discount' => '10.00',
        ]);
        $paymentId = $insertRecord('cpms_payments', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'payment_number' => $fixture['payment'],
            'invoice_id' => $invoiceId,
            'patient_id' => $patientId,
            'amount' => '30.00',
            'method' => 'cash',
            'transaction_ref' => 'SYNTHETIC-' . strtoupper($scope),
            'idempotency_key' => $fixture['payment_key'],
            'status' => 'captured',
            'paid_at' => $visitTime,
            'received_by_wp_user_id' => $actorId,
            'created_at' => $fixedTime,
        ]);

        $tenants[$scope] = [
            'organization_id' => $organizationId,
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'record_ids' => [
                'cpms_organizations' => $organizationId,
                'cpms_clinics' => $clinicId,
                'cpms_locations' => $locationId,
                'cpms_clinicians' => $clinicianId,
                'cpms_patients' => $patientId,
                'cpms_visits' => $visitId,
                'cpms_clinical_notes' => $noteId,
                'cpms_invoices' => $invoiceId,
                'cpms_invoice_items' => $itemId,
                'cpms_payments' => $paymentId,
            ],
        ];
    }

    $schema = $schemaSnapshot();
    $assertTenantRelationships($tenants);
    $records = $captureRecords($tenants);
    $recordsJson = json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $baseline = [
        'previous_source' => $previousRef,
        'schema_version' => $schemaVersion,
        'schema_columns' => $schema,
        'tenants' => $tenants,
        'records' => $records,
        'records_sha256' => hash('sha256', $recordsJson),
    ];
    $baselineJson = json_encode($baseline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$written = file_put_contents($baselinePath, $baselineJson, LOCK_EX);
$assert($written === strlen($baselineJson), 'could not save the private synthetic baseline snapshot.');
$assert(chmod($baselinePath, 0600), 'could not restrict access to the synthetic baseline snapshot.');

    printf(
        "UPGRADE_FIXTURE_SEEDED previous_source=%s schema=%s tenant_scopes=%d synthetic_records=%d\n",
        $previousRef,
        $schemaVersion,
        count($tenants),
        count($tenants) * count($recordColumns)
    );
    exit(0);
}

$assert(is_file($baselinePath), 'pre-upgrade snapshot is missing.');
$assert((fileperms($baselinePath) & 0777) === 0600, 'pre-upgrade snapshot permissions are not private.');
$baselineJson = file_get_contents($baselinePath);
$assert(is_string($baselineJson), 'pre-upgrade snapshot could not be read.');
$baseline = json_decode($baselineJson, true, 512, JSON_THROW_ON_ERROR);
$assert(is_array($baseline), 'pre-upgrade snapshot has an invalid format.');
$assert(($baseline['previous_source'] ?? null) === $previousRef, 'snapshot source does not match the pinned previous source.');
$assert(($baseline['schema_version'] ?? null) === $expectedPreviousVersion, 'snapshot does not represent the expected previous schema.');
$assert(is_array($baseline['tenants'] ?? null) && count($baseline['tenants']) === 2, 'snapshot does not contain two tenant scopes.');
$tenants = $baseline['tenants'];

$firstPass = App::migrations()->migrate();
$assert($firstPass === [], 'migration runner changed an already-upgraded schema.');
$currentVersion = App::migrations()->currentVersion();
$assert($currentVersion === $expectedCandidateVersion, 'current migration runner did not reach the expected schema.');
$secondPass = App::migrations()->migrate();
$assert($secondPass === [], 'second migration pass was not a safe no-op.');

$currentSchema = $schemaSnapshot();
$previousSchema = $baseline['schema_columns'] ?? [];
$assert(is_array($previousSchema), 'pre-upgrade schema snapshot is invalid.');
$assert(
    $currentSchema === $previousSchema,
    'current schema differs from the immediately preceding application schema.'
);

$assertTenantRelationships($tenants);
$currentRecords = $captureRecords($tenants);
$currentRecordsJson = json_encode($currentRecords, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$currentHash = hash('sha256', $currentRecordsJson);
$assert(
    is_string($baseline['records_sha256'] ?? null)
        && hash_equals($baseline['records_sha256'], $currentHash)
        && $currentRecords === $baseline['records'],
    'representative pre-upgrade values changed or disappeared.'
);

// Exercise the current tenant-aware repository with each explicit Clinic ID;
// the fixture intentionally does not consult licensing or infer a default tenant.
$patients = new PatientRepository($db);
$scopeNames = array_keys($tenants);
foreach ($scopeNames as $scope) {
    $clinicId = (int) $tenants[$scope]['clinic_id'];
    $patientBefore = $baseline['records'][$scope]['cpms_patients'];
    $read = $patients->findByMrn($clinicId, (string) $patientBefore['mrn']);
    $assert(
        is_array($read)
            && (int) $read['id'] === (int) $patientBefore['id']
            && (int) $read['clinic_id'] === $clinicId,
        'current tenant-aware PatientRepository could not read an upgraded row.'
    );
}
$alphaClinicId = (int) $tenants[$scopeNames[0]]['clinic_id'];
$betaClinicId = (int) $tenants[$scopeNames[1]]['clinic_id'];
$alphaMrn = (string) $baseline['records'][$scopeNames[0]]['cpms_patients']['mrn'];
$betaMrn = (string) $baseline['records'][$scopeNames[1]]['cpms_patients']['mrn'];
$assert($patients->findByMrn($alphaClinicId, $betaMrn) === null, 'cross-tenant PatientRepository read was not isolated.');
$assert($patients->findByMrn($betaClinicId, $alphaMrn) === null, 'cross-tenant PatientRepository read was not isolated.');

printf(
    "UPGRADE_PATH_OK schema=%s safe_repeat_passes=%d tenant_scopes=%d preserved_records=%d\n",
    $currentVersion,
    2,
    count($tenants),
    count($tenants) * count($recordColumns)
);
