<?php
error_log( '[wp-tests-config] using DB=' . ( getenv( 'WP_TEST_DB_NAME' ) ?: 'wordpress_test' ) . ' CORE_DIR=' . ( getenv( 'WP_CORE_DIR' ) ?: 'tests/.wp-core' ) );

// ----- Core location (we use the self-contained tests/.wp-core you created) -----
$coreEnv = getenv( 'WP_CORE_DIR' ) ?: 'tests/.wp-core';
$coreDir = realpath( __DIR__ . '/../' . trim( $coreEnv, '/\\' ) );
if ( ! $coreDir || ! is_file( $coreDir . '/wp-settings.php' ) ) {
	fwrite( STDERR, "[wp-tests-config] WordPress core not found at: {$coreDir}\n" );
	exit( 1 );
}
defined( 'WP_CORE_DIR' ) || define( 'WP_CORE_DIR', $coreDir );
defined( 'ABSPATH' ) || define( 'ABSPATH', rtrim( WP_CORE_DIR, '/\\' ) . '/' );

// ----- Required WP test constants -----
defined( 'WP_TESTS_DOMAIN' ) || define( 'WP_TESTS_DOMAIN', 'example.org' );
defined( 'WP_TESTS_EMAIL' ) || define( 'WP_TESTS_EMAIL', 'admin@example.org' );
defined( 'WP_TESTS_TITLE' ) || define( 'WP_TESTS_TITLE', 'Test Blog' );
defined( 'WP_PHP_BINARY' ) || define( 'WP_PHP_BINARY', PHP_BINARY );

// ----- DB (matches your compose) -----
define( 'DB_NAME', getenv( 'WP_TEST_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TEST_DB_USER' ) ?: 'wpuser' );
define( 'DB_PASSWORD', getenv( 'WP_TEST_DB_PASS' ) ?: 'wppass' );
define( 'DB_HOST', getenv( 'WP_TEST_DB_HOST' ) ?: 'iftp-db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';

// ----- Dummy salts -----
foreach ( array(
	'AUTH_KEY',
	'SECURE_AUTH_KEY',
	'LOGGED_IN_KEY',
	'NONCE_KEY',
	'AUTH_SALT',
	'SECURE_AUTH_SALT',
	'LOGGED_IN_SALT',
	'NONCE_SALT',
) as $k ) {
	defined( $k ) || define( $k, 'test' ); }

define( 'WP_DEBUG', true );
