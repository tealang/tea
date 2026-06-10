<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IStatement {}

class BaseStatement extends Node implements IStatement
{
	/**
	 * The block this statement belongs to
	 */
	public BaseDeclaration|IBlock|null $belong_block = null;
}
