<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PropertyDeclaration extends Node implements IClassMemberDeclaration, IVariableDeclaration
{
	use IVariableDeclarationTrait;

	const KIND = 'property_declaration';

	public $modifier;

	public $is_static;

	public function __construct(?string $modifier, string $name, IType $type = null, IExpression $value = null)
	{
		$this->modifier = $modifier;
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
	}
}
