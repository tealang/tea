<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaHelper
{
	private const IMPURE_STRING_PATTERN = '/["<>\n\r]/';

	private const REGEX_MODIFIER_PATTERN = '/^[imu]+$/';

	public static function is_pure_string(string $text)
	{
		return preg_match(self::IMPURE_STRING_PATTERN, $text) == 0;
	}

	/**
	 * Check token is a number
	 * return the number base type when is a number format
	 *
	 * @return string 	the number base type (_BASE_DECIMAL, _BASE_HEX, _BASE_OCTAL, _BASE_BINARY)
	 */
	public static function check_number($token)
	{
		if ($token === _ZERO || preg_match('/^[1-9][0-9_]*(e\+?[0-9]*)?$/i', $token)) {
			return _BASE_DECIMAL;
		}

		if ($token[0] === _ZERO) {
			if ($token[1] === _BASE_HEX) {
				return preg_match('/^0x[0-9a-f][0-9a-f_]*$/i', $token) ? _BASE_HEX : null;
			}
			elseif ($token[1] === _BASE_BINARY) {
				return preg_match('/^0b[01][01_]*$/', $token) ? _BASE_BINARY : null;
			}
			elseif ($token[1] === _BASE_OCTAL) {
				return preg_match('/^0o[0-7][0-7_]*$/', $token) ? _BASE_OCTAL : null;
			}
		}

		return null;
	}

	public static function is_uint_number($token)
	{
		return preg_match('/^[1-9][0-9]*$/', $token) && $token <= PHP_INT_MAX;
	}

	public static function is_space_tab($token)
	{
		return $token === _SPACE || $token === _TAB;
	}

	public static function is_space_tab_nl($token)
	{
		return $token === _SPACE || $token === _TAB || $token === LF || $token === _CR;
	}

	public static function is_reserved($token)
	{
		return in_array($token, _CASE_INSENSITIVE_RESERVEDS, true)
			|| in_array(strtolower($token), _CASE_SENSITIVE_RESERVEDS, true);
	}

	public static function is_modifier($token)
	{
		return in_array($token, _ACCESSING_MODIFIERS, true);
	}

	public static function is_builtin_identifier($token)
	{
		return in_array($token, _BUILTIN_IDENTIFIERS, true);
	}

	public static function is_xtag_name($token)
	{
		return preg_match('/^[a-z\-\!][a-z0-9\-:]*$/i', $token);
	}

	public static function is_identifier_name(?string $token)
	{
		return preg_match('/^_{0,2}[a-z][a-z0-9_]*$/i', $token);
	}

	public static function is_normal_variable_name(?string $token)
	{
		return (preg_match('/^_{0,2}[a-z][a-z0-9_]*$/', $token)
			&& !self::is_reserved($token)) || $token === '_';
	}

	public static function is_normal_constant_name(?string $token)
	{
		return preg_match('/^_{0,2}[A-Z][A-Z0-9_]+$/', $token);
	}

	public static function is_normal_function_name(?string $token)
	{
		return preg_match('/^_{0,2}[a-z][a-z0-9_]*$/', $token);
	}

	public static function is_builtin_type_name(?string $token)
	{
		return in_array($token, _BUILTIN_TYPE_NAMES, true);
	}

	public static function is_domain_component(?string $token)
	{
		return preg_match('/^[a-z][a-z0-9\-]*[a-z0-9]$/i', $token);
	}

	public static function is_subnamespace_name(?string $token)
	{
		return preg_match('/^[a-z][a-z0-9]+$/i', $token);
	}

	public static function is_assign_operator_token(?string $token)
	{
		return in_array($token, _ASSIGN_OPERATORS, true);
	}

	public static function is_regex_flags($token)
	{
		return preg_match(self::REGEX_MODIFIER_PATTERN, $token);
	}
}

// end
