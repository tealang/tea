<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IVariableDeclaration extends IValuedDeclaration {}

abstract class BaseVariableDeclaration extends Node implements IVariableDeclaration
{
	use DeclarationTrait;

	public $value;

	public $is_final;

	// to mark the value is mutable
	// just for Array|Dict|Object values
	public $is_mutable = true;

	public function __construct(string $name, IType $type = null, BaseExpression $value = null)
	{
		$this->name = $name;
		$this->declared_type = $type;
		$this->value = $value;
	}
}

class VariableDeclaration extends BaseVariableDeclaration implements IStatement
{
	const KIND = 'variable_declaration';
	public $block; // defined in which block?
}

class FinalVariableDeclaration extends VariableDeclaration
{
	public $is_final = true;
}

class InvariantDeclaration extends VariableDeclaration
{
	public $is_final = true;
	public $is_mutable;
}

class SuperVariableDeclaration extends VariableDeclaration implements IRootDeclaration
{
	const KIND = 'super_variable_declaration';
	public $is_final;
	public $program;
}

class ParameterDeclaration extends BaseVariableDeclaration
{
	const KIND = 'parameter_declaration';

	public $is_inout;

	// is a variadic parameter
	// to receive multiple arguments in a parameter
	public $is_variadic;

	// /**
	//  * @var RuleOptions
	//  */
	// public $rule_options;

	// public function set_rule_options(RuleOptions $rule_options)
	// {
	// 	$this->rule_options = $rule_options;
	// }
}

// The rules to supports the declarative programming
// class RuleOptions
// {
// 	/**
// 	 * The value of Int/UInt/Float
// 	 * @var array  [min, max]
// 	 */
// 	public $range;

// 	/**
// 	 * @var int
// 	 */
// 	public $maxlen;

// 	/**
// 	 * @var int
// 	 */
// 	public $minlen;

// 	/**
// 	 * The pattern of String
// 	 * @var string
// 	 */
// 	public $regex;

// 	/**
// 	 * The custom defineds ...
// 	 */
// 	// public $other;

// 	public function __construct(array $options)
// 	{
// 		foreach ($options as $key => $value) {
// 			$this->$key = $value;
// 		}
// 	}
// }

// end
