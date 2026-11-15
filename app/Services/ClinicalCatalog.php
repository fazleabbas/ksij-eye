<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Fixed clinical reference data and lookups against the administrator-managed catalogues. */
final class ClinicalCatalog
{
    /** Ophthalmology examination sections, in examination order. Findings per section live in exam_findings. */
    public const EXAM_SECTIONS = [
        'face' => 'Face & Head',
        'eyelids' => 'Eyelids',
        'conjunctiva' => 'Conjunctiva',
        'sclera' => 'Sclera',
        'cornea' => 'Cornea',
        'anterior_chamber' => 'Anterior Chamber',
        'pupil' => 'Pupil',
        'iris' => 'Iris',
        'lens' => 'Lens',
        'vitreous' => 'Vitreous',
        'ocular_movements' => 'Ocular Movements',
        'optic_disc' => 'Optic Disc',
        'macula' => 'Macula',
        'retinal_vessels' => 'Retinal Vessels',
        'peripheral_retina' => 'Peripheral Retina',
    ];

    public const DIAGRAM_COLORS = ['#EF4444', '#0D9488', '#3B82F6', '#F59E0B', '#1E293B'];

    private const ID_TABLES = ['icd10_codes', 'drugs', 'exam_findings', 'price_items'];

    /** Active values of a dropdown list. */
    public static function lookupValues(string $list): array
    {
        return array_column(Database::all(
            'SELECT value FROM lookup_values WHERE list_code = :l AND is_active = 1 ORDER BY sort_order, label',
            ['l' => $list]
        ), 'value');
    }

    /**
     * Which of $ids exist and are active in a catalogue table.
     * $condition is a fixed SQL fragment chosen by the caller (never user input).
     */
    public static function activeIds(string $table, array $ids, string $condition = ''): array
    {
        if (!in_array($table, self::ID_TABLES, true) || !$ids) {
            return [];
        }
        [$in, $params] = Database::in('id', array_values(array_unique($ids)));
        return array_map('intval', array_column(Database::all(
            "SELECT id FROM $table WHERE is_active = 1 AND id IN ($in)" . ($condition !== '' ? " AND $condition" : ''),
            $params
        ), 'id'));
    }
}
