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
?>

<form class="form-horizontal">
    <fieldset>
        <div class="form-group">
			<div class="col-md-2"></div>
  				<div class="col-sm-4">
            		<div class="alert alert-info">{{Après un changement ici, redémarrez le démon pour qu'il en tienne compte.}}</div>
             	</div>
       	</div>
        <legend>{{Démon Myfox Local}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Port web}}</label>
            <div class="col-md-1">
                <input type="number" class="configKey form-control" data-l1key="webPort" placeholder="8081"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{MyFox Local}}</label>
			<div class="col-md-3">
				<a class="btn btn-danger controller_action" target="_blank" href="<?php echo network::getNetworkAccess('internal') ?>:<?php echo config::byKey('webPort','Alarmemyfox', '8081') ?>" title=""><i class="fas fa-external-link-square-alt "></i> {{Interface MyFox Local}}</a></td>
			</div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Serveur DNS intégré}}
                <sup>
                    <i class="fas fa-question-circle floatright" title="Si vous avez installé un serveur DNS exemple (Pi-hole/dnsmasq) ne pas cocher cette case."></i>
                </sup>
            </label>
            <div class="col-md-3">
                <label class="switch switch-blue">
                    <input type="checkbox" class="configKey" data-l1key="dnsEnable"/>
                    <span class="switch-indicator"></span>
                </label>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Garde TCP}}
                <sup>
                    <i class="fas fa-question-circle floatright" title="Garde TCP évite la déconnexion de la centrale d'alarme à chaque redémarrage du démon."></i>
                </sup>
            </label>
            <div class="col-md-3">
                <label class="switch switch-blue">
                    <input type="checkbox" class="configKey" data-l1key="guardEnable"/>
                    <span class="switch-indicator"></span>
                </label>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Port interne du démon}}
                <sup>
                    <i class="fas fa-question-circle floatright" title="Ce port sert à faire communiquer le garde TCP et le démon uniquement."></i>
                </sup>
            </label>
            <div class="col-md-1">
                <input type="number" class="configKey form-control" data-l1key="guardPort" placeholder="18888"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Clé API locale Myfox}}
                <sup>
                    <i class="fas fa-question-circle floatright" title="Créez cette clé depuis le tableau de bord MyFox Local (onglet Sécurité -> Clés d'intégration), collez-la ici."></i>
                </sup>
            </label>
            <div class="col-md-3">
                <input type="text" class="configKey form-control" data-l1key="restApiKey" placeholder="mfx_..."/>
            </div>
        </div>
    </fieldset>
</form>

<script>
function alarmemyfox_toggleGuardPort() {
    var enabled = $('.configKey[data-l1key="guardEnable"]').is(':checked');
    $('.configKey[data-l1key="guardPort"]').prop('disabled', !enabled);
}
$(function() {
    alarmemyfox_toggleGuardPort();
    $(document).on('change', '.configKey[data-l1key="guardEnable"]', alarmemyfox_toggleGuardPort);
});
</script>
