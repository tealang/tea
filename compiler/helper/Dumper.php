<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const MAX_CHARS_INLINE = 150;

class Dumper
{
	const INDENT = '    ';

	const QUOTE_ENCODES = ['\\' => '\\\\', LF => '\n', "\r" => '\r', _TAB => '\t', '"' => '\"'];

	private $ignore_list = [];

	// private $dumped_objects = [];

	private $max_dump_depth;
	private $dumped_depth = 0;

	private $stringify_objects = [];

	function __construct(array $ignore_list = [], int $max_dump_depth = 5)
	{
		$this->ignore_list = $ignore_list;
		$this->max_dump_depth = $max_dump_depth;
	}

	function stringify($data, $indent = 1, $expansion_depth = 1, $max_chars_inline = MAX_CHARS_INLINE)
	{
		$this->dumped_depth++;

		if (is_null($data) ) {
			$tmp = 'null';
		}
		elseif (is_int($data) || is_float($data) ) {
			$tmp = $data;
		}
		elseif (is_bool($data)) {
			$tmp = $data ? 'true' : 'false';
		}
		elseif (is_string($data)) {
			$tmp = $this->quote_string($data);
		}
		elseif (is_array($data)) {
			$tmp = $this->stringify_array($data, $indent, $expansion_depth, $max_chars_inline);
		}
		elseif (is_object($data)) {
			$tmp = $this->stringify_object($data, $indent, $expansion_depth, $max_chars_inline);
		}
		else {
			throw new Exception("Unknow data type");
		}

		$this->dumped_depth--;
		return $tmp;
	}

	function stringify_array(array $data, $indent_num = 1, $expansion_depth = 1, $max_chars_inline = 120)
	{
		if (empty($data) ) {
			return '[]';
		}

		$is_index_array = range(0, count($data) - 1) === array_keys($data);

		if ($is_index_array) {
			$indents = $indent_num ? str_repeat(static::INDENT, $indent_num) : '';
			$indents = LF . $indents;

			$items = [];
			foreach ($data as $value) {
				$items[] = trim($this->stringify($value, $indent_num + 1, $expansion_depth - 1) );
			}

			$code = "[{$indents}" . static::INDENT . implode(",{$indents}" . static::INDENT, $items) . "{$indents}]";
		}
		else {
			$code = $this->stringify_as_object('Dict', $data, $indent_num, $expansion_depth, $max_chars_inline);
		}

		// 换行控制
		if (strlen($code) < $max_chars_inline) {
			$code = strtr($code, [static::INDENT => '', ",\n" => ', ', LF => '']);
		}

		return $code;
	}

	function stringify_object(object $object, $indent_num = 1, $expansion_depth = 1, $max_chars_inline = MAX_CHARS_INLINE)
	{
		$name = get_class($object);
		if (isset($object->name)) {
			$name .= " {$object->name}";
		}

		if ($this->dumped_depth > $this->max_dump_depth) {
			return "{$name} {...}";
		}
		elseif (in_array($object, $this->stringify_objects, true)) {
			return "{$name} {recurrence}";
		}
		// elseif (in_array($object, $this->dumped_objects, true)) {
		// 	return "{$name} {...} [dumped]";
		// }
		// else {
		// 	$this->dumped_objects[] = $object;
		// }

		$this->stringify_objects[] = $object;
		$tmp = $this->stringify_as_object($name, $object, $indent_num, $expansion_depth, $max_chars_inline);
		array_pop($this->stringify_objects);

		return $tmp;
	}

	function stringify_as_object(string $name, $object, $indent_num = 1, $expansion_depth = 1, $max_chars_inline = MAX_CHARS_INLINE)
	{
		$indents = $indent_num ? str_repeat(static::INDENT, $indent_num) : '';
		$indents = LF . $indents;

		$items = [];
		foreach ($object as $key => $value) {
			// if (empty($value) && $value !== 0) continue;

			if (is_object($value) && in_array($key, $this->ignore_list, true)) {
				$contents = get_class($value);
				if (isset($value->name)) {
					$contents .= " {$value->name}";
				}

				$contents .= ' [ignored]';
			}
			else {
				$contents = trim($this->stringify($value, $indent_num + 1, $expansion_depth - 1) );
			}

			$items[] = sprintf("%s: %s", $this->quote_key($key), $contents);
		}

		$code = "$name {{$indents}" . static::INDENT . implode(",{$indents}" . static::INDENT, $items) . "{$indents}}";

		// 换行控制
		if (strlen($code) < $max_chars_inline) {
			$code = strtr($code, [static::INDENT => '', ",\n" => ', ', LF => '']);
		}

		return $code;
	}

	function quote_key(?string $str)
	{
		if ($str === '') {
			return '[empty]';
		}

		return strtr($str, self::QUOTE_ENCODES);
	}

	function quote_string(?string $str)
	{
		if ($str === '') {
			return '""';
		}

		return '"' . strtr($str, self::QUOTE_ENCODES) . '"';
	}
}

