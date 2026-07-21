<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class DeclarationChecker
{
	private CheckerContext $context;
	private $infer_expression;
	private $get_and_check_hinted_type;
	private $assert_compile_time_value_for;
	private $assert_type_compatible;
	private $get_unknown_php_value_type;
	private $check_function_declaration;
	private $check_classkindred_declaration;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $get_and_check_hinted_type,
		callable $assert_compile_time_value_for,
		callable $assert_type_compatible,
		callable $get_unknown_php_value_type,
		callable $check_function_declaration,
		callable $check_classkindred_declaration
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->get_and_check_hinted_type = $get_and_check_hinted_type;
		$this->assert_compile_time_value_for = $assert_compile_time_value_for;
		$this->assert_type_compatible = $assert_type_compatible;
		$this->get_unknown_php_value_type = $get_unknown_php_value_type;
		$this->check_function_declaration = $check_function_declaration;
		$this->check_classkindred_declaration = $check_classkindred_declaration;
	}

	public function check_declaration(BaseDeclaration $decl): void
	{
		if ($this->context->is_checked($decl)) {
			return;
		}

		$decl_program = $decl->program ?? null;
		$temp_program = $this->context->get_program();
		$decl_program and $this->context->set_program($decl_program);

		switch ($decl::KIND) {
			case FunctionDeclaration::KIND:
				($this->check_function_declaration)($decl);
				break;

			case BuiltinTypeClassDeclaration::KIND:
			case ClassDeclaration::KIND:
			case EnumDeclaration::KIND:
			case IntertraitDeclaration::KIND:
			case TraitDeclaration::KIND:
			case InterfaceDeclaration::KIND:
				$this->context->is_checked($decl) || ($this->check_classkindred_declaration)($decl);
				break;

			case ConstantDeclaration::KIND:
				$this->check_constant_declaration($decl);
				break;
			case SuperVariableDeclaration::KIND:
			case VariableDeclaration::KIND:
			case ParameterDeclaration::KIND:
				$this->check_variable_declaration($decl);
				break;

			default:
				$kind = $decl::KIND;
				throw $this->context->new_syntax_error("Unexpect declaration kind: '{$kind}'", $decl);
		}

		$decl_program and $this->context->set_program($temp_program);
	}

	public function check_constant_declaration(IConstantDeclaration $node): void
	{
		if ($this->context->is_checked($node)) {
			return;
		}
		$this->context->mark_checked($node);

		$value = $node->value;
		$hinted = $this->get_and_check_hinted_type($node);

		if ($value === null) {
			if (!$hinted) {
				throw $this->context->new_syntax_error("Type hinting for constant declaration '{$node->name}' required", $node);
			}
			return;
		}

		$infered = ($this->infer_expression)($value);
		if (!$infered) {
			throw $this->context->new_syntax_error("Type hinting for constant declaration '{$node->name}' required", $node);
		}

		($this->assert_compile_time_value_for)($node);

		if ($hinted) {
			($this->assert_type_compatible)($hinted, $infered, $node->value);
		}

		$node->infered_type = $infered;
	}

	public function check_variable_declaration(IVariableDeclaration $node): void
	{
		$this->context->mark_checked($node);

		$infered = $node->value ? ($this->infer_expression)($node->value) : null;

		$this->set_variable_kindred_declaration_type($node, $infered);
		if ($node instanceof VariableDeclaration && $node->value !== null) {
			TypeHelper::set_bound_value($node, $node->value);
		}
		$this->bind_variadic_parameter_array_type($node);
	}

	public function check_parameters_for_callable_declaration(ICallableDeclaration $node): void
	{
		foreach ($node->parameters as $parameter) {
			$this->check_variable_declaration($parameter);
			if ($parameter->value !== null) {
				($this->assert_compile_time_value_for)($parameter);
			}
		}
	}

	public function check_property_declaration(PropertyDeclaration $node): void
	{
		if ($node->value !== null) {
			$infered = ($this->infer_expression)($node->value);
			($this->assert_compile_time_value_for)($node);
		}
		else {
			$infered = null;
		}

		$this->set_variable_kindred_declaration_type($node, $infered);
	}

	public function check_enum_case_declaration(EnumCaseDeclaration $node): void
	{
		if ($node->value !== null) {
			($this->infer_expression)($node->value);
			($this->assert_compile_time_value_for)($node);
		}

		$node->infered_type = $node->belong_block->typing_identifier;
	}

	public function check_class_constant_declaration(ClassConstantDeclaration $node): void
	{
		$infered = isset($node->value) ? ($this->infer_expression)($node->value) : null;

		$hinted = $this->get_and_check_hinted_type($node);
		if ($hinted && $infered && !$infered instanceof NoneType) {
			($this->assert_type_compatible)($hinted, $infered, $node->value);
		}

		$node->infered_type = $infered ?? $hinted ?? ($this->get_unknown_php_value_type)();
	}

	private function get_and_check_hinted_type(IDeclaration|AnonymousFunction|CallableType $node): ?BaseType
	{
		return ($this->get_and_check_hinted_type)($node);
	}

	private function set_variable_kindred_declaration_type(BaseDeclaration $node, ?BaseType $infered): void
	{
		$hinted = $this->get_and_check_hinted_type($node);
		if ($hinted) {
			if ($infered) {
				if ($node->value instanceof LiteralDefaultMark) {
					//
				}
				elseif ($this->is_nullable_default_allowed_by_declared_type($node, $infered)) {
					//
				}
				elseif ($this->is_phpdoc_null_default_allowed($node, $infered)) {
					//
				}
				else {
					($this->assert_type_compatible)($hinted, $infered, $node->value);
				}
			}

			$infered = $hinted;
		}
		elseif ($infered === TypeFactory::$_uint && $node->value instanceof LiteralInteger) {
			//
		}
		elseif ($infered instanceof NoneType) {
			$infered = ($this->get_unknown_php_value_type)();
		}

		$node->infered_type = $infered;
	}

	private function is_nullable_default_allowed_by_declared_type(BaseDeclaration $node, BaseType $infered): bool
	{
		return $infered instanceof NoneType
			&& $node->declared_type instanceof BaseType
			&& TypeHelper::is_nullable_type($node->declared_type);
	}

	private function is_phpdoc_null_default_allowed(BaseDeclaration $node, BaseType $infered): bool
	{
		return $this->context->is_weakly_checking()
			&& $infered instanceof NoneType
			&& $node->declared_type === null
			&& ASTHelper::is_noted_type_from_phpdoc($node);
	}

	private function bind_variadic_parameter_array_type(IVariableDeclaration $node): void
	{
		if (!$node instanceof ParameterDeclaration || !$node->is_variadic) {
			return;
		}

		$element_type = ASTHelper::get_noted_type($node) ?? $node->declared_type ?? ($this->get_unknown_php_value_type)();
		$node->bind_type(TypeFactory::create_array_type($element_type));
	}
}
