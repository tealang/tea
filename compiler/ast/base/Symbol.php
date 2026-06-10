<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Symbol
{
	public string $name;

	public BaseDeclaration $declaration;

	public ?UseDeclaration $using = null;

	public function __construct(BaseDeclaration $decl)
	{
		$this->name = $decl->get_name();
		$this->declaration = $decl;
	}
}

class TopSymbol extends Symbol
{
	//
}

// end
