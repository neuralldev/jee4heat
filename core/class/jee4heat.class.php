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
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class jee4heat extends eqLogic {

  public static function deadCmd()
  {
      $return = array();
      foreach (eqLogic::byType('jee4heat') as $jee4heat) {
          foreach ($jee4heat->getCmd() as $cmd) {
              preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('modele', ''), $matches);
              foreach ($matches[1] as $cmd_id) {
                  if (!cmd::byId(str_replace('#', '', $cmd_id))) {
                      $return[] = array('detail' => __('jee4heat', __FILE__) . ' ' . $jee4heat->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
                  }
              }
              preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('ip', ''), $matches);
              foreach ($matches[1] as $cmd_id) {
                  if (!cmd::byId(str_replace('#', '', $cmd_id))) {
                      $return[] = array('detail' => __('jee4heat', __FILE__) . ' ' . $jee4heat->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('IP', __FILE__), 'who' => '#' . $cmd_id . '#');
                  }
              }
          }
      }
      return $return;
  }

  public static function cron() {
    foreach (eqLogic::byType(__CLASS__, true) as $jee4heat) {
      if ($jee4heat->getIsEnable()) {
        if (($modele = $jee4heat->getConfiguration('modele')) != '') {
        /* pull depuis poele ici */
          $ip = $jee4heat->getConfiguration('ip');
          $id = $jee4heat->getId();
          log::add(__CLASS__, 'debug', "cron : ID=".$id);
          log::add(__CLASS__, 'debug', "cron : IP du poele=".$ip);
          log::add(__CLASS__, 'debug', "cron : modele=".$modele);         
                
          if ($id==0) {
            $modele = $jee4heat->getConfiguration('modele');
            $ip = $jee4heat->getConfiguration('ip');
          }
          if ($jee4heat->getConfiguration('modele') != '') {
              /* pull depuis poele ici */
              $fp = fsockopen($ip, 80, $errno, $errstr,10);
              if (!$fp) {
                log::add(__CLASS__, 'debug', 'error opening socket on '.$ip);
                log::add(__CLASS__, 'debug', 'error ='.$errno.' / '.$errstr);
              } else {
                // query status
                $query ='["SEL","0"]';
                if (fwrite($fp, $query) > 0) {
                $stove_return = fread($fp, 4096);
                fclose($fp);
                } else {
                  log::add(__CLASS__, 'debug', 'not able to write on socket');
                }
                log::add(__CLASS__, 'debug', 'socket has returned ='.$stove_return);
                }
            
          }
        }
      }
    }
  }

  public function AddCommand($Name, $_logicalId, $Type = 'info', $SubType = 'binary', $Template = null, $unite = null, $generic_type = null, $IsVisible = 1, $icon = 'default', $forceLineB = 'default', $valuemin = 'default', $valuemax = 'default', $_order = null, $IsHistorized = '0', $repeatevent = false, $_iconname = null, $_calculValueOffset = null, $_historizeRound = null, $_noiconname = null)
  {
    $Command = $this->getCmd(null, $_logicalId);
      if (!is_object($Command)) {
          log::add(__CLASS__, 'debug', ' add record for '.$Name);
          $Command = new jee4heatCmd();
          $Command->setId(null);
          $Command->setLogicalId($_logicalId);
          $Command->setEqLogic_id($this->getId());
          $Command->setName($Name);
          $Command->setType($Type);
          $Command->setSubType($SubType);

          if ($Template != null) {
              $Command->setTemplate('dashboard', $Template);
              $Command->setTemplate('mobile', $Template);
          }

          if ($unite != null && $SubType == 'numeric') {
              $Command->setUnite($unite);
          }

          $Command->setIsVisible($IsVisible);
          $Command->setIsHistorized($IsHistorized);

          if ($icon != 'default') {
              $Command->setdisplay('icon', '<i class="' . $icon . '"></i>');
          }
          if ($forceLineB != 'default') {
              $Command->setdisplay('forceReturnLineBefore', 1);
          }
          if ($_iconname != 'default') {
              $Command->setdisplay('showIconAndNamedashboard', 1);
          }
          if ($_noiconname != null) {
              $Command->setdisplay('showNameOndashboard', 0);
          }

          if ($_calculValueOffset != null) {
              $Command->setConfiguration('calculValueOffset', $_calculValueOffset);
          }

          if ($_historizeRound != null) {
              $Command->setConfiguration('historizeRound', $_historizeRound);
          }
          if ($generic_type != null) {
              $Command->setGeneric_type($generic_type);
          }

          if ($repeatevent == true && $Type == 'info') {
              $Command->setconfiguration('repeatEventManagement', 'never');
          }
          if ($valuemin != 'default') {
              $Command->setconfiguration('minValue', $valuemin);
          }
          if ($valuemax != 'default') {
              $Command->setconfiguration('maxValue', $valuemax);
          }

          if ($_order != null) {
              $Command->setOrder($_order);
          }
          $Command->save();
      }

     /*  log::add(__CLASS__, 'debug', ' check for refresh');

      $createRefreshCmd = true;
      $refresh = $this->getCmd(null, 'refresh');
      if (!is_object($refresh)) {
          $refresh = cmd::byEqLogicIdCmdName($this->getId(), __('Rafraichir', __FILE__));
          if (is_object($refresh)) {
            log::add(__CLASS__, 'debug', ' refresh already created');
            $createRefreshCmd = false;
          }
      }
      if ($createRefreshCmd) {
        log::add(__CLASS__, 'debug', ' create refresh');
        if (!is_object($refresh)) {
              $refresh = new jee4heatCmd();
              $refresh->setLogicalId('refresh');
              $refresh->setIsVisible(1);
              $refresh->setName(__('Rafraichir', __FILE__));
          }
          $refresh->setType('action');
          $refresh->setSubType('other');
          $refresh->setEqLogic_id($this->getId());
          $refresh->save();
      } */
      log::add(__CLASS__, 'debug', ' addcommand end');
      return $Command;
  }

  public function refresh()
  {
      foreach ($this->getCmd() as $cmd) {
          $s = print_r($cmd, 1);
          log::add(__CLASS__, 'debug', 'refresh  cmd: ' . $s);
          $cmd->execute();
      }
  }

  public function postSave() {
    log::add(__CLASS__, 'debug', 'postsave start');

    $_eqName = $this->getName();
    log::add(__CLASS__, 'debug', 'Sauvegarde de l\'équipement [postSave()] : ' . $_eqName);
    $order = 1;

    if (!is_file(__DIR__ . '/../config/devices/' . $this->getConfiguration('modele') . '.json')) {
      log::add(__CLASS__, 'debug', 'postsave no file found for '._eqName.', then do nothing');
      return;
    }
    $content = file_get_contents(__DIR__ . '/../config/devices/' . $this->getConfiguration('modele') . '.json');
    if (!is_json($content)) {
      log::add(__CLASS__, 'debug', 'postsave content is not json '.$this->getConfiguration('modele'));
      return;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      log::add(__CLASS__, 'debug', 'postsave array cannot be decoded ');
      return true;
    }
    $Equipement = eqlogic::byId($this->getId());
    $order = 0;
    log::add(__CLASS__, 'debug', 'postsave add commands on ID '.$this->getId());
    foreach ($device['commands'] as $item) {
      log::add(__CLASS__, 'debug', 'postsave found commands array name='.json_encode($item));
      $Equipement->AddCommand($item['name'], $item['logicalId'], $item['type'], $item['subtype'], 'line', '', '', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null);
      $order++;
    }
   
    log::add(__CLASS__, 'debug', 'check refresh in postsave');
   
    $createRefreshCmd = true;
    $refresh = $this->getCmd(null, 'refresh');
    if (!is_object($refresh)) {
        $refresh = cmd::byEqLogicIdCmdName($this->getId(), __('Rafraichir', __FILE__));
        if (is_object($refresh)) {
            log::add(__CLASS__, 'debug', 'refresh already created');
            $createRefreshCmd = false;
        }
    }
    if ($createRefreshCmd) {
        log::add(__CLASS__, 'debug', 'refresh to be created in postsave');
        if (!is_object($refresh)) {
            $refresh = new jee4heatCmd();
            $refresh->setLogicalId('refresh');
            $refresh->setIsVisible(1);
            $refresh->setName(__('Rafraichir', __FILE__));
        }
        $refresh->setType('action');
        $refresh->setSubType('other');
        $refresh->setEqLogic_id($this->getId());
        $refresh->save();

  }
  log::add(__CLASS__, 'debug', 'postsave stop');
}

  public function preUpdate()
  {
      if (!$this->getIsEnable()) return;

      if ($this->getConfiguration('ip') == '') {
          throw new Exception(__((__('Le champ IP ne peut être vide pour l\'équipement : ', __FILE__)) . $this->getName(), __FILE__));
      }
  }

  public function postUpdate()
  {
    log::add(__CLASS__, 'debug', 'postupdate start');
   // $this->getInformations();
    log::add(__CLASS__, 'debug', 'postupdate stop');
  }

  public function preRemove()
  {
  }

  public function postRemove()
  {
  }


  public function getInformations() {
    log::add(__CLASS__, 'debug', 'getinformation start');
    log::add(__CLASS__, 'debug', 'getinformation stop');
    }


  public function getjee4heat() {
  /* c'est là qu'on appelle les API */
    log::add(__CLASS__, 'debug', 'getjee4heat' . "");
    $this->checkAndUpdateCmd('jee4heat', "");
  }
}

class jee4heatCmd extends cmd {
  public function dontRemoveCmd()
  {
      if ($this->getLogicalId() == 'refresh') {
          return true;
      }
      return false;
  }

  
  public function execute($_options = null)
  {
      if ($this->getLogicalId() == 'refresh') {
          log::add(__CLASS__, 'debug', ' ─────────> ACTUALISATION MANUELLE');
          $this->getEqLogic()->getInformations();
          log::add(__CLASS__, 'debug', ' ─────────> FIN ACTUALISATION MANUELLE');
          return;
      }
  }
}
