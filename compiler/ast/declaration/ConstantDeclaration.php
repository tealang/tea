<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait IConstantDeclarationTrait
{
	use DeclarationTrait;

	public $modifier;

	public $value;

	public function __construct(?string $modifier, string $name, ?BaseType $type, ?IExpression $value)
	{
		$this->modifier = $modifier;
		$this->name = $name;
		$this->type = $type;
		$this->value = $value;
	}
}

class ConstantDeclaration extends RootDeclaration
{
	use IConstantDeclarationTrait;

	const KIND = 'constant_declaration';

	/**
	 * @var Program
	 */
	public $program;
}

class ClassConstantDeclaration extends Node implements IClassMemberDeclaration
{
	use IConstantDeclarationTrait;

	const KIND = 'class_constant_declaration';

	public $is_static = true;
}
