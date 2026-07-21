<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class FlowNarrowingResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private $infer_block;
	private ElseBlockTypeReducer $else_block_type_reducer;
	private $get_type_assertion;
	private $get_empty_condition_target;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $infer_block,
		ElseBlockTypeReducer $else_block_type_reducer,
		callable $get_type_assertion,
		callable $get_empty_condition_target
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->infer_block = $infer_block;
		$this->else_block_type_reducer = $else_block_type_reducer;
		$this->get_type_assertion = $get_type_assertion;
		$this->get_empty_condition_target = $get_empty_condition_target;
	}

	public function infer_if_block(IfBlock $node): ?BaseType
	{
		$result_type = $this->infer_base_if_block($node);

		return $result_type;
	}

	public function infer_base_if_block(BaseIfBlock $node): ?BaseType
	{
		$condition = $node->condition;
		($this->infer_expression)($condition);

		[$empty_argument, $empty_is_not] = ($this->get_empty_condition_target)($condition);
		if ($empty_argument && $this->can_assert_nullable_identifiable($empty_argument)) {
			return $this->infer_base_if_block_with_empty_guard($node, $empty_argument, $empty_is_not);
		}

		[$assertion, $is_not] = ($this->get_type_assertion)($condition);
		if ($assertion) {
			// with type assertion
			$result_type = $this->infer_base_if_block_with_assertion($node, $assertion, $is_not);
		}
		else {
			// without type assertion
			$parent_bindings = $this->context->snapshot_variable_bindings($this->context->get_block());
			$result_type = ($this->infer_block)($node);
			if ($this->context->is_weakly_checking()) {
				$main_rebound_vars = $node->get_rebound_variables();
				$main_rebound_types = $node->reset_rebound_types();
				if ($node->else) {
					$this->context->restore_variable_bindings($parent_bindings);
					$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
					$this->deliver_bound_types_with_else($node->else, $main_rebound_vars, $main_rebound_types);
				}
				elseif (!$this->block_exits_current_flow_for_narrowing($node)) {
					$this->deliver_bound_types_without_else($main_rebound_vars, $main_rebound_types);
				}
				else {
					$this->context->restore_variable_bindings($parent_bindings);
					$this->apply_condition_assertions_after_exited_if($condition);
				}
			}
			else {
				if ($node->else) {
					$this->context->restore_variable_bindings($parent_bindings);
					$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
				}
				elseif ($this->block_exits_current_flow_for_narrowing($node)) {
					$this->context->restore_variable_bindings($parent_bindings);
					$this->apply_condition_assertions_after_exited_if($condition);
				}
			}
		}

		return $result_type;
	}

	public function can_assert_nullable_identifiable(Identifiable $argument): bool
	{
		$argument_decl = ASTHelper::get_identifier_symbol($argument)->declaration ?? null;
		if (!$argument_decl instanceof IVariableDeclaration) {
			return false;
		}

		$argument_type = $this->get_assertion_original_type($argument_decl);
		return !$argument_type instanceof AnyType && TypeHelper::is_nullable_type($argument_type);
	}

	public function get_assertion_original_type(IVariableDeclaration $decl): BaseType
	{
		if ($this->context->is_weakly_checking()
			&& $decl instanceof IClassMemberDeclaration
			&& TypeHelper::get_raw_bound_type($decl) instanceof NoneType) {
			return ASTHelper::get_noted_type($decl) ?? $decl->declared_type ?? $decl->infered_type ?? TypeFactory::$_any;
		}

		return TypeHelper::get_bound_type($decl);
	}

	public function get_asserted_branch_type(BaseType $asserting_type): BaseType
	{
		return $asserting_type instanceof InvalidableType
			? TypeHelper::get_invalidable_sentinel_type($asserting_type)
			: $asserting_type;
	}

	public function get_type_without_assertion(BaseType $left_type, BaseType $asserting_type): ?BaseType
	{
		if ($asserting_type instanceof InvalidableType) {
			return TypeHelper::get_invalidable_valid_branch_type($asserting_type);
		}

		if ($left_type instanceof InvalidableType) {
			if ($asserting_type instanceof NoneType && $left_type->sentinel instanceof LiteralNone) {
				return TypeHelper::get_invalidable_valid_branch_type($left_type);
			}
		}

		if ($left_type instanceof UnionType) {
			return $left_type->deunite($asserting_type);
		}

		if ($asserting_type instanceof NoneType) {
			return TypeHelper::to_non_nullable($left_type);
		}

		return null;
	}

	public function apply_condition_assertions(BaseExpression $expr, bool $truthy, array &$bindings): void
	{
		if ($expr instanceof PrefixOperation && $expr->operator->is(OPID::BOOL_NOT)) {
			$this->apply_condition_assertions($expr->expression, !$truthy, $bindings);
			return;
		}

		if ($expr instanceof BinaryOperation) {
			if ($truthy && $expr->operator->is(OPID::BOOL_AND)) {
				$this->apply_condition_assertions($expr->left, true, $bindings);
				$this->apply_condition_assertions($expr->right, true, $bindings);
				return;
			}

			if (!$truthy && $expr->operator->is(OPID::BOOL_OR)) {
				$this->apply_condition_assertions($expr->left, false, $bindings);
				$this->apply_condition_assertions($expr->right, false, $bindings);
				return;
			}
		}

		[$assertion, $is_not] = ($this->get_type_assertion)($expr);
		if ($assertion === null) {
			return;
		}

		if (!$truthy) {
			$is_not = !$is_not;
		}

		$this->apply_single_condition_assertion($assertion, $is_not, $bindings);
	}

	public function restore_condition_assertions(array $bindings): void
	{
		foreach ($bindings as [$decl, $original_type]) {
			TypeHelper::set_raw_bound_type($decl, $original_type);
		}
	}

	private function apply_condition_assertions_after_exited_if(BaseExpression $condition): void
	{
		$bindings = [];
		$this->apply_condition_assertions($condition, false, $bindings);
	}

	private function infer_base_if_block_with_empty_guard(BaseIfBlock $node, Identifiable $argument, bool $is_not): ?BaseType
	{
		$argument_decl = ASTHelper::get_identifier_symbol($argument)->declaration;
		$argument_type = $this->get_assertion_original_type($argument_decl);
		$non_empty_type = TypeHelper::to_non_nullable($argument_type);
		$parent_bindings = $this->context->snapshot_variable_bindings($this->context->get_block());

		if ($is_not) {
			$argument_decl->bind_type($non_empty_type);
		}

		$result_type = ($this->infer_block)($node);
		$main_rebound_vars = $node->get_rebound_variables();
		$main_rebound_types = $node->reset_rebound_types();

		if ($node->else) {
			$this->context->restore_variable_bindings($parent_bindings);
			$argument_decl->bind_type($is_not ? $argument_type : $non_empty_type);
			$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
			$this->deliver_bound_types_with_else($node->else, $main_rebound_vars, $main_rebound_types, $argument_decl);
			$argument_decl->bind_type($argument_type);
		}
		elseif (!$is_not && $this->block_exits_current_flow_for_narrowing($node)) {
			$argument_decl->bind_type($non_empty_type);
		}
		else {
			$argument_decl->bind_type($argument_type);
			$this->deliver_bound_types_without_else($main_rebound_vars, $main_rebound_types);
		}

		return $result_type;
	}

	private function infer_base_if_block_with_assertion(BaseIfBlock $node, IsOperation $type_assertion, bool $is_not): ?BaseType
	{
		$assertion_left = $type_assertion->left;
		$parent_bindings = $this->context->snapshot_variable_bindings($this->context->get_block());

		// cannot use type assertion when not an Identifiable
		if (!$assertion_left instanceof Identifiable) {
			// check block body
			$result_type = ($this->infer_block)($node);
			if ($node->else) {
				$this->context->restore_variable_bindings($parent_bindings);
				$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
			}

			return $result_type;
		}

		$left_decl = ASTHelper::get_identifier_symbol($assertion_left)->declaration ?? null;
		if (!$left_decl instanceof IVariableDeclaration) {
			$result_type = ($this->infer_block)($node);
			if ($node->else) {
				$this->context->restore_variable_bindings($parent_bindings);
				$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
			}

			return $result_type;
		}

		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $this->get_assertion_original_type($left_decl);
		$left_type = $left_original_type ?? TypeFactory::$_any;
		$asserting_type = $type_assertion->right;
		if ($asserting_type instanceof InvalidType && $left_type instanceof InvalidableType) {
			$asserting_type = $left_type;
		}

		if ($is_not) {
			$asserted_then_type = $this->get_type_without_assertion($left_type, $asserting_type);
			$asserted_else_type = $this->get_asserted_branch_type($asserting_type);
		}
		else {
			$asserted_else_type = $this->get_type_without_assertion($left_type, $asserting_type);
			$asserted_then_type = $this->get_asserted_branch_type($asserting_type);
		}

		// it would infer with the asserted then type
		if ($asserted_then_type) {
			$left_decl->bind_type($asserted_then_type);
		}

		// check block body
		$result_type = ($this->infer_block)($node);

		// the rebound vars
		$main_rebound_vars = $node->get_rebound_variables();
		$main_rebound_types = $node->reset_rebound_types();

		if ($node->else) {
			// it would infer with the asserted else type
			$this->context->restore_variable_bindings($parent_bindings);
			$left_decl->bind_type($asserted_else_type ?? $left_original_type);
			$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);

			$this->deliver_bound_types_with_else(
				$node->else,
				$main_rebound_vars,
				$main_rebound_types,
				$left_decl,
				$asserted_then_type,
				$asserted_else_type
			);
		}
		else {
			$this->deliver_bound_types_without_else($main_rebound_vars, $main_rebound_types, $left_decl);
		}

		if ($assertion_left instanceof AccessingIdentifier
			&& !($assertion_left->basing instanceof PlainIdentifier && $assertion_left->basing->name === _THIS)) {
			// Keep narrowing for $this->prop, but still avoid carrying assumptions across arbitrary member chains.
			$left_decl->bind_type($left_original_type);
		}
		elseif ($this->can_deliver_assertion_after_transfer($node->condition)
			&& $this->block_exits_current_flow_for_narrowing($node)) {
			if ($is_not) {
				$left_type = $this->get_asserted_branch_type($asserting_type);
			}
			else {
				$left_type = $this->get_type_without_assertion($left_type, $asserting_type) ?? $left_type;
			}

			$left_decl->bind_type($left_type);
		}
		elseif (TypeHelper::get_bound_type($left_decl) === $asserted_then_type or $is_not) {
			// type not changed, so reset to original type
			$left_decl->bind_type($left_original_type);
		}

		return $result_type;
	}

	private function can_deliver_assertion_after_transfer(BaseExpression $condition): bool
	{
		if ($condition instanceof PrefixOperation && $condition->operator->is(OPID::BOOL_NOT)) {
			return $this->can_deliver_assertion_after_transfer($condition->expression);
		}

		return !($condition instanceof BinaryOperation
			&& ($condition->operator->is(OPID::BOOL_AND) || $condition->operator->is(OPID::BOOL_OR)));
	}

	private function block_exits_current_flow_for_narrowing(IBlock $block): bool
	{
		foreach ($block->body as $statement) {
			if ($statement instanceof ReturnStatement
				|| $statement instanceof ThrowStatement
				|| $statement instanceof ExitStatement
				|| $statement instanceof ContinueStatement) {
				return true;
			}
		}

		return false;
	}

	private function deliver_bound_types_without_else(array $main_rebound_vars, array $main_rebound_types, ?IVariableDeclaration $asserted_decl = null)
	{
		foreach ($main_rebound_vars as $idx => $var) {
			$new_type = $main_rebound_types[$idx];
			if ($var !== $asserted_decl) {
				$new_type = TypeHelper::get_bound_type($var)->unite($new_type);
			}

			$var->bind_type($new_type);
		}
	}

	private function deliver_bound_types_with_else(
		IElseBlock $else,
		array $main_rebound_vars,
		array $main_rebound_types,
		?IVariableDeclaration $asserted_decl = null,
		?BaseType $asserted_then_type = null,
		?BaseType $asserted_else_type = null
	)
	{
		$else_rebound_vars = $else->get_rebound_variables();
		$else_rebound_types = $else->reset_rebound_types();
		foreach ($main_rebound_vars as $idx => $var) {
			$idx_in_else = array_search($var, $else_rebound_vars, true);
			$basing_type = $idx_in_else === false
				? ($var !== $asserted_decl ? TypeHelper::get_bound_type($var) : $asserted_else_type)
				: $else_rebound_types[$idx_in_else];

			$new_type = $main_rebound_types[$idx];
			if ($basing_type) {
				$new_type = $basing_type->unite($new_type);
			}

			$var->bind_type($new_type);
		}

		foreach ($else_rebound_vars as $idx => $var) {
			if (array_search($var, $main_rebound_vars, true) !== false) {
				continue;
			}

			$new_type = $else_rebound_types[$idx];
			if ($var === $asserted_decl && $asserted_then_type !== null) {
				$new_type = $asserted_then_type->unite($new_type);
			}
			elseif ($var !== $asserted_decl) {
				$new_type = TypeHelper::get_bound_type($var)->unite($new_type);
			}

			$var->bind_type($new_type);
		}
	}

	private function apply_single_condition_assertion(IsOperation $assertion, bool $is_not, array &$bindings): void
	{
		if (!$assertion->left instanceof Identifiable) {
			return;
		}

		$left_decl = ASTHelper::get_identifier_symbol($assertion->left)->declaration ?? null;
		if (!$left_decl instanceof IVariableDeclaration) {
			return;
		}

		$key = spl_object_id($left_decl);
		if (!isset($bindings[$key])) {
			$bindings[$key] = [$left_decl, TypeHelper::get_raw_bound_type($left_decl)];
		}

		$left_type = $this->get_assertion_original_type($left_decl) ?? TypeFactory::$_any;
		$asserting_type = $assertion->right;
		if ($asserting_type instanceof InvalidType && $left_type instanceof InvalidableType) {
			$asserting_type = $left_type;
		}

		if ($asserting_type instanceof InvalidableType) {
			$asserted_type = $is_not
				? TypeHelper::get_invalidable_valid_branch_type($asserting_type)
				: TypeHelper::get_invalidable_sentinel_type($asserting_type);
		}
		elseif ($is_not) {
			if ($left_type instanceof UnionType) {
				$asserted_type = $left_type->deunite($asserting_type);
			}
			elseif ($asserting_type instanceof NoneType) {
				$asserted_type = TypeHelper::to_non_nullable($left_type);
			}
			else {
				return;
			}
		}
		else {
			$asserted_type = $asserting_type;
		}

		$left_decl->bind_type($asserted_type);
	}
}
