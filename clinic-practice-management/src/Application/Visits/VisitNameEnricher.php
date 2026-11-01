<?php
declare(strict_types=1);

namespace ClinicCore\Application\Visits;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * تکمیل نام‌های نمایشی (patient_name / clinician_name) برای ویزیت‌ها.
 *
 * پرس‌وجوهای جداگانه و غیر قفل (NON-locking)، همیشه clinic_scoped (tenancy).
 * هرگز نامی از کلینیک دیگری برگردانده نمی‌شود؛ اگر بیمار یا پزشک در همان
 * کلینیک نباشد، نام خالی/null (fail closed). دسته‌ای grouped by clinic_id با
 * chunk 500 برای جملات IN.
 */
final class VisitNameEnricher
{
    public function __construct(
        private readonly CpmsDb $db,
    ) {
    }

    public function enrich_visit_names(array $visit): array
    {
        if (!empty($visit['_names_enriched'])) {
            return $visit;
        }

        $clinic_id = $visit['clinic_id'] ?? 0;
        $clinic_id = (int) $clinic_id;
        if ($clinic_id <= 0) {
            return $visit;
        }

        $patient_id = $visit['patient_id'] ?? 0;
        $patient_id = (int) $patient_id;
        if ($patient_id > 0 && !isset($visit['patient_first_name'])) {
            $patient = $this->db->fetchRow(
                'SELECT first_name, last_name FROM ' . $this->db->table('cpms_patients') .
                ' WHERE id = %d AND clinic_id = %d LIMIT 1',
                [$patient_id, $clinic_id]
            );
            if ($patient !== null) {
                $visit['patient_first_name'] = (string) ($patient['first_name'] ?? '');
                $visit['patient_last_name'] = (string) ($patient['last_name'] ?? '');
            } else {
                $visit['patient_first_name'] = '';
                $visit['patient_last_name'] = '';
            }
        }

        $clinician_id = $visit['clinician_id'] ?? 0;
        $clinician_id = (int) $clinician_id;
        if ($clinician_id > 0 && !isset($visit['clinician_name'])) {
            $clinician = $this->db->fetchRow(
                'SELECT full_name FROM ' . $this->db->table('cpms_clinicians') .
                ' WHERE id = %d AND clinic_id = %d LIMIT 1',
                [$clinician_id, $clinic_id]
            );
            if ($clinician !== null) {
                $visit['clinician_name'] = (string) ($clinician['full_name'] ?? '');
            } else {
                $visit['clinician_name'] = null;
            }
        }

        return $visit;
    }

    public function enrich_visit_names_batch(array $visits): array
    {
        $by_clinic = [];
        foreach ($visits as $index => $visit) {
            $clinic_id = $visit['clinic_id'] ?? 0;
            $clinic_id = (int) $clinic_id;
            if ($clinic_id <= 0) {
                $visits[$index]['_names_enriched'] = true;
                continue;
            }
            $by_clinic[$clinic_id][] = $index;
        }

        foreach ($by_clinic as $clinic_id => $indexes) {
            $patient_ids = [];
            $clinician_ids = [];
            foreach ($indexes as $index) {
                $visit_patient_id = $visits[$index]['patient_id'] ?? 0;
                if ((int) $visit_patient_id > 0 && !isset($visits[$index]['patient_first_name'])) {
                    $patient_ids[] = (int) $visit_patient_id;
                }
                $visit_clinician_id = $visits[$index]['clinician_id'] ?? 0;
                if ((int) $visit_clinician_id > 0 && !isset($visits[$index]['clinician_name'])) {
                    $clinician_ids[] = (int) $visit_clinician_id;
                }
            }

            $patient_map = [];
            if (!empty($patient_ids)) {
                $patient_ids = array_unique($patient_ids);
                foreach (array_chunk($patient_ids, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                    $rows = $this->db->fetchAll(
                        'SELECT id, first_name, last_name FROM ' . $this->db->table('cpms_patients') .
                        ' WHERE clinic_id = %d AND id IN (' . $placeholders . ')',
                        array_merge([$clinic_id], $chunk)
                    );
                    foreach ((array) $rows as $row) {
                        $patient_map[(int) $row['id']] = [
                            'first_name' => (string) ($row['first_name'] ?? ''),
                            'last_name' => (string) ($row['last_name'] ?? ''),
                        ];
                    }
                }
            }

            $clinician_map = [];
            if (!empty($clinician_ids)) {
                $clinician_ids = array_unique($clinician_ids);
                foreach (array_chunk($clinician_ids, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                    $rows = $this->db->fetchAll(
                        'SELECT id, full_name FROM ' . $this->db->table('cpms_clinicians') .
                        ' WHERE clinic_id = %d AND id IN (' . $placeholders . ')',
                        array_merge([$clinic_id], $chunk)
                    );
                    foreach ((array) $rows as $row) {
                        $clinician_map[(int) $row['id']] = (string) ($row['full_name'] ?? '');
                    }
                }
            }

            foreach ($indexes as $index) {
                $visit = $visits[$index];
                $visit['_names_enriched'] = true;
                $visit_patient_id = $visit['patient_id'] ?? 0;
                if ((int) $visit_patient_id > 0 && !isset($visit['patient_first_name'])) {
                    $patient = $patient_map[(int) $visit_patient_id] ?? null;
                    if ($patient !== null) {
                        $visit['patient_first_name'] = $patient['first_name'];
                        $visit['patient_last_name'] = $patient['last_name'];
                    } else {
                        $visit['patient_first_name'] = '';
                        $visit['patient_last_name'] = '';
                    }
                }
                $visit_clinician_id = $visit['clinician_id'] ?? 0;
                if ((int) $visit_clinician_id > 0 && !isset($visit['clinician_name'])) {
                    $visit['clinician_name'] = $clinician_map[(int) $visit_clinician_id] ?? null;
                }
                $visits[$index] = $visit;
            }
        }

        return $visits;
    }
}
