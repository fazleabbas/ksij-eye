<?php
declare(strict_types=1);

/**
 * The clinic's operation templates (notes, post-op medicines and instructions, first review day).
 * A template is only added when its surgery or procedure has none yet, so an administrator's
 * edits are never overwritten. Medicines are matched to the pharmacy catalogue by name; one that
 * cannot be found is left out for the administrator to add in Settings → Clinical Lists.
 */
return static function (PDO $pdo): void {
    $templates = [
        [
            'codes' => ['SURG-CATARACT-MSICS'], 'names' => ['%cataract%'],
            'notes' => 'Under aseptic technique and adequate anaesthesia, the eye was prepped and draped in the usual sterile fashion. '
                . 'A superior scleral tunnel incision with side port was made, followed by capsulorrhexis, hydrodissection, and nucleus delivery done. '
                . 'Cortical washout was performed with Simcoe irrigation/aspiration, and a PCIOL was implanted in the capsular bag. '
                . 'Residual viscoelastic was removed, the anterior chamber reformed, and the wound found watertight. '
                . 'Subconjunctival antibiotic and steroid injections were given, and the eye was padded and shielded.',
            'instructions' => null,
            'review_days' => 1,
            'medicines' => [
                ['acetazolamide', '250mg', 'STAT', 'Once', 1],
                ['paracetamol', '1g', 'TDS', '3 days', 9],
            ],
        ],
        [
            'codes' => ['PROC-BTRP'], 'names' => ['%btrp%'],
            'notes' => "Under aseptic conditions, retrobulbar anaesthesia with 5mls Lignocaine 2% was administered. Patient prepped and draped. "
                . "Lid speculum applied. Surgical separation of lids done. Hemostasis achieved with gentle pressure.\n\n"
                . "Lid margin was aligned anatomically from distal to proximal. Tarsal plate approximated with 4/0 absorbable suture. "
                . "Lid margin closed with vertical interrupted suture to ensure precise alignment of grey line and lash line. "
                . "Skin closed with 4/0 absorbable interrupted sutures.\n\n"
                . "Wound cleaned. Tetracycline eye ointment instilled. Sterile dry gauze pad and bandage applied.",
            'instructions' => 'Review Day 1 for wound check, Day 9 for suture assessment.',
            'review_days' => 1,
            'medicines' => [
                ['paracetamol', '500mg', 'TDS', '3 days', 9],
                ['tetracycline', 'Apply', 'BD', 'As directed', 1],
            ],
        ],
        [
            'codes' => ['SURG-EVISCERATION'], 'names' => ['%eviscer%'],
            'notes' => "Under aseptic technique and local anesthesia, the eye was prepped and draped in the sterile fashion. "
                . "360-degree conjunctival peritomy was performed at the limbus. Keratectomy was done to remove the cornea. "
                . "All intraocular contents (uveal tissue, retina, lens, and vitreous) were evacuated using an evisceration spoon. "
                . "The internal scleral shell was thoroughly scraped and scrubbed with saline-soaked gauze to ensure complete removal of any residual pigment or uveal tissue.\n\n"
                . "The anterior scleral flaps were overlapped horizontally and closed with interrupted 4-0 Vicryl sutures followed by conjunctiva. "
                . "Topical antibiotic ointment was instilled. Subconjunctival antibiotic and steroid injections were administered, "
                . "and a firm pressure bandage with eye pad was applied.",
            'instructions' => 'Keep pressure bandage intact for 48 hours.',
            'review_days' => 2,
            'medicines' => [
                ['paracetamol', '1g', 'TDS', '3 days', 9],
            ],
        ],
        [
            'codes' => ['SURG-PTERYGIUM'], 'names' => ['%pterygium%'],
            'notes' => 'Pterygium excision plus MMC. Under sterile conditions, a lid speculum was inserted. The pterygium head and body were dissected '
                . 'and excised from the cornea and sclera. Residual fibrovascular tissue was removed and the corneal surface smoothened. '
                . 'Hemostasis achieved with minimal cautery. MMC was applied to the bare sclera and irrigated thoroughly with BSS. '
                . 'Primary conjunctival closure was performed using 9-0 sutures with good wound apposition. '
                . 'Subconjunctival medications administered and eye padded.',
            'instructions' => null,
            'review_days' => 1,
            'medicines' => [
                ['paracetamol', '1g', 'TDS', '3 days', 9],
            ],
        ],
        [
            'codes' => ['PROC-AVASTIN'], 'names' => ['%avastin%'],
            'notes' => 'Under sterile conditions, topical anaesthesia and povidone-iodine were applied. An intravitreal injection of Avastin 0.1 mL '
                . 'was administered through the pars plana inferotemporally 3.5mm from limbus. Topical antibiotic applied and eye padded.',
            'instructions' => 'Remove eye pad 6 hours after the injection.',
            'review_days' => 1,
            'medicines' => [
                ['paracetamol', '1g', 'TDS', '1 day', 3],
                ['moxifloxacin', '1 drop', '2 hourly', '1 week', 1],
            ],
        ],
    ];

    $findItem = static function (array $codes, array $names) use ($pdo): ?int {
        foreach ($codes as $code) {
            $id = $pdo->prepare('SELECT id FROM price_items WHERE code = :c');
            $id->execute(['c' => $code]);
            if (($found = $id->fetchColumn()) !== false) {
                return (int) $found;
            }
        }
        foreach ($names as $name) {
            $id = $pdo->prepare("SELECT id FROM price_items WHERE category IN ('surgery', 'procedure') AND is_active = 1 AND LOWER(name) LIKE :n ORDER BY id LIMIT 1");
            $id->execute(['n' => $name]);
            if (($found = $id->fetchColumn()) !== false) {
                return (int) $found;
            }
        }
        return null;
    };
    // The active catalogue medicine whose name contains the word; with a strength given, one with it first.
    $findDrug = static function (string $word, string $dose) use ($pdo): ?array {
        $stmt = $pdo->prepare('SELECT id, name FROM drugs WHERE is_active = 1 AND LOWER(name) LIKE :n ORDER BY id');
        $stmt->execute(['n' => '%' . $word . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        $strength = strtolower(str_replace(' ', '', $dose));
        foreach ($rows as $row) {
            if ($strength !== '' && str_contains(strtolower(str_replace(' ', '', $row['name'])), $strength)) {
                return $row;
            }
        }
        return $rows[0];
    };

    $exists = $pdo->prepare('SELECT 1 FROM operation_templates WHERE price_item_id = :i');
    $insert = $pdo->prepare(
        'INSERT INTO operation_templates (price_item_id, operation_notes, postop_instructions, review_days, created_at)
         VALUES (:i, :n, :p, :r, UTC_TIMESTAMP(3))'
    );
    $insertMedicine = $pdo->prepare(
        'INSERT IGNORE INTO operation_template_medicines (template_id, drug_id, quantity, dose, frequency, duration, sort_order)
         VALUES (:t, :d, :q, :dose, :f, :du, :o)'
    );
    foreach ($templates as $template) {
        $itemId = $findItem($template['codes'], $template['names']);
        if ($itemId === null) {
            continue;
        }
        $exists->execute(['i' => $itemId]);
        if ($exists->fetchColumn() !== false) {
            continue;
        }
        $insert->execute(['i' => $itemId, 'n' => $template['notes'], 'p' => $template['instructions'], 'r' => $template['review_days']]);
        $templateId = (int) $pdo->lastInsertId();
        foreach ($template['medicines'] as $i => [$word, $dose, $frequency, $duration, $quantity]) {
            $drug = $findDrug($word, $dose);
            if ($drug === null) {
                continue;
            }
            // 1g taken as 500mg tablets is two tablets a dose.
            if ($dose === '1g' && preg_match('/500\s*mg/i', $drug['name'])) {
                $quantity *= 2;
            }
            $insertMedicine->execute([
                't' => $templateId, 'd' => (int) $drug['id'], 'q' => $quantity, 'dose' => $dose,
                'f' => $frequency, 'du' => $duration, 'o' => $i,
            ]);
        }
    }
};
