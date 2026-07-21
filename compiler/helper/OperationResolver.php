<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class OperationResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private $infer_accessing_identifier;
	private $reduce_types;
	private $get_type_assertion_for;
	private $set_type_assertion_for;
	private TypeReferenceChecker $type_reference_checker;
	private TypeCompatibilityReporter $type_compatibility_reporter;
	private FlowNarrowingResolver $flow_narrowing_resolver;
	private MemberResolver $member_resolver;
	private TypeAssertionResolver $type_assertion_resolver;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $infer_accessing_identifier,
		callable $reduce_types,
		callable $get_type_assertion_for,
		callable $set_type_assertion_for,
		TypeReferenceChecker $type_reference_checker,
		TypeCompatibilityReporter $type_compatibility_reporter,
		FlowNarrowingResolver $flow_narrowing_resolver,
		MemberResolver $member_resolver,
		TypeAssertionResolver $type_assertion_resolver
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->infer_accessing_identifier = $infer_accessing_identifier;
		$this->reduce_types = $reduce_types;
		$this->get_type_assertion_for = $get_type_assertion_for;
		$this->set_type_assertion_for = $set_type_assertion_for;
		$this->type_reference_checker = $type_reference_checker;
		$this->type_compatibility_reporter = $type_compatibility_reporter;
		$this->flow_narrowing_resolver = $flow_narrowing_resolver;
		$this->member_resolver = $member_resolver;
		$this->type_assertion_resolver = $type_assertion_resolver;
	}

	public function infer_binary_operation(BinaryOperation $node): BaseType
	{
		$operator = $node->operator;
		$is_none_coalescing = $operator->is(OPID::NONE_COALESCING);

		$left_expr = $node->left;
		if ($is_none_coalescing and $left_expr instanceof AccessingIdentifier) {
			$left_type = ($this->infer_accessing_identifier)($left_expr, true);
		}
		else {
			$left_type = ($this->infer_expression)($left_expr);
		}

		$right_expr = $node->right;
		if ($operator->is(OPID::BOOL_AND)) {
			$bindings = [];
			$this->flow_narrowing_resolver->apply_condition_assertions($left_expr, true, $bindings);
		}

		$right_type = ($this->infer_expression)($right_expr);

		if (isset($bindings)) {
			$this->flow_narrowing_resolver->restore_condition_assertions($bindings);
		}

		if ($this->context->is_weakly_checking()
			&& $operator->is(OPID::ADDITION)
			&& ($array_union_type = TypeHelper::infer_php_array_union_operation($left_type, $right_type))) {
			$infered = $array_union_type;
		}
		elseif (OperatorFactory::is_number_operator($operator)) {
			$this->assert_math_operable($left_type, $left_expr);
			$this->assert_math_operable($right_type, $right_expr);

			if ($left_type === TypeFactory::$_float || $right_type === TypeFactory::$_float) {
				if ($operator->is(OPID::REMAINDER)) {
					throw $this->context->new_syntax_error("Remainder operation cannot use in 'Float' type expression", $node);
				}

				$infered = TypeFactory::$_float;
			}
			elseif ($operator->is(OPID::DIVISION)) {
				$infered = TypeFactory::$_float;
			}
			elseif ($operator->is(OPID::NEGATION)) {
				$infered = TypeFactory::$_int;
			}
			elseif ($left_type === TypeFactory::$_int || $right_type === TypeFactory::$_int) {
				$infered = TypeFactory::$_int;
			}
			else {
				$infered = TypeFactory::$_uint;
			}
		}
		elseif (OperatorFactory::is_bool_operator($operator)) {
			$infered = TypeFactory::$_bool;
			$this->assign_null_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);
			$this->assign_false_literal_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);
			$this->assign_invalidable_sentinel_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);
			$this->assign_false_sentinel_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);

			if ($operator->is(OPID::BOOL_AND)) {
				if ($left_expr instanceof BinaryOperation and $assertion = ($this->get_type_assertion_for)($left_expr)) {
					($this->set_type_assertion_for)($node, $assertion);
				}
				elseif ($right_expr instanceof BinaryOperation and $assertion = ($this->get_type_assertion_for)($right_expr)) {
					($this->set_type_assertion_for)($node, $assertion);
				}
			}
		}
		elseif ($operator->is(OPID::CONCAT)) {
			if ($this->context->is_weakly_checking()) {
				if ($left_type instanceof ArrayType) {
					if ($right_type instanceof AnyType) {
						$node->operator = OperatorFactory::$array_concat;
						$infered = $left_type;
					}
					elseif (!$right_type instanceof ArrayType) {
						$type_name = ASTChecker::get_type_name($left_type);
						throw $this->context->new_syntax_error("The concat operation cannot use for '$type_name' type targets", $left_expr);
					}
					else {
						$this->type_compatibility_reporter->assert_type_compatible($left_type, $right_type, $right_expr, 'concat');
						$node->operator = OperatorFactory::$array_concat;
						$infered = $left_type;
					}
				}
				else {
					$this->assert_php_concat_operable($left_type, $left_expr);
					$this->assert_php_concat_operable($right_type, $right_expr);
					$infered = TypeFactory::$_string;
				}
			}
			elseif ($left_type instanceof ArrayType) {
				$this->type_compatibility_reporter->assert_type_compatible($left_type, $right_type, $right_expr, 'concat');
				$node->operator = OperatorFactory::$array_concat;
				$infered = $left_type;
			}
			elseif (!TypeHelper::is_string_concatable_type($left_type)) {
				$type_name = ASTChecker::get_type_name($left_type);
				throw $this->context->new_syntax_error("The concat operation cannot use for '$type_name' type targets", $left_expr);
			}
			else {
				$is_pure = $left_type instanceof IPureType && $right_type instanceof IPureType;
				$infered = $is_pure ? TypeFactory::$_text : TypeFactory::$_string;
			}
		}
		elseif ($operator->is(OPID::REPEAT)) {
			if (!TypeHelper::is_string_concatable_type($left_type)) {
				$type_name = ASTChecker::get_type_name($left_type);
				throw $this->context->new_syntax_error("Expected Stringable, {$type_name} given", $left_expr);
			}
			elseif (!$right_type instanceof UIntType) {
				$type_name = ASTChecker::get_type_name($right_type);
				throw $this->context->new_syntax_error("Expected UInt, {$type_name} given", $right_expr);
			}

			$is_pure = $left_type instanceof IPureType;
			$infered = $is_pure ? TypeFactory::$_text : TypeFactory::$_string;
		}
		elseif ($is_none_coalescing) {
			$infered = $this->infer_none_coalesced_type($left_type, $right_type);
		}
		elseif (OperatorFactory::is_bitwise_operator($operator)) {
			$infered = ($this->reduce_types)([$left_type, $right_type]);
		}
		elseif ($operator->is(OPID::MEMBER_ACCESSING)) {
			$infered = TypeFactory::$_mixed;
		}
		else {
			$sign = $node->operator->get_debug_sign();
			throw $this->context->new_syntax_error("Unknow binary operator: {$sign}", $node);
		}

		return $infered;
	}

	public function infer_as_operation(AsOperation $node): BaseType
	{
		($this->infer_expression)($node->left);
		$this->type_reference_checker->check_type($node->right, null);

		$cast_type = $node->right;

		if (!$cast_type instanceof BaseType) {
			throw $this->context->new_syntax_error("Invalid 'as' expression '{$node->right->name}'", $node);
		}

		return $cast_type;
	}

	public function infer_cast_operation(CastOperation $node): BaseType
	{
		($this->infer_expression)($node->left);
		$this->type_reference_checker->check_type($node->right, null);

		if (TypeHelper::is_number_type($node->right)) {
			OutputSafety::set_value_trust($node, ValueTrust::RUNTIME_ENSURED);
		}

		return $node->right;
	}

	public function infer_is_operation(IsOperation $node): BaseType
	{
		$left_type = ($this->infer_expression)($node->left);

		$assert_type = $node->right;
		if (!$assert_type instanceof BaseType) {
			if (!$this->context->is_weakly_checking() || !$assert_type instanceof BaseExpression) {
				$kind = $node->right::KIND;
				throw $this->context->new_syntax_error("Invalid 'is' expression '{$kind}'", $node);
			}

			($this->infer_expression)($assert_type);
			return TypeFactory::$_bool;
		}

		if ($assert_type instanceof InvalidType) {
			if (!$left_type instanceof InvalidableType) {
				$left_type_name = ASTChecker::get_type_name($left_type);
				throw $this->context->new_syntax_error("Cannot use 'is Invalid' for non-Invalidable type '{$left_type_name}'", $node);
			}

			if ($node->left instanceof Identifiable) {
				($this->set_type_assertion_for)($node, $node);
			}

			return TypeFactory::$_bool;
		}

		$this->type_reference_checker->check_type($assert_type);

		if ($node->left instanceof Identifiable) {
			($this->set_type_assertion_for)($node, $node);
		}

		return TypeFactory::$_bool;
	}

	public function infer_prefix_operation(PrefixOperation $node): BaseType
	{
		$expr_type = ($this->infer_expression)($node->expression);
		$operator = $node->operator;

		if ($operator->is(OPID::BOOL_NOT)) {
			$this->assert_bool_operable($expr_type, $node->expression);
			$infered = TypeFactory::$_bool;
		}
		elseif ($operator->is(OPID::NEGATION)) {
			$this->assert_math_operable($expr_type, $node->expression);

			if ($expr_type === TypeFactory::$_uint) {
				$infered = TypeFactory::$_int;
			}
			elseif ($expr_type instanceof UnionType and $expr_type->contains_single_type(TypeFactory::$_uint)) {
				$infered = $expr_type->merge_with_single_type(TypeFactory::$_int);
			}
			else {
				$infered = $expr_type;
			}
		}
		elseif ($operator->is(OPID::IDENTITY)) {
			$this->assert_math_operable($expr_type, $node->expression);
			$infered = $expr_type;
		}
		elseif ($operator->is(OPID::REFERENCE) || $operator->is(OPID::PRE_INCREMENT)) {
			$infered = $expr_type;
		}
		elseif ($operator->is(OPID::PRE_DECREMENT)) {
			$infered = $expr_type instanceof UIntType ? TypeFactory::$_int : $expr_type;
		}
		elseif ($operator->is(OPID::BITWISE_NOT)) {
			$this->assert_bitwise_operable($expr_type, $node->expression);
			$infered = $expr_type === TypeFactory::$_uint || $expr_type === TypeFactory::$_int || $expr_type === TypeFactory::$_float
				? TypeFactory::$_int
				: $expr_type;
		}
		elseif ($operator->is(OPID::CLONE) || $operator->is(OPID::ERROR_CONTROL)) {
			$infered = $expr_type;
		}
		elseif ($operator->is(OPID::SPREAD)) {
			$infered = TypeFactory::$_array;
		}
		else {
			$sign = $operator->get_debug_sign();
			throw $this->context->new_syntax_error("Unknow prefix operator: {$sign}", $node);
		}

		return $infered;
	}

	public function infer_postfix_operation(PostfixOperation $node): BaseType
	{
		return ($this->infer_expression)($node->expression);
	}

	public function infer_none_coalescing_expression(NoneCoalescingOperation $node): BaseType
	{
		$prev_allow_none_key_access = $this->context->allows_none_coalescing_key_access();
		$this->context->set_none_coalescing_key_access(true);
		$left_infered = ($this->infer_expression)($node->left);
		$this->context->set_none_coalescing_key_access($prev_allow_none_key_access);
		$right_infered = ($this->infer_expression)($node->right);

		return $this->infer_none_coalesced_type($left_infered, $right_infered);
	}

	public function infer_ternary_expression(TernaryExpression $node): BaseType
	{
		$condition = $node->condition;
		$condition_type = ($this->infer_expression)($condition);

		[$assertion, $is_not] = $this->type_assertion_resolver->resolve_condition_assertion($condition);
		if ($assertion) {
			$infereds = $this->infer_ternary_with_assertion($node, $condition_type, $assertion, $is_not);
		}
		else {
			if ($node->then === null) {
				$then_type = $this->get_php_truthy_condition_type($condition_type);
			}
			else {
				$then_type = $this->infer_reachable_ternary_branch($node->then);
			}

			$else_type = $this->infer_reachable_ternary_branch($node->else);

			$infereds = [$then_type, $else_type];
		}

		return $this->reduce_reachable_ternary_types($infereds);
	}

	private function assert_php_concat_operable(BaseType $type, BaseExpression $expr): void
	{
		$type = TypeHelper::unwrap_excludable_type($type);

		if ($this->is_php_dynamic_top_type($type)) {
			return;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$this->assert_php_concat_operable($member, $expr);
			}
			return;
		}

		if ($type instanceof InvalidableType) {
			if (TypeHelper::is_string_concatable_type($type->valid_type) || $this->is_php_stringable_object_type($type->valid_type)) {
				if ($type->sentinel instanceof LiteralNone) {
					return;
				}
				$this->context->add_syntax_warning("PHP weak concat converts Invalidable to String", $expr);
				return;
			}
		}

		if (TypeHelper::is_string_concatable_type($type) || $this->is_php_stringable_object_type($type)) {
			return;
		}

		if ($type instanceof MetaType) {
			$this->context->add_syntax_warning("PHP weak concat converts MetaType to class string", $expr);
			return;
		}

		if ($type instanceof BoolType) {
			$this->context->add_syntax_warning("PHP weak concat converts Bool to String", $expr);
			return;
		}

		$type_name = ASTChecker::get_type_name($type);
		throw $this->context->new_syntax_error("The concat operation cannot use for '$type_name' type targets", $expr);
	}

	private function is_php_stringable_object_type(BaseType $type): bool
	{
		$decl = $type instanceof TypeReference ? (TypeHelper::get_type_symbol($type)->declaration ?? null) : null;
		if (!$decl instanceof ClassKindredDeclaration) {
			return false;
		}

		return $this->member_resolver->find_member_symbol_in_class_declaration($decl, '__toString') !== null;
	}

	private function assign_null_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if (!$operator->is(OPID::EQUAL)
			&& !$operator->is(OPID::IDENTICAL)
			&& !$operator->is(OPID::NOT_EQUAL)
			&& !$operator->is(OPID::NOT_IDENTICAL)) {
			return;
		}

		$is_not = $operator->is(OPID::NOT_EQUAL) || $operator->is(OPID::NOT_IDENTICAL);
		if ($right_type instanceof NoneType && $left_expr instanceof Identifiable) {
			($this->set_type_assertion_for)($node, new IsOperation($left_expr, TypeFactory::$_none, $is_not));
		}
		elseif ($left_type instanceof NoneType && $right_expr instanceof Identifiable) {
			($this->set_type_assertion_for)($node, new IsOperation($right_expr, TypeFactory::$_none, $is_not));
		}
	}

	private function assign_false_sentinel_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if ($operator->is(OPID::GREATERTHAN)) {
			$this->assign_false_sentinel_positive_comparison_assertion($node, $left_expr, $left_type, $right_type);
		}
		elseif ($operator->is(OPID::LESSTHAN)) {
			$this->assign_false_sentinel_positive_comparison_assertion($node, $right_expr, $right_type, $left_type);
		}
	}

	private function assign_false_literal_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if (!$operator->is(OPID::EQUAL)
			&& !$operator->is(OPID::IDENTICAL)
			&& !$operator->is(OPID::NOT_EQUAL)
			&& !$operator->is(OPID::NOT_IDENTICAL)) {
			return;
		}

		$is_not = $operator->is(OPID::NOT_EQUAL) || $operator->is(OPID::NOT_IDENTICAL);
		if ($this->is_literal_false($right_expr) && $left_expr instanceof Identifiable) {
			if ($this->is_false_sentinel_invalidable($left_type)) {
				($this->set_type_assertion_for)($node, new IsOperation($left_expr, $left_type, $is_not));
			}
			elseif ($this->is_bool_sentinel_union($left_type)) {
				($this->set_type_assertion_for)($node, new IsOperation($left_expr, TypeFactory::$_bool, $is_not));
			}
		}
		elseif ($this->is_literal_false($left_expr) && $right_expr instanceof Identifiable) {
			if ($this->is_false_sentinel_invalidable($right_type)) {
				($this->set_type_assertion_for)($node, new IsOperation($right_expr, $right_type, $is_not));
			}
			elseif ($this->is_bool_sentinel_union($right_type)) {
				($this->set_type_assertion_for)($node, new IsOperation($right_expr, TypeFactory::$_bool, $is_not));
			}
		}
	}

	private function is_literal_false(BaseExpression $expr): bool
	{
		return $expr instanceof LiteralBoolean
			&& ($expr->value === false || $expr->value === '' || $expr->value === '0');
	}

	private function is_false_sentinel_invalidable(BaseType $type): bool
	{
		return $type instanceof InvalidableType
			&& TypeHelper::is_invalidable_sentinel($type, new LiteralBoolean(false));
	}

	private function assign_invalidable_sentinel_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if (!$operator->is(OPID::EQUAL)
			&& !$operator->is(OPID::IDENTICAL)
			&& !$operator->is(OPID::NOT_EQUAL)
			&& !$operator->is(OPID::NOT_IDENTICAL)) {
			return;
		}

		$is_not = $operator->is(OPID::NOT_EQUAL) || $operator->is(OPID::NOT_IDENTICAL);
		if ($left_expr instanceof Identifiable && TypeHelper::is_invalidable_sentinel($left_type, $right_expr)) {
			($this->set_type_assertion_for)($node, new IsOperation($left_expr, $left_type, $is_not));
		}
		elseif ($right_expr instanceof Identifiable && TypeHelper::is_invalidable_sentinel($right_type, $left_expr)) {
			($this->set_type_assertion_for)($node, new IsOperation($right_expr, $right_type, $is_not));
		}
	}

	private function is_bool_sentinel_union(BaseType $type): bool
	{
		return $type instanceof UnionType
			&& $type->count() > 1
			&& $type->contains_single_type(TypeFactory::$_bool);
	}

	private function assign_false_sentinel_positive_comparison_assertion(
		BinaryOperation $node,
		BaseExpression $target_expr,
		BaseType $target_type,
		BaseType $number_type
	): void {
		if (!$target_expr instanceof Identifiable
			|| !TypeHelper::is_number_type($number_type)) {
			return;
		}

		if ($this->is_number_false_sentinel_union($target_type)) {
			($this->set_type_assertion_for)($node, new IsOperation($target_expr, TypeFactory::$_bool, true));
		}
		elseif ($this->is_number_false_sentinel_invalidable($target_type)) {
			($this->set_type_assertion_for)($node, new IsOperation($target_expr, $target_type, true));
		}
	}

	private function is_number_false_sentinel_union(BaseType $type): bool
	{
		if (!$this->is_bool_sentinel_union($type)) {
			return false;
		}

		$number_type = $type->deunite(TypeFactory::$_bool);
		return TypeHelper::is_number_type($number_type);
	}

	private function is_number_false_sentinel_invalidable(BaseType $type): bool
	{
		return $this->is_false_sentinel_invalidable($type)
			&& TypeHelper::is_number_type(TypeHelper::get_invalidable_valid_branch_type($type));
	}

	private function assert_bitwise_operable(BaseType $type, BaseExpression $node): void
	{
		if ($this->context->is_weakly_checking() && $this->is_php_dynamic_top_type($type)) {
			return;
		}

		if (!$type instanceof UIntType && !$type instanceof IntType && !$type instanceof FloatType && !$type instanceof StringType) {
			$type_name = ASTChecker::get_type_name($type);
			throw $this->context->new_syntax_error("Bitwise operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function is_php_dynamic_top_type(BaseType $type): bool
	{
		return PHPWeakPolicy::is_dynamic_top_type($type);
	}

	private function assert_math_operable(BaseType $type, BaseExpression $node): void
	{
		if (!TypeHelper::is_number_type($type, $node) && $this->should_report_math_mismatch($type, $node)) {
			$type_name = ASTChecker::get_type_name($type);
			throw $this->context->new_syntax_error("Math operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function should_report_math_mismatch(BaseType $type, BaseExpression $node): bool
	{
		if (!$this->context->is_weakly_checking()) {
			return true;
		}

		return $node instanceof LiteralString && !is_numeric($node->value);
	}

	private function assert_bool_operable(BaseType $type, BaseExpression $node): void
	{
		if (!$this->context->is_weakly_checking() && !$this->is_bool_operable_type($type)) {
			$type_name = ASTChecker::get_type_name($type);
			throw $this->context->new_syntax_error("Bool operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function is_bool_operable_type(BaseType $type): bool
	{
		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if (!$this->is_bool_operable_type($member)) {
					return false;
				}
			}

			return true;
		}

		return $type instanceof BoolType
			|| $type instanceof UIntType
			|| $type instanceof IntType
			|| $this->is_number_false_sentinel_invalidable($type);
	}

	private function infer_none_coalesced_type(BaseType $left_infered, BaseType $right_infered): BaseType
	{
		$reduced_type = ($this->reduce_types)([$left_infered, $right_infered]);
		if ($reduced_type instanceof MixedType) {
			return $reduced_type;
		}

		if (!$right_infered instanceof NoneType) {
			$reduced_type = clone $reduced_type;
			$reduced_type = TypeHelper::to_non_nullable($reduced_type);
		}

		return $reduced_type;
	}

	private function infer_reachable_ternary_branch(BaseExpression $node): ?BaseType
	{
		if ($node instanceof ThrowExpression) {
			($this->infer_expression)($node);
			return null;
		}

		return ($this->infer_expression)($node);
	}

	private function reduce_reachable_ternary_types(array $types): BaseType
	{
		$reachable_types = array_values(array_filter($types));
		return $reachable_types
			? ($this->reduce_types)($reachable_types)
			: TypeFactory::$_any;
	}

	private function get_php_truthy_condition_type(BaseType $type): BaseType
	{
		if ($type instanceof InvalidableType && $type->sentinel instanceof LiteralBoolean && $type->sentinel->value === false) {
			return TypeHelper::get_invalidable_valid_branch_type($type);
		}

		return TypeHelper::to_non_nullable($type);
	}

	private function infer_ternary_with_assertion(TernaryExpression $node, BaseType $condition_type, IsOperation $type_assertion, bool $is_not): array
	{
		$assertion_left = $type_assertion->left;
		$left_decl = $assertion_left instanceof Identifiable
			? (ASTHelper::get_identifier_symbol($assertion_left)->declaration ?? null)
			: null;
		if (!$left_decl instanceof IVariableDeclaration) {
			$then_type = $node->then === null
				? $condition_type
				: $this->infer_reachable_ternary_branch($node->then);
			$else_type = $this->infer_reachable_ternary_branch($node->else);

			return [$then_type, $else_type];
		}

		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = TypeHelper::get_bound_type($left_decl);
		$asserting_type = $type_assertion->right;

		if ($is_not) {
			$asserted_then_type = $this->flow_narrowing_resolver->get_type_without_assertion($left_original_type, $asserting_type);
			$asserted_else_type = $this->flow_narrowing_resolver->get_asserted_branch_type($asserting_type);
		}
		else {
			$asserted_else_type = $this->flow_narrowing_resolver->get_type_without_assertion($left_original_type, $asserting_type);
			$asserted_then_type = $this->flow_narrowing_resolver->get_asserted_branch_type($asserting_type);
		}

		if ($node->then === null) {
			$then_type = $asserted_then_type ?? $this->get_php_truthy_condition_type($condition_type);
		}
		else {
			$asserted_then_type and $left_decl->bind_type($asserted_then_type);
			$then_type = $this->infer_reachable_ternary_branch($node->then);
		}

		$left_decl->bind_type($asserted_else_type ?? $left_original_type);
		$else_type = $this->infer_reachable_ternary_branch($node->else);

		$left_decl->bind_type($left_original_type);

		return [$then_type, $else_type];
	}
}
