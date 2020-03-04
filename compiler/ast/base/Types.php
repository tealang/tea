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

trait SubValuedTrait {
	/**
	 * the value type
	 * default to Any when null
	 * @var IType
	 */
	public $value_type;

	public function set_value_type(IType $type) {
		$this->value_type = $type;
	}

	public function is_accept_type(IType $type) {
		if ($type === $this || $type === TypeFactory::$_none) {
			return true;
		}

		// for the builtin classes
		if ($type instanceof ClassLikeIdentifier && $this->symbol === $type->symbol) {
			return true;
		}

		if (!$type instanceof static) {
			return false;
		}

		// if current value type is Any, then accept any types
		if ($this->value_type === null || $this->value_type === TypeFactory::$_any) {
			return true;
		}

		if ($type->value_type === null) {
			return false;
		}

		return $this->value_type->is_accept_type($type->value_type);
	}
}

abstract class BaseType extends Node implements IType {
	const KIND = 'type_identifier';

	const ACCEPT_TYPES = [];

	public $name;

	// for render
	public $dist_name;

	public $symbol;

	// to support class
	public function is_based_with(IType $type) {
		return false;
	}

	public function is_accept_type(IType $type) {
		return $type === $this
			|| $type === TypeFactory::$_none
			|| in_array($type->name, static::ACCEPT_TYPES, true)
			|| $type->symbol->declaration === $this->symbol->declaration;
	}

	public function is_same_or_based_with(IType $type) {
		return $this === $type;
	}
}

class MetaType extends BaseType {
	use SubValuedTrait;
	public $name = _METATYPE;
}

class VoidType extends BaseType {
	public $name = _VOID;
	public function is_accept_type(IType $type) {
		return $this === $type;
	}
}

class NoneType extends BaseType {
	public $name = _NONE;
	public function is_accept_type(IType $type) {
		return false;
	}
}

class AnyType extends BaseType {
	public $name = _ANY;
	public function is_accept_type(IType $type) {
		return true;
	}
}

// class ScalarType extends BaseType {
// 	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _BOOL, _XVIEW];
// 	public $name = _SCALAR;
// }

// class BytesType {
// 	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _XVIEW];
// }

class StringType extends BaseType {
	// PHP中Array下标可为Int和String，并且会将数字内容的String自动转为Int
	// 故在使用PHP Array的下标时，实际上可能有Int和String两种数据类型，这给严格的类型系统实现带来困难
	// 由于此处规则为可接受Int值，导致在生成PHP代码时，不能用strict_types模式
	// Float/Bool在作为PHP Array下标时，会自动转为Int，故为避免问题，不支持直接作为String使用，因为可能发生赋值到String/Any变量后，再作为Array下标的情况
	const ACCEPT_TYPES = [_INT, _UINT, _XVIEW];
	public $name = _STRING;
}

class FloatType extends BaseType {
	const ACCEPT_TYPES = [_INT, _UINT];  // Int/UInt作为Float时可能会丢失精度
	public $name = _FLOAT;
}

class IntType extends BaseType {
	const ACCEPT_TYPES = [_UINT];
	public $name = _INT;
}

class UIntType extends BaseType {
	// would output int for php
	public $name = _UINT;
}

class BoolType extends BaseType {
	public $name = _BOOL;
}

class IterableType extends BaseType {
	use SubValuedTrait;

	public $name = _ITERABLE;

	/**
	 * the key type
	 * UInt for Array, and String/Int for Dict or classes based on Iterable
	 * @var UIntType/IntType/StringType
	 */
	public $key_type;
}

class ArrayType extends IterableType {
	public $name = _ARRAY;
	public $is_collect_mode;
}

// 当Float作为PHP Array下标时, 将会自动转为Int, 容易出问题, 需转换成String
class DictType extends IterableType {
	public $name = _DICT;
	// public $key_type; // just support String for Dict keys now
}

class CallableType extends BaseType implements ICallableDeclaration {
	public $name = _CALLABLE;

	/**
	 * @var BaseType
	 */
	public $type;

	public $parameters = [];

	public function is_accept_type(IType $type)
	{
		if ($type === TypeFactory::$_none || $this === TypeFactory::$_callable) {
			return true;
		}

		if (!$type instanceof static) {
			return false;
		}

		if ($this->type === null) {
			return true;
		}

		if (!$this->type->is_accept_type($type->type)) {
			return false;
		}

		if (count($type->parameters) > count($this->parameters)) {
			return false;
		}

		foreach ($this->parameters as $key => $protocol_param) {
			$implement_param = $type->parameters[$key] ?? null;
			if ($implement_param === null && $protocol_param->value === null) {
				return false;
			}

			if (!$this->type->is_accept_type($type->type)) {
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
	public function is_accept_type(IType $type) {
		if ($type === $this || $type === TypeFactory::$_none || $type->symbol->declaration === $this->symbol->declaration) {
			return true;
		}

		return $type->symbol->declaration->is_same_or_based_with_symbol(TypeFactory::$_iview_symbol);
	}
}

class NamespaceType extends BaseType {
	public $name = _NAMESPACE;
}

// document end
