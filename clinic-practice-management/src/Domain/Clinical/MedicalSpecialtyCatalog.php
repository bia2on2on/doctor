<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Clinical;

use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
use InvalidArgumentException;

/**
 * Offline catalogue of clinical specialty LABELS offered by CPMS forms.
 *
 * Scope boundary (deliberate and explicit):
 *  - This is a **label catalogue for form input**, NOT a master-data entity and
 *    NOT a coded national classification. Phase 4 (`cpms_specialties` +
 *    `cpms_clinician_specialties` M:N, drift M-01/M-04, open decision Q11) owns
 *    real Specialty master data; nothing here creates, migrates or pre-empts it.
 *  - No code system is invented: there are no fabricated identifiers, no claimed
 *    ministry codes, no ICD/نظام‌پزشکی numbering. `slug` is an internal, stable
 *    ASCII key used only for lookup/tests — it is never persisted.
 *  - Persistence is unchanged: `cpms_clinicians.specialty VARCHAR(190) NULL`
 *    keeps storing exactly one human-readable Persian label, so this catalogue
 *    needs no migration and cannot break historical rows.
 *
 * Canonical vs user-defined separation:
 *  - a stored value that equals a catalogue label is CANONICAL (`classify()`
 *    returns its slug and `custom = false`);
 *  - any other non-empty stored value is USER-DEFINED (`custom = true`) and is
 *    re-displayed in the "Other / سایر" field, never forced into a wrong
 *    canonical bucket and never silently dropped.
 *  - Because the specialty column holds a single standalone label (unlike the
 *    composed address string), classification is unambiguous WITHOUT a marker,
 *    which keeps existing free-text rows valid and readable.
 *
 * Provenance & invariants:
 *  - Source: in-repository curated list of specialty labels in common use in
 *    Iranian clinical practice (`DATASET_SOURCE`). Curated, offline, and
 *    versioned — NOT an authoritative government publication.
 *  - Offline-only: zero runtime HTTP/API calls, zero external dependency; the
 *    list ships inside the release ZIP.
 *  - UTF-8 & Persian normalization: canonical Persian Yeh (ی U+06CC) and Kaf
 *    (ک U+06A9), trimmed, control-character-free (shares the exact normalization
 *    contract of `IranProvinceCityCatalog`, so Arabic ي/ك input matches too).
 *  - No silent default: no method returns "پزشک عمومی" or any other label for an
 *    empty/unknown input.
 */
final class MedicalSpecialtyCatalog {

    /** Curated dataset version — bump whenever labels change. */
    public const DATASET_VERSION = '2026.10-cpms-specialty-labels-1';

    /** Honest provenance: curated in-repo labels, not an official classification. */
    public const DATASET_SOURCE = 'cpms-curated-clinical-specialty-labels-offline';

    /** Sentinel a client submits for "Other / سایر"; never persisted. */
    public const OTHER_SPECIALTY_SENTINEL = '__other__';

    /** Column is VARCHAR(190); keep a tighter practical cap for readable labels. */
    public const MAX_SPECIALTY_LENGTH = 120;

    /** Minimum accepted length for a manually entered specialty. */
    public const MIN_CUSTOM_SPECIALTY_LENGTH = 2;

