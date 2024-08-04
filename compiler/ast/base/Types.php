<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IType {}

trait ITypeTrait
{
	public $nullable;

	public $has_null;

	public function let_nullable() {
		$this->nullable = true;
	}

	public function remove_nullable() {
		$this->nullable = false;
		$this->has_null = false;
	}

	public function get_nullable_instance(): IType {
		if ($this->nullable) {
			return $this;
		}

		$copy = clone $this;
		$copy->let_nullable();

		return $copy;
	}

	public function is_accept_type(IType $target) {
		if ($target instanceof UnionType) {
			$accept = false;
			foreach ($target->get_members() as $target_member) {
				if ($this->is_accept_single_type($target_member)) {
					$accept = true;
					break;
				}
			}
		}
		else {
			$accept = $this->is_accept_single_type($target);
		}

		return $accept;
	}

	public function unite_type(IType $target): IType {
		if ($target instanceof UnionType) {
			$result = $target->merge_with_single_type($this);
		}
		elseif ($this->is_same_with($target)
			|| ($this instanceof StringType and $target instanceof PuresType)) {
			$result = $this;
		}
		else {
			$result = new UnionType([$this, $target]);
		}

		return $result;
	}

	public function is_same_with(IType $target) {
		return $this->symbol !== null and $this->symbol === $target->symbol;
	}

	public function is_same_or_based_with(IType $target) {
		return $this->symbol === $target->symbol;
	}
}

class BaseType extends Node implements IType
{
	use ITypeTrait;

	const KIND = 'type_identifier';

	const ACCEPT_TYPES = [];

	public $name;

	public $symbol;

	public function is_based_with(IType $target) {
		return false;
	}

	public function is_accept_single_type(IType $target) {
		if ($target->has_null and !$this->nullable and !$this->has_null) {
			$result = false;
		}
		elseif ($target instanceof NoneType) {
			$result = $this->nullable;
		}
		else {
			$result = $target === $this
				|| in_array($target->name, static::ACCEPT_TYPES, true)
				|| $target->symbol->declaration === $this->symbol->declaration;
		}

		return $result;
	}
}

abstract class SingleGenericType extends BaseType
{
	/**
	 * The generic type
	 * default to Any when is null
	 * @var IType
	 */
	public $generic_type;

	public function __construct(IType $generic_type = null) {
		$this->generic_type = $generic_type;
	}

	public function unite_type(IType $target): IType {
		if ($target instanceof UnionType) {
			$result = $target->merge_with_single_type($this);
		}
		elseif ($this->name === $target->name) {
			if ($this->generic_type === $target->generic_type) {
				$result = $this;
			}
			else {
				// just to uniting the generic_type
				$generic_type = $this->generic_type ?? TypeFactory::$_any;
				$generic_type = $generic_type->unite_type($target->generic_type ?? TypeFactory::$_any);
				$result = clone $this;
				$result->generic_type = $generic_type;
			}
		}
		else {
			$result = new UnionType([$this, $target]);
		}

		return $result;
	}

	public function is_accept_single_type(IType $target) {
		if ($target->has_null and !$this->nullable and !$this->has_null) {
			return false;
		}

		if ($target instanceof NoneType) {
			return $this->nullable;
		}

		if ($target === $this
			// || $target === TypeFactory::$_none
		) {
			return true;
		}

		// for the builtin classes
		if ($target instanceof ClassKindredIdentifier && $this->symbol === $target->symbol) {
			return true;
		}

		if (!$target instanceof static) {
			return false;
		}

		// if current value type is Any, then accept any types
		if ($this->generic_type === null || $this->generic_type === TypeFactory::$_any) {
			return true;
		}

		if ($target->generic_type === null) {
			return false;
		}

		return $this->generic_type->is_accept_type($target->generic_type);
	}

	public function is_same_with(IType $target) {
		if ($this->symbol !== null and $this->symbol === $target->symbol) {
			if ($this->generic_type === null || $this->generic_type->is_same_with($target->generic_type ?? TypeFactory::$_any)) {
				return true;
			}
		}

		return false;
	}

	public function is_same_or_based_with(IType $target) {
		return $this->symbol === $target->symbol && $this->generic_type->is_same_or_based_with($target->generic_type);
	}
}

class UnionType extends BaseType
{
	const KIND = 'union_type_expression';

