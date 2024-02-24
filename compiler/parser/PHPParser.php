<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const SUPPORT_PHP_VERSION = '8.1.10';

if (version_compare(PHP_VERSION, SUPPORT_PHP_VERSION, '<')) {
	trigger_error('The minimum supported PHP version for parser is "' . SUPPORT_PHP_VERSION . '".', E_USER_ERROR);
}

// // define PHP8 constants to compatible
// if (!defined('T_NAME_QUALIFIED')) {
// 	define('T_NAME_QUALIFIED', 1001);
// 	define('T_NAME_FULLY_QUALIFIED', 1002);
// }

/**
 * A lite Parser uses to supported the Mixed Programming
 */
class PHPParser extends BaseParser
{
	const NS_SEPARATOR = _BACK_SLASH;

	const VAL_NONE = _VAL_NULL;

	private const TYPE_MAP = [
		'void' => _VOID,
		'mixed' => _ANY,
		'string' => _STRING,
		'int' => _INT,
		'float' => _FLOAT,
		'bool' => _BOOL,
		'array' => _DICT,
		'iterable' => _ITERABLE,
		'callable' => _CALLABLE,
		'object' => _OBJECT,
	];

	private const TYPING_TOKEN_TYPES = [
		T_STRING,
		T_ARRAY,
		T_CALLABLE,
		T_NAME_QUALIFIED,
		T_NAME_FULLY_QUALIFIED
	];

	private const METHOD_MAP = [
		'__construct' => _CONSTRUCT,
		'__destruct' => _DESTRUCT,
		'__toString' => 'to_string',
	];

	private const PREFIX_OPERATORS = [_EXCLAMATION, _NEGATION, _IDENTITY, _BITWISE_NOT];

	private const EXPRESSION_ENDINGS = [null, _PAREN_CLOSE, _BRACKET_CLOSE, _BLOCK_END, _COMMA, _SEMICOLON];

	private const VALID_TOKEN_TYPES = [T_STRING, T_PRINT, T_ECHO, T_EXIT, T_USE];

	/**
	 * @var NamespaceIdentifier
	 */
	private $namespace;

	private $current_following_comment;

	public function read_program(): Program
	{
		$this->is_declare_mode = false;

		$max_pos = $this->tokens_count - 1;

		// while ($this->pos < $max_pos) {
		// 	$this->pos++;
		// 	$this->print_token();
		// }
		// exit;

		$this->program->is_native = true;

		while ($this->pos < $max_pos) {
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
			$token = $this->expect_typed_token_ignore_empty();
		}

		switch ($token[0]) {
			case T_NAMESPACE:
				$node = $this->read_namespace_statement();
				break;

			case T_USE:
				$node = $this->read_use_statement();
				break;

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

			case T_TRAIT:
				$node = $this->read_trait_declaration();
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

			// we do not care the others
			default:
				$node = null;
		}

		return $node;
	}

	private function read_namespace_statement()
	{
		if ($this->namespace !== null) {
			throw $this->new_parse_error("Cannot redeclare a new namespace");
		}

		$token = $this->scan_token();
		$names = $this->read_qualified_name_with($token);

		$ns = $this->create_namespace_identifier($names);
		$this->namespace = $ns;
		$this->program->ns = $ns;

		$statement = new NamespaceStatement($ns);
		return $statement;
	}

	private function create_namespace_identifier(array $names)
	{
		$ns = $this->factory->create_namespace_identifier($names);
		$ns->pos = $this->pos;

		return $ns;
	}

	private function read_use_statement()
	{
		// e.g. use NS\Target;
		// e.g. use NS\Target as AliasName1;
		// e.g. use NS1\NS2\{Target1, Target2 as AliasName2};

		$token = $this->scan_token_ignore_empty();

		// if has the \ separator, skip it
		if ($token[0] === T_NS_SEPARATOR) {
			$token = $this->scan_token_ignore_empty();
		}

		if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) {
			$names = explode(_BACK_SLASH, $token[1]);
		}
		else {
			$names[] = $token[1];
		}

		$alias_name = null;
		$targets = null;

