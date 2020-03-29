<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IVariableDeclaration extends IDeclaration {}

abstract class BaseVariableDeclaration extends Node
{
	use DeclarationTrait;

	public $value;

	public $is_reassignable = true;

	// just for Array / Dict
	public $is_value_mutable = true;

	public function __construct(string $name, IType $type = null, IExpression $value = null)
	{
		$this->name = $name;
		$this->type = $type;
		$this->value = $value;
	}
}

class VariableDeclaration extends BaseVariableDeclaration implements IVariableDeclaration, IStatement
{
	const KIND = 'variable_declaration';
	public $block; // defined in which block?
}

class NonReassignableVarDeclaration extends VariableDeclaration
{
	public $is_reassignable = false;
}

class InvariantDeclaration extends VariableDeclaration
{
	public $is_reassignable = false;
	public $is_value_mutable = false;
}

class SuperVariableDeclaration extends VariableDeclaration implements IRootDeclaration
{
	use DeferChecksTrait;
	const KIND = 'super_variable_declaration';
	public $is_reassignable = false;
}

class ParameterDeclaration extends BaseVariableDeclaration implements IVariableDeclaration
{
	const KIND = 'parameter_declaration';
	public $is_value_mutable = false;
	// public $is_referenced;
}

