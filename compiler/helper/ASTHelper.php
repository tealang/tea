<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ASTHelper
{
	public static function is_assignable_expr(BaseExpression $expr)
	{
		if ($expr instanceof Identifiable && $expr->is_assignable()) {
			return true;
		}

		if ($expr instanceof KeyAccessing && self::is_mutable_expr($expr->left)) {
			return true;
		}

		if ($expr instanceof SquareAccessing && self::is_mutable_expr($expr->expression)) {
			return true;
		}

		return false;
	}

	public static function is_mutable_expr(BaseExpression $expr)
	{
		if ($expr instanceof Identifiable && $expr->is_mutable()) {
			return true;
		}

		if ($expr instanceof KeyAccessing && self::is_mutable_expr($expr->left)) {
			return true;
		}

		return false;
	}
}

// end