    /**
     * Grouped catalogue. `groups` is ordered for display; every entry carries a
     * stable ASCII `slug`, the canonical Persian `label` and an optional Latin
     * `en` alias used only to widen offline search (never persisted).
     *
     * @var list<array{key:string, label:string, items:list<array{slug:string, label:string, en:string}>}>
     */
    private const GROUPS = [
        [
            'key'   => 'general',
            'label' => 'عمومی و پزشکی خانواده',
            'items' => [
                [ 'slug' => 'general-practitioner', 'label' => 'پزشک عمومی', 'en' => 'General Practitioner' ],
                [ 'slug' => 'family-medicine', 'label' => 'متخصص پزشکی خانواده', 'en' => 'Family Medicine' ],
                [ 'slug' => 'general-dentist', 'label' => 'دندانپزشک عمومی', 'en' => 'General Dentist' ],
                [ 'slug' => 'general-pharmacist', 'label' => 'داروساز عمومی', 'en' => 'Pharmacist' ],
                [ 'slug' => 'midwifery', 'label' => 'مامایی', 'en' => 'Midwifery' ],
            ],
        ],
        [
            'key'   => 'medical',
            'label' => 'تخصص‌های پزشکی بالینی',
            'items' => [
                [ 'slug' => 'internal-medicine', 'label' => 'متخصص داخلی', 'en' => 'Internal Medicine' ],
                [ 'slug' => 'cardiology', 'label' => 'متخصص قلب و عروق', 'en' => 'Cardiology' ],
                [ 'slug' => 'pulmonology', 'label' => 'متخصص ریه', 'en' => 'Pulmonology' ],
                [ 'slug' => 'gastroenterology', 'label' => 'متخصص گوارش و کبد', 'en' => 'Gastroenterology' ],
                [ 'slug' => 'endocrinology', 'label' => 'متخصص غدد درون‌ریز و متابولیسم', 'en' => 'Endocrinology' ],
                [ 'slug' => 'nephrology', 'label' => 'متخصص کلیه و فشار خون', 'en' => 'Nephrology' ],
                [ 'slug' => 'rheumatology', 'label' => 'متخصص روماتولوژی', 'en' => 'Rheumatology' ],
                [ 'slug' => 'hematology-oncology', 'label' => 'متخصص خون و انکولوژی', 'en' => 'Hematology-Oncology' ],
                [ 'slug' => 'infectious-diseases', 'label' => 'متخصص بیماری‌های عفونی', 'en' => 'Infectious Diseases' ],
                [ 'slug' => 'immunology-allergy', 'label' => 'متخصص ایمونولوژی و آلرژی', 'en' => 'Immunology & Allergy' ],
                [ 'slug' => 'neurology', 'label' => 'متخصص مغز و اعصاب', 'en' => 'Neurology' ],
                [ 'slug' => 'psychiatry', 'label' => 'متخصص روانپزشکی', 'en' => 'Psychiatry' ],
                [ 'slug' => 'pediatrics', 'label' => 'متخصص اطفال و نوزادان', 'en' => 'Pediatrics' ],
                [ 'slug' => 'obstetrics-gynecology', 'label' => 'متخصص زنان و زایمان', 'en' => 'Obstetrics & Gynecology' ],
                [ 'slug' => 'ophthalmology', 'label' => 'متخصص چشم', 'en' => 'Ophthalmology' ],
                [ 'slug' => 'ent', 'label' => 'متخصص گوش و حلق و بینی', 'en' => 'Otolaryngology (ENT)' ],
                [ 'slug' => 'dermatology', 'label' => 'متخصص پوست و مو', 'en' => 'Dermatology' ],
                [ 'slug' => 'orthopedics', 'label' => 'متخصص ارتوپدی', 'en' => 'Orthopedics' ],
                [ 'slug' => 'physical-medicine-rehab', 'label' => 'متخصص طب فیزیکی و توانبخشی', 'en' => 'Physical Medicine & Rehabilitation' ],
                [ 'slug' => 'geriatrics', 'label' => 'متخصص طب سالمندان', 'en' => 'Geriatrics' ],
                [ 'slug' => 'emergency-medicine', 'label' => 'متخصص طب اورژانس', 'en' => 'Emergency Medicine' ],
                [ 'slug' => 'occupational-medicine', 'label' => 'متخصص طب کار', 'en' => 'Occupational Medicine' ],
                [ 'slug' => 'traditional-medicine', 'label' => 'متخصص طب سنتی', 'en' => 'Traditional Medicine' ],
                [ 'slug' => 'sports-medicine', 'label' => 'متخصص پزشکی ورزشی', 'en' => 'Sports Medicine' ],
                [ 'slug' => 'forensic-medicine', 'label' => 'متخصص پزشکی قانونی', 'en' => 'Forensic Medicine' ],
                [ 'slug' => 'community-medicine', 'label' => 'متخصص پزشکی اجتماعی', 'en' => 'Community Medicine' ],
            ],
        ],
        [
            'key'   => 'surgical',
            'label' => 'جراحی و بیهوشی',
            'items' => [
                [ 'slug' => 'general-surgery', 'label' => 'متخصص جراحی عمومی', 'en' => 'General Surgery' ],
                [ 'slug' => 'neurosurgery', 'label' => 'متخصص جراحی مغز و اعصاب', 'en' => 'Neurosurgery' ],
                [ 'slug' => 'cardiac-surgery', 'label' => 'متخصص جراحی قلب', 'en' => 'Cardiac Surgery' ],
                [ 'slug' => 'vascular-surgery', 'label' => 'متخصص جراحی عروق', 'en' => 'Vascular Surgery' ],
                [ 'slug' => 'thoracic-surgery', 'label' => 'متخصص جراحی قفسه سینه', 'en' => 'Thoracic Surgery' ],
                [ 'slug' => 'plastic-surgery', 'label' => 'متخصص جراحی پلاستیک و ترمیمی', 'en' => 'Plastic & Reconstructive Surgery' ],
                [ 'slug' => 'urology', 'label' => 'متخصص جراحی کلیه و مجاری ادراری', 'en' => 'Urology' ],
                [ 'slug' => 'colorectal-surgery', 'label' => 'متخصص جراحی روده و معقد', 'en' => 'Colorectal Surgery' ],
                [ 'slug' => 'anesthesiology', 'label' => 'متخصص بیهوشی', 'en' => 'Anesthesiology' ],
            ],
        ],
        [
            'key'   => 'diagnostic',
            'label' => 'تصویربرداری و پاراکلینیک',
            'items' => [
                [ 'slug' => 'radiology', 'label' => 'متخصص رادیولوژی', 'en' => 'Radiology' ],
                [ 'slug' => 'sonography', 'label' => 'سونوگرافی', 'en' => 'Sonography' ],
                [ 'slug' => 'nuclear-medicine', 'label' => 'متخصص پزشکی هسته‌ای', 'en' => 'Nuclear Medicine' ],
                [ 'slug' => 'radiotherapy', 'label' => 'متخصص رادیوتراپی (آنکولوژی تشعشعی)', 'en' => 'Radiotherapy' ],
                [ 'slug' => 'pathology', 'label' => 'متخصص آسیب‌شناسی (پاتولوژی)', 'en' => 'Pathology' ],
                [ 'slug' => 'clinical-laboratory', 'label' => 'آزمایشگاه بالینی', 'en' => 'Clinical Laboratory' ],
                [ 'slug' => 'medical-genetics', 'label' => 'متخصص ژنتیک پزشکی', 'en' => 'Medical Genetics' ],
            ],
        ],
        [
            'key'   => 'dental',
            'label' => 'تخصص‌های دندانپزشکی',
            'items' => [
                [ 'slug' => 'orthodontics', 'label' => 'متخصص ارتودنسی', 'en' => 'Orthodontics' ],
                [ 'slug' => 'oral-maxillofacial-surgery', 'label' => 'متخصص جراحی دهان و فک و صورت', 'en' => 'Oral & Maxillofacial Surgery' ],
                [ 'slug' => 'endodontics', 'label' => 'متخصص درمان ریشه (اندودنتیکس)', 'en' => 'Endodontics' ],
                [ 'slug' => 'prosthodontics', 'label' => 'متخصص پروتزهای دندانی', 'en' => 'Prosthodontics' ],
                [ 'slug' => 'pediatric-dentistry', 'label' => 'متخصص دندانپزشکی کودکان', 'en' => 'Pediatric Dentistry' ],
                [ 'slug' => 'periodontics', 'label' => 'متخصص بیماری‌های لثه (پریودنتیکس)', 'en' => 'Periodontics' ],
                [ 'slug' => 'restorative-dentistry', 'label' => 'متخصص دندانپزشکی ترمیمی و زیبایی', 'en' => 'Restorative & Cosmetic Dentistry' ],
                [ 'slug' => 'oral-radiology', 'label' => 'متخصص رادیولوژی دهان و فک و صورت', 'en' => 'Oral & Maxillofacial Radiology' ],
                [ 'slug' => 'oral-pathology', 'label' => 'متخصص آسیب‌شناسی دهان و فک و صورت', 'en' => 'Oral & Maxillofacial Pathology' ],
            ],
        ],
        [
            'key'   => 'pharmacy',
            'label' => 'داروسازی و سم‌شناسی',
            'items' => [
                [ 'slug' => 'clinical-pharmacy', 'label' => 'داروسازی بالینی', 'en' => 'Clinical Pharmacy' ],
                [ 'slug' => 'toxicology', 'label' => 'سم‌شناسی', 'en' => 'Toxicology' ],
            ],
        ],
        [
            'key'   => 'allied',
            'label' => 'پیراپزشکی، توانبخشی و سلامت روان',
            'items' => [
                [ 'slug' => 'physiotherapy', 'label' => 'فیزیوتراپی', 'en' => 'Physiotherapy' ],
                [ 'slug' => 'occupational-therapy', 'label' => 'کاردرمانی', 'en' => 'Occupational Therapy' ],
                [ 'slug' => 'speech-therapy', 'label' => 'گفتاردرمانی', 'en' => 'Speech Therapy' ],
                [ 'slug' => 'audiology', 'label' => 'شنوایی‌شناسی', 'en' => 'Audiology' ],
                [ 'slug' => 'optometry', 'label' => 'بینایی‌سنجی (اپتومتری)', 'en' => 'Optometry' ],
                [ 'slug' => 'clinical-nutrition', 'label' => 'تغذیه بالینی', 'en' => 'Clinical Nutrition' ],
                [ 'slug' => 'clinical-psychology', 'label' => 'روان‌شناسی بالینی', 'en' => 'Clinical Psychology' ],
                [ 'slug' => 'counseling', 'label' => 'مشاوره و روان‌درمانی', 'en' => 'Counseling' ],
                [ 'slug' => 'nursing', 'label' => 'پرستاری', 'en' => 'Nursing' ],
                [ 'slug' => 'social-work', 'label' => 'مددکاری اجتماعی', 'en' => 'Social Work' ],
            ],
        ],
    ];

