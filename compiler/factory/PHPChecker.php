<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPChecker extends ASTChecker
{
	protected function infer_plain_identifier(PlainIdentifier $node): IType
	{
		if (!$node->symbol) {
			$this->attach_symbol($node);
			if (!$node->symbol) {
				return TypeFactory::$_any;
			}
		}

		$declaration = $node->symbol->declaration;

		if ($declaration instanceof VariableDeclaration || $declaration instanceof ConstantDeclaration || $declaration instanceof ParameterDeclaration || $declaration instanceof ClassKindredDeclaration) {
			if (!$declaration->type) {
				throw $this->new_syntax_error("Declaration of '{$node->name}' not found.", $node);
			}

			$type = $declaration->type;
		}
		elseif ($declaration instanceof ICallableDeclaration) {
			$type = TypeFactory::$_callable;
		}
		else {
			throw new UnexpectNode($declaration);
		}

		return $type;
	}

	protected function attach_symbol(PlainIdentifier $node)
	{
		$symbol = $this->find_symbol_by_name($node->name, $node);
		if ($symbol === null) {
			return null;
		}

		$node->symbol = $symbol;
		return $symbol;
	}

	protected function get_source_declaration_for_use(UseDeclaration $use): ?IRootDeclaration
	{
		if ($use->source_declaration === null) {
			$unit = $this->get_uses_unit_declaration($use->ns);
			if ($unit === null) {
				return null;
			}

			$this->attach_source_declaration_for_use($use, $unit);
		}

		return $use->source_declaration;
	}
}

// end
