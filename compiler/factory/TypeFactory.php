<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeFactory
{
	// public static $_dict_key_type;

	public static $_metatype;

	public static $_uniontype;

	public static $_any;
	public static $_none;
	public static $_default_marker; // use NoneType current

	public static $_void;
	public static $_object;

	// static $_scalar;
	public static $_bytes;
	public static $_string;
	public static $_pure_string;
	public static $_float;
	public static $_int;
	public static $_uint;
	public static $_bool;

	public static $_iterable;
	public static $_array;
	public static $_dict;
	public static $_generalized_array; // for PHP array, same as Array|Dict

	public static $_xview;
	public static $_regex;

	public static $_callable;
	public static $_namespace;

	public static $_iterator;
	public static $_yield_generator;

	// for check accepts
	public static $_iview_symbol;

	// for check Iterable type accepts
	public static $_iterator_symbol;
	public static $_yield_generator_symbol;

	private static $type_map = [];

	public static function init()
	{
		// init all builtin types

		self::$_metatype = self::create_type(MetaType::class);
		self::$_uniontype = self::create_type(UnionType::class);

		self::$_void = self::create_type(VoidType::class);
		self::$_none = self::create_type(NoneType::class);
		self::$_default_marker = self::create_type(NoneType::class);

		self::$_any = self::create_type(AnyType::class);
		self::$_object = self::create_type(ObjectType::class);

		// self::$_scalar = self::create_type(ScalarType::class);
		self::$_bytes = self::create_type(BytesType::class);
		self::$_string = self::create_type(StringType::class);
		self::$_pure_string = self::create_type(PuresType::class);
		self::$_float = self::create_type(FloatType::class);
		self::$_int = self::create_type(IntType::class);
		self::$_uint = self::create_type(UIntType::class);
		self::$_bool = self::create_type(BoolType::class);

		self::$_iterable = self::create_type(IterableType::class);
		self::$_array = self::create_type(ArrayType::class);
		self::$_dict = self::create_type(DictType::class);
		self::$_generalized_array = self::create_union_type([self::$_array, self::$_dict]);
		self::$type_map[_GENERAL_ARRAY] = self::$_generalized_array;

		self::$_xview = self::create_type(XViewType::class);
		self::$_regex = self::create_type(RegexType::class);

		self::$_callable = self::create_type(CallableType::class);
		// self::$_namespace = self::create_type(NamespaceType::class);

		// self::$_dict_key_type = self::create_union_type([self::$_string, self::$_int]);

		self::$_iterator = new ClassKindredIdentifier('Iterator');
		// self::$_yield_generator = new ClassKindredIdentifier('YieldGenerator');
		self::$_yield_generator = self::$_iterator;
	}

	public static function find_iterator_identifier(IType $type)
	{
		if ($type->symbol === self::$_iterator_symbol) {
			$result = $type;
		}
		else {
			$result = $type->symbol->declaration->find_based_with_symbol(self::$_iterator_symbol);
		}

		return $result;
	}

	public static function set_symbols(Unit $unit)
	{
		foreach (self::$type_map as $type_name => $object) {
			if (isset($unit->symbols[$type_name])) {
				$object->symbol = $unit->symbols[$type_name];
			}
		}

		self::$_iview_symbol = $unit->symbols['IView'] ?? null;
		self::$_iterator_symbol = $unit->symbols['Iterator'] ?? null;
		// self::$_yield_generator_symbol = $unit->symbols['YieldGenerator'] ?? null;
		self::$_yield_generator_symbol = self::$_iterator_symbol;
	}

	public static function exists_type(string $name): bool
	{
		return isset(self::$type_map[$name]);
	}

	public static function get_type(string $name)
	{
		return self::$type_map[$name] ?? null;
	}

	public static function clone_type(string $name)
	{
		$type = self::$type_map[$name] ?? null;
		if ($type !== null) {
			$type = clone $type;
		}

		return $type;
	}

	private static function create_type(string $class)
	{
		$type_object = new $class();
		self::$type_map[$type_object->name] = $type_object;

		return $type_object;
	}

	public static function create_array_type(IType $generic_type)
	{
		$type = new ArrayType($generic_type);
		$type->symbol = self::$_array->symbol;

		return $type;
	}

	public static function create_dict_type(IType $generic_type)
	{
		$type = new DictType($generic_type);
		$type->symbol = self::$_dict->symbol;

		return $type;
	}

	public static function create_callable_type(?IType $return_type, array $parameters)
	{
		$type = new CallableType($return_type, $parameters);
		$type->symbol = self::$_callable->symbol;

		return $type;
	}

	public static function create_meta_type(IType $generic_type)
	{
		$type = new MetaType($generic_type);
		$type->symbol = self::$_metatype->symbol;

		return $type;
	}

	public static function create_union_type(array $members)
	{
		$type = new UnionType($members);
		$type->symbol = self::$_uniontype->symbol;

		return $type;
	}
}

// end
