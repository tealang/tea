<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait ITypeTrait
{
	public function is_accept_type(BaseType $target) {
		return TypeHelper::is_accepting_type($this, $target);
	}

	public function unite(BaseType $target): BaseType {
		if ($target instanceof UnionType) {
			$result = $target->merge_with_single_type($this);
		}
		elseif ($this->is_same_with($target)
			|| ($this instanceof StringType and $target instanceof PlainType)) {
			$result = $this;
		}
		else {
			$result = new UnionType([$this, $target]);
		}

		return $result;
	}

	public function is_same_with(BaseType $target) {
		return TypeHelper::is_same_type($this, $target);
	}

	public function is_same_or_based_with(BaseType $target) {
		return TypeHelper::is_same_or_based_type($this, $target);
	}
}

abstract class BaseType extends Node
{
	use ITypeTrait;

	const KIND = 'type_identifier';

	const ACCEPT_TYPES = [];

	public string $name;

	public ?Symbol $symbol = null;

	public function is_based_with(BaseType $target) {
		return false;
	}

	public function is_accept_single_type(BaseType $target) {
		return TypeHelper::is_default_accepting_single_type($this, $target);
	}
}

class TypeReference extends BaseType
{
	const KIND = 'type_reference';

	public ?NamespaceIdentifier $ns = null;

	/**
	 * @var BaseType[]
	 */
	public array $generic_types = [];

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function set_namespace(NamespaceIdentifier $ns)
	{
		$this->ns = $ns;
	}

	public function unite(BaseType $target): BaseType {
		if ($target instanceof UnionType) {
			$result = $target->merge_with_single_type($this);
		}
		elseif ($this->symbol !== null && $target->symbol !== null && TypeHelper::is_same_or_based_type($target, $this)) {
			$result = $this;
		}
		elseif ($this->symbol !== null && $target->symbol !== null && TypeHelper::is_same_or_based_type($this, $target)) {
			$result = $target;
		}
		else {
			$result = new UnionType([$this, $target]);
		}

		return $result;
	}

	public function is_based_with(BaseType $target)
	{
		return TypeHelper::is_based_type($this, $target);
	}

	public function is_same_or_based_with(BaseType $target)
	{
		return TypeHelper::is_same_or_based_type($this, $target);
	}

	public function is_accept_single_type(BaseType $target)
	{
		return TypeHelper::is_type_reference_accepting_type($this, $target);
	}
}

abstract class SingleGenericType extends BaseType
{
	/**
	 * The generic type
	 * default to Any when is null
	 * @var BaseType
	 */
	public ?BaseType $generic_type = null;

	public function __construct(?BaseType $generic_type = null) {
		$this->generic_type = $generic_type;
	}

	public function unite(BaseType $target): BaseType {
		if ($target instanceof UnionType) {
			$result = $target->merge_with_single_type($this);
		}
		elseif ($this->name === $target->name) {
			$this_generic_type = $this->generic_type ?? TypeFactory::$_any;
			$target_generic_type = $target->generic_type ?? TypeFactory::$_any;
			if ($this_generic_type->is_same_with($target_generic_type)) {
				$result = $this;
			}
			else {
				// just to uniting the generic_type
				$united = $this_generic_type->unite($target_generic_type);
				$result = clone $this;
				$result->generic_type = $united;
			}
		}
		else {
			$result = new UnionType([$this, $target]);
		}

		return $result;
	}

	public function is_accept_single_type(BaseType $target) {
		return TypeHelper::is_single_generic_accepting_type($this, $target);
	}

	public function is_same_with(BaseType $target) {
		return TypeHelper::is_same_type($this, $target);
	}

	public function is_same_or_based_with(BaseType $target) {
		return TypeHelper::is_same_or_based_type($this, $target);
	}
}

class UnionType extends BaseType
{
	const KIND = 'union_type';

	public string $name = _UNIONTYPE;

	/**
	 * @var BaseType[]
	 */
	public array $members = [];

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

