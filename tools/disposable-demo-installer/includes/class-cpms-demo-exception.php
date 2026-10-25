<?php
/**
 * A refusal or a failed pre-condition. Messages are Persian, user-facing and
 * free of credentials, tokens and stack details.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Terminal, displayable refusal/error for the demo installer.
 */
class Exception extends \RuntimeException {

	/**
	 * Stable machine code, e.g. NONEMPTY_SITE.
	 *
	 * @var string
	 */
	private string $demo_code;

	/**
	 * @param string $demo_code Stable code (uppercase, no spaces).
	 * @param string $message   Persian, user-facing message.
	 */
	public function __construct( string $demo_code, string $message ) {
		parent::__construct( $message );
		$this->demo_code = $demo_code;
	}

	/**
	 * @return string
	 */
	public function demo_code(): string {
		return $this->demo_code;
	}
}
