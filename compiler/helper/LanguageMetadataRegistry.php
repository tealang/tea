<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class LanguageMetadataRegistry
{
	public const ATTRIBUTE_TYPE_ASSERTION = 'TypeAssertion';
	public const ATTRIBUTE_TRUSTED = 'Trusted';
	public const ATTRIBUTE_BUILTIN_FEATURE = 'BuiltinFeature';
	public const ATTRIBUTE_HTML_SAFE = 'HtmlSafe';

	public const INTRINSIC_ISSET = 'isset';
	public const INTRINSIC_EMPTY = 'empty';

	public static function get_php_predicate_asserted_type(?string $callee_name): ?BaseType
	{
		return match ($callee_name) {
			'is_array' => TypeFactory::$_generalized_array,
			'is_bool' => TypeFactory::$_bool,
			'is_float' => TypeFactory::$_float,
			'is_int' => TypeFactory::$_int,
			'is_string' => TypeFactory::$_string,
			default => null,
		};
	}

	public static function is_attribute_named(MetaAttribute $attribute, string $name): bool
	{
		return $attribute->identifier->name === $name;
	}
}
