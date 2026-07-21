<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ValueTrustResolver
{
	private CheckerContext $context;
	private NameResolver $name_resolver;

	public function __construct(CheckerContext $context, NameResolver $name_resolver)
	{
		$this->context = $context;
		$this->name_resolver = $name_resolver;
	}

	public function mark_call_value_trust(BaseCallExpression $node, IDeclaration $decl): void
	{
		$this->mark_declaration_value_trust($node, $decl, true);
	}

	public function mark_member_value_trust(AccessingIdentifier $node, IDeclaration $decl): void
	{
		$this->mark_declaration_value_trust($node, $decl);
	}

	private function mark_declaration_value_trust(BaseExpression $node, IDeclaration $decl, bool $callable_only = false): void
	{
		if (!$decl instanceof BaseDeclaration) {
			return;
		}

		if ($callable_only && !$decl instanceof ICallableDeclaration) {
			return;
		}

		if ($decl->noted_type_from_phpdoc) {
			OutputSafety::set_value_trust($node, ValueTrust::PHPDOC_ONLY);
			return;
		}

		if ($this->is_local_checked_declaration($decl)) {
			OutputSafety::set_value_trust($node, ValueTrust::CHECKED_LOCAL);
			return;
		}

		if ($this->is_trusted_unit_declaration($decl)) {
			OutputSafety::set_value_trust($node, ValueTrust::TRUSTED_DECLARATION);
			return;
		}

		if ($decl->program !== null && Program::is_header_source_dialect($decl->program->source_dialect)) {
			OutputSafety::set_value_trust($node, ValueTrust::HEADER_ONLY);
		}
	}

	private function is_local_checked_declaration(BaseDeclaration $decl): bool
	{
		$program = $decl->program;
		return !$decl->is_extern
			&& $program !== null
			&& $program->unit === $this->context->get_unit()
			&& !Program::is_header_source_dialect($program->source_dialect);
	}

	private function is_trusted_unit_declaration(BaseDeclaration $decl): bool
	{
		if ($decl->belong_block instanceof ClassKindredDeclaration) {
			return $this->is_trusted_unit_declaration($decl->belong_block);
		}

		$unit = $decl->program?->unit;
		if ($unit !== null) {
			return $unit->is_trusted;
		}

		$builtin_unit = $this->context->get_builtin_unit();
		return ($builtin_unit !== null
				&& $builtin_unit->is_trusted
				&& $this->name_resolver->get_symbol_in_unit($builtin_unit, $decl->name)?->declaration === $decl)
			|| ($decl instanceof RootDeclaration && $decl->ns?->uri === _BUILTIN_NS);
	}
}