	// public function remove_nullable() {
	// 	$this->nullable = false;
	// 	foreach ($this->members as $key => $type) {
	// 		if ($type instanceof NoneType) {
	// 			unset($this->members[$key]);
	// 		}
	// 		elseif ($type->nullable or $type->has_null) {
	// 			$type = clone $type; // not to be affected the source
	// 			$type->remove_nullable();

	// 			// use the cloned one
	// 			$this->members[$key] = $type;
	// 		}
	// 	}
	// }

	public function is_all_array_types() {
		foreach ($this->members as $type) {
			if (!$type instanceof ArrayType) {
				return false;
			}
		}

		return true;
	}

	public function is_same_or_based_with(BaseType $target) {
		return TypeHelper::is_union_same_or_based_type($this, $target);
	}

	public function is_all_dict_types() {
		foreach ($this->members as $type) {
			if (!TypeHelper::is_dict_like_type($type)) {
				return false;
			}
		}

		return true;
	}

	public function has_array_or_dict_type() {
		$has = false;
		foreach ($this->members as $type) {
			if ($type instanceof ArrayType || $type instanceof DictType) {
				$has = true;
				break;
			}
		}

		return $has;
	}

	public function unite(BaseType $target): BaseType {
		$result = $target instanceof UnionType
			? $this->merge_with_union_type($target)
			: $this->merge_with_single_type($target);

		return $result;
	}

	public function deunite(BaseType $target)
	{
		$target_members = $target instanceof UnionType ? $target->members : [$target];
		$items = [];
		foreach ($this->members as $member) {
			$matched = false;
			foreach ($target_members as $target_member) {
				if (TypeHelper::is_same_or_based_type($member, $target_member)) {
					$matched = true;
					break;
				}
			}

			if (!$matched) {
				$items[] = $member;
			}
		}

		$count = count($items);
		if ($count === 0) {
			return TypeFactory::$_none;
		}

		$new_type = $count > 1
			? TypeFactory::create_union_type($items)
			: $items[0];

		return $new_type;
	}

	public function merge_with_single_type(BaseType $target): BaseType {
		if ($target instanceof AnyType) {
			$type = TypeFactory::create_union_type(array_merge($this->members, [$target]));
		}
		elseif ($this->contains_single_type($target)) {
			$type = $this;
		}
		elseif ($target instanceof SingleGenericType) {
			$type = $this->merge_with_container_type($target);
		}
		else {
			$type = clone $this;
			$type->members[] = $target;
		}

		return $type;
	}

	private function merge_with_container_type(SingleGenericType $target)
	{
		$pos = null;
		foreach ($this->members as $idx => $item) {
			if (get_class($target) === get_class($item)) {
				$pos = $idx;
				break;
			}
		}

		$result = clone $this;
		if ($pos === null) {
			$result->members[] = $target;
		}
		else {
			$member = $result->members[$pos];
			$result->members[$pos] = $member->unite($target);
		}

		return $result;
	}

	public function merge_with_union_type(UnionType $target): BaseType {
		if ($this->contains_single_type(TypeFactory::$_any) || $target->contains_single_type(TypeFactory::$_any)) {
			return TypeFactory::create_union_type(array_merge($this->members, $target->members));
		}

		$new_members = [];
		foreach ($target->get_members() as $target_member) {
			if (!$this->contains_member_type($target_member)) {
				$new_members[] = $target_member;
			}
		}

		if ($new_members) {
			$type = clone $this;
			$type->members = array_merge($type->members, $new_members);
		}
		else {
			$type = $this;
		}

		return $type;
	}

	private function contains_member_type(BaseType $target)
	{
		$is = false;
		foreach ($this->members as $member) {
			if ($member->is_same_with($target)) {
				$is = true;
				break;
			}
		}

		return $is;
	}

