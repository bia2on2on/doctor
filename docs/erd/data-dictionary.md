# Data Dictionary + Constraints & Indexes — CPMS

نسخه 1.0 | 2026-09-05 | فاز 4 | همه زمان‌ها UTC. PK همه جداول: `id BIGINT UNSIGNED AI PK`.

راهنما: **U**=Unique, **FK**=Foreign Key, **N**=Nullable, **AI**=Auto Increment, **DF**=Default.

---

## 1. `cpms_clinics`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| name | VARCHAR(190) | | | نام شعبه |
| slug | VARCHAR(190) | | | **U** |
| timezone | VARCHAR(64) | | 'Asia/Tehran' | IANA |
| address | VARCHAR(255) | N | | |
| phone | VARCHAR(32) | N | | |
| created_at / updated_at | DATETIME(3) | | | |

## 2. `cpms_clinicians`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | **FK→clinics** |
| wp_user_id | BIGINT UNSIGNED | N | | لینک به wp_users (null تا زمانی که اکانت ساخته نشود) |
| full_name | VARCHAR(190) | | | |
| specialty | VARCHAR(190) | N | | |
| room | VARCHAR(64) | N | | اتاق ویزیت (V1: اختیاری) |
| is_active | TINYINT(1) | | 1 | |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_clinician_active (clinic_id, is_active)` |

## 3. `cpms_patients`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | **FK** |
| mrn | VARCHAR(32) | | | شماره پرونده **U(clinic_id, mrn)**؛ فرمت `P-000123` |
| first_name / last_name | VARCHAR(120) | | | |
| mobile | VARCHAR(32) | | | عادی‌شده (09xxxxxxxxx) **U(clinic_id, mobile)** |
| national_id | VARCHAR(10) | N | | **U(clinic_id, national_id)**؛ checksum در لایه اپ |
| birth_date | DATE | N | | |
| gender | ENUM('male','female','other','unknown') | | 'unknown' | |
| address | VARCHAR(255) | N | | |
| phone | VARCHAR(32) | N | | ثابت |
| emergency_contact_name | VARCHAR(190) | N | | |
| emergency_contact_phone | VARCHAR(32) | N | | |
| blood_group | VARCHAR(8) | N | | AB+ ... |
| medication_allergies | JSON | N | | لیست `{name, note}` |
| other_allergies | JSON | N | | |
| chronic_conditions | JSON | N | | |
| medical_history | TEXT | N | | سوابق مهم |
| surgery_history | TEXT | N | | |
| current_medications | JSON | N | | |
| status | ENUM('active','archived') | | 'active' | |
| archived_at / archive_reason | DATETIME(3)/VARCHAR(255) | N | | |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_pat_search (clinic_id, last_name, first_name)`, `idx_pat_mobile (clinic_id, mobile)`, `idx_pat_nid (clinic_id, national_id)` |

> PHI فیلدها فقط از طریق Repository با Permission؛ `SELECT` مستقیم در Template ممنوع (NFR-MAINT-2).

## 4. `cpms_patient_user_links`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | **FK** |
| patient_id | BIGINT UNSIGNED | | | **FK→patients RESTRICT** |
| wp_user_id | BIGINT UNSIGNED | | | **FK→wp_users** |
| mobile_at_link | VARCHAR(32) | | | snapshot شماره در لحظه اتصال |
| is_primary | TINYINT(1) | | 0 | |
| linked_at | DATETIME(3) | | | |
| **U** `(patient_id, wp_user_id)` |
| Index: `idx_link_user (wp_user_id)` — برای «بازیابی Patients مربوطه» |

## 5. `cpms_patient_merges`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id, surviving_patient_id, merged_patient_id | BIGINT UNSIGNED | | | |
| merged_by_wp_user_id | BIGINT UNSIGNED | | | |
| reason | VARCHAR(255) | | | |
| mapping_json | JSON | | | نگاشت رکوردها (کدام‌ها منتقل شدند) |
| merged_at | DATETIME(3) | | | |
> بیمار مerged به `status=archived` (نه حذف)؛ FKهای قدیمی با نگاشت خوانده می‌شوند.

