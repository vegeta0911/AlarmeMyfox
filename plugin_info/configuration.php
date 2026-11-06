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
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', 'Alarmemyfox', '404', 'php');
    die();
}

require_once dirname(__FILE__) . '/../core/class/Alarmemyfox.class.php';
$dnsEnabled = Alarmemyfox::dnsEnabled();
$guardEnabled = Alarmemyfox::guardEnabled();
$guardRunning = Alarmemyfox::guardInfo()['state'] == 'ok';
?>


<form class="form-horizontal">
    <fieldset>
        <legend>{{Démon Myfox Local}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Port du tableau de bord / API locale}}</label>
            <div class="col-lg-2">
                <input type="number" class="configKey form-control" data-l1key="webPort" placeholder="8081"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{MyFox Local}}</label>
			<div class="col-md-3">
				<a class="btn btn-danger controller_action" target="_blank" href="http://<?php echo network::getNetworkAccess('internal', 'ip') ?>:<?php echo config::byKey('webPort','Alarmemyfox', '8081') ?>" title=""><i class="fas fa-external-link-square-alt "></i> {{Interface MyFox Local}}</a></td>
			</div>
        </div>
        <div class="form-group">
            <div class="help-block">
                {{Après un changement ici, redémarrez le démon pour qu'il en tienne compte.}}
                {{Rappel réseau : redirigez le nom DNS core.steadyfox.com vers l'adresse de ce boîtier Jeedom pour que la centrale parle à ce script (voir section 4 de sa documentation, --reference).}}
            </div>
        </div>
    </fieldset>
    <fieldset>
        <legend>{{Serveur DNS intégré (alternative à Pi-hole/dnsmasq)}}</legend>
        <div class="alert <?= $dnsEnabled ? 'alert-success' : 'alert-warning' ?>">
            <?php if ($dnsEnabled) { ?>
                {{Actif : myfox_local_server.py redirige lui-même core.steadyfox.com/hc2.steadyfox.com vers ce boîtier. Nécessite les droits root sur le boîtier (port 53) et que le réseau utilise ce boîtier comme serveur DNS.}}
            <?php } else { ?>
                {{Désactivé : utilisez Pi-hole/dnsmasq/votre routeur pour rediriger core.steadyfox.com (voir section 4 de --reference), ou activez cette option une fois le réseau prêt.}}
            <?php } ?>
        </div>
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Activer}}</label>
            <div class="col-lg-2">
                <label class="switch switch-blue">
                    <input type="checkbox" class="configKey" data-l1key="dnsEnable" <?= $dnsEnabled ? 'checked' : '' ?> />
                    <span class="switch-indicator"></span>
                </label>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-3 control-label">{{IP renvoyée (optionnel)}}</label>
            <div class="col-lg-3">
                <input type="text" class="configKey form-control" data-l1key="dnsIp"
                       placeholder="<?= htmlspecialchars(network::getNetworkAccess('internal', 'ip')) ?>"/>
                <span class="help-block">{{Vide = IP de ce boîtier Jeedom.}}</span>
            </div>
        </div>
    </fieldset>
    <fieldset>
        <legend>{{Garde TCP permanent (évite la déconnexion de la centrale à chaque redémarrage du démon)}}</legend>
        <div class="alert <?= $guardEnabled ? ($guardRunning ? 'alert-success' : 'alert-warning') : 'alert-warning' ?>">
            <?php if ($guardEnabled) { ?>
                <?php if ($guardRunning) { ?>
                    {{Actif et en cours d'exécution : un process séparé (myfox_tcp_guard.py) tient la connexion de la centrale en continu. Redémarrer le démon ne la déconnecte plus.}}
                <?php } else { ?>
                    {{Activé mais pas démarré pour l'instant : redémarrez le démon pour le lancer.}}
                <?php } ?>
            <?php } else { ?>
                {{Désactivé (comportement actuel) : redémarrer le démon coupe la connexion TCP de la centrale. Observation du 1er novembre : elle ne se reconnecte pas seule, il faut couper son secteur quelques secondes.}}
            <?php } ?>
        </div>
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Activer}}</label>
            <div class="col-lg-2">
                <label class="switch switch-blue">
                    <input type="checkbox" class="configKey" data-l1key="guardEnable" <?= $guardEnabled ? 'checked' : '' ?> />
                    <span class="switch-indicator"></span>
                </label>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Port interne du démon (optionnel)}}</label>
            <div class="col-lg-2">
                <input type="number" class="configKey form-control" data-l1key="guardPort" placeholder="18888"/>
                <span class="help-block">{{Entre le garde et le démon uniquement (127.0.0.1) -- jamais exposé à la centrale.}}</span>
            </div>
        </div>
        <div class="help-block">
            {{Fonctionnement : le garde écoute en permanence le port de la centrale (8888) et relaie vers le démon, qui n'écoute plus qu'en local. Quand le démon redémarre, le garde se rattache seul au nouveau process sans jamais couper la centrale -- en cas d'échec du rattachement (démon indisponible trop longtemps, réponse inattendue...), il referme la connexion proprement : jamais pire qu'aujourd'hui, juste pas pire.}}
            {{Après avoir activé cette option, redémarrez le démon une première fois.}}
        </div>
    </fieldset>
    <fieldset>
        <legend>{{Commandes natives (armer / désarmer / état)}}</legend>
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Clé API locale (Bearer)}}</label>
            <div class="col-lg-4">
                <input type="text" class="configKey form-control" data-l1key="restApiKey" placeholder="mfx_..."/>
            </div>
        </div>
        <div class="help-block">
            {{Créez cette clé depuis le tableau de bord MyFox Local (onglet Sécurité -> Clés d'intégration), collez-la ici.}}
            {{Puis, sur cet équipement, créez (en mode avancé) une commande action avec le logicalId <code>arm_total</code> (armer total), <code>arm_partial</code> (armer partiel), <code>disarm</code> (désarmer), et/ou une commande info numérique avec le logicalId <code>state</code> (niveau d'alarme courant : 1 désarmé, 2 partiel, 4 total).}}
        </div>
    </fieldset>
</form>