    /**
     * Grouped catalogue, as stored.
     *
     * @return list<array{key:string, label:string, items:list<array{slug:string, label:string, en:string}>}>
     */
    public static function groups(): array {
        return self::GROUPS;
    }

    /**
     * Flat list of every catalogue entry.
     *
     * @return list<array{slug:string, label:string, en:string, group:string, group_label:string}>
     */
    public static function specialties(): array {
        $flat = [];
        foreach ( self::GROUPS as $group ) {
            foreach ( $group['items'] as $item ) {
                $flat[] = [
                    'slug'        => $item['slug'],
                    'label'       => $item['label'],
                    'en'          => $item['en'],
                    'group'       => $group['key'],
                    'group_label' => $group['label'],
                ];
            }
        }

        return $flat;
    }

    /**
     * Canonical Persian labels only, in display order.
     *
     * @return list<string>
     */
    public static function labels(): array {
        return array_map(
            static fn( array $item ): string => $item['label'],
            self::specialties()
        );
    }

    /**
     * group label => list of canonical labels, for offline client-side search.
     *
     * @return array<string, list<string>>
     */
    public static function client_catalog_map(): array {
        $map = [];
        foreach ( self::GROUPS as $group ) {
            $map[ $group['label'] ] = array_map(
                static fn( array $item ): string => $item['label'],
                $group['items']
            );
        }

        return $map;
    }

