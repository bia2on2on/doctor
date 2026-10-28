<?php
/**
 * Real-WordPress, real-MySQL acceptance for the two Iran V1 (schema 0030)
 * demo-required features: strictly-private staff messaging and doctor-managed
 * visit pricing.
 *
 * This file is not a runner. It defines ONE function that both integration
 * harnesses call while their freshly-provisioned fixture is still live:
 *
 *   tools/disposable-demo-installer/tests/integration-wp.php  (strict mode)
 *   tools/disposable-demo-installer/tests/scoped-integration.php (scoped mode)
 *
 * Every assertion goes through the production service the Staff Portal calls
 * (`StaffMessagingService`, `VisitPricingService`, `FinanceService`), inside the
 * fixture's own `ScopeContext`. Nothing is mocked: the rows are real, the
 * denials are the production authorization engine's, and the invoice is issued
 * by the existing finance engine.
 *
 * @package CpmsDisposableDemo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assert one property. Throws so the caller's report names the first failure.
 *
 * @param bool   $ok    Condition.
 * @param string $label Evidence label.
 * @return void
 * @throws RuntimeException When the condition is false.
 */
function cpdd_feature_check( bool $ok, string $label ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	echo 'PASS ' . $label . PHP_EOL;
}

/**
 * Run $callback and return the demo/CPMS error code it raised, or '' if it
 * raised nothing. Authorization-safe behaviour is asserted on the CODE (and on
 * the HTTP status where the service exposes one), never on a message string.
 *
 * @param callable():void $callback Operation expected to fail.
 * @return array{code:string,status:int,class:string}
 */
function cpdd_feature_failure( callable $callback ): array {
	try {
		$callback();
	} catch ( Throwable $error ) {
		$code   = '';
		$status = 0;
		if ( method_exists( $error, 'demo_code' ) ) {
			$code = (string) $error->demo_code();
		}
		foreach ( array( 'error_code', 'errorCode' ) as $property ) {
			if ( '' === $code && isset( $error->{$property} ) ) {
				$code = (string) $error->{$property};
			}
		}
		if ( isset( $error->http_status ) ) {
			$status = (int) $error->http_status;
		} elseif ( isset( $error->httpStatus ) ) {
			$status = (int) $error->httpStatus;
		}

		return array( 'code' => $code, 'status' => $status, 'class' => get_class( $error ) );
	}

	return array( 'code' => '', 'status' => 0, 'class' => '' );
}

/**
 * @param array<string,mixed> $billing Billing projection.
 * @param string              $key     Field.
 * @return int
 */
function cpdd_feature_int( array $billing, string $key ): int {
	return isset( $billing[ $key ] ) && null !== $billing[ $key ] ? (int) $billing[ $key ] : 0;
}

/**
 * Full messaging + pricing acceptance against a live fixture.
 *
 * @param \CpmsDemo\Platform      $platform Adapter (production services).
 * @param array<string,mixed>     $fixture  Verified, sealed manifest.
 * @param string                  $mode     'strict' or 'scoped' (evidence only).
 * @return array<string,mixed> Evidence for the caller's JSON report.
 */
