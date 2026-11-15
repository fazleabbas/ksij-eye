<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Patient identity. Names, date of birth, phone, address and next of kin are stored encrypted.
 * Search uses keyed blind-index tokens — every name word prefix of two or more letters, the
 * full phone number and its last six digits — so the database never sees plaintext.
 */
final class PatientService
{
    public const COLUMNS = 'p.id, p.reg_no, p.paper_file, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
        p.phone_enc, p.address_enc, p.kin_name_enc, p.kin_relationship, p.kin_relationship_other_enc, p.kin_phone_enc,
        p.status, p.row_version,
        p.registered_at, p.updated_at';

    private const MAX_PREFIX = 20;
    private const PHONE_PATTERN = '/^\+?[0-9 ]{9,20}$/';
    private const PHONE_MESSAGE = 'Enter a valid phone number, e.g. 0712 345 678 or 255712345678.';

    public static function validate(array $input, bool $creating): array
    {
        $v = Validator::make($input)
            ->string('first_name', true, 80)
            ->string('middle_name', false, 80)
            ->string('last_name', true, 80)
            ->enum('gender', ['male', 'female'])
            ->string('date_of_birth', true, 10, 10, '/^\d{4}-\d{2}-\d{2}$/', message: 'Enter a valid date of birth.')
            ->string('phone', true, 20, 9, self::PHONE_PATTERN, message: self::PHONE_MESSAGE)
            ->string('address', true, 255, 3)
            ->string('kin_name', true, 160, 2)
            ->string('kin_relationship', true, 30)
            ->string('kin_relationship_other', false, 100, 2)
            ->string('kin_phone', true, 20, 9, self::PHONE_PATTERN, message: self::PHONE_MESSAGE)
            ->bool('confirm_duplicate');
        if (!$creating) {
            $v->int('row_version', true, 1);
        } else {
            // Set only for a patient who already has a paper file; a new patient gets the next number.
            $v->string('paper_file_no', false, 9, 1, '/^\d+$/', message: 'Enter the file number in digits only.');
        }

        // Report every problem at once: basic field errors plus the checks below.
        $relationships = array_column(Database::all('SELECT value FROM lookup_values WHERE list_code = :l AND is_active = 1', ['l' => 'kin_relationship']), 'value');
        $relationship = is_string($input['kin_relationship'] ?? null) ? trim($input['kin_relationship']) : '';
        try {
            $d = $v->validate();
        } catch (HttpException $e) {
            $fields = $e->fields;
            if ($relationship !== '' && !in_array($relationship, $relationships, true)) {
                $fields['kin_relationship'] ??= 'Choose a relationship.';
            }
            throw HttpException::validation($fields);
        }

        $errors = [];
        if (!Format::isDate($d['date_of_birth'])) {
            $errors['date_of_birth'] = 'Enter a valid date of birth.';
        } elseif ($d['date_of_birth'] > Format::localToday()) {
            $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
        } elseif ($d['date_of_birth'] < '1900-01-01') {
            $errors['date_of_birth'] = 'Check the year of birth.';
        }
        if (!in_array($d['kin_relationship'], $relationships, true)) {
            $errors['kin_relationship'] = 'Choose a relationship.';
        }
        // "Other" is only useful if the clinic says what it is.
        if ($d['kin_relationship'] === 'Other' && ($d['kin_relationship_other'] ?? null) === null) {
            $errors['kin_relationship_other'] = 'Say what the relationship is.';
        }
        if ($d['kin_relationship'] !== 'Other') {
            $d['kin_relationship_other'] = null;
        }
        $d['phone'] = self::normalizePhone($d['phone']);
        $d['kin_phone'] = self::normalizePhone($d['kin_phone']);
        if (!preg_match('/^\d{10,15}$/', (string) $d['phone'])) {
            $errors['phone'] = self::PHONE_MESSAGE;
        }
        if (!preg_match('/^\d{10,15}$/', (string) $d['kin_phone'])) {
            $errors['kin_phone'] = self::PHONE_MESSAGE;
        }
        if ($errors) {
            throw HttpException::validation($errors);
        }
        return $d;
    }

