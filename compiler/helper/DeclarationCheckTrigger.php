<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class DeclarationCheckTrigger
{
	private CheckerContext $context;
	private UseResolver $use_resolver;
	private DeclarationChecker $declaration_checker;

	public function __construct(
		CheckerContext $context,
		UseResolver $use_resolver,
		DeclarationChecker $declaration_checker
	) {
		$this->context = $context;
		$this->use_resolver = $use_resolver;
		$this->declaration_checker = $declaration_checker;
	}

	public function check_declaration(BaseDeclaration $decl, bool $skip_current_function_owner = false): void
	{
		if ($this->context->is_checked($decl)) {
			return;
		}

		if ($skip_current_function_owner && $this->is_current_function_owner_declaration($decl)) {
			return;
		}

		$this->declaration_checker->check_declaration($decl);
	}

	public function check_declaration_for_symbol(Symbol $symbol): void
	{
		$decl = $symbol->declaration;
		if ($decl instanceof UseDeclaration) {
			$decl = $this->use_resolver->resolve_use_symbol_declaration($symbol);
			if ($decl === null) {
				return;
			}
		}

		$this->check_declaration($decl);
	}

	public function check_parameters_for_callable_declaration(ICallableDeclaration $decl): void
	{
		$this->declaration_checker->check_parameters_for_callable_declaration($decl);
	}

	private function is_current_function_owner_declaration(IDeclaration $decl): bool
	{
		$function = $this->context->get_function();
		return $function instanceof IFunctionDeclaration
			&& $function->belong_block === $decl;
	}
}
