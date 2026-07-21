<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class CallResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private InstancingResolver $instancing_resolver;
	private CallableDeclarationResolver $callable_declaration_resolver;
	private TypeCompatibilityReporter $type_compatibility_reporter;
	private AssignmentResolver $assignment_resolver;
	private MemberResolver $member_resolver;
	private SymbolDeclarationResolver $declaration_resolver;
	private ClassKindredLookupResolver $classkindred_lookup_resolver;
	private ValueTrustResolver $value_trust_resolver;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		InstancingResolver $instancing_resolver,
		CallableDeclarationResolver $callable_declaration_resolver,
		TypeCompatibilityReporter $type_compatibility_reporter,
		AssignmentResolver $assignment_resolver,
		MemberResolver $member_resolver,
		SymbolDeclarationResolver $symbol_declaration_resolver,
		ClassKindredLookupResolver $classkindred_lookup_resolver,
		ValueTrustResolver $value_trust_resolver
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->instancing_resolver = $instancing_resolver;
		$this->callable_declaration_resolver = $callable_declaration_resolver;
		$this->type_compatibility_reporter = $type_compatibility_reporter;
		$this->assignment_resolver = $assignment_resolver;
		$this->member_resolver = $member_resolver;
		$this->declaration_resolver = $symbol_declaration_resolver;
		$this->classkindred_lookup_resolver = $classkindred_lookup_resolver;
		$this->value_trust_resolver = $value_trust_resolver;
	}

	public function infer_basecall_expression(BaseCallExpression $node): BaseType
	{
		$callee = $node->callee;
		$callable_decl = $this->require_callee_declaration($callee);

		if ($callable_decl instanceof ClassKindredDeclaration) {
			$infered = $this->instancing_resolver->infer_instancing_expr($callee, $callable_decl);
			$this->check_call_arguments($node, $callable_decl);
		}
		elseif ($callable_decl === TypeFactory::$_callable or $callable_decl->is_virtual) {
			foreach ($node->arguments as $argument) {
				($this->infer_expression)($argument);
			}

			$infered = TypeFactory::$_mixed;
		}
		elseif ($callable_decl instanceof ICallableDeclaration) {
			if ($this->callable_declaration_resolver->should_check_callable_declaration($callable_decl)) {
				$this->context->is_checked($callable_decl) or $this->callable_declaration_resolver->check_callable_declaration($callable_decl);
			}
			$infered = $callable_decl->get_expressed_type();
			$this->check_call_arguments($node, $callable_decl);
		}
		else {
			$error = $this->context->new_syntax_error("Callee not a valid callable declaration", $callee);
			throw $error;
		}

		if ($infered === null) {
			if ($this->context->is_weakly_checking()) {
				$infered = TypeFactory::$_mixed;
			}
			else {
				$error = $this->context->new_syntax_error("Unable to infer return type, there may have been a recursive call", $node);
				throw $error;
			}
		}

		$infered = $this->rebind_self_return_type_for_call($infered, $callee);
		ASTHelper::set_callee_declaration($node, $callable_decl);
		$this->value_trust_resolver->mark_call_value_trust($node, $callable_decl);

		return $infered;
	}

	private function rebind_self_return_type_for_call(BaseType $type, BaseExpression $callee): BaseType
	{
		if (!$callee instanceof AccessingIdentifier || !$this->contains_self_type($type)) {
			return $type;
		}

		$receiver_type = ASTHelper::get_expressed_type($callee->basing) ?? ($this->infer_expression)($callee->basing);
		if ($receiver_type instanceof MetaType) {
			$receiver_type = $receiver_type->generic_type;
		}

		return $this->replace_self_type($type, $receiver_type);
	}

	private function contains_self_type(BaseType $type): bool
	{
		if ($type instanceof SelfType || $type->name === _TYPE_SELF) {
			return true;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if ($this->contains_self_type($member)) {
					return true;
				}
			}
		}
		elseif ($type instanceof InvalidableType) {
			return $this->contains_self_type($type->valid_type);
		}

		return false;
	}

	private function replace_self_type(BaseType $type, BaseType $receiver_type): BaseType
	{
		if ($type instanceof SelfType || $type->name === _TYPE_SELF) {
			return $receiver_type;
		}

		if ($type instanceof UnionType) {
			$members = [];
			foreach ($type->get_members() as $member) {
				$members[] = $this->replace_self_type($member, $receiver_type);
			}
			return TypeFactory::create_union_type($members);
		}

		if ($type instanceof InvalidableType) {
			return TypeFactory::create_invalidable_type($this->replace_self_type($type->valid_type, $receiver_type), $type->sentinel);
		}

		return $type;
	}

	public function check_call_arguments(BaseCallExpression $node, ICallableDeclaration $callee_decl): void
	{
		$arguments = $node->arguments;

		if ($callee_decl instanceof ClassDeclaration) {
			$constructor_decl = $this->instancing_resolver->get_construct_declaration_for_class($callee_decl, $node);
			if ($constructor_decl === null) {
				if ($arguments) {
					if (!$this->context->is_weakly_checking()) {
						$error = $this->context->new_syntax_error("Cannot use arguments to create a non-construct class instance", $node);
						throw $error;
					}

					foreach ($arguments as $argument) {
						($this->infer_expression)($argument);
					}
				}

				return;
			}

			$callee_decl = $constructor_decl;
		}

		$parameters = $callee_decl->parameters;

		if (isset($node->callbacks)) {
			$this->merge_callbacks_to_arguments($arguments, $node->callbacks, $parameters);
		}

		$has_named_arguments = false;
		$last_variadic_param = $this->get_last_variadic_param($parameters);
		$first_unpack_argument_index = null;

		$normalizeds = [];
		foreach ($arguments as $key => $argument) {
			if (is_numeric($key)) {
				$parameter = $parameters[$key] ?? $last_variadic_param;
				if (!$parameter) {
					if ($this->context->is_weakly_checking()) {
						($this->infer_expression)($argument);
						$normalizeds[$key] = $argument;
						continue;
					}

					$declar_name = $callee_decl instanceof BaseDeclaration
						? ASTChecker::get_declaration_name($callee_decl)
						: 'callable';
					$error = $this->context->new_syntax_error("Argument $key does not matched the parameter defined in '{$declar_name}'", $argument);
					throw $error;
				}

				$idx = $key;
			}
			else {
				$has_named_arguments = true;
				list($idx, $parameter) = $this->require_parameter_by_name($key, $parameters, $node->callee);
			}

			if ($first_unpack_argument_index === null && $this->is_argument_unpack($argument)) {
				$first_unpack_argument_index = $idx;
			}

			$param_type = $parameter->get_expressed_type();
			$infered = ($this->infer_expression)($argument);
			if (!$this->type_compatibility_reporter->is_type_compatible($param_type, $infered, $argument, 'argument')
				&& $this->type_compatibility_reporter->should_report_type_mismatch($param_type, $infered, $argument, 'argument')) {
				if ($this->type_compatibility_reporter->warn_phpdoc_only_type_mismatch($parameter, $param_type, $infered, $argument, 'argument')) {
					$normalizeds[$idx] = $argument;
					continue;
				}

				$expected_name = ASTChecker::get_type_name($param_type);
				$infered_name = ASTChecker::get_type_name($infered);

				if (!is_int($key)) {
					$key = "'$key'";
				}

				$error = $this->context->new_syntax_error("Type of argument $key does not matched the parameter, expected {$expected_name}, {$infered_name} given", $argument);
				throw $error;
			}

			if ($parameter->is_inout) {
				if (!ASTHelper::is_assignable_expr($argument)) {
					$error = $this->context->new_syntax_error("Argument $key is final(un-reassignable), cannot use for inout parameter", $argument);
					throw $error;
				}

				$this->rebind_inout_argument_type($argument, $parameter, $param_type);
			}

			$normalizeds[$idx] = $argument;
		}

		foreach ($parameters as $idx => $param) {
			if ($first_unpack_argument_index !== null && $idx >= $first_unpack_argument_index) {
				continue;
			}

			if ($param->value === null && !$param->is_variadic && !isset($normalizeds[$idx])) {
				$callee_name = ASTChecker::get_declaration_name($callee_decl);
				$param_name = $param->name;
				$error = $this->context->new_syntax_error("Required argument '$param_name' to call '{$callee_name}'", $node);
				throw $error;
			}
		}

		if ($has_named_arguments) {
			$last_idx = array_key_last($normalizeds);

			$i = 0;
			foreach ($parameters as $param) {
				if ($i <= $last_idx && !isset($normalizeds[$i])) {
					$normalizeds[$i] = $param->value;
				}

				$i++;
			}

			ksort($normalizeds);
			ASTHelper::set_normalized_arguments($node, $normalizeds);
		}
	}

	public function require_callee_declaration(BaseExpression $node): IDeclaration
	{
		$decl = null;
		if ($node instanceof AccessingIdentifier) {
			$basing_type = ($this->infer_expression)($node->basing);
			$decl = $this->member_resolver->require_accessing_identifier_declaration($basing_type, $node);
		}
		elseif ($node instanceof PlainIdentifier) {
			$decl = $this->require_callable_declaration($node);
		}
		else {
			$type = ($this->infer_expression)($node);
			$decl = TypeHelper::get_callable_declaration_from_type($type);
			if ($decl === null) {
				if ($this->allow_dynamic_callable_invocation($type)) {
					[$decl, ] = $this->create_virtual_function('__php_unknow_func', $node);
				}
				else {
					$type_name = ASTChecker::get_type_name(ASTHelper::get_expressed_type($node));
					$error = $this->context->new_syntax_error("Unknow callee type: $type_name", $node);
					throw $error;
				}
			}
		}

		if ($decl instanceof ObjectMember) {
			$value = $decl->value;
			if (!$value instanceof IDeclaration) {
				$error = $this->context->new_syntax_error("Invalid callable expression", $node);
				throw $error;
			}

			return $value;
		}
		if ($decl instanceof IVariableDeclaration) {
			$type = TypeHelper::get_bound_type($decl);
			if ($type instanceof MetaType) {
				$type = $type->generic_type;
			}

			return TypeHelper::get_type_symbol($type)->declaration;
		}
		if ($decl instanceof IConstantDeclaration) {
			$type = $decl->get_expressed_type();
			if ($type instanceof MetaType) {
				$type = $type->generic_type;
			}

			return TypeHelper::get_type_symbol($type)->declaration;
		}

		return $decl;
	}

	private function require_callable_declaration(BaseExpression $node): IDeclaration
	{
		$decl = $this->declaration_resolver->get_actual_declaration_for_identifier($node);

		$return_type = $decl instanceof IVariableDeclaration
			? TypeHelper::get_bound_type($decl)
			: $decl->get_expressed_type();
		$return_type = TypeHelper::unwrap_excludable_type($return_type);
		$callable_type = TypeHelper::get_callable_declaration_from_type($return_type);
		if ($decl instanceof ICallableDeclaration) {
			$this->context->is_checked($decl) or $this->callable_declaration_resolver->check_callable_declaration($decl);
		}
		elseif ($callable_type !== null) {
			$decl = $callable_type;
		}
		elseif ($return_type instanceof MetaType and $return_type->generic_type instanceof TypeReference) {
			$decl = $this->classkindred_lookup_resolver->get_checked_classkindred_declaration($return_type->generic_type, true);
		}
		elseif ($this->allow_dynamic_callable_invocation($return_type)) {
			[$decl, ] = $this->create_virtual_function('__php_unknow_func', $node);
		}
		else {
			$error = $this->context->new_syntax_error("Invalid callable expression", $node);
			throw $error;
		}

		return $decl;
	}

	private function allow_dynamic_callable_invocation(BaseType $type): bool
	{
		return $this->context->is_weakly_checking() && PHPWeakPolicy::is_dynamic_top_type($type);
	}

	private function create_virtual_function(string $name, Node $node): array
	{
		$factory = $this->context->get_factory();
		if ($factory === null) {
			$error = $this->context->new_syntax_error("Cannot create virtual function '{$name}' without ASTFactory", $node);
			throw $error;
		}

		return $factory->create_virtual_function($name);
	}

	private function get_last_variadic_param(array $parameters)
	{
		$len = count($parameters);
		if ($len === 0) {
			return null;
		}

		$param = $parameters[$len - 1];
		return $param->is_variadic ? $param : null;
	}

	private function is_argument_unpack(BaseExpression $argument): bool
	{
		return $argument instanceof PrefixOperation && $argument->operator->is(OPID::SPREAD);
	}

	private function rebind_inout_argument_type(BaseExpression $argument, ParameterDeclaration $parameter, BaseType $param_type): void
	{
		if (!$argument instanceof Identifiable) {
			return;
		}

		$decl = ASTHelper::get_identifier_symbol($argument)?->declaration ?? null;
		if (!$decl instanceof IVariableDeclaration) {
			return;
		}

		$current_type = TypeHelper::get_bound_type($decl);
		if (!$current_type instanceof NoneType && !$current_type instanceof AnyType && !$current_type instanceof MixedType) {
			return;
		}

		$rebound_type = $parameter->value !== null && TypeHelper::is_nullable_type($param_type)
			? TypeHelper::to_non_nullable($param_type)
			: $param_type;
		$this->assignment_resolver->rebind_type_for_variable($decl, $rebound_type);
	}

	private function merge_callbacks_to_arguments(array &$arguments, array $callbacks, array $parameters): void
	{
		if (count($callbacks) === 1 && $callbacks[0]->name === null) {
			$first_callback_parameter_on_tail = null;
			for ($i = count($parameters) - 1; $i >=0; $i--) {
				$parameter = $parameters[$i];
				$param_type = $parameter->get_expressed_type();
				if ($param_type instanceof CallableType) {
					$first_callback_parameter_on_tail = $parameter;
				}
				else {
					break;
				}
			}

			if (!$first_callback_parameter_on_tail) {
				$error = $this->context->new_syntax_error("Unknow which parameter for callback", $callbacks[0]);
				throw $error;
			}

			$callbacks[0]->name = $first_callback_parameter_on_tail->name;
		}

		foreach ($callbacks as $cb) {
			$arguments[$cb->name] = $cb->value;
		}
	}

	private function require_parameter_by_name(string $name, array $parameters, BaseExpression $callee_node)
	{
		foreach ($parameters as $idx => $parameter) {
			if ($parameter->name === $name) {
				return [$idx, $parameter];
			}
		}

		$error = $this->context->new_syntax_error("Argument '$name' not found in declaration", $callee_node);
		throw $error;
	}
}
