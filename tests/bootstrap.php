<?php
/**
 * PHPUnit-Bootstrap. Die Domänen- und Parser-Tests brauchen kein WordPress.
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress-Konstante, von Tests erwartet.
}
