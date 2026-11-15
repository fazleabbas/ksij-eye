<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Settings;
use App\Core\Validator;

/** System rules editable by administrators. Only known keys within safe ranges are accepted. */
final class SettingsController
{
    private const RULES = [
        'security.session_idle_minutes'    => [5, 240],
        'security.session_warning_seconds' => [30, 600],
        'security.session_absolute_hours'  => [1, 24],
        'security.login_max_attempts'      => [3, 20],
        'security.lockout_minutes'         => [1, 1440],
        'security.password_min_length'     => [8, 64],
        'visits.revisit_exemption_days'    => [0, 90],
        'clinical.record_edit_hours'       => [0, 72],
    ];

    public function index(): array
    {
        // Settings with their own screens and rules (patient numbering) are not listed here.
        [$in, $params] = Database::in('k', array_keys(self::RULES));
        $rows = Database::all(
            "SELECT s.setting_key, s.setting_value, s.value_type, s.description, s.updated_at,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS updated_by_name
               FROM settings s LEFT JOIN users u ON u.id = s.updated_by
              WHERE s.setting_key IN ($in)
              ORDER BY s.setting_key",
            $params
        );
        return ['settings' => array_map(static fn (array $r) => [
            'key' => $r['setting_key'],
            'value' => $r['value_type'] === 'int' ? (int) $r['setting_value'] : $r['setting_value'],
            'type' => $r['value_type'],
            'description' => $r['description'],
            'min' => self::RULES[$r['setting_key']][0] ?? null,
            'max' => self::RULES[$r['setting_key']][1] ?? null,
            'updated_at' => Format::utc($r['updated_at']),
            'updated_by' => $r['updated_by_name'] ?: null,
        ], $rows)];
    }

    /**
     * Patient numbering: the paper range (numbers kept by patients who have a paper file) and the
     * next number the system gives a new patient, which is always above it.
     */
    public function registrationNumber(): array
    {
        $paperMax = Settings::int('patients.paper_max', 0);
        $last = (int) (Database::value('SELECT last_value FROM sequences WHERE seq_key = :k', ['k' => 'patient_reg']) ?? 0);
        $highest = (int) (Database::value('SELECT MAX(CAST(reg_no AS UNSIGNED)) FROM patients WHERE paper_file = 0') ?? 0);
        $next = max($last, $highest, $paperMax) + 1;
        return [
            'next_number' => $next,
            'next_reg_no' => (string) $next,
            'highest_used' => $highest,
            'paper_max' => $paperMax,
            'highest_paper_used' => (int) (Database::value('SELECT MAX(CAST(reg_no AS UNSIGNED)) FROM patients WHERE paper_file = 1') ?? 0),
            'patients_registered' => (int) Database::value('SELECT COUNT(*) FROM patients'),
            'paper_patients' => (int) Database::value('SELECT COUNT(*) FROM patients WHERE paper_file = 1'),
        ];
    }

    /** The administrator chooses where numbering continues; it can never go back over numbers already used. */
    public function updateRegistrationNumber(Request $request): array
    {
        $v = Validator::make($request->all())->int('next_number', true, 1, 99999999)->validate();
        $current = $this->registrationNumber();
        if ($v['next_number'] <= $current['highest_used']) {
            throw HttpException::validation(['next_number' => "Must be higher than the last number already used ({$current['highest_used']})."]);
        }
        if ($v['next_number'] <= $current['paper_max']) {
            throw HttpException::validation(['next_number' => "Must be higher than the highest paper file number ({$current['paper_max']})."]);
        }
        Database::execute(
            'INSERT INTO sequences (seq_key, last_value, updated_at) VALUES (:k, :v1, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE last_value = :v2, updated_at = UTC_TIMESTAMP(3)',
            ['k' => 'patient_reg', 'v1' => $v['next_number'] - 1, 'v2' => $v['next_number'] - 1]
        );
        $updated = $this->registrationNumber();
        Audit::log('settings', 'registration_number.set', "Next patient registration number set to {$updated['next_reg_no']}", 'settings', 'patient_reg',
            ['next_reg_no' => ['from' => $current['next_reg_no'], 'to' => $updated['next_reg_no']]]);
        return $updated;
    }

    /**
     * The highest file number used on paper. It cannot cover a number the system has already given
     * a new patient, nor fall below a paper number already registered. New numbers start above it.
     */
    public function updatePaperMax(Request $request): array
    {
        $v = Validator::make($request->all())->int('paper_max', true, 1, 99999999)->validate();
        $current = $this->registrationNumber();
        $clash = Database::value('SELECT MIN(CAST(reg_no AS UNSIGNED)) FROM patients WHERE paper_file = 0 AND CAST(reg_no AS UNSIGNED) <= :m', ['m' => $v['paper_max']]);
        if ($clash !== null) {
            throw HttpException::validation(['paper_max' => "The system has already given number $clash to a new patient, so the paper range cannot reach it."]);
        }
        if ($v['paper_max'] < $current['highest_paper_used']) {
            throw HttpException::validation(['paper_max' => "Paper file {$current['highest_paper_used']} is already registered, so the range must reach at least that."]);
        }
        Database::execute(
            "UPDATE settings SET setting_value = :v, updated_at = UTC_TIMESTAMP(3), updated_by = :by WHERE setting_key = 'patients.paper_max'",
            ['v' => (string) $v['paper_max'], 'by' => Auth::require()->userId]
        );
        Settings::flush();
        $updated = $this->registrationNumber();
        Audit::log('settings', 'paper_max.set', "Highest paper file number set to {$v['paper_max']}; new patients start at {$updated['next_reg_no']}", 'settings', 'patients.paper_max',
            ['paper_max' => ['from' => $current['paper_max'], 'to' => $v['paper_max']]]);
        return $updated;
    }

    public function update(Request $request): array
    {
        $input = $request->all()['values'] ?? null;
        if (!is_array($input) || !$input) {
            throw HttpException::validation(['values' => 'Nothing to update.']);
        }
        $validator = Validator::make($input);
        foreach (array_keys($input) as $key) {
            if (!isset(self::RULES[$key])) {
                throw HttpException::validation([(string) $key => 'Unknown setting.']);
            }
            $validator->int($key, true, ...self::RULES[$key]);
        }
        $clean = $validator->validate();

        $current = Settings::all();
        $idleSeconds = ($clean['security.session_idle_minutes'] ?? (int) ($current['security.session_idle_minutes'] ?? 30)) * 60;
        $warning = $clean['security.session_warning_seconds'] ?? (int) ($current['security.session_warning_seconds'] ?? 120);
        if ($warning >= $idleSeconds) {
            throw HttpException::validation(['security.session_warning_seconds' => 'The warning must appear before the session times out.']);
        }

        $ctx = Auth::require();
        $changes = [];
        foreach ($clean as $key => $value) {
            $old = $current[$key] ?? null;
            if ((string) $old === (string) $value) {
                continue;
            }
            Database::execute(
                'UPDATE settings SET setting_value = :v, updated_at = UTC_TIMESTAMP(3), updated_by = :by WHERE setting_key = :k',
                ['v' => (string) $value, 'by' => $ctx->userId, 'k' => $key]
            );
            $changes[$key] = ['from' => $old === null ? null : (int) $old, 'to' => $value];
        }
        Settings::flush();
        if ($changes) {
            Audit::log('settings', 'settings.updated', 'Updated system settings: ' . implode(', ', array_keys($changes)), 'settings', null, $changes);
        }
        return $this->index();
    }
}
