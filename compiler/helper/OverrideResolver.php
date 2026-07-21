<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class OverrideResolver
{
	private CheckerContext $context;

	public function __construct(CheckerContext $context)
	{
		$this->context = $context;
	}

	public function check_overrided_member(IClassMemberDeclaration $node, IClassMemberDeclaration $super, bool $is_interface = false): void
	{
		// do not need to check for construct
		if ($node->name === _CONSTRUCT) {
			return;
		}

		// the accessing modifer
		$super_modifier = $super->modifier ?? _PUBLIC;
		$current_modifier = $node->modifier ?? _PUBLIC;
		if ($super_modifier !== $current_modifier) {
			if (($super_modifier === _PROTECTED || $super_modifier === _INTERNAL) && $current_modifier === _PUBLIC) {
				// pass
			}
			else {
				throw $this->context->new_syntax_error("Modifier in '{$node->belong_block->name}.{$node->name}' must be same as '{$super->belong_block->name}.{$super->name}'", $node);
			}
		}

		$covariance_mode = false;
		if ($super instanceof MethodDeclaration) {
			if (!$node instanceof MethodDeclaration) {
				throw $this->context->new_syntax_error("Member declaration kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
			}

			$this->check_overrided_method_parameters($node, $super);
			$covariance_mode = true;
		}
		elseif ($super instanceof PropertyDeclaration) {
			if (!$node instanceof PropertyDeclaration) {
				throw $this->context->new_syntax_error("Member declaration kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
			}
		}
		elseif ($super instanceof ClassConstantDeclaration && $is_interface) {
			throw $this->context->new_syntax_error("Cannot override interface constant '{$super->belong_block->name}.{$super->name}' in '{$node->belong_block->name}'", $node);
		}

		$this->check_overrided_member_declared_type($node, $super, $covariance_mode);
	}

	private function check_overrided_member_declared_type(IClassMemberDeclaration $node, IClassMemberDeclaration $super, bool $covariance_mode): void
	{
		// only check the declared
		$current_type = $node->declared_type;
		$super_type = $super->declared_type;

		if ($super_type === null) {
			if ($current_type === null) {
				return;
			}

			if ($this->context->is_weakly_checking() && $covariance_mode) {
				return;
			}

			$current_type_name = ASTChecker::get_type_name($current_type);
			throw $this->context->new_syntax_error("There are declared type '{$current_type_name}' in '{$node->belong_block->name}', but not declared in '{$super->belong_block->name}'", $node);
		}

		if ($current_type === null) {
			$super_type_name = ASTChecker::get_type_name($super_type);
			throw $this->context->new_syntax_error("There are not declared type in '{$node->belong_block->name}', but declared '{$super_type_name}' in '{$super->belong_block->name}'", $node);
		}

		$current_type = $this->rebind_self_type_for_member_declared_type($current_type, $node);
		$super_type = $this->rebind_self_type_for_member_declared_type($super_type, $super);

		// supports Covariance
		$compatible = $covariance_mode
			? TypeHelper::is_covariant_for($current_type, $super_type)
			: $current_type->is_same_with($super_type);
		if (!$compatible) {
			$current_type_name = ASTChecker::get_type_name($current_type);
			$super_type_name = ASTChecker::get_type_name($super_type);
			throw $this->context->new_syntax_error("The declared type '{$current_type_name}' in '{$node->belong_block->name}' is incompatible with '$super_type_name' in '{$super->belong_block->name}'", $node);
		}
	}

	private function rebind_self_type_for_member_declared_type(BaseType $type, IClassMemberDeclaration $member): BaseType
	{
		if (!$this->contains_self_type($type) || !$member->belong_block instanceof ClassKindredDeclaration) {
			return $type;
		}

		return $this->replace_self_type($type, $member->belong_block->typing_identifier);
	}

	private function check_overrided_method_parameters(MethodDeclaration $node, MethodDeclaration $protocol): void
	{
		if ($protocol->parameters === null && $protocol->parameters === null) {
			return;
		}

		// the parameters count
		if (count($protocol->parameters) > count($node->parameters)) {
			throw $this->context->new_syntax_error("Parameters of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$protocol->belong_block->name}.{$protocol->name}'", $node);
		}

		// the parameter types
		foreach ($protocol->parameters as $idx => $protocol_param) {
			$node_param = $node->parameters[$idx];
			$super_type = $this->get_override_parameter_hinted_type($protocol_param, $protocol);
			$current_type = $this->get_override_parameter_hinted_type($node_param, $node);
			if (!$this->is_overrided_method_param_compatible_types($super_type, $current_type)) {
				$type_name = ASTChecker::get_type_name($current_type);
				throw $this->context->new_syntax_error("Parameter '{$node_param->name} {$type_name}' in '{$node->belong_block->name}.{$node->name}' is incompatible with '{$protocol->belong_block->name}.{$protocol->name}'", $node_param);
			}
		}
	}

	private function get_override_parameter_hinted_type(ParameterDeclaration $node, IFunctionDeclaration $method): BaseType
	{
		$program = $method->program;
		if ($program === null && $method->belong_block instanceof BaseDeclaration) {
			$program = $method->belong_block->program;
		}

		if ($program?->is_native === true && $node->declared_type !== null) {
			$type = $node->declared_type;
		}
		elseif (ASTHelper::is_noted_type_from_phpdoc($node) && $node->declared_type !== null) {
			$type = $node->declared_type;
		}
		else {
			$type = $node->get_hinted_type();
		}

		if (($type instanceof SelfType || $type->name === _TYPE_SELF)
			&& $method->belong_block instanceof ClassKindredDeclaration) {
			return $method->belong_block->typing_identifier;
		}

		return $type;
	}

	private function is_overrided_method_param_compatible_types(BaseType $super, BaseType $current): bool
	{
		return TypeHelper::is_override_parameter_compatible($super, $current, $this->context->is_weakly_checking());
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
}
