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
	public $defer_check_identifiers = [];

	function set_defer_check_identifier(Identifiable $identifiable)
	{
		$this->defer_check_identifiers[$identifiable->name] = $identifiable;
	}

	function remove_defer_check_identifier(Identifiable $identifiable)
	{
		if (isset($this->defer_check_identifiers[$identifiable->name])) {
			unset($this->defer_check_identifiers[$identifiable->name]);
		}
	}
}


