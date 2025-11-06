<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExampleIntegrationTest extends TestCase {

	public function test_wordpress_booted(): void {
		$this->assertTrue( function_exists( 'do_action' ) );
		$this->assertSame( 'en_US', get_locale() );
	}
}
