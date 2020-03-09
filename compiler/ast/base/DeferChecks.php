<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait DeferChecksTrait
{
	public $uses = [];

	public $defer_check_identifiers = [];

	public function append_use_declaration(UseDeclaration $use)
	{
		if (!in_array($use, $this->uses, true)) {
			$this->uses[] = $use;
		}
	}

	public function set_defer_check_identifier(Identifiable $identifier)
	{
		$this->defer_check_identifiers[$identifier->name] = $identifier;
	}

	public function remove_defer_check_identifier(Identifiable $identifier)
	{
		if (isset($this->defer_check_identifiers[$identifier->name])) {
			unset($this->defer_check_identifiers[$identifier->name]);
		}
	}

	public function append_defer_check_identifiers(IDeclaration $declaration)
	{
		if (!$declaration->defer_check_identifiers) {
			return;
		}

		$this->defer_check_identifiers = array_merge($this->defer_check_identifiers, $declaration->defer_check_identifiers);
	}
}

