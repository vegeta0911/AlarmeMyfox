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
	 $cron = cron::byClassAndFunction('Alarmemyfox', 'maj');
  if (is_object($cron)) {
      $cron->remove();
  }
}

function Alarmemyfox_update() {
	 $cron = cron::byClassAndFunction('Alarmemyfox', 'maj');
  if (is_object($cron)) {
      $cron->remove();
	   
  }
    $cronP = cron::byClassAndFunction('Alarmemyfox', 'pull');
	  if (!is_object($cronP)) {
				$cronP = new cron();
				$cronP->setClass('Alarmemyfox');
				$cronP->setFunction('pull');
				$cronP->setOption(array('Alarmemyfox_id' => intval($this->getId())));
				$cronP->setLastRun(date('Y-m-d H:i:s'));
				$cronP->setEnable(1);
				$cronP->setDeamon(1);
				$cronP->setTimeout('30');
				$cronP->setSchedule('* * * * *');
				$cronP->save();
				log::add('Alarmemyfox', 'debug', 'addCron');
		  
		  
	  }
}

function Alarmemyfox_remove() {
	
    $cron = cron::byClassAndFunction('Alarmemyfox', 'maj');
    if (is_object($cron)) {
        $cron->remove();
    }
	 $cron = cron::byClassAndFunction('Alarmemyfox', 'pull');
    if (is_object($cron)) {
        $cron->remove();
    }
}
}
?>
