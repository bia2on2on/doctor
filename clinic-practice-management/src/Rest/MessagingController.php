<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Messaging\MessagingException;
use ClinicCore\Application\Messaging\StaffMessagingService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST boundary for private one-to-one messaging among eligible Clinic staff.
 *
 * Efficient authenticated polling only (incremental `since_id`); no WebSocket,
 * no WordPress comments, no public route. Every route is nonce + login +
 * Clinic-scoped capability guarded in its permission_callback, and every
 * service call re-authorizes the trusted Clinic/Organization/Location and the
 * persisted participant/patient/visit chain.
 */
final class MessagingController extends RestBase {

    private const SEND_PER_MINUTE = 20;
    private const READ_PER_MINUTE = 120;

    public function __construct( private readonly StaffMessagingService $messaging ) {
    }

    public function register_routes(): void {
        register_rest_route(
            self::NS,
            '/staff/portal/messages/context',
            [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn ( WP_REST_Request $r ): WP_REST_Response|WP_Error => $this->context( $r ),
					'permission_callback' => fn ( WP_REST_Request $r ): bool|WP_Error => $this->permission( $r ),
				],
			]
        );

        register_rest_route(
            self::NS,
            '/staff/portal/messages/threads',
            [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn ( WP_REST_Request $r ): WP_REST_Response|WP_Error => $this->threads( $r ),
					'permission_callback' => fn ( WP_REST_Request $r ): bool|WP_Error => $this->permission( $r ),
					'args'                => [
						'limit' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn ( WP_REST_Request $r ): WP_REST_Response|WP_Error => $this->create_thread( $r ),
					'permission_callback' => fn ( WP_REST_Request $r ): bool|WP_Error => $this->permission( $r ),
					'args'                => [
						'recipient_wp_user_id' => ['required' => true, 'type' => 'integer'],
						'patient_id'           => ['required' => false, 'type' => 'integer'],
						'visit_id'             => ['required' => false, 'type' => 'integer'],
					],
				],
			]
        );

        register_rest_route(
            self::NS,
            '/staff/portal/messages/threads/(?P<id>\d+)/messages',
            [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn ( WP_REST_Request $r ): WP_REST_Response|WP_Error => $this->messages( $r ),
					'permission_callback' => fn ( WP_REST_Request $r ): bool|WP_Error => $this->permission( $r ),
					'args'                => [
						'since_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
						'limit'    => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn ( WP_REST_Request $r ): WP_REST_Response|WP_Error => $this->send( $r ),
					'permission_callback' => fn ( WP_REST_Request $r ): bool|WP_Error => $this->permission( $r ),
					'args'                => [
						'body' => ['required' => true, 'type' => 'string'],
					],
				],
			]
        );

        register_rest_route(
            self::NS,
            '/staff/portal/messages/threads/(?P<id>\d+)/read',
            [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn ( WP_REST_Request $r ): WP_REST_Response|WP_Error => $this->read( $r ),
					'permission_callback' => fn ( WP_REST_Request $r ): bool|WP_Error => $this->permission( $r ),
				],
			]
        );
    }

    private function context( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed = $this->poll_limit( $request );
        if ( $allowed instanceof WP_Error ) {
            return $allowed;
        }

        return $this->run( fn (): array => $this->messaging->context( $this->user_id() ) );
    }

    private function threads( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed = $this->poll_limit( $request );
        if ( $allowed instanceof WP_Error ) {
            return $allowed;
        }
        $limit = (int) ( $request['limit'] ?? 50 );

        return $this->run( fn (): array => $this->messaging->threads( $this->user_id(), $limit > 0 ? $limit : 50 ) );
    }

    private function create_thread( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed = $this->poll_limit( $request );
        if ( $allowed instanceof WP_Error ) {
            return $allowed;
        }

        return $this->run(
            fn (): array => $this->messaging->create_thread( $this->user_id(), $this->body( $request ) ),
            201
        );
    }

    private function messages( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed = $this->poll_limit( $request );
        if ( $allowed instanceof WP_Error ) {
            return $allowed;
        }
        $thread_id = (int) $request['id'];
        $since_id  = (int) ( $request['since_id'] ?? 0 );
        $limit     = (int) ( $request['limit'] ?? 50 );

        return $this->run(
            fn (): array => $this->messaging->messages(
                $this->user_id(),
                $thread_id,
                $since_id,
                $limit > 0 ? $limit : 50
            )
        );
    }

    private function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed = $this->rateLimit( $request, 'msg_send_' . $this->user_id(), self::SEND_PER_MINUTE, MINUTE_IN_SECONDS );
        if ( $allowed instanceof WP_Error ) {
            return $allowed;
        }
        $thread_id = (int) $request['id'];
        $body      = $this->body( $request )['body'] ?? null;

        return $this->run( fn (): array => $this->messaging->send( $this->user_id(), $thread_id, $body ), 201 );
    }

    private function read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed = $this->poll_limit( $request );
        if ( $allowed instanceof WP_Error ) {
            return $allowed;
        }
        $thread_id = (int) $request['id'];

        return $this->run( fn (): array => $this->messaging->mark_read( $this->user_id(), $thread_id ) );
    }

    // ================= Helpers =================

    private function permission( WP_REST_Request $request ): bool|WP_Error {
        $nonce = $this->requireNonce( $request );
        if ( $nonce instanceof WP_Error ) {
            return $nonce;
        }
        if ( $this->user_id() <= 0 ) {
            return $this->error( 'CLINIC_UNAUTHORIZED', 401, 'وارد نشده اید' );
        }

        try {
            $this->messaging->authorize_actor( $this->user_id() );
        } catch ( MessagingException ) {
            // This route has no safe unscoped mode. Collapse missing Clinic,
            // membership, and capability state into the same denial envelope.
            return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید' );
        }

        return true;
    }

    private function poll_limit( WP_REST_Request $request ): bool|WP_Error {
        // The shared limiter is keyed by actor and window, never by a
        // client-supplied identifier.
        $result = $this->rateLimit( $request, 'msg_read_' . $this->user_id(), self::READ_PER_MINUTE, MINUTE_IN_SECONDS );

        return $result instanceof WP_Error ? $result : true;
    }

    private function user_id(): int {
        return (int) get_current_user_id();
    }

    /**
     * @return array<string, mixed>
     */
    private function body( WP_REST_Request $request ): array {
        $params = $request->get_json_params();
        if ( is_array( $params ) ) {
            return $params;
        }
        $params = $request->get_params();

        return is_array( $params ) ? $params : [];
    }

    /**
     * @param callable(): array<string, mixed> $callback
     */
    private function run( callable $callback, int $status = 200 ): WP_REST_Response|WP_Error {
        try {
            return $this->success( $callback(), $status );
        } catch ( MessagingException $exception ) {
            return $this->error( $exception->error_code, $exception->http_status, $exception->getMessage(), $exception->data );
        } catch ( \Throwable $exception ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention; no request data is logged.
            error_log( '[CPMS][MessagingController] unexpected: ' . get_class( $exception ) );

            return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
        }
    }
}
