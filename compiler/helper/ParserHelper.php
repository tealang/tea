<?php
/**
 * Parser Helper Utilities
 * 
 * Common helper functions for parser operations
 * 
 * @package Tea
 */

namespace Tea;

class ParserHelper
{
	/**
	 * Check if a token is a valid type identifier
	 * 
	 * @param array|string|null $token The token to check
	 * @return bool True if the token is a valid type identifier
	 */
	public static function is_type_token(array|string|null $token): bool
	{
		if (!is_array($token)) {
			return false;
		}
		
		$type_tokens = [
			T_STRING, T_ARRAY, T_CALLABLE,
			T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
			T_NAME_RELATIVE
		];
		
		return in_array($token[0], $type_tokens, true);
	}
	
	/**
	 * Check if a token is a visibility modifier
	 * 
	 * @param array|string|null $token The token to check
	 * @return bool True if the token is a visibility modifier
	 */
	public static function is_visibility_token(array|string|null $token): bool
	{
		if (!is_array($token)) {
			return false;
		}
		
		return in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true);
	}
	
	/**
	 * Check if a token is a whitespace token
	 * 
	 * @param array|string|null $token The token to check
	 * @return bool True if the token is whitespace
	 */
	public static function is_whitespace(array|string|null $token): bool
	{
		return is_array($token) && $token[0] === T_WHITESPACE;
	}
	
	/**
	 * Check if a token is a comment
	 * 
	 * @param array|string|null $token The token to check
	 * @return bool True if the token is a comment
	 */
	public static function is_comment(array|string|null $token): bool
	{
		return is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true);
	}
	
	/**
	 * Get the string value of a token
	 * 
	 * @param array|string|null $token The token
	 * @return string|null The token string or null
	 */
	public static function get_token_string(array|string|null $token): ?string
	{
		if ($token === null) {
			return null;
		}
		
		return is_array($token) ? $token[1] : $token;
	}
	
	/**
	 * Get the token type
	 * 
	 * @param array|string|null $token The token
	 * @return int|string|null The token type or null
	 */
	public static function get_token_type(array|string|null $token): int|string|null
	{
		if ($token === null) {
			return null;
		}
		
		return is_array($token) ? $token[0] : $token;
	}
	
	/**
	 * Check if a token matches a specific type
	 * 
	 * @param array|string|null $token The token to check
	 * @param int $type The token type to match
	 * @return bool True if the token matches the type
	 */
	public static function is_token_type(array|string|null $token, int $type): bool
	{
		return is_array($token) && $token[0] === $type;
	}
	
	/**
	 * Check if a token is a specific string
	 * 
	 * @param array|string|null $token The token to check
	 * @param string $string The string to match
	 * @return bool True if the token matches the string
	 */
	public static function is_token_string(array|string|null $token, string $string): bool
	{
		return $token === $string;
	}
	
	/**
	 * Normalize a token for comparison
	 * 
	 * @param array|string|null $token The token to normalize
	 * @return string|null The normalized token string or null
	 */
	public static function normalize_token(array|string|null $token): ?string
	{
		if ($token === null) {
			return null;
		}
		
		$str = is_array($token) ? $token[1] : $token;
		return strtolower($str);
	}
	
	/**
	 * Check if a string matches a keyword (case-insensitive)
	 * 
	 * @param string $token_str The token string
	 * @param string $keyword The keyword to match
	 * @return bool True if the string matches the keyword
	 */
	public static function is_keyword(string $token_str, string $keyword): bool
	{
		return strtolower($token_str) === $keyword;
	}
}