    /** 0712345678 / 712345678 / +255 712 345 678 → 255712345678 */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return '255' . substr($digits, 1);
        }
        return strlen($digits) === 9 ? '255' . $digits : $digits;
    }

    /** Column values to store: ciphertexts plus the non-identifying helper columns. */
    /**
     * Checks a paper file number for a patient: digits, within the paper range set at go-live,
     * and not already someone else's. Returns it without leading zeros.
     */
    public static function assertPaperNumber(string $number, ?int $exceptPatientId = null): string
    {
        $number = ltrim($number, '0');
        $paperMax = Settings::int('patients.paper_max', 0);
        if ($number === '' || !ctype_digit($number)) {
            throw HttpException::validation(['paper_file_no' => 'Enter the file number in digits only.']);
        }
        if ($paperMax === 0) {
            throw HttpException::validation(['paper_file_no' => 'Paper file numbers are not set up yet: an administrator must enter the highest paper file number in Settings.']);
        }
        if ((int) $number > $paperMax) {
            throw HttpException::validation(['paper_file_no' => "Paper files go up to $paperMax. For a patient without a paper file, choose New patient."]);
        }
        $holder = Database::one(
            'SELECT id, reg_no, first_name_enc, middle_name_enc, last_name_enc FROM patients WHERE reg_no = :n AND id <> :except',
            ['n' => $number, 'except' => $exceptPatientId ?? 0]
        );
        if ($holder !== null) {
            $name = Format::fullName(
                (string) Crypto::decrypt($holder['first_name_enc'], 'patients.first_name'),
                Crypto::decrypt($holder['middle_name_enc'], 'patients.middle_name'),
                (string) Crypto::decrypt($holder['last_name_enc'], 'patients.last_name')
            );
            throw HttpException::validation(['paper_file_no' => "File number $number is already registered to $name."]);
        }
        return $number;
    }

    public static function storageValues(array $d): array
    {
        return [
            'first_name_enc' => Crypto::encrypt($d['first_name'], 'patients.first_name'),
            'middle_name_enc' => Crypto::encrypt($d['middle_name'], 'patients.middle_name'),
            'last_name_enc' => Crypto::encrypt($d['last_name'], 'patients.last_name'),
            'gender' => $d['gender'],
            'date_of_birth_enc' => Crypto::encrypt($d['date_of_birth'], 'patients.date_of_birth'),
            'birth_year' => (int) substr($d['date_of_birth'], 0, 4),
            'phone_enc' => Crypto::encrypt($d['phone'], 'patients.phone'),
            'address_enc' => Crypto::encrypt($d['address'], 'patients.address'),
            'kin_name_enc' => Crypto::encrypt($d['kin_name'], 'patients.kin_name'),
            'kin_relationship' => $d['kin_relationship'],
            'kin_relationship_other_enc' => Crypto::encrypt($d['kin_relationship_other'], 'patients.kin_relationship_other'),
            'kin_phone_enc' => Crypto::encrypt($d['kin_phone'], 'patients.kin_phone'),
            'identity_bidx' => self::identityIndex($d['first_name'], $d['last_name'], $d['date_of_birth']),
            'phone_bidx' => Crypto::blindIndex((string) $d['phone'], 'patients.phone_exact'),
        ];
    }

    /** Existing patients with the same name + date of birth, or the same phone number. */
    public static function possibleDuplicates(array $d, int $exceptId = 0): array
    {
        $identity = self::identityIndex($d['first_name'], $d['last_name'], $d['date_of_birth']);
        $rows = Database::all(
            'SELECT ' . self::COLUMNS . ', p.identity_bidx FROM patients p
              WHERE (p.identity_bidx = :i OR p.phone_bidx = :ph) AND p.id <> :id
              ORDER BY p.id LIMIT 5',
            ['i' => $identity, 'ph' => Crypto::blindIndex((string) $d['phone'], 'patients.phone_exact'), 'id' => $exceptId]
        );
        return array_map(static function (array $row) use ($identity): array {
            $patient = self::present($row);
            return [
                'id' => $patient['id'],
                'reg_no' => $patient['reg_no'],
                'full_name' => $patient['full_name'],
                'age' => $patient['age'],
                'gender' => $patient['gender'],
                'phone' => $patient['phone'],
                'match' => hash_equals($row['identity_bidx'], $identity) ? 'Same name and date of birth' : 'Same phone number',
            ];
        }, $rows);
    }

    public static function syncSearchTokens(int $patientId, array $d): void
    {
        Database::execute('DELETE FROM patient_search_tokens WHERE patient_id = :id', ['id' => $patientId]);
        $tokens = [];
        foreach (self::words(implode(' ', [$d['first_name'], $d['middle_name'] ?? '', $d['last_name']])) as $word) {
            for ($length = 2, $max = mb_strlen($word); $length <= $max; $length++) {
                $tokens[] = Crypto::blindIndex(mb_substr($word, 0, $length), 'patients.name_prefix');
            }
        }
        $tokens[] = Crypto::blindIndex((string) $d['phone'], 'patients.phone');
        $tokens[] = Crypto::blindIndex(substr((string) $d['phone'], -6), 'patients.phone_tail');
        foreach (array_unique($tokens) as $token) {
            Database::execute('INSERT IGNORE INTO patient_search_tokens (token, patient_id) VALUES (:t, :id)', ['t' => $token, 'id' => $patientId]);
        }
    }

    /**
     * SQL condition (on the given patients alias) for a free-text search:
     *  "3127" (or "REG-3127") → that file number; 9+ digits → phone; 6 digits → phone tail or file number;
     *  words → every word must prefix-match a name word.
     * @return array{0:string,1:array}|null
     */
    public static function searchCondition(string $query, string $alias = 'p'): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }
        $compact = (string) preg_replace('/[\s+\-]/', '', $query);

        if (preg_match('/^reg(\d+)$/i', $compact, $m)) {
            return ["$alias.reg_no = :s_reg", ['s_reg' => ltrim($m[1], '0') ?: '0']];
        }
        if (preg_match('/^\d+$/', $compact)) {
            if (strlen($compact) >= 9) {
                return [
                    "$alias.id IN (SELECT patient_id FROM patient_search_tokens WHERE token = :s_phone)",
                    ['s_phone' => Crypto::blindIndex((string) self::normalizePhone($compact), 'patients.phone')],
                ];
            }
            $conditions = ["$alias.reg_no = :s_reg"];
            $params = ['s_reg' => ltrim($compact, '0') ?: '0'];
            if (strlen($compact) === 6) {
                $conditions[] = "$alias.id IN (SELECT patient_id FROM patient_search_tokens WHERE token = :s_tail)";
                $params['s_tail'] = Crypto::blindIndex($compact, 'patients.phone_tail');
            }
            return ['(' . implode(' OR ', $conditions) . ')', $params];
        }

        $tokens = array_values(array_unique(array_map(
            static fn (string $word) => Crypto::blindIndex($word, 'patients.name_prefix'),
            self::words($query)
        )));
        if (!$tokens) {
            return ['1 = 0', []];
        }
        [$in, $params] = Database::in('s_tok', $tokens);
        $params['s_count'] = count($tokens);
        return [
            "$alias.id IN (SELECT patient_id FROM patient_search_tokens WHERE token IN ($in) GROUP BY patient_id HAVING COUNT(*) = :s_count)",
            $params,
        ];
    }

    /** Decrypts a patient row. $full adds address and next of kin. */
    public static function present(array $r, bool $full = false): array
    {
        $first = (string) Crypto::decrypt($r['first_name_enc'], 'patients.first_name');
        $middle = Crypto::decrypt($r['middle_name_enc'], 'patients.middle_name');
        $last = (string) Crypto::decrypt($r['last_name_enc'], 'patients.last_name');
        $dob = (string) Crypto::decrypt($r['date_of_birth_enc'], 'patients.date_of_birth');
        $patient = [
            'id' => (int) $r['id'],
            'reg_no' => $r['reg_no'],
            'paper_file' => (bool) ($r['paper_file'] ?? false),
            'full_name' => Format::fullName($first, $middle, $last),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'gender' => $r['gender'],
            'date_of_birth' => $dob,
            'age' => Format::ageOn($dob, Format::localToday()),
            'phone' => Crypto::decrypt($r['phone_enc'], 'patients.phone'),
            'status' => $r['status'],
            'registered_at' => Format::utc($r['registered_at']),
        ];
        if ($full) {
            $patient += [
                'address' => Crypto::decrypt($r['address_enc'], 'patients.address'),
                'kin_name' => Crypto::decrypt($r['kin_name_enc'], 'patients.kin_name'),
                'kin_relationship' => $r['kin_relationship'],
                'kin_relationship_other' => Crypto::decrypt($r['kin_relationship_other_enc'], 'patients.kin_relationship_other'),
                'kin_phone' => Crypto::decrypt($r['kin_phone_enc'], 'patients.kin_phone'),
                'row_version' => (int) $r['row_version'],
                'updated_at' => Format::utc($r['updated_at']),
            ];
        }
        return $patient;
    }

    /** Compact decrypted name and demographics for list rows that join the patients table as alias p. */
    public static function brief(array $r): array
    {
        $dob = (string) Crypto::decrypt($r['date_of_birth_enc'], 'patients.date_of_birth');
        return [
            'id' => (int) $r['patient_id'],
            'reg_no' => $r['reg_no'],
            'full_name' => Format::fullName(
                (string) Crypto::decrypt($r['first_name_enc'], 'patients.first_name'),
                Crypto::decrypt($r['middle_name_enc'], 'patients.middle_name'),
                (string) Crypto::decrypt($r['last_name_enc'], 'patients.last_name')
            ),
            'gender' => $r['gender'],
            'age' => Format::ageOn($dob, Format::localToday()),
        ];
    }

    public static function identityIndex(string $first, string $last, string $dateOfBirth): string
    {
        return Crypto::blindIndex(Crypto::normalize($first) . '|' . Crypto::normalize($last) . '|' . $dateOfBirth, 'patients.identity');
    }

    /** Lower-cased words of two or more letters/digits, truncated to the longest indexed prefix. */
    public static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn (string $w) => mb_strlen($w) >= 2);
        return array_values(array_unique(array_map(static fn (string $w) => mb_substr($w, 0, self::MAX_PREFIX), $words)));
    }
}
