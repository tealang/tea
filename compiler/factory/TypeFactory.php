<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeFactory
{
	public static $_dict_key;

	public static $_self;

	public static $_meta;

	public static $_union;
	public static $_intersection;

	public static $_any;
	public static $_none;
	public static $_invalid;
	public static $_default_marker; // use NoneType current

	public static $_void;
	public static $_mixed;
	public static $_object;

	// static $_scalar;
	public static $_bytes;
	public static $_string;
	public static $_plain;
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
	public static $_generator;

	public static $_int_types;
	public static $_int_and_string_types;

	public static $_exception_symbol;

	// for check accepts
	public static $_iview_symbol;

	// for check Iterable type accepts
	public static $_iterator_symbol;

	private static $_type_map = [];
	private static $_casting_map;

	public static function init()
	{
		// init all builtin types

		self::$_self = self::create_type(SelfType::class);

		self::$_meta = self::create_type(MetaType::class);
		self::$_union = self::create_type(UnionType::class);
		self::$_intersection = self::create_type(IntersectionType::class);

		self::$_void = self::create_type(VoidType::class);
		self::$_none = self::create_type(NoneType::class);
		self::$_type_map['Null'] = self::$_none;
		self::$_invalid = self::create_type(InvalidType::class);

		self::$_any = self::create_type(AnyType::class);
		self::$_mixed = self::create_type(MixedType::class);
		self::$_object = self::create_type(ObjectType::class);

		// self::$_scalar = self::create_type(ScalarType::class);
		self::$_bytes = self::create_type(BytesType::class);
		self::$_string = self::create_type(StringType::class);
		self::$_plain = self::create_type(PlainType::class);
		self::$_type_map[_PLAIN] = self::$_plain;
		self::$_float = self::create_type(FloatType::class);
		self::$_int = self::create_type(IntType::class);
		self::$_uint = self::create_type(UIntType::class);
		self::$_bool = self::create_type(BoolType::class);

		self::$_iterable = self::create_type(IterableType::class);
		self::$_array = self::create_type(ArrayType::class);
		self::$_type_map['List'] = self::$_array;
		self::$_dict = self::create_type(DictType::class);
		self::$_generalized_array = self::create_union_type([self::$_array, self::$_dict]);
		self::$_type_map[_GENERAL_ARRAY] = self::$_generalized_array;

		self::$_xview = self::create_type(XViewType::class);
		self::$_regex = self::create_type(RegexType::class);

		self::$_callable = self::create_type(CallableType::class);
		// self::$_namespace = self::create_type(NamespaceType::class);

		self::$_dict_key = self::create_union_type([self::$_string, self::$_int]);

		self::$_iterator = new TypeReference('Iterator');
		// self::$_generator = new TypeReference('Generator');
		self::$_generator = self::$_iterator;

		self::$_int_types = [self::$_uint, self::$_int];
		self::$_int_and_string_types = [self::$_uint, self::$_int, self::$_string, self::$_plain];

		self::$_casting_map = [
			T_STRING_CAST => self::$_string,
			T_INT_CAST => self::$_int,
			T_DOUBLE_CAST => self::$_float,
			T_BOOL_CAST => self::$_bool,
			T_ARRAY_CAST => self::$_generalized_array,
			T_OBJECT_CAST => self::$_object,
			T_UNSET_CAST => self::$_none,
		];
	}

	public static function find_iterator_identifier(BaseType $type)
	{
		if (self::$_iterator_symbol === null) {
			return null;
		}

		if ($type->symbol === self::$_iterator_symbol) {
			$result = $type;
		}
		else {
			$decl = $type->symbol->declaration ?? null;
			$result = $decl instanceof ClassKindredDeclaration
				? $decl->find_based_with_symbol(self::$_iterator_symbol)
				: null;
		}

		return $result;
	}

	public static function set_symbols(Unit $unit)
	{
		foreach (self::$_type_map as $type_name => $object) {
			if (isset($unit->symbols[$type_name])) {
				$object->symbol = $unit->symbols[$type_name];
			}
		}

		// just a marker
		self::$_default_marker = clone self::$_none;

		self::$_iview_symbol = $unit->symbols['IView'] ?? null;

		self::$_exception_symbol = $unit->symbols[_BASE_EXCEPTION] ?? null;
		self::$_iterator_symbol = $unit->symbols['Iterator'] ?? null;
		self::$_iterator->symbol = self::$_iterator_symbol;
	}

	// only valid after set_symbols
	public static function get_base_exception_type()
	{
		static $identifier = new TypeReference(_BASE_EXCEPTION);
		$identifier->symbol = self::$_exception_symbol;
		return $identifier;
	}

	public static function get_for_casting_token_id(int $id)
	{
		return self::$_casting_map[$id] ?? null;
	}

	public static function exists_type(string $name): bool
	{
		return isset(self::$_type_map[$name]);
	}

	public static function get_type(string $name)
	{
		return self::$_type_map[$name] ?? null;
	}

	public static function clone_type(string $name)
	{
		$type = self::$_type_map[$name] ?? null;
		if ($type !== null) {
			$type = clone $type;
		}

		return $type;
	}

	private static function create_type(string $class)
	{
		$type_object = new $class();
		self::$_type_map[$type_object->name] = $type_object;

		return $type_object;
	}

	public static function create_array_type(BaseType $generic_type)
	{
		$type = new ArrayType($generic_type);
		$type->symbol = self::$_array->symbol;

		return $type;
	}

	public static function create_dict_type(BaseType $generic_type, array $known_member_types = [])
	{
		$type = new DictType($generic_type);
		$type->symbol = self::$_dict->symbol;
		$type->known_member_types = $known_member_types;

		return $type;
	}

	public static function create_callable_type(BaseType $return_type, array $parameters)
	{
		$type = new CallableType($return_type, $parameters);
		$type->symbol = self::$_callable->symbol;

		return $type;
	}

	public static function create_meta_type(BaseType $generic_type)
	{
		$type = new MetaType($generic_type);
		$type->symbol = self::$_meta->symbol;

		return $type;
	}

	public static function create_invalidable_type(BaseType $valid_type, LiteralExpression $sentinel): BaseType
	{
		if ($sentinel instanceof LiteralNone
			&& ($valid_type instanceof AnyType || $valid_type instanceof MixedType)) {
			return self::$_mixed;
		}

		return new InvalidableType($valid_type, $sentinel);
	}

	public static function create_excludable_type(BaseType $base_type, LiteralExpression $sentinel): ExcludableType
	{
		return new ExcludableType($base_type, $sentinel);
	}

	public static function create_union_type(array $items): BaseType
	{
		$single_items = [];
		foreach ($items as $member) {
			if ($member instanceof UnionType) {
				$single_items = array_merge($single_items, $member->members);
			}
			else {
				$single_items[] = $member;
			}
		}

		$has_mixed = false;
		$has_any = false;
		$has_null = false;
		foreach ($single_items as $member) {
			if ($member instanceof MixedType) {
				$has_mixed = true;
			}
			elseif ($member instanceof AnyType) {
				$has_any = true;
			}
			elseif ($member instanceof NoneType) {
				$has_null = true;
			}
			elseif ($member instanceof InvalidableType
				&& $member->sentinel instanceof LiteralNone
				&& $member->valid_type instanceof AnyType) {
				$has_any = true;
				$has_null = true;
			}
		}
		if ($has_mixed) {
			return self::$_mixed;
		}

		if ($has_any) {
			return $has_null
				? self::$_mixed
				: self::$_any;
		}

		$type = new UnionType($single_items);
		$type->symbol = self::$_union->symbol;

		return $type;
	}

	public static function create_intersection_type(array $items)
	{
		$type = new IntersectionType($items);
		$type->symbol = self::$_intersection->symbol;

		return $type;
	}
}

// end
