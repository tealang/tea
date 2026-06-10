<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeHelper
{
	private static array $bound_values = [];
	private static ?\SplObjectStorage $bound_types = null;

	public static function reset_semantic_state(): void
	{
		self::$bound_values = [];
		self::$bound_types = null;
	}

	public static function set_bound_value(IVariableDeclaration $decl, ?BaseExpression $value): void
	{
		$key = spl_object_id($decl);
		if ($value === null) {
			unset(self::$bound_values[$key]);
			return;
		}

		self::$bound_values[$key] = $value;
	}

	public static function get_bound_value(IVariableDeclaration $decl): ?BaseExpression
	{
		return self::$bound_values[spl_object_id($decl)] ?? null;
	}

	public static function get_raw_bound_type(BaseDeclaration $decl): ?BaseType
	{
		$table = self::$bound_types;
		if ($table !== null && isset($table[$decl])) {
			return $table[$decl];
		}

		return null;
	}

	public static function set_raw_bound_type(BaseDeclaration $decl, ?BaseType $type): void
	{
		if ($type === null) {
			if (self::$bound_types !== null) {
				unset(self::$bound_types[$decl]);
			}
			return;
		}

		$table = self::get_bound_types_table();
		$table[$decl] = $type;
	}

	public static function get_bound_type(BaseDeclaration $decl): BaseType
	{
		return self::get_raw_bound_type($decl)
			?? $decl->noted_type
			?? $decl->declared_type
			?? $decl->infered_type
			?? TypeFactory::$_any;
	}

	private static function get_bound_types_table(): \SplObjectStorage
	{
		return self::$bound_types ??= new \SplObjectStorage();
	}

	public static function get_type_symbol(BaseType $type): ?Symbol
	{
		return $type->symbol;
	}

	public static function set_type_symbol(BaseType $type, ?Symbol $symbol): void
	{
		$type->symbol = $symbol;
	}

	public static function unwrap_excludable_type(BaseType $type): BaseType
	{
		return $type instanceof ExcludableType ? $type->base_type : $type;
	}

	public static function is_simple_xtag_safe_value_type(BaseType $type)
	{
		$is = false;

		if (self::is_xview_or_none_type($type)) {
			$is = true;
		}
		elseif ($type instanceof InvalidableType && $type->sentinel instanceof LiteralNone) {
			$is = self::is_simple_xtag_safe_value_type($type->valid_type);
		}
		elseif ($type instanceof ExcludableType) {
			$is = self::is_simple_xtag_safe_value_type($type->base_type);
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$is = self::is_simple_xtag_safe_value_type($member);
				if (!$is) {
					break;
				}
			}
		}

		return $is;
	}

	public static function is_xtag_child_type(BaseType $type): bool
	{
		$is = false;
		if ($type instanceof AnyType
			or self::is_scalar_type($type) && self::is_plain_scalar_xtag_type($type)
			or self::is_xview_or_none_type($type)) {
			$is = true;
		}
		elseif ($type instanceof InvalidableType && $type->sentinel instanceof LiteralNone) {
			$is = self::is_xtag_child_type($type->valid_type);
		}
		elseif ($type instanceof ExcludableType) {
			$is = self::is_xtag_child_type($type->base_type);
		}
		elseif ($type instanceof UnionType) {
			$is = self::is_union_xview_type($type);
		}
		elseif ($type instanceof IterableType) {
			$gtype = $type->generic_type;
			if ($gtype instanceof InvalidableType && $gtype->sentinel instanceof LiteralNone) {
				$gtype = $gtype->valid_type;
			}
			$is = self::is_xview_or_none_type($gtype)
				|| ($gtype instanceof UnionType && self::is_union_xview_type($gtype));
		}

		return $is;
	}

	private static function is_plain_scalar_xtag_type(BaseType $type): bool
	{
		return self::get_type_symbol($type) === null || self::get_classkindred_declaration($type) !== null;
	}

	private static function is_xview_or_none_type(BaseType $type)
	{
		return $type instanceof XViewType
			|| $type instanceof NoneType
			|| self::is_xview_implementation_type($type);
	}

	private static function is_union_xview_type(UnionType $type)
	{
		$is = true;
		foreach ($type->get_members() as $member) {
			if (!self::is_xview_or_none_type($member)) {
				$is = false;
				break;
			}
		}

		return $is;
	}

	public static function is_dict_key_type(BaseType $type)
	{
		$type = self::unwrap_excludable_type($type);
		$is = false;
		if ($type instanceof StringType || $type instanceof IntType || $type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$is = self::is_dict_key_type($member);
				if (!$is) {
					break;
				}
			}
		}

		return $is;
	}

	public static function is_case_testable_type(BaseType $type)
	{
		$type = self::unwrap_excludable_type($type);
		$is = false;
		if ($type instanceof StringType || $type instanceof IntType || $type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof PlainType) {
			// Enum types are case-testable
			$is = true;
		}
		elseif ($type instanceof InvalidableType && $type->sentinel instanceof LiteralNone) {
			$is = self::is_case_testable_type($type->valid_type);
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$is = self::is_case_testable_type($member);
				if (!$is) {
					break;
				}
			}
		}

		return $is;
	}

	public static function is_switch_compatible(BaseType $matchig, BaseType $case)
	{
		return self::is_accepting_type($matchig, $case)
			or ($matchig instanceof PlainType and $case instanceof StringType);
	}

	public static function is_covariant_for(BaseType $type_in_child, BaseType $type_in_super)
	{
		return self::is_same_or_based_type($type_in_child, $type_in_super)
			|| self::is_accepting_type($type_in_super, $type_in_child);
	}

	public static function is_classkindred_same_or_based_with_symbol(ClassKindredDeclaration $decl, Symbol $symbol): bool
	{
		return $decl === $symbol->declaration
			|| self::find_classkindred_based_with_symbol($decl, $symbol) !== null;
	}

	public static function is_object_accepting_type(BaseType $target): bool
	{
		return self::get_classkindred_declaration($target) !== null;
	}

	public static function is_dict_like_type(BaseType $type): bool
	{
		$decl = self::get_classkindred_declaration($type);
		return $type instanceof DictType
			|| ($decl !== null && $decl->has_feature(ClassFeature::ARRAY_ACCESS));
	}

	public static function is_xview_accepting_type(XViewType $type, BaseType $target): bool
	{
		return self::is_same_type($target, $type)
			|| self::is_xview_implementation_type($target);
	}

	public static function is_plain_same_or_based_type(PlainType $type, BaseType $target): bool
	{
		$target_symbol = self::get_type_symbol($target);
		return self::get_type_symbol($type) === $target_symbol
			|| self::get_type_symbol(TypeFactory::$_string) === $target_symbol;
	}

	public static function is_uint_same_or_based_type(UIntType $type, BaseType $target): bool
	{
		$target_symbol = self::get_type_symbol($target);
		return self::get_type_symbol($type) === $target_symbol
			|| self::get_type_symbol(TypeFactory::$_int) === $target_symbol;
	}

	public static function is_iterable_same_or_based_type(IterableType $type, BaseType $target): bool
	{
		if (!$target instanceof IterableType) {
			return false;
		}

		$type_symbol = self::get_type_symbol($type);
		$target_symbol = self::get_type_symbol($target);
		if ($type_symbol !== $target_symbol && $target_symbol !== self::get_type_symbol(TypeFactory::$_iterable)) {
			return false;
		}

		if ($type->generic_type === null || $target->generic_type === null) {
			return $type->generic_type === $target->generic_type
				|| ($type->generic_type ?? $target->generic_type) instanceof AnyType;
		}

		return self::is_same_or_based_type($type->generic_type, $target->generic_type);
	}

	private static function is_xview_implementation_type(BaseType $type): bool
	{
		$decl = self::get_classkindred_declaration($type);
		$iview_symbol = TypeFactory::$_iview_symbol;

		return $decl !== null
			&& $iview_symbol instanceof Symbol
			&& self::is_classkindred_same_or_based_with_symbol($decl, $iview_symbol);
	}

	private static function get_classkindred_declaration(BaseType $type): ?ClassKindredDeclaration
	{
		$decl = self::get_type_symbol($type)->declaration ?? null;
		return $decl instanceof ClassKindredDeclaration ? $decl : null;
	}

	public static function find_classkindred_based_with_symbol(ClassKindredDeclaration $decl, Symbol $symbol): PlainIdentifier|TypeReference|null
	{
		if ($decl instanceof ClassDeclaration
			&& $decl->extends
			&& ($result = self::find_classkindred_based_with_symbol_in_super($decl->extends[0], $symbol))) {
			return $result;
		}

		$bases = $decl instanceof InterfaceDeclaration ? $decl->extends : $decl->implements;
		foreach ($bases as $based) {
			if (self::is_classkindred_identifier_based_with_symbol($based, $symbol)) {
				return $based;
			}
		}

		return null;
	}

	private static function is_classkindred_identifier_based_with_symbol(TypeReference $based, Symbol $symbol): bool
	{
		$based_symbol = self::get_type_symbol($based);
		$based_decl = null;
		if ($based_symbol instanceof Symbol) {
			$based_decl = $based_symbol->declaration;
		}

		return $based_symbol === $symbol
			|| $based_decl === $symbol->declaration
			|| ($based_decl instanceof ClassKindredDeclaration
				&& self::find_classkindred_based_with_symbol($based_decl, $symbol) !== null);
	}

	private static function find_classkindred_based_with_symbol_in_super(PlainIdentifier|TypeReference $super_identifier, Symbol $symbol): PlainIdentifier|TypeReference|null
	{
		$super_symbol = $super_identifier instanceof TypeReference
			? self::get_type_symbol($super_identifier)
			: ASTHelper::get_identifier_symbol($super_identifier);

		if ($super_symbol === $symbol || $super_symbol->declaration === $symbol->declaration) {
			return $super_identifier;
		}

		if ($super_symbol->declaration instanceof ClassKindredDeclaration) {
			return self::find_classkindred_based_with_symbol($super_symbol->declaration, $symbol);
		}

		return null;
	}

	public static function is_same_type(BaseType $left, BaseType $right): bool
	{
		if ($left === $right) {
			return true;
		}

		if ($left instanceof InvalidableType || $right instanceof InvalidableType) {
			return self::is_same_invalidable_type($left, $right);
		}

		if ($left instanceof ExcludableType || $right instanceof ExcludableType) {
			return self::is_same_excludable_type($left, $right);
		}

		if ($left instanceof SingleGenericType || $right instanceof SingleGenericType) {
			return self::is_same_generic_type($left, $right);
		}

		return self::is_same_type_base($left, $right);
	}

	private static function is_same_invalidable_type(BaseType $left, BaseType $right): bool
	{
		return $left instanceof InvalidableType
			&& $right instanceof InvalidableType
			&& self::is_same_type($left->valid_type, $right->valid_type)
			&& self::is_same_literal_value($left->sentinel, $right->sentinel);
	}

	private static function is_same_excludable_type(BaseType $left, BaseType $right): bool
	{
		return $left instanceof ExcludableType
			&& $right instanceof ExcludableType
			&& self::is_same_type($left->base_type, $right->base_type)
			&& self::is_same_literal_value($left->sentinel, $right->sentinel);
	}

	private static function is_same_type_base(BaseType $left, BaseType $right): bool
	{
		$left_symbol = self::get_type_symbol($left);
		$right_symbol = self::get_type_symbol($right);
		if ($left_symbol !== null && $right_symbol !== null) {
			return $left_symbol === $right_symbol
				|| $left_symbol->declaration === $right_symbol->declaration
				|| self::is_same_virtual_type_reference_name($left, $right);
		}

		return $left->name === $right->name && get_class($left) === get_class($right);
	}

	private static function is_same_virtual_type_reference_name(BaseType $left, BaseType $right): bool
	{
		return $left instanceof TypeReference
			&& $right instanceof TypeReference
			&& self::is_virtual_type_reference($left)
			&& self::is_virtual_type_reference($right)
			&& self::get_type_reference_name($left) === self::get_type_reference_name($right);
	}

	private static function is_same_generic_type(BaseType $left, BaseType $right): bool
	{
		if (!$left instanceof SingleGenericType || !$right instanceof SingleGenericType) {
			return false;
		}

		if (!self::is_same_type_base($left, $right)) {
			return false;
		}

		$left_generic_type = $left->generic_type ?? TypeFactory::$_any;
		$right_generic_type = $right->generic_type ?? TypeFactory::$_any;
		return self::is_same_type($left_generic_type, $right_generic_type);
	}

	public static function is_same_union_type(UnionType $type, BaseType $target): bool
	{
		if (!$target instanceof UnionType || $type->count() !== $target->count()) {
			return false;
		}

		foreach ($target->get_members() as $target_member) {
			if (!$type->contains_single_type($target_member)) {
				return false;
			}
		}

		return true;
	}

	public static function is_same_or_based_type(BaseType $left, BaseType $right): bool
	{
		if (self::is_same_type($left, $right)) {
			return true;
		}

		if ($left instanceof InvalidableType || $right instanceof InvalidableType) {
			return self::is_invalidable_same_or_based_type($left, $right);
		}

		if ($left instanceof UnionType) {
			return self::is_union_same_or_based_type($left, $right);
		}

		if ($left instanceof PlainType) {
			return self::is_plain_same_or_based_type($left, $right);
		}

		if ($left instanceof UIntType) {
			return self::is_uint_same_or_based_type($left, $right);
		}

		if ($left instanceof IterableType) {
			return self::is_iterable_same_or_based_type($left, $right);
		}

		if ($left instanceof SingleGenericType || $right instanceof SingleGenericType) {
			return self::is_same_or_based_generic_type($left, $right);
		}

		if (!$left instanceof TypeReference || self::get_type_symbol($left) === null || self::get_type_symbol($right) === null) {
			return false;
		}

		return self::is_based_type($left, $right);
	}

	private static function is_invalidable_same_or_based_type(BaseType $left, BaseType $right): bool
	{
		return $left instanceof InvalidableType
			&& $right instanceof InvalidableType
			&& self::is_same_literal_value($left->sentinel, $right->sentinel)
			&& self::is_same_or_based_type($left->valid_type, $right->valid_type);
	}

	public static function is_union_same_or_based_type(UnionType $type, BaseType $target): bool
	{
		if (!$target instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if (self::is_same_or_based_type($member, $target)) {
					return true;
				}
			}

			return false;
		}

		if ($type->count() !== $target->count()) {
			return false;
		}

		foreach ($target->get_members() as $target_member) {
			$found = false;
			foreach ($type->get_members() as $member) {
				if (self::is_same_or_based_type($member, $target_member)) {
					$found = true;
					break;
				}
			}

			if (!$found) {
				return false;
			}
		}

		return true;
	}

	public static function is_union_based_type(UnionType $type, BaseType $target): bool
	{
		foreach ($type->get_members() as $member) {
			if (!self::is_based_type($member, $target)) {
				return false;
			}
		}

		return true;
	}

	public static function is_union_accepting_type(UnionType $type, BaseType $target): bool
	{
		foreach ($type->get_members() as $member) {
			if (self::is_accepting_type($member, $target)) {
				return true;
			}
		}

		return false;
	}

	public static function is_based_type(BaseType $left, BaseType $right): bool
	{
		if (!$left instanceof TypeReference) {
			return false;
		}

		$left_symbol = self::get_type_symbol($left);
		if ($left_symbol === null) {
			throw new \Exception("Cannot call is_based_type when left symbol is not bound, type name: {$left->name}");
		}

		$right_symbol = self::get_type_symbol($right);
		if ($right_symbol === null) {
			return false;
		}

		$left_decl = $left_symbol->declaration;
		return $left_decl instanceof ClassKindredDeclaration
			&& self::find_classkindred_based_with_symbol($left_decl, $right_symbol) !== null;
	}

	public static function is_type_reference_accepting_type(TypeReference $type, BaseType $target): bool
	{
		return self::is_same_type($target, $type)
			|| ($target instanceof TypeReference
				&& self::get_type_symbol($target) !== null
				&& self::get_type_symbol($type) !== null
				&& self::is_based_type($target, $type));
	}

	public static function is_accepting_type(BaseType $type, BaseType $target): bool
	{
		if (!$target instanceof UnionType) {
			return self::is_accepting_single_type($type, $target);
		}

		foreach ($target->get_members() as $target_member) {
			if (self::is_accepting_single_type($type, $target_member)) {
				return true;
			}
		}

		return false;
	}

	private static function is_accepting_single_type(BaseType $type, BaseType $target): bool
	{
		if ($type instanceof InvalidableType) {
			return self::is_invalidable_accepting_type($type, $target);
		}

		if ($type instanceof ExcludableType) {
			return self::is_excludable_accepting_type($type, $target);
		}

		if ($target instanceof ExcludableType) {
			return self::is_accepting_type($type, $target->base_type);
		}

		if ($type instanceof UnionType) {
			return self::is_union_accepting_type($type, $target);
		}

		if ($type instanceof TypeReference) {
			return self::is_type_reference_accepting_type($type, $target);
		}

		if ($type instanceof SingleGenericType) {
			return self::is_single_generic_accepting_type($type, $target);
		}

		if ($type instanceof CallableType) {
			return self::is_callable_accepting_type($type, $target);
		}

		if ($type instanceof XViewType) {
			return self::is_xview_accepting_type($type, $target);
		}

		if ($type instanceof ObjectType) {
			return self::is_object_accepting_type($target);
		}

		if ($type instanceof VoidType) {
			return $type === $target;
		}

		if ($type instanceof NoneType) {
			return $target instanceof NoneType;
		}

		if ($type instanceof MixedType) {
			return true;
		}

		if ($type instanceof AnyType) {
			return !$target instanceof NoneType;
		}

		return self::is_default_accepting_single_type($type, $target);
	}

	private static function is_invalidable_accepting_type(InvalidableType $type, BaseType $target): bool
	{
		if ($target instanceof InvalidableType) {
			return self::is_same_literal_value($type->sentinel, $target->sentinel)
				&& self::is_accepting_type($type->valid_type, $target->valid_type);
		}

		if (self::is_accepting_type($type->valid_type, $target)) {
			return true;
		}

		return $type->sentinel instanceof LiteralNone
			&& $target instanceof NoneType;
	}

	public static function is_excludable_accepting_type(ExcludableType $type, BaseType $target): bool
	{
		if ($target instanceof ExcludableType) {
			return self::is_same_literal_value($type->sentinel, $target->sentinel)
				&& self::is_accepting_type($type->base_type, $target->base_type);
		}

		return false;
	}

	public static function is_default_accepting_single_type(BaseType $type, BaseType $target): bool
	{
		return self::is_same_type($target, $type)
			|| in_array($target->name, $type::ACCEPT_TYPES, true);
	}

	public static function is_single_generic_accepting_type(SingleGenericType $type, BaseType $target): bool
	{
		if ($target === $type) {
			return true;
		}

		if ($target instanceof TypeReference && self::get_type_symbol($type) === self::get_type_symbol($target)) {
			return true;
		}

		if (!$target instanceof SingleGenericType || get_class($target) !== get_class($type)) {
			return false;
		}

		if ($type->generic_type === null || $type->generic_type === TypeFactory::$_any) {
			return true;
		}

		if ($target->generic_type === null) {
			return false;
		}

		return self::is_accepting_type($type->generic_type, $target->generic_type);
	}

	private static function is_same_or_based_generic_type(BaseType $left, BaseType $right): bool
	{
		if (!$left instanceof SingleGenericType || !$right instanceof SingleGenericType) {
			return false;
		}

		if (!self::is_same_type_base($left, $right)) {
			return false;
		}

		if ($left->generic_type === null || $right->generic_type === null) {
			return false;
		}

		return self::is_same_or_based_type($left->generic_type, $right->generic_type);
	}

	public static function is_callable_parameter_compatible(BaseType $protocol_param_type, BaseType $implement_param_type)
	{
		// Callable parameters are contravariant; Any is retained as v1's dynamic boundary.
		return $protocol_param_type === TypeFactory::$_any
			|| $implement_param_type === TypeFactory::$_any
			|| self::is_accepting_type($implement_param_type, $protocol_param_type);
	}

	public static function is_callable_accepting_type(CallableType $type, BaseType $target): bool
	{
		if (!$target instanceof CallableType) {
			return false;
		}

		if ($type->declared_type === null) {
			return true;
		}

		if (!self::is_accepting_type($type->declared_type, $target->declared_type)
			|| count($target->parameters) > count($type->parameters)) {
			return false;
		}

		foreach ($type->parameters as $key => $protocol_param) {
			$implement_param = $target->parameters[$key] ?? null;
			if ($implement_param === null) {
				if ($protocol_param->value === null) {
					return false;
				}

				continue;
			}

			$protocol_param_type = $protocol_param->declared_type ?? TypeFactory::$_any;
			$implement_param_type = $implement_param->declared_type ?? TypeFactory::$_any;
			if (!self::is_callable_parameter_compatible($protocol_param_type, $implement_param_type)) {
				return false;
			}
		}

		return true;
	}

	public static function is_value_compatible(BaseType $expected_type, BaseType $actual_type): bool
	{
		if ($expected_type instanceof AnyType || $actual_type instanceof AnyType) {
			return true;
		}

		if ($expected_type instanceof InvalidableType) {
			return self::is_invalidable_value_compatible($expected_type, $actual_type);
		}

		if ($actual_type instanceof InvalidableType) {
			return self::is_value_compatible($expected_type, $actual_type->valid_type)
				&& self::is_value_compatible($expected_type, self::get_invalidable_sentinel_type($actual_type));
		}

		if ($actual_type instanceof UnionType) {
			foreach ($actual_type->get_members() as $actual_member) {
				if (!self::is_value_compatible($expected_type, $actual_member)) {
					return false;
				}
			}

			return true;
		}

		if ($expected_type instanceof UnionType) {
			foreach ($expected_type->get_members() as $expected_member) {
				if (self::is_value_compatible($expected_member, $actual_type)) {
					return true;
				}
			}

			return false;
		}

		if ($expected_type instanceof SingleGenericType || $actual_type instanceof SingleGenericType) {
			return self::is_generic_value_compatible($expected_type, $actual_type);
		}

		return self::is_accepting_type($expected_type, $actual_type);
	}

	private static function is_invalidable_value_compatible(InvalidableType $expected_type, BaseType $actual_type): bool
	{
		if ($actual_type instanceof UnionType) {
			foreach ($actual_type->get_members() as $actual_member) {
				if (!self::is_invalidable_value_compatible($expected_type, $actual_member)) {
					return false;
				}
			}

			return true;
		}

		if ($actual_type instanceof InvalidableType) {
			return self::is_value_compatible($expected_type->valid_type, $actual_type->valid_type)
				&& self::is_same_literal_value($expected_type->sentinel, $actual_type->sentinel);
		}

		if (self::is_value_compatible($expected_type->valid_type, $actual_type)) {
			return true;
		}

		return $expected_type->sentinel instanceof LiteralNone
			&& $actual_type instanceof NoneType;
	}

	public static function is_argument_compatible(BaseType $expected_type, BaseType $actual_type): bool
	{
		return self::is_value_compatible($expected_type, $actual_type);
	}

	public static function is_assignment_compatible(BaseType $expected_type, BaseType $actual_type): bool
	{
		return self::is_value_compatible($expected_type, $actual_type);
	}

	public static function is_return_compatible(BaseType $expected_type, BaseType $actual_type): bool
	{
		return self::is_value_compatible($expected_type, $actual_type);
	}

	private static function is_generic_value_compatible(BaseType $expected_type, BaseType $actual_type): bool
	{
		if (!$expected_type instanceof SingleGenericType || !$actual_type instanceof SingleGenericType) {
			return self::is_accepting_type($expected_type, $actual_type);
		}

		if (!self::is_same_type_base($expected_type, $actual_type)) {
			return false;
		}

		$expected_generic_type = $expected_type->generic_type ?? TypeFactory::$_any;
		if ($expected_generic_type instanceof AnyType) {
			return true;
		}

		$actual_generic_type = $actual_type->generic_type ?? TypeFactory::$_any;
		if ($actual_generic_type instanceof AnyType) {
			return false;
		}

		return self::is_value_compatible($expected_generic_type, $actual_generic_type);
	}

	public static function is_override_parameter_compatible(BaseType $super_param_type, BaseType $current_param_type, bool $allow_dynamic_boundary = false): bool
	{
		if ($super_param_type === $current_param_type) {
			return true;
		}

		if ($allow_dynamic_boundary
			&& ($super_param_type === TypeFactory::$_any || $current_param_type === TypeFactory::$_any)) {
			return true;
		}

		if ($super_param_type === TypeFactory::$_any || $current_param_type === TypeFactory::$_any) {
			return false;
		}

		if ($super_param_type instanceof InvalidableType || $current_param_type instanceof InvalidableType) {
			if ($super_param_type instanceof InvalidableType && $current_param_type instanceof InvalidableType) {
				return self::is_same_literal_value($super_param_type->sentinel, $current_param_type->sentinel)
					&& self::is_override_parameter_compatible($super_param_type->valid_type, $current_param_type->valid_type, $allow_dynamic_boundary);
			}

			if ($super_param_type instanceof InvalidableType
				&& $super_param_type->sentinel instanceof LiteralNone
				&& self::is_nullable_type($current_param_type)) {
				return self::is_override_parameter_compatible($super_param_type->valid_type, self::to_non_nullable($current_param_type), $allow_dynamic_boundary);
			}

			if ($current_param_type instanceof InvalidableType
				&& $current_param_type->sentinel instanceof LiteralNone
				&& self::is_nullable_type($super_param_type)) {
				return self::is_override_parameter_compatible(self::to_non_nullable($super_param_type), $current_param_type->valid_type, $allow_dynamic_boundary);
			}

			if ($current_param_type instanceof InvalidableType && $current_param_type->sentinel instanceof LiteralNone) {
				return self::is_override_parameter_compatible($super_param_type, $current_param_type->valid_type, $allow_dynamic_boundary);
			}

			return false;
		}

		if ($super_param_type instanceof UnionType) {
			foreach ($super_param_type->get_members() as $super_member) {
				if (!self::is_override_parameter_compatible($super_member, $current_param_type, $allow_dynamic_boundary)) {
					return false;
				}
			}

			return true;
		}

		if ($current_param_type instanceof UnionType) {
			foreach ($current_param_type->get_members() as $current_member) {
				if (self::is_override_parameter_compatible($super_param_type, $current_member, $allow_dynamic_boundary)) {
					return true;
				}
			}

			return false;
		}

		if (self::is_same_type($super_param_type, $current_param_type)) {
			return true;
		}

		if ($super_param_type === TypeFactory::$_int && $current_param_type === TypeFactory::$_uint) {
			return true;
		}

		// Override parameters are contravariant.
		if ($current_param_type instanceof TypeReference && $super_param_type instanceof TypeReference) {
			return self::is_type_reference_accepting_type($current_param_type, $super_param_type);
		}

		return false;
	}

	public static function should_report_php_weak_type_mismatch(BaseType $expected, BaseType $actual, Node $value_node, string $kind): bool
	{
		return self::is_definite_php_scalar_mismatch($expected, $actual, $value_node)
			|| self::is_definite_php_array_mismatch($expected, $actual, $value_node)
			|| (($kind === 'argument' || $kind === 'return' || $kind === 'assign')
				&& self::is_definite_php_class_mismatch($expected, $actual, $value_node, $kind));
	}

	public static function get_php_builtin_predicate_asserted_type_fallback(?string $callee_name): ?BaseType
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

	public static function infer_php_array_union_operation(BaseType $left_type, BaseType $right_type): ?BaseType
	{
		if ($left_type instanceof MixedType && self::is_php_array_union_type($right_type)) {
			return $right_type;
		}

		if ($right_type instanceof MixedType && self::is_php_array_union_type($left_type)) {
			return $left_type;
		}

		if ($left_type instanceof AnyType || $right_type instanceof AnyType) {
			return TypeFactory::$_any;
		}

		if (!self::is_php_array_union_type($left_type) || !self::is_php_array_union_type($right_type)) {
			return null;
		}

		return $left_type->unite($right_type);
	}

	private static function is_php_array_union_type(BaseType $type): bool
	{
		if ($type instanceof ArrayType || $type instanceof DictType) {
			return true;
		}

		return $type instanceof UnionType && $type->has_array_or_dict_type();
	}

	private static function is_definite_php_scalar_mismatch(BaseType $expected, BaseType $actual, Node $value_node): bool
	{
		if ($expected instanceof AnyType || $actual instanceof AnyType || $expected instanceof MixedType || $actual instanceof MixedType) {
			return false;
		}

		if ($expected instanceof IntType || $expected instanceof UIntType || $expected instanceof FloatType) {
			if ($actual instanceof StringType || $actual instanceof PlainType) {
				return $value_node instanceof LiteralString && !is_numeric($value_node->value);
			}

			return $actual instanceof BoolType;
		}

		if ($expected instanceof BoolType) {
			return $actual instanceof StringType || $actual instanceof PlainType || $actual instanceof IntType
				|| $actual instanceof UIntType || $actual instanceof FloatType;
		}

		return false;
	}

	private static function is_definite_php_array_mismatch(BaseType $expected, BaseType $actual, Node $value_node): bool
	{
		if ($expected instanceof AnyType || $actual instanceof AnyType || $expected instanceof MixedType || $actual instanceof MixedType) {
			return false;
		}

		if (!self::is_definite_php_array_type($expected)) {
			return false;
		}

		return self::is_definite_php_non_array_value($actual, $value_node);
	}

	private static function is_definite_php_array_type(BaseType $type): bool
	{
		if ($type instanceof ArrayType || $type instanceof DictType) {
			return true;
		}

		if (!$type instanceof UnionType) {
			return false;
		}

		$members = $type->get_members();
		if (!$members) {
			return false;
		}

		foreach ($members as $member) {
			if (!$member instanceof ArrayType && !$member instanceof DictType) {
				return false;
			}
		}

		return true;
	}

	private static function is_definite_php_non_array_value(BaseType $type, Node $value_node): bool
	{
		if ($type instanceof AnyType || $type instanceof MixedType || $type instanceof UnionType) {
			return false;
		}

		return $value_node instanceof LiteralString
			|| $value_node instanceof LiteralInteger
			|| $value_node instanceof LiteralFloat
			|| $value_node instanceof LiteralBoolean
			|| $value_node instanceof LiteralNone
			|| $value_node instanceof InstancingExpression
			|| ($value_node instanceof ICallableDeclaration && self::is_definite_php_non_array_type($type));
	}

	private static function is_definite_php_non_array_type(BaseType $type): bool
	{
		return !self::is_definite_php_array_type($type)
			&& !$type instanceof AnyType
			&& !$type instanceof MixedType
			&& !$type instanceof UnionType
			&& !self::is_virtual_type_reference($type);
	}

	private static function is_virtual_type_reference(BaseType $type): bool
	{
		$decl = self::get_type_symbol($type)->declaration ?? null;
		return $type instanceof TypeReference
			&& $decl instanceof ClassKindredDeclaration
			&& $decl->is_virtual;
	}

	private static function is_definite_php_class_mismatch(BaseType $expected, BaseType $actual, Node $value_node, string $kind): bool
	{
		if (!self::is_definite_php_class_type($expected)) {
			return false;
		}

		return $actual instanceof TypeReference
			&& self::is_definite_php_class_value($expected, $actual, $value_node, $kind)
			&& self::is_definite_php_different_class_type($expected, $actual);
	}

	private static function is_definite_php_class_value(TypeReference $expected, TypeReference $actual, Node $value_node, string $kind): bool
	{
		if ($value_node instanceof InstancingExpression) {
			return self::is_definite_static_instancing($value_node);
		}

		if ($value_node instanceof BaseCallExpression) {
			return self::is_definite_same_program_class_call($expected, $actual, $value_node);
		}

		if ($kind !== 'argument' && $kind !== 'assign' && $kind !== 'return') {
			return false;
		}

		if (!$value_node instanceof Identifiable) {
			return false;
		}

		if (($kind === 'argument' || $kind === 'assign') && !self::is_same_program_class_pair($expected, $actual)) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($value_node)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || !$decl instanceof IVariableDeclaration) {
			return false;
		}

		$deterministic_type = $decl->declared_type ?? $decl->infered_type ?? self::get_raw_bound_type($decl);
		$bound_value = self::get_bound_value($decl);
		return $deterministic_type instanceof TypeReference
			&& self::is_same_type($deterministic_type, $actual)
			&& $bound_value instanceof InstancingExpression
			&& self::is_definite_static_instancing($bound_value);
	}

	private static function is_definite_same_program_class_call(TypeReference $expected, TypeReference $actual, BaseCallExpression $value_node): bool
	{
		$callable_decl = ASTHelper::get_callee_declaration($value_node);
		if (!$callable_decl instanceof BaseDeclaration || !$callable_decl instanceof ICallableDeclaration) {
			return false;
		}

		$return_type = $callable_decl->get_expressed_type();
		return $return_type instanceof TypeReference
			&& self::is_same_type($return_type, $actual)
			&& self::is_same_program_class_pair($expected, $actual)
			&& self::is_same_program_callable($expected, $callable_decl);
	}

	private static function is_same_program_callable(TypeReference $expected, BaseDeclaration $callable_decl): bool
	{
		$expected_decl = self::get_type_symbol($expected)->declaration ?? null;
		return $expected_decl instanceof ClassKindredDeclaration
			&& $expected_decl->program !== null
			&& $expected_decl->program === $callable_decl->program;
	}

	private static function is_same_program_class_pair(TypeReference $expected, TypeReference $actual): bool
	{
		$expected_decl = self::get_type_symbol($expected)->declaration ?? null;
		$actual_decl = self::get_type_symbol($actual)->declaration ?? null;
		return $expected_decl instanceof ClassKindredDeclaration
			&& $actual_decl instanceof ClassKindredDeclaration
			&& $expected_decl->program !== null
			&& $expected_decl->program === $actual_decl->program;
	}

	private static function is_definite_static_instancing(InstancingExpression $value_node): bool
	{
		return $value_node->callee instanceof PlainIdentifier
			&& !$value_node->callee instanceof VariableIdentifier;
	}

	private static function is_definite_php_class_type(BaseType $type): bool
	{
		$decl = $type instanceof TypeReference ? (self::get_type_symbol($type)->declaration ?? null) : null;
		return $decl instanceof ClassKindredDeclaration;
	}

	private static function is_definite_php_different_class_type(TypeReference $expected, TypeReference $actual): bool
	{
		if (self::is_late_static_return_type($actual)
			|| self::is_runtime_class_compatible($expected, $actual)) {
			return false;
		}

		$expected_decl = self::get_type_symbol($expected)->declaration ?? null;
		$actual_decl = self::get_type_symbol($actual)->declaration ?? null;

		if (!$expected_decl instanceof ClassKindredDeclaration || !$actual_decl instanceof ClassKindredDeclaration) {
			return false;
		}

		return $expected_decl !== $actual_decl && self::get_type_reference_name($expected) !== self::get_type_reference_name($actual);
	}

	private static function is_late_static_return_type(TypeReference $type): bool
	{
		return $type->name === _TYPE_SELF || $type->name === _STATIC;
	}

	private static function is_runtime_class_compatible(TypeReference $expected, TypeReference $actual): bool
	{
		$expected_name = ltrim(self::get_type_reference_name($expected), _BACK_SLASH);
		$actual_name = ltrim(self::get_type_reference_name($actual), _BACK_SLASH);

		if ((!class_exists($expected_name, false) && !interface_exists($expected_name, false))
			|| (!class_exists($actual_name, false) && !interface_exists($actual_name, false))) {
			return false;
		}

		return is_a($actual_name, $expected_name, true);
	}

	private static function get_type_reference_name(BaseType $type): string
	{
		$name = $type->name;
		if ($type instanceof TypeReference && $type->ns) {
			$names = $type->ns->names;
			$names[] = $name;
			$name = join(_BACK_SLASH, $names);
		}

		return $name;
	}

	public static function is_number_type(BaseType $type)
	{
		$type = self::unwrap_excludable_type($type);
		$is = false;
		if ($type instanceof IntType || $type instanceof FloatType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$is = static::is_number_type($member);
				if (!$is) {
					break;
				}
			}
		}

		return $is;
	}

	public static function is_scalar_type(BaseType $type)
	{
		$type = self::unwrap_excludable_type($type);
		$is = false;
		if ($type instanceof IScalarType or $type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$is = self::is_scalar_type($member);
				if (!$is) {
					break;
				}
			}
		}

		return $is;
	}

	public static function is_pure_type(BaseType $type)
	{
		$type = self::unwrap_excludable_type($type);
		$is = false;
		if ($type instanceof IPureType or $type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$is = self::is_pure_type($member);
				if (!$is) {
					break;
				}
			}
		}

		return $is;
	}

	public static function is_string_concatable_type(BaseType $type)
	{
		$type = self::unwrap_excludable_type($type);
		$is = false;
		if ($type instanceof AnyType
			or $type instanceof StringType
			or $type instanceof IntType
			or $type instanceof FloatType
			or $type instanceof XViewType
			or $type instanceof NoneType
			) {
			$is = true;
		}
		elseif ($type instanceof InvalidableType && $type->sentinel instanceof LiteralNone) {
			$is = self::is_string_concatable_type($type->valid_type);
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$is = self::is_string_concatable_type($member);
				if (!$is) {
					break;
				}
			}
		}

		return $is;
	}

	public static function is_nullable_type(BaseType $type)
	{
		$is = false;
		if ($type instanceof MixedType) {
			$is = true;
		}
		elseif ($type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof InvalidableType) {
			$is = $type->sentinel instanceof LiteralNone;
		}
		elseif ($type instanceof UnionType) {
			$is = self::is_union_contains_none($type);
		}

		return $is;
	}

	private static function is_union_contains_none(UnionType $type)
	{
		$is = false;
		foreach ($type->get_members() as $member) {
			if ($is = self::is_nullable_type($member)) {
				break;
			}
		}

		return $is;
	}

	public static function to_nullable(BaseType $type): BaseType
	{
		if ($type instanceof AnyType) {
			return TypeFactory::$_mixed;
		}

		return self::is_nullable_type($type)
			? $type
			: TypeFactory::create_invalidable_type($type, new LiteralNone());
	}

	public static function to_non_nullable(BaseType $type)
	{
		if ($type instanceof MixedType) {
			return TypeFactory::$_any;
		}

		if ($type instanceof InvalidableType && $type->sentinel instanceof LiteralNone) {
			return $type->valid_type;
		}

		if (!$type instanceof UnionType) {
			return $type;
		}

		$members = [];
		foreach ($type->get_members() as $member) {
			if ($member instanceof InvalidableType && $member->sentinel instanceof LiteralNone) {
				$members[] = $member->valid_type;
			}
			elseif (!$member instanceof NoneType) {
				$members[] = $member;
			}
		}

		if (count($members) === 0) {
			$type = TypeFactory::$_none;
		}
		elseif (count($members) === 1) {
			$type = $members[0];
		}
		else {
			$type = clone $type;
			$type->members = $members;
		}

		return $type;
	}

	public static function is_invalidable_type(BaseType $type): bool
	{
		return $type instanceof InvalidableType;
	}

	public static function get_valid_type(BaseType $type): BaseType
	{
		if ($type instanceof InvalidableType) {
			return $type->valid_type;
		}

		return $type instanceof ExcludableType ? $type->base_type : $type;
	}

	public static function get_invalidable_valid_branch_type(InvalidableType $type): BaseType
	{
		return self::literal_can_belong_to_type($type->sentinel, $type->valid_type)
			? TypeFactory::create_excludable_type($type->valid_type, $type->sentinel)
			: $type->valid_type;
	}

	private static function literal_can_belong_to_type(LiteralExpression $literal, BaseType $type): bool
	{
		if ($type instanceof MixedType || $type instanceof AnyType) {
			return !$literal instanceof LiteralNone || $type instanceof MixedType;
		}

		if ($type instanceof ExcludableType) {
			return self::literal_can_belong_to_type($literal, $type->base_type)
				&& !self::is_same_literal_value($literal, $type->sentinel);
		}

		if ($type instanceof InvalidableType) {
			return self::literal_can_belong_to_type($literal, $type->valid_type)
				|| self::is_same_literal_value($literal, $type->sentinel);
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if (self::literal_can_belong_to_type($literal, $member)) {
					return true;
				}
			}
			return false;
		}

		if ($literal instanceof LiteralNone) {
			return $type instanceof NoneType;
		}

		if ($literal instanceof LiteralBoolean) {
			return $type instanceof BoolType;
		}

		if ($literal instanceof LiteralInteger) {
			if ($type instanceof UIntType) {
				$value = (string) $literal->value;
				return $value === '' || $value[0] !== '-';
			}

			return $type instanceof IntType;
		}

		if ($literal instanceof LiteralString) {
			return $type instanceof StringType;
		}

		return false;
	}

	public static function is_invalidable_sentinel(BaseType $type, BaseExpression $expr): bool
	{
		return $type instanceof InvalidableType
			&& self::is_same_literal_value($type->sentinel, $expr);
	}

	public static function get_invalidable_sentinel_type(InvalidableType $type): BaseType
	{
		if ($type->sentinel instanceof LiteralNone) {
			return TypeFactory::$_none;
		}

		if ($type->sentinel instanceof LiteralBoolean) {
			return TypeFactory::$_bool;
		}

		if ($type->sentinel instanceof LiteralInteger) {
			return TypeFactory::$_int;
		}

		if ($type->sentinel instanceof LiteralString) {
			return TypeFactory::$_string;
		}

		return TypeFactory::$_any;
	}

	private static function is_same_literal_value(BaseExpression $left, BaseExpression $right): bool
	{
		if (self::is_same_integer_literal_value($left, $right)) {
			return true;
		}

		if (get_class($left) !== get_class($right)) {
			return false;
		}

		if ($left instanceof LiteralNone) {
			return true;
		}

		if ($left instanceof LiteralBoolean && $right instanceof LiteralBoolean) {
			return self::literal_boolean_to_bool($left) === self::literal_boolean_to_bool($right);
		}

		if ($left instanceof LiteralInteger && $right instanceof LiteralInteger) {
			return (string) $left->value === (string) $right->value;
		}

		if ($left instanceof LiteralString && $right instanceof LiteralString) {
			return (string) $left->value === (string) $right->value;
		}

		return false;
	}

	private static function is_same_integer_literal_value(BaseExpression $left, BaseExpression $right): bool
	{
		$left_value = self::get_integer_literal_value($left);
		$right_value = self::get_integer_literal_value($right);
		return $left_value !== null
			&& $right_value !== null
			&& $left_value === $right_value;
	}

	private static function get_integer_literal_value(BaseExpression $expr): ?string
	{
		if ($expr instanceof LiteralInteger) {
			return (string) $expr->value;
		}

		if (!$expr instanceof PrefixOperation || !$expr->expression instanceof LiteralInteger) {
			return null;
		}

		if ($expr->operator->is(OPID::NEGATION)) {
			return '-' . (string) $expr->expression->value;
		}

		if ($expr->operator->is(OPID::IDENTITY)) {
			return (string) $expr->expression->value;
		}

		return null;
	}

	private static function literal_boolean_to_bool(LiteralBoolean $literal): bool
	{
		return $literal->value === true || $literal->value === '1' || $literal->value === 1;
	}
}

// end
