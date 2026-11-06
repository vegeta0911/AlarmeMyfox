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

/* Callback du daemon Jeedom natif (voir "INTEGRATION DAEMON JEEDOM NATIF" dans myfox_local_server.py).
 * myfox_local_server.py POSTe ici (via jeedom_com.add_changes(), module jeedom.py) a chaque changement
 * d'etat, avec en ?apikey= la cle passee a son lancement (--jeedom-apikey, voir
 * Alarmemyfox::deamon_start()). Le corps JSON a la forme :
 *     { "<eqLogic_id>": { "cmd": { "<logicalId>": <valeur>, ... },
 *                          "name": { "<logicalId>": "<nom lisible>", ... } (optionnel, voir plus bas) } }
 * -- une entree par equipement Jeedom concerne (en pratique un seul, cf. --jeedom-eqlogic-id). */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/Alarmemyfox.class.php';

// IMPORTANT (trouve le 4 novembre) : isConnect('apikey') verifie la requete contre la cle API GLOBALE de
// Jeedom (reglages systeme), PAS contre la cle SPECIFIQUE au plugin que deamon_start() genere et passe au
// demon via --apikey (jeedom::getApiKey('Alarmemyfox')). Resultat : CHAQUE push etait rejete ici (mais
// repondait quand meme HTTP 200 avec ce JSON d'erreur, donc invisible cote Python -- aucune erreur loggee
// la-bas), et la commande $cmd->event() n'etait donc JAMAIS appelee : "etat"/"etat1" restaient vides pour
// toujours, meme avec l'integration active et le push parti correctement. On verifie desormais la cle
// explicitement contre celle du plugin, la seule que le demon connaisse.
if (!isset($_GET['apikey']) || $_GET['apikey'] !== jeedom::getApiKey('Alarmemyfox')) {
    echo json_encode(array('state' => 'error', 'result' => __('Erreur d\'identification Jeedom', __FILE__)));
    die();
}

// IMPORTANT (trouve le 4 novembre, juste apres le correctif de la cle ci-dessus) : jeedom.py poste avec
// requests.post(url, json=change, ...) -- un corps JSON BRUT (Content-Type: application/json), PAS des
// champs de formulaire. jeedom::post() (pensee pour lire des champs $_POST classiques) plantait ici avec
// une erreur fatale (page 500 vide, voir le log : "return code 500"), chaque envoi une fois l'authentification
// corrigee. On lit donc directement le corps brut de la requete.
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
    // Noms lisibles optionnels (ex. "temperature12" -> "Fenêtre cuisine"), pousses a part par le demon --
    // voir le commentaire plus haut et "names[k] = s['name']" dans pusher() cote myfox_local_server.py.
    // Utilises UNIQUEMENT pour NOMMER une commande si elle vient d'etre creee ci-dessous ; jamais pour
    // choisir le logicalId, qui doit rester stable meme si l'appareil est renomme plus tard.
    $names = (isset($eqLogic_data['name']) && is_array($eqLogic_data['name'])) ? $eqLogic_data['name'] : array();

    foreach ($eqLogic_data['cmd'] as $logicalId => $value) {
        $cmd = $eqLogic->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            // Auto-creation (demande le 4 novembre) : avant, toute commande inconnue (capteurs de
            // temperature dynamiques "temperatureN", "Event_Last"...) etait silencieusement ignoree --
            // seules les commandes listees dans Alarmemyfox::NATIVE_COMMANDS existaient. On la cree ici a
            // la volee, avec un nom lisible si le demon en a fourni un (voir $names ci-dessus), pour ne
            // plus avoir a la creer a la main et eviter d'afficher "temperature12" au lieu du nom du capteur.
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
        } elseif (isset($names[$logicalId]) && $names[$logicalId] !== '' && $cmd->getName() != $names[$logicalId]
                  && strpos($cmd->getLogicalId(), 'temperature') === 0) {
            // Capteur renomme cote tableau de bord depuis sa creation : on suit le changement (uniquement
            // pour les commandes auto-creees/dynamiques -- jamais sur etat/etat1/arm_total/... dont le nom
            // est volontairement fixe par Alarmemyfox::NATIVE_COMMANDS).
            $cmd->setName($names[$logicalId]);
            $cmd->save();
        }
        // "etat"/"etat1" arrivent en valeur BRUTE depuis Python (statusLabel "armed"/"partial"/"disarmed",
        // seclev "1"/"2"/"4") -- la mise en forme (icone HTML / binaire) reste ici, comme avant dans
        // AlarmemyfoxCmd::execute() (voir ORDER_REFERENCE "100" et ce fichier pour la convention).
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
