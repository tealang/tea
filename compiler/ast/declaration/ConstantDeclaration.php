<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IConstantDeclaration {}

trait IConstantDeclarationTrait
{
	use DeclarationTrait;

	public $ns;

	public $modifier;

	public $value;

	public function __construct(?string $modifier, string $name, BaseType $type = null, BaseExpression $value = null)
	{
		if ($modifier !== null && $modifier === _PUBLIC) {
			$this->is_unit_level = true;
		}

		$this->is_static = true;
		$this->modifier = $modifier;
		$this->name = $name;
		$this->type = $type;
		$this->value = $value;
	}
}

class ConstantDeclaration extends Node implements IConstantDeclaration, IRootDeclaration, IStatement
{
	use IConstantDeclarationTrait;

	const KIND = 'constant_declaration';

	/**
	 * @var Program
	 */
	public $program;
}

class ClassConstantDeclaration extends Node implements IConstantDeclaration, IClassMemberDeclaration
{
	use IClassMemberDeclarationTrait, IConstantDeclarationTrait;

	const KIND = 'class_constant_declaration';
}
