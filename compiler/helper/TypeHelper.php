<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeHelper
{
	public static function is_xtag_child_type(?IType $type)
	{
		$is = false;
		if ($type instanceof XViewType
			|| $type instanceof StringType
			|| $type instanceof IntType
			|| $type instanceof FloatType
			|| $type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof IterableType and $type->generic_type instanceof XViewType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member_type) {
				if (!TypeHelper::is_dict_key_type($member_type)) {
					break;
				}
			}

			$is = true;
		}

		return $is;
	}

	public static function is_dict_key_type(?IType $type)
	{
		$is = false;
		if ($type instanceof StringType || $type instanceof IntType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->get_members() as $member_type) {
				if (!TypeHelper::is_dict_key_type($member_type)) {
					break;
				}
			}

			$is = true;
		}

		return $is;
	}

	public static function is_case_testable_type(IType $type)
	{
		if ($type instanceof StringType || $type instanceof IntType) {
			$result = true;
		}
		elseif ($type instanceof UnionType) {
			$result = true;
			foreach ($type->members as $member_type) {
				if (!self::is_case_testable_type($member_type)) {
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

	public static function is_switch_compatible(IType $matchig, IType $case)
	{
		return $matchig->is_accept_type($case)
			or ($matchig instanceof PuresType and $case instanceof StringType);
	}

	public static function is_number_type(?IType $type)
	{
		if ($type instanceof IntType || $type instanceof FloatType) {
			return true;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $subtype) {
				if (!static::is_number_type($subtype)) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	public static function is_scalar_type(?IType $type)
	{
		if ($type instanceof IScalarType or $type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->members as $member_type) {
				$is = self::is_scalar_type($member_type);
				if (!$is) {
					break;
				}
			}
		}
		else {
			$is = false;
		}

		return $is;
	}

	public static function is_pure_type(IType $type)
	{
		return $type instanceof IPureType;
	}

	public static function is_stringable_type(IType $type)
	{
		$is = false;
		if ($type instanceof StringType
			or $type instanceof IntType
			or $type instanceof XViewType
			) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->members as $member_type) {
				$is = self::is_stringable_type($member_type);
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
		if ($type->nullable
			or $type instanceof AnyType
			or $type instanceof NoneType) {
			$is = true;
		}
		elseif ($type instanceof UnionType) {
			foreach ($type->members as $member_type) {
				$is = self::is_nullable_type($member_type);
				if ($is) { // just check nullable
					break;
				}
			}
		}

		return $is;
	}
}

// end
