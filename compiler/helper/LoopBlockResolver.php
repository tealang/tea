<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class LoopBlockResolver
{
	private $infer_expression;
	private $infer_block;
	private ElseBlockTypeReducer $else_block_type_reducer;

	public function __construct(
		callable $infer_expression,
		callable $infer_block,
		ElseBlockTypeReducer $else_block_type_reducer
	) {
		$this->infer_expression = $infer_expression;
		$this->infer_block = $infer_block;
		$this->else_block_type_reducer = $else_block_type_reducer;
	}

	public function infer_for_block(ForBlock $node): ?BaseType
	{
		$this->check_expression_list($node->args1);
		$this->check_expression_list($node->args2);
		$this->check_expression_list($node->args3);

		$result_type = ($this->infer_block)($node);
		if ($node->else) {
			$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	public function infer_while_block(WhileBlock $node): ?BaseType
	{
		($this->infer_expression)($node->condition);
		$result_type = ($this->infer_block)($node);

		return $result_type;
	}

	public function infer_dowhile_block(DoWhileBlock $node): ?BaseType
	{
		$result_type = ($this->infer_block)($node);
		($this->infer_expression)($node->condition);

		return $result_type;
	}

	public function infer_loop_block(LoopBlock $node): ?BaseType
	{
		$result_type = ($this->infer_block)($node);

		return $result_type;
	}

	private function check_expression_list(array $list): void
	{
		foreach ($list as $expr) {
			($this->infer_expression)($expr);
		}
	}
}
