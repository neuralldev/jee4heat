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
        throw new Exception(__('401 - {{Accès non autorisé}}', __FILE__));
    }
    log::add('jee4heat', 'debug', ' action request =  '.init('action'));
/*
    if (init('action') == 'getStove') {
        $stove = jee4heat::byId(init('id'));
        if (!is_object($stove)) {
            throw new Exception(__('Plugin inconnu vérifier l\'id', __FILE__));
        }
        $return = utils::o2a($stove);
        $return['cmd'] = array();
        foreach ($stove->getCmd() as $cmd) {
            $cmd_info = utils::o2a($cmd);
            $cmd_info['value'] = $cmd->execCmd(null, 0);
            $return['cmd'][] = $cmd_info;
        }
        ajax::success($return);
    }
*/

    throw new Exception(__('{{Aucune méthode correspondante à}} : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
} catch (Exception $e) {
    if (version_compare(jeedom::version(), '4.4', '>=')) {
        ajax::error(displayException($e), $e->getCode());
    } else {
        ajax::error(displayException($e), $e->getCode());
    }
}
