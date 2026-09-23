<?php
// phpcs:disable Generic.WhiteSpace.DisallowSpaceIndent,WordPress.Files.FileName,WordPress.PHP.YodaConditions,Universal.Arrays.DisallowShortArraySyntax,WordPress.Arrays.ArrayDeclarationSpacing,NormalizedArrays.Arrays.ArrayBraceSpacing,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.NamingConventions.ValidVariableName,WordPress.NamingConventions.ValidFunctionName,WordPress.WhiteSpace.ControlStructureSpacing,PEAR.Functions.FunctionCallSignature,Generic.WhiteSpace.ArbitraryParenthesesSpacing,Squiz.Functions.FunctionDeclarationArgumentSpacing,Generic.Functions.OpeningFunctionBraceKernighanRitchie,WordPress.WhiteSpace.OperatorSpacing,Generic.Formatting.MultipleStatementAlignment,WordPress.WhiteSpace.CastStructureSpacing,WordPress.NamingConventions.PrefixAllGlobals,WordPress.Arrays.MultipleStatementAlignment,WordPress.WhiteSpace.OperatorSpacing,Generic.WhiteSpace.DisallowSpaceIndent
declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName,WordPress.NamingConventions.ValidFunctionName,WordPress.WhiteSpace.ControlStructureSpacing,PEAR.Functions.FunctionCallSignature,Generic.WhiteSpace.ArbitraryParenthesesSpacing,Squiz.Functions.FunctionDeclarationArgumentSpacing,Generic.Functions.OpeningFunctionBraceKernighanRitchie,WordPress.WhiteSpace.OperatorSpacing,Generic.Formatting.MultipleStatementAlignment,WordPress.WhiteSpace.CastStructureSpacing,WordPress.NamingConventions.PrefixAllGlobals,WordPress.Arrays.MultipleStatementAlignment,WordPress.WhiteSpace.OperatorSpacing,Generic.WhiteSpace.DisallowSpaceIndent,WordPress.Arrays.ArrayDeclarationSpacing,NormalizedArrays.Arrays.ArrayBraceSpacing


namespace ClinicCore\Rest;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Doctor Portal independent shell — context endpoints (read-only Today+Live Queue).
 *
 * Provides:
 * - GET /doctor/portal/context : doctor identity + eligible clinics + current clinic/location + auto-resolution
 * - GET /doctor/portal/locations?clinic_id=X : eligible locations for that clinic for current doctor
 *
 * Reuses trusted Clinic+Location scope: RestClinicContext + TrustedClinicEstablisher + ScopeContext.
 * No new auth model, no SPA, no mutation.
 */
