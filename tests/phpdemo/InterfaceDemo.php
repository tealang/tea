<?php
namespace tests\phpdemo;

interface BaseInterface {
	public function get_class_name(string $caller = 'anonymous'): string;
}

interface Interface1 extends BaseInterface {
	public const LINE = __LINE__;
	public static function get_target_class_methods(string $class): array;
}

