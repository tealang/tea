<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class SwitchMatchResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private $infer_block;
	private $reduce_types;
	private ElseBlockTypeReducer $else_block_type_reducer;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $infer_block,
		callable $reduce_types,
		ElseBlockTypeReducer $else_block_type_reducer
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->infer_block = $infer_block;
		$this->reduce_types = $reduce_types;
		$this->else_block_type_reducer = $else_block_type_reducer;
	}

	public function infer_switch_block(SwitchBlock $node): ?BaseType
	{
		$subject_type = ($this->infer_expression)($node->subject);
		if (!$this->context->is_weakly_checking() && !TypeHelper::is_case_testable_type($subject_type)) {
			$subject_type_name = ASTChecker::get_type_name($subject_type);
			$error = $this->context->new_syntax_error("The switch subject type should be String/Int/UInt/Null, $subject_type_name given", $node->subject);
			throw $error;
		}

		$infereds = [];
		$branch_rebounds = [];
		$switch_bindings = $this->context->snapshot_variable_bindings($this->context->get_block());
		foreach ($node->branches as $branch) {
			$this->context->restore_variable_bindings($switch_bindings);
			foreach ($branch->patterns as $pattern) {
				if ($pattern === null) {
					// the 'default' branch
					continue;
				}

					$case_type = ($this->infer_expression)($pattern);
					if (!$this->context->is_weakly_checking() && !TypeHelper::is_switch_compatible($subject_type, $case_type)) {
						$subject_type_name = ASTChecker::get_type_name($subject_type);
						$case_type_name = ASTChecker::get_type_name($case_type);
						$error = $this->context->new_syntax_error("Incompatible matching types, matching type is $subject_type_name, case type is $case_type_name", $pattern);
						throw $error;
					}
			}

			$infereds[] = ($this->infer_block)($branch);
			foreach ($switch_bindings as $key => [$var, $original_type]) {
				$current_type = TypeHelper::get_raw_bound_type($var);
				if ($current_type === null) {
					continue;
				}

				if ($original_type !== null && TypeHelper::is_same_type($current_type, $original_type)) {
					continue;
				}

				$key = spl_object_id($var);
				if (!isset($branch_rebounds[$key])) {
					$branch_rebounds[$key] = [$var, $original_type, []];
				}
				$branch_rebounds[$key][2][] = $current_type;
			}
			$branch->reset_rebound_types();
		}
		$this->context->restore_variable_bindings($switch_bindings);

		$result_type = $infereds ? ($this->reduce_types)($infereds) : null;

		if ($node->else) {
			$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
		}

		foreach ($branch_rebounds as [$var, $original_type, $types]) {
			$var->bind_type(($this->reduce_types)($original_type ? [$original_type, ...$types] : $types));
		}

		return $result_type;
	}

	public function infer_match_block(MatchBlock $node): ?BaseType
	{
		$subject_type = ($this->infer_expression)($node->subject);
		if (!$this->context->is_weakly_checking() && !TypeHelper::is_case_testable_type($subject_type)) {
			$subject_type_name = ASTChecker::get_type_name($subject_type);
			$error = $this->context->new_syntax_error("The match subject should be String/Int/UInt, $subject_type_name given", $node->subject);
			throw $error;
		}

		$infereds = [];
		foreach ($node->arms as $arm) {
			foreach ($arm->patterns as $pattern) {
				if ($pattern instanceof DefaultPattern) {
					// the 'default' arm
					continue;
				}

					$case_type = ($this->infer_expression)($pattern);
					if (!$this->context->is_weakly_checking() && !TypeHelper::is_switch_compatible($subject_type, $case_type)) {
						$subject_type_name = ASTChecker::get_type_name($subject_type);
						$case_type_name = ASTChecker::get_type_name($case_type);
						$error = $this->context->new_syntax_error("Incompatible matching types, matching type is $subject_type_name, arm type is $case_type_name", $pattern);
						throw $error;
					}
			}

			if ($arm->return instanceof ThrowExpression) {
				($this->infer_expression)($arm->return);
			}
			else {
				$infereds[] = ($this->infer_expression)($arm->return);
			}
		}

		$result_type = $infereds ? ($this->reduce_types)($infereds) : null;
		return $result_type;
	}
}
