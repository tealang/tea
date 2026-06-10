<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class SemanticContext
{
	public static function reset(): void
	{
		self::reset_check_state();
		ASTHelper::reset_semantic_state();
		TypeHelper::reset_semantic_state();
		OutputSafety::reset_semantic_state();
	}

	public static function reset_check_state(): void
	{
		ASTChecker::reset_check_state();
	}
}

// end
