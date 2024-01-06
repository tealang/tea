<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaHeaderCoder extends TeaCoder
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

		$uri = ltrim($program->unit->ns->uri, _SLASH);
		$unit_declaration = "#unit {$uri}\n";
		array_unshift($items, $unit_declaration);

		return $items;
	}

	public function render_class_constant_declaration(ClassConstantDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		return parent::render_class_constant_declaration($node);
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

		return $this->render_function_protocol($node);
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		return $this->render_function_protocol($node);
	}

	public function render_masked_declaration(MaskedDeclaration $node)
	{
		$header = _MASKED . " {$node->name}";
		$type = $this->generate_declaration_type($node);

		if ($node->parameters === null && $node->callbacks === null) {
			return "{$header}{$type}";
		}
		else {
			$parameters = $node->parameters ? $this->render_parameters($node->parameters) : '';
			$callbacks = $node->callbacks ? $this->render_callback_protocols($node->callbacks) : '';

			return "{$header}($parameters){$type}{$callbacks}";
		}
	}
}

// end
