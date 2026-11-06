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

/* * ***************************Includes********************************* */
require_once __DIR__ . '/../../../../core/php/core.inc.php';

class Alarmemyfox extends eqLogic {
	const PYTHON_PATH = __DIR__ .'/../../resources/venv/bin/python3';
	const NATIVE_COMMANDS = array(
		'arm_total'   => array('Armer total',   'action', 'other'),
		'arm_partial' => array('Armer partiel', 'action', 'other'),
		'disarm'      => array('Désarmer',      'action', 'other'),
		'etat'        => array('État alarme',   'info',   'string'),
		'etat1'       => array('Alarme armée',  'info',   'binary'),
        'Event_Last'  => array('Dernier événement', 'info', 'string'),
	);

	public static function dependancy_info() {

        $return = array();
        $return['log'] = log::getPathToLog(__CLASS__ . '_update');
        $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependance';
        $return['state'] = 'ok';

        if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependance')) {
            $return['state'] = 'in_progress';
        } elseif (!file_exists(self::PYTHON_PATH)) {
            $return['state'] = 'nok';
        } elseif (!self::pythonRequirementsInstalled(self::PYTHON_PATH, __DIR__ . '/../../resources/requirements.txt')) {
            $return['state'] = 'nok';
        }

        return $return;
    }

    public static function dependancy_install() {
        log::remove(__CLASS__ . '_update');
        return array('script' => dirname(__FILE__) . '/../../resources/install_dep.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependance',
                     'log' => log::getPathToLog(__CLASS__ . '_update'));
    }

    private static function pythonRequirementsInstalled(string $pythonPath, string $requirementsPath) {
		if (!file_exists($pythonPath) || !file_exists($requirementsPath)) {
			return false;
		}
		exec("{$pythonPath} -m pip freeze", $packages_installed);
		$packages = join("||", $packages_installed);
		exec("cat {$requirementsPath}", $packages_needed);
		foreach ($packages_needed as $line) {
			if (preg_match('/([^\s]+)[\s]*([>=~]=)[\s]*([\d+\.?]+)$/', $line, $need) === 1) {
				if (preg_match('/' . $need[1] . '==([\d+\.?]+)/i', $packages, $install) === 1) {
					if ($need[2] == '==' && $need[3] != $install[1]) {
						return false;
					} elseif (version_compare($need[3], $install[1], '>')) {
						return false;
					}
				} else {
					return false;
				}
			}
		}

		return true;
	}

	public static function deamon_info() {
		$return = array();
		$return['log'] = __CLASS__;
		$return['state'] = 'nok';

		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/myfox_local_server.pid';

		// $serverExist = count(system::ps('core/php/server.php')) > 0;
		if (file_exists($pid_file)) {
			if (@posix_getsid(trim(file_get_contents($pid_file)))) {
				$return['state'] = 'ok';
			} else {
				shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
			}
		}

        $return['launchable'] = 'ok';
		$return['last_launch'] = config::byKey('lastDeamonLaunchTime', __CLASS__, __('Inconnue', __FILE__));
		return $return;
	}

	public static function deamon_start() {
		self::backendStop();

        $daemonLogConfig = config::byKey('daemonLog', __CLASS__, 'parent');
		$daemonLog = ($daemonLogConfig == 'parent') ? log::getLogLevel(__CLASS__) : $daemonLogConfig;
        $parent_path = realpath(dirname(__FILE__) . '/../..'); // répertoire parent du démon
        $cmd = 'cd ' . escapeshellarg($parent_path) . ' && ' . self::PYTHON_PATH . ' resources/myfox_local_server.py';
		$cmd .= ' --web-port ' . config::byKey('webPort', __CLASS__, '8081');
		$cmd .= ' --backup-dir ' . __DIR__ . '/../../data ';
      	#$cmd .= ' --allow-host myfox.steff80.fr ';
		$cmd .= ' --data-dir ' . __DIR__ . '/../../data ';
		$cmd .= ' --log ' . __DIR__ . '/../../data/myfox_server.log ';
        $cmd .= ' --loglevel ' . log::convertLogLevel($daemonLog);
        $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, '58000');
        $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
        $cmd .= ' --callback ' . network::getNetworkAccess('internal') .'/plugins/Alarmemyfox/core/php/jeeAlarmemyfox.php';
        $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/myfox_local_server.pid';
		$myEqLogics = eqLogic::byType(__CLASS__);
        if (isset($myEqLogics[0])) {
            $cmd .= ' --jeedom-eqlogic-id ' . intval($myEqLogics[0]->getId());
            $cmd .= ' --jeedom-cycle ' . floatval(config::byKey('jeedomCycle', __CLASS__, '3'));
        }
		if (self::dnsEnabled()) {
			$cmd .= ' --dns --dns-ip ' . network::getNetworkAccess('internal', 'ip');
		}
        if (self::guardEnabled()) {
			$cmd .= ' --guard-port ' . intval(self::getGuardPort());
		} else {
			self::guardStop();
		}
        exec($cmd . ' >> ' . log::getPathToLog('Alarmemyfox_daemon') . ' 2>&1 &');

        $i = 0;
		while ($i < 10) {
			$deamon_info = self::deamon_info();
			if ($deamon_info['state'] == 'ok') {
				break;
			}
			sleep(1);
			$i++;
		}
		if ($i >= 10) {
			log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
			return false;
		}
		message::removeAll(__CLASS__, 'unableStartDeamon');
		if (self::guardEnabled()) {
			self::guardEnsureRunning();
		}
		// Cree/retablit automatiquement les commandes natives et les commandes dynamiques du tableau
		// "Equipements" (voir syncCommands() plus bas) sur chaque equipement du plugin, sans manipulation
		// manuelle en "mode avance". Idempotent (verifie l'existant par logicalId avant de creer) : sans
		// effet si elles existent deja.
		self::syncAllCommands();
		return true;
    }


	public static function deamon_stop() {
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/myfox_local_server.pid';
        if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			system::kill($pid);
		}
		system::kill('myfox_local_server.py');
		sleep(1);
	}

	public static function dnsEnabled() {
		$v = config::byKey('dnsEnable', 'Alarmemyfox');
		return ($v === '' || $v === null) ? false : (bool) $v;
	}

	public static function guardEnabled() {
		$v = config::byKey('guardEnable', 'Alarmemyfox');
		return ($v === '' || $v === null) ? false : (bool) $v;
	}

	public static function getGuardPort() {
		$v = config::byKey('guardPort', 'Alarmemyfox');
		return ($v !== '' && $v !== null) ? intval($v) : 18888;
	}

	public static function guardInfo() {
		$return = array();
		$return['state'] = 'nok';
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/myfox_tcp_guard.pid';
		if (file_exists($pid_file)) {
			if (@posix_getsid(trim(file_get_contents($pid_file)))) {
				$return['state'] = 'ok';
			} else {
				shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
			}
		}
		return $return;
	}

	public static function guardStop() {
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/myfox_tcp_guard.pid';
		if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			if ($pid > 0) {
				system::kill($pid);
			}
		}
		system::kill('myfox_tcp_guard.py');
	}

	public static function backendStop() {
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/myfox_local_server.pid';
        if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			system::kill($pid);
		}
		system::kill('myfox_local_server.py');
		sleep(1);
	}

	public static function guardEnsureRunning() {
		if (self::guardInfo()['state'] == 'ok') {
			return true;
		}
		$daemonLogConfig = config::byKey('daemonLog', __CLASS__, 'parent');
		$daemonLog = ($daemonLogConfig == 'parent') ? log::getLogLevel(__CLASS__) : $daemonLogConfig;
		$parent_path = realpath(dirname(__FILE__) . '/../..');
		$cmd = 'cd ' . escapeshellarg($parent_path) . ' && ' . self::PYTHON_PATH . ' resources/myfox_tcp_guard.py';
		$cmd .= ' --listen-port 8888'; // port de la centrale : voir note sur --port dans deamon_start() (jamais surcharge aujourd'hui)
		$cmd .= ' --backend-port ' . intval(self::getGuardPort());
		$cmd .= ' --log ' . __DIR__ . '/../../data/myfox_guard.log';
		$cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/myfox_tcp_guard.pid';
		exec($cmd . ' >> ' . log::getPathToLog(__CLASS__.'_daemon') . ' 2>&1 &');
		$i = 0;
		while ($i < 10) {
			if (self::guardInfo()['state'] == 'ok') {
				return true;
			}
			sleep(1);
			$i++;
		}
		log::add(__CLASS__, 'error', __('Impossible de lancer le garde TCP (myfox_tcp_guard.py), vérifiez le log', __FILE__), 'unableStartGuard');
		return false;
	}
	public static function restApiKey() {
		return config::byKey('restApiKey', __CLASS__, '');
	}

	public static function restApiBase() {
		// Toujours en local (127.0.0.1) : le tableau de bord HTTP (--web-port) tourne sur ce meme boitier
		// Jeedom, ce n'est PAS le port de la centrale (8888/garde) qui lui peut etre sur un autre reseau.
		return network::getNetworkAccess('internal') .':' . intval(config::byKey('webPort', __CLASS__, '8081'));
	}

	/* Miroir de _is_disarm_order() cote Python (myfox_local_server.py) : vrai uniquement pour "100|1". */
	public static function isDisarmOrder($order) {
		$p = explode('|', (string) $order);
		return isset($p[0]) && intval($p[0]) === 100 && isset($p[1]) && $p[1] !== '' && hexdec($p[1]) === 1;
	}

	public static function restApiCall($method, $path, $body = null) {
		$ch = curl_init(self::restApiBase() . $path);
		$headers = array('Content-Type: application/json');
		$key = self::restApiKey();
		if ($key !== '') {
			$headers[] = 'Authorization: Bearer ' . $key;
		}
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_HTTPHEADER => $headers,
		);
		if ($method === 'POST') {
			$opts[CURLOPT_POST] = true;
			$opts[CURLOPT_POSTFIELDS] = json_encode($body === null ? new stdClass() : $body);
		}
		curl_setopt_array($ch, $opts);
		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($errno) {
			log::add(__CLASS__, 'error', 'Appel API locale ' . $path . ' impossible : ' . $err);
			return null;
		}
		$json = json_decode((string) $response, true);
		if (!is_array($json)) {
			log::add(__CLASS__, 'error', 'Reponse API locale ' . $path . ' illisible (HTTP ' . $httpCode . ') : ' . substr((string) $response, 0, 200));
			return null;
		}
		if ($httpCode >= 400) {
			$msg = isset($json['error']) ? $json['error'] : ('HTTP ' . $httpCode);
			log::add(__CLASS__, 'error', 'Appel API locale ' . $path . ' refuse : ' . $msg);
		}
		return $json;
	}
	public function postSave() {
		$this->syncCommands();
	}

	public function syncCommands() {
		foreach (self::NATIVE_COMMANDS as $logicalId => $def) {
			list($name, $type, $subType) = $def;
			$command = $this->getCmd(null, $logicalId);
			if (!is_object($command)) {
				$command = new AlarmemyfoxCmd();
				$command->setLogicalId($logicalId);
				$command->setEqLogic_id($this->getId());
			}
			$command->setName($name);
			$command->setType($type);
			$command->setSubType($subType);
            if($logicalId == 'arm_partial'){
              $command->setDisplay('icon','<i style="color:orange;" class="icon jeedomapp-lock-partiel"></i>');
            }
          	if($logicalId == 'arm_total'){
              $command->setDisplay('icon','<i style="color:red;" class="icon jeedomapp-lock-ferme"></i>');
            }
          	if($logicalId == 'disarm'){
              $command->setDisplay('icon','<i style="color:rgb(47,236,66);" class="icon jeedomapp-lock-ouvert"></i>');
            }
			$command->setIsVisible(1);
			$command->save();
		}
		$this->syncEquipmentCommands();
	}

	public static function syncAllCommands() {
		foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
			if (method_exists($eqLogic, 'syncCommands')) {
				$eqLogic->syncCommands();
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * COMMANDES DYNAMIQUES DU TABLEAU "ÉQUIPEMENTS" (prises, modules, volets roulants) -- demande
	 * explicite de l'utilisateur le 6 novembre : une commande Jeedom par appareil REELLEMENT connu du
	 * demon (/api/state), pas une liste figee -- un appareil supprime cote centrale/tableau de bord voit
	 * sa commande retiree au prochain sync, un appareil nouvellement appaire voit la sienne apparaitre de
	 * la meme facon (postSave() et deamon_start()/syncAllCommands() ci-dessus appellent deja syncCommands()
	 * automatiquement ; un redemarrage du demon ou un enregistrement de l'equipement suffit donc a repercuter
	 * un changement cote centrale, sans toucher au code).
	 * --------------------------------------------------------------------- */

	// Recupere le tableau "actuators" de /api/state (memes appareils, memes champs que le tableau
	// "Equipements" du tableau de bord local -- voir out["actuators"] dans myfox_local_server.py). Renvoie
	// null (et non un tableau vide) si l'API est injoignable ou refuse la cle : syncEquipmentCommands() ne
	// doit alors RIEN modifier, pour ne pas effacer les commandes existantes a cause d'un demon simplement
	// pas encore demarre.
	public static function getEquipmentDevices() {
		$resp = self::restApiCall('GET', '/api/state');
		if (!is_array($resp) || !isset($resp['actuators']) || !is_array($resp['actuators'])) {
			return null;
		}
		return $resp['actuators'];
	}

	// Devmod 115 = volet roulant, 112 = prise ; le reste (120 = module 1000 W, 114 = module 3500 W...) est
	// traite comme un module ON/OFF simple -- meme logique que panel_group() cote Python
	// (myfox_local_server.py). Le type choisi par l'utilisateur (colonne "Type" du tableau de bord, champ
	// "ctype") prime sur le devmod, exactement comme cote tableau de bord (voir isShutter dans
	// renderActuators()).
	public static function isShutterDevice($dev) {
		$ctype = isset($dev['ctype']) ? $dev['ctype'] : '';
		if ($ctype === 'Volet roulant') {
			return true;
		}
		if ($ctype === 'Prise') {
			return false;
		}
		$devmod = isset($dev['devmod']) ? (string) $dev['devmod'] : '';
		$parts = explode('-', $devmod);
		return $parts[0] === '115';
	}

	public function syncEquipmentCommands() {
		$devices = self::getEquipmentDevices();
		if ($devices === null) {
			return;    // demon injoignable : on laisse les commandes d'equipements telles quelles
		}
		$keep = array();
		foreach ($devices as $dev) {
			if (!isset($dev['id'])) {
				continue;
			}
			$id = intval($dev['id']);
			$label = (isset($dev['name']) && $dev['name'] !== '') ? $dev['name'] : ('Appareil ' . $id);
			if (self::isShutterDevice($dev)) {
				$keep[] = $this->ensureEquipmentCmd('act_' . $id . '_up', $label . ' - Monter', '400|' . $id . '|2');
				$keep[] = $this->ensureEquipmentCmd('act_' . $id . '_down', $label . ' - Descendre', '400|' . $id . '|0');
				$keep[] = $this->ensureEquipmentCmd('act_' . $id . '_stop', $label . ' - Arrêter', 'act_stop:' . $id);
			} else {
				$keep[] = $this->ensureEquipmentCmd('act_' . $id . '_on', $label . ' - ON', '400|' . $id . '|2');
				$keep[] = $this->ensureEquipmentCmd('act_' . $id . '_off', $label . ' - OFF', '400|' . $id . '|0');
			}
		}
		// Retire les commandes "act_*" dont l'appareil n'existe plus -- jamais une commande native
		// (NATIVE_COMMANDS) ni une commande ajoutee a la main par l'utilisateur en mode avance (logicalId
		// different de ce schema).
		foreach ($this->getCmd() as $command) {
			$logicalId = $command->getLogicalId();
			if (strpos($logicalId, 'act_') === 0 && !in_array($logicalId, $keep, true)) {
				$command->remove();
			}
		}
	}

	private function ensureEquipmentCmd($logicalId, $name, $action) {
		$command = $this->getCmd(null, $logicalId);
		if (!is_object($command)) {
			$command = new AlarmemyfoxCmd();
			$command->setLogicalId($logicalId);
			$command->setEqLogic_id($this->getId());
		}
		$command->setName($name);
		$command->setType('action');
		$command->setSubType('other');
		$command->setIsVisible(1);
		$command->setConfiguration('equipmentOrder', $action);
		$command->save();
		return $logicalId;
	}

}

class AlarmemyfoxCmd extends cmd {

	// Alias pratiques -> code d'ordre reel (voir ORDER_REFERENCE "100" dans myfox_local_server.py --reference).
	// Si le logicalId d'une commande n'est pas dans ce tableau, il est utilise TEL QUEL comme ordre brut
	// (permet de creer d'autres commandes "100|2", "621|...", etc. sans toucher au code).
	const ORDER_ALIASES = array(
		'arm_total'   => '100|4',
		'arm_partial' => '100|2',
		'disarm'      => '100|1',
	);

	public static function convertLang($value) {

      	switch ($value) {
			case 'armed':
				return '<i style="color:var(--al-danger-color) !important; font-size: 30px;" class="icon jeedomapp-lock-ferme"></i>';
			case 'partial':
				return '<i style="color:var(--al-warning-color) !important; font-size: 30px;" class="icon jeedomapp-lock-partiel"></i>';
			case 'disarmed':
				return '<i style="color:var(--al-success-color) !important; font-size: 30px;" class="icon jeedomapp-lock-ouvert"></i>';
		}

	}

	public static function converbinary($value) {
		return ($value === '1') ? 0 : 1;
	}

	public function execute($_options = array()) {
		$logicalId = $this->getLogicalId();

		if ($this->getType() == 'info') {
			if ($logicalId == 'state') {
				$resp = Alarmemyfox::restApiCall('GET', '/api/state');
				if ($resp !== null && array_key_exists('seclev', $resp)) {
					$this->event($resp['seclev']);
				}
			}
			return;
		}

		// Commandes dynamiques du tableau "Equipements" (prises/modules/volets, voir
		// Alarmemyfox::syncEquipmentCommands()) : logicalId "act_<id>_on/off/up/down/stop", ordre reel
		// range dans la configuration de la commande (champ "equipmentOrder") plutot que dans
		// ORDER_ALIASES, puisqu'il depend de l'appareil et change a chaque sync.
		if (strpos($logicalId, 'act_') === 0) {
			$this->executeEquipmentCommand($logicalId);
			return;
		}

		$order = isset(self::ORDER_ALIASES[$logicalId]) ? self::ORDER_ALIASES[$logicalId] : $logicalId;
		$body = array('order' => $order);
		if (Alarmemyfox::isDisarmOrder($order)) {
			// Confirmation obligatoire cote API (voir _is_disarm_order()/confirm dans myfox_local_server.py) --
			// pas de mot de passe ici : disarm_needs_password() est ignore quand l'appel porte une cle API
			// (voir "not self._apikey" cote serveur), et c'est justement le mode utilise par ce plugin.
			$body['confirm'] = true;
		}

		$resp = Alarmemyfox::restApiCall('POST', '/api/order', $body);
		if ($resp === null || empty($resp['ok'])) {
			$msg = (is_array($resp) && isset($resp['message'])) ? $resp['message'] : 'pas de reponse';
			log::add('Alarmemyfox', 'error', 'Commande "' . $logicalId . '" (ordre ' . $order . ') refusee : ' . $msg);
		}
	}

	/* "act_<id>_up"/"_down"/"_on"/"_off" portent un ordre brut ("400|<id>|2"/"400|<id>|0") dans leur
	 * configuration "equipmentOrder" : envoi direct, comme les commandes natives. "act_<id>_stop" (volets
	 * roulants uniquement) porte le marqueur "act_stop:<id>" : la centrale Myfox n'a pas d'ordre "arreter"
	 * dedie -- renvoyer le DERNIER ordre Monter/Descendre arrete le volet en cours de mouvement (meme
	 * principe que shutterStop() cote tableau de bord, voir myfox_local_server.py) ; on retient donc la
	 * derniere direction envoyee pour chaque appareil dans le cache Jeedom (expire apres 24 h : au-dela,
	 * un volet qui serait encore en train de bouger serait de toute facon anormal). */
	private function executeEquipmentCommand($logicalId) {
		$action = $this->getConfiguration('equipmentOrder');
		if (strpos($action, 'act_stop:') === 0) {
			$id = substr($action, strlen('act_stop:'));
			$dir = cache::byKey('Alarmemyfox_actDir_' . $id)->value('');
			if ($dir === '') {
				log::add('Alarmemyfox', 'warning', 'Arrêt demandé pour l\'appareil ' . $id
					. ' sans ordre Monter/Descendre connu depuis le démarrage du démon : rien envoyé.');
				return;
			}
			$order = '400|' . $id . '|' . $dir;
		} else {
			$order = $action;
			if (preg_match('/^400\|(\d+)\|(\d+)$/', $order, $m)) {
				cache::set('Alarmemyfox_actDir_' . $m[1], $m[2], 86400);
			}
		}
		$resp = Alarmemyfox::restApiCall('POST', '/api/order', array('order' => $order));
		if ($resp === null || empty($resp['ok'])) {
			$msg = (is_array($resp) && isset($resp['message'])) ? $resp['message'] : 'pas de reponse';
			log::add('Alarmemyfox', 'error', 'Commande "' . $logicalId . '" (ordre ' . $order . ') refusee : ' . $msg);
		}
	}
}
?>
