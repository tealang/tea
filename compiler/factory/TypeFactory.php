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
	// static $_dict_key_type;

	static $_metatype;

	static $_uniontype;

	static $_any;
	static $_none;
	static $_default_marker; // use NoneType current

	static $_void;
	static $_object;

	// static $_scalar;
	static $_string;
	static $_float;
	static $_int;
	static $_uint;
	static $_bool;

	static $_iterable;
	static $_array;
	static $_dict;
	static $_chan;

	static $_xview;
	static $_regex;

	static $_callable;
	static $_namespace;

	static $_iiterator;
	static $_yield_generator;

	// for check XView accepts
	static $_iview_symbol;

	// for check Iterable type accepts
	static $_iiterator_symbol;
	static $_yield_generator_symbol;

	private static $type_map = [];

	static function init()
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
		self::$_string = self::create_type(StringType::class);
		self::$_float = self::create_type(FloatType::class);
		self::$_int = self::create_type(IntType::class);
		self::$_uint = self::create_type(UIntType::class);
		self::$_bool = self::create_type(BoolType::class);

		self::$_iterable = self::create_type(IterableType::class);
		self::$_array = self::create_type(ArrayType::class);
		self::$_dict = self::create_type(DictType::class);
		self::$_chan = self::create_type(ChanType::class);

		self::$_xview = self::create_type(XViewType::class);
		self::$_regex = self::create_type(RegexType::class);

		self::$_callable = self::create_type(CallableType::class);
		self::$_namespace = self::create_type(NamespaceType::class);

		// self::$_dict_key_type = self::create_union_type([self::$_string, self::$_int]);

		self::$_iiterator = new ClassKindredIdentifier('IIterator');
		self::$_yield_generator = self::$_iiterator;
		// self::$_yield_generator = new ClassKindredIdentifier('YieldGenerator');
	}

	static function is_iterable_type(IType $type)
	{
		if ($type === TypeFactory::$_any || $type instanceof IterableType) {
			$result = true;
		}
		elseif ($type instanceof PlainIdentifier) {
			$result = $type->symbol->declaration->is_same_or_based_with_symbol(self::$_iiterator_symbol);
		}
		elseif ($type instanceof UnionType) {
			$result = true;
			foreach ($type->types as $member_type) {
				if (!TypeFactory::is_iterable_type($member_type)) {
					$result = false;
					break;
				}
			}
		}
		else {
			$result = false;
		}

		return $result;
	}

	static function is_dict_key_directly_supported_type(?IType $type)
	{
		// 一些类型值作为下标调用时，PHP中有一些隐式的转换规则，这些规则往往不那么清晰，故不能把这些用于key
		// 如false用作下标时将转换为0，但直接转成string时是''
		// 而0.1用作下标时将转换为0，但实际情况可能需要的是'0.1'

		if ($type === self::$_string || $type === self::$_uint || $type === self::$_int) {
			return true;
		}
		elseif ($type instanceof StringType || $type instanceof IntType) {
			return true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->types as $member_type) {
				if (!TypeFactory::is_dict_key_directly_supported_type($member_type)) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	static function is_case_testable_type(?IType $type)
	{
		return $type === self::$_int
			|| $type === self::$_uint
			|| $type === self::$_string;
	}

	static function is_number_type(?IType $type)
	{
		if ($type instanceof IntType || $type instanceof UIntType || $type instanceof FloatType) {
			return true;
		}

		if ($type instanceof UnionType) {
			foreach ($type->types as $subtype) {
				if (!static::is_number_type($subtype)) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	static function set_symbols(Unit $unit)
	{
		foreach (self::$type_map as $type_name => $object) {
			if (isset($unit->symbols[$type_name])) {
				$object->symbol = $unit->symbols[$type_name];
			}
		}

		self::$_iview_symbol = $unit->symbols['IView'];
		self::$_iiterator_symbol = $unit->symbols['IIterator'];
		// self::$_yield_generator_symbol = $unit->symbols['YieldGenerator'];
		self::$_yield_generator_symbol = self::$_iiterator_symbol;
	}

	static function exists_type(string $name): bool
	{
		return isset(self::$type_map[$name]);
	}

	static function get_type(string $name)
	{
		return self::$type_map[$name] ?? null;
	}

	static function clone_type(string $name)
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

	static function create_collector_type(IType $generic_type)
	{
		$type = new ArrayType($generic_type);
		$type->symbol = self::$_array->symbol;
		$type->is_collect_mode = true;

		return $type;
	}

	static function create_array_type(IType $generic_type)
	{
		$type = new ArrayType($generic_type);
		$type->symbol = self::$_array->symbol;

		return $type;
	}

	static function create_dict_type(IType $generic_type)
	{
		$type = new DictType($generic_type);
		$type->symbol = self::$_dict->symbol;

		return $type;
	}

	static function create_chan_type(IType $generic_type)
	{
		$type = new ChanType($generic_type);
		$type->symbol = self::$_chan->symbol;

		return $type;
	}

	static function create_callable_type(?IType $return_type, array $parameters = null)
	{
		$type = new CallableType($return_type, $parameters);
		$type->symbol = self::$_callable->symbol;

		return $type;
	}

	static function create_meta_type(IType $generic_type)
	{
		$type = new MetaType($generic_type);
		$type->symbol = self::$_metatype->symbol;

		return $type;
	}

	static function create_union_type(array $members)
	{
		$type = new UnionType($members);
		$type->symbol = self::$_uniontype->symbol;

		return $type;
	}
}
