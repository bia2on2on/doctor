<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * Explicit, authorized Clinic selection for wp-admin screens.
 *
 * Shared by screens that separate installation-wide information from
 * Clinic-specific data (System, Settings) and by Clinic-scoped management
 * screens used by people who manage more than one Clinic (Clinicians).
 *
 * Invariants:
 *  - A requested `clinic_id` is only a selector. It is verified against the
 *    actor's durable ACTIVE membership (TrustedClinicEstablisher) and then
 *    against the Clinic-scoped capability (AuthorizationService, DENY > GRANT).
 *  - Nothing is inferred here: there is no sole-Clinic, first-row,
 *    primary-membership or unique-membership fallback. An absent, malformed,
 *    foreign or unauthorized selector resolves to null.
 *  - The choice list contains only Clinics in which the actor holds an active
 *    membership AND the requested capability. The rendered selector has no
 *    pre-selected default.
 *  - WordPress `manage_options`/`cpms_config` is never treated as Clinic
 *    authority.
 */
final class AdminClinicSelector {
    private function __construct() {
    }

    /**
     * Clinics the actor may select for the given Clinic-scoped capability.
     *
     * @return list<array{clinic_id:int, clinic_name:string}>
     */
    public static function choices( int $actor_user_id, string $capability ): array {
        if ( $actor_user_id <= 0 ) {
            return [];
        }

        $choices = [];
        $seen    = [];
        foreach ( App::membership_service()->active_memberships_for_user( $actor_user_id ) as $membership ) {
            $clinic_id = (int) ( $membership['clinic_id'] ?? 0 );
            if ( $clinic_id <= 0 || isset( $seen[ $clinic_id ] ) ) {
                continue;
            }
            if ( ! App::authorization_service()->can( $actor_user_id, $clinic_id, $capability ) ) {
                continue;
            }
            $seen[ $clinic_id ] = true;
            $choices[]          = [
                'clinic_id'   => $clinic_id,
                'clinic_name' => (string) ( $membership['clinic_name'] ?? ( 'Clinic #' . $clinic_id ) ),
            ];
        }

        return $choices;
    }

    /**
     * Raw selector value from a request array.
     *
     * Returns null when the selector is absent or empty, and 0 when it is
     * present but malformed, so callers can distinguish "not chosen yet" from
     * "rejected".
     *
     * @param array<string|int, mixed> $source Usually $_GET or $_POST.
     */
    public static function requested_clinic_id( array $source ): ?int {
        if ( ! isset( $source['clinic_id'] ) || ! is_scalar( $source['clinic_id'] ) ) {
            return null;
        }
        $raw = trim( (string) wp_unslash( (string) $source['clinic_id'] ) );
        if ( '' === $raw ) {
            return null;
        }
        if ( 1 !== preg_match( '/^[1-9][0-9]{0,9}$/', $raw ) ) {
            return 0;
        }

        return (int) $raw;
    }

    /**
     * Verify an explicit Clinic selector for the actor and capability.
     *
     * Never infers a Clinic: a null/zero selector returns null.
     */
    public static function establish( int $actor_user_id, ?int $requested_clinic_id, string $capability ): ?ClinicScope {
        if ( $actor_user_id <= 0 || null === $requested_clinic_id || $requested_clinic_id <= 0 ) {
            return null;
        }

        try {
            $scope = ( new TrustedClinicEstablisher( App::db(), new MembershipRepository( App::db() ) ) )
                ->establish( $actor_user_id, $requested_clinic_id );
            App::authorization_service()->authorize( $actor_user_id, (int) $scope->clinicId, $capability ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the published scope API.

            return $scope;
        } catch ( ScopeRequiredException | AuthorizationException ) {
            return null;
        }
    }

    /**
     * Render the explicit Clinic selector (GET form, no default selection).
     *
     * @param list<array{clinic_id:int, clinic_name:string}> $choices
     */
    public static function render_selector(
        string $page_slug,
        string $element_id,
        array $choices,
        int $selected_clinic_id,
        string $label
    ): void {
        if ( [] === $choices ) {
            return;
        }
        ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="cpms-clinic-selector" data-cpms-clinic-selector="<?php echo esc_attr( $page_slug ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
            <label for="<?php echo esc_attr( $element_id ); ?>"><?php echo esc_html( $label ); ?></label>
            <select id="<?php echo esc_attr( $element_id ); ?>" name="clinic_id" required>
                <option value="">انتخاب Clinic</option>
                <?php foreach ( $choices as $choice ) : ?>
                    <option value="<?php echo (int) $choice['clinic_id']; ?>" <?php selected( $selected_clinic_id, (int) $choice['clinic_id'] ); ?>><?php echo esc_html( (string) $choice['clinic_name'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">اعمال Clinic</button>
            <p class="description">انتخاب Clinic فقط زمینهٔ نمایش است؛ سرور عضویت فعال و مجوز Clinic-scoped را در هر درخواست دوباره بررسی می‌کند و هیچ Clinic پیش‌فرضی حدس زده نمی‌شود.</p>
        </form>
        <?php
    }
}
