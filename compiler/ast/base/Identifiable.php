<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class Identifiable extends Node implements IExpression, ICallee, IAssignable, IType
{
	public $name;

	public $symbol;

	/**
	 * is has any operation like accessing or call
	 * used for render target code
	 * @var bool
	 */
	public $with_call_or_accessing;

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function get_unit()
	{
		return $this->symbol->declaration->unit;
	}
}

class AccessingIdentifier extends Identifiable
{
	const KIND = 'accessing_identifier';

	public $master;

	public function __construct(IExpression $master, string $name)
	{
		$master->with_call_or_accessing = true;

		$this->master = $master;
		$this->name = $name;
	}
}

class PlainIdentifier extends Identifiable
{
	const KIND = 'plain_identifier';

	public static function create_with_symbol(Symbol $symbol)
	{
		$identifier = new static($symbol->name);
		$identifier->symbol = $symbol;
		return $identifier;
	}

	public function to_class_identifier()
	{
		$identifier = new ClassIdentifier($this->name);
		$identifier->symbol = $this->symbol;
		return $identifier;
	}

	public function is_based_with(IType $type)
	{
		if (!$this->symbol->declaration instanceof ClassLikeDeclaration) {
			return false;
		}

		if ($this->symbol->declaration->is_based_with_symbol($type->symbol)) {
			return true;
		}

		return false;
	}

	public function is_accept_type(IType $type)
	{
		return $type->symbol === $this->symbol || $type === TypeFactory::$_none
			// for check MetaClassDeclaration like String
			// can not use symbol to compare MetaClassDeclaration, because of the symbol maybe 'this'
			|| $this->symbol->declaration === $type->symbol->declaration
			|| $type->is_based_with($this)
		;
	}

	public function is_same_or_based_with(IType $type)
	{
		return $this->symbol === $type->symbol || $this->is_based_with($type);
	}
}

class ConstantIdentifier extends PlainIdentifier implements ILiteral
{
	const KIND = 'constant_identifier';
}

class VariableIdentifier extends PlainIdentifier
{
	const KIND = 'variable_identifier';
}

class ClassLikeIdentifier extends PlainIdentifier
{
	const KIND = 'classlike_identifier';

	public $ns;

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function set_namespace(PlainIdentifier $ns)
	{
		$this->ns = $ns;
	}
}

class ClassIdentifier extends ClassLikeIdentifier
{
	const KIND = 'class_identifier';
}

// class UriIdentifier extends Identifiable
// {
// 	const KIND = 'uri_identifier';

// 	public $ns_names;

// 	public function __construct(array $ns_names, string $name)
// 	{
// 		$this->ns_names = $ns_names;
// 		$this->name = $name;
// 	}

// 	public function get_first_ns_name()
// 	{
// 		return $this->ns_names[0];
// 	}

// 	public function is_compatible_to(IType $type)
// 	{
// 		return $this->symbol === $type->symbol;
// 	}

// 	public function __toString()
// 	{
// 		return join(_DOT, $this->ns_names) . _DOT . $this->name;
// 	}
// }
