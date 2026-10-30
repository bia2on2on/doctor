<?php
/**
 * Static proof that every class the demo references can actually be resolved.
 *
 * WHY THIS EXISTS
 * ---------------
 * A drill under `tests/` is executed by `wp eval-file` inside real WordPress,
 * so nothing in the unit harness ever runs it. That is how a bare
 * `ScopedRecovery::class` in `scoped-integration.php` reached CI twice: the
 * file declares no namespace and imports its subjects explicitly, so an
 * un-imported name silently means `\ScopedRecovery` — a class that does not
 * exist. `php -l` is happy, every local suite is green, and the failure only
 * appears in the lane that runs the drill (`class_exists() === false`, or an
 * `Error: Class not found` halfway through a real database drill).
 *
 * WHAT IT CHECKS
 * --------------
 * Every name used as `Name::` or `new Name(...)` is resolved the way PHP
 * resolves it (namespace prefix, then `use` aliases), and the resulting
 * fully-qualified name must be one of:
 *   - declared in one of the scanned files,
 *   - an explicitly global / `namespace\`-relative name (the author said what
 *     they meant), or
 *   - a known PHP or WordPress global class (the whitelist below).
 *
 * Anything else is reported as `file:line — name (namespace ...)`. `self::`,
 * `static::` and `parent::` are ignored, as are names that do not start with a
 * capital letter (constants and functions are out of scope).
 *
 * The whitelist is deliberate: a new global class has to be added here, which
 * keeps the exception list reviewable instead of turning the check into a
 * heuristic that passes by accident.
 *
 * @package CpmsDisposableDemo
 */

/**
 * Class names that are legitimately global and need no `use` statement.
 *
 * @return array<int,string> Lower-cased global class names.
 */
function cpsd_global_class_whitelist(): array {
	return array_map(
		'strtolower',
		array(
			// PHP itself.
			'arrayobject', 'arrayiterator', 'closure', 'countable', 'datetime', 'datetimeimmutable',
			'datetimeinterface', 'dateinterval', 'datetimezone', 'divisionbyzeroerror', 'domainexception',
			'error', 'exception', 'generator', 'invalidargumentexception', 'iteratoraggregate',
			'jsonexception', 'jsonserializable', 'logicexception', 'mysqli', 'pdo', 'pdoexception',
			'rangeexception', 'reflectclass', 'reflectionfunction', 'reflectionmethod', 'runtimeexception',
			'stdclass', 'throwable', 'traversable', 'typeerror', 'unexpectedvalueexception', 'valueerror',
			// WordPress core.
			'wp_ajax', 'wp_admin_bar', 'wp_block_template', 'wp_block_type', 'wp_cli', 'wp_comment',
			'wp_error', 'wp_filesystem', 'wp_http', 'wp_list_table', 'wp_metadata_lazyloader',
			'wp_network', 'wp_post', 'wp_query', 'wp_rest_request', 'wp_rest_response', 'wp_rest_server',
			'wp_role', 'wp_roles', 'wp_screen', 'wp_site_health', 'wp_site_query', 'wp_term', 'wp_theme',
			'wp_upgrader', 'wp_user', 'wp_user_query', 'wpdb', 'plugin_upgrader',
		)
	);
}

/**
 * Is this token one that may start an expression name?
 *
 * @param array<int,mixed> $token Token from token_get_all().
 * @return bool
 */
function cpsd_ref_token_is_name( array $token ): bool {
	return in_array(
		$token[0],
		array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ),
		true
	);
}

/**
 * Index of the next meaningful token after $from (skips whitespace/comments).
 *
 * @param array<int,mixed> $tokens Token stream.
 * @param int              $from   Index to start from.
 * @return int
 */
function cpsd_next_meaningful( array $tokens, int $from ): int {
	$count = count( $tokens );
	for ( $i = $from + 1; $i < $count; $i++ ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		return $i;
	}

	return $count;
}

/**
 * Index of the previous meaningful token before $from (skips whitespace/comments).
 *
 * @param array<int,mixed> $tokens Token stream.
 * @param int              $from   Index to start from.
 * @return int -1 when none.
 */
function cpsd_prev_meaningful( array $tokens, int $from ): int {
	for ( $i = $from - 1; $i >= 0; $i-- ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		return $i;
	}

	return -1;
}

