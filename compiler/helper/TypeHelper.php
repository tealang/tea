<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeHelper
{
	public static function is_simple_xtag_safe_value_type(?IType $type)
	{
		$is = false;

		if ($type instanceof IPureType || self::is_xview_or_none_type($type)) {
			$is = true;
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

	public static function is_xtag_child_type(?IType $type)
	{
		$is = false;
		if (self::is_scalar_type($type)
			or self::is_xview_or_none_type($type)) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			$is = self::is_union_xview_type($type);
		}
		elseif ($type instanceof IterableType) {
			$gtype = $type->generic_type;
			$is = self::is_xview_or_none_type($gtype)
				|| ($gtype instanceof UnionType && self::is_union_xview_type($gtype));
		}

		return $is;
	}

	private static function is_xview_or_none_type(?IType $type)
	{
		return $type instanceof XViewType
			|| $type instanceof NoneType
			|| $type->symbol->declaration->is_same_or_based_with_symbol(TypeFactory::$_iview_symbol);
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

	public static function is_dict_key_type(?IType $type)
	{
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

	public static function is_case_testable_type(IType $type)
	{
		$is = false;
		if ($type instanceof StringType || $type instanceof IntType || $type instanceof NoneType) {
			$is = true;
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

	public static function is_switch_compatible(IType $matchig, IType $case)
	{
		return $matchig->is_accept_type($case)
			or ($matchig instanceof PlainType and $case instanceof StringType);
	}

	public static function is_covariant_for(IType $type_in_child, IType $type_in_super)
	{
		if ($type_in_super instanceof UnionType) {
			$is = $type_in_super->contains_type($type_in_child);
		}
		else {
			$is = $type_in_child->is_same_or_based_with($type_in_super);
		}

		return $is;
	}

	public static function is_number_type(?IType $type)
	{
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

	public static function is_scalar_type(?IType $type)
	{
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

	public static function is_pure_type(IType $type)
	{
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

	public static function is_string_concatable_type(IType $type)
	{
		$is = false;
		if ($type instanceof StringType
			or $type instanceof IntType
			or $type instanceof FloatType
			or $type instanceof XViewType
			or $type instanceof NoneType
			) {
			$is = true;
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

	public static function is_nullable_type(IType $type)
	{
		$is = false;
		if ($type instanceof NoneType
			// or $type->nullable
			or $type instanceof AnyType) {
			$is = true;
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

	public static function to_nullable(IType $type)
	{
		return self::is_nullable_type($type)
			? $type
			: TypeFactory::create_union_type([$type, TypeFactory::$_none]);
	}

	public static function to_non_nullable(IType $type)
	{
		if (!$type instanceof UnionType) {
			return $type;
		}

		$members = [];
		foreach ($type->get_members() as $member) {
			if (!$member instanceof NoneType) {
				$members[] = $member;
			}
		}

		if (count($members) === 1) {
			$type = $members[0];
		}
		else {
			$type = clone $type;
			$type->members = $members;
		}

		return $type;
	}
}

// end
