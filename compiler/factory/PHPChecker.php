<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPChecker extends ASTChecker
{
	const NS_SEPARATOR = PHPParser::NS_SEPARATOR;

	protected $is_weakly_checking = true;

}

// end
