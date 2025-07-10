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
	public $value;

	public $modifier;

	public $is_static;

	public $belong_block;

	public function __construct(?string $modifier, string $name, ?IType $type = null)
	{
		$this->modifier = $modifier;
		$this->name = $name;
		$this->declared_type = $type;
	}
}

class ClassConstantDeclaration extends BaseClassMemberDeclaration implements IConstantDeclaration
{
	const KIND = 'class_constant_declaration';

	public $is_static = true;

}

class PropertyDeclaration extends BaseClassMemberDeclaration implements IBlock, IVariableDeclaration
{
	const KIND = 'property_declaration';

	public $is_final;

	// to mark the value is mutable
	// just for Array|Dict|Object values
	public $is_mutable = true;
}

class MethodDeclaration extends BaseClassMemberDeclaration implements IFunctionDeclaration
{
	use IScopeBlockTrait;

	const KIND = 'method_declaration';

	public $is_abstract;
}

// end
