<?php
/**
 * Table-level privileges for the runtime DB account (ksi_eye_app). Applied by
 * `php bin/install.php grant`, which first revokes everything, so this file is the
 * complete list. Append-only tables get INSERT + SELECT only — never UPDATE or DELETE.
 */
declare(strict_types=1);

return [
    'departments'       => ['SELECT', 'INSERT', 'UPDATE'],
    'designations'      => ['SELECT', 'INSERT', 'UPDATE'],
    'users'             => ['SELECT', 'INSERT', 'UPDATE'],
    'roles'             => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'permissions'       => ['SELECT'],
    'role_permissions'  => ['SELECT', 'INSERT', 'DELETE'],
    'user_roles'        => ['SELECT', 'INSERT', 'DELETE'],
    'user_designations' => ['SELECT', 'INSERT', 'DELETE'],
    'user_sessions'     => ['SELECT', 'INSERT', 'UPDATE'],
    'sequences'         => ['SELECT', 'INSERT', 'UPDATE'],
    'settings'          => ['SELECT', 'UPDATE'],
    'price_items'       => ['SELECT', 'INSERT', 'UPDATE'],
    'lookup_values'     => ['SELECT', 'INSERT', 'UPDATE'],

    // Patients, visits & billing (no DELETE: records are cancelled, voided or refunded instead)
    'patients'              => ['SELECT', 'INSERT', 'UPDATE'],
    'patient_search_tokens' => ['SELECT', 'INSERT', 'DELETE'],
    'patient_files'         => ['SELECT', 'INSERT', 'UPDATE'],
    'visits'                => ['SELECT', 'INSERT', 'UPDATE'],
    'bills'                 => ['SELECT', 'INSERT', 'UPDATE'],
    'payments'              => ['SELECT', 'INSERT', 'UPDATE'],
    'refunds'               => ['SELECT', 'INSERT', 'UPDATE'],
    'approval_requests'     => ['SELECT', 'INSERT', 'UPDATE'],

    // Clinical catalogues and records
    'icd10_codes'                 => ['SELECT', 'INSERT', 'UPDATE'],
    'exam_findings'               => ['SELECT', 'INSERT', 'UPDATE'],
    'drugs'                       => ['SELECT', 'INSERT', 'UPDATE'],
    'triage_assessments'          => ['SELECT', 'INSERT', 'UPDATE'],
    'optometry_assessments'       => ['SELECT', 'INSERT', 'UPDATE'],
    'optometry_refractions'       => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'ophthalmology_consultations' => ['SELECT', 'INSERT', 'UPDATE'],
    // Child rows of an editable record are replaced as a whole on save
    'optometry_lens_items'        => ['SELECT', 'INSERT', 'DELETE'],
    'ophthalmology_findings'      => ['SELECT', 'INSERT', 'DELETE'],
    'ophthalmology_section_notes' => ['SELECT', 'INSERT', 'DELETE'],
    'clinical_diagnoses'          => ['SELECT', 'INSERT', 'DELETE'],
    'prescription_items'          => ['SELECT', 'INSERT', 'DELETE'],
    // DELETE is used only for unsubmitted drafts
    'prescriptions'               => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    // History of changes to issued prescriptions: append-only
    'prescription_amendments'     => ['SELECT', 'INSERT'],
    'follow_ups'                  => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'investigation_requests'      => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'surgery_recommendations'     => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],

    // Diagnostics
    'diagnostic_bookings'         => ['SELECT', 'INSERT', 'UPDATE'],
    'diagnostic_results'          => ['SELECT', 'INSERT', 'UPDATE'],
    'diagnostic_booking_laser_subtypes' => ['SELECT', 'INSERT', 'DELETE'],

    // Optical
    'optical_orders'              => ['SELECT', 'INSERT', 'UPDATE'],
    'optical_order_items'         => ['SELECT', 'INSERT', 'DELETE'],
    'frames'                      => ['SELECT', 'INSERT', 'UPDATE'],
    'optical_frame_stock'         => ['SELECT', 'INSERT', 'UPDATE'],

    // Pharmacy
    'pharmacy_stock'              => ['SELECT', 'INSERT', 'UPDATE'],
    'pharmacy_walkins'            => ['SELECT', 'INSERT'],
    'pharmacy_orders'             => ['SELECT', 'INSERT', 'UPDATE'],
    'pharmacy_order_items'        => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'pharmacy_dispensings'        => ['SELECT', 'INSERT'],
    'pharmacy_dispensing_items'   => ['SELECT', 'INSERT'],

    // Theatre
    'theatre_bookings'            => ['SELECT', 'INSERT', 'UPDATE'],
    'theatre_booking_procedures'  => ['SELECT', 'INSERT', 'DELETE'],
    'theatre_cataract_biometry'   => ['SELECT', 'INSERT', 'UPDATE'],
    'theatre_preop_assessments'   => ['SELECT', 'INSERT', 'UPDATE'],
    'theatre_operations'          => ['SELECT', 'INSERT', 'UPDATE'],
    'theatre_operation_team'      => ['SELECT', 'INSERT', 'DELETE'],
    'theatre_postop_visits'       => ['SELECT', 'INSERT', 'UPDATE'],

    // Stores
    'suppliers'                   => ['SELECT', 'INSERT', 'UPDATE'],
    'store_items'                 => ['SELECT', 'INSERT', 'UPDATE'],
    'store_item_batches'          => ['SELECT', 'INSERT', 'UPDATE'],
    'store_requisitions'          => ['SELECT', 'INSERT', 'UPDATE'],
    'store_requisition_items'     => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'store_issues'                => ['SELECT', 'INSERT'],
    'store_issue_items'           => ['SELECT', 'INSERT'],
    'store_quotations'            => ['SELECT', 'INSERT', 'UPDATE'],
    'store_quotation_items'       => ['SELECT', 'INSERT', 'DELETE'],
    'store_purchase_orders'       => ['SELECT', 'INSERT', 'UPDATE'],
    'store_purchase_order_items'  => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'store_goods_received'        => ['SELECT', 'INSERT', 'UPDATE'],
    'store_goods_received_items'  => ['SELECT', 'INSERT'],

    // Append-only
    'login_attempts'       => ['SELECT', 'INSERT'],
    'audit_log'            => ['SELECT', 'INSERT'],
    'record_access_log'    => ['SELECT', 'INSERT'],
    'price_history'        => ['SELECT', 'INSERT'],
    'stored_files'         => ['SELECT', 'INSERT'],
    'visit_status_history' => ['SELECT', 'INSERT'],
    'bill_items'           => ['SELECT', 'INSERT'],
    'approval_files'       => ['SELECT', 'INSERT'],
    'clinical_addenda'     => ['SELECT', 'INSERT'],
    'diagnostic_result_files' => ['SELECT', 'INSERT', 'UPDATE'],
    'pharmacy_stock_movements' => ['SELECT', 'INSERT'],
    'store_stock_movements'    => ['SELECT', 'INSERT'],
    'optical_frame_movements'  => ['SELECT', 'INSERT'],
];
