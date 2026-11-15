<?php
/**
 * KSI Eye Centre HMS — command-line installer and maintenance.
 *
 *   php bin/install.php setup            First-time install: config, database, schema, grants, seed data, admin.
 *   php bin/install.php init-config      Create the private config (DB credentials + encryption keys).
 *                                        Never overwrites an existing config: losing the keys means losing
 *                                        every encrypted patient field.
 *   php bin/install.php create-db        Create the database and the two DB accounts.
 *   php bin/install.php migrate          Apply new files from database/migrations (as ksi_eye_migrate).
 *   php bin/install.php grant            Re-apply table-level privileges for ksi_eye_app from database/grants.php.
 *   php bin/install.php seed             Insert/refresh reference data (permissions, roles, lists, prices).
 *   php bin/install.php create-admin     Create an administrator (prompts; or KSI_ADMIN_* env vars).
 *   php bin/install.php crypto-selftest  Verify encryption and blind indexes with the configured keys.
 *   php bin/install.php reset            DEVELOPMENT ONLY: drop all tables, then migrate, grant and seed.
 *   php bin/install.php clear-catalogue  Before go-live: remove the example medicines (and their pharmacy stock
 *                                        rows) and the example prices, keeping the consultation and frame prices
 *                                        the system needs. Refuses if any of them has already been used.
 *   php bin/install.php medicines-sheet FILE.csv
 *                                        Propose the pharmacy medicine list from the Price List ("Other")
 *                                        and the store's medicines, for the clinic to check in Excel.
 *   php bin/install.php medicines-apply FILE.csv
 *                                        Apply a checked sheet: create/price the medicines, link store items,
 *                                        take the medicines out of the Price List, retire the rest.
 *
 * Root DB credentials for create-db/grant come from KSI_DB_ROOT_USER / KSI_DB_ROOT_PASSWORD (default root / empty).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('KSI_SKIP_CONFIG', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http\HttpException;
use App\Core\Sequence;

const PROJECT_ROOT = __DIR__ . '/..';

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message" . PHP_EOL);
    exit(1);
}

function prompt(string $label, bool $hidden = false): string
{
    if ($hidden && PHP_OS_FAMILY === 'Windows') {
        $ps = '$p = Read-Host -AsSecureString ' . escapeshellarg($label) . '; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))';
        return rtrim((string) shell_exec('powershell -NoProfile -Command "' . str_replace('"', '\"', $ps) . '"'), "\r\n");
    }
    if ($hidden) {
        system('stty -echo');
    }
    fwrite(STDOUT, "$label: ");
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden) {
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $hidden ? $value : trim($value);
}

function randomSecret(int $bytes = 32): string
{
    return base64_encode(random_bytes($bytes));
}

function randomPassword(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function loadConfig(): void
{
    if (!is_file(Config::privateDir() . '/config.php')) {
        fail('Private config not found — run: php bin/install.php init-config');
    }
    Config::load();
}

function migrateConnection(): PDO
{
    $c = Config::get('db');
    return Database::connect($c['migrate_user'], $c['migrate_password']);
}

function rootConnection(): PDO
{
    return Database::connect(getenv('KSI_DB_ROOT_USER') ?: 'root', getenv('KSI_DB_ROOT_PASSWORD') ?: '', false);
}

// ─── Commands ────────────────────────────────────────────────────────────

function initConfig(): void
{
    $dir = Config::privateDir();
    $file = "$dir/config.php";
    if (is_file($file)) {
        out("Config already exists, left untouched: $file");
        return;
    }
    foreach ([$dir, "$dir/storage/uploads", "$dir/logs"] as $d) {
        if (!is_dir($d) && !mkdir($d, 0700, true)) {
            fail("Cannot create $d");
        }
    }
    $config = [
        'env' => 'development',
        'db' => [
            'host' => '127.0.0.1', 'port' => 3306, 'name' => 'ksi_eye', 'charset' => 'utf8mb4',
            'app_user' => 'ksi_eye_app', 'app_password' => randomPassword(),
            'migrate_user' => 'ksi_eye_migrate', 'migrate_password' => randomPassword(),
        ],
        'crypto' => [
            'active_key_id' => 'k1',
            'keys' => ['k1' => randomSecret()],
            'blind_index_key' => randomSecret(),
        ],
        'session' => ['name' => 'KSIEYE_SID', 'secure_cookie' => false],
        'paths' => ['storage' => "$dir/storage", 'logs' => "$dir/logs"],
    ];
    $php = "<?php\n// KSI Eye HMS private configuration — contains secrets. BACK UP the crypto keys securely;\n"
        . "// without them encrypted patient data cannot be recovered. Never commit or copy into the web root.\n"
        . 'return ' . var_export($config, true) . ";\n";
    if (file_put_contents($file, $php, LOCK_EX) === false) {
        fail("Cannot write $file");
    }
    out("Created $file (secrets not shown). Back up this file to a secure offline location.");
}

function createDb(): void
{
    loadConfig();
    $c = Config::get('db');
    $root = rootConnection();
    $db = str_replace('`', '', $c['name']);
    $root->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach ([[$c['migrate_user'], $c['migrate_password']], [$c['app_user'], $c['app_password']]] as [$user, $pass]) {
        foreach (['localhost', '127.0.0.1'] as $host) {
            $account = $root->quote($user) . '@' . $root->quote($host);
            $root->exec("CREATE USER IF NOT EXISTS $account IDENTIFIED BY " . $root->quote($pass));
            $root->exec("ALTER USER $account IDENTIFIED BY " . $root->quote($pass));
        }
    }
    foreach (['localhost', '127.0.0.1'] as $host) {
        $root->exec("GRANT ALL PRIVILEGES ON `$db`.* TO " . $root->quote($c['migrate_user']) . '@' . $root->quote($host));
    }
    $root->exec('FLUSH PRIVILEGES');
    out("Database `$db` and accounts {$c['migrate_user']}, {$c['app_user']} are ready.");
}

function migrate(): void
{
    loadConfig();
    $pdo = migrateConnection();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $applied = $pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);

    $files = glob(PROJECT_ROOT . '/database/migrations/*.sql') ?: [];
    sort($files);
    $count = 0;
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        $sql = (string) file_get_contents($file);
        $checksum = hash('sha256', $sql);
        if (isset($applied[$version])) {
            if (!hash_equals($applied[$version], $checksum)) {
                fail("Migration $version changed after it was applied. Add a new migration instead of editing an old one.");
            }
            continue;
        }
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*$/m', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (:v, :c)')->execute(['v' => $version, 'c' => $checksum]);
        out("Applied migration $version");
        $count++;
    }
    out($count ? "$count migration(s) applied." : 'Schema is up to date.');
}

function grant(): void
{
    loadConfig();
    $c = Config::get('db');
    $db = str_replace('`', '', $c['name']);
    $root = rootConnection();
    $grants = require PROJECT_ROOT . '/database/grants.php';
    $stmt = $root->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = :db');
    $stmt->execute(['db' => $db]);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach (['localhost', '127.0.0.1'] as $host) {
        $account = $root->quote($c['app_user']) . '@' . $root->quote($host);
        $root->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");
        foreach ($grants as $table => $privileges) {
            if (!in_array($table, $tables, true)) {
                fail("grants.php lists table '$table', which does not exist. Run migrate first.");
            }
            foreach ($privileges as $privilege) {
                if (!in_array($privilege, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) {
                    fail("Unsupported privilege '$privilege' for $table");
                }
            }
            $root->exec('GRANT ' . implode(', ', $privileges) . " ON `$db`.`$table` TO $account");
        }
    }
    $root->exec('FLUSH PRIVILEGES');
    $ungranted = array_diff($tables, array_keys($grants), ['schema_migrations']);
    out('Runtime account privileges applied (table-level).' . ($ungranted ? ' No access to: ' . implode(', ', $ungranted) : ''));
}

function seed(): void
{
    loadConfig();
    (require PROJECT_ROOT . '/database/seeds/foundation.php')(migrateConnection());
    out('Reference data seeded.');
}

function createAdmin(): void
{
    loadConfig();
    Database::use(migrateConnection());
    $adminRoleId = (int) Database::value('SELECT id FROM roles WHERE code = :c', ['c' => 'admin']);
    if ($adminRoleId === 0) {
        fail('Roles are missing — run: php bin/install.php seed');
    }

    $username = getenv('KSI_ADMIN_USERNAME') ?: prompt('Administrator username');
    $first = getenv('KSI_ADMIN_FIRST_NAME') ?: prompt('First name');
    $last = getenv('KSI_ADMIN_LAST_NAME') ?: prompt('Last name');
    $phone = getenv('KSI_ADMIN_PHONE') ?: prompt('Phone number (e.g. 255712345678)');
    $password = getenv('KSI_ADMIN_PASSWORD') ?: '';
    if ($password === '') {
        $password = prompt('Password (input hidden)', true);
        if ($password !== prompt('Repeat password', true)) {
            fail('Passwords do not match.');
        }
    }

    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        fail('Username must be 3–50 letters, digits, dots, dashes or underscores.');
    }
    if ($first === '' || $last === '') {
        fail('First and last name are required.');
    }
    if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
        fail('Enter a valid phone number.');
    }
    try {
        Auth::assertPasswordPolicy($password, $username);
    } catch (HttpException $e) {
        fail('Password: ' . implode(' ', $e->fields));
    }
    if (Database::value('SELECT 1 FROM users WHERE username = :u', ['u' => $username])) {
        fail("A user named '$username' already exists.");
    }

    $departmentId = Database::value('SELECT id FROM departments WHERE name = :n', ['n' => 'Administration']);
    $designationId = Database::value('SELECT id FROM designations WHERE code = :c', ['c' => 'administrator']);
    $id = Database::transaction(function () use ($username, $first, $last, $phone, $password, $departmentId, $designationId, $adminRoleId): int {
        $id = Database::insert(
            'INSERT INTO users (staff_no, username, password_hash, first_name, last_name, phone, department_id, status,
                                must_change_password, password_changed_at, created_at)
             VALUES (:staff, :u, :h, :f, :l, :p, :d, :st, 0, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))',
            [
                'staff' => sprintf('USR-%04d', Sequence::next('user')), 'u' => $username, 'h' => Auth::hashPassword($password),
                'f' => $first, 'l' => $last, 'p' => $phone, 'd' => $departmentId === null ? null : (int) $departmentId, 'st' => 'active',
            ]
        );
        Database::execute('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (:u, :r, UTC_TIMESTAMP(3))', ['u' => $id, 'r' => $adminRoleId]);
        if ($designationId !== null) {
            Database::execute('INSERT INTO user_designations (user_id, designation_id, assigned_at) VALUES (:u, :g, UTC_TIMESTAMP(3))', ['u' => $id, 'g' => (int) $designationId]);
        }
        return $id;
    });
    Audit::log('users', 'user.created', "Administrator account $username created by installer", 'user', $id, null,
        ['user_id' => null, 'username' => 'installer', 'display' => 'System installer']);
    out("Administrator '$username' created. Sign in at the application URL.");
}

function cryptoSelfTest(): void
{
    loadConfig();
    $plain = 'Amina Yusuf Hassan · 255712345001 · ñ ü 眼';
    $a = Crypto::encrypt($plain, 'selftest.field');
    $b = Crypto::encrypt($plain, 'selftest.field');
    $checks = [
        'round trip' => Crypto::decrypt($a, 'selftest.field') === $plain,
        'random nonce' => $a !== $b,
        'plaintext hidden' => !str_contains($a, 'Amina'),
    ];
    try {
        Crypto::decrypt($a, 'selftest.other_field');
        $checks['context binding'] = false;
    } catch (RuntimeException) {
        $checks['context binding'] = true;
    }
    $tampered = $a;
    $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
    try {
        Crypto::decrypt($tampered, 'selftest.field');
        $checks['tamper detection'] = false;
    } catch (RuntimeException) {
        $checks['tamper detection'] = true;
    }
    $checks['blind index normalises'] = Crypto::blindIndex('  AMINA ', 'x') === Crypto::blindIndex('amina', 'x');
    $checks['blind index is keyed per context'] = Crypto::blindIndex('amina', 'x') !== Crypto::blindIndex('amina', 'y');

    foreach ($checks as $name => $ok) {
        out(($ok ? '  PASS  ' : '  FAIL  ') . $name);
    }
    if (in_array(false, $checks, true)) {
        fail('Crypto self-test failed.');
    }
    out('Crypto self-test passed.');
}

function resetDatabase(): void
{
    loadConfig();
    if (Config::isProduction()) {
        fail('Refusing to reset a production database.');
    }
    $db = (string) Config::get('db.name');
    if (getenv('KSI_CONFIRM_RESET') !== 'yes' && prompt("Type RESET to drop every table in `$db`") !== 'RESET') {
        fail('Aborted.');
    }
    $pdo = migrateConnection();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    out("All tables in `$db` dropped.");
    migrate();
    grant();
    seed();
}

/** Prices the system looks up by code: visit consultations and the two frame prices. Never cleared. */
const REQUIRED_PRICE_CODES = ['CONS-NORMAL', 'CONS-FAST', 'CONS-FOLLOWUP', 'CONS-SURG-FOLLOWUP', 'CONS-FOLLOWUP-FAST',
    'FRAME-INTERNAL', 'FRAME-EXTERNAL'];

