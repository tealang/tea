<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class Identifiable extends BaseExpression implements IAssignable
{
	public string $name;

	public ?Symbol $symbol = null;

	public function __construct(string $name)
	{
		$this->name = $name;
	}
}

class AccessingIdentifier extends Identifiable
{
	const KIND = 'accessing_identifier';

	public BaseExpression $basing;

	public ?bool $is_static = null;

	public bool $is_nullsafe = false;

	public function __construct(BaseExpression $basing, string $name, bool $nullsafe = false)
	{
		$basing->set_purpose(PURPOSE_ACCESSING);

		$this->basing = $basing;
		$this->name = $name;
		$this->is_nullsafe = $nullsafe;
	}
}

// use for rendering dist code
class NativeIdentifier extends Identifiable
{
	const KIND = 'native_identifier';
}

class PlainIdentifier extends Identifiable
{
	// use ITypeTrait;

	const KIND = 'plain_identifier';

	public ?NamespaceIdentifier $ns = null;

	public ?array $generic_types = null;

	// public function is_based_with(BaseType $target)
	// {
	// 	$this_decl = $this->symbol->declaration;
	// 	if ($this_decl instanceof ClassKindredDeclaration) {
	// 		$is = $this_decl->find_based_with_symbol($target->symbol) !== null;
	// 	}
	// 	else {
	// 		$is = false;
	// 	}

	// 	return $is;
	// }

	// public function is_same_or_based_with(BaseType $target)
	// {
	// 	return $this->symbol === $target->symbol || $this->is_based_with($target);
	// }

	// public function is_accept_single_type(BaseType $target)
	// {
	// 	// if ($target->has_null and !$this->nullable and !$this->has_null) {
	// 	// 	$is = false;
	// 	// }
	// 	// elseif ($target instanceof NoneType) {
	// 	// 	$is = $this->nullable;
	// 	// }
	// 	// else {
	// 		$is = $target->symbol === $this->symbol
	// 			// || $target === TypeFactory::$_none
	// 			// for check BuiltinTypeClassDeclaration like String
	// 			// can not use symbol to compare BuiltinTypeClassDeclaration, because of the symbol maybe 'this'
	// 			|| $this->symbol->declaration === $target->symbol->declaration
	// 			|| $target->is_based_with($this)
	// 		;
	// 	// }

	// 	return $is;
	// }
}

class ConstantIdentifier extends PlainIdentifier
{
	const KIND = 'constant_identifier';

	public function __construct(string $name)
	{
		$this->name = $name;
		$this->is_const_value = true;
	}
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

// 	public function is_compatible_to(BaseType $type)
// 	{
// 		return $this->symbol === $type->symbol;
// 	}

// 	public function __toString()
// 	{
// 		return join(_DOT, $this->ns_names) . _DOT . $this->name;
// 	}
// }