	public function contains_type(BaseType $target)
	{
		if ($target instanceof UnionType) {
			$is = true;
			foreach ($target->get_members() as $member) {
				if (!$this->contains_single_type($member)) {
					$is = false;
					break;
				}
			}
		}
		else {
			$is = $this->contains_single_type($target);
		}

		return $is;
	}

	public function contains_single_type(BaseType $target)
	{
		$is = false;
		foreach ($this->members as $member) {
			if ($member->is_same_with($target)) {
				$is = true;
				break;
			}
		}

		return $is;
	}

	public function is_same_with(BaseType $target) {
		return TypeHelper::is_same_union_type($this, $target);
	}

	public function is_based_with(BaseType $target) {
		return TypeHelper::is_union_based_type($this, $target);
	}

	public function is_accept_single_type(BaseType $target) {
		return TypeHelper::is_union_accepting_type($this, $target);
	}
}

class MetaType extends SingleGenericType {
	public string $name = _METATYPE;
}

class VoidType extends BaseType {
	public string $name = _VOID;
	public function is_accept_single_type(BaseType $target) {
		return $this === $target;
	}
}

class NoneType extends BaseType {

	public string $name = _NONE;

	// public function get_nullable_instance(): BaseType {
	// 	return $this;
	// }

	public function is_accept_single_type(BaseType $target) {
		return $target instanceof NoneType;
	}
}

class InvalidType extends BaseType {
	public string $name = _INVALID;
}

class InvalidableType extends BaseType {
	public string $name = 'Invalidable';
	public BaseType $valid_type;
	public LiteralExpression $sentinel;

	public function __construct(BaseType $valid_type, LiteralExpression $sentinel)
	{
		$this->valid_type = $valid_type;
		$this->sentinel = $sentinel;
	}
}

class ExcludableType extends BaseType {
	public string $name = 'Excludable';
	public BaseType $base_type;
	public LiteralExpression $sentinel;

	public function __construct(BaseType $base_type, LiteralExpression $sentinel)
	{
		$this->base_type = $base_type;
		$this->sentinel = $sentinel;
	}

	public function is_accept_single_type(BaseType $target) {
		return TypeHelper::is_excludable_accepting_type($this, $target);
	}
}

class AnyType extends BaseType {
	public string $name = _ANY;
	// public $nullable = true;
	public function is_accept_single_type(BaseType $target) {
		return !$target instanceof NoneType;
	}
}

class MixedType extends BaseType {
	public string $name = _MIXED;
	public function is_accept_single_type(BaseType $target) {
		return true;
	}
}

class ObjectType extends BaseType {
	public string $name = _OBJECT;
	public function is_accept_single_type(BaseType $target) {
		// if ($target->has_null and !$this->nullable and !$this->has_null) {
		// 	return false;
		// }

		// if ($target instanceof NoneType) {
		// 	return $this->nullable;
		// }

		return TypeHelper::is_object_accepting_type($target);
	}
}

interface IScalarType {}

interface IPureType {}

// class ScalarType extends BaseType implements IScalarType {
// 	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _BOOL, _XVIEW];
// 	public string $name = _SCALAR;
// }

class BytesType extends BaseType implements IScalarType {
	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _XVIEW];
	public string $name = _BYTES;
}

class StringType extends BaseType implements IScalarType {
	const ACCEPT_TYPES = [_BYTES, _INT, _UINT, _TEXT_TYPE, _PLAIN, _XVIEW, _METATYPE];
	public string $name = _STRING;
}

class PlainType extends StringType implements IPureType {
	const ACCEPT_TYPES = [_INT, _UINT];
	public string $name = _TEXT_TYPE;

	public function is_same_or_based_with(BaseType $target) {
		return TypeHelper::is_plain_same_or_based_type($this, $target);
	}
}

class IntType extends BaseType implements IScalarType, IPureType {
	const ACCEPT_TYPES = [_UINT];
	public string $name = _INT;
}

class UIntType extends IntType {
	const ACCEPT_TYPES = [];
	public string $name = _UINT;