/**
 * A new clinic starts with the prototype's example medicines and prices. Before go-live they are
 * cleared so the clinic's own lists can go in. Only possible while none of them has been used:
 * a prescription, bill, stock movement or store link keeps the whole clear from happening.
 * Operation templates hang off surgery prices, so they go too; `seed` re-attaches them by name
 * once the clinic's own surgeries and medicines are in.
 */
function clearCatalogue(): void
{
    loadConfig();
    Database::use(migrateConnection());
    [$keep, $keepParams] = Database::in('k', REQUIRED_PRICE_CODES);
    $drugs = (int) Database::value('SELECT COUNT(*) FROM drugs');
    $prices = (int) Database::value("SELECT COUNT(*) FROM price_items WHERE code NOT IN ($keep)", $keepParams);
    if ($drugs === 0 && $prices === 0) {
        out('Nothing to clear: no medicines, and only the required prices.');
        return;
    }
    out("This removes $drugs medicine(s) with their pharmacy stock rows, $prices price(s) and their operation templates.");
    out('Kept: ' . implode(', ', REQUIRED_PRICE_CODES) . ' (set their real amounts in the Price List).');
    if (getenv('KSI_CONFIRM_CLEAR') !== 'yes' && prompt('Type CLEAR to continue') !== 'CLEAR') {
        fail('Aborted.');
    }

    $removed = $prices . ' price(s)';
    try {
        Database::transaction(function () use ($keep, $keepParams): void {
            $removedItems = "SELECT id FROM price_items WHERE code NOT IN ($keep)";
            Database::execute('DELETE FROM operation_template_medicines');
            Database::execute("DELETE FROM operation_templates WHERE price_item_id IN ($removedItems)", $keepParams);
            Database::execute('DELETE FROM pharmacy_stock');
            Database::execute('DELETE FROM drugs');
            Database::execute("DELETE FROM price_history WHERE price_item_id IN ($removedItems)", $keepParams);
            Database::execute("DELETE FROM price_items WHERE code NOT IN ($keep)", $keepParams);
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail('Nothing was removed: some of these medicines or prices are already used (a bill, prescription, '
                . 'stock movement, booking or store item points at them).');
        }
        throw $e;
    }
    Audit::log('settings', 'catalogue.cleared', "Example catalogue cleared: $drugs medicine(s), $removed", 'catalogue', 'example', null,
        ['user_id' => null, 'username' => 'installer', 'display' => 'System installer']);
    out("Removed $drugs medicine(s) and $removed. Add the clinic's own prices and medicines, then run: php bin/install.php seed");
}

// ─── Pharmacy medicine list (UAT round 2) ────────────────────────────────
// The clinic typed medicine prices into the Price List and medicines into the
// store, neither of which the pharmacy reads. These two commands turn that into
// the pharmacy's own medicine list, with a sheet the clinic checks in between.

const MEDICINE_SHEET_COLUMNS = ['action', 'medicine_name', 'unit_price', 'form', 'store_item_code', 'store_item_name',
    'price_item_code', 'price_item_name', 'note'];

/** Price List code prefixes the clinic used for medicines, and the form each one means. */
const MEDICINE_PRICE_PREFIXES = ['EYEDROP' => 'Eye Drops', 'EYDROP' => 'Eye Drops', 'EYE-DROP' => 'Eye Drops', 'TABLET' => 'Tablets',
    'TAB' => 'Tablets', 'OILTMENT' => 'Eye Ointment', '0ILTMENT' => 'Eye Ointment', 'INJECTION' => 'Injection', 'AMPULE' => 'Injection'];

function medicineWords(string $name): array
{
    $plain = (string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($name));
    return array_values(array_unique(array_filter(explode(' ', trim($plain)), static fn (string $w) => $w !== '')));
}

/**
 * How alike two names are, 0..1: the share of words in common, or a little less than 1
 * when every word of the shorter name is in the longer ("BETAMETHASONE + NEOMYCIN"
 * against "BETAMETHASONE + NEOMYCIN (PROBETA - N) 7.5ml").
 */
function medicineLikeness(string $a, string $b): float
{
    $x = medicineWords($a);
    $y = medicineWords($b);
    $shared = count(array_intersect($x, $y));
    $union = count(array_unique([...$x, ...$y]));
    if ($union === 0) {
        return 0.0;
    }
    return max($shared / $union, $shared === min(count($x), count($y)) ? 0.9 : 0.0);
}

function medicineForm(string $priceCode): ?string
{
    foreach (MEDICINE_PRICE_PREFIXES as $prefix => $form) {
        if (str_starts_with(strtoupper($priceCode), $prefix)) {
            return $form;
        }
    }
    return null;
}

function medicinesSheet(string $file): void
{
    loadConfig();
    Database::use(migrateConnection());
    $prices = Database::all("SELECT code, name, unit_price FROM price_items WHERE category = 'other' AND is_active = 1 ORDER BY name");
    $storeItems = Database::all(
        "SELECT code, name FROM store_items
          WHERE is_active = 1 AND (drug_id IS NOT NULL OR category LIKE '%medic%') ORDER BY name"
    );
    $drugs = Database::all('SELECT name, unit_price, dosage_form FROM drugs WHERE is_active = 1 ORDER BY name');

    // Pair each store medicine with the closest Price List entry, best pairs first, each used once.
    $pairs = [];
    foreach ($storeItems as $si => $item) {
        foreach ($prices as $pi => $price) {
            $score = medicineLikeness($item['name'], $price['name']);
            if ($score >= 0.5) {
                $pairs[] = [$score, $si, $pi];
            }
        }
    }
    usort($pairs, static fn (array $a, array $b) => $b[0] <=> $a[0]);
    $storeMatch = [];
    $priceUsed = [];
    foreach ($pairs as [$score, $si, $pi]) {
        if (!isset($storeMatch[$si]) && !isset($priceUsed[$pi])) {
            $storeMatch[$si] = [$pi, $score];
            $priceUsed[$pi] = true;
        }
    }

    $rows = [];
    $named = [];
    foreach ($storeItems as $si => $item) {
        [$pi, $score] = $storeMatch[$si] ?? [null, 0];
        $price = $pi === null ? null : $prices[$pi];
        $hasPrice = $price !== null && (float) $price['unit_price'] > 0;
        $rows[] = [
            $hasPrice ? 'create' : 'needs_price', $item['name'], $hasPrice ? $price['unit_price'] : '',
            ($price ? medicineForm($price['code']) : null) ?? '', $item['code'], $item['name'],
            $price['code'] ?? '', $price['name'] ?? '',
            $price === null ? 'No price found in the Price List — enter one'
                : ($score < 1 ? 'Names differ — check this is the same medicine' : ''),
        ];
        $named[mb_strtoupper($item['name'])] = true;
    }
    foreach ($prices as $pi => $price) {
        if (isset($priceUsed[$pi])) {
            continue;
        }
        $form = medicineForm($price['code']);
        $priced = (float) $price['unit_price'] > 0;
        // Migration 012 marked a Medicine entry that duplicated an Other one with " (medicine)".
        $twin = preg_replace('/ \(medicine\)$/', '', $price['name']);
        if ($twin !== $price['name'] && isset($named[mb_strtoupper($twin)])) {
            $rows[] = ['skip', $price['name'], $price['unit_price'], $form ?? '', '', '', $price['code'], $price['name'],
                "Same medicine as \"$twin\" — check which price is right"];
            continue;
        }
        $rows[] = [
            $form === null ? 'skip' : ($priced ? 'create' : 'needs_price'), $price['name'], $priced ? $price['unit_price'] : '', $form ?? '',
            '', '', $price['code'], $price['name'],
            $form === null ? 'Looks like a service, not a medicine — stays in the Price List' : 'Not found in the store',
        ];
        $named[mb_strtoupper($price['name'])] = true;
    }
    foreach ($drugs as $drug) {
        if (!isset($named[mb_strtoupper($drug['name'])])) {
            $rows[] = ['retire', $drug['name'], (float) $drug['unit_price'] > 0 ? $drug['unit_price'] : '', $drug['dosage_form'] ?? '',
                '', '', '', '', 'Already on the pharmacy list — retire, or change to keep'];
        }
    }

    $out = fopen($file, 'wb') ?: fail("Cannot write $file");
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, MEDICINE_SHEET_COLUMNS);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    $count = array_count_values(array_column($rows, 0));
    ksort($count);
    out("Sheet written to $file — " . implode(', ', array_map(static fn ($k, $n) => "$n $k", array_keys($count), $count)));
    out('Actions: create | needs_price (fill unit_price, then change to create) | keep | retire | skip.');
}

