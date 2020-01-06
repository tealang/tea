<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

use Exception;

interface IType {}

class BaseType extends Node implements IType {

	const KIND = 'type_identifier';

	const ACCEPT_TYPES = [];

	public $name;

	// for render
	public $dist_name;

	public $symbol;

	public function __construct(string $name) {
		$this->name = $name;
	}

	// to support class
	public function is_based_with(IType $type) {
		return false;
	}

	public function is_accept_type(IType $type) {
		if ($this->symbol === null) {
			dump($this);exit;
		}
		return $type === $this
			|| $type === TypeFactory::$_none
			|| in_array($type->name, static::ACCEPT_TYPES, true)
			|| $type->symbol->declaration === $this->symbol->declaration;
	}

	public function is_same_or_based_with(IType $type) {
		return $this === $type;
	}
}

class NoneType extends BaseType {
	public function is_accept_type(IType $type) {
		return false;
	}
}

class AnyType extends BaseType {
	public function is_accept_type(IType $type) {
		return true;
	}
}

class ScalarType extends BaseType {
	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _BOOL, _XVIEW];
}

// class BytesType extends ScalarType {
// 	const ACCEPT_TYPES = [_STRING, _INT, _UINT, _XVIEW];
// }

class StringType extends ScalarType {
	// PHP中Array下标可为Int和String，并且会将数字内容的String自动转为Int
	// 故在使用PHP Array的下标时，实际上可能有Int和String两种数据类型，这给严格的类型系统实现带来困难
	// 由于此处规则为可接受Int值，导致在生成PHP代码时，不能用strict_types模式
	// Float/Bool在作为PHP Array下标时，会自动转为Int，故为避免问题，不支持直接作为String使用，因为可能发生赋值到String/Any变量后，再作为Array下标的情况
	const ACCEPT_TYPES = [_INT, _UINT, _XVIEW];
}

class FloatType extends ScalarType {
	const ACCEPT_TYPES = [_INT, _UINT];  // Int/UInt作为Float时可能会丢失精度
}

class IntType extends ScalarType {
	const ACCEPT_TYPES = [_UINT];
}

class UIntType extends ScalarType {
	// UInt在转成PHP后实际上为Int类型
}

class BoolType extends ScalarType {
	//
}

class IterableType extends BaseType {
	/**
	 * the key type
	 * UInt for Array, and String/Int for Dict or classes based on Iterable
	 * @var UIntType/IntType/StringType
	 */
	public $key_type;

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

		if ($type->value_type === null) {
			return false;
		}

		// if current value type is Any, then accept any types
		if ($this->value_type === null || $this->value_type === TypeFactory::$_any) {
			return true;
		}

		return $this->value_type->is_accept_type($type->value_type);
	}
}

class ArrayType extends IterableType {
	public $is_collect_mode;
}

// 当Float作为PHP Array下标时, 将会自动转为Int, 容易出问题, 需转换成String
class DictType extends IterableType {
	// public $key_type; // just support String for Dict keys now
}

class CallableType extends BaseType {
	//
}

class RegexType extends BaseType {
	//
}

class XViewType extends BaseType {
	public function is_accept_type(IType $type) {
		if ($type === $this || $type === TypeFactory::$_none || $type->symbol->declaration === $this->symbol->declaration) {
			return true;
		}

		return $type->symbol->declaration->is_same_or_based_with_symbol(TypeFactory::$_iview_symbol);
	}
}

class MetaClassType extends BaseType {
	public function is_accept_type(IType $type) {
		if ($type === $this || $type === TypeFactory::$_none || $this->value_type->symbol === $type->value_type->symbol) {
			return true;
		}

		return false;
	}
}

// document end
