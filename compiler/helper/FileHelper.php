<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class FileHelper
{
	// use the Unix style to normalize the path
	public static function normalize_path(string $path)
	{
		if (strpos($path, _BACK_SLASH) !== false) {
			$path = strtr($path, _BACK_SLASH, DS);
		}

		return $path;
	}

	public static function mkdir(string $dir)
	{
		$result = mkdir($dir, 0755, true);
		if (!$result) {
			throw new Exception("Create dir '$dir' failed.");
		}
	}

	public static function get_iterator(string $dir_path, string $pattern = null, int $flags = 0): \Iterator
	{
		$dir_iter = new \RecursiveDirectoryIterator($dir_path, \FilesystemIterator::SKIP_DOTS | $flags);
		$recursive_iter = new \RecursiveIteratorIterator($dir_iter);
		if ($pattern === null) {
			return $recursive_iter;
		}

		return new \RegexIterator($recursive_iter, $pattern);
	}
}