	public $name = _UNIONTYPE;

	public $members = [];

	public function __construct(array $members = []) {
		$this->members = $members;
	}

	public function get_members()
	{
		return $this->members;
	}

	public function count()
	{
		return count($this->members);
	}

	public function remove_nullable() {
		$this->nullable = false;
		foreach ($this->members as $key => $type) {
			if ($type->nullable or $type->has_null) {
				$type = clone $type; // not to be affected the source
				$type->remove_nullable();

				// use the cloned one
				$this->members[$key] = $type;
			}
		}
	}

	public function is_all_array_types() {
		foreach ($this->members as $member_type) {
			if (!$member_type instanceof ArrayType) {
				return false;
			}
		}

		return true;
	}

	public function is_all_dict_types() {
		foreach ($this->members as $member_type) {
			if (!$member_type instanceof DictType) {
				return false;
			}
		}

		return true;
	}

	public function unite_type(IType $target): IType {
		$result = $target instanceof UnionType
			? $this->merge_with_union_type($target)
			: $this->merge_with_single_type($target);

		return $result;
	}

	public function merge_with_single_type(IType $target) {
		if ($this->is_contains_single_type($target)) {
			return $this;
		}

		$new = clone $this;
		$new->members[] = $target;

		return $new;
	}

	public function merge_with_union_type(UnionType $target) {
		$diff_items = [];
		foreach ($target->get_members() as $target_member) {
			$diff_items[] = $target_member;
		}

		if (!$diff_items) {
			return $this;
		}

		$new = clone $this;
		$new->members = array_merge($new->members, $diff_items);

		return $new;
	}

	public function is_contains_single_type(IType $target) {
		$contains = false;
		foreach ($this->members as $member) {
			if ($member->is_same_with($target)) {
				$contains = true;
				break;
			}
		}

		return $contains;
	}

	public function get_members_type_except(IType $target) {
		$items = [];
		foreach ($this->members as $member) {
			if (!$member->is_same_or_based_with($target)) {
				$items[] = $member;
			}
		}

		$new_type = count($items) > 1
			? TypeFactory::create_union_type($items)
			: $items[0];

		return $new_type;
	}

	public function is_same_with(IType $target) {
		if (!$target instanceof UnionType or $this->count() !== $target->count()) {
			return false;
		}

		foreach ($target->get_members() as $target_member) {
			if (!$this->is_contains_single_type($target_member)) {
				return false;
			}
		}

		return true;
	}

	public function is_based_with(IType $target) {
		foreach ($this->members as $member) {
			if (!$member->is_based_with($target)) {
				return false;
			}
		}

		return true;
	}

	public function is_same_or_based_with(IType $target) {
		foreach ($this->members as $member) {
			if (!$member->is_same_or_based_with($target)) {
				return false;
			}
		}

		return true;
	}

	public function is_accept_single_type(IType $target) {
		$accept = false;
		foreach ($this->members as $member) {
			if ($member->is_accept_type($target)) {
				$accept = true;
				break;
			}
		}

		return $accept;
	}
}

class MetaType extends SingleGenericType {
	public $name = _METATYPE;
}

class VoidType extends BaseType {
	public $name = _VOID;
	public function is_accept_single_type(IType $target) {
		return $this === $target;
	}
}

class NoneType extends BaseType {

	public $name = _NONE;

	public function get_nullable_instance(): IType {
		return $this;
	}

	public function is_accept_single_type(IType $target) {
		return false;
	}
}

class AnyType extends BaseType {
	public $name = _ANY;
	// public $nullable = true;
	public function is_accept_single_type(IType $target) {
		return true;
	}
}

class ObjectType extends BaseType {
	public $name = _OBJECT;
	public function is_accept_single_type(IType $target) {
		if ($target->has_null and !$this->nullable and !$this->has_null) {
			return false;
		}

		if ($target instanceof NoneType) {
			return $this->nullable;
		}

		return $target->symbol->declaration instanceof ClassKindredIdentifier;
	}
}

interface IScalarType {}

interface IPureType {}

// class ScalarType extends BaseType implements IScalarType {
// 	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _BOOL, _XVIEW];
// 	public $name = _SCALAR;
// }

class BytesType extends BaseType implements IScalarType {
	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _XVIEW];
	public $name = _BYTES;
}

