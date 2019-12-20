<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
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
		$result = mkdir($dir, 0777, true);
		if (!$result) {
			throw Exception::file("Create dir failed.", $dir);
		}
	}

	public static function get_iterator(string $dir_path, string $regex = null, int $flags = 0): \Iterator
	{
		$dir_iter = new \RecursiveDirectoryIterator($dir_path, \FilesystemIterator::SKIP_DOTS | $flags);
		$recursive_iter = new \RecursiveIteratorIterator($dir_iter);
		if ($regex === null) {
			return $recursive_iter;
		}

		return new \RegexIterator($recursive_iter, $regex);
	}
}
