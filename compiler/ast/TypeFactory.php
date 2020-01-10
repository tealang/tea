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
	const BUILTIN_TYPE_NAMES = [
		_ANY, _VOID, _NONE, // _NONE just a internal type
		_STRING, _INT, _UINT, _FLOAT, _BOOL, // maybe support Bytes?
		_ITERABLE, _DICT, _ARRAY, // maybe support Matrix, Tensor?
		_OBJECT, _XVIEW, _REGEX,
		_CALLABLE, _NAMESPACE,
		_METATYPE,
	];

	static $_metatype;

	static $_any;
	static $_none;
	static $_void;

	// static $_scalar;
	static $_string;
	static $_float;
	static $_int;
	static $_uint;
	static $_bool;

	static $_iterable;
	static $_array;
	static $_dict;

	static $_xview;
	static $_regex;

	static $_callable;
	// static $_class;
	static $_namespace;

	// for check XView accepts
	static $_iview_symbol;

	// for check Iterable type accepts
	static $_iterator_interface_symbol;
	static $_generator_interface_symbol;

	private static $type_map = [];

	static function init()
	{
		// init all builtin types

		self::$_metatype = self::create_type(MetaType::class);

		self::$_void = self::create_type(VoidType::class);
		self::$_none = self::create_type(NoneType::class);
		self::$_any = self::create_type(AnyType::class);

		// self::$_scalar = self::create_type(ScalarType::class);
		self::$_string = self::create_type(StringType::class);
		self::$_float = self::create_type(FloatType::class);
		self::$_int = self::create_type(IntType::class);
		self::$_uint = self::create_type(UIntType::class);
		self::$_bool = self::create_type(BoolType::class);

		self::$_iterable = self::create_type(IterableType::class);
		self::$_array = self::create_type(ArrayType::class);
		self::$_dict = self::create_type(DictType::class);

		self::$_xview = self::create_type(XViewType::class);
		self::$_regex = self::create_type(RegexType::class);

		self::$_callable = self::create_type(CallableType::class);
		self::$_namespace = self::create_type(NamespaceType::class);
	}

	static function is_iterable_type(IType $type)
	{
		if ($type === TypeFactory::$_any || $type instanceof IterableType) {
			return true;
		}

		if ($type->symbol === null) {
			dump($type);exit;
		}

		return $type->symbol->declaration->is_same_or_based_with_symbol(self::$_iterator_interface_symbol);
	}

	static function is_dict_key_directly_supported_type(?IType $type)
	{
		return $type === self::$_string
			|| $type === self::$_uint
			|| $type === self::$_int;
	}

	static function is_dict_key_castable_type(?IType $type)
	{
		// Data type convert is a problem
		// false to string in javascript is 'false', and in python is 'False', in PHP is '' ...

		return $type === self::$_int
			|| $type === self::$_uint
			|| $type === self::$_float
			|| $type === self::$_bool
			|| $type === self::$_any;
	}

	static function is_case_testable_type(?IType $type)
	{
		return $type === self::$_int
			|| $type === self::$_uint
			|| $type === self::$_string;
	}

	static function is_number_type(?IType $type)
	{
		return $type === self::$_int
			|| $type === self::$_uint
			|| $type === self::$_float;
	}

	static function set_symbols(Unit $unit)
	{
		foreach (static::$type_map as $type_name => $object) {
			if (isset($unit->symbols[$type_name])) {
				$object->symbol = $unit->symbols[$type_name];
			}
		}

		static::$_iview_symbol = $unit->symbols['IView'];
		static::$_iterator_interface_symbol = $unit->symbols['IteratorInterface'];
		static::$_generator_interface_symbol = $unit->symbols['GeneratorInterface'];
	}

	static function exists_type(string $name): bool
	{
		return isset(static::$type_map[$name]);
	}

	static function get_type(string $name)
	{
		return static::$type_map[$name] ?? null;
	}

	static function create_type(string $class = BaseType::class)
	{
		$type_object = new $class();
		static::$type_map[$type_object->name] = $type_object;

		return $type_object;
	}

	static function create_collector_type(IType $value_type)
	{
		$type = new ArrayType();
		$type->value_type = $value_type;
		$type->symbol = static::$_array->symbol;
		$type->is_collect_mode = true;

		return $type;
	}

	static function create_array_type(IType $value_type)
	{
		$type = new ArrayType();
		$type->value_type = $value_type;
		$type->symbol = static::$_array->symbol;

		return $type;
	}

	static function create_dict_type(IType $value_type)
	{
		$type = new DictType();
		$type->value_type = $value_type;
		$type->symbol = static::$_dict->symbol;

		return $type;
	}

	static function create_meta_type(IType $value_type)
	{
		$type = new MetaType();
		$type->value_type = $value_type;
		$type->symbol = static::$_metatype->symbol;

		return $type;
	}

	static function create_callable_type(?IType $return_type, ?array $parameters)
	{
		$type = new CallableType();
		$type->type = $return_type;
		$type->parameters = $parameters;
		$type->symbol = static::$_callable->symbol;

		return $type;
	}
}