class StringType extends BaseType implements IScalarType {
	const ACCEPT_TYPES = [_BYTES, _INT, _UINT, _PURE_STRING, _XVIEW];
	public $name = _STRING;
}

class PuresType extends StringType implements IPureType {
	const ACCEPT_TYPES = [_INT, _UINT];
	public $name = _PURE_STRING;

	public function is_same_or_based_with(IType $target) {
		return $this->symbol === $target->symbol || TypeFactory::$_string->symbol === $target->symbol;
	}
}

class IntType extends BaseType implements IScalarType, IPureType {
	const ACCEPT_TYPES = [_UINT];
	public $name = _INT;
}

class UIntType extends IntType {
	const ACCEPT_TYPES = [];
	public $name = _UINT;

	public function is_same_or_based_with(IType $target) {
		return $this->symbol === $target->symbol || TypeFactory::$_int->symbol === $target->symbol;
	}
}

class FloatType extends BaseType implements IScalarType, IPureType {
	const ACCEPT_TYPES = [_INT, _UINT];
	public $name = _FLOAT;
}

class BoolType extends BaseType implements IScalarType {
	public $name = _BOOL;
}

class IterableType extends SingleGenericType {

	public $name = _ITERABLE;

	/**
	 * the key type
	 * UInt for Array, and String/Int for Dict or classes based on Iterable
	 * @var UIntType/IntType/StringType
	 */
	// public $key_type;

	public function is_same_or_based_with(IType $target) {
		if ($this->symbol !== $target->symbol and $target->symbol !== TypeFactory::$_iterable->symbol) {
			return false;
		}

		if ($this->generic_type === null or $target->generic_type === null) {
			return $this->generic_type === $target->generic_type
				|| ($this->generic_type ?? $target->generic_type) instanceof AnyType;
		}

		return $this->generic_type->is_same_or_based_with($target->generic_type);
	}
}

class ArrayType extends IterableType {
	public $name = _ARRAY;
}

// In PHP, the subscripts for Array can be Int and String, and the String of numerical content will be automatically converted to Int
// Therefore, when using the index of PHP Array, there may actually be two data types: Int and String, which poses difficulties for strict type system implementation
// Due to the rule being an acceptable Int value, strict types mode cannot be used when generating PHP code
// When using Float/Bool as a PHP Array index, it will automatically convert to Int. Therefore, to avoid issues, it is not supported to use it directly as a String, as it may be assigned to the String/Any variable and then used as an Array index
class DictType extends IterableType {
	public $name = _DICT;
	// public $key_type; // just support String for Dict keys now
}

class CallableType extends BaseType implements ICallableDeclaration {

	use TypingTrait;

	public $name = _CALLABLE;

	public $parameters = [];

	public $is_checked;

	public function __construct(IType $return_type = null, array $parameters = []) {
		$this->declared_type = $return_type;
		$this->parameters = $parameters;
	}

	public function is_accept_single_type(IType $target) {
		if ($target->has_null and !$this->nullable and !$this->has_null) {
			return false;
		}

		if ($target instanceof NoneType) {
			return $this->nullable;
		}

		if ($this === TypeFactory::$_callable) {
			return true;
		}

		if (!$target instanceof CallableType) {
			return false;
		}

		if ($this->declared_type === null) {
			return true;
		}

		if (!$this->declared_type->is_accept_type($target->declared_type)) {
			return false;
		}

		if (count($target->parameters) > count($this->parameters)) {
			return false;
		}

		foreach ($this->parameters as $key => $protocol_param) {
			$implement_param = $target->parameters[$key] ?? null;
			if ($implement_param === null && $protocol_param->value === null) {
				return false;
			}

			if (!$this->declared_type->is_accept_type($target->declared_type)) {
				return false;
			}
		}

		return true;
	}
}

class RegexType extends BaseType {
	public $name = _REGEX;
}

class XViewType extends BaseType {

	public $name = _XVIEW;

	public function is_accept_single_type(IType $target) {
		$result = parent::is_accept_single_type($target);
		if ($result === false) {
			$result = $target->symbol->declaration->is_same_or_based_with_symbol(TypeFactory::$_iview_symbol);
		}

		return $result;
	}
}

class SelfType extends BaseType {
	public $name = _TYPE_SELF;
}

// class NamespaceType extends BaseType {
// 	public $name = _NAMESPACETYPE;
// }

// document end