## 6. `cpms_otp_tokens`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| mobile | VARCHAR(32) | | | عادی‌شده |
| purpose | ENUM('login','verify_mobile') | | 'login' | |
| code_hash | CHAR(64) | | | SHA-256(code+pepper) — **هرگز کد خام** |
| expires_at | DATETIME(3) | | | +5 دقیقه |
| attempts | SMALLINT UNSIGNED | | 0 | |
| locked_until | DATETIME(3) | N | | قفل 15 دقیقه |
| consumed_at | DATETIME(3) | N | | |
| created_at | DATETIME(3) | | | |
| Index: `idx_otp_mobile (mobile, purpose, created_at)` |
> پاک‌سازی: Job روزانه رکوردهای >24h. `code_hash` فقط.

## 7. `cpms_idempotency_keys`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| key | VARCHAR(128) | | | **U** (کlient-generated UUID) |
| clinic_id | BIGINT UNSIGNED | | | |
| wp_user_id | BIGINT UNSIGNED | N | | |
| endpoint | VARCHAR(190) | | | |
| response_code | SMALLINT | | | |
| response_json | JSON | N | | برای replay دقیق |
| created_at | DATETIME(3) | | | |
> پاک‌سازی 90 روز.

## 8. `cpms_schedule` (برنامه هفتگی)
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| clinician_id | BIGINT UNSIGNED | | | **FK** |
| day_of_week | TINYINT | | | 0=شنبه ... 6=جمعه (تعریف در docs: 0=Saturday به سبک هفته ایرانی) |
| start_time / end_time | TIME | | | |
| break_start / break_end | TIME | N | | استراحت |
| appointment_duration_min | SMALLINT UNSIGNED | | 20 | |
| slot_capacity | SMALLINT UNSIGNED | | 1 | |
| is_active | TINYINT(1) | | 1 | |
| **U** `(clinician_id, day_of_week)` (روی رکوردهای فعال در Transaction) |

## 9. `cpms_schedule_exceptions`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id, clinician_id | BIGINT UNSIGNED | | | |
| date | DATE | | | |
| type | ENUM('holiday','leave','blocked','open_override') | | | |
| start_time / end_time | TIME | N | | برای open_override/blocked جزئی |
| reason | VARCHAR(255) | N | | |
| created_by_wp_user_id | BIGINT UNSIGNED | | | |
| created_at | DATETIME(3) | | | |
| Index: `idx_sched_exc (clinician_id, date, type)` |

## 10. `cpms_schedule_slots` ⭐
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id, clinician_id | BIGINT UNSIGNED | | | |
| slot_date | DATE | | | |
| slot_time | TIME | | | |
| duration_min | SMALLINT UNSIGNED | | | snapshot از schedule |
| capacity | SMALLINT UNSIGNED | | 1 | |
| booked_count | SMALLINT UNSIGNED | | 0 | |
| held_count | SMALLINT UNSIGNED | | 0 | |
| is_open | TINYINT(1) | | 1 | (مسدودی دستی) |
| generated_from | ENUM('lazy','cron','manual') | | 'lazy' | |
| created_at / updated_at | DATETIME(3) | | | |
| **U** `(clinician_id, slot_date, slot_time)` — K-2 |
| Index: `idx_slots_avail (clinician_id, slot_date, is_open)` |
> **Claim اتمیک (ADR-0004):**
> `UPDATE cpms_schedule_slots SET held_count = held_count + 1 WHERE id = ? AND capacity - booked_count - held_count > 0` → affected_rows=0 یعنی گرفته شده.
> Convert (hold→booked): `SET held_count=held_count-1, booked_count=booked_count+1` در Transaction.

