<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ArrayLiteral extends ArrayExpression implements ILiteral
{
	const KIND = 'array_literal';
}

class DictLiteral extends DictExpression implements ILiteral
{
	const KIND = 'dict_literal';
}