final class DoctorPortalController extends RestBase
{
    public function __construct(
        private readonly MembershipRepository $memberships
    ) {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/doctor/portal/context', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn(WP_REST_Request $r) => $this->context($r),
                'permission_callback' => fn(WP_REST_Request $r) => $this->permDoctor($r),
            ],
        ]);

        register_rest_route(self::NS, '/doctor/portal/locations', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn(WP_REST_Request $r) => $this->locations($r),
                'permission_callback' => fn(WP_REST_Request $r) => $this->permDoctor($r),
                'args' => [
                    'clinic_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/doctor/portal/clinics', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn(WP_REST_Request $r) => $this->clinics($r),
                'permission_callback' => fn(WP_REST_Request $r) => $this->permDoctor($r),
            ],
        ]);
    }

    private function permDoctor(WP_REST_Request $r): bool|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $cap = $this->requireCap(RolesAndCapabilities::QUEUE_READ);
        if ($cap instanceof WP_Error) {
            return $cap;
        }
        // Doctor identity fail-closed: must have doctor role + linked active clinician
        $user = wp_get_current_user();
        if (!($user instanceof \WP_User) || (int) $user->ID <= 0) {
            return new WP_Error('CLINIC_UNAUTHORIZED', 'Unauthorized', ['status' => 401]);
        }
        $roles = (array) ($user->roles ?? []);
        if (!in_array(RolesAndCapabilities::ROLE_DOCTOR, $roles, true)) {
            return new WP_Error('CLINIC_PERMISSION_DENIED', 'Doctor role required', ['status' => 403]);
        }
        $db = App::db();
        $clinicianId = $db->fetchValue(
            'SELECT id FROM ' . $db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [(int) $user->ID]
        );
        if ($clinicianId === null || (int) $clinicianId <= 0) {
            return new WP_Error('CLINIC_PERMISSION_DENIED', 'Doctor not linked to active clinician', ['status' => 403]);
        }
        // Secretary must not enter via overlapping caps – if user has secretary role but not doctor-only? We already require doctor role.
        // Additionally, ensure doctor participates in at least one clinic (has active membership)
        $activeClinics = $this->memberships->active_clinic_ids_for_user((int) $user->ID);
        if ($activeClinics === []) {
            return new WP_Error('CLINIC_SCOPE_UNAVAILABLE', 'No active clinic membership', ['status' => 403]);
        }
        return true;
    }

    private function context(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $userId = (int) ($user->ID ?? 0);
        $db = App::db();

        // Doctor identity
        $clinicianRow = $db->fetchRow(
            'SELECT id, full_name, clinic_id FROM ' . $db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [$userId]
        );
        $clinicianId = $clinicianRow ? (int) $clinicianRow['id'] : 0;
        $clinicianName = $clinicianRow ? (string) $clinicianRow['full_name'] : '';

        // Eligible clinics for this doctor (active memberships)
        $memberships = $this->memberships->active_for_user($userId);
        $clinics = [];
        foreach ($memberships as $m) {
            $clinics[] = [
                'id' => (int) $m['clinic_id'],
                'name' => (string) ($m['clinic_name'] ?? 'Clinic ' . $m['clinic_id']),
                'slug' => (string) ($m['clinic_slug'] ?? ''),
                'organization_id' => 0, // will be filled from clinic row if needed
            ];
        }

        // Try to get current trusted scope (if request already has X-CPMS-Clinic-Id / Location-Id)
        $currentClinic = null;
        $currentLocation = null;
        $selectedClinicId = null;
        $selectedLocationId = null;

        // Determine auto-resolution: if 1 clinic, auto; if N>1, require explicit (no fallback)
        if (count($clinics) === 1) {
            $selectedClinicId = $clinics[0]['id'];
            $currentClinic = $clinics[0];
        } else {
            // Check if request has explicit Clinic header (via RestClinicContext, App::scope will be set)
            try {
                $scope = App::scope();
                $selectedClinicId = $scope->clinicId;
                $selectedLocationId = $scope->locationId;
                // Find clinic details for current
                foreach ($clinics as $c) {
                    if ($c['id'] === $selectedClinicId) {
                        $currentClinic = $c;
                        break;
                    }
                }
                if ($selectedLocationId !== null) {
                    $locRow = $db->fetchRow(
                        'SELECT id, name, slug, timezone, is_primary, is_active FROM ' . $db->table('cpms_locations') . ' WHERE id = %d LIMIT 1',
                        [$selectedLocationId]
                    );
                    if ($locRow) {
                        $currentLocation = [
                            'id' => (int) $locRow['id'],
                            'name' => (string) $locRow['name'],
                            'slug' => (string) $locRow['slug'],
                            'timezone' => (string) $locRow['timezone'],
                            'is_primary' => (int) $locRow['is_primary'] === 1,
                            'is_active' => (int) $locRow['is_active'] === 1,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // No explicit scope yet – leave null, frontend will show selector
            }
        }

        // If 1 clinic auto, also resolve its eligible locations
        $eligibleLocations = [];
        if ($selectedClinicId !== null) {
            $eligibleLocations = $this->eligibleLocationsForClinic($selectedClinicId, $userId);
            if (count($eligibleLocations) === 1) {
                $selectedLocationId = $eligibleLocations[0]['id'];
                $currentLocation = $eligibleLocations[0];
            }
        }

        // For N>1 clinics, do NOT auto-select first – require explicit (fail-closed)
        // For locations, same: N>1 requires explicit, no first/primary fallback (handled in frontend)

        $data = [
            'doctor' => [
                'wp_user_id' => $userId,
                'clinician_id' => $clinicianId,
                'clinician_name' => $clinicianName,
                'display_name' => (string) $user->display_name,
            ],
            'clinics' => $clinics,
            'current_clinic' => $currentClinic,
            'current_location' => $currentLocation,
            'selected_clinic_id' => $selectedClinicId,
            'selected_location_id' => $selectedLocationId,
            'eligible_locations' => $eligibleLocations,
        ];

        return $this->success($data);
    }

    private function clinics(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $userId = (int) wp_get_current_user()->ID;
        $memberships = $this->memberships->active_for_user($userId);
        $clinics = [];
        foreach ($memberships as $m) {
            $clinics[] = [
                'id' => (int) $m['clinic_id'],
                'name' => (string) ($m['clinic_name'] ?? 'Clinic ' . $m['clinic_id']),
                'slug' => (string) ($m['clinic_slug'] ?? ''),
            ];
        }
        return $this->success(['clinics' => $clinics]);
    }

    private function locations(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $userId = (int) ($user->ID ?? 0);
        $clinicId = (int) $r->get_param('clinic_id');

        // If clinic_id not provided, try to get from trusted scope
        if ($clinicId <= 0) {
            try {
                $scope = App::scope();
                $clinicId = $scope->clinicId;
            } catch (\Throwable $e) {
                // If user has exactly 1 clinic, use it
                $active = $this->memberships->active_clinic_ids_for_user($userId);
                if (count($active) === 1) {
                    $clinicId = $active[0];
                } else {
                    return $this->error('CLINIC_SCOPE_REQUIRED', 400, 'Clinic scope required for locations', ['field' => 'clinic_id']);
                }
            }
        }

        // Validate membership for this clinic
        if ($this->memberships->find_active($clinicId, $userId) === null) {
            return $this->error('CLINIC_SCOPE_UNAVAILABLE', 403, 'No membership for clinic', ['reason' => 'membership']);
        }

        $locations = $this->eligibleLocationsForClinic($clinicId, $userId);

        return $this->success(['locations' => $locations, 'clinic_id' => $clinicId]);
    }

    /**
     * Eligible Locations resolution — mirrors TrustedClinicEstablisher.
     *
     * @return list<array<string, mixed>>
     */
    private function eligibleLocationsForClinic(int $clinicId, int $wpUserId): array
    {
        $db = App::db();
        $membership = $this->memberships->find_active($clinicId, $wpUserId);
        if ($membership === null) {
            return [];
        }

        $activeRows = $db->fetchAll(
            'SELECT id, name, slug, timezone, is_primary, is_active FROM ' . $db->table('cpms_locations') .
            ' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC',
            [$clinicId]
        );
        $active = [];
        foreach ((is_array($activeRows) ? $activeRows : []) as $row) {
            $active[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'timezone' => (string) $row['timezone'],
                'is_primary' => (int) $row['is_primary'] === 1,
                'is_active' => (int) $row['is_active'] === 1,
            ];
        }

        $scopeMode = (string) ($membership['scope_mode'] ?? 'clinic');
        if ($scopeMode === 'location') {
            $assignedIds = $this->memberships->location_ids_for((int) $membership['id']);
            $eligible = [];
            foreach ($assignedIds as $id) {
                if (isset($active[$id])) {
                    $eligible[] = $active[$id];
                }
            }
            return $eligible;
        }

        return array_values($active);
    }
}
