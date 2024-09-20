<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

// operator constants
const
	OP_PREFIX = 0x00,	// prefix
	OP_POSTFIX = 0x01,	// postfix
	OP_BIN = 0x02,  // binary
	OP_TERNARY = 0x04,
	OP_ASSIGN = 0x08,
	OP_NA = 0x20,  	// n/a associative,
	OP_NON = 0x21, 	// non associative, cannot be used next to each other
	OP_L = 0x22,  	// left associative
	OP_R = 0x24;  	// right associative

const
	// do not use the DIRECTORY_SEPARATOR to render targets code
	DS = '/',

	// some basic settings
	_NS_LEVELS_MAX = 3,
	_STRUCT_DIMENSIONS_MAX = 12,
	_BUILTIN_NS = 'tea\builtin',
	_UNIT_OPTIONAL_KEYS = ['type', 'loader'],

	// system labels
	_MAIN = 'main',
	_TEST = 'test',
	_TEXT = 'text',
	_DEFAULT = 'default',

	// builtin types
	_INVALIDABLE_SIGN = '?',
	_UNIONTYPE = 'UnionType',
	_METATYPE = 'MetaType',
	_VOID = 'Void',
	_NONE = 'None',
	_ANY = 'Any',
	// _SCALAR = 'Scalar',
	_BYTES = 'Bytes',
	//_RUNES = 'Runes',
	_STRING = 'String',
	_PURE_STRING = 'Pures',
	_INT = 'Int',
	_UINT = 'UInt',
	_FLOAT = 'Float',
	_BOOL = 'Bool',
	_ITERABLE = 'Iterable',
	_DICT = 'Dict',
	_ARRAY = 'Array', // 'Pair', 'Matrix', 'Tensor'
	_GENERAL_ARRAY = 'GeneralArray',
	_OBJECT = 'Object',
	_XVIEW = 'XView',
	_REGEX = 'Regex',
	_CALLABLE = 'Callable',
	_TYPE_SELF = 'Self',

	_BASE_EXCEPTION = 'Exception',

	// dot signs of compound types
	_DOT_SIGN_ARRAY = 'Array', _DOT_SIGN_DICT = 'Dict', _DOT_SIGN_METATYPE = 'Type',

	// number system
	_BASE_BINARY = 'b', _BASE_OCTAL = 'o', _BASE_DECIMAL = '', _BASE_HEX = 'x',
	_LOW_CASE_E = 'e', _ZERO = '0',

	// blank chars
	// some blank chars did not supported now, e.g. \f, \v, [0x2000 - 0x200B], line separator \u2028, paragraph separator \u2029
	_NOTHING = '', _SPACE = ' ', _TAB = "\t", _CR = "\r",

	// syntax chars, operators
	_SLASH = '/',
	_BACK_SLASH = '\\',
	_SHARP = '#',
	_DOLLAR = '$',
	_AT = '@',
	_EXCLAMATION = '!',
	_UNDERSCORE = '_',
	_STRIKETHROUGH = '-',
	_COLON = ':',
	_COMMA = ',',
	_SEMICOLON = ';',
	_AS = 'as', // for make alias name
	_IS = 'is', // for check Type
	_IN = 'in', _TO = 'to', _DOWNTO = 'downto', _STEP = 'step', // just use in the 'for' block

	_TYPE_UNION = '|',
	_REFERENCE = '&',
	_IDENTITY = '+', // +1, +num
	_NEGATION = '-', // -1, -num
	_BITWISE_NOT = '~',
	_CLONE = 'clone',
	_CONCAT = 'concat',
	_NOT = 'not',
	_AND = 'and', _OR = 'or', _XOR = 'xor',
	// _ADDITION = '+', _SUBTRACTION = '-', _MULTIPLICATION = '*', _DIVISION = '/', _REMAINDER = '%', _EXPONENTIATION = '**',
	// _BITWISE_AND = '&', _BITWISE_OR = '|', _BITWISE_XOR = '^',
	// _ARRAY_CONCAT = 'array_concat', _MERGE = 'merge',
	// _EQUAL = '==', _NOT_EQUAL = '!=', _IDENTICAL = '===', _NOT_IDENTICAL = '!==',
	// _LESS_THAN = '<', _GREATER_THAN = '>',
	// _LESS_THAN_OR_EQUAL_TO = '<=', _GREATER_THAN_OR_EQUAL_TO = '>=',
	// _SPACESHIP = '<=>',
	_NONE_COALESCING = '??', _QUESTION = '?',
	_DOT = '.',
	_DOUBLE_COLON = '::',
	// _PUT = '<-', _NOTIFY = '->',
	_DOUBLE_ARROW = '=>',
	// _SHIFT_LEFT = '<<', _SHIFT_RIGHT = '>>',

	_ASSIGN = '=',
	_ASSIGN_OPERATORS = ['=', '+=', '-=', '*=', '/=', '.=', '%=', '&=', '|=', '^=', '<<=', '>>=', '??='], // '**=',

	_SINGLE_QUOTE = '\'',
	_DOUBLE_QUOTE = '"',
	_XTAG_OPEN = '<', _XTAG_CLOSE = '>',
	_PAREN_OPEN = '(', _PAREN_CLOSE = ')',
	_BRACKET_OPEN = '[', _BRACKET_CLOSE = ']',
	_BRACE_OPEN = '{', _BRACE_CLOSE = '}',
	_GENERIC_OPEN = '<', _GENERIC_CLOSE = '>',
	_BLOCK_BEGIN = _BRACE_OPEN, _BLOCK_END = _BRACE_CLOSE,
	_DOC_MARK = '---',
	_LINE_COMMENT_MARK = '//',
	_BLOCK_COMMENT_OPEN = '/*', _BLOCK_COMMENT_CLOSE = '*/',

	_UNIT_PATH = 'UNIT_PATH',
	_VAL_NULL = 'null',
	_VAL_NONE = 'none',
	_VAL_TRUE = 'true', _VAL_FALSE = 'false',

	_NAMESPACE = 'namespace',
	_USE = 'use',
	_VAR = 'var',
	_MUT = 'mut',
	_INOUT = 'inout',
	_YIELD = 'yield',
	_UNSET = 'unset',
	_IF = 'if', _ELSE = 'else', _ELSEIF = 'elseif',
	_SWITCH = 'switch', _CASE = 'case',
	_FOR = 'for',
	_WHILE = 'while',
	// _LOOP = 'loop',
	_TRY = 'try', _CATCH = 'catch', _FINALLY = 'finally',
	_ECHO = 'echo',
	_RETURN = 'return',
	_EXIT = 'exit',
	_BREAK = 'break',
	_CONTINUE = 'continue',
	_THROW = 'throw',
	// _WHEN = 'when',
	// _ASYNC = 'async', _AWAIT = 'await',
	_STATIC = 'static',
	_FINAL = 'final',
	_MASKED = 'masked',
	_RUNTIME = 'runtime',
	_PUBLIC = 'public',
	_INTERNAL = 'internal',
	_PROTECTED = 'protected',
	_PRIVATE = 'private',

	_TYPE = 'type',
	_CLASS = 'class',
	_ABSTRACT = 'abstract',
	_INTERFACE = 'interface',
	_TRAIT = 'trait',
	_INTERTRAIT = 'intertrait',
	_ENUM = 'enum',
	_FUNC = 'func',
	_CONST = 'const',
	_THIS = 'this',
	_SUPER = 'super',
	// _CT_SELF = '__ct_self', // self for compile time
	// _RT_SELF = '__rt_self', // self for run time
	// _INST_SELF = '__inst_self', // self for instance
	_CONSTRUCT = '__construct',
	_DESTRUCT = '__destruct',
	_EXTENDS = 'extends',
	_IMPLEMENTS = 'implements';