## 11. `cpms_slot_holds`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| slot_id | BIGINT UNSIGNED | | | **FK→slots** |
| holder_wp_user_id | BIGINT UNSIGNED | N | | (بیمار لاگین‌شده) |
| holder_mobile | VARCHAR(32) | N | | (پیش از ساخت اکانت) |
| token | CHAR(36) | | | UUID **U** |
| expires_at | DATETIME(3) | | | +10 دقیقه |
| status | ENUM('active','converted','expired','released') | | 'active' | |
| created_at | DATETIME(3) | | | |
| Index: `idx_hold_slot (slot_id, status)`, `idx_hold_exp (status, expires_at)` (برای Job انقضا) |

## 12. `cpms_appointments`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| reference_code | VARCHAR(24) | | | **U** — `AP-260405-12` |
| clinician_id | BIGINT UNSIGNED | | | **FK** |
| patient_id | BIGINT UNSIGNED | | | **FK→patients RESTRICT** |
| slot_id | BIGINT UNSIGNED | | | **FK→slots** |
| slot_date / slot_time | DATE / TIME | | | snapshot (نمایش/Report سریع) |
| wp_user_id | BIGINT UNSIGNED | N | | رزروکننده |
| reason | VARCHAR(255) | N | | علت مراجعه |
| status | ENUM('pending','confirmed','cancelled_by_patient','cancelled_by_staff','rescheduled','completed','no_show') | | 'pending' | |
| is_walkin_express | TINYINT(1) | | 0 | نوبت فوری |
| rescheduled_from / rescheduled_to | BIGINT UNSIGNED | N | | ارجاع دوطرفه |
| active_visit_id | BIGINT UNSIGNED | N | | NULL تا Check-In |
| booked_at / confirmed_at | DATETIME(3) | N | | |
| cancelled_at / cancel_reason / cancelled_by_wp_user_id | DATETIME(3)/VARCHAR(255)/BIGINT | N | | |
| no_show_at | DATETIME(3) | N | | |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_appt_day (clinician_id, slot_date, status)`, `idx_appt_patient (patient_id, slot_date)`, `idx_appt_ref (reference_code)`، `idx_appt_visit (active_visit_id)` |
> K-1: دو نوبت فعال روی Slot یکسان برای یک بیمار — در Transaction (Row Lock روی slot) بررسی می‌شود.

## 13. `cpms_visits`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| clinician_id | BIGINT UNSIGNED | | | **FK** |
| patient_id | BIGINT UNSIGNED | | | **FK** |
| appointment_id | BIGINT UNSIGNED | N | | **FK→appointments** (NULL=walk-in) |
| source | ENUM('scheduled','walk_in') | | | |
| status | ENUM('checked_in','waiting','called','in_consultation','consultation_completed','awaiting_payment','paid','checked_out','cancelled','skipped') | | 'checked_in' | |
| visit_date | DATE | | | (برای J-5) |
| check_in_at / waiting_since / called_at / consultation_started_at / consultation_completed_at / checked_out_at | DATETIME(3) | N | | |
| cancel_reason / skip_reason | VARCHAR(255) | N | | |
| cancelled_by_wp_user_id | BIGINT UNSIGNED | N | | |
| recall_count | TINYINT UNSIGNED | | 0 | |
| active | TINYINT(1) | | 1 | برای J-5 (Unique نرم) |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_visit_day (clinic_id, visit_date, status)`, `idx_visit_queue (clinician_id, status, waiting_since)`, `idx_visit_patient (patient_id, visit_date)`, `idx_visit_appt (appointment_id)` |
> J-5: یک Visit فعال (active=1) بر (patient_id, clinician_id, visit_date) — در Transaction.

## 14. `cpms_visit_status_history` (append-only, K-6, بدون FK فیزیکی K-8)
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| visit_id | BIGINT UNSIGNED | | | |
| from_status | VARCHAR(32) | N | | NULL برای ایجاد |
| to_status | VARCHAR(32) | | | |
| changed_at | DATETIME(3) | | | |
| actor_wp_user_id | BIGINT UNSIGNED | N | | NULL=سیستم |
| actor_role | VARCHAR(32) | | | |
| note | VARCHAR(255) | N | | |
| request_id | VARCHAR(64) | N | | |
| Index: `idx_vsh_visit (visit_id, changed_at)` |

