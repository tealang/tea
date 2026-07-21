<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ClassPreprocessor
{
	private CheckerContext $context;
	private OverrideResolver $override_resolver;
	private ClassKindredLookupResolver $classkindred_lookup_resolver;

	public function __construct(
		CheckerContext $context,
		ClassKindredLookupResolver $classkindred_lookup_resolver,
		OverrideResolver $override_resolver
	) {
		$this->context = $context;
		$this->classkindred_lookup_resolver = $classkindred_lookup_resolver;
		$this->override_resolver = $override_resolver;
	}

	public function preprocess_classkindred_declaration(ClassKindredDeclaration $node): void
	{
		$temp_program = $this->context->get_program();
		$node->program and $this->context->set_program($node->program);

		foreach ($node->members as $member) {
			if ($member instanceof TraitsUsingStatement) {
				$node->append_trait_using($member);
			}
		}

		if ($node->extends) {
			$this->preprocess_bases_for_classkindred_declaration($node);
		}

		ASTHelper::set_trait_members($node, $this->dig_trait_members_for($node));
		ASTHelper::set_aggregated_members($node, array_merge(ASTHelper::get_trait_members($node), ASTHelper::get_scope_symbols($node)));

		if ($node->extends) {
			$digged = $this->dig_members_in_extends_for($node);
			ASTHelper::set_aggregated_members($node, array_merge($digged, ASTHelper::get_aggregated_members($node)));
		}

		if ($node->implements) {
			$digged = $this->dig_members_in_implements_for($node);
			ASTHelper::set_aggregated_members($node, array_merge($digged, ASTHelper::get_aggregated_members($node)));
		}

		ASTHelper::set_aggregated_members($node, array_merge(ASTHelper::get_aggregated_members($node), ASTHelper::get_scope_symbols($node)));

		$this->context->mark_classkindred_ready($node);

		$this->context->set_program($temp_program);
	}

	private function preprocess_bases_for_classkindred_declaration(ClassKindredDeclaration $node): void
	{
		$is_interface = $node instanceof InterfaceDeclaration;
		$class_identifiers = [];
		$interface_identifiers = [];
		foreach ($node->extends as $identifier) {
			$based_decl = $this->classkindred_lookup_resolver->get_unchecked_classkindred_declaration($identifier);

			if ($based_decl instanceof ClassDeclaration) {
				if ($is_interface) {
					$error = $this->context->new_syntax_error("Cannot to inherits a class for interface '{$node->name}'", $node);
					throw $error;
				}
				elseif ($class_identifiers) {
					$error = $this->context->new_syntax_error("Only one super class could be inherits for class '{$node->name}'", $node);
					throw $error;
				}
				else {
					$class_identifiers[] = $identifier;
				}
			}
			else {
				$interface_identifiers[] = $identifier;
			}
		}

		if ($is_interface) {
			if ($node->implements) {
				$error = $this->context->new_syntax_error("Cannot implements in interface '{$node->name}'", $node);
				throw $error;
			}
		}
		else {
			if ($node->implements && $interface_identifiers) {
				$error = $this->context->new_syntax_error("Please appending interfaces by implements", $node);
				throw $error;
			}

			foreach ($node->implements as $identifier) {
				$based_decl = $this->classkindred_lookup_resolver->get_unchecked_classkindred_declaration($identifier);
				if (!$based_decl instanceof InterfaceDeclaration) {
					$error = $this->context->new_syntax_error("Cannot implements to a class '{$identifier->name}'", $identifier);
					throw $error;
				}

				$interface_identifiers[] = $identifier;
			}

			$node->extends = $class_identifiers;
			$node->implements = $interface_identifiers;
		}
	}

	private function dig_trait_members_for(ClassKindredDeclaration $node): array
	{
		$items = [];
		foreach ($node->usings as $using) {
			foreach ($using->items as $identifier) {
				$decl = $this->classkindred_lookup_resolver->get_unchecked_classkindred_declaration($identifier);
				if (!$decl instanceof TraitDeclaration) {
					$error = $this->context->new_syntax_error("Invalid using trait of '{$identifier->name}'", $identifier);
					throw $error;
				}

				if (!$this->context->is_classkindred_ready($decl)) {
					$this->preprocess_classkindred_declaration($decl);
				}

				$node->unite_feature_flags($decl->feature_flags);
				$items = array_merge($items, ASTHelper::get_trait_members($decl));
				foreach (ASTHelper::get_aggregated_members($decl) as $name => $member_symbol) {
					$items[$name] = $member_symbol;
				}
			}
		}

		return $items;
	}

	private function dig_members_in_extends_for(ClassKindredDeclaration $node): array
	{
		$items = [];
		foreach ($node->extends as $identifier) {
			$based_decl = $this->classkindred_lookup_resolver->get_unchecked_classkindred_declaration($identifier);

			$node->unite_feature_flags($based_decl->feature_flags);
			$items = array_merge($items, ASTHelper::get_aggregated_members($based_decl));
		}

		foreach ($items as $name => $super_member_symbol) {
			$current_member_symbol = ASTHelper::get_aggregated_members($node)[$name] ?? null;
			if ($current_member_symbol) {
				$super_member = $super_member_symbol->declaration;
				$current_member = $current_member_symbol->declaration;
				if ($this->separate_php_property_method_namespace($node, $items, $name, $current_member_symbol, $super_member_symbol)) {
					continue;
				}

				$this->override_resolver->check_overrided_member($current_member, $super_member, false);
			}
		}

		return $items;
	}

	private function separate_php_property_method_namespace(ClassKindredDeclaration $node, array &$items, string $name, Symbol $current_member_symbol, Symbol $super_member_symbol): bool
	{
		$current_member = $current_member_symbol->declaration;
		$super_member = $super_member_symbol->declaration;
		if ($current_member instanceof PropertyDeclaration && $super_member instanceof MethodDeclaration) {
			$property_key = ClassKindredDeclaration::get_property_symbol_key($current_member->name);
			$members = ASTHelper::get_aggregated_members($node);
			$members[$property_key] = $current_member_symbol;
			unset($members[$name], $node->members[$name]);
			ASTHelper::unset_scope_symbol($node, $name);
			ASTHelper::set_scope_symbol($node, $property_key, $current_member_symbol);
			$node->members[$property_key] = $current_member;
			ASTHelper::set_aggregated_members($node, $members);
			return true;
		}

		if ($current_member instanceof MethodDeclaration && $super_member instanceof PropertyDeclaration) {
			$property_key = ClassKindredDeclaration::get_property_symbol_key($super_member->name);
			$items[$property_key] = $super_member_symbol;
			unset($items[$name]);
			return true;
		}

		return false;
	}

	private function dig_members_in_implements_for(ClassKindredDeclaration $node): array
	{
		$items = [];
		foreach ($node->implements as $identifier) {
			$based_decl = $this->classkindred_lookup_resolver->get_unchecked_classkindred_declaration($identifier);
			if (!$based_decl instanceof InterfaceDeclaration) {
				$error = $this->context->new_syntax_error("Cannot implements to a class '{$identifier->name}'", $identifier);
				throw $error;
			}

			$node->unite_feature_flags($based_decl->feature_flags);

			foreach (ASTHelper::get_aggregated_members($based_decl) as $name => $member_symbol) {
				$member_decl = $member_symbol->declaration;
				$exist_member_symbol = $items[$name] ?? null;
				if ($exist_member_symbol) {
					$exist_member = $exist_member_symbol->declaration;
					$this->override_resolver->check_overrided_member($exist_member, $member_decl, true);

					if ($member_decl instanceof MethodDeclaration && $member_decl->body !== null) {
						$items[$name] = $member_symbol;
					}
				}
				else {
					$items[$name] = $member_symbol;
				}
			}
		}

		if ($node instanceof ClassDeclaration && $node->define_mode) {
			foreach ($items as $name => $interface_member_symbol) {
				$interface_member = $interface_member_symbol->declaration;
				$current_member_symbol = ASTHelper::get_aggregated_members($node)[$name] ?? null;
				if ($current_member_symbol) {
					$current_member = $current_member_symbol->declaration;
					$this->override_resolver->check_overrided_member($current_member, $interface_member, true);
				}
				elseif ($interface_member instanceof MethodDeclaration && $interface_member->body === null) {
					$interface = $interface_member->belong_block;
					$error = $this->context->new_syntax_error("Method protocol '{$interface->name}.{$name}' required an implementation in class '{$node->name}'", $node);
					throw $error;
				}
			}
		}

		return $items;
	}
}
