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
require_once __DIR__ . '/../../../../core/php/core.inc.php';

class jee4heat extends eqLogic {

  public static function pull() {
		foreach (eqLogic::byType('jee4heat', true) as $jee4heat) {
			$jee4heat->getInformations();
			$j4 = cache::byKey('jee4heatWidgetmobile' . $jee4heat->getId());
			$j4->remove();
			$j4 = cache::byKey('jee4heatWidgetdashboard' . $jee4heat->getId());
			$j4->remove();
			$jee4heat->toHtml('mobile');
			$jee4heat->toHtml('dashboard');
			$jee4heat->refreshWidget();
		}
	}

public static function cron() {
    foreach (eqLogic::byType(__CLASS__, true) as $jee4heat) {
      if (($jeetype = $jee4heat->getConfiguration('modele')) != '') {
        /* pull depuis poele ici */
          $ip = $jee4heat->getConfiguration('modele');
          log::add(__CLASS__, 'debug', "IP du poele=".$ip);
          log::add(__CLASS__, 'debug', "modele=".$jeetype);         
          $jee4heat->refreshWidget();
      }
    }
  }

  public function getInformations() {
      if ($this->getConfiguration('modele') != '') {
        $this->getStove($this->getConfiguration('modele'));
        /* pull depuis poele ici */
      }
      $this->refreshWidget();
  }

  public function loadCmdFromConf($_update = false) {

    if (!is_file(__DIR__ . '/../config/devices/' . $this->getConfiguration('modele') . '.json')) {
      return;
    }
    $content = file_get_contents(__DIR__ . '/../config/devices/' . $this->getConfiguration('modele') . '.json');
    if (!is_json($content)) {
      return;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      return true;
    }
    foreach ($device['commands'] as $command) {
      $cmd = null;
      foreach ($this->getCmd() as $liste_cmd) {
        if ((isset($command['logicalId']) && $liste_cmd->getLogicalId() == $command['logicalId'])
        || (isset($command['name']) && $liste_cmd->getName() == $command['name'])) {
          $cmd = $liste_cmd;
          break;
        }
      }
      try {
        if ($cmd == null || !is_object($cmd)) {
          $cmd = new jee4heatCmd();
          $cmd->setEqLogic_id($this->getId());
        } else {
          $command['name'] = $cmd->getName();
          if (isset($command['display'])) {
            unset($command['display']);
          }
        }
        utils::a2o($cmd, $command);
        $cmd->setConfiguration('logicalId', $cmd->getLogicalId());
        $cmd->save();
        if (isset($command['value'])) {
          $link_cmds[$cmd->getId()] = $command['value'];
        }
        if (isset($command['configuration']) && isset($command['configuration']['updateCmdId'])) {
          $link_actions[$cmd->getId()] = $command['configuration']['updateCmdId'];
        }
      } catch (Exception $exc) {

      }
    }
  }

  public function postAjax() {
    $this->loadCmdFromConf();
  }

  public function postUpdate() {
  }

  public function getjee4heat() {
  /* c'est là qu'on appelle les API */
    
    log::add(__CLASS__, 'debug', 'received' . "");
    $this->checkAndUpdateCmd('jee4heat', "");
  }
}

class jee4heatCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getLogicalId() == 'refresh') {
      $this->getEqLogic()->getInformations();
    }
  }
}