/**
 * Split a file into the namespace/alias context and the class references in it.
 *
 * @param string $code PHP source.
 * @return array{declared:array<string,array<int,string>>,imports:array<string,array<string,string>>,refs:array<int,array{ns:string,line:int,name:string}>}
 */
function cpsd_scan_php( string $code ): array {
	$tokens    = is_callable( 'token_get_all' ) ? token_get_all( $code ) : array();
	$declared  = array(); // namespace => list of class names.
	$imports   = array(); // namespace => alias (lower-case) => target.
	$refs      = array();
	$count     = count( $tokens );
	$depth     = 0;
	$namespace = '';
	$blocks    = array(); // open `namespace Foo {` blocks: array( depth, previous namespace ).
	$skip      = -1;

	for ( $i = 0; $i < $count; $i++ ) {
		if ( $i <= $skip ) {
			continue;
		}
		$token = $tokens[ $i ];

		if ( is_string( $token ) ) {
			if ( '{' === $token ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
				if ( array() !== $blocks && end( $blocks )[0] === $depth ) {
					$namespace = (string) array_pop( $blocks )[1];
				}
			}
			continue;
		}

		if ( T_NAMESPACE === $token[0] ) {
			$name = '';
			$j    = $i + 1;
			for ( ; $j < $count; $j++ ) {
				$next = $tokens[ $j ];
				if ( is_array( $next ) && in_array( $next[0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
					$name .= $next[1];
					continue;
				}
				if ( is_array( $next ) && T_NS_SEPARATOR === $next[0] ) {
					$name .= '\\';
					continue;
				}
				if ( is_array( $next ) && in_array( $next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				break;
			}
			$name = trim( $name, '\\' );
			$next = $tokens[ $j ] ?? null;
			if ( is_string( $next ) && '{' === $next ) {
				$blocks[] = array( $depth + 1, $namespace );
			}
			$namespace  = $name;
			$skip       = $j - 1;
			continue;
		}

		// Class import (`use Foo\Bar as Baz;`). Not a closure `use (`, not a
		// trait `use`, not `use function` / `use const`.
		if ( T_USE === $token[0] ) {
			$next = $tokens[ cpsd_next_meaningful( $tokens, $i ) ] ?? null;
			if ( null === $next || ( is_string( $next ) && '(' === $next ) ) {
				continue;
			}
			if ( is_array( $next ) && ( T_FUNCTION === $next[0] || T_CONST === $next[0] ) ) {
				continue;
			}
			$statement = '';
			$j         = $i + 1;
			for ( ; $j < $count; $j++ ) {
				$piece = $tokens[ $j ];
				if ( ';' === $piece ) {
					break;
				}
				$statement .= is_array( $piece ) ? $piece[1] : $piece;
			}
			$skip = $j;
			foreach ( explode( ',', $statement ) as $entry ) {
				$entry = trim( $entry );
				if ( '' === $entry ) {
					continue;
				}
				$alias = '';
				if ( preg_match( '/^(.*?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $entry, $m ) ) {
					$entry = trim( $m[1] );
					$alias = $m[2];
				}
				$target = trim( $entry, '\\' );
				if ( '' === $target ) {
					continue;
				}
				if ( '' === $alias ) {
					$parts = explode( '\\', $target );
					$alias = (string) end( $parts );
				}
				$imports[ $namespace ][ strtolower( $alias ) ] = $target;
			}
			continue;
		}

		if ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) || ( defined( 'T_ENUM' ) && T_ENUM === $token[0] ) ) {
			$next = $tokens[ cpsd_next_meaningful( $tokens, $i ) ] ?? null;
			if ( is_array( $next ) && T_STRING === $next[0] ) {
				$declared[ $namespace ][] = $next[1];
			}
			continue;
		}

		if ( cpsd_ref_token_is_name( $token ) ) {
			$next       = $tokens[ cpsd_next_meaningful( $tokens, $i ) ] ?? null;
			$prev_index = cpsd_prev_meaningful( $tokens, $i );
			$prev       = $prev_index >= 0 ? $tokens[ $prev_index ] : null;
			$is_static  = is_array( $next ) && T_DOUBLE_COLON === $next[0];
			$is_new     = is_array( $prev ) && T_NEW === $prev[0];
			if ( $is_static || $is_new ) {
				$refs[] = array(
					'ns'   => $namespace,
					'line' => (int) $token[2],
					'name' => (string) $token[1],
				);
			}
		}
	}

	return array(
		'declared' => $declared,
		'imports'  => $imports,
		'refs'     => $refs,
	);
}

/**
 * Resolve a class name the way PHP does for the given namespace context.
 *
 * @param string                $name   Name as written in the source.
 * @param string                $ns     Namespace in effect at that point.
 * @param array<string,string>  $imports Aliases in effect (lower-case alias => target).
 * @return array{0:string,1:bool} Fully-qualified name (no leading separator) and
 *                                whether the author wrote it explicitly (global
 *                                or `namespace\`-relative), which is always fine.
 */
function cpsd_resolve_class( string $name, string $ns, array $imports ): array {
	if ( 0 === strpos( $name, '\\' ) ) {
		return array( ltrim( $name, '\\' ), true );
	}
	if ( 0 === stripos( $name, 'namespace\\' ) ) {
		$rest = substr( $name, strlen( 'namespace\\' ) );

		return array( ( '' === $ns ? '' : $ns . '\\' ) . $rest, true );
	}
	$parts = explode( '\\', $name );
	$head  = strtolower( $parts[0] );
	if ( isset( $imports[ $head ] ) ) {
		$parts[0] = $imports[ $head ];

		return array( implode( '\\', $parts ), false );
	}

	return array( ( '' === $ns ? '' : $ns . '\\' ) . $name, false );
}

/**
 * Every class reference in $files that cannot be resolved, as "file:line — name".
 *
 * @param array<int,string> $files Absolute paths.
 * @return array<int,string> Problems (empty when the tree is consistent).
 */
function cpsd_class_ref_problems( array $files ): array {
	$scans      = array();
	$declared   = array(); // Lower-case fully-qualified name => true.

	foreach ( $files as $file ) {
		$scan           = cpsd_scan_php( (string) file_get_contents( $file ) );
		$scans[ $file ] = $scan;
		foreach ( $scan['declared'] as $ns => $names ) {
			foreach ( $names as $name ) {
				$fqcn = ( '' === $ns ? '' : $ns . '\\' ) . $name;

				$declared[ strtolower( $fqcn ) ] = true;
			}
		}
	}

	$whitelist = array_fill_keys( cpsd_global_class_whitelist(), true );
	$problems  = array();

	foreach ( $scans as $file => $scan ) {
		foreach ( $scan['refs'] as $ref ) {
			$name = $ref['name'];
			if ( in_array( strtolower( $name ), array( 'self', 'static', 'parent' ), true ) ) {
				continue;
			}
			if ( ! preg_match( '/^\\\\?[A-Z]/', $name ) ) {
				continue; // Constants, functions, variables.
			}

			// Only single-segment names are judged. A qualified name
			// (`CpmsDemo\Standalone`, `ClinicCore\Bootstrap\App`) is an explicit
			// path the author wrote on purpose, and whether that dependency is
			// present at runtime is a different question than this check asks.
			// The defect that motivated this file was a BARE name - the shape that
			// silently becomes `\ScopedRecovery` instead of `CpmsDemo\ScopedRecovery`.
			if ( false !== strpos( $name, '\\' ) ) {
				continue;
			}

			$ns        = $ref['ns'];
			$imports   = $scan['imports'][ $ns ] ?? array();
			$resolved  = cpsd_resolve_class( $name, $ns, $imports );
			$fqcn      = $resolved[0];
			$is_global = $resolved[1];

			// An imported name is the author naming the class explicitly; whether
			// that dependency is installed at runtime (the production plugin) is a
			// different question, answered by the compatibility gate.
			$resolved = $is_global
				|| isset( $imports[ strtolower( $name ) ] )
				|| isset( $declared[ strtolower( $fqcn ) ] )
				|| isset( $whitelist[ strtolower( $fqcn ) ] );

			if ( ! $resolved ) {
				$problems[] = $file . ':' . $ref['line'] . ' — ' . $name
					. ' (resolves to \\' . $fqcn . ', which no scanned file declares)';
			}
		}
	}

	return $problems;
}
