<?php
/**
 * Clinical catalogues taken from the prototype: ICD-10 eye codes, examination findings and the drug list.
 * Each catalogue is seeded only while it is empty, so administrators' edits are never overwritten
 * (a renamed entry would otherwise come back under its old name). The example drug list goes only
 * into a brand-new install: a clinic that cleared it for its own medicines keeps it cleared.
 */
declare(strict_types=1);

return static function (PDO $pdo, bool $fresh = true): void {
    $isEmpty = static fn (string $table): bool => (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 0;

    // ── ICD-10 codes (code, description, category) ────────────────────────
    if ($isEmpty('icd10_codes')) {
        $codes = [
            ['H10.0', 'Mucopurulent conjunctivitis', 'Conjunctiva'],
            ['H10.1', 'Acute atopic conjunctivitis', 'Conjunctiva'],
            ['H10.3', 'Acute conjunctivitis, unspecified', 'Conjunctiva'],
            ['H10.4', 'Chronic conjunctivitis', 'Conjunctiva'],
            ['H10.9', 'Conjunctivitis, unspecified', 'Conjunctiva'],
            ['H04.129', 'Dry eye syndrome, unspecified eye', 'Lacrimal system'],
            ['H52.0', 'Hypermetropia', 'Refractive error / accommodation'],
            ['H52.1', 'Myopia', 'Refractive error / accommodation'],
            ['H52.2', 'Astigmatism', 'Refractive error / accommodation'],
            ['H52.4', 'Presbyopia', 'Refractive error / accommodation'],
            ['H52.5', 'Disorders of accommodation', 'Refractive error / accommodation'],
            ['H25.9', 'Age-related cataract, unspecified', 'Cataract'],
            ['H26.0', 'Infantile and juvenile cataract', 'Cataract'],
            ['H26.9', 'Cataract, unspecified', 'Cataract'],
            ['H40.1', 'Open-angle glaucoma', 'Glaucoma'],
            ['H40.2', 'Primary angle-closure glaucoma', 'Glaucoma'],
            ['H40.9', 'Glaucoma, unspecified', 'Glaucoma'],
            ['H11.0', 'Pterygium', 'Cornea / external eye'],
            ['H16.9', 'Keratitis, unspecified', 'Cornea / external eye'],
            ['H17.9', 'Corneal scar and opacity, unspecified', 'Cornea / external eye'],
            ['H18.9', 'Disorder of cornea, unspecified', 'Cornea / external eye'],
            ['H20.9', 'Iridocyclitis, unspecified', 'Uvea'],
            ['H33.2', 'Serous retinal detachment', 'Retina / posterior segment'],
            ['H35.1', 'Retinopathy of prematurity', 'Retina / posterior segment'],
            ['H35.3', 'Degeneration of macula and posterior pole', 'Retina / posterior segment'],
            ['H35.9', 'Retinal disorder, unspecified', 'Retina / posterior segment'],
            ['H43.9', 'Disorder of vitreous body, unspecified', 'Vitreous'],
            ['H46.9', 'Optic neuritis, unspecified', 'Optic nerve'],
            ['H02.0', 'Entropion and trichiasis of eyelid', 'Eyelid / orbit'],
            ['H02.1', 'Ectropion of eyelid', 'Eyelid / orbit'],
            ['H02.4', 'Ptosis of eyelid', 'Eyelid / orbit'],
            ['H50.0', 'Convergent concomitant strabismus', 'Strabismus / binocular'],
            ['H50.1', 'Divergent concomitant strabismus', 'Strabismus / binocular'],
            ['H53.2', 'Diplopia', 'Strabismus / binocular'],
            ['H57.9', 'Disorder of eye and adnexa, unspecified', 'Other'],
        ];
        $stmt = $pdo->prepare('INSERT INTO icd10_codes (code, description, category, sort_order, created_at) VALUES (:c, :d, :cat, :s, UTC_TIMESTAMP(3))');
        foreach ($codes as $i => [$code, $description, $category]) {
            $stmt->execute(['c' => $code, 'd' => $description, 'cat' => $category, 's' => ($i + 1) * 10]);
        }
    }

    // ── Examination findings per section ─────────────────────────────────
    if ($isEmpty('exam_findings')) {
        $sections = [
            'face' => ['Normal', 'Facial asymmetry', 'Proptosis', 'Enophthalmos', 'Ptosis', 'Lid lag', 'Lagophthalmos', 'Facial nerve palsy', 'Swelling'],
            'eyelids' => ['Normal', 'Entropion', 'Ectropion', 'Trichiasis', 'Blepharitis', 'Chalazion', 'Hordeolum', 'Meibomian gland dysfunction', 'Dermatochalasis', 'Lash loss'],
            'conjunctiva' => ['Normal', 'Conjunctivitis', 'Chemosis', 'Subconjunctival haemorrhage', 'Pinguecula', 'Pterygium', 'Papillae', 'Follicles', 'Discharge', 'Pallor'],
            'sclera' => ['Normal', 'Episcleritis', 'Scleritis', 'Scleral thinning', 'Blue sclera'],
            'cornea' => ['Normal', 'Corneal oedema', 'Ulcer', 'Opacity', 'Keratoconus', 'Arcus', 'Pannus', 'Foreign body', 'Staining', 'Dystrophy'],
            'anterior_chamber' => ['Normal', 'Deep', 'Shallow', 'Hyphaema', 'Hypopyon', 'Cells & flare'],
            'iris' => ['Normal', 'Iritis', 'Rubeosis', 'Synechiae', 'Coloboma', 'Heterochromia', 'Atrophy', 'Neovascularisation'],
            'lens' => ['Normal', 'Clear', 'Nuclear cataract', 'Cortical cataract', 'Posterior subcapsular', 'Mature cataract', 'Pseudophakic', 'Aphakic', 'Subluxation'],
            'vitreous' => ['Normal', 'Clear', 'Haemorrhage', 'Floaters', 'Detachment', 'Cells', 'Degeneration'],
            'ocular_movements' => ['Normal', 'Full range', 'Limitation – abduction', 'Limitation – adduction', 'Limitation – elevation', 'Limitation – depression', 'Nystagmus', 'Diplopia'],
            'optic_disc' => ['Normal', 'Pink & healthy', 'Pale', 'Cupped (C:D > 0.6)', 'Swollen / papilloedema', 'Drusen', 'Glaucomatous cupping', 'Haemorrhage'],
            'macula' => ['Normal', 'Normal reflex', 'Oedema', 'Scar', 'ARMD', 'Cherry red spot', 'Epiretinal membrane', 'Macular hole'],
            'retinal_vessels' => ['Normal', 'Normal calibre', 'A/V nipping', 'Flame haemorrhages', 'Dot & blot haemorrhages', 'Exudates', 'Neovascularisation', 'Cotton wool spots', 'Tortuous vessels'],
            'peripheral_retina' => ['Normal', 'Lattice degeneration', 'Retinal tear', 'Retinal detachment', 'Retinoschisis', 'Pigmentary changes'],
        ];
        $normal = ['Normal', 'Clear', 'Deep', 'Full range', 'Pink & healthy', 'Normal reflex', 'Normal calibre'];
        $stmt = $pdo->prepare('INSERT INTO exam_findings (section, label, is_normal, sort_order, created_at) VALUES (:s, :l, :n, :o, UTC_TIMESTAMP(3))');
        foreach ($sections as $section => $labels) {
            foreach ($labels as $i => $label) {
                $stmt->execute(['s' => $section, 'l' => $label, 'n' => in_array($label, $normal, true) ? 1 : 0, 'o' => ($i + 1) * 10]);
            }
        }
    }

    // ── Drug catalogue (name, category, default dose, frequency, duration) ─
    if ($fresh && $isEmpty('drugs')) {
        $drugs = [
            ['Timolol 0.5% Eye Drops', 'Glaucoma', '1 Drop', 'BD', '1 Month'],
            ['Latanoprost 0.005% Eye Drops', 'Glaucoma', '1 Drop', 'OD', '1 Month'],
            ['Bimatoprost 0.03% Eye Drops', 'Glaucoma', '1 Drop', 'OD', '1 Month'],
            ['Dorzolamide 2% Eye Drops', 'Glaucoma', '1 Drop', 'TDS', '1 Month'],
            ['Brimonidine 0.2% Eye Drops', 'Glaucoma', '1 Drop', 'BD', '1 Month'],
            ['Prednisolone 1% Eye Drops', 'Anti-inflammatory', '1 Drop', 'QID', '2 Weeks'],
            ['Dexamethasone 0.1% Eye Drops', 'Anti-inflammatory', '1 Drop', 'QID', '2 Weeks'],
            ['Fluorometholone 0.1% Eye Drops', 'Anti-inflammatory', '1 Drop', 'QID', '4 Weeks'],
            ['Ketorolac 0.5% Eye Drops', 'Anti-inflammatory', '1 Drop', 'QID', '2 Weeks'],
            ['Chloramphenicol 0.5% Eye Drops', 'Antibiotic', '1 Drop', 'QID', '5 Days'],
            ['Ciprofloxacin 0.3% Eye Drops', 'Antibiotic', '1 Drop', 'QID', '7 Days'],
            ['Moxifloxacin 0.5% Eye Drops', 'Antibiotic', '1 Drop', 'QID', '7 Days'],
            ['Ofloxacin 0.3% Eye Drops', 'Antibiotic', '1 Drop', 'QID', '7 Days'],
            ['Gentamicin 0.3% Eye Drops', 'Antibiotic', '1 Drop', 'QID', '7 Days'],
            ['Acyclovir 3% Eye Ointment', 'Antiviral', 'Thin strip', '5x/day', '10 Days'],
            ['Natamycin 5% Eye Drops', 'Antifungal', '1 Drop', 'Hourly', '3 Weeks'],
            ['Voriconazole 1% Eye Drops', 'Antifungal', '1 Drop', 'Hourly', '3 Weeks'],
            ['Atropine 1% Eye Drops', 'Mydriatic', '1 Drop', 'BD', '1 Week'],
            ['Cyclopentolate 1% Eye Drops', 'Mydriatic', '1 Drop', 'BD', 'As directed'],
            ['Tropicamide 1% Eye Drops', 'Mydriatic', '1 Drop', 'BD', 'As directed'],
            ['Homatropine 2% Eye Drops', 'Mydriatic', '1 Drop', 'BD', 'As directed'],
            ['Pilocarpine 2% Eye Drops', 'Miotic', '1 Drop', 'QID', 'As directed'],
            ['Hypromellose 0.5% Eye Drops', 'Lubricant', '1-2 Drops', 'PRN', 'Ongoing'],
            ['Carmellose 0.5% Eye Drops', 'Lubricant', '1-2 Drops', 'PRN', 'Ongoing'],
            ['Sodium Hyaluronate 0.1% Eye Drops', 'Lubricant', '1 Drop', 'PRN', 'Ongoing'],
            ['Dextran 0.1% / Hypromellose Eye Drops', 'Lubricant', '1-2 Drops', 'PRN', 'Ongoing'],
            ['Olopatadine 0.1% Eye Drops', 'Antihistamine', '1 Drop', 'BD', '2 Weeks'],
            ['Ketotifen 0.025% Eye Drops', 'Antihistamine', '1 Drop', 'BD', '2 Weeks'],
            ['Sodium Cromoglicate 2% Eye Drops', 'Antihistamine', '1-2 Drops', 'QID', '4 Weeks'],
            ['Acetazolamide 250mg Tablets', 'Glaucoma (Systemic)', '250mg', 'BD', 'As directed'],
            ['Acetazolamide 500mg Tablets', 'Glaucoma (Systemic)', '500mg', 'OD', 'As directed'],
            ['Prednisolone 5mg Tablets', 'Systemic Steroid', '5mg', 'OD', 'As directed'],
            ['Prednisolone 10mg Tablets', 'Systemic Steroid', '10mg', 'OD', 'As directed'],
            ['Amoxicillin 500mg Capsules', 'Systemic Antibiotic', '500mg', 'TDS', '7 Days'],
            ['Doxycycline 100mg Capsules', 'Systemic Antibiotic', '100mg', 'OD', '7 Days'],
            ['Acyclovir 400mg Tablets', 'Systemic Antiviral', '400mg', '5x/day', '10 Days'],
            ['Ibuprofen 400mg Tablets', 'Analgesic/NSAID', '400mg', 'TDS', '5 Days'],
            ['Paracetamol 500mg Tablets', 'Analgesic', '1g', 'QID', 'As needed'],
            ['Vitamin A 200,000 IU Capsules', 'Vitamins', '200,000 IU', 'OD', 'As directed'],
            ['Vitamin C 500mg Tablets', 'Vitamins', '500mg', 'OD', '1 Month'],
            ['Omega-3 Fatty Acid Capsules', 'Vitamins', '1 Capsule', 'OD', '3 Months'],
            ['Chloramphenicol 1% Eye Ointment', 'Antibiotic', 'Thin strip', 'TDS', '7 Days'],
            ['Betamethasone + Chloramphenicol Eye Drops', 'Antibiotic + Steroid', '1 Drop', 'QID', '5 Days'],
            ['Tobramycin + Dexamethasone Eye Drops', 'Antibiotic + Steroid', '1 Drop', 'QID', '7 Days'],
            ['Brinzolamide 1% Eye Drops', 'Glaucoma', '1 Drop', 'BD', '1 Month'],
            ['Travoprost 0.004% Eye Drops', 'Glaucoma', '1 Drop', 'OD', '1 Month'],
            ['Tafluprost 0.0015% Eye Drops', 'Glaucoma', '1 Drop', 'OD', '1 Month'],
            ['Povidone-Iodine 5% Solution', 'Antiseptic', 'As directed', 'As directed', 'As directed'],
            ['Fluorescein Strips', 'Diagnostic', '1 Strip', 'PRN', 'Single use'],
            ['Rose Bengal Strips', 'Diagnostic', '1 Strip', 'PRN', 'Single use'],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO drugs (name, category, dosage_form, default_dose, default_frequency, default_duration, created_at)
             VALUES (:n, :c, :f, :d, :q, :u, UTC_TIMESTAMP(3))'
        );
        foreach ($drugs as [$name, $category, $dose, $frequency, $duration]) {
            $form = preg_match('/(Eye Drops|Eye Ointment|Tablets|Capsules|Solution|Strips)$/', $name, $m) ? $m[1] : null;
            $stmt->execute(['n' => $name, 'c' => $category, 'f' => $form, 'd' => $dose, 'q' => $frequency, 'u' => $duration]);
        }
    }
};
