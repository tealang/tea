<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class Identifiable extends BaseExpression implements IAssignable
{
	public $name;

	public $symbol;

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function get_unit()
	{
		return $this->symbol->declaration->unit;
	}

	// public function get_declaration()
	// {
	// 	$decl = $this->symbol->declaration;
	// 	if ($decl instanceof UseDeclaration) {
	// 		$decl = $decl->source_declaration;
	// 	}

	// 	return $decl;
	// }

	public function is_assignable()
	{
		$decl = $this->symbol->declaration;
		return $decl instanceof IVariableDeclaration && !$decl->is_final;
	}

	public function is_mutable()
	{
		$decl = $this->symbol->declaration;
		return $decl instanceof IVariableDeclaration && $decl->is_mutable;
	}
}

class AccessingIdentifier extends Identifiable implements IType
{
	use ITypeTrait;

	const KIND = 'accessing_identifier';

	public $basing;

	/**
	 * is static accessing mode
	 * var bool
	 */
	public $is_static;

	public $is_nullsafe;

	public function __construct(BaseExpression $basing, string $name, bool $nullsafe = false)
	{
		if ($basing instanceof PlainIdentifier) {
			$basing->is_accessing = true;
		}

		$this->basing = $basing;
		$this->name = $name;
		$this->is_nullsafe = $nullsafe;
	}
}

// use for rendering dist code
class RuntimeIdentifier extends Identifiable implements IType
{
	const KIND = 'runtime_identifier';
}

class PlainIdentifier extends Identifiable implements IType
{
	use ITypeTrait;

	const KIND = 'plain_identifier';

	public $ns;

	public $generic_types;

	public static function create_with_symbol(Symbol $symbol)
	{
		$identifier = new static($symbol->name);
		$identifier->symbol = $symbol;
		return $identifier;
	}

	public function is_based_with(IType $target)
	{
		$curr_decl = $this->symbol->declaration;
		if (!$curr_decl instanceof ClassKindredDeclaration) {
			$is = false;
		}
		else {
			$is = $curr_decl->find_based_with_symbol($target->symbol) !== null;
		}

		return $is;
	}

	public function is_same_or_based_with(IType $target)
	{
		return $this->symbol === $target->symbol || $this->is_based_with($target);
	}

	public function is_accept_single_type(IType $target)
	{
		if ($target->has_null and !$this->nullable and !$this->has_null) {
			$is = false;
		}
		elseif ($target instanceof NoneType) {
			$is = $this->nullable;
		}
		else {
			$is = $target->symbol === $this->symbol
				// || $target === TypeFactory::$_none
				// for check BuiltinTypeClassDeclaration like String
				// can not use symbol to compare BuiltinTypeClassDeclaration, because of the symbol maybe 'this'
				|| $this->symbol->declaration === $target->symbol->declaration
				|| $target->is_based_with($this)
			;
		}

		return $is;
	}
}

class ConstantIdentifier extends PlainIdentifier
{
	const KIND = 'constant_identifier';

	public $is_const_value = true;

}

class VariableIdentifier extends PlainIdentifier
{
	const KIND = 'variable_identifier';
}

class ClassKindredIdentifier extends PlainIdentifier
{
	const KIND = 'classkindred_identifier';

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function set_namespace(NamespaceIdentifier $ns)
	{
		$this->ns = $ns;
	}
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
