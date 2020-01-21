<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IDeclaration {}
interface IRootDeclaration extends IDeclaration {}
interface ICallableDeclaration extends IDeclaration {}
interface IMemberDeclaration extends IDeclaration {}
interface IClassMemberDeclaration extends IMemberDeclaration {}

trait DeclarationTrait {
	/**
	 * @var Docs
	 */
	public $docs;

	public $label;

	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var IType
	 */
	public $type;

	/**
	 * to catch symbol for self
	 * @var Symbol
	 */
	public $symbol;

	public $checked = false; // set true when checked by ASTChecker

	// public or used by other programs
	public $is_unit_level = false;
}

class RootDeclaration extends Node implements IRootDeclaration, IStatement
{
	use DeclarationTrait;
}
