<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait TeaSharpTrait
{
	protected $is_in_tea_declaration = false;

	protected function read_label_statement()
	{
		$token = $this->scan_token();
		switch ($token) {
			case _TEA:
				$node = $this->read_tea_declaration();
				break;

			case _PHP:
				$node = $this->read_php_declaration();
				break;

			case _USE:
				return $this->read_use_statement();

			case _UNIT:
				$this->read_unit_declaration();
				return null;

			case _EXPECT:
				return $this->read_expect_declaration();

			case _MAIN:
				$this->factory->set_as_main_program();
				return;

			case _CO:
				return $this->read_coroutine();

			default:
				$node = $this->read_other_label_statement_with($token);
		}

		$node->label = $token;
		return $node;
	}

	protected function read_coroutine()
	{
		$block = $this->factory->create_coroutine_block();
		$block->pos = $this->pos;

		if ($this->get_token_ignore_empty() === _BLOCK_BEGIN) {
			$this->read_block_body($block);
		}
		else {
			$statement = $this->read_normal_statement();
			$block->set_body_with_statements($statement);
			$this->factory->end_block();
		}

		return $block;
	}

	protected function read_tea_declaration()
	{
		$name = $this->scan_token_ignore_space();

		// use to allow extends with a builtin type class
		$this->is_in_tea_declaration = true;

		$declaration = $this->factory->create_builtin_type_class_declaration($name);
		if ($declaration === null) {
			throw $this->new_parse_error("'$name' not a builtin type, cannot use the #tea label.");
		}

		$this->read_rest_for_classkindred_declaration($declaration);

		$this->is_in_tea_declaration = false;

		return $declaration;
	}

	protected function read_php_declaration()
	{
		$name = $this->scan_token_ignore_space();

		$this->assert_not_reserveds_word($name);

		if (TeaHelper::is_constant_name($name)) {
			$next = $this->get_token_ignore_space();
			if ($next !== _BLOCK_BEGIN && $next !== _COLON && $next !== _AS) {
				return $this->read_constant_declaration_without_value($name, _PUBLIC, $this->factory->root_namespace, true);
			}
		}

		if (TeaHelper::is_classkindred_name($name)) {
			// the alias feature
			if ($this->skip_token_ignore_space(_AS)) {
				$origin_name = $name;
				$name = $this->scan_token_ignore_space();
				if (!TeaHelper::is_classkindred_name($name)) {
					throw $this->new_parse_error("Invalid class/interface name.");
				}
			}

			if (TeaHelper::is_interface_marked_name($name)) {
				$declaration = $this->read_interface_declaration($name, _PUBLIC, $this->factory->root_namespace, true);
			}
			else {
				$declaration = $this->read_class_declaration($name, _PUBLIC, $this->factory->root_namespace, true);
			}

			if (isset($origin_name)) {
				$declaration->origin_name = $origin_name;
			}
		}
		elseif (TeaHelper::is_strict_less_function_name($name)) {
			$declaration = $this->read_function_declaration($name, _PUBLIC, $this->factory->root_namespace, true);
		}
		// elseif ($name === _DOLLAR) {
		// 	$name = $this->expect_identifier_token();
		// 	$type = $this->try_read_type_identifier();
		// 	if (!$type) {
		// 		throw $this->new_parse_error("Expected type for declared super variable '$name'.");
		// 	}

		// 	$declaration = $this->factory->create_super_variable_declaration($name, $type, null);
		// }
		else {
			throw $this->new_unexpected_error();
		}

		return $declaration;
	}

	protected function read_expect_declaration()
	{
		// #expect var0 Type0, var1 Type1, ...

		$items[] = $this->read_expect_parameter();

		while ($this->skip_comma()) {
			$items[] = $this->read_expect_parameter();
		}

		return $this->factory->create_program_expection(...$items);
	}

	protected function read_expect_parameter()
	{
		$name = $this->scan_token_ignore_space();

		$type = null;
		$value = null;

		if (!TeaHelper::is_declarable_variable_name($name) && $name !== _THIS) {
			throw $this->new_unexpected_error();
		}

		$next = $this->get_token_ignore_empty();
		if (TeaHelper::is_type_name($next)) {
			$type = $this->try_read_type_identifier();
		}

		$parameter = new ParameterDeclaration($name, $type, $value);
		$parameter->pos = $this->pos;

		return $parameter;
	}

	private function read_other_label_statement_with(string $label)
	{
		// normal statement
		$expression = $this->read_sharp_expression_with($label);
		if ($expression !== null) {
			$expression = $this->read_expression_combination($expression);
			return new NormalStatement($expression);
		}

		if (TeaHelper::is_reserveds($label)) {
			throw $this->new_parse_error("Cannot use a reserveds keyword '$label' as a label name.");
		}

		// labeled block
		$next = $this->scan_token_ignore_space();
		if ($next === _FOR) {
			$block = $this->read_for_block($label);
		}
		elseif ($next === _WHILE) {
			$block = $this->read_while_block($label);
		}
		// elseif ($next === _LOOP) {
		// 	$block = $this->read_loop_block($label);
		// }
		elseif ($next === _SWITCH) {
			$block = $this->read_switch_block($label);
		}
		else {
			throw $this->new_parse_error("Expected a inline statement after label #{$label}.");
		}

		return $block;
	}

	protected function read_unit_declaration()
	{
		if (!$this->is_parsing_header) {
			throw $this->new_parse_error("The '#unit' label could not be used at here.");
		}

		$ns = $this->read_namespace_identifier();
		$this->factory->set_namespace($ns);

		if ($this->skip_token_ignore_space(_BRACE_OPEN)) {
			$this->read_unit_options();
			$this->expect_token_ignore_empty(_BRACE_CLOSE);
		}
	}

	private function read_unit_options()
	{
		// try read options for unit
		while ($key = $this->read_object_key()) {
			if (!in_array($key, _UNIT_OPTIONAL_KEYS, true)) {
				throw $this->new_parse_error("Invalid option key '$key' for unit definition.");
			}

			$this->expect_token_ignore_space(_COLON);

			$expr = $this->read_expression();
			if ($expr === null || !$expr instanceof ILiteral) {
				throw $this->new_unexpected_error();
			}

			// set to unit
			$this->factory->set_unit_option($key, $expr->value);

			if (!$this->skip_comma()) {
				break;
			}
		}
	}

	protected function read_namespace_identifier()
	{
		$domain = $this->read_domain_name();

		$names = [];
		while ($this->skip_token(_SLASH)) {
			$token = $this->scan_token();
			if (!TeaHelper::is_subnamespace_name($token)) {
				throw $this->new_parse_error("Invalid subnamespace name.");
			}

			$names[] = $token;
		}

		if (empty($names) && !TeaHelper::is_subnamespace_name($domain)) {
			throw $this->new_parse_error("Invalid namespace name.");
		}

		array_unshift($names, $domain);
		if (count($names) > _NS_LEVELS_MAX) {
			throw $this->new_parse_error(sprintf("It's too many namespace levels, the max levels is %d.", _NS_LEVELS_MAX));
		}

		$ns = $this->factory->create_namespace_identifier($names, true);
		$ns->pos = $this->pos;

		return $ns;
	}

	private function read_domain_name(): ?string
	{
		$token = $this->scan_token_ignore_space();
		if (!TeaHelper::is_domain_component($token)) {
			throw $this->new_unexpected_error();
		}

		$components[] = $token;
		while ($this->skip_token(_DOT)) {
			$token = $this->scan_token();
			if (!TeaHelper::is_domain_component($token)) {
				throw $this->new_unexpected_error();
			}

			$components[] = $token;
		}

		return join(_DOT, $components);
	}

	private function read_use_statement()
	{
		if (!$this->is_parsing_header) {
			throw $this->new_parse_error("The '#use' statements can only be used in the __unit.th or __public.th files.");
		}

		$ns = $this->read_namespace_identifier();

		if ($this->skip_token_ignore_space(_BLOCK_BEGIN)) {
			$targets = $this->read_use_targets($ns);
			$this->expect_block_end();
		}
		else {
			// throw $this->new_parse_error("Expected the use targets {...}.");
			$targets = [];
		}

		$this->factory->create_use_statement($ns, $targets);
	}

	private function read_use_targets(NamespaceIdentifier $ns): array
	{
		$targets = [];
		while ($token = $this->scan_token_ignore_empty()) {
			if (!TeaHelper::is_identifier_name($token)) {
				break;
			}

			if ($this->skip_token_ignore_space(_AS)) {
				$alias = $this->scan_token_ignore_empty();
				if (!TeaHelper::is_identifier_name($alias)) {
					throw $this->new_parse_error("Invalid name format token '$alias' for the use statement.");
				}

				$target = $this->factory->append_use_target($ns, $alias, $token);
			}
			else {
				$target = $this->factory->append_use_target($ns, $token);
			}

			$target->pos = $this->pos;
			$targets[] = $target;

			if (!$this->skip_comma()) {
				break;
			}
		}

		return $targets;
	}

	protected function read_sharp_expression_with(string $token)
	{
		switch ($token) {
			case _DEFAULT:
				return ASTFactory::$default_value_marker;

			case _INCLUDE:
				return $this->read_include_expression();

			// case _SINGLE_QUOTE:
			// 	$expression = $this->read_single_quoted_expression();
			// 	return new HTMLEscapeExpression($expression);

			// case _DOUBLE_QUOTE:
			// 	$expression = $this->read_double_quoted_expression();
			// 	return new HTMLEscapeExpression($expression);

			// case _PAREN_OPEN:
			// 	$expression = $this->read_expression();
			// 	$this->expect_token_ignore_empty(_PAREN_CLOSE);
			// 	return new HTMLEscapeExpression($expression);

			case _TEXT:
				return $this->read_string_literal();

			default:
				return null;
		}
	}

	protected function read_include_expression()
	{
		$this->expect_token(_PAREN_OPEN);
		$this->expect_token(_SINGLE_QUOTE);

		$name = '';
		while (($token = $this->get_token()) !== null) {
			if (TeaHelper::is_identifier_name($token) || $token === _SLASH || $token === _STRIKETHROUGH) {
				$this->scan_token();
				$name .= $token;
			}
			else {
				break;
			}
		}

		$this->expect_token(_SINGLE_QUOTE);
		$this->expect_token(_PAREN_CLOSE);

		return $this->factory->create_include_expression($name);
	}

	private function read_string_literal()
	{
		$token = $this->scan_token_ignore_space();

		switch ($token) {
			case _SINGLE_QUOTE:
				$expression = $this->read_unescaped_string_literal();
				break;

			case _DOUBLE_QUOTE:
				$expression = $this->read_escaped_string_literal();
				break;

			default:
				throw $this->new_unexpected_error();
		}

		return $expression;
	}
}

// end
