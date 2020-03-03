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
 * A lite Parser uses to generate __public.th file for PHP programs
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

	public function read_program(): Program
	{
		// while ($this->pos + 1 < $this->tokens_count) {
		// 	$this->pos++;
		// 	$this->print_token();
		// }
		// exit;

		$this->program->is_dynamic = true;

		while ($this->pos + 1 < $this->tokens_count) {
			$item = $this->read_root_statement();
			if ($item instanceof IRootDeclaration) {
				$this->program->append_declaration($item);
			}
		}

		// end the main function
		$this->factory->end_block();

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
				break;

			case T_NAMESPACE:
				$node = $this->read_namespace();
				break;

			// we do not care the others
			default:
				$node = null;
		}

		return $node;
	}

	private function read_interface_declaration()
	{
		$name = $this->expect_identifier_token();

		$declaration = $this->factory->create_interface_declaration($name, null);
		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->baseds = $this->expect_identifier_token();
		}

		$this->expect_block_begin();

		while ($this->read_interface_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
	}

	private function read_class_declaration(bool $is_abstract = false)
	{
		$name = $this->expect_identifier_token();


		$declaration = $this->factory->create_class_declaration($name, null);
		$declaration->is_abstract = $is_abstract;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->inherits = $this->expect_identifier_token();
		}

		if ($this->skip_typed_token(T_IMPLEMENTS)) {
			do {
				$implements[] = $this->expect_identifier_token();
			}
			while ($this->skip_char_token(_COMMA));

			$declaration->baseds = $implements;
		}

		$this->expect_block_begin();

		$use_traits = [];
		if ($this->skip_typed_token(T_USE)) {
			do {
				$use_traits[] = $this->expect_identifier_token();
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
			throw $this->new_parse_error();
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
			throw $this->new_parse_error();
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
		$name = $this->expect_identifier_token();
		$this->expect_char_token(_ASSIGN);
		$type = $this->read_constant_type($doc);
		$this->expect_statement_end();

		return $this->factory->create_constant_declaration(null, $name, $type, null);
	}

	private function read_class_constant_declaration(?string $modifier, ?string $doc)
	{
		$name = $this->expect_identifier_token();
		$this->expect_char_token(_ASSIGN);
		$type = $this->read_constant_type($doc);
		$this->expect_statement_end();

		return $this->factory->create_class_constant_declaration($modifier, $name, $type, null);
	}

	private function read_constant_type(?string $doc)
	{
		$temp_pos = $this->pos;

		$type = $this->try_detatch_type_and_skip_value();
		if ($type === null) {
			$doc && $type = $this->get_type_in_doc($doc, 'const');
			if ($type === null) {
				$this->pos = $temp_pos;
				throw $this->new_parse_error("Constant required a type hint in it's doc.");
			}
		}

		return $type;
	}

	private function get_type_in_doc(string $doc, string $kind)
	{
		// /**
		//  * @var int
		//  */

		if (preg_match('/\n\s+\*\s@' . $kind . '\s+([^\s]+)/', $doc, $match)) {
			return $this->create_type_identifier($match[1]);
		}

		return null;
	}

	private function try_detatch_type_and_skip_value()
	{
		$token = $this->scan_typed_token_ignore_empty();

		switch ($token[0]) {
			case T_STRING:
				$lower_case = strtolower($token[1]);
				if ($lower_case === 'true' || $lower_case === 'false') {
					$type = TypeFactory::$_string;
				}
				else {
					// may be a user defined constant
					// $this->print_token($token);
					// throw $this->new_unexpected_error();
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
				$this->print_token($token);
				throw $this->new_unexpected_error();
		}

		if ($this->get_token_ignore_empty() !== _SEMICOLON) {
			return null;
		}

		return $type;
	}

	private function read_property_declaration(array $token, string $modifier, ?string $doc)
	{
		$type = null;
		if ($token[0] === T_STRING) {
			// it must be a type name
			$type = $this->create_type_identifier($token[1]);

			// scan the next token
			$token = $this->scan_typed_token_ignore_empty();
		}
		else {
			$type = $this->get_type_in_doc($doc, 'var');
			if ($type === null) {
				$this->pos = $temp_pos;
				throw $this->new_parse_error("Const $name required a type hint in it's doc.");
			}
		}

		$name = ltrim($token[1], '$');

		if ($this->skip_char_token(_ASSIGN)) {
			$this->try_detatch_type_and_skip_value();
		}

		$this->expect_statement_end();

		return $this->factory->create_property_declaration($modifier, $name, $type, null);
	}

	private function read_method_declaration(string $modifier = null, ?string $doc, bool $is_interface = false)
	{
		$name = $this->expect_identifier_token();
		$parameters = $this->read_parameters();
		$type = $this->try_read_function_return_type();

		if (isset(static::METHOD_MAP[$name])) {
			$name = static::METHOD_MAP[$name];
		}

		$declaration = $this->factory->declare_method($modifier, $name, $type, $parameters);

		if ($is_interface) {
			$this->expect_statement_end();
		}
		else {
			$this->read_function_block();
		}

		return $declaration;
	}

	private function read_function_declaration(?string $doc, bool $is_interface = false)
	{
		$name = $this->expect_identifier_token();
		$parameters = $this->read_parameters();
		$type = $this->try_read_function_return_type();

		$declaration = $this->factory->declare_function(null, $name, $type, $parameters);
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

			$type = null;
			if ($token[0] === T_STRING) {
				$type = $this->create_type_identifier($token[1]);
				$token = $this->scan_token_ignore_empty();
			}

			if ($token[0] !== T_VARIABLE) {
				throw $this->new_unexpected_error();
			}

			if ($this->skip_char_token(_ASSIGN)) {
				$value_type = $this->try_detatch_type_and_skip_value();
				if ($type === null) {
					$type = $value_type;
				}
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
		$this->expect_block_begin();

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

	private function read_type_identifier()
	{
		$name = $this->expect_identifier_token();
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
		$names[] = $this->expect_identifier_token();

		while ($next = $this->scan_token()) {
			if ($next === _SEMICOLON) {
				break;
			}
			elseif ($next[0] === T_NS_SEPARATOR) {
				$names[] = $this->expect_identifier_token();
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

	protected function expect_block_begin()
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

		return $token[1];
	}

	private function expect_identifier_token()
	{
		$token = $this->scan_token_ignore_empty();
		$this->assert_identifier_token($token);
		return $token[1];
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