		// scan the namespace components
		while ($token = $this->scan_token_ignore_empty()) {
			if ($token === _SEMICOLON) {
				break;
			}
			elseif ($token[0] === T_NS_SEPARATOR) {
				$next = $this->scan_token_ignore_empty();
				if ($next === _BLOCK_BEGIN) {
					// the multi targets mode
					$ns = $this->create_namespace_identifier($names);
					$targets = $this->read_use_targets($ns);
					$this->expect_block_end();
					break;
				}
				else {
					$names[] = $this->get_identifier_name($next);
				}
			}
			elseif ($token[0] === T_AS) {
				$alias_name = $this->expect_identifier_name();
				break;
			}
			else {
				throw $this->new_unexpected_error();
			}
		}

		// the single target mode
		if ($targets === null) {
			$name = array_pop($names);
			$ns = $this->create_namespace_identifier($names);

			if ($alias_name) {
				$target = $this->factory->append_use_target($ns, $alias_name, $name);
			}
			else {
				$target = $this->factory->append_use_target($ns, $name);
			}

			$targets = [$target];
		}

		// 由于PHP项目中使用的库来源多，且不一定都满足编译规则，故不能将所有"use ..."的都加入解析
		// 确实需要解析的，需要在 __package.th 中显式声明

		$statement = $this->create_use_statement_when_not_exists($ns, $targets);

