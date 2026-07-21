<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class SimpleStatementResolver
{
	private CheckerContext $context;
	private TypeCompatibilityReporter $type_compatibility_reporter;
	private TypeReferenceChecker $type_reference_checker;
	private $infer_expression;
	private DeclarationChecker $declaration_checker;

	public function __construct(
		CheckerContext $context,
		TypeCompatibilityReporter $type_compatibility_reporter,
		TypeReferenceChecker $type_reference_checker,
		callable $infer_expression,
		DeclarationChecker $declaration_checker
	) {
		$this->context = $context;
		$this->type_compatibility_reporter = $type_compatibility_reporter;
		$this->type_reference_checker = $type_reference_checker;
		$this->infer_expression = $infer_expression;
		$this->declaration_checker = $declaration_checker;
	}

	public function check_throw_statement(ThrowStatement $node): void
	{
		($this->infer_expression)($node->argument);
	}

	public function infer_return_statement(ReturnStatement $node): ?BaseType
	{
		$infered = $node->argument ? ($this->infer_expression)($node->argument) : null;
		$hinted = $this->get_current_function_hinted_return_type();
		if ($hinted && $infered && $node->argument) {
			$function = $this->context->get_function();
			if ($function instanceof BaseDeclaration
				&& $this->type_compatibility_reporter->warn_phpdoc_only_type_mismatch($function, $hinted, $infered, $node->argument, 'return')) {
				return $infered;
			}

			$this->type_compatibility_reporter->assert_type_compatible($hinted, $infered, $node->argument, 'return');
		}

		return $infered;
	}

	public function check_exit_statement(ExitStatement $node): void
	{
		$node->argument === null || $this->expect_infered_type($node->argument, TypeFactory::$_int_and_string_types);
	}

	public function check_unset_statement(UnsetStatement $node): void
	{
		$argument = $node->argument;
		$allow_php_soft_key_access = $this->context->allows_php_soft_key_access();
		$this->context->set_php_soft_key_access(true);
		try {
			($this->infer_expression)($argument);
		}
		finally {
			$this->context->set_php_soft_key_access($allow_php_soft_key_access);
		}

		if (!$argument instanceof KeyAccessing) {
			$error = $this->context->new_syntax_error("The unset target must be a KeyAccessing", $argument);
			throw $error;
		}
	}

	public function check_var_statement(VarStatement $node): void
	{
		foreach ($node->members as $member) {
			$this->declaration_checker->check_variable_declaration($member);
		}
	}

	public function check_echo_statement(EchoStatement $node): void
	{
		foreach ($node->arguments as $argument) {
			($this->infer_expression)($argument);
		}
	}

	public function expect_infered_type(BaseExpression $node, array $types): BaseType
	{
		$infered = ($this->infer_expression)($node);
		if (!in_array($infered, $types, true)) {
			$names = array_column($types, 'name');
			$names = join(' or ', $names);
			$infered_name = ASTChecker::get_type_name($infered);
			$error = $this->context->new_syntax_error("Expected type $names, {$infered_name} given", $node);
			throw $error;
		}

		return $infered;
	}

	private function get_current_function_hinted_return_type(): ?BaseType
	{
		$function = $this->context->get_function();
		if (!$function) {
			return null;
		}

		$hinted = $this->type_reference_checker->get_and_check_hinted_type($function);
		if ($hinted instanceof BaseType && $hinted->name === _TYPE_SELF) {
			$decl = $function->belong_block;
			$hinted = $decl->typing_identifier;
		}

		return $hinted;
	}
}
