<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class VirtualSymbolFactory
{
	private CheckerContext $context;

	public function __construct(CheckerContext $context)
	{
		$this->context = $context;
	}

	public function create_virtual_symbol(PlainIdentifier|TypeReference $identifier): Symbol
	{
		$factory = $this->require_factory($identifier);

		$name = $identifier->name;
		if ($identifier instanceof BaseExpression) {
			if ($identifier->is_invoking()) {
				[$decl, $symbol] = $identifier->is_instancing()
					? $factory->create_virtual_class($name, $this->context->get_program())
					: $factory->create_virtual_function($name, $this->context->get_program());
			}
			elseif ($identifier->is_accessing() || $identifier instanceof ClassKindredIdentifier) {
				[$decl, $symbol] = $factory->create_virtual_class($name, $this->context->get_program());
			}
			else {
				// treat others as constant identifiers
				[$decl, $symbol] = $factory->create_virtual_constant($name);
			}
		}
		elseif ($identifier instanceof TypeReference) {
			[$decl, $symbol] = $factory->create_virtual_class($name, $this->context->get_program());
		}
		else {
			throw $this->context->new_syntax_error("Symbol of '{$identifier->name}' not found", $identifier);
		}

		if (($identifier instanceof PlainIdentifier || $identifier instanceof TypeReference)
			&& $identifier->ns !== null
			&& $decl instanceof ClassKindredDeclaration
			&& $decl->typing_identifier !== null) {
			$decl->typing_identifier->set_namespace($identifier->ns);
		}

		return $symbol;
	}

	public function create_virtual_class_declaration(string $name): ClassKindredDeclaration
	{
		[$decl, ] = $this->require_factory()->create_virtual_class($name, $this->context->get_program());
		return $decl;
	}

	public function create_trait_host_super_symbol(bool $is_static): Symbol
	{
		$super_class = $this->create_virtual_class_declaration('__TRAIT_HOST_PARENT__');
		return $is_static
			? $super_class->this_class_symbol
			: $super_class->this_object_symbol;
	}

	private function require_factory(PlainIdentifier|TypeReference|null $node = null): ASTFactory
	{
		$factory = $this->context->get_factory();
		if ($factory === null) {
			if ($node !== null) {
				throw $this->context->new_syntax_error("Symbol of '{$node->name}' not found", $node);
			}

			throw new Exception('Cannot create virtual symbol without ASTFactory');
		}

		return $factory;
	}
}
