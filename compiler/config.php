<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

const
	TEA_EXT_NAME = 'tea',
	TEA_HEADER_EXT_NAME = 'th',
	PHP_EXT_NAME = 'php',

	SRC_DIR_NAME = 'src',
	DIST_DIR_NAME = 'dist',

	UNIT_HEADER_FILE_NAME = '__package.th',

	PUBLIC_HEADER_NAME = '__public',
	PUBLIC_HEADER_FILE_NAME = '__public.th',
	PUBLIC_LOADER_FILE_NAME = '__public.php',

	BUILTIN_LOADER_FILE = 'tea/builtin/' . PUBLIC_LOADER_FILE_NAME,

	DIR_SCAN_SKIP_ITEMS = ['.', '..', DIST_DIR_NAME, PUBLIC_LOADER_FILE_NAME]
;

// program end
