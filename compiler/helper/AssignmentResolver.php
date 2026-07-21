<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class AssignmentResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private $infer_accessing_identifier;
	private KeyAccessResolver $key_access_resolver;
	private TypeCompatibilityReporter $type_compatibility_reporter;
	private ContainerLiteralResolver $container_literal_resolver;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $infer_accessing_identifier,
		KeyAccessResolver $key_access_resolver,
		TypeCompatibilityReporter $type_compatibility_reporter,
		ContainerLiteralResolver $container_literal_resolver
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->infer_accessing_identifier = $infer_accessing_identifier;
		$this->key_access_resolver = $key_access_resolver;
		$this->type_compatibility_reporter = $type_compatibility_reporter;
		$this->container_literal_resolver = $container_literal_resolver;
	}

	public function infer_assignment_operation(AssignmentOperation $node): BaseType
	{
		$left = $node->left;
		$right = $node->right;
		$infered = ($this->infer_expression)($right);
		$left_decl = null;
		$rebind_decl = null;

		if ($infered === TypeFactory::$_void) {
			if ($this->context->is_weakly_checking()) {
				$infered = TypeFactory::$_mixed;
			}
			else {
				$error = $this->context->new_syntax_error("The returns type is Void, cannot use as value", $right);
				throw $error;
			}
		}

		if ($left instanceof AccessingIdentifier) {
			($this->infer_accessing_identifier)($left);
			$left_decl = ASTHelper::get_identifier_symbol($left)->declaration ?? null;
			$left_type = $left_decl ? $this->get_assignment_target_type($left_decl) : TypeFactory::$_mixed;
			if ($this->context->is_weakly_checking()
				&& $left_decl instanceof IVariableDeclaration
				&& $this->is_this_member_accessing($left)) {
				$rebind_decl = $left_decl;
			}
		}
		elseif ($left instanceof KeyAccessing) {
			$left_type = $this->key_access_resolver->infer_key_accessing($left);
			$left_type = $this->get_key_accessing_assignment_type($left, $left_type);
		}
		elseif ($left instanceof SquareAccessing) {
			$left_type = $this->key_access_resolver->infer_square_accessing($left);
			if ($this->context->is_weakly_checking()) {
				$left_type = $this->widen_php_array_append_assignment($left, $left_type, $infered);
			}
		}
		elseif ($left instanceof PlainIdentifier) {
			$left_decl = ASTHelper::get_identifier_symbol($left)->declaration ?? null;
			if ($this->context->is_weakly_checking() && $left instanceof VariableIdentifier && !$left_decl instanceof IVariableDeclaration) {
				$left_decl = $this->create_php_dynamic_variable_declaration($left);
			}
			$left_type = $left_decl->get_hinted_type();
			if ($this->context->is_weakly_checking() && $this->is_catch_variable_declaration($left_decl)) {
				$left_type = TypeFactory::$_any;
			}
			$this->rebind_type_for_variable($left_decl, $infered, $right);
		}
		elseif ($left instanceof Destructuring) {
			$left_type = $this->infer_destructuring($left);
		}
		elseif ($left instanceof BinaryOperation && $left->operator === OperatorFactory::$member_accessing) {
			$left_type = TypeFactory::$_mixed;
		}
		else {
			$error = $this->context->new_syntax_error("Required assignable expression", $left);
			throw $error;
		}

		if (!ASTHelper::is_assignable_expr($left)) {
			if ($left instanceof KeyAccessing) {
				$error = $this->context->new_syntax_error("Cannot change a immutable item", $left->basing);
			}
			elseif ($left instanceof SquareAccessing) {
				$error = $this->context->new_syntax_error("Cannot change a immutable item", $left);
			}
			else {
				$error = $this->context->new_syntax_error("Cannot assign to a final(un-reassignable) item", $left);
			}
			throw $error;
		}

		if ($left_decl instanceof BaseDeclaration
			&& ($phpdoc_type = $this->get_phpdoc_only_noted_type($left_decl)) instanceof BaseType
			&& $this->type_compatibility_reporter->warn_phpdoc_only_type_mismatch($left_decl, $phpdoc_type, $infered, $right, 'assign')) {
			$left_type = TypeFactory::$_mixed;
		}

		$this->type_compatibility_reporter->assert_type_compatible($left_type, $infered, $right);
		if ($rebind_decl) {
			$this->rebind_type_for_variable($rebind_decl, $infered, $right);
		}

		return $infered;
	}

	public function rebind_type_for_variable(IVariableDeclaration $decl, BaseType $type, ?BaseExpression $value = null): void
	{
		$decl->bind_type($type);
		TypeHelper::set_bound_value($decl, $value);
		$this->context->get_block()->add_rebound_variable($decl);
	}

	public function infer_destructuring(Destructuring $expr): BaseType
	{
		foreach ($expr->items as $item) {
			if ($item === null) {
				continue;
			}

			if ($item instanceof DictMember) {
				$this->container_literal_resolver->infer_dict_member($item);
			}
			else {
				($this->infer_expression)($item);
			}
		}

		return TypeFactory::$_array;
	}

	private function is_catch_variable_declaration($decl): bool
	{
		return $decl instanceof VariableDeclaration && $decl->block instanceof CatchBlock;
	}

	private function is_this_member_accessing(AccessingIdentifier $node): bool
	{
		return $node->basing instanceof PlainIdentifier && $node->basing->name === _THIS;
	}

	private function get_assignment_target_type(BaseDeclaration $decl): BaseType
	{
		if ($this->context->is_weakly_checking() && $decl->declared_type === null && ASTHelper::get_noted_type($decl) !== null) {
			return TypeFactory::$_mixed;
		}

		return $decl->get_hinted_type();
	}

	private function get_phpdoc_only_noted_type(?BaseDeclaration $decl): ?BaseType
	{
		if (!$decl instanceof BaseDeclaration
			|| $decl->declared_type !== null
			|| !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return null;
		}

		return ASTHelper::get_noted_type($decl);
	}

	private function get_key_accessing_assignment_type(KeyAccessing $left, BaseType $read_type): BaseType
	{
		$basing_type = ASTHelper::get_expressed_type($left->basing);
		if ($basing_type instanceof DictType && $basing_type->generic_type instanceof BaseType) {
			return $basing_type->generic_type;
		}

		return $read_type;
	}

	private function widen_php_array_append_assignment(SquareAccessing $left, BaseType $read_type, BaseType $assigned_type): BaseType
	{
		if (!$left->basing instanceof Identifiable) {
			return $read_type;
		}

		$decl = ASTHelper::get_identifier_symbol($left->basing)->declaration ?? null;
		if (!$decl instanceof IVariableDeclaration) {
			return $read_type;
		}

		$basing_type = ASTHelper::get_expressed_type($left->basing);
		if (!$basing_type instanceof ArrayType) {
			return $read_type;
		}

		$current_generic = $basing_type->generic_type ?? TypeFactory::$_mixed;
		if (TypeHelper::is_value_compatible($current_generic, $assigned_type)) {
			return $read_type;
		}

		$widened_generic = $current_generic->unite($assigned_type);
		$decl->bind_type(TypeFactory::create_array_type($widened_generic));
		$this->context->get_block()->add_rebound_variable($decl);

		return $widened_generic;
	}

	private function create_php_dynamic_variable_declaration(VariableIdentifier $identifier): VariableDeclaration
	{
		$decl = new VariableDeclaration($identifier->name);
		$block = $this->context->get_block();
		$decl->block = $block;
		$symbol = new Symbol($decl);
		ASTHelper::set_identifier_symbol($identifier, $symbol);
		if ($block instanceof IBlock) {
			$block->set_symbol($identifier->name, $symbol);
		}

		return $decl;
	}
}