    /**
     * Shared normalization contract (identical to the geography catalogue).
     */
    public static function normalize_text( string $value ): string {
        return IranProvinceCityCatalog::normalize_persian_text( $value );
    }

    /**
     * Look up one catalogue entry by canonical Persian label, ASCII slug or Latin alias.
     *
     * @return array{slug:string, label:string, en:string, group:string, group_label:string}|null
     */
    public static function find( string $label_or_slug ): ?array {
        $needle = self::normalize_text( $label_or_slug );
        if ( $needle === '' ) {
            return null;
        }

        $slug_needle = strtolower( $needle );
        foreach ( self::specialties() as $item ) {
            if (
                $item['label'] === $needle
                || $item['slug'] === $slug_needle
                || strtolower( $item['en'] ) === $slug_needle
            ) {
                return $item;
            }
        }

        return null;
    }

    public static function is_known( string $label_or_slug ): bool {
        return self::find( $label_or_slug ) !== null;
    }

    /**
     * Classify a PERSISTED specialty value.
     *
     * `custom = true` means the value is user-defined (typed through "Other / سایر"
     * or written before this catalogue existed) and must be re-displayed in the
     * manual field — it is never coerced into a canonical bucket.
     *
     * @return array{label:string, slug:?string, group:?string, custom:bool}
     */
    public static function classify( ?string $stored ): array {
        $label = self::normalize_text( (string) ( $stored ?? '' ) );
        if ( $label === '' ) {
            return [ 'label' => '', 'slug' => null, 'group' => null, 'custom' => false ];
        }

        $match = self::find( $label );
        if ( $match !== null ) {
            return [
                'label'  => $match['label'],
                'slug'   => $match['slug'],
                'group'  => $match['group'],
                'custom' => false,
            ];
        }

        return [ 'label' => $label, 'slug' => null, 'group' => null, 'custom' => true ];
    }

