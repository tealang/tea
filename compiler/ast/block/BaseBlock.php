<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IBlock {}

trait IBlockTrait {

	public $label;

	/**
	 * @var IStatement[] | BaseExpression
	 */
	public $body;

	public $symbols = [];

	public $belong_block;

	public $is_ended_function = false;

	public function set_body_with_statements(array $statements)
	{
		$this->body = $statements;
	}
}

class ControlBlock extends BaseStatement implements IBlock {
	use IBlockTrait;
}
