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

	protected function render_program_statements(Program $program)
	{
		$items = parent::render_program_statements($program);

		$unit_declaration = "#unit {$program->unit->ns->uri}\n";
		array_unshift($items, $unit_declaration);

		return $items;
	}

	public function render_property_declaration(PropertyDeclaration $node)
	{
		$code = $this->generate_property_header($node);

		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
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
