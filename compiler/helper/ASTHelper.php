<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ASTHelper
{
	public static function is_pure_bracket_accessing_expr(BaseExpression $expr)
	{
		return $expr instanceof BracketAccessing
			&& ($expr->basing instanceof PlainIdentifier
				|| self::is_pure_bracket_accessing_expr($expr->basing));
	}

	public static function is_assignable_expr(BaseExpression $expr)
	{
		return $expr instanceof Identifiable && $expr->is_assignable()
			|| $expr instanceof KeyAccessing && self::is_mutable_expr($expr->basing)
			|| $expr instanceof SquareAccessing && self::is_mutable_expr($expr->basing)
			|| $expr instanceof Destructuring;
	}

	public static function is_mutable_expr(BaseExpression $expr)
	{
		return $expr instanceof Identifiable && $expr->is_mutable()
			|| $expr instanceof KeyAccessing && self::is_mutable_expr($expr->basing);
	}
}

// end