## 15. `cpms_clinical_notes`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| visit_id | BIGINT UNSIGNED | | | **FK→visits** |
| patient_id | BIGINT UNSIGNED | | | **FK** (denormal برای Query سریع + Audit) |
| clinician_id | BIGINT UNSIGNED | | | **FK** |
| category | ENUM('chief_complaint','history','examination','diagnosis','clinical_note','recommendation_text','private_note','other') | | | |
| visibility | ENUM('patient_visible','doctor_private') | | | |
| content_text | TEXT | | | |
| content_html | MEDIUMTEXT | N | | (Rich Text sanitized؛ V1.5) |
| version | INT UNSIGNED | | 1 | |
| correction_of_note_id | BIGINT UNSIGNED | N | | ارجاع به یادداشت اصلی |
| change_reason | VARCHAR(255) | N | | برای Correction |
| is_archived | TINYINT(1) | | 0 | |
| created_by_wp_user_id / updated_by_wp_user_id | BIGINT UNSIGNED | | | |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_note_visit (visit_id, category)`, `idx_note_patient (patient_id, created_at)`, `idx_note_vis (patient_id, visibility)` |

## 16. `cpms_clinical_note_versions` (append-only)
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| note_id | BIGINT UNSIGNED | | | |
| version | INT UNSIGNED | | | **U(note_id, version)** |
| content_snapshot | MEDIUMTEXT | | | |
| changed_by_wp_user_id | BIGINT UNSIGNED | | | |
| change_reason | VARCHAR(255) | N | | |
| created_at | DATETIME(3) | | | |

## 17. `cpms_handwriting_documents`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id, visit_id, patient_id, clinician_id | BIGINT UNSIGNED | | | |
| title | VARCHAR(190) | N | | |
| page_count | SMALLINT UNSIGNED | | 1 | |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_hwdoc_visit (visit_id)` |

## 18. `cpms_handwriting_pages` ⭐
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | | | **FK** |
| page_index | SMALLINT UNSIGNED | | | **U(document_id, page_index)** |
| width / height | INT UNSIGNED | | | mm/px canvas |
| stroke_data | LONGTEXT | | | **JSON فشرده (gzip+base64)** — آرایه Strokeها: `{id, tool, color, size, points:[[x,y,pressure,ts],...]}` (ADR-0009) |
| stroke_count | INT UNSIGNED | | 0 | |
| preview_png | VARCHAR(255) | | | مسیر (مخفی) |
| preview_pdf | VARCHAR(255) | N | | |
| background_template | ENUM('blank','lined','graph','form') | | 'lined' | |
| client_revision | BIGINT UNSIGNED | | 0 | برای Conflict Detection (ADR-0014) |
| last_saved_at | DATETIME(3) | | | |
| version | INT UNSIGNED | | 1 | |
| updated_at | DATETIME(3) | | | |
> یک Save = یک UPDATE روی یک Row (NFR-PERF-4).

## 19. `cpms_handwriting_page_versions` (append-only)
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| page_id | BIGINT UNSIGNED | | | |
| version | INT UNSIGNED | | | **U(page_id, version)** |
| stroke_data | LONGTEXT | | | snapshot فشرده |
| saved_by | ENUM('autosave','manual','sync_recovery') | | | |
| created_at | DATETIME(3) | | | |
> سیاست نگهداری: نسخه‌های autosave قدیمی (>30 روز و > آخرین 10 نسخه) با Job حذف می‌شوند (Setting).

