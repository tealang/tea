<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

// 调用时参数顺序不同可能导致不一致的运算结果，采用类似宏替换在部分场景下可能会导致问题
// 问题举例：以为对象形式方法调用时，“对象表达式”可能在实际中被作为非第一个参数，如果其它参数与这个参数相关时，导致调用时序不一致
// 解决方案：用函数包起来，并且预先将“对象表达式”计算出来存储到局部变量中
// 为降低实现复杂度，可采用约定mask函数和真函数对应参数顺序强制一致，并且每个参数仅被调用一次

class MaskedDeclaration extends FunctionDeclaration
{
	const KIND = 'masked_declaration';
	public $arguments_map = [];
}

