<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ReturnBuilder
{
	private $node;

	private $collect_type;

	private $vector_identifier;

	private $collecteds = 0;

	private $temp_var_idx = 0;

	public function __construct(IScopeBlock $node, IType $collect_type)
	{
		$this->node = $node;
		$this->collect_type = $collect_type;
	}

	public function build_return_statements()
	{
		$array_var_declaration = new VariableDeclaration('__collects', TypeFactory::$_array, new ArrayLiteral([]));
		$this->vector_identifier = ASTHelper::create_variable_identifier($array_var_declaration);

		// the fixed statements
		$fixeds = $this->collect_block($this->node);

		if (!$this->collecteds) {
			throw new SyntaxError("Expect to collect the type '{$this->collect_type->name}' items, but not found anything.");
		}

		// the var declaration
		array_unshift($fixeds, $array_var_declaration);

		// the return statement
		$return = new ReturnStatement($this->vector_identifier, $this->node);
		$return->leading = LF;

		$fixeds[] = $return;

		return $fixeds;
	}

	private function collect_block(IBlock $node)
	{
		$fixeds = [];
		foreach ($node->body as $subnode) {
			if ($subnode instanceof NormalStatement) {
				$expr = $subnode->expression;

				// e.g. View().color('red')
				if ($expr instanceof CallExpression) {
					$this->collect_call_statement($subnode, $fixeds);
					continue;
				}

				// just for XView
				if ($this->collect_type === TypeFactory::$_xview && $expr instanceof XBlock) {
					$this->collecteds++;
					$subnode = new ArrayElementAssignment($this->vector_identifier, null, $expr);
				}
			}
			elseif ($subnode instanceof IAssignment) {
				// e.g. View().text = 'abc'
				if ($subnode->master instanceof AccessingIdentifier) {
					$this->collect_accessing_master_assignment($subnode, $fixeds);
					continue;
				}
			}
			elseif ($subnode instanceof ControlBlock) {
				// e.g. for-in, if-elseif-else, try-catch-finally, ...
				$to_fix_block = clone $subnode;
				$to_fix_block->body = $this->collect_block($to_fix_block);
				$subnode = $to_fix_block;
			}

			$fixeds[] = $subnode;
		}

		return $fixeds;
	}

	private function collect_call_statement(NormalStatement $stmt, array &$fixeds)
	{
		$expr = $stmt->expression;
		if ($expr->callee instanceof PlainIdentifier) {
			// if ($expr->is_class_new() && $expr->callee->is_same_or_based_with($this->collect_type)) {
			if ($expr->is_class_new()) {
				if ($this->collect_type->is_accept_type($expr->callee)) {
					$this->collecteds++;
					$fixeds[] = new ArrayElementAssignment($this->vector_identifier, null, $expr);
					return;
				}
			}
			elseif ($this->collect_type->is_accept_type($expr->callee->symbol->declaration->type)) {
				$this->collecteds++;
				$fixeds[] = new ArrayElementAssignment($this->vector_identifier, null, $expr);
				return;
			}
		}
		elseif ($hit = $this->find_and_collect_for_call($expr, $fixeds)) {
			$this->collecteds++;
			$new_stmt = clone $stmt;
			$new_stmt->expression = $hit;

			$fixeds_count = count($fixeds);
			if ($new_stmt->leading) {
				// just to let code prety
				$fixeds[$fixeds_count - 2]->leading = LF;
				$new_stmt->leading = false;
			}

			// we let code prety
			$last_item = $fixeds[$fixeds_count - 1];
			$fixeds[$fixeds_count - 1] = $new_stmt;
			$fixeds[] = $last_item;
			return;
		}

		$fixeds[] = $stmt; // do not collected anything
	}

	private function collect_accessing_master_assignment(IAssignment $assignment, array &$fixeds)
	{
		$hit = $this->find_and_collect_for_accessing($assignment->master, $fixeds);
		if ($hit) {
			$this->collecteds++;
			$new_assignment = clone $assignment;
			$new_assignment->master = $hit;

			$fixeds_count = count($fixeds);
			if ($new_assignment->leading) {
				// just to let code prety
				$fixeds[$fixeds_count - 2]->leading = LF;
				$new_assignment->leading = false;
			}

			// we let code prety
			$last_item = $fixeds[$fixeds_count - 1];
			$fixeds[$fixeds_count - 1] = $new_assignment;
			$fixeds[] = $last_item;
		}
		else {
			$fixeds[] = $assignment; // do not collected anything
		}
	}

	private function find_and_collect_for_call(CallExpression $call, array &$fixeds): ?Node
	{
		$callee = $call->callee;
		$hit = null;

		if ($callee instanceof PlainIdentifier) {
			if ($this->collect_type->is_accept_type($callee)) {
				// create a temp variable to insteadof old expression
				$var_declar = $this->create_local_variable($call);
				$var_ident = ASTHelper::create_variable_identifier($var_declar);
				$fixeds[] = $var_declar;
				$fixeds[] = new ArrayElementAssignment($this->vector_identifier, null, $var_ident);

				return $var_ident;
			}
		}
		elseif ($callee instanceof CallExpression) {
			$hit = $this->find_and_collect_for_call($callee, $fixeds);
		}
		elseif ($callee instanceof AccessingIdentifier) {
			$hit = $this->find_and_collect_for_accessing($callee, $fixeds);
		}

		if ($hit) {
			$new_call = clone $call;
			$new_call->callee = $hit;
			return $new_call;
		}

		return null;
	}

	private function find_and_collect_for_accessing(AccessingIdentifier $accessing, array &$fixeds): ?Node
	{
		$master = $accessing->master;

		$hit = null;
		if ($master instanceof CallExpression) {
			$hit = $this->find_and_collect_for_call($master, $fixeds);
		}
		elseif ($master instanceof AccessingIdentifier) {
			$hit = $this->find_and_collect_for_accessing($master, $fixeds);
		}

		if ($hit) {
			$new_accessing = clone $accessing;
			$new_accessing->master = $hit;
			return $new_accessing;
		}

		return null;
	}

	private function create_local_variable(BaseExpression $value)
	{
		$name = '__tmp' . $this->temp_var_idx++;
		return new VariableDeclaration($name, null, $value);
	}
}