## 20. `cpms_ocr_jobs`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| source_page_id | BIGINT UNSIGNED | | | **FK→pages** |
| provider | VARCHAR(64) | | | |
| model | VARCHAR(128) | N | | |
| status | ENUM('queued','processing','success','failed','cancelled') | | 'queued' | |
| confidence | DECIMAL(5,4) | N | | 0..1 اگر Provider بدهد |
| extracted_text | MEDIUMTEXT | N | | **نامعتبر تا تأیید** |
| review_status | ENUM('pending','reviewed','confirmed','rejected') | | 'pending' | |
| reviewed_by_wp_user_id / reviewed_at | BIGINT/DATETIME(3) | N | | |
| confirmed_text | MEDIUMTEXT | N | | **تأییدشده — searchable** |
| attempts | SMALLINT UNSIGNED | | 0 | |
| last_error | VARCHAR(255) | N | | |
| created_at / completed_at | DATETIME(3) | N | | |
| Index: `idx_ocr_status (status, created_at)` |

## 21. `cpms_prescriptions`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| prescription_number | VARCHAR(24) | | | **U** `RX-000123` |
| visit_id | BIGINT UNSIGNED | | | **FK** |
| patient_id | BIGINT UNSIGNED | | | **FK** |
| clinician_id | BIGINT UNSIGNED | | | **FK** |
| status | ENUM('draft','finalized','voided') | | 'draft' | |
| is_patient_visible | TINYINT(1) | | 1 | |
| void_reason | VARCHAR(255) | N | | |
| correction_of_prescription_id | BIGINT UNSIGNED | N | | |
| finalized_at | DATETIME(3) | N | | |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_rx_visit (visit_id)`, `idx_rx_patient (patient_id, created_at)` |

## 22. `cpms_prescription_items`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| prescription_id | BIGINT UNSIGNED | | | **FK→prescriptions CASCADE** |
| drug_ref_id | BIGINT UNSIGNED | N | | **FK→drug_reference** (اختیاری) |
| generic_name | VARCHAR(190) | | | |
| brand_name | VARCHAR(190) | N | | |
| strength | VARCHAR(64) | N | | `500mg` |
| form | ENUM('tablet','capsule','syrup','injection','ointment','drops','inhaler','other') | | 'tablet' | |
| dose | VARCHAR(64) | | | `1 قرص` |
| frequency | VARCHAR(64) | | | `هر 8 ساعت` |
| route | ENUM('oral','iv','im','sc','topical','inhaled','other') | | 'oral' | |
| duration_days | SMALLINT UNSIGNED | N | | |
| instructions | VARCHAR(500) | N | | |
| source | ENUM('manual','ocr_confirmed') | | 'manual' | |
| ocr_job_id | BIGINT UNSIGNED | N | | |
| sort_order | SMALLINT UNSIGNED | | 0 | |
| created_at | DATETIME(3) | | | |

## 23. `cpms_drug_reference`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | 1 | global |
| generic_name | VARCHAR(190) | | | **U(clinic_id, generic_name, strength, form)** |
| brand_name | VARCHAR(190) | N | | |
| strength / form | VARCHAR(64)/ENUM | | | |
| is_active | TINYINT(1) | | 1 | |
> Seed دستی/CSV (کاربر).

## 24. `cpms_recommendations`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id, visit_id, patient_id, clinician_id | BIGINT UNSIGNED | | | |
| type | ENUM('diet','rest','activity','care','lab','followup','other') | | | |
| text | VARCHAR(1000) | | | |
| is_patient_visible | TINYINT(1) | | 1 | |
| created_at | DATETIME(3) | | | |
| Index: `idx_rec_visit (visit_id)`, `idx_rec_patient (patient_id, created_at)` |

## 25. `cpms_follow_ups`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id, visit_id, patient_id, clinician_id | BIGINT UNSIGNED | | | |
| is_needed | TINYINT(1) | | 1 | |
| suggested_date | DATE | N | | |
| interval_days | SMALLINT UNSIGNED | N | | |
| reason | VARCHAR(255) | N | | |
| status | ENUM('pending','booked','done','cancelled') | | 'pending' | |
| linked_appointment_id | BIGINT UNSIGNED | N | | |
| reminder_sent_at | DATETIME(3) | N | | |
| created_at | DATETIME(3) | | | |
| Index: `idx_fu_due (status, suggested_date)` (Job یادآوری) |

## 26. `cpms_medical_attachments`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| patient_id | BIGINT UNSIGNED | | | **FK** |
| visit_id | BIGINT UNSIGNED | N | | **FK** |
| category | ENUM('lab_result','image','scan','document','other') | | | |
| original_filename | VARCHAR(255) | | | |
| stored_filename | VARCHAR(64) | | | hash تصادفی **U(clinic_id, stored_filename)** |
| mime_type | VARCHAR(120) | | | اعتبارسنجی finfo |
| file_size | INT UNSIGNED | | | |
| storage_path | VARCHAR(255) | | | (خارج از URL عمومی) |
| visibility | ENUM('patient_visible','doctor_private') | | | |
| metadata_json | JSON | N | | (مثلاً نتایج آزمایش ساختاریافته) |
| uploaded_by_wp_user_id | BIGINT UNSIGNED | | | |
| deleted_at | DATETIME(3) | N | | Soft |
| created_at | DATETIME(3) | | | |
| Index: `idx_att_patient (patient_id, created_at)`, `idx_att_visit (visit_id)`, `idx_att_store (stored_filename)` |

## 27. `cpms_services`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| code | VARCHAR(32) | | | **U(clinic_id, code)** |
| name | VARCHAR(190) | | | |
| price | DECIMAL(12,2) | | | |
| currency | CHAR(3) | | 'IRR' | |
| is_active | TINYINT(1) | | 1 | |
| created_at / updated_at | DATETIME(3) | | | |

## 28. `cpms_invoices`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| invoice_number | VARCHAR(24) | | | **U(clinic_id, invoice_number)** `INV-260905-001` |
| patient_id / visit_id | BIGINT UNSIGNED | | | **FK** |
| status | ENUM('open','partial','paid','voided') | | 'open' | |
| subtotal / discount / tax / total | DECIMAL(12,2) | | 0 | |
| currency | CHAR(3) | | 'IRR' | |
| paid_amount | DECIMAL(12,2) | | 0 | |
| balance | DECIMAL(12,2) | | 0 | |
| issued_by_wp_user_id | BIGINT UNSIGNED | | | |
| void_reason / voided_at | VARCHAR(255)/DATETIME(3) | N | | |
| created_at / updated_at | DATETIME(3) | | | |
| Index: `idx_inv_visit (visit_id)`, `idx_inv_patient (patient_id, created_at)`, `idx_inv_status (clinic_id, status, created_at)` |

## 29. `cpms_invoice_items`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| invoice_id | BIGINT UNSIGNED | | | **FK→invoices CASCADE** |
| service_id | BIGINT UNSIGNED | N | | **FK→services** |
| description | VARCHAR(255) | | | |
| quantity | DECIMAL(8,2) | | 1 | |
| unit_price | DECIMAL(12,2) | | | |
| amount | DECIMAL(12,2) | | | |
| discount | DECIMAL(12,2) | | 0 | |

## 30. `cpms_payments`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| payment_number | VARCHAR(24) | | | **U(clinic_id, payment_number)** `PAY-260905-0001` |
| invoice_id | BIGINT UNSIGNED | | | **FK→invoices RESTRICT** |
| patient_id | BIGINT UNSIGNED | | | |
| amount | DECIMAL(12,2) | | | |
| method | ENUM('cash','card_pos','online','other') | | | |
| transaction_ref | VARCHAR(128) | N | | (POS/bank) |
| idempotency_key | VARCHAR(128) | | | **U(invoice_id, idempotency_key)** — K-3 |
| status | ENUM('captured','voided','refunded') | | 'captured' | |
| refunded_amount | DECIMAL(12,2) | | 0 | |
| paid_at | DATETIME(3) | | | |
| received_by_wp_user_id | BIGINT UNSIGNED | | | |
| void_reason / voided_at / voided_by_wp_user_id | VARCHAR(255)/DATETIME(3)/BIGINT | N | | |
| created_at | DATETIME(3) | | | |
| Index: `idx_pay_invoice (invoice_id, created_at)`, `idx_pay_patient (patient_id, created_at)`, `idx_pay_day (clinic_id, paid_at)` |

## 31. `cpms_payment_adjustments`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| invoice_id / payment_id | BIGINT UNSIGNED | | | payment_id N (adjustment روی فاکتور هم ممکن است) |
| type | ENUM('credit','debit') | | | |
| amount | DECIMAL(12,2) | | | |
| reason | VARCHAR(255) | | | |
| approved_by_wp_user_id | BIGINT UNSIGNED | | | |
| created_at | DATETIME(3) | | | |
> اثر: `balance` فاکتور (در Transaction)؛ روی فاکتور `paid` فقط credit (سود بیمار) مجاز است.

## 32. `cpms_notifications`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| recipient_wp_user_id | BIGINT UNSIGNED | N | | |
| recipient_patient_id | BIGINT UNSIGNED | N | | (یکی از دو تاش) |
| channel | ENUM('internal','sms','email','push') | | | |
| template | VARCHAR(64) | | | |
| payload_json | JSON | | | |
| status | ENUM('queued','sent','delivered','failed','cancelled') | | 'queued' | |
| attempts | SMALLINT UNSIGNED | | 0 | |
| next_retry_at | DATETIME(3) | N | | |
| provider | VARCHAR(64) | N | | |
| provider_ref | VARCHAR(128) | N | | |
| last_error | VARCHAR(255) | N | | |
| dedupe_key | VARCHAR(190) | N | | **U** (ضد تکرار رویداد) |
| sent_at / delivered_at / created_at / scheduled_at | DATETIME(3) | N | | |
| Index: `idx_notif_rcpt (recipient_wp_user_id, status, created_at)`, `idx_notif_retry (status, next_retry_at)` |

## 33. `cpms_jobs`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| type | VARCHAR(64) | | | |
| payload_json | JSON | N | | |
| status | ENUM('queued','processing','success','failed','cancelled') | | 'queued' | |
| priority | TINYINT | | 5 | |
| attempts | SMALLINT UNSIGNED | | 0 | |
| max_attempts | SMALLINT UNSIGNED | | 5 | |
| run_after | DATETIME(3) | | | |
| locked_by / lock_expires_at | VARCHAR(64)/DATETIME(3) | N | | (Single Worker) |
| last_error | VARCHAR(255) | N | | |
| created_at / started_at / completed_at | DATETIME(3) | N | | |
| Index: `idx_job_due (status, run_after, priority)` |

## 34. `cpms_audit_logs` ⭐ (append-only, بدون FK فیزیکی)
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| actor_wp_user_id | BIGINT UNSIGNED | N | | NULL=سیستم |
| actor_role | VARCHAR(32) | | | |
| action | VARCHAR(64) | | | `PATIENT_UPDATE`, `PHI_READ`, `QUEUE_TRANSITION`, `PAYMENT_CAPTURE`, `EXPORT`, `FORBIDDEN_ACCESS_ATTEMPT`, ... |
| resource_type | VARCHAR(48) | | | |
| resource_id | BIGINT UNSIGNED | N | | |
| patient_id | BIGINT UNSIGNED | N | | (فیلتر سریع + Masking) |
| ip_hash | CHAR(64) | N | | HMAC(ip) — نه IP خام |
| session_id | VARCHAR(64) | N | | |
| request_id | VARCHAR(64) | N | | |
| before_json | JSON | N | | change-set |
| after_json | JSON | N | | |
| meta_json | JSON | N | | (فیلترهای Export و...) |
| prev_hash / row_hash | CHAR(64) | | | **Hash Chain** (ADR-0008) |
| created_at | DATETIME(3) | | | |
| Index: `idx_audit_res (resource_type, resource_id, created_at)`, `idx_audit_actor (actor_wp_user_id, created_at)`, `idx_audit_action (action, created_at)`, `idx_audit_patient (patient_id, created_at)` |
> ممنوع: کد OTP، رمز، Token در هیچ فیلدی (NFR-SEC / FR-21.3).

## 35. `cpms_operational_logs`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| level | ENUM('debug','info','warning','error') | | | |
| message | VARCHAR(500) | | | **بدون PHI** |
| context_json | JSON | N | | |
| request_id | VARCHAR(64) | N | | |
| created_at | DATETIME(3) | | | |
> چرخه نگهداری: >90 روز → archive/حذف (Setting).

## 36. `cpms_settings`
| فیلد | نوع | Null | DF | توضیح |
|---|---|---|---|---|
| clinic_id | BIGINT UNSIGNED | | | |
| key | VARCHAR(128) | | | **U(clinic_id, key)** |
| value_json | JSON | | | |
| updated_by_wp_user_id | BIGINT UNSIGNED | N | | |
| updated_at | DATETIME(3) | | | |
> کلیدها (نمونه): `otp.ttl=300`, `otp.max_attempts=5`, `otp.cooldown=60`, `otp.daily_max=3`, `booking.hold_ttl=600`, `booking.min_advance_hours=2`, `booking.max_future_days=30`, `queue.no_show_grace_minutes=30`, `patient.profile_invoices_visible=false`, `retention.audit_years=10`, `retention.record_years=15`, `sms.provider=...`

---

## فهرست Constraints و Indexes (خلاصه اجرایی برای Migration)

| جدول | Unique / FK / Index |
|---|---|
| patients | U(clinic_id,mrn), U(clinic_id,mobile), U(clinic_id,national_id); idx نام |
| patient_user_links | U(patient_id,wp_user_id); FK patients RESTRICT, wp_users |
| schedule | U(clinician_id,day_of_week) فعال |
| schedule_exceptions | idx(clinician_id,date,type) |
| schedule_slots | U(clinician_id,slot_date,slot_time); idx可用性 |
| slot_holds | U(token); idx(slot_id,status), idx(status,expires_at) |
| appointments | U(reference_code); FK slot, patient RESTRICT, clinician; idx روز/بیمار |
| visits | idx روز/صف/بیمار; FK patient, appointment |
| visit_status_history | append-only; idx(visit_id,changed_at) |
| clinical_notes | idx(visit_id,category),(patient_id,visibility) |
| clinical_note_versions | U(note_id,version) |
| handwriting_pages | U(document_id,page_index) |
| handwriting_page_versions | U(page_id,version) |
| ocr_jobs | idx(status,created_at) |
| prescriptions | U(prescription_number); idx(visit_id),(patient_id) |
| prescription_items | FK prescription CASCADE |
| invoices | U(clinic_id,invoice_number) |
| payments | U(clinic_id,payment_number), U(invoice_id,idempotency_key); FK invoice RESTRICT |
| attachments | U(clinic_id,stored_filename) |
| audit_logs | append-only; 4 idx |
| notifications | U(dedupe_key) null-able |
| jobs | idx(status,run_after,priority) |
| idempotency_keys | U(key) |

> Transactional Notes:
> - `book_final`: BEGIN → SELECT slot FOR UPDATE → check hold+slot → INSERT appointment → UPDATE slot (convert) → UPDATE hold(converted) → COMMIT.
> - `payment_captured`: BEGIN → SELECT invoice FOR UPDATE → check idempotency → INSERT payment → UPDATE invoice → (Update Visit اگر settled) → COMMIT.
> - `complete`/`check_out`: BEGIN → SELECT visit FOR UPDATE → transition check → UPDATE + history → (appointment completed در check_out) → COMMIT.
