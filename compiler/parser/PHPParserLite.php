<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

/**
 * A lite Parser uses to supported the Mixed Programming
 */
class PHPParserLite extends BaseParser
{
	const TYPE_MAP = [
		'void' => _VOID,
		'string' => _STRING,
		'int' => _INT,
		'float' => _FLOAT,
		'bool' => _BOOL,
		'array' => _DICT,
		'iterable' => _ITERABLE,
		'callable' => _CALLABLE,
	];

	const METHOD_MAP = [
		'__construct' => _CONSTRUCT,
		'__destruct' => _DESTRUCT,
		'__toString' => 'to_string',
	];

	/**
	 * @var NamespaceIdentifier
	 */
	private $namespace;

	public function read_program(): Program
	{
		// while ($this->pos + 1 < $this->tokens_count) {
		// 	$this->pos++;
		// 	$this->print_token();
		// }
		// exit;

		$this->program->is_native = true;

		while ($this->pos + 1 < $this->tokens_count) {
			$item = $this->read_root_statement();
			if ($item instanceof IRootDeclaration) {
				$this->program->append_declaration($item);
			}
		}

		$this->factory->end_program();

		$this->program->main_function = null;

		return $this->program;
	}

	protected function tokenize(string $source)
	{
		$this->tokens = token_get_all($source);
		$this->tokens_count = count($this->tokens);
	}

