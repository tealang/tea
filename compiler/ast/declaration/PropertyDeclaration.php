<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IClassMemberDeclaration extends IMemberDeclaration {}

trait ClassMemberDeclarationTrait
{
	public $modifier;
	public $is_static = false;
	public $belong_block;
}

class PropertyDeclaration extends BaseVariableDeclaration implements IClassMemberDeclaration, IBlock
{
	use ClassMemberDeclarationTrait;

	const KIND = 'property_declaration';

	public function __construct(?string $modifier, string $name, IType $type = null, BaseExpression $value = null)
	{
		$this->modifier = $modifier;
		$this->name = $name;
		$this->value = $value;
		$this->declared_type = $type;
	}
}

// end
