<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IVariableDeclaration extends IValuableDeclaration {
	public function get_bound_type(): BaseType;
	public function bind_type(BaseType $type);
}

trait VariableTrait
{
	/**
	 * Default value
	 */
	public ?BaseExpression $value = null;

	public bool $is_final = false;

	/**
	 * To mark the value is mutable
	 * Just for Array|Dict|Object values
	 */
	public bool $is_mutable = true;

	public function __construct(string $name, ?BaseType $type = null, ?BaseExpression $value = null)
	{
		$this->name = $name;
		$this->declared_type = $type;
		$this->value = $value;
	}
}

abstract class BaseVariableDeclaration extends BaseDeclaration implements IVariableDeclaration
{
	use VariableTrait;
}

class VariableDeclaration extends BaseVariableDeclaration implements IStatement
{
	const KIND = 'variable_declaration';

	/**
	 * Defined in which block
	 */
	public ?IBlock $block = null;
}

class FinalVariableDeclaration extends VariableDeclaration
{
	// is_final is always true for FinalVariableDeclaration
	
	public function __construct(string $name, ?BaseType $type = null, ?BaseExpression $value = null)
	{
		parent::__construct($name, $type, $value);
		$this->is_final = true;
	}
}

// class InvariantDeclaration extends VariableDeclaration
// {
// 	public $is_final = true;
// 	public $is_mutable;
// }

class SuperVariableDeclaration extends RootDeclaration implements IVariableDeclaration
{
	use VariableTrait;
	const KIND = 'super_variable_declaration';
}

class ParameterDeclaration extends BaseVariableDeclaration
{
	const KIND = 'parameter_declaration';

	public bool $is_inout = false;

	/**
	 * Is a variadic parameter
	 * To receive multiple arguments in a parameter
	 */
	public bool $is_variadic = false;

	/**
	 * For PHP 8.0+ constructor property promotion
	 * Stores 'public', 'protected', or 'private'
	 */
	public ?string $promoted_property_modifier = null;

	/**
	 * For PHP 8.4+ constructor promoted property asymmetric visibility.
	 */
	public ?string $promoted_property_set_modifier = null;

	public ?string $set_visibility = null;

	public ?string $get_visibility = null;

	/**
	 * @var RuleOptions
	 */
	// public $rule_options;

	/**
	 * @param RuleOptions $rule_options
	 */
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
