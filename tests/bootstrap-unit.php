<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\Brain\Monkey\setUp();
register_shutdown_function(
	static function () {
		\Brain\Monkey\tearDown();
	}
);
