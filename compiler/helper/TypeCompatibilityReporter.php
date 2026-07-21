<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeCompatibilityReporter
{
	private CheckerContext $context;
	private SymbolDeclarationResolver $declaration_resolver;

	public function __construct(
		CheckerContext $context,
		SymbolDeclarationResolver $symbol_declaration_resolver
	) {
		$this->context = $context;
		$this->declaration_resolver = $symbol_declaration_resolver;
	}

	public function assert_type_compatible(BaseType $left, BaseType $right, Node $value_node, string $kind = 'assign'): void
	{
		if (!$this->is_type_compatible($left, $right, $value_node, $kind) && $this->should_report_type_mismatch($left, $right, $value_node, $kind)) {
			if ($left === TypeFactory::$_none) {
				$error = $this->context->new_syntax_error("It's required a type hint", $value_node);
				throw $error;
			}

			$left_type_name = ASTChecker::get_type_name($left);
			$right_type_name = ASTChecker::get_type_name($right);

			$error = $this->context->new_syntax_error("It's not compatible for type {$left_type_name}, {$kind} with {$right_type_name}", $value_node);
			throw $error;
		}
	}

	public function is_type_compatible(BaseType $left, BaseType $right, Node $value_node, string $kind): bool
	{
		if ($this->context->is_weakly_checking()
			&& $value_node instanceof InstancingExpression
			&& $this->is_dynamic_instancing_value($value_node)
		) {
			return true;
		}

		if ($this->is_type_compatible_for_kind($left, $right, $kind)) {
			return true;
		}

		if ($this->context->is_weakly_checking()
			&& $right instanceof InvalidableType
			&& $this->is_type_compatible_for_kind($left, TypeHelper::get_invalidable_valid_branch_type($right), $kind)) {
			return true;
		}

		if ($value_node instanceof IArrayLikeExpression && !$value_node->items) {
			return true;
		}

		return false;
	}

	public function should_report_type_mismatch(BaseType $expected, BaseType $actual, Node $value_node, string $kind = 'assign'): bool
	{
		if (!$this->context->is_weakly_checking()) {
			return true;
		}

		return TypeHelper::should_report_php_weak_type_mismatch($expected, $actual, $value_node, $kind);
	}

	public function warn_phpdoc_only_type_mismatch(?BaseDeclaration $decl, BaseType $expected, BaseType $actual, Node $value_node, string $kind): bool
	{
		if (!$this->context->is_weakly_checking()
			|| !$decl instanceof BaseDeclaration
			|| $decl->declared_type !== null
			|| !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		if ($this->is_type_compatible($expected, $actual, $value_node, $kind)
			|| !TypeHelper::should_report_php_weak_type_mismatch($expected, $actual, $value_node, $kind)) {
			return false;
		}

		$expected_name = ASTChecker::get_type_name($expected);
		$actual_name = ASTChecker::get_type_name($actual);
		$this->context->add_syntax_warning("PHPDoc {$kind} type mismatch; expected {$expected_name}, {$actual_name} given; degrading to warning in PHP weak mode", $value_node);
		return true;
	}

	private function is_type_compatible_for_kind(BaseType $left, BaseType $right, string $kind): bool
	{
		return match ($kind) {
			'argument' => TypeHelper::is_argument_compatible($left, $right),
			'return' => TypeHelper::is_return_compatible($left, $right),
			default => TypeHelper::is_assignment_compatible($left, $right),
		};
	}

	private function is_dynamic_instancing_value(InstancingExpression $value_node): bool
	{
		$callee = $value_node->callee;
		if ($callee instanceof VariableIdentifier) {
			return true;
		}

		if (!$callee instanceof PlainIdentifier) {
			return true;
		}

		return $this->is_dynamic_instancing_identifier($callee);
	}

	private function is_dynamic_instancing_identifier(PlainIdentifier $callee): bool
	{
		$decl = $this->declaration_resolver->get_actual_declaration_for_identifier($callee);
		return $decl instanceof IVariableDeclaration;
	}
}
