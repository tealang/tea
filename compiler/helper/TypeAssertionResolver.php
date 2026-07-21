<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeAssertionResolver
{
	private CheckerContext $context;
	private $get_stored_assertion;
	private FlowNarrowingResolver $flow_narrowing_resolver;
	private $infer_accessing_identifier;

	public function __construct(
		CheckerContext $context,
		callable $get_stored_assertion,
		FlowNarrowingResolver $flow_narrowing_resolver,
		callable $infer_accessing_identifier
	) {
		$this->context = $context;
		$this->get_stored_assertion = $get_stored_assertion;
		$this->flow_narrowing_resolver = $flow_narrowing_resolver;
		$this->infer_accessing_identifier = $infer_accessing_identifier;
	}

	public function resolve_condition_assertion(BaseExpression $expr): array
	{
		$is_not = false;
		if ($expr instanceof PrefixOperation and $expr->operator->is(OPID::BOOL_NOT)) {
			$is_not = true;
			$expr = $expr->expression;
		}

		$assertion = null;
		if ($expr instanceof IsOperation && $expr->right instanceof BaseType) {
			$assertion = $expr;
		}
		elseif ($expr instanceof BinaryOperation) {
			$assertion = ($this->get_stored_assertion)($expr);
		}
		elseif ($expr instanceof BaseCallExpression) {
			$assertion = $this->resolve_call_assertion($expr);
		}
		elseif ($expr instanceof Identifiable && $this->flow_narrowing_resolver->can_assert_nullable_identifiable($expr)) {
			$assertion = new IsOperation($expr, TypeFactory::$_none, true);
		}

		if ($assertion and $assertion->not) {
			$is_not = !$is_not;
		}

		return [$assertion, $is_not];
	}

	public function resolve_empty_condition_target(BaseExpression $expr): array
	{
		$is_not = false;
		if ($expr instanceof PrefixOperation && $expr->operator->is(OPID::BOOL_NOT)) {
			$is_not = true;
			$expr = $expr->expression;
		}

		if (!$expr instanceof BaseCallExpression) {
			return [null, false];
		}

		return [$this->get_single_identifiable_argument_for($expr, LanguageMetadataRegistry::INTRINSIC_EMPTY), $is_not];
	}

	public function resolve_call_assertion(BaseCallExpression $expr): ?IsOperation
	{
		$assertion = $this->get_isset_type_assertion($expr);
		$assertion ??= $this->get_declared_php_type_assertion($expr);
		$assertion ??= $this->get_php_builtin_fallback_type_assertion($expr);
		$assertion ??= $this->get_php_predicate_type_assertion($expr);

		return $assertion;
	}

	public function get_plain_callee_name(BaseExpression $callee): ?string
	{
		if (!$callee instanceof PlainIdentifier) {
			return null;
		}

		return ltrim($callee->name, '\\');
	}

	private function get_isset_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		$argument = $this->get_single_identifiable_argument_for($expr, LanguageMetadataRegistry::INTRINSIC_ISSET);
		if (!$argument || !$this->flow_narrowing_resolver->can_assert_nullable_identifiable($argument)) {
			return null;
		}

		return new IsOperation($argument, TypeFactory::$_none, true);
	}

	private function get_single_identifiable_argument_for(BaseCallExpression $expr, string $callee_name): ?Identifiable
	{
		if (count($expr->arguments) !== 1 || $expr->named_arguments) {
			return null;
		}

		if ($this->get_plain_callee_name($expr->callee) !== $callee_name) {
			return null;
		}

		$argument = $expr->arguments[0];
		return $argument instanceof Identifiable ? $argument : null;
	}

	private function get_php_builtin_fallback_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		if (!$this->context->is_weakly_checking() || $this->context->get_builtin_unit() !== null || count($expr->arguments) !== 1 || $expr->named_arguments) {
			return null;
		}

		$type = TypeHelper::get_php_builtin_predicate_asserted_type_fallback($this->get_plain_callee_name($expr->callee));

		return $type ? new IsOperation($expr->arguments[0], $type) : null;
	}

	private function get_declared_php_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		if (!$this->context->is_weakly_checking() || $expr->named_arguments) {
			return null;
		}

		$callee_decl = ASTHelper::get_callee_declaration($expr);
		if (!$callee_decl instanceof IFunctionDeclaration) {
			return null;
		}

		foreach (ASTHelper::get_php_true_assertions($callee_decl) as $index => $type) {
			if (isset($expr->arguments[$index])) {
				return new IsOperation($expr->arguments[$index], $type);
			}
		}

		return $this->get_declared_php_property_type_assertion($expr, $callee_decl);
	}

	private function get_declared_php_property_type_assertion(BaseCallExpression $expr, IFunctionDeclaration $callee_decl): ?IsOperation
	{
		$properties = ASTHelper::get_php_true_property_non_null_assertions($callee_decl);
		if (!$properties || !$expr->callee instanceof AccessingIdentifier) {
			return null;
		}

		$basing = $expr->callee->basing;
		foreach ($properties as $property_name) {
			$property = new AccessingIdentifier($basing, $property_name);
			($this->infer_accessing_identifier)($property);
			return new IsOperation($property, TypeFactory::$_none, true);
		}

		return null;
	}

	private function get_php_predicate_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		if (!$this->context->is_weakly_checking() || count($expr->arguments) !== 1 || $expr->named_arguments) {
			return null;
		}

		$callee_name = $this->get_predicate_callee_name($expr->callee);
		if ($callee_name === null || !str_starts_with($callee_name, 'is_')) {
			return null;
		}

		$callee_decl = ASTHelper::get_callee_declaration($expr);
		if (!$callee_decl instanceof IFunctionDeclaration) {
			return null;
		}

		$return_type = $callee_decl->get_expressed_type();
		if (!$return_type instanceof BoolType) {
			return null;
		}

		$parameter = $callee_decl->parameters[0] ?? null;
		if (!$parameter instanceof ParameterDeclaration) {
			return null;
		}

		$param_type = $parameter->get_expressed_type();
		if (!TypeHelper::is_nullable_type($param_type)) {
			return null;
		}

		$argument = $expr->arguments[0];
		if (!$argument instanceof Identifiable) {
			return null;
		}

		$argument_decl = ASTHelper::get_identifier_symbol($argument)->declaration ?? null;
		if (!$argument_decl instanceof IVariableDeclaration) {
			return null;
		}

		$argument_type = $this->flow_narrowing_resolver->get_assertion_original_type($argument_decl);
		if ($argument_type instanceof AnyType || !TypeHelper::is_nullable_type($argument_type)) {
			return null;
		}

		return new IsOperation($argument, TypeHelper::to_non_nullable($argument_type));
	}

	private function get_predicate_callee_name(BaseExpression $callee): ?string
	{
		if ($callee instanceof PlainIdentifier) {
			return ltrim($callee->name, '\\');
		}

		if ($callee instanceof AccessingIdentifier) {
			return $callee->name;
		}

		return null;
	}
}
