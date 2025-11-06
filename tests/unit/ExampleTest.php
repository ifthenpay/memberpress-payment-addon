<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function Brain\Monkey\Functions\expect;

final class ExampleTest extends TestCase {

	protected function setUp(): void {
		\Brain\Monkey\setUp(); }
	protected function tearDown(): void {
		\Brain\Monkey\tearDown(); }

	public function test_it_mocks_a_wp_function(): void {
		expect( 'get_option' )->once()->with( 'my_key' )->andReturn( 'ok' );
		$this->assertSame( 'ok', get_option( 'my_key' ) );
	}
}