	public function is_same_or_based_with(BaseType $target) {
		return TypeHelper::is_uint_same_or_based_type($this, $target);
	}
}

class FloatType extends BaseType implements IScalarType, IPureType {
	const ACCEPT_TYPES = [_INT, _UINT];
	public string $name = _FLOAT;
}

class BoolType extends BaseType implements IScalarType {
	public string $name = _BOOL;
}

class IterableType extends SingleGenericType {

	public string $name = _ITERABLE;

	/**
	 * the key type
	 * UInt for Array, and String/Int for Dict or classes based on Iterable
	 */
	// public BaseType $key_type;

	public function is_same_or_based_with(BaseType $target) {
		return TypeHelper::is_iterable_same_or_based_type($this, $target);
	}
}

class ArrayType extends IterableType {
	public string $name = _ARRAY;
}

// In PHP, the subscripts for Array can be Int and String, and the String of numerical content will be automatically converted to Int
// Therefore, when using the index of PHP Array, there may actually be two data types: Int and String, which poses difficulties for strict type system implementation
// Due to the rule being an acceptable Int value, strict types mode cannot be used when generating PHP code
// When using Float/Bool as a PHP Array index, it will automatically convert to Int. Therefore, to avoid issues, it is not supported to use it directly as a String, as it may be assigned to the String/Any variable and then used as an Array index
class DictType extends IterableType {
	public string $name = _DICT;
	// public $key_type; // just support String for Dict keys now

	/**
	 * Exact value types for literal keys when this type comes from a dict literal.
	 *
	 * @var array<string, BaseType>
	 */
	public array $known_member_types = [];

	public function unite(BaseType $target): BaseType {
		$result = parent::unite($target);
		if (!$result instanceof DictType) {
			return $result;
		}

		if (!$target instanceof DictType || !$this->has_same_known_member_types($target)) {
			$result = clone $result;
			$result->known_member_types = [];
		}

		return $result;
	}

	private function has_same_known_member_types(DictType $target): bool
	{
		if (count($this->known_member_types) !== count($target->known_member_types)) {
			return false;
		}

		foreach ($this->known_member_types as $key => $type) {
			if (!isset($target->known_member_types[$key])
				|| !$type->is_same_with($target->known_member_types[$key])) {
				return false;
			}
		}

		return true;
	}
}

class CallableType extends BaseType implements IDeclaration, ICallableDeclaration {

	use TypingTrait;

	public string $name = _CALLABLE;

	/**
	 * @var ParameterDeclaration[]
	 */
	public array $parameters = [];

	public function __construct(?BaseType $return_type = null, array $parameters = []) {
		$this->declared_type = $return_type;
		$this->parameters = $parameters;
	}

	public function get_name(): ?string
	{
		return $this->name;
	}

	public function is_accept_single_type(BaseType $target) {
		return TypeHelper::is_callable_accepting_type($this, $target);
	}
}

class RegexType extends BaseType {
	public string $name = _REGEX;
}

class XViewType extends BaseType {

	public string $name = _XVIEW;

	public function is_accept_single_type(BaseType $target) {
		return TypeHelper::is_xview_accepting_type($this, $target);
	}
}

class SelfType extends BaseType {
	public string $name = _TYPE_SELF;
}

class IntersectionType extends BaseType
{
	const KIND = 'intersection_type';
	public string $name = _INTERSECTIONTYPE;
	/**
	 * @var BaseType[]
	 */
	public array $members = [];

	public function __construct(array $members = [])
	{
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

	public function render(BaseCoder $coder): string
	{
		$items = [];
		foreach ($this->members as $member) {
			$items[] = $member->render($coder);
		}
		return implode('&', $items);
	}

	public function is($type): bool
	{
		foreach ($this->members as $member) {
			if (!$member->is($type)) {
				return false;
			}
		}
		return true;
	}
}

// class NamespaceType extends BaseType {
// 	public $name = _NAMESPACETYPE;
// }

// document end
