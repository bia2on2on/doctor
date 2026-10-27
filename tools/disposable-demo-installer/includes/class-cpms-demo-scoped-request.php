<?php
/**
 * Request authorization for the separate scoped demo mode.
 *
 * Inherits the strict POST / capability / nonce / phrase / site-fingerprint gate
 * from Request but uses distinct actions and phrases so a strict-disposable
 * confirmation can never authorize a scoped-site operation.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Scoped-mode request gates.
 */
class ScopedRequest extends Request {

	public const ACTION_ENABLE    = 'enable';
	public const ACTION_PROVISION = 'provision';
	public const ACTION_OTP       = 'otp';
	public const ACTION_DIAGNOSE  = 'diagnose';
	public const ACTION_TEARDOWN  = 'teardown';

	/**
	 * @return array<string,array{nonce:string,phrase:string,label:string}>
	 */
	public static function actions(): array {
		return array(
			self::ACTION_ENABLE    => array(
				'nonce'  => 'cpsd_enable',
				'phrase' => 'I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE',
				'label'  => 'فعالسازی حالت محدودشده',
			),
			self::ACTION_PROVISION => array(
				'nonce'  => 'cpsd_provision',
				'phrase' => 'CREATE_SCOPED_DEMO_ON_TEST_SITE',
				'label'  => 'ساخت دموی محدودشده',
			),
			self::ACTION_OTP       => array(
				'nonce'  => 'cpsd_otp',
				'phrase' => 'SHOW_SCOPED_DEMO_OTP',
				'label'  => 'ساخت کد OTP نمایشی',
			),
			self::ACTION_DIAGNOSE  => array(
				'nonce'  => 'cpsd_diagnose',
				'phrase' => 'RUN_READ_ONLY_SCOPED_DIAGNOSTIC',
				'label'  => 'پیشنمایش پاکسازی',
			),
			self::ACTION_TEARDOWN  => array(
				'nonce'  => 'cpsd_teardown',
				'phrase' => 'REMOVE_ONLY_SCOPED_DEMO_DATA',
				'label'  => 'پاکسازی فقط دادههای دمو',
			),
		);
	}
}