function cpdd_feature_suite( \CpmsDemo\Platform $platform, array $fixture, string $mode ): array {
	$ids       = (array) ( $fixture['ids'] ?? array() );
	$clinic_id = (int) ( $ids['clinic_id'] ?? 0 );
	$org_id    = (int) ( $ids['organization_id'] ?? 0 );
	$locations = array_values( array_map( 'intval', (array) ( $ids['location_ids'] ?? array() ) ) );
	$location  = $locations[0] ?? 0;
	$users     = (array) ( $ids['user_ids'] ?? array() );

	$doctor     = (int) ( $users['doctor'] ?? 0 );
	$doctor_two = (int) ( $users['doctor_second'] ?? 0 );
	$reception  = (int) ( $users['receptionist'] ?? 0 );
	$reception_two = (int) ( $users['receptionist_second'] ?? 0 );
	$accountant = (int) ( $users['accountant'] ?? 0 );

	$threads = (array) ( $ids['message_thread_ids'] ?? array() );
	$thread_private = (int) ( $threads['doctor_receptionist'] ?? 0 );
	$visit_pricing  = (int) ( $ids['visit_id_pricing'] ?? 0 );
	$fixture_id     = (string) ( $fixture['fixture_id'] ?? '' );

	$evidence = array(
		'mode'    => $mode,
		'clinic_id' => $clinic_id,
		'visit_id_pricing' => $visit_pricing,
		'threads' => array_map( 'intval', $threads ),
	);

	cpdd_feature_check( $clinic_id > 0 && $org_id > 0 && $location > 0, 'feature fixture: clinic, organization and location recorded' );
	cpdd_feature_check( $doctor > 0 && $doctor_two > 0 && $reception > 0 && $reception_two > 0 && $accountant > 0, 'feature fixture: two doctors, two receptionists and one finance user recorded' );
	cpdd_feature_check( 3 === count( $threads ), 'feature fixture: three private staff threads recorded' );
	cpdd_feature_check( $visit_pricing > 0, 'feature fixture: pricing visit recorded' );

	$previous = $platform->set_scope( $clinic_id, $location, $org_id );
	try {
		// ============================================================ MESSAGING
		$doctor_threads = $platform->message_threads( $doctor );
		$doctor_ids     = array_map(
			static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ),
			(array) ( $doctor_threads['threads'] ?? array() )
		);
		// Three threads exist, but the doctor is a participant of exactly TWO of
		// them: the third is receptionist <-> finance. That asymmetry is the
		// whole point, so it is asserted rather than glossed over.
		cpdd_feature_check( 2 === count( $doctor_ids ), 'messaging: the doctor is a participant of exactly two of the three fixture threads' );
		cpdd_feature_check(
			array( (int) $threads['doctor_receptionist'], (int) $threads['doctor_finance'] ) === $doctor_ids
			|| array( (int) $threads['doctor_finance'], (int) $threads['doctor_receptionist'] ) === $doctor_ids,
			'messaging: the doctor sees exactly the doctor<->receptionist and doctor<->finance threads'
		);
		cpdd_feature_check( ! in_array( (int) $threads['receptionist_finance'], $doctor_ids, true ), 'messaging: the receptionist<->finance thread is NOT visible to the doctor' );
		cpdd_feature_check(
			2 === count( (array) ( $platform->message_threads( $accountant )['threads'] ?? array() ) ),
			'messaging: the finance user sees exactly the two threads they participate in'
		);
		cpdd_feature_check(
			2 === count( (array) ( $platform->message_threads( $reception )['threads'] ?? array() ) ),
			'messaging: the receptionist sees exactly the two threads they participate in'
		);

		// The thread is strictly two-person, proven from the database.
		$participant_rows = $platform->fetch_all(
			'SELECT wp_user_id FROM ' . $platform->table( 'cpms_message_participants' ) . ' WHERE thread_id = %d ORDER BY wp_user_id ASC',
			array( $thread_private )
		);
		$participant_ids  = array_map( static fn ( array $row ): int => (int) $row['wp_user_id'], $participant_rows );
		sort( $participant_ids );
		$expected_pair = array( $doctor, $reception );
		sort( $expected_pair );
		cpdd_feature_check( $expected_pair === $participant_ids, 'messaging: the private thread has exactly the doctor and ONE receptionist as participants' );
		cpdd_feature_check( ! in_array( $reception_two, $participant_ids, true ), 'messaging: the second receptionist is NOT a participant of the private thread' );
		cpdd_feature_check( ! in_array( $doctor_two, $participant_ids, true ), 'messaging: the second doctor is NOT a participant of the private thread' );

		// The non-participant colleague sees no thread and no unread count.
		$second_reception_threads = $platform->message_threads( $reception_two );
		$second_reception_ids     = array_map(
			static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ),
			(array) ( $second_reception_threads['threads'] ?? array() )
		);
		cpdd_feature_check( array() === array_intersect( array_map( 'intval', $threads ), $second_reception_ids ), 'messaging: the second receptionist sees none of the private fixture threads' );
		cpdd_feature_check( 0 === (int) ( $second_reception_threads['unread_total'] ?? -1 ), 'messaging: the second receptionist unread total is exactly 0 (no leak)' );

		$second_doctor_threads = $platform->message_threads( $doctor_two );
		cpdd_feature_check( array() === (array) ( $second_doctor_threads['threads'] ?? array() ), 'messaging: the second doctor sees no thread at all' );
		cpdd_feature_check( 0 === (int) ( $second_doctor_threads['unread_total'] ?? -1 ), 'messaging: the second doctor unread total is exactly 0 (no leak)' );

		// Guessing a thread id is authorization-safe: the same 404 the production
		// service returns for a thread that does not exist, never a 403 that would
		// confirm the thread exists.
		foreach ( array(
			'second receptionist' => $reception_two,
			'second doctor'       => $doctor_two,
			'finance counterpart' => $accountant,
		) as $label => $intruder ) {
			$denied = cpdd_feature_failure(
				static function () use ( $platform, $intruder, $thread_private ): void {
					$platform->message_list( $intruder, $thread_private, 0 );
				}
			);
			cpdd_feature_check(
				'CLINIC_NOT_FOUND' === $denied['code'] && 404 === $denied['status'],
				'messaging: the ' . $label . ' reading the private thread is refused with the non-revealing 404 (got ' . ( '' === $denied['code'] ? 'no error' : $denied['code'] . '/' . $denied['status'] ) . ')'
			);
		}

		$guessed = cpdd_feature_failure(
			static function () use ( $platform, $doctor, $thread_private ): void {
				$platform->message_list( $doctor, $thread_private + 5000, 0 );
			}
		);
		cpdd_feature_check(
			'CLINIC_NOT_FOUND' === $guessed['code'] && 404 === $guessed['status'],
			'messaging: a guessed thread id returns the same non-revealing 404'
		);

		// The two participants do read the same conversation.
		$doctor_view = $platform->message_list( $doctor, $thread_private, 0 );
		$reception_view = $platform->message_list( $reception, $thread_private, 0 );
		cpdd_feature_check( 2 === count( (array) ( $doctor_view['messages'] ?? array() ) ), 'messaging: the doctor reads both seeded messages' );
		cpdd_feature_check(
			count( (array) ( $doctor_view['messages'] ?? array() ) ) === count( (array) ( $reception_view['messages'] ?? array() ) ),
			'messaging: the receptionist reads the same conversation'
		);

		// A new message raises ONLY the recipient's unread counter.
		$platform->send_message( $doctor, $thread_private, 'پیام تازهٔ آزمایشی برای سنجش شمارندهٔ خوانده‌نشده.' );
		$reception_after = $platform->message_threads( $reception );
		cpdd_feature_check( (int) ( $reception_after['unread_total'] ?? 0 ) >= 1, 'messaging: the recipient unread total rises after a new message' );
		$second_after = $platform->message_threads( $reception_two );
		cpdd_feature_check( 0 === (int) ( $second_after['unread_total'] ?? -1 ), 'messaging: the non-participant receptionist unread total stays exactly 0' );
		$second_doctor_after = $platform->message_threads( $doctor_two );
		cpdd_feature_check( 0 === (int) ( $second_doctor_after['unread_total'] ?? -1 ), 'messaging: the non-participant doctor unread total stays exactly 0' );

		$read = $platform->mark_thread_read( $reception, $thread_private );
		cpdd_feature_check( 0 === (int) ( $read['unread_count'] ?? -1 ), 'messaging: mark-read clears the recipient counter for that thread only' );

		$evidence['messaging'] = array(
			'doctor_threads'            => $doctor_ids,
			'second_doctor_threads'     => count( (array) ( $second_doctor_threads['threads'] ?? array() ) ),
			'second_reception_threads'  => count( $second_reception_ids ),
		);

		// ============================================================== PRICING
		$pricing_default = \CpmsDemo\Provisioner::PRICING_DEFAULT_FEE;
		$pricing_changed = \CpmsDemo\Provisioner::PRICING_CHANGED_FEE;
		$pricing_override = \CpmsDemo\Provisioner::PRICING_OVERRIDE_FEE;
		$charge_unit      = \CpmsDemo\Provisioner::PRICING_CHARGE_UNIT;
		$charge_quantity  = \CpmsDemo\Provisioner::PRICING_CHARGE_QUANTITY;

		$context = $platform->pricing_context( $doctor_two );
		cpdd_feature_check( $pricing_changed === (int) ( $context['default_fee'] ?? 0 ), 'pricing: the doctor default fee is the LATEST configured value (' . $pricing_changed . ')' );

		$billing = $platform->visit_billing( $doctor_two, $visit_pricing );
		cpdd_feature_check( $pricing_default === cpdd_feature_int( $billing, 'snapshot_fee' ), 'pricing: the established visit keeps the fee captured at check-in (' . $pricing_default . ')' );
		cpdd_feature_check( $pricing_default === cpdd_feature_int( $billing, 'effective_fee' ), 'pricing: changing the doctor default did NOT reprice the established visit' );
		cpdd_feature_check( 'checkin_snapshot' === (string) ( $billing['fee_source'] ?? '' ), 'pricing: the fee source is the check-in snapshot' );
		cpdd_feature_check( $pricing_changed !== cpdd_feature_int( $billing, 'effective_fee' ), 'pricing: the later default is NOT the applied fee' );

		// Unauthorized pricing changes are denied by the production engine.
		$reception_default = cpdd_feature_failure(
			static function () use ( $platform, $reception, $pricing_changed ): void {
				$platform->set_default_consultation_fee( $reception, null, $pricing_changed + 1 );
			}
		);
		cpdd_feature_check( 'CLINIC_PERMISSION_DENIED' === $reception_default['code'], 'pricing: the receptionist cannot set a doctor default fee (denied)' );

		$finance_default = cpdd_feature_failure(
			static function () use ( $platform, $accountant, $pricing_changed ): void {
				$platform->set_default_consultation_fee( $accountant, null, $pricing_changed + 1 );
			}
		);
		cpdd_feature_check( 'CLINIC_PERMISSION_DENIED' === $finance_default['code'], 'pricing: the finance user cannot set a doctor default fee (denied)' );

		$other_doctor_visit = cpdd_feature_failure(
			static function () use ( $platform, $doctor, $visit_pricing ): void {
				$platform->set_visit_consultation_fee( $doctor, $visit_pricing, 999999 );
			}
		);
		cpdd_feature_check( 'CLINIC_PERMISSION_DENIED' === $other_doctor_visit['code'], 'pricing: another doctor cannot price a visit that is not theirs (denied)' );

		$reception_override = cpdd_feature_failure(
			static function () use ( $platform, $reception, $visit_pricing ): void {
				$platform->set_visit_consultation_fee( $reception, $visit_pricing, 999999 );
			}
		);
		cpdd_feature_check( 'CLINIC_PERMISSION_DENIED' === $reception_override['code'], 'pricing: the receptionist cannot override a visit fee (denied)' );

		// Authorized per-visit override.
		$platform->set_visit_consultation_fee( $doctor_two, $visit_pricing, $pricing_override );
		$billing = $platform->visit_billing( $doctor_two, $visit_pricing );
		cpdd_feature_check( $pricing_override === cpdd_feature_int( $billing, 'override_fee' ), 'pricing: the doctor per-visit override is stored (' . $pricing_override . ')' );
		cpdd_feature_check( $pricing_override === cpdd_feature_int( $billing, 'effective_fee' ), 'pricing: the override replaces the snapshot as the applied fee' );
		cpdd_feature_check( 'visit_override' === (string) ( $billing['fee_source'] ?? '' ), 'pricing: the fee source becomes the visit override' );

		// Itemized charges: add, then edit.
		$charge_id = $platform->add_visit_charge(
			$doctor_two,
			$visit_pricing,
			array(
				'description' => 'خدمت درمانی آزمایشی — سونوگرافی فرضی',
				'amount'      => $charge_unit,
				'quantity'    => 1,
			)
		);
		cpdd_feature_check( $charge_id > 0, 'pricing: the doctor adds an itemized treatment charge' );
		$billing = $platform->visit_billing( $doctor_two, $visit_pricing );
		cpdd_feature_check( $charge_unit === cpdd_feature_int( $billing, 'charges_total' ), 'pricing: the charge total equals the single unit amount' );

		$platform->update_visit_charge( $doctor_two, $visit_pricing, $charge_id, array( 'quantity' => $charge_quantity ) );
		$billing = $platform->visit_billing( $doctor_two, $visit_pricing );
		$expected_charges = (int) round( $charge_unit * $charge_quantity );
		cpdd_feature_check( $expected_charges === cpdd_feature_int( $billing, 'charges_total' ), 'pricing: editing the charge quantity updates the line total (' . $expected_charges . ')' );

		// The receptionist sees the SAME breakdown and cannot edit it.
		$reception_billing = $platform->visit_billing( $reception, $visit_pricing );
		cpdd_feature_check( $pricing_override === cpdd_feature_int( $reception_billing, 'effective_fee' ), 'pricing: the receptionist sees the applied doctor fee' );
		cpdd_feature_check( $expected_charges === cpdd_feature_int( $reception_billing, 'charges_total' ), 'pricing: the receptionist sees the itemized charges' );
		$expected_total = $pricing_override + $expected_charges;
		cpdd_feature_check( $expected_total === cpdd_feature_int( $reception_billing, 'expected_total' ), 'pricing: the receptionist sees the correct expected total (' . $expected_total . ')' );
		cpdd_feature_check( false === (bool) ( $reception_billing['can_edit_pricing'] ?? true ), 'pricing: the receptionist projection is read-only' );
		cpdd_feature_check( 1 === count( (array) ( $reception_billing['charges'] ?? array() ) ), 'pricing: the receptionist sees exactly the one itemized charge' );

		// Invoice through the EXISTING finance engine.
		$invoice_id = $platform->issue_invoice_from_pricing( $accountant, $visit_pricing );
		cpdd_feature_check( $invoice_id > 0, 'pricing: the finance user issues an invoice from the doctor pricing' );
		$invoice = (array) $platform->invoice_row( $invoice_id );
		cpdd_feature_check( $expected_total === (int) round( (float) ( $invoice['total'] ?? 0 ) ), 'pricing: the invoice total equals the doctor pricing (' . $expected_total . ')' );
		$items = (array) $platform->invoice_items( $invoice_id );
		cpdd_feature_check( 2 === count( $items ), 'pricing: the invoice has a consultation line and one treatment line' );
		$item_amounts = array_map( static fn ( array $row ): int => (int) round( (float) $row['amount'] ), $items );
		sort( $item_amounts );
		$expected_amounts = array( $pricing_override, $expected_charges );
		sort( $expected_amounts );
		cpdd_feature_check( $expected_amounts === $item_amounts, 'pricing: the invoice lines carry the override fee and the charge total' );

		$stamp = $platform->fetch_row(
			'SELECT invoiced_invoice_id FROM ' . $platform->table( 'cpms_visit_charge_items' ) . ' WHERE id = %d',
			array( $charge_id )
		);
		cpdd_feature_check( $invoice_id === (int) ( $stamp['invoiced_invoice_id'] ?? 0 ), 'pricing: the consumed charge is stamped with the invoice id' );

		// Partial payment, then an idempotent replay of the SAME key.
		$partial_key = 'cpdd-feature-' . substr( str_replace( '-', '', (string) wp_generate_uuid4() ), 0, 12 ) . '-partial';
		$partial     = (int) round( $expected_total * 0.3 );
		$payment_id  = $platform->record_payment(
			$accountant,
			$invoice_id,
			array( 'amount' => $partial, 'method' => 'cash', 'transaction_ref' => 'SYNTHETIC-FEATURE-PARTIAL' ),
			$partial_key
		);
		cpdd_feature_check( $payment_id > 0, 'pricing: a partial payment is recorded' );
		$invoice = (array) $platform->invoice_row( $invoice_id );
		cpdd_feature_check( $partial === (int) round( (float) ( $invoice['paid_amount'] ?? 0 ) ), 'pricing: the invoice paid amount equals the partial payment' );
		cpdd_feature_check( $expected_total - $partial === (int) round( (float) ( $invoice['balance'] ?? 0 ) ), 'pricing: the invoice balance equals the remainder' );

		$replay_id = $platform->record_payment(
			$accountant,
			$invoice_id,
			array( 'amount' => $partial, 'method' => 'cash', 'transaction_ref' => 'SYNTHETIC-FEATURE-PARTIAL' ),
			$partial_key
		);
		cpdd_feature_check( $payment_id === $replay_id, 'pricing: replaying the same idempotency key returns the SAME payment id' );
		$payments_after_replay = (array) $platform->invoice_payments( $invoice_id );
		cpdd_feature_check( 1 === count( $payments_after_replay ), 'pricing: the idempotent replay created no second payment row' );
		$invoice = (array) $platform->invoice_row( $invoice_id );
		cpdd_feature_check( $partial === (int) round( (float) ( $invoice['paid_amount'] ?? 0 ) ), 'pricing: the replay did not double-count the paid amount' );

		// Full settlement.
		$platform->record_payment(
			$accountant,
			$invoice_id,
			array(
				'amount'          => $expected_total - $partial,
				'method'          => 'card_pos',
				'transaction_ref' => 'SYNTHETIC-FEATURE-FINAL',
			),
			'cpdd-feature-' . substr( str_replace( '-', '', (string) wp_generate_uuid4() ), 0, 12 ) . '-final'
		);
		$invoice = (array) $platform->invoice_row( $invoice_id );
		cpdd_feature_check( 0 === (int) round( (float) ( $invoice['balance'] ?? -1 ) ), 'pricing: the invoice balance is zero after full settlement' );
		cpdd_feature_check( 'paid' === (string) ( $invoice['status'] ?? '' ), 'pricing: the invoice reaches the paid state' );

		// Finalized invoices are immutable and pricing is frozen.
		$frozen_override = cpdd_feature_failure(
			static function () use ( $platform, $doctor_two, $visit_pricing ): void {
				$platform->set_visit_consultation_fee( $doctor_two, $visit_pricing, 123456 );
			}
		);
		cpdd_feature_check( 'CLINIC_INVOICE_NOT_MODIFIABLE' === $frozen_override['code'], 'pricing: a fee override after finalization is refused (immutable)' );

		$frozen_charge = cpdd_feature_failure(
			static function () use ( $platform, $doctor_two, $visit_pricing ): void {
				$platform->add_visit_charge(
					$doctor_two,
					$visit_pricing,
					array( 'description' => 'قلم پس از نهایی‌سازی', 'amount' => 1000, 'quantity' => 1 )
				);
			}
		);
		cpdd_feature_check( 'CLINIC_INVOICE_NOT_MODIFIABLE' === $frozen_charge['code'], 'pricing: adding a charge after finalization is refused (immutable)' );

		$invoice_after = (array) $platform->invoice_row( $invoice_id );
		cpdd_feature_check( (int) round( (float) ( $invoice_after['total'] ?? 0 ) ) === $expected_total, 'pricing: the finalized invoice total never changed' );
		cpdd_feature_check( count( (array) $platform->invoice_items( $invoice_id ) ) === 2, 'pricing: the finalized invoice line set never changed' );

		// No fee-snapshot failure marker exists for this visit.
		$failure_rows = $platform->fetch_all(
			'SELECT visit_id FROM ' . $platform->table( 'cpms_visit_fee_snapshot_failures' ) . ' WHERE visit_id = %d',
			array( $visit_pricing )
		);
		cpdd_feature_check( array() === $failure_rows, 'pricing: no fee-snapshot failure marker exists for the priced visit' );

		$evidence['pricing'] = array(
			'default_fee'      => $pricing_changed,
			'snapshot_fee'     => $pricing_default,
			'override_fee'     => $pricing_override,
			'charges_total'    => $expected_charges,
			'invoice_id'       => $invoice_id,
			'invoice_total'    => $expected_total,
			'payments'         => count( (array) $platform->invoice_payments( $invoice_id ) ),
			'replay_payment_id' => $replay_id,
		);
	} finally {
		$platform->restore_scope( $previous );
	}

	return $evidence;
}
