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
	const NS_SEPARATOR = PHPParser::NS_SEPARATOR;

	protected $is_weakly_checking = true;

	// protected function infer_plain_identifier(PlainIdentifier $node): IType
	// {
	// 	if (!$node->symbol) {
	// 		$this->attach_symbol($node);
	// 		if (!$node->symbol) {
	// 			return TypeFactory::$_any;
	// 		}
	// 	}

	// 	$declaration = $node->symbol->declaration;

	// 	if ($declaration instanceof VariableDeclaration || $declaration instanceof ConstantDeclaration || $declaration instanceof ParameterDeclaration || $declaration instanceof ClassKindredDeclaration) {
	// 		if (!$declaration->declared_type) {
	// 			throw $this->new_syntax_error("Declaration of '{$node->name}' not found.", $node);
	// 		}

	// 		$type = $declaration->declared_type;
	// 	}
	// 	elseif ($declaration instanceof ICallableDeclaration) {
	// 		$type = TypeFactory::$_callable;
	// 	}
	// 	else {
	// 		throw $this->new_syntax_error('Undexpected declaration for identifier', $node);
	// 	}

	// 	return $type;
	// }

	// protected function attach_symbol(PlainIdentifier $identifier)
	// {
	// 	$symbol = $this->find_plain_symbol_and_check_declaration($identifier);
	// 	if ($symbol === null) {
	// 		return null;
	// 	}

	// 	$identifier->symbol = $symbol;
	// 	return $symbol;
	// }

	// protected function check_use_target(UseDeclaration $node)
	// {
	// 	$unit = $this->get_uses_unit_declaration($node->ns);
	// 	if ($unit !== null) {
	// 		$this->attach_source_declaration_for_use($node, $unit);
	// 	}
	// }

	// protected function assert_member_declarations(IClassMemberDeclaration $node, IClassMemberDeclaration $super, bool $is_interface = false)
	// {
	// 	//
	// }
}

// end
