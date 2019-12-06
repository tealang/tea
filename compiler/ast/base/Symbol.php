<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Symbol
{
	public $name;

	/**
	 * @var IDeclaration
	 */
	public $declaration;

	public function __construct(IDeclaration $declaration)
	{
		$this->name = $declaration->name;
		$this->declaration = $declaration;
	}

	public function is_assignable()
	{
		return $this->declaration instanceof IVariableDeclaration && $this->declaration->reassignable;
	}
}

class NamespaceSymbol extends Symbol
{
	public $name;

	public $declaration;

	public function __construct(IMemberDeclaration $declaration)
	{
		$this->name = $declaration->name;
		$this->declaration = $declaration;
	}

	public function is_assignable()
	{
		return false;
	}
}

