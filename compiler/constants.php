<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const
	// do not use the DIRECTORY_SEPARATOR to render targets code
	DS = '/',

	// some basic settings
	_MAX_NS_LEVELS = 3,
	_MAX_STRUCT_DIMENSIONS = 12,
	_BUILTIN_NS = 'tea/builtin',
	_UNIT_OPTIONAL_KEYS = ['type', 'loader'],

	// system labels
	_TEA = 'tea', _PHP  = 'php',
	_UNIT = 'unit', _USE = 'use',
	_MAIN = 'main', _EXPECT = 'expect', _INCLUDE = 'include',
	_TEXT = 'text', _DEFAULT = 'default',
	_ASYNC = 'async', _AWAIT = 'await',

	// builtin types
	_VOID = 'Void', _NONE = 'None', _ANY = 'Any',
	// _SCALAR = 'Scalar',
	// _BYTES = 'Bytes',
	// _RUNES = 'Runes',
	_STRING = 'String',
	_INT = 'Int', _UINT = 'UInt', _FLOAT = 'Float', _BOOL = 'Bool',
	_ITERABLE = 'Iterable', _DICT = 'Dict', _ARRAY = 'Array', // 'Pair', 'Matrix', 'Tensor'
	_OBJECT = 'Object', _XVIEW = 'XView', _REGEX = 'Regex',
	_CALLABLE = 'Callable', _NAMESPACE = 'Namespace', _METATYPE = 'MetaType', // _CLASS = 'Class',

	// dot signs of compound types
	_DOT_SIGN_ARRAY = 'Array', _DOT_SIGN_DICT = 'Dict', _DOT_SIGN_METATYPE = 'Type',

	// number system
	_BASE_BINARY = 'b', _BASE_OCTAL = 'o', _BASE_DECIMAL = '', _BASE_HEX = 'x',
	_LOW_CASE_E = 'e', _UP_CASE_E = 'E', _ZERO = '0',

	// blank chars
	// some blank chars did not supported now, eg. \f, \v, [0x2000 - 0x200B], line separator \u2028, paragraph separator \u2029
	_NOTHING = '', _SPACE = ' ', _TAB = "\t", _CR = "\r",

	// syntax chars, operators
	_SLASH = '/', _BACK_SLASH = '\\',
	_SHARP = '#', _DOLLAR = '$', _AT = '@', _EXCLAMATION = '!', _UNDERSCORE = '_', _STRIKETHROUGH = '-',
	_COLON = ':', _COMMA = ',', _SEMICOLON = ';',
	_AS = 'as', _IS = 'is',
	_IN = 'in', _TO = 'to', _DOWNTO = 'downto', _STEP = 'step', // just use in the 'for' block

	_REFERENCE = '&',  _NEGATION = '-', // -1, -num
	_ADDITION = '+', _SUBTRACTION = '-', _MULTIPLICATION = '*', _DIVISION = '/', _REMAINDER = '%', _EXPONENTIATION = '**',
	_BITWISE_NOT = '~', _BITWISE_AND = '&', _BITWISE_OR = '|', _BITWISE_XOR = '^', //bitwise
	_CONCAT = 'concat', _MERGE = 'merge',
	_NOT_EQUAL = '!=',
	_NOT = 'not', _AND = 'and', _OR = 'or', // bool
	_NONE_COALESCING = '??', _CONDITIONAL = '?',
	_ASSIGN = '=', _DOT = '.', _NOTIFY = '->', _ARROW = '=>', _RELAY = '==>',
	_COLLECT = '>>',
	_SHIFT_LEFT = '<<', _SHIFT_RIGHT = '>>',

	_SINGLE_QUOTE = '\'', _DOUBLE_QUOTE = '"',
	_XTAG_OPEN = '<', _XTAG_CLOSE = '>',
	_PAREN_OPEN = '(', _PAREN_CLOSE = ')',
	_BRACKET_OPEN = '[', _BRACKET_CLOSE = ']',
	_BRACE_OPEN = '{', _BRACE_CLOSE = '}',
	_BLOCK_BEGIN = _BRACE_OPEN, _BLOCK_END = _BRACE_CLOSE,
	_DOCS_MARK = '---', _INLINE_COMMENT_MARK = '//', _COMMENTS_OPEN = '/*', _COMMENTS_CLOSE = '*/',

	// reserved words
	_UNIT_PATH = 'UNIT_PATH',
	_VAL_NONE = 'none', _VAL_TRUE = 'true', _VAL_FALSE = 'false',
	_VAR = 'var', _YIELD = 'yield',
	_IF = 'if', _ELSE = 'else', _ELSEIF = 'elseif', _CASE = 'case', _WHEN = 'when',
	_FOR = 'for', _WHILE = 'while', _LOOP = 'loop',
	_TRY = 'try', _CATCH = 'catch', _FINALLY = 'finally',
	_ECHO = 'echo', _PRINT = 'print',
	_RETURN = 'return', _EXIT = 'exit', _BREAK = 'break', _CONTINUE = 'continue', _THROW = 'throw',
	_STATIC = 'static', _MASKED = 'masked', _PUBLIC = 'public', _INTERNAL = 'internal', _PROTECTED = 'protected', _PRIVATE = 'private',
	_THIS = 'this', _SUPER = 'super', _CONSTRUCT = 'construct', _DESTRUCT = 'destruct';


// program end