		return $statement;
	}

	private function read_use_targets(NamespaceIdentifier $ns): array
	{
		$targets = [];
		while ($token = $this->scan_token_ignore_empty()) {
			$name = $token[0];
			$next = $this->get_token_ignore_empty();

			if ($next[0] === T_AS) {
				$alias = $this->expect_identifier_name($next);
				$target = $this->factory->append_use_target($ns, $alias, $name);
			}
			else {
				$target = $this->factory->append_use_target($ns, $name);
			}

			$target->pos = $this->pos;
			$targets[] = $target;

			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		return $targets;
	}

	private function read_expression($debug_name = null)
	{
		// we do not care about the contents, just skip ...

		$expr = null;
		while (($token = $this->scan_token_ignore_empty()) !== null) {
			// the string token
			if (is_string($token)) {
				if (in_array($token, static::EXPRESSION_ENDINGS, true)) {
					$this->pos--;
					break;
				}

				switch ($token) {
					case _PAREN_OPEN:
						$expr = $this->read_expression();
						$this->expect_char_token(_PAREN_CLOSE);
						break 2;

					case _BRACKET_OPEN:
						$expr = $this->read_bracket_expression();
						break 2;

					default:
						if ($expr === null) {
							$operator = OperatorFactory::get_php_prefix_operator($token);
						}
						else {
							$operator = OperatorFactory::get_php_normal_operator($token);
						}

						if ($operator === null) {
							return $expr;
						}

						// we don't care the precedences
						$right_expr = $this->read_expression();

						if ($expr === null) {
							$expr = new PrefixOperation($operator, $right_expr);
						}
						else {
							$expr = new BinaryOperation($operator, $expr, $right_expr);
						}

						break;
				}

				continue;
			}

			// the typed token
			$token_type = $token[0];
			$token_content = $token[1];

			switch ($token_type) {
				case T_STRING:
					if ($token_content === static::VAL_NONE) {
						$expr = $this->factory->create_none_identifier();
					}
					else {
						$expr = $this->create_unchecking_identifier($token_content);
					}
					break;
				case T_NS_SEPARATOR:
					$expr = $this->read_classkindred_identifier($token);
					break;
				case T_NAME_QUALIFIED:
				case T_NAME_FULLY_QUALIFIED:
					$expr = $this->create_unchecking_identifier($token_content);
					break;
				case T_CONSTANT_ENCAPSED_STRING:
					$quote = $token_content[0];
					$quote_content = substr($token_content, 1, -1);
					$expr = $quote === _SINGLE_QUOTE
						? new UnescapedStringLiteral($quote_content)
						: new EscapedStringLiteral($quote_content);
					break;
				case T_LNUMBER:
					$expr = new IntegerLiteral($token_content);
					break;
				case T_DNUMBER:
					$expr = new FloatLiteral($token_content);
					break;
				case T_DOUBLE_COLON:
					$name = $this->expect_member_identifier_name();
					$expr = $this->factory->create_accessing_identifier($expr, $name);
					break;
				case T_DOUBLE_ARROW:
					$this->pos--;
					break 2;
				case T_COMMENT:
				case T_DOC_COMMENT:
					continue 2;
				default:
					// $this->print_token($token);
					throw $this->new_unexpected_error();
			}

			$expr->pos = $this->pos;
		}

		return $expr;
	}

	private function create_unchecking_identifier(string $name)
	{
		$identifier = new PlainIdentifier($name);
		$identifier->pos = $this->pos;
		return $identifier;
	}

	private function read_bracket_expression(bool $not_opened = false, $debug_name = null)
	{
		$not_opened && $this->expect_char_token(_BRACKET_OPEN);

		$is_dict = false;
		while ($expr1 = $this->read_expression($debug_name)) {
			if ($this->skip_typed_token(T_DOUBLE_ARROW)) {
				$is_dict = true;
				$expr2 = $this->read_expression();
			}

			while ($this->get_token_ignore_empty()[0] === T_COMMENT) {
				$this->scan_token_ignore_empty();
			}

			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		$this->expect_char_token(_BRACKET_CLOSE);

		return $is_dict ? new DictExpression([]) : new ArrayExpression([]);
	}

	private function read_interface_declaration()
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_interface_declaration($name, _PUBLIC, $this->namespace);
		$declaration->pos = $this->pos;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->baseds = $this->expect_identifier_name();
		}

		$this->expect_block_begin();

		while ($this->read_interface_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
	}

	private function read_trait_declaration()
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_trait_declaration($name, _PUBLIC, $this->namespace);
		$declaration->pos = $this->pos;

		$this->expect_block_begin();

		while ($this->read_class_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
	}

	private function read_class_declaration(bool $is_abstract = false)
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_class_declaration($name, _PUBLIC, $this->namespace);
		$declaration->pos = $this->pos;
		$declaration->is_abstract = $is_abstract;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->inherits = $this->read_classkindred_identifier();
		}

		if ($this->skip_typed_token(T_IMPLEMENTS)) {
			do {
				$implements[] = $this->read_classkindred_identifier();
			}
			while ($this->skip_char_token(_COMMA));

			$declaration->baseds = $implements;
		}

		$this->expect_block_begin();

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
			$token = $this->expect_typed_token_ignore_empty();
		}

		$modifier = null;
		if (in_array($token[0], [T_PUBLIC], true)) {
			$modifier = $token[1];
			$token = $this->expect_typed_token_ignore_empty();
		}

		$is_static = $token[0] === T_STATIC;
		if ($is_static) {
			$token = $this->expect_typed_token_ignore_empty();
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
			$token = $this->expect_typed_token_ignore_empty();
		}

		$modifier = null;
		if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
			$modifier = $token[1];
			$token = $this->scan_token_ignore_empty();;
		}

		$is_static = false;
		if ($token[0] === T_STATIC) {
			$is_static = true;
			$token = $this->scan_token_ignore_empty();;
		}

		// for property/constant
		$nullable = false;
		if (is_string($token)) {
			if ($token === '?') {
				$nullable = true;
			}
			else {
				throw $this->new_unexpected_error();
			}

			$token = $this->expect_typed_token_ignore_empty();
		}

		// $this->print_token($token);
		switch ($token[0]) {
			case T_VAR:
				$token = $this->expect_typed_token_ignore_empty();
				// unbreak
			case T_VARIABLE:
				$declaration = $this->read_property_declaration($token, $modifier, $doc);
				break;

			case T_STRING: // type annotated property
			case T_ARRAY:
			case T_NAME_FULLY_QUALIFIED:
				$type_identifier = $this->read_type_tailing_and_create_identifier($token[1], $nullable);
				$token = $this->expect_typed_token_ignore_empty();
				$declaration = $this->read_property_declaration($token, $modifier, $doc, $type_identifier);
				break;

			case T_CONST:
				$declaration = $this->read_class_constant_declaration($modifier, $doc);
				break;

			case T_FUNCTION:
				$declaration = $this->read_method_declaration($modifier, $doc);
				break;

			case T_COMMENT:
				return $this->read_class_member();

			case T_USE:
				$declaration = $this->read_trait_use_declaration();
				break;

			default:
				$this->print_token($token);
				throw $this->new_unexpected_error();
		}

		if ($is_static) {
			$declaration->is_static = $is_static;
		}

		$this->factory->end_class_member();

		return $declaration;
	}

	private function read_trait_use_declaration()
	{
		$used_traits = [];
		do {
			$used_traits[] = $this->read_classkindred_identifier();
		}
		while ($this->skip_char_token(_COMMA));

		$this->expect_statement_end();

		return new ClassUseTraitsDeclaration($used_traits);
	}

	private function read_constant_declaration(?string $doc)
	{
		$name = $this->expect_identifier_name();
		$declaration = $this->factory->create_constant_declaration(_PUBLIC, $name, $this->namespace);

		$this->expect_char_token(_ASSIGN);
		$declaration->type = $this->read_value_type_skip($doc);
		$this->expect_statement_end();

		$declaration->pos = $this->pos;

		return $declaration;
	}

	private function read_class_constant_declaration(?string $modifier, ?string $doc)
	{
		$name = $this->expect_member_identifier_name();
		$declaration = $this->factory->create_class_constant_declaration($modifier ?? _PUBLIC, $name);

		$this->expect_char_token(_ASSIGN);
		$declaration->type = $this->read_value_type_skip($doc, 'const', $name);
		$this->expect_statement_end();

		$declaration->pos = $this->pos;

		return $declaration;
	}

	private function read_value_type_skip(?string $doc, string $doc_kind = 'const', string $name = null)
	{
		$temp_pos = $this->pos;

		$type = $this->try_detatch_assign_value_type_and_skip($name);
		if ($type === null and $doc) {
			$type = $this->get_type_in_doc($doc, $doc_kind);
		}

		return $type;
	}

	private function get_type_in_doc(?string $doc, string $kind)
	{
		// /**
		//  * @var int
		//  */

		if ($doc !== null and preg_match('/\s+\*\s+@' . $kind . '\s+([^\s]+)/', $doc, $match)) {
			return $this->create_doc_type_identifier($match[1]);
		}

		return null;
	}

	private function try_detatch_assign_value_type_and_skip($name = null)
	{
		$token = $this->scan_token_ignore_empty();

		if (is_string($token)) {
			if ($token === _BRACKET_OPEN) {
				$expr = $this->read_bracket_expression(false, $name);

				if ($this->skip_char_token(_SEMICOLON)) {
					$this->pos--; // back to ;
					return $expr instanceof ArrayExpression
						? TypeFactory::$_array
						: TypeFactory::$_dict;
				}
			}
			elseif (in_array($token, self::PREFIX_OPERATORS, true)) {
				if ($token === _EXCLAMATION) {
					$this->read_expression(); // skip the expression when is bool
					return TypeFactory::$_bool;
				}

				return $this->try_detatch_assign_value_type_and_skip($name);
			}
			else {
				// $this->print_token($token);
				throw $this->new_unexpected_error();
			}
		}

		switch ($token[0]) {
			// constants
			case T_NS_SEPARATOR:
				$token = $this->scan_token();
			case T_STRING:
				$lower_case = strtolower($token[1]);
				if ($lower_case === _VAL_TRUE || $lower_case === _VAL_FALSE) {
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

			case T_NAME_FULLY_QUALIFIED:
			case T_NAME_QUALIFIED:
				$type = null;
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

	private function read_property_declaration(array $token, string $modifier, ?string $doc, IType $type = null)
	{
		$name = ltrim($token[1], '$');
		$declaration = $this->factory->create_property_declaration($modifier, $name);
		$declaration->pos = $this->pos;

		if ($type === null) {
			$type = $this->get_type_in_doc($doc, 'var');
		}

		if ($this->skip_char_token(_ASSIGN)) {
			if ($type) {
				$this->skip_to_char_token(_SEMICOLON);
				$this->pos--;
			}
			else {
				$type = $this->read_value_type_skip($doc, 'var');
			}
		}
		// elseif ($type === null) {
		// 	$type = TypeFactory::$_any;
		// }

		$this->expect_statement_end();
		$declaration->type = $type;
		$declaration->pos = $this->pos;

		return $declaration;
	}

	private function read_method_declaration(string $modifier = null, ?string $doc, bool $is_interface = false)
	{
		$name = $this->expect_member_identifier_name();
		if (isset(static::METHOD_MAP[$name])) {
			$name = static::METHOD_MAP[$name];
		}

		$declaration = $this->factory->create_method_declaration($modifier ?? _PUBLIC, $name);

		$parameters = $this->read_parameters();
		$this->factory->set_scope_parameters($parameters);

		$declaration->type = $this->try_read_function_return_type();

		$declaration->pos = $this->pos;

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

		$declaration = $this->factory->create_function_declaration(_PUBLIC, $name, $this->namespace);

		$parameters = $this->read_parameters();
		$return_type = $this->try_read_function_return_type();

		$this->factory->set_scope_parameters($parameters);
		$declaration->type = $return_type;

		$declaration->pos = $this->pos;

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
		while ($parameter = $this->read_parameter()) {
			$items[] = $parameter;
			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		$this->expect_char_token(_PAREN_CLOSE);

		return $items;
	}

	private function read_parameter()
	{
		$token = $this->get_token_ignore_empty();
		if ($token === _PAREN_CLOSE) {
			return null;
		}

		$this->scan_token_ignore_empty();

		// $this->print_token($token);
		$token_type = $token[0];

		// parameters at __construct maybe has modifiers
		$modifier = null;
		if (in_array($token_type, [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
			$modifier = $token[1];
			$token = $this->scan_token_ignore_empty();
			$token_type = $token[0];
		}

		$type = $this->try_read_type_expression_with_token($token);
		if ($type) {
			$token = $this->scan_token_ignore_empty();
		}

		// variadic feature, the '...' operator
		$is_variadic = false;
		if ($token[0] === T_ELLIPSIS) {
			$is_variadic = true;
			$token = $this->expect_typed_token_ignore_empty();
		}

		// &
		$is_reference = $token === _REFERENCE
			|| $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
		if ($is_reference) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		if ($token[0] !== T_VARIABLE) {
			$this->print_token($token);
			throw $this->new_unexpected_error();
		}

		$name = substr($token[1], 1); // remove the prefix '$'

		$value = null;
		if ($this->skip_char_token(_ASSIGN)) {
			$value = $this->read_expression($name);
			if ($type === null and $value instanceof DictType) {
				$type = TypeFactory::$_dict;
			}

			$value = ASTFactory::$default_value_marker;
		}

		$declar = new ParameterDeclaration($name, $type, $value);
		$declar->is_value_mutable = $is_reference;
		$declar->is_variadic = $is_variadic;

		return $declar;
	}

	private function try_read_type_expression_with_token($token)
	{
		$nullable = $token === _INVALIDABLE_SIGN;
		if ($nullable) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		$following_comment = $this->scan_comments_ignore_empty();

		$token_type = $token[0];
		if (in_array($token_type, self::TYPING_TOKEN_TYPES)) {
			$name = $token[1];
			$type = $this->create_type_identifier($name, $nullable, $following_comment);
			while ($this->skip_char_token(_TYPE_UNION)) {
				$member_name = $this->expect_identifier_name();
				$member_type = $this->create_type_identifier($member_name);
				$type = $type->unite_type($member_type);
			}
		}
		else {
			$type = null;
		}

		return $type;
	}

	private function read_function_block()
	{
		$this->read_block('function');
		$this->factory->end_block();
	}

	private function read_block(string $label = null)
	{
		// echo "\n--------------------begin {$label}\n";
		$this->expect_block_begin();

		// we don't care the contents
		while (($token = $this->get_token_ignore_empty()) !== null) {
			// var_dump($token);
			if ($token === _BLOCK_BEGIN) {
				$this->read_block('local');
			}
			elseif ($token === _BLOCK_END) {
				break;
			}
			elseif ($token === _DOUBLE_QUOTE) {
				$this->scan_token_ignore_empty();
				$this->read_double_quoted_string();
			}
			elseif ($token === _SINGLE_QUOTE) {
				$this->scan_token_ignore_empty();
				$this->read_single_quoted_string();
			}
			elseif ($token[0] === T_START_HEREDOC) {
				$this->scan_token_ignore_empty();
				$this->read_heredoc();
			}
			else {
				$this->scan_token_ignore_empty();
			}
		}

		$this->expect_block_end();
		// echo "\n--------------------end {$label}\n";
	}

	private function read_heredoc()
	{
		$items = [];
		while (($token = $this->scan_token_ignore_empty())) {
			if ($token[0] === T_END_HEREDOC) {
				break;
			}

			$items[] = $token;
		}

		return $items;
	}

	private function read_double_quoted_string()
	{
		$value = $this->skip_to_char_token(_DOUBLE_QUOTE);
		$this->scan_token();
		return $value;
	}

	private function read_single_quoted_string()
	{
		$value = $this->skip_to_char_token(_SINGLE_QUOTE);
		$this->scan_token();
		return $value;
	}

	private function read_classkindred_identifier($token = null)
	{
		// NS1\NS2\Target
		// \NS1\NS2\Target

		if ($token === null) {
			$token = $this->scan_token_ignore_empty();
		}

		$components = $this->read_qualified_name_with($token);
		$identifier = $this->create_classkindred_identifier(array_pop($components), $components);

		return $identifier;
	}

	private function create_classkindred_identifier(string $name, array $ns_components = null)
	{
		$identifier = $this->factory->create_classkindred_identifier($name);
		$identifier->pos = $this->pos;

		if ($ns_components) {
			$ns = $this->create_namespace_identifier($ns_components);
			$identifier->set_namespace($ns);

			// $target = $this->factory->append_use_target($ns, $name);
			// $statement = $this->create_use_statement_when_not_exists($ns, [$target]);
		}

		$this->program->set_defer_check_identifier($identifier);

		return $identifier;
	}

	private function create_use_statement_when_not_exists(NamespaceIdentifier $ns, array $targets = [])
	{
		if ($this->factory->exists_use_unit($ns)) {
			$statement = null;
		}
		else {
			$statement = new UseStatement($ns, $targets);
			$statement->pos = $this->pos;
		}

		return $statement;
	}

	private function read_type_identifier()
	{
		$nullable = $this->skip_char_token('?');
		$name = $this->expect_identifier_name();

		return $this->read_type_tailing_and_create_identifier($name, $nullable);
	}

	private function read_type_tailing_and_create_identifier(string $name, bool $nullable)
	{
		$following_comment = $this->scan_comments_ignore_empty();
		$identifier = $this->create_type_identifier($name, $nullable, $following_comment);

		return $identifier;
	}

	private function create_type_identifier(string $name, bool $nullable = false, string $following_comment = null)
	{
		$lower_case_name = strtolower($name);
		if ($lower_case_name === 'array') {
			if ($following_comment === '/*list*/') {
				$identifier = TypeFactory::$_array;
			}
			elseif ($following_comment === '/*dict*/') {
				$identifier = TypeFactory::$_dict;
			}
			else {
				$identifier = TypeFactory::$_hashtable;
			}

			if ($nullable) {
				$identifier = clone $identifier;
			}
		}
		elseif (isset(static::TYPE_MAP[$lower_case_name])) {
			$name = static::TYPE_MAP[$lower_case_name];
			if ($following_comment === '/*list*/') {
				$name = _ARRAY;
			}

			$identifier = TypeFactory::get_type($name);
			if ($nullable) {
				$identifier = clone $identifier;
			}
		}
		elseif (strpos($name, static::NS_SEPARATOR) !== false) {
			$names = explode(static::NS_SEPARATOR, $name);
			$identifier = $this->create_classkindred_identifier(array_pop($names), $names);
		}
		else {
			$identifier = $this->create_classkindred_identifier($name);
		}

		$identifier->nullable = $nullable;
		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_doc_type_identifier(string $name, bool $nullable = false)
	{
		if (strpos($name, _DOT)) {
			$identifier = $this->create_dots_style_compound_type($name);
			$identifier->nullable = $nullable;
		}
		else {
			$identifier = $this->create_type_identifier($name, $nullable);
		}

		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_dots_style_compound_type(string $names): IType
	{
		$names = explode(_DOT, $names);
		$name = array_shift($names);
		$type = $this->create_type_identifier($name);

		$i = 0;
		foreach ($names as $kind) {
			if ($i === _STRUCT_DIMENSIONS_MAX) {
				throw $this->new_parse_error('The dimensions of Array/Dict exceeds, the max is ' . _STRUCT_DIMENSIONS_MAX);
			}

			if ($kind === _DOT_SIGN_ARRAY) {
				$type = TypeFactory::create_array_type($type);
			}
			elseif ($kind === _DOT_SIGN_DICT) {
				$type = TypeFactory::create_dict_type($type);
			}
			elseif ($kind === _DOT_SIGN_METATYPE) {
				$type = TypeFactory::create_meta_type($type);
			}
			else {
				throw $this->new_unexpected_error();
			}

			$i++;
		}

		return $type;
	}

	private function expect_statement_end()
	{
		return $this->expect_char_token(_SEMICOLON);
	}

	private function expect_block_begin()
	{
		return $this->expect_char_token(_BLOCK_BEGIN);
	}

	private function expect_block_end()
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

	private function read_qualified_name_with($token)
	{
		switch ($token[0]) {
			case T_NS_SEPARATOR:
				$names = $this->read_names_with_component(_NOTHING);
				break;

			case T_STRING:
				$names = $this->read_names_with_component($token[1]);
				break;

			case T_NAME_QUALIFIED:
			case T_NAME_FULLY_QUALIFIED:
				$names = explode(_BACK_SLASH, $token[1]);
				break;

			default:
				// $this->print_token($token);
				throw $this->new_unexpected_error();
		}

		return $names;
	}

	private function read_names_with_component(string $component)
	{
		$names = [$component];

		while (($next = $this->get_token()) && $next[0] === T_NS_SEPARATOR) {
			$this->scan_token();
			$names[] = $this->expect_identifier_name();
		}

		return $names;
	}

	private function expect_identifier_name()
	{
		$token = $this->scan_token_ignore_empty();
		return $this->get_identifier_name($token);
	}

	private function expect_member_identifier_name()
	{
		$token = $this->scan_token_ignore_empty();
		$this->assert_member_identifier_token($token);
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

	private function get_identifier_name($token)
	{
		if (is_string($token) || ($token[0] !== T_STRING && $token[0] !== T_ARRAY)) {
			// $this->print_token($token);
			throw $this->new_unexpected_error();
		}

		$type = $token[1];
		return $type;
	}

	private function assert_member_identifier_token($token)
	{
		if (is_string($token) || !in_array($token[0], self::VALID_TOKEN_TYPES, true) ) {
			// $this->print_token($token);
			throw $this->new_unexpected_error();
		}
	}

	protected function get_current_token_string()
	{
		$token = $this->tokens[$this->pos] ?? null;
		return is_array($token) ? $token[1] : $token;
	}

	private function expect_typed_token_ignore_empty()
	{
		$token = $this->scan_token_ignore_empty();
		if (!is_array($token)) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function scan_comments_ignore_empty()
	{
		$comment = null;
		$next = $this->get_token_ignore_empty();
		if ($next !== null and ($next[0] === T_DOC_COMMENT || $next[0] === T_COMMENT) and !strpos("\n", $this->get_token()[1])) {
			$comment = $next[1];
			do {
				$this->pos++;
				$tmp = $this->tokens[$this->pos] ?? null;
				if ($tmp !== _SPACE && (!is_array($tmp) || $tmp[0] !== T_WHITESPACE)) {
					break;
				}
			} while ($tmp !== null);
		}

		return $comment;
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
		$token = $this->tokens[$this->pos] ?? null;

		if ($token[0] === T_WHITESPACE) {
			return $this->scan_token();
		}

		return $token;
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

			if ($token === LF || (is_array($token) && strpos($token[1], LF) !== false)) {
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

	protected function get_previous_code_inline(int $pos = null): string
	{
		if ($pos === null) {
			$pos = $this->pos;
		}

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

	private function print_token($token = null, string $marker = null)
	{
		$token === null and ($token = $this->tokens[$this->pos] ?? null);

		if ($marker) {
			echo $marker . "\t";
		}

		if (is_string($token)) {
			echo $token, LF;
		}
		elseif ($token) {
			echo token_name($token[0]), " $token[1]\n";
		}
		else {
			echo "no token at pos {$this->pos}\n";
		}
	}
}

// end