    /**
     * Sanitize a manually entered ("Other / سایر") specialty label.
     *
     * Whitelist mirroring the geography contract: letters, digits, ZWNJ, dashes,
     * slashes/ampersands used inside real labels ("خون و انکولوژی", "دهان و فک و
     * صورت"), parentheses and whitespace survive; everything else (separators that
     * could forge structure, quotes, angle brackets, markup, control characters) is
     * removed. Never throws.
     */
    public static function sanitize_custom( string $value ): string {
        $normalized = self::normalize_text( $value );
        if ( $normalized === '' ) {
            return '';
        }

        if ( strtolower( $normalized ) === self::OTHER_SPECIALTY_SENTINEL ) {
            return '';
        }

        $cleaned = preg_replace( '/[^\p{L}\p{N}\x{200C}\x{2010}-\x{2015}\s\-().&]+/u', ' ', $normalized );
        if ( ! is_string( $cleaned ) ) {
            return '';
        }

        return self::normalize_text( $cleaned );
    }

    /**
     * Resolve what must be persisted from the two submitted controls.
     *
     * Server-side authority: JavaScript is never trusted. Rules
     *  - nothing submitted -> `null` (column stays NULL, historical value untouched
     *    by callers that skip the field);
     *  - a canonical label/slug submitted -> the canonical label is persisted;
     *  - "Other / سایر" (or a manual value with no selection) -> the manual label is
     *    validated and persisted verbatim (normalized);
     *  - a manual label that duplicates a canonical one collapses to the canonical
     *    label, so the catalogue remains the single source of truth;
     *  - overlong, letter-less, sentinel-only or ambiguous payloads are rejected.
     *
     * @throws InvalidArgumentException On an unusable manual specialty or an ambiguous payload.
     */
    public static function resolve_submitted( ?string $raw_selected, ?string $raw_custom = null ): ?string {
        $selected_raw = self::normalize_text( (string) ( $raw_selected ?? '' ) );
        $other        = strtolower( trim( (string) ( $raw_selected ?? '' ) ) ) === self::OTHER_SPECIALTY_SENTINEL;
        $selected     = $other ? '' : $selected_raw;
        $raw_typed    = trim( (string) ( $raw_custom ?? '' ) );
        $custom       = self::sanitize_custom( $raw_typed );

        if ( $selected === '' && $custom === '' ) {
            // The operator typed something that carries no usable letter/digit: reject
            // it explicitly instead of silently dropping the input.
            if ( $raw_typed !== '' ) {
                return self::accept_custom( $raw_typed );
            }

            return null;
        }

        if ( $selected !== '' ) {
            $match = self::find( $selected );
            if ( $match === null ) {
                // Not a catalogue entry: treat the submitted text itself as the
                // user-defined value (a form may legitimately post free text).
                if ( $custom !== '' && $custom !== $selected ) {
                    throw new InvalidArgumentException( 'هم‌زمان نمی‌توان یک تخصص از فهرست و یک تخصص دستی وارد کرد؛ یکی را انتخاب کنید.' );
                }

                return self::accept_custom( $selected );
            }

            if ( $custom !== '' && $custom !== $match['label'] ) {
                throw new InvalidArgumentException( 'هم‌زمان نمی‌توان یک تخصص از فهرست و یک تخصص دستی وارد کرد؛ یکی را انتخاب کنید.' );
            }

            return $match['label'];
        }

        return self::accept_custom( $custom );
    }

    /**
     * Validate and return a user-defined specialty label.
     *
     * @throws InvalidArgumentException
     */
    public static function accept_custom( string $raw_custom ): string {
        $custom = self::sanitize_custom( $raw_custom );
        if ( $custom === '' ) {
            throw new InvalidArgumentException( 'نام تخصص واردشده معتبر نیست؛ لطفاً تخصص را بدون علامت نگارشی وارد کنید.' );
        }

        if ( mb_strlen( $custom ) > self::MAX_SPECIALTY_LENGTH ) {
            throw new InvalidArgumentException( 'نام تخصص واردشده بیش از حد طولانی است.' );
        }

        if ( mb_strlen( $custom ) < self::MIN_CUSTOM_SPECIALTY_LENGTH || preg_match( '/\p{L}/u', $custom ) !== 1 ) {
            throw new InvalidArgumentException( 'نام تخصص واردشده باید حداقل دو نویسه و شامل حروف باشد.' );
        }

        // Canonical wins over a typed duplicate so no shadow label is created.
        $match = self::find( $custom );

        return $match !== null ? $match['label'] : $custom;
    }
}
