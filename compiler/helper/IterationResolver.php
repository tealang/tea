<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class IterationResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private $infer_block;
	private ElseBlockTypeReducer $else_block_type_reducer;
	private $expect_infered_type;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $infer_block,
		ElseBlockTypeReducer $else_block_type_reducer,
		callable $expect_infered_type
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->infer_block = $infer_block;
		$this->else_block_type_reducer = $else_block_type_reducer;
		$this->expect_infered_type = $expect_infered_type;
	}

	public function infer_foreach_block(ForEachBlock $node): ?BaseType
	{
		$element_types = $this->expect_iter_element_types_for_expr($node->iterable);

		[$key_type, $val_type] = $element_types;
		$key = $node->key;
		$val = $node->val;
		if ($key instanceof PlainIdentifier) {
			ASTHelper::get_identifier_symbol($key)->declaration->infered_type = $key_type;
		}
		if ($val instanceof PlainIdentifier) {
			ASTHelper::get_identifier_symbol($val)->declaration->infered_type = $val_type;
		}

		$this->bind_loop_carried_rebound_types($node);

		$result_type = ($this->infer_block)($node);
		if ($node->else) {
			$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	public function infer_forin_block(ForInBlock $node): ?BaseType
	{
		$element_types = $this->expect_iter_element_types_for_expr($node->iterable);

		$key = $node->key;
		$val = $node->val;
		$this->context->mark_checked($val);

		[$key_type, $val->infered_type] = $element_types;

		if ($key) {
			$key->infered_type = $key_type;
			$this->context->mark_checked($key);
		}

		$result_type = ($this->infer_block)($node);
		if ($node->else) {
			$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	public function infer_forto_block(ForToBlock $node): ?BaseType
	{
		$start_type = ($this->expect_infered_type)($node->start, TypeFactory::$_int_types);
		$end_type = ($this->expect_infered_type)($node->end, TypeFactory::$_int_types);

		$key = $node->key;
		if ($key) {
			$key->infered_type = TypeFactory::$_uint;
			$this->context->mark_checked($key);
		}

		$val = $node->val;
		$this->context->mark_checked($val);

		// infer the val type
		$val->infered_type = ($start_type === TypeFactory::$_int || $end_type === TypeFactory::$_int)
			? TypeFactory::$_int
			: TypeFactory::$_uint;

		$result_type = ($this->infer_block)($node);
		if ($node->else) {
			$result_type = $this->else_block_type_reducer->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	private function bind_loop_carried_rebound_types(IBlock $block): void
	{
		if (!$this->context->is_weakly_checking()) {
			return;
		}

		$types = $this->collect_loop_carried_rebound_types($block);
		foreach ($types as [$decl, $type]) {
			$original_type = TypeHelper::get_bound_type($decl);
			$decl->bind_type($original_type->unite($type));
		}
	}

	private function collect_loop_carried_rebound_types(IBlock $block): array
	{
		$types = [];
		foreach ($block->body as $statement) {
			if (!$statement instanceof NormalStatement || !$statement->expression instanceof AssignmentOperation) {
				continue;
			}

			$left = $statement->expression->left;
			if (!$left instanceof PlainIdentifier) {
				continue;
			}

			$decl = ASTHelper::get_identifier_symbol($left)->declaration ?? null;
			if (!$decl instanceof IVariableDeclaration) {
				continue;
			}

			$type = $this->infer_loop_carried_assignment_type($statement->expression->right, $types);
			if ($type === null) {
				continue;
			}

			$key = spl_object_id($decl);
			if (isset($types[$key])) {
				$type = $types[$key][1]->unite($type);
			}

			$types[$key] = [$decl, $type];
		}

		return $types;
	}

	private function infer_loop_carried_assignment_type(BaseExpression $expr, array $types): ?BaseType
	{
		if ($expr instanceof PlainIdentifier) {
			$decl = ASTHelper::get_identifier_symbol($expr)->declaration ?? null;
			if (!$decl instanceof IVariableDeclaration) {
				return null;
			}

			$key = spl_object_id($decl);
			return $types[$key][1] ?? TypeHelper::get_bound_type($decl);
		}

		return ($this->infer_expression)($expr);
	}

	private function expect_iter_element_types_for_expr(BaseExpression $expr)
	{
		$iter_infered = ($this->infer_expression)($expr);
		$element_types = $this->infer_iter_element_types($iter_infered);
		if ($element_types === null) {
			$element_types = $this->infer_dynamic_foreach_element_types($expr, $iter_infered);
			if ($element_types === null) {
				$type_name = ASTChecker::get_type_name($iter_infered);
				$error = $this->context->new_syntax_error("Expected iterable type value, {$type_name} given", $expr);
				throw $error;
			}
		}

		return $element_types;
	}

	private function infer_dynamic_foreach_element_types(BaseExpression $expr, BaseType $type): ?array
	{
		if (!$this->context->is_weakly_checking()) {
			return null;
		}

		if ($type instanceof AnyType || $type instanceof MixedType || $this->is_php_foreachable_object_type($type)) {
			return [TypeFactory::$_dict_key, TypeFactory::$_mixed];
		}

		if ($type instanceof InvalidableType && $type->sentinel instanceof LiteralNone) {
			return $this->infer_dynamic_foreach_element_types($expr, $type->valid_type);
		}

		if ($type instanceof UnionType) {
			return $this->infer_dynamic_foreach_element_types_for_union($expr, $type);
		}

		return null;
	}

	private function infer_dynamic_foreach_element_types_for_union(BaseExpression $expr, UnionType $type): ?array
	{
		$key_types = [];
		$val_types = [];
		foreach ($type->get_members() as $member_type) {
			if ($member_type instanceof NoneType) {
				continue;
			}

			$element_types = $this->infer_iter_element_types($member_type);
			if ($element_types === null && ($member_type instanceof AnyType || $member_type instanceof MixedType || $this->is_php_foreachable_object_type($member_type))) {
				$element_types = [TypeFactory::$_dict_key, TypeFactory::$_mixed];
			}

			if ($element_types === null) {
				return null;
			}

			[$key_type, $val_type] = $element_types;
			$key_types[] = $key_type;
			$val_types[] = $val_type;
		}

		if (!$key_types) {
			return null;
		}

		return [TypeFactory::create_union_type($key_types), TypeFactory::create_union_type($val_types)];
	}

	private function is_php_foreachable_object_type(BaseType $type): bool
	{
		if ($type instanceof ObjectType) {
			return true;
		}

		$decl = TypeHelper::get_type_symbol($type)->declaration ?? null;
		return $type instanceof TypeReference && $decl instanceof ClassKindredDeclaration;
	}

	private function infer_iter_element_types(BaseType $expr_type)
	{
		$expr_type = TypeHelper::unwrap_excludable_type($expr_type);
		if ($expr_type instanceof IterableType) {
			// for Array or Dict
			$key_type = $expr_type instanceof ArrayType
				? TypeFactory::$_uint
				: TypeFactory::create_union_type([TypeFactory::$_uint, TypeFactory::$_string]);
			$val_type = $expr_type->generic_type ?? TypeFactory::$_mixed;
			$element_types = [$key_type, $val_type];
		}
		elseif ($expr_type instanceof TypeReference and $based_iter_ident = TypeFactory::find_iterator_identifier($expr_type)) {
			// for Iterator
			$key_type = $based_iter_ident->generic_types['K'] ?? TypeFactory::$_mixed;
			$val_type = $based_iter_ident->generic_types['V'] ?? TypeFactory::$_mixed;
			$element_types = [$key_type, $val_type];
		}
		elseif ($expr_type instanceof UnionType) {
			$element_types = $this->infer_iter_element_types_for_union_type($expr_type);
		}
		else {
			$element_types = null;
		}

		return $element_types;
	}

	private function infer_iter_element_types_for_union_type(UnionType $type) {
		$key_types = [];
		$val_types = [];
		foreach ($type->get_members() as $member_type) {
			$element_types = $this->infer_iter_element_types($member_type);
			if ($element_types === null) {
				return null;
			}

			[$key_type, $val_type] = $element_types;
			$key_types[] = $key_type;
			$val_types[] = $val_type;
		}

		return [TypeFactory::create_union_type($key_types), TypeFactory::create_union_type($val_types)];
	}
}
