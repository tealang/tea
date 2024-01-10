<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ClassUseTraitsDeclaration extends Node implements IClassMemberDeclaration
{
	use ClassMemberDeclarationTrait;

	const KIND = 'use_trait_declaration';

	public $traits;

	public $options;

	public function __construct(array $traits, array $options = null)
	{
		$this->traits = $traits;
		$this->options = $options;
	}
}

// end
