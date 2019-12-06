<?php
namespace tea\tests\PHPDemoUnit\NS1;

use tea\tests\PHPDemoUnit\Interface1;

class Demo implements Interface1 {
	public function get_class_name(string $caller = 'anonymous'): string {
		return __CLASS__;
	}

	public static function get_target_class_methods(string $class): array {
		return get_class_methods($class);
	}
}
