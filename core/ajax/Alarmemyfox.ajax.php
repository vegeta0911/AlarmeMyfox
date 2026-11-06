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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

  /* Fonction permettant l'envoi de l'entête 'Content-Type: application/json'
    En V3 : indiquer l'argument 'true' pour contrôler le token d'accès Jeedom
    En V4 : autoriser l'exécution d'une méthode 'action' en GET en indiquant le(s) nom(s) de(s) action(s) dans un tableau en argument
  */
    ajax::init();

    if (init('action') == 'testCommand') {
        // Bouton "Tester" de la page de configuration : declenche un ordre reel (ou lit l'etat) via la
        // meme API REST locale que AlarmemyfoxCmd::execute(), pour verifier la cle et le demon sans
        // attendre la creation/le declenchement d'une vraie commande Jeedom.
        $logicalId = init('logicalId');
        if ($logicalId == 'state') {
            $resp = Alarmemyfox::restApiCall('GET', '/api/state');
            if ($resp === null) {
                throw new Exception('Appel API locale impossible (voir le log du plugin)');
            }
            ajax::success(array('seclev' => array_key_exists('seclev', $resp) ? $resp['seclev'] : null, 'raw' => $resp));
        } else {
            $order = isset(AlarmemyfoxCmd::ORDER_ALIASES[$logicalId]) ? AlarmemyfoxCmd::ORDER_ALIASES[$logicalId] : $logicalId;
            $body = array('order' => $order);
            if (Alarmemyfox::isDisarmOrder($order)) {
                $body['confirm'] = true;
            }
            $resp = Alarmemyfox::restApiCall('POST', '/api/order', $body);
            if ($resp === null) {
                throw new Exception('Appel API locale impossible (voir le log du plugin)');
            }
            ajax::success($resp);
        }
    }


    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
    /*     * *********Catch exeption*************** */
}
catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
