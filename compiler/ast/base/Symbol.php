<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
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

	/**
	 * @var ?UseDeclaration
	 */
	public $using;

	public function __construct(IDeclaration $decl)
	{
		$this->name = $decl->name;
		$this->declaration = $decl;
		// $decl->symbol = $this;
	}
}

class TopSymbol extends Symbol
{
	//
}

// end
