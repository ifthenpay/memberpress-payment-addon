<?php
declare(strict_types=1);

// 0) Composer autoload (plugin-local)
require __DIR__ . '/../vendor/autoload.php';

// 1) Absolute path to tests config (no CWD surprises)
$cfgAbs = realpath( __DIR__ . '/wp-tests-config.php' );
if ( ! $cfgAbs ) {
	fwrite( STDERR, "[boot] Missing tests/wp-tests-config.php\n" );
	exit( 1 );
}
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $cfgAbs );

// 2) Locate wp-phpunit dir (plugin-local vendor)
$testsDir = realpath( __DIR__ . '/../vendor/wp-phpunit/wp-phpunit' );
if ( ! $testsDir || ! is_dir( $testsDir ) ) {
	fwrite( STDERR, "[boot] wp-phpunit not found in vendor\n" );
	exit( 1 );
}
putenv( 'WP_PHPUNIT__DIR=' . $testsDir );

// 3) Standard WP test bootstrap
require $testsDir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		// Load your plugin main file (adjust if name differs)
		require dirname( __DIR__ ) . '/ifthenpay-payments-for-memberpress.php';
	}
);

require $testsDir . '/includes/bootstrap.php';
