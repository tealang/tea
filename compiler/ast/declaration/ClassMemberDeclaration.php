<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IClassMemberDeclaration {}

abstract class BaseClassMemberDeclaration extends BaseDeclaration implements IClassMemberDeclaration
{
	/**
	 * Default value
	 */
	public ?BaseExpression $value = null;

	public ?string $modifier = null;

	public ?bool $is_static = null;

	public function __construct(?string $modifier, string $name, ?BaseType $type = null)
	{
		$this->modifier = $modifier;
		$this->name = $name;
		$this->declared_type = $type;
	}
}

class ClassConstantDeclaration extends BaseClassMemberDeclaration implements IConstantDeclaration
{
	const KIND = 'class_constant_declaration';

	// is_static is always true for class constants
	
	public function __construct(?string $modifier, string $name, ?BaseType $type = null)
	{
		parent::__construct($modifier, $name, $type);
		$this->is_static = true;
	}
}

class PropertyDeclaration extends BaseClassMemberDeclaration implements IVariableDeclaration
{
	const KIND = 'property_declaration';

	public bool $is_final = false;

	/**
	 * To mark the value is mutable
	 * Just for Array|Dict|Object values
	 */
	public bool $is_mutable = true;

	/**
	 * PHP 8.1+ readonly property
	 */
	public bool $is_readonly = false;

	/**
	 * PHP 8.4+ property hooks
	 */
	public bool $has_hooks = false;
	public ?BaseExpression $hook_get = null;
	public string|BaseExpression|null $hook_set = null;

	/**
	 * PHP 8.4+ asymmetric visibility
	 */
	public ?string $promoted_property_set_modifier = null;  // for constructor property promotion
	public ?string $set_visibility = null;  // explicit set visibility (public/protected/private)
	public ?string $get_visibility = null;  // explicit get visibility (public/protected/private)
}

class MethodDeclaration extends BaseClassMemberDeclaration implements IFunctionDeclaration
{
	use FunctionTrait;

	const KIND = 'method_declaration';

	public bool $is_abstract = false;
}

// end
