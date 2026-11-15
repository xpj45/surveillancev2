<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function surveillancev2_install() {

}

function surveillancev2_update() {

	foreach (eqLogic::byType('surveillancev2') as $surveillancev2) {
		try {
			$surveillancev2->save();
		} catch (\Throwable $e) {
			log::add('surveillancev2', 'error', $surveillancev2->getHumanName() . ' : ' . $e->getMessage());
		}
	}

}

function surveillancev2_remove() {
    
}