	private function read_root_statement()
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null || is_string($token)) {
			return null;
		}

		$doc = null;
		if ($token[0] === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->scan_typed_token_ignore_empty();
		}

		switch ($token[0]) {
			case T_CLASS:
				$node = $this->read_class_declaration();
				break;

			case T_ABSTRACT:
				$this->expect_typed_token(T_CLASS);
				$node = $this->read_class_declaration(true);
				break;

			case T_INTERFACE:
				$node = $this->read_interface_declaration();
				break;

			case T_FUNCTION:
				$node = $this->read_function_declaration($doc);
				break;

			case T_CONST:
				$node = $this->read_constant_declaration($doc);

				// for 'const A, B, C;' style
				while ($this->skip_char_token(_COMMA)) {
					$this->program->append_declaration($node);
					$node = $this->read_constant_declaration();
				}

				break;

			case T_NAMESPACE:
				$this->program->ns = $this->namespace = $this->read_namespace();
				$node = null;
				break;

			case T_USE:
				$this->skip_to_char_token(_SEMICOLON);
				// unbreak
			// we do not care the others
			default:
				$node = null;
		}

		return $node;
	}

	const EXPRESSION_ENDINGS = [null, _PAREN_CLOSE, _BRACKET_CLOSE, _BLOCK_END, _SEMICOLON];

	private function read_expression()
	{
		// we do not care about the contents, just skip ...

		while (($token = $this->scan_token_ignore_empty()) !== null) {
			if (is_string($token)) {
				if (in_array($token, static::EXPRESSION_ENDINGS, true)) {
					$this->pos--;
					$expr = null;
					break;
				}

				switch ($token) {
					case _PAREN_OPEN:
						$this->read_expression();
						$this->expect_char_token(_PAREN_CLOSE);
						break;

					case _BRACKET_OPEN:
						$expr = $this->read_bracket_expression();
						break;
				}
			}
		}

		return $expr;
	}

	private function read_bracket_expression(bool $not_opened = false)
	{
		$not_opened && $this->expect_char_token(_BRACKET_OPEN);

		$is_dict = false;
		while ($expr = $this->read_expression()) {
			if ($this->skip_char_token('=>')) {
				$is_dict = true;
			}
		}

		$this->expect_char_token(_BRACKET_CLOSE);

		return $is_dict ? new DictExpression([]) : new ArrayExpression([]);
	}

	private function read_interface_declaration()
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_interface_declaration($name, _PUBLIC);
		$declaration->ns = $this->namespace;
		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->baseds = $this->expect_identifier_name();
		}

		$this->expect_block_begin_inline();

		while ($this->read_interface_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
	}

	private function read_class_declaration(bool $is_abstract = false)
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_class_declaration($name, _PUBLIC);
		$declaration->ns = $this->namespace;
		$declaration->is_abstract = $is_abstract;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->inherits = $this->read_classlike_identifier();
		}

		if ($this->skip_typed_token(T_IMPLEMENTS)) {
			do {
				$implements[] = $this->read_classlike_identifier();
			}
			while ($this->skip_char_token(_COMMA));

			$declaration->baseds = $implements;
		}

		$this->expect_block_begin_inline();

		$use_traits = [];
		if ($this->skip_typed_token(T_USE)) {
			do {
				$use_traits[] = $this->read_classlike_identifier();
			}
			while ($this->skip_char_token(_COMMA));

			$this->expect_statement_end();
		}

		while ($this->read_class_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
	}

	private function read_interface_member()
	{
		$token = $this->get_token_ignore_empty();
		if (is_string($token)) {
			if ($token === _BLOCK_END) {
				return null;
			}

			$this->scan_token_ignore_empty();
			throw $this->new_unexpected_error();
		}

		$this->scan_token_ignore_empty();

		$doc = null;
		if ($token[0] === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->scan_typed_token_ignore_empty();
		}

		$modifier = null;
		if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
			$modifier = $token[1];
			$token = $this->scan_typed_token_ignore_empty();
		}

		$is_static = false;
		if ($token[0] === T_STATIC) {
			$is_static = true;
			$token = $this->scan_typed_token_ignore_empty();
		}

		switch ($token[0]) {
			case T_CONST:
				$declaration = $this->read_class_constant_declaration($modifier, $doc);
				break;
			case T_FUNCTION:
				$declaration = $this->read_method_declaration($modifier, $doc, true);
				break;

			default:
				throw $this->new_unexpected_error();
		}

		$declaration->is_static = $is_static;

		return $declaration;
	}

	private function read_class_member()
	{
		$token = $this->get_token_ignore_empty();
		if (is_string($token)) {
			if ($token === _BLOCK_END) {
				return null;
			}

			$this->scan_token_ignore_empty();
			throw $this->new_unexpected_error();
		}

		$this->scan_token_ignore_empty();

		$doc = null;
		if ($token[0] === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->scan_typed_token_ignore_empty();
		}

		$modifier = null;
		if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
			$modifier = $token[1];
			$token = $this->scan_typed_token_ignore_empty();
		}

		$is_static = false;
		if ($token[0] === T_STATIC) {
			$is_static = true;
			$token = $this->scan_typed_token_ignore_empty();
		}

		switch ($token[0]) {
			case T_VAR:
				$token = $this->scan_typed_token_ignore_empty();
				// unbreak
			case T_VARIABLE:
			case T_STRING: // maybe the type hint
				$declaration = $this->read_property_declaration($token, $modifier, $doc);
				break;

			case T_CONST:
				$declaration = $this->read_class_constant_declaration($modifier, $doc);
				break;

			case T_FUNCTION:
				$declaration = $this->read_method_declaration($modifier, $doc);
				break;

			default:
				$this->print_token($token);
				throw $this->new_unexpected_error();
		}

		$declaration->is_static = $is_static;

		return $declaration;
	}

	private function read_constant_declaration(?string $doc)
	{
		$name = $this->expect_identifier_name();
		$declaration = $this->factory->create_constant_declaration(_PUBLIC, $name);

		$this->expect_char_token(_ASSIGN);
		$declaration->type = $this->read_value_type_skip($doc);
		$this->expect_statement_end();

		return $declaration;
	}

	private function read_class_constant_declaration(?string $modifier, ?string $doc)
	{
		$name = $this->expect_identifier_name();
		$declaration = $this->factory->create_class_constant_declaration($modifier, $name);

		$this->expect_char_token(_ASSIGN);
		$declaration->type = $this->read_value_type_skip($doc);
		$this->expect_statement_end();

		return $declaration;
	}

	private function read_value_type_skip(?string $doc, string $doc_kind = 'const')
	{
		$temp_pos = $this->pos;

		$type = $this->try_detatch_assign_value_type_and_skip();
		if ($type === null) {
			$doc && $type = $this->get_type_in_doc($doc, $doc_kind);
			if ($type === null) {
				$this->pos = $temp_pos;
				throw $this->new_parse_error("Required a type hint in the declaration docs.");
			}
		}

		return $type;
	}

	private function get_type_in_doc(?string $doc, string $kind)
	{
		// /**
		//  * @var int
		//  */

		if (preg_match('/\s+\*\s+@' . $kind . '\s+([^\s]+)/', $doc, $match)) {
			return $this->create_type_identifier($match[1]);
		}

		return null;
	}

	private function try_detatch_assign_value_type_and_skip()
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === _BRACKET_OPEN) {
			$expr = $this->read_bracket_expression();
			if ($this->skip_char_token(_SEMICOLON)) {
				$this->pos--; // back to ;
				return $expr instanceof ArrayExpression
					? TypeFactory::$_array
					: TypeFactory::$_dict;
			}
		}

		switch ($token[0]) {
			case T_STRING:
				$lower_case = strtolower($token[1]);
				if ($lower_case === _TRUE || $lower_case === _FALSE) {
					$type = TypeFactory::$_string;
				}
				else {
					// may be a user defined constant
					$type = null;
				}

				break;

			case T_LNUMBER:
			case T_LINE: // __LINE__
				$type = TypeFactory::$_int;
				break;

			case T_DNUMBER:
				$type = TypeFactory::$_float;
				break;

			case T_CONSTANT_ENCAPSED_STRING:
			case T_DIR: // __DIR__
			case T_FILE: // __FILE__
			case T_CLASS_C: // __CLASS__
			case T_NS_C: // __NAMESPACE__
				$type = TypeFactory::$_string;
				break;

			default:
				// $this->print_token($token);
				throw $this->new_unexpected_error();
		}

		$next = $this->get_token_ignore_empty();
		if ($next !== _SEMICOLON) {
			$type = $next === _DOT ? TypeFactory::$_string : null;
			$this->skip_to_char_token(_SEMICOLON);
			$this->pos--; // back to ;
		}

		return $type;
	}

	private function read_property_declaration(array $token, string $modifier, ?string $doc)
	{
		$type_name = null;
		if ($token[0] === T_STRING) {
			$type_name = $token[1];

			// scan the next token
			$token = $this->scan_typed_token_ignore_empty();
		}

		$name = ltrim($token[1], '$');
		$declaration = $this->factory->create_property_declaration($modifier, $name);

		if ($type_name === null) {
			$type = $this->get_type_in_doc($doc, 'var');
		}
		else {
			$type = $this->create_type_identifier($type_name);
		}

		if ($this->skip_char_token(_ASSIGN)) {
			if ($type) {
				$this->skip_to_char_token(_SEMICOLON);
				$this->pos--;
			}
			else {
				$type = $this->read_value_type_skip($doc);
			}
		}
		elseif ($type === null) {
			throw $this->new_parse_error("Property '{$token[1]}' required a type hint '@var string/int/float/bool/...' in it's docs.");
		}

		$this->expect_statement_end();
		$declaration->type = $type;

		return $declaration;
	}

	private function read_method_declaration(string $modifier = null, ?string $doc, bool $is_interface = false)
	{
		$name = $this->expect_identifier_name();
		if (isset(static::METHOD_MAP[$name])) {
			$name = static::METHOD_MAP[$name];
		}

		$declaration = $this->factory->create_method_declaration($modifier ?? _PUBLIC, $name);

		$parameters = $this->read_parameters();
		$this->factory->set_enclosing_parameters($parameters);

		$declaration->type = $this->try_read_function_return_type();

		if ($is_interface) {
			$this->expect_statement_end();
		}
		else {
			$this->read_function_block();
		}

		return $declaration;
	}

	private function read_function_declaration(?string $doc)
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_function_declaration(_PUBLIC, $name);

		$parameters = $this->read_parameters();
		$return_type = $this->try_read_function_return_type();

		$this->factory->set_enclosing_parameters($parameters);
		$declaration->type = $return_type;

		$declaration->ns = $this->namespace;
		$this->read_function_block();

		return $declaration;
	}

	private function try_read_function_return_type()
	{
		$type = null;
		if ($this->skip_char_token(_COLON)) {
			$type = $this->read_type_identifier();
		}

		return $type;
	}

	private function read_parameters()
	{
		$this->expect_char_token(_PAREN_OPEN);

		$items = [];
		while (($token = $this->get_token_ignore_empty()) !== null) {
			if ($token === _PAREN_CLOSE) {
				break;
			}

			$this->scan_token_ignore_empty();

			if ($token[0] === T_STRING) {
				$type = $this->create_type_identifier($token[1]);
				$token = $this->scan_token_ignore_empty();
			}
			else {
				$type = TypeFactory::$_any;
			}

			if ($token[0] !== T_VARIABLE) {
				throw $this->new_unexpected_error();
			}

			if ($this->skip_char_token(_ASSIGN)) {
				$value = $this->read_expression();
			}

			$name = ltrim($token[1], '$');
			$items[] = new ParameterDeclaration($name, $type);

			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		$this->expect_char_token(_PAREN_CLOSE);

		return $items;
	}

	private function read_function_block()
	{
		$this->read_block();
		$this->factory->end_block();
	}

	private function read_block()
	{
		$this->expect_block_begin_inline();

		// we don't care the contents
		while (($token = $this->get_token_ignore_empty()) !== null) {
			if ($token === _BLOCK_BEGIN) {
				$this->read_block();
			}
			elseif ($token === _BLOCK_END) {
				break;
			}
			else {
				$this->scan_token_ignore_empty();
			}
		}

		$this->expect_block_end();
	}

	private function read_classlike_identifier()
	{
		// \NS1\NS2\Name
		// NS1\NS2\Name

		$names = [];

		$token = $this->scan_token_ignore_empty();

		if ($token[0] === T_NS_SEPARATOR) {
			$names[] = _NOTHING;
			$names[] = $this->expect_identifier_name();
		}
		else {
			$this->assert_identifier_token($token);
			$names[] = $token[1];
		}

		while (($next = $this->get_token()) && $next[0] === T_NS_SEPARATOR) {
			$this->scan_token();
			$names[] = $this->expect_identifier_name();
		}

		$name = array_pop($names);
		$identifier = new ClassLikeIdentifier($name);

		if ($names) {
			$identifier->ns = new NamespaceIdentifier($names);
		}

		return $identifier;
	}

	private function read_type_identifier()
	{
		$name = $this->expect_identifier_name();
		return $this->create_type_identifier($name);
	}

	private function create_type_identifier(string $name)
	{
		$lower_case_name = strtolower($name);
		if (isset(static::TYPE_MAP[$lower_case_name])) {
			$name = static::TYPE_MAP[$lower_case_name];
			$identifier = TypeFactory::get_type($name);
		}
		else {
			$identifier = new ClassLikeIdentifier($name);
		}

		return $identifier;
	}

	private function read_namespace()
	{
		$names[] = $this->expect_identifier_name();

		while ($next = $this->scan_token()) {
			if ($next === _SEMICOLON) {
				break;
			}
			elseif ($next[0] === T_NS_SEPARATOR) {
				$names[] = $this->expect_identifier_name();
			}
			else {
				throw $this->new_unexpected_error();
			}
		}

		return new NamespaceIdentifier($names);
	}

	protected function expect_statement_end()
	{
		return $this->expect_char_token(_SEMICOLON);
	}

	protected function expect_block_begin_inline()
	{
		return $this->expect_char_token(_BLOCK_BEGIN);
	}

	protected function expect_block_end()
	{
		return $this->expect_char_token(_BLOCK_END);
	}

	private function expect_char_token(string $char)
	{
		$token = $this->scan_token_ignore_empty();
		if ($token !== $char) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function expect_typed_token(int $type)
	{
		$token = $this->scan_token_ignore_empty();
		if (!is_array($token) || $token[0] !== $type) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function expect_identifier_name()
	{
		$token = $this->scan_token_ignore_empty();
		$this->assert_identifier_token($token);
		return $token[1];
	}

	private function skip_to_char_token(string $char)
	{
		while (($token = $this->scan_token()) !== null) {
			if ($token === $char) {
				return;
			}
		}

		throw $this->new_parse_error("Expected token \"$char\".");
	}

	private function skip_char_token(string $char)
	{
		$token = $this->get_token_ignore_empty();
		if ($token === $char) {
			$this->scan_token_ignore_empty();
			return true;
		}

		return false;
	}

	private function skip_typed_token(int $type)
	{
		$token = $this->get_token_ignore_empty();
		if (is_array($token) && $token[0] === $type) {
			$this->scan_token_ignore_empty();
			return true;
		}

		return false;
	}

	private function assert_identifier_token($token)
	{
		if (is_string($token) || $token[0] !== T_STRING) {
			throw $this->new_unexpected_error();
		}
	}

	protected function get_current_token_string()
	{
		$token = $this->tokens[$this->pos] ?? null;
		return is_array($token) ? $token[1] : $token;
	}

	private function scan_typed_token_ignore_empty()
	{
		$token = $this->scan_token_ignore_empty();
		if (!is_array($token)) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function scan_token_ignore_empty()
	{
		do {
			$this->pos++;
			$token = $this->tokens[$this->pos] ?? null;
			if ($token !== _SPACE && (!is_array($token) || $token[0] !== T_WHITESPACE)) {
				break;
			}
		} while ($token !== null);

		return $token;
	}

	private function scan_token()
	{
		$this->pos++;
		return $this->tokens[$this->pos] ?? null;
	}

	private function get_token_ignore_empty()
	{
		$pos = $this->pos;

		do {
			$pos++;
			$token = $this->tokens[$pos] ?? null;
			if ($token !== _SPACE && (!is_array($token) || $token[0] !== T_WHITESPACE)) {
				break;
			}
		} while ($token !== null);

		return $token;
	}

	private function get_token()
	{
		return $this->tokens[$this->pos + 1] ?? null;
	}

	protected function get_to_line_end(int $from = null)
	{
		$i = $from ?? $this->pos + 1;

		$tmp = '';
		while ($i < $this->tokens_count) {
			$token = $this->tokens[$i];
			$i++;

			if ($token === LF) {
				break;
			}

			$tmp .= is_string($token) ? $token : $token[1];
		}

		return $tmp;
	}

	protected function get_line_number(int $pos): int
	{
		if ($pos >= $this->tokens_count) {
			$pos = $this->tokens_count - 1;
		}

		while ($pos < $this->tokens_count) {
			if (is_array($this->tokens[$pos]) || $pos <= 0) {
				break;
			}
			else {
				$pos--;
			}
		}

		return $this->tokens[$pos][2];
	}

	protected function get_previous_code_inline(int $pos): string
	{
		$code = '';
		$temp_line = null;

		while (isset($this->tokens[$pos])) {
			$token = $this->tokens[$pos];
			if (is_array($token)) {
				if ($temp_line !== null && $temp_line !== $token[2]) {
					break;
				}

				$code = $token[1] . $code;
				$temp_line = $token[2];
			}
			else {
				$code = $token . $code;
			}

			$pos--;
		}

		return $code;
	}

	private function print_token($token = null)
	{
		$token === null && $token = $this->tokens[$this->pos];

		if (is_string($token)) {
			echo $token, LF;
		}
		else {
			if (!isset($token[1])) {
				dump($token);exit;
			}

			echo token_name($token[0]), " '$token[1]'\n";
		}
	}
}

// end
