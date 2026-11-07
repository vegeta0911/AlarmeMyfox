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


function Alarmemyfox_install() {
}

function Alarmemyfox_update() {
}

function Alarmemyfox_remove() {

	// arrete proprement le processus myfox_local_server.py (sauvegarde sa base avant de quitter) pour ne
	// pas le laisser tourner orphelin apres la desinstallation du plugin.
	try {
		Alarmemyfox::deamon_stop();
	} catch (Exception $e) {
		log::add('Alarmemyfox', 'debug', 'deamon_stop a la desinstallation : ' . $e->getMessage());
	}

    $cron = cron::byClassAndFunction('Alarmemyfox', 'maj');
    if (is_object($cron)) {
        $cron->remove();
    }
	 $cron = cron::byClassAndFunction('Alarmemyfox', 'pull');
    if (is_object($cron)) {
        $cron->remove();
    }
}
?>
