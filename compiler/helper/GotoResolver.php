<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class GotoResolver
{
	/**
	 * @var array<string, LabelStatement>
	 */
	private array $labels = [];

	private CheckerContext $context;

	public function __construct(CheckerContext $context)
	{
		$this->context = $context;
	}

	public function infer_with_collected_labels(IBlock $block, callable $infer_block): ?BaseType
	{
		$prev_labels = $this->labels;
		$this->labels = $this->collect_goto_labels($block);
		try {
			return $infer_block($block);
		}
		finally {
			$this->labels = $prev_labels;
		}
	}

	/**
	 * @return array<string, LabelStatement>
	 */
	public function collect_goto_labels(IBlock $block): array
	{
		$labels = [];
		$this->collect_goto_labels_from_block($block, $labels);
		return $labels;
	}

	/**
	 * @param array<string, LabelStatement> $labels
	 */
	private function collect_goto_labels_from_block(IBlock $block, array &$labels): void
	{
		if (isset($block->body) && is_array($block->body)) {
			foreach ($block->body as $statement) {
				if ($statement instanceof LabelStatement) {
					if (isset($labels[$statement->label])) {
						$error = $this->context->new_syntax_error("Duplicated goto label '{$statement->label}'", $statement);
						throw $error;
					}

					$labels[$statement->label] = $statement;
				}

				if ($statement instanceof IBlock) {
					$this->collect_goto_labels_from_block($statement, $labels);
				}
			}
		}

		if ($block instanceof BaseIfBlock || $block instanceof SwitchBlock) {
			$this->collect_goto_labels_from_else_branches($block, $labels);
		}

		if ($block instanceof IExceptAble) {
			foreach ($block->catchings as $catching) {
				$this->collect_goto_labels_from_block($catching, $labels);
			}

			if ($block->finally) {
				$this->collect_goto_labels_from_block($block->finally, $labels);
			}
		}

		if ($block instanceof SwitchBlock && isset($block->branches)) {
			foreach ($block->branches as $branch) {
				$this->collect_goto_labels_from_block($branch, $labels);
			}
		}
	}

	/**
	 * @param array<string, LabelStatement> $labels
	 */
	private function collect_goto_labels_from_else_branches(BaseIfBlock|SwitchBlock $block, array &$labels): void
	{
		$branch = $block->else;
		while ($branch instanceof IBlock) {
			$this->collect_goto_labels_from_block($branch, $labels);
			$branch = $branch instanceof ElseIfBlock ? $branch->else : null;
		}
	}

	public function check_goto_statement(GotoStatement $node): void
	{
		if (!isset($this->labels[$node->target_label])) {
			$error = $this->context->new_syntax_error("Goto target label '{$node->target_label}' not found", $node);
			throw $error;
		}
	}
}