function medicinesApply(string $file): void
{
    loadConfig();
    Database::use(migrateConnection());
    $in = fopen($file, 'rb') ?: fail("Cannot read $file");
    $header = fgetcsv($in) ?: fail('The sheet is empty.');
    $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    $width = count(MEDICINE_SHEET_COLUMNS);
    if (array_slice(array_map('trim', $header), 0, $width) !== MEDICINE_SHEET_COLUMNS) {
        fail('Unexpected columns. Expected: ' . implode(',', MEDICINE_SHEET_COLUMNS));
    }
    $rows = [];
    $problems = [];
    $line = 1;
    while (($cells = fgetcsv($in)) !== false) {
        $line++;
        if ($cells === [null]) {
            continue;
        }
        $row = array_combine(MEDICINE_SHEET_COLUMNS, array_map('trim', array_pad(array_slice($cells, 0, $width), $width, '')));
        $row['action'] = strtolower($row['action']);
        $row['unit_price'] = str_replace(',', '', $row['unit_price']);
        $row['line'] = $line;
        if (!in_array($row['action'], ['create', 'keep', 'retire', 'skip', 'needs_price'], true)) {
            $problems[] = "Line $line: unknown action '{$row['action']}'.";
        } elseif ($row['action'] === 'needs_price') {
            $problems[] = "Line $line: {$row['medicine_name']} still needs a price.";
        } elseif ($row['action'] === 'create' && (!is_numeric($row['unit_price']) || (float) $row['unit_price'] <= 0)) {
            $problems[] = "Line $line: {$row['medicine_name']} needs a price above zero.";
        } elseif ($row['action'] !== 'skip' && ($row['medicine_name'] === '' || mb_strlen($row['medicine_name']) > 150)) {
            $problems[] = "Line $line: the medicine name is missing or longer than 150 characters.";
        }
        $rows[] = $row;
    }
    fclose($in);
    $creating = array_filter($rows, static fn (array $r) => $r['action'] === 'create');
    foreach (array_count_values(array_map(static fn (array $r) => mb_strtoupper($r['medicine_name']), $creating)) as $name => $n) {
        if ($n > 1) {
            $problems[] = "\"$name\" appears $n times — keep one row.";
        }
    }
    if ($problems) {
        fail("Nothing was changed. Fix the sheet first:\n  " . implode("\n  ", $problems));
    }

    $actor = ['user_id' => null, 'username' => 'installer', 'display' => 'Medicine list import'];
    $done = ['created' => 0, 'repriced' => 0, 'linked' => 0, 'prices_retired' => 0, 'retired' => 0, 'unlinked' => 0];
    Database::transaction(function () use ($rows, $actor, &$done): void {
        $keep = [];
        foreach ($rows as $row) {
            if ($row['action'] === 'keep') {
                $keep[] = mb_strtoupper($row['medicine_name']);
            }
            if ($row['action'] !== 'create') {
                continue;
            }
            $form = $row['form'] === '' ? null : mb_substr($row['form'], 0, 40);
            $drug = Database::one('SELECT id, unit_price, is_active FROM drugs WHERE name = :n', ['n' => $row['medicine_name']]);
            if ($drug === null) {
                $drugId = Database::insert(
                    'INSERT INTO drugs (name, category, dosage_form, unit_price, created_at) VALUES (:n, :c, :f, :p, UTC_TIMESTAMP(3))',
                    ['n' => $row['medicine_name'], 'c' => $form ?? 'General', 'f' => $form, 'p' => $row['unit_price']]
                );
                Audit::log('settings', 'drug.created', "Added medicine {$row['medicine_name']} from the medicine list import", 'drug', $drugId,
                    ['unit_price' => ['to' => (float) $row['unit_price']]], $actor);
                $done['created']++;
            } elseif ($drug['is_active'] && abs((float) $drug['unit_price'] - (float) $row['unit_price']) < 0.005) {
                $drugId = (int) $drug['id'];
            } else {
                $drugId = (int) $drug['id'];
                Database::execute(
                    'UPDATE drugs SET unit_price = :p, is_active = 1, updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1 WHERE id = :id',
                    ['p' => $row['unit_price'], 'id' => $drugId]
                );
                Audit::log('settings', 'drug.updated', "Priced medicine {$row['medicine_name']} from the medicine list import", 'drug', $drugId,
                    ['unit_price' => ['from' => (float) $drug['unit_price'], 'to' => (float) $row['unit_price']]], $actor);
                $done['repriced']++;
            }
            $keep[] = mb_strtoupper($row['medicine_name']);
            if ($row['store_item_code'] !== '') {
                $current = Database::one('SELECT drug_id FROM store_items WHERE code = :c', ['c' => $row['store_item_code']])
                    ?? throw new RuntimeException("Line {$row['line']}: store item {$row['store_item_code']} not found. Nothing was changed.");
                if ((int) $current['drug_id'] !== $drugId) {
                    Database::execute(
                        'UPDATE store_items SET drug_id = :d, updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1 WHERE code = :c',
                        ['d' => $drugId, 'c' => $row['store_item_code']]
                    );
                    Audit::log('stores', 'store.item_updated', "Linked {$row['store_item_code']} to pharmacy medicine {$row['medicine_name']}",
                        'store_item', $row['store_item_code'], null, $actor);
                    $done['linked']++;
                }
            }
            // The price now lives on the pharmacy list; leaving it here would let medicine be billed past the pharmacy.
            if ($row['price_item_code'] !== '') {
                $retired = Database::execute(
                    "UPDATE price_items SET is_active = 0, updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                      WHERE code = :c AND category = 'other' AND is_active = 1",
                    ['c' => $row['price_item_code']]
                );
                if ($retired > 0) {
                    Audit::log('prices', 'price.updated', "Retired {$row['price_item_code']} — moved to the pharmacy medicine list",
                        'price_item', $row['price_item_code'], null, $actor);
                    $done['prices_retired']++;
                }
            }
        }

        foreach (Database::all('SELECT id, name FROM drugs WHERE is_active = 1') as $drug) {
            if (in_array(mb_strtoupper($drug['name']), $keep, true)) {
                continue;
            }
            Database::execute('UPDATE drugs SET is_active = 0, updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1 WHERE id = :id', ['id' => $drug['id']]);
            $done['unlinked'] += Database::execute('UPDATE store_items SET drug_id = NULL, row_version = row_version + 1 WHERE drug_id = :id', ['id' => $drug['id']]);
            Audit::log('settings', 'drug.updated', "Retired medicine {$drug['name']} by the medicine list import", 'drug', (int) $drug['id'],
                ['is_active' => ['from' => true, 'to' => false]], $actor);
            $done['retired']++;
        }
    });
    out(sprintf(
        'Done: %d medicines created, %d re-priced, %d store items linked, %d Price List entries retired, %d medicines retired (%d store links removed).',
        $done['created'], $done['repriced'], $done['linked'], $done['prices_retired'], $done['retired'], $done['unlinked']
    ));
}

$command = $argv[1] ?? '';
match ($command) {
    'setup' => (function (): void {
        initConfig();
        createDb();
        migrate();
        grant();
        seed();
        Database::use(migrateConnection());
        if ((int) Database::value('SELECT COUNT(*) FROM users') === 0) {
            createAdmin();
        }
    })(),
    'init-config' => initConfig(),
    'create-db' => createDb(),
    'migrate' => migrate(),
    'grant' => grant(),
    'seed' => seed(),
    'create-admin' => createAdmin(),
    'crypto-selftest' => cryptoSelfTest(),
    'reset' => resetDatabase(),
    'clear-catalogue' => clearCatalogue(),
    'medicines-sheet' => medicinesSheet($argv[2] ?? fail('Usage: php bin/install.php medicines-sheet FILE.csv')),
    'medicines-apply' => medicinesApply($argv[2] ?? fail('Usage: php bin/install.php medicines-apply FILE.csv')),
    default => fail('Usage: php bin/install.php setup|init-config|create-db|migrate|grant|seed|create-admin|crypto-selftest|reset|clear-catalogue|medicines-sheet|medicines-apply'),
};
