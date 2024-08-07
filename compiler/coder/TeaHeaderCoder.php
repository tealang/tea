<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaHeaderCoder extends BaseCoder
{
	const PROGRAM_HEADER = "// the public declarations\n";

	protected function process_use_statments(Program $program)
	{
		$program->uses = []; // clear the custom use items

		foreach ($program->declarations as $node) {
			$this->collect_use_statements($program, $node);
		}
	}

	protected function collect_use_statements(Program $program, IDeclaration $declaration)
	{
		$unit_header_program = $program->unit->programs['__package'] ?? null;
		if ($unit_header_program) {
			// the non targets use statements used for namespace search
			foreach ($unit_header_program->uses as $use_statement) {
				if (!$use_statement->targets) {
					$program->uses[$use_statement->ns->uri . '!'] = $use_statement;
				}
			}
		}

		foreach ($declaration->uses as $use) {
			$uri = $use->ns->uri;
			if (!isset($program->uses[$uri])) {
				$program->uses[$uri] = new UseStatement($use->ns);
			}

			$program->uses[$uri]->append_target($use);
		}
	}

	protected function render_program_statements(Program $program)
	{
		$this->process_use_statments($program);

		$items = parent::render_program_statements($program);

		$uri = ltrim($program->unit->ns->uri, static::NS_SEPARATOR);
		$unit_declaration = _NAMESPACE . " {$uri}\n";
		array_unshift($items, $unit_declaration);

		return $items;
	}

	public function render_class_constant_declaration(ClassConstantDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		$code = $this->generate_class_constant_header($node);

		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

	public function render_constant_declaration(IConstantDeclaration $node)
	{
		if ($node->modifier === _INTERNAL) {
			return null;
		}

		$code = $this->generate_constant_header($node);

		return $code . static::STATEMENT_TERMINATOR;
	}

	public function render_property_declaration(PropertyDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		$code = $this->generate_property_header($node);
		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

	public function render_method_declaration(MethodDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		return $this->generate_function_header($node);
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		return $this->generate_function_header($node);
	}

	public function render_masked_declaration(MaskedDeclaration $node)
	{
		$header = _MASKED . " {$node->name}";
		$type = $this->render_type_expr_for_decl($node);

		if ($node->parameters === null && $node->callbacks === null) {
			return "{$header} {$type}";
		}
		else {
			$parameters = $node->parameters ? $this->render_parameters($node->parameters) : '';
			$callbacks = $node->callbacks ? $this->render_callback_protocols($node->callbacks) : '';

			return "{$header}($parameters) {$type}{$callbacks}";
		}
	}

	protected function render_type_expr_for_decl(IDeclaration $node)
	{
		$type = $node->declared_type ?? $node->infered_type;
		$buffer = $type instanceof VoidType
			? null
			: $type->render($this);

		if ($node->noted_type !== null) {
			$buffer .= '#' . $node->noted_type->render($this);
		}

		// if ($node->declared_type === null) {
		// 	$buffer = '#' . $buffer;
		// }

		return $buffer;
	}
}

// end
