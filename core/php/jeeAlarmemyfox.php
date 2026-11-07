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

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/Alarmemyfox.class.php';

if (!isset($_GET['apikey']) || $_GET['apikey'] !== jeedom::getApiKey('Alarmemyfox')) {
    echo json_encode(array('state' => 'error', 'result' => __('Erreur d\'identification Jeedom', __FILE__)));
    die();
}

$content = json_decode(file_get_contents('php://input'), true);
if (!is_array($content)) {
    echo json_encode(array('state' => 'error', 'result' => 'invalid body'));
    die();
}

foreach ($content as $eqLogic_id => $eqLogic_data) {
    $eqLogic = eqLogic::byId($eqLogic_id);
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'Alarmemyfox') {
        continue;
    }
    if (!isset($eqLogic_data['cmd']) || !is_array($eqLogic_data['cmd'])) {
        continue;
    }
    
    $names = (isset($eqLogic_data['name']) && is_array($eqLogic_data['name'])) ? $eqLogic_data['name'] : array();

    foreach ($eqLogic_data['cmd'] as $logicalId => $value) {
        $cmd = $eqLogic->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new AlarmemyfoxCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setType('info');
            if (strpos($logicalId, 'temperature') === 0) {
                $cmd->setSubType('numeric');
                $cmd->setUnite('°C');
                $cmd->setName(isset($names[$logicalId]) && $names[$logicalId] !== '' ? $names[$logicalId] : $logicalId);
            } elseif ($logicalId == 'Event_Last') {
                $cmd->setSubType('string');
                $cmd->setName('Dernier événement');
            } else {
                $cmd->setSubType('string');
                $cmd->setName(isset($names[$logicalId]) && $names[$logicalId] !== '' ? $names[$logicalId] : $logicalId);
            }
            $cmd->setIsVisible(1);
            $cmd->save();
        }
        if ($logicalId == 'etat') {
            $value = AlarmemyfoxCmd::convertLang($value);
        } elseif ($logicalId == 'etat1') {
            $value = AlarmemyfoxCmd::converbinary($value);
        }
        if ($value === null || $value === '') {
            continue;
        }
        $cmd->event($value);
    }
}

echo json_encode(array('state' => 'ok'));