const _BUILTIN_TYPE_NAMES = [
	_UNIONTYPE,
	_METATYPE,
	_ANY,
	_VOID,
	_NONE,
	_STRING,
	_PURE_STRING,
	_INT,
	_UINT,
	_FLOAT,
	_BOOL,
	_DICT,
	_ARRAY,
	_ITERABLE,
	_OBJECT,
	_XVIEW,
	_REGEX,
	_CALLABLE,
	_TYPE_SELF,
];

const _CTRL_STRUCTURE_NAMES = [
	_VAR, _UNSET,
	_IF, _SWITCH, _FOR, _WHILE, _TRY, // _LOOP
	_ECHO, _RETURN, _EXIT, _BREAK, _CONTINUE, _THROW,
];

const _ACCESSING_MODIFIERS = [_MASKED, _PUBLIC, _INTERNAL, _PROTECTED, _PRIVATE];

const _BUILTIN_IDENTIFIERS = [_TYPE_SELF, _THIS, _SUPER, _VAL_NONE, _VAL_TRUE, _VAL_FALSE, _UNIT_PATH];

const _OTHER_KEY_WORDS = [
	_STATIC,
	_ELSEIF, _ELSE,
	_IN, _TO, _DOWNTO,
	_CATCH, _FINALLY,
	_CLONE, _CONCAT, _NOT, _AND, _OR, _XOR,
];

define('_CASE_SENSITIVE_RESERVEDS', array_merge(
	_CTRL_STRUCTURE_NAMES,
	_ACCESSING_MODIFIERS,
	_BUILTIN_IDENTIFIERS,
	_OTHER_KEY_WORDS,
));

define('_CASE_INSENSITIVE_RESERVEDS', array_merge(
	_BUILTIN_TYPE_NAMES,
	[_UNIT_PATH],
));


// program end
