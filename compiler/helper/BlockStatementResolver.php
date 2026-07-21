<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class BlockStatementResolver
{
	private CheckerContext $context;
	private SimpleStatementResolver $simple_statement_resolver;
	private GotoResolver $goto_resolver;
	private FlowNarrowingResolver $flow_narrowing_resolver;
	private LoopBlockResolver $loop_block_resolver;
	private IterationResolver $iteration_resolver;
	private ExceptionBlockResolver $exception_block_resolver;
	private SwitchMatchResolver $switch_match_resolver;
	private $infer_expression;
	private $reduce_types;

	public function __construct(
		CheckerContext $context,
		SimpleStatementResolver $simple_statement_resolver,
		GotoResolver $goto_resolver,
		FlowNarrowingResolver $flow_narrowing_resolver,
		LoopBlockResolver $loop_block_resolver,
		IterationResolver $iteration_resolver,
		ExceptionBlockResolver $exception_block_resolver,
		SwitchMatchResolver $switch_match_resolver,
		callable $infer_expression,
		callable $reduce_types
	) {
		$this->context = $context;
		$this->simple_statement_resolver = $simple_statement_resolver;
		$this->goto_resolver = $goto_resolver;
		$this->flow_narrowing_resolver = $flow_narrowing_resolver;
		$this->loop_block_resolver = $loop_block_resolver;
		$this->iteration_resolver = $iteration_resolver;
		$this->exception_block_resolver = $exception_block_resolver;
		$this->switch_match_resolver = $switch_match_resolver;
		$this->infer_expression = $infer_expression;
		$this->reduce_types = $reduce_types;
	}

	public function infer_else_block(IElseBlock $node): ?BaseType
	{
		if ($node instanceof ElseIfBlock) {
			$result_type = $this->flow_narrowing_resolver->infer_base_if_block($node);
		}
		else {
			$result_type = $this->infer_block($node);
		}

		return $result_type;
	}

	public function infer_single_expression_block(IBlock $block): BaseType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->context->get_block();
		$this->context->set_block($block);

		$infered = ($this->infer_expression)($block->body);

		$this->context->set_block($temp_block);

		return $infered;
	}

	public function infer_block(IBlock $block): ?BaseType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->context->get_block();
		$this->context->set_block($block);

		$infereds = [];
		foreach ($block->body as $statement) {
			if ($type = $this->infer_statement($statement)) {
				$infereds[] = $type;
			}
		}

		$this->context->set_block($temp_block);

		return $infereds ? ($this->reduce_types)($infereds, $block) : null;
	}

	public function infer_statement(IStatement $node): ?BaseType
	{
		$infered = null;
		switch ($node::KIND) {
			case NormalStatement::KIND:
				$node->expression and ($this->infer_expression)($node->expression);
				break;
			case EchoStatement::KIND:
				$this->simple_statement_resolver->check_echo_statement($node);
				break;
			case ThrowStatement::KIND:
				$this->simple_statement_resolver->check_throw_statement($node);
				break;
			case VarStatement::KIND:
				$this->simple_statement_resolver->check_var_statement($node);
				break;
			case ReturnStatement::KIND:
				$infered = $this->simple_statement_resolver->infer_return_statement($node);
				break;
			case ExitStatement::KIND:
				$this->simple_statement_resolver->check_exit_statement($node);
			case BreakStatement::KIND:
			case ContinueStatement::KIND:
				break;
			case GotoStatement::KIND:
				$this->goto_resolver->check_goto_statement($node);
				break;
			case LabelStatement::KIND:
				break;
			case UnsetStatement::KIND:
				$this->simple_statement_resolver->check_unset_statement($node);
				break;
			case IfBlock::KIND:
				$infered = $this->flow_narrowing_resolver->infer_if_block($node);
				break;
			case ForBlock::KIND:
				$infered = $this->loop_block_resolver->infer_for_block($node);
				break;
			case ForEachBlock::KIND:
				$infered = $this->iteration_resolver->infer_foreach_block($node);
				break;
			case ForInBlock::KIND:
				$infered = $this->iteration_resolver->infer_forin_block($node);
				break;
			case ForToBlock::KIND:
				$infered = $this->iteration_resolver->infer_forto_block($node);
				break;
			case WhileBlock::KIND:
				$infered = $this->loop_block_resolver->infer_while_block($node);
				break;
			case DoWhileBlock::KIND:
				$infered = $this->loop_block_resolver->infer_dowhile_block($node);
				break;
			case LoopBlock::KIND:
				$infered = $this->loop_block_resolver->infer_loop_block($node);
				break;
			case TryBlock::KIND:
				$infered = $this->exception_block_resolver->infer_try_block($node);
				break;
			case SwitchBlock::KIND:
				$infered = $this->switch_match_resolver->infer_switch_block($node);
				break;
			case UseStatement::KIND:
				break;
			case LineComment::KIND:
			case BlockComment::KIND:
			case DocComment::KIND:
				break;
			default:
				$kind = $node::KIND;
				throw $this->context->new_syntax_error("Unknow statement kind: '{$kind}'", $node);
		}

		return $infered;
	}
}
