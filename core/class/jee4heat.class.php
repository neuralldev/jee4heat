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

const STATE_REGISTER = 30001;
const ERROR_REGISTER = 30002;
const BUFFER_SIZE = 2048;
const SOCKET_PORT = 80;
const DATA_QUERY = '["SEL","0"]';
const ERROR_QUERY = '["SEC","3","I30001000000000000","I30002000000000000","I30017000000000000"]';
const UNBLOCK_CMD = '["SEC","1","J30255000000000001"]'; // Unblock
const OFF_CMD = '["SEC","1","J30254000000000001"]'; // OFF
const ON_CMD = '["SEC","1","J30253000000000001"]'; // O
const MODE_NAMES = [
  0 => "OFF",
  1 => "Vérification",
  2 => "Allumage",
  3 => "Stabilisation",
  4 => "Allumage",
  5 => "Chauffage",
  6 => "Modulation",
  7 => "Extinction",
  8 => "Sécurité",
  9 => "Bloqué",
  10 => "Récupération",
  11 => "Standby",
  30 => "-",
  31 => "-",
  32 => "-",
  33 => "-",
  34 => "-"
];

const ERROR_NAMES = [
  0 => "No error",
  1 => "Safety Thermostat HV1 => signalled also in case of Stove OFF",
  2 => "Safety PressureSwitch HV2 => signalled with Combustion Fan ON",
  3 => "Extinguishing for Exhausting Temperature lowering",
  4 => "Extinguishing for water over Temperature",
  5 => "Extinguishing for Exhausting over Temperature",
  6 => "unknown",
  7 => "Encoder Error => No Encoder Signal (in case of P25=1 or 2)",
  8 => "Encoder Error => Combustion Fan regulation failed (in case of P25=1 or 2)",
  9 => "Low pressure in to the Boiler",
  10 => "High pressure in to the Boiler Error",
  11 => "DAY and TIME not correct due to prolonged absence of Power Supply",
  12 => "Failed Ignition",
  13 => "Ignition",
  14 => "Ignition",
  15 => "Lack of Voltage Supply",
  16 => "Ignition",
  17 => "Ignition",
  18 => "Lack of Voltage Supply"
];

class jee4heat extends eqLogic {

  public static function pull($_options=null) {
    $cron = cron::byClassAndFunction(__CLASS__, 'pull', $_options);
    if (is_object($cron)) {
      $cron->remove();
    }
    return;
  }

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

  /*
  Temperature set point function, used to ask the stove to modulate up to this value
  */
  private function setStoveValue($ip, $register, $value, $prefix = null)
  {
    log::add(__CLASS__, 'debug', 'set value '.$register.'='.$value);
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$socket) {
      log::add(__CLASS__, 'debug', 'error opening socket');
    } else {
      if (!socket_connect($socket, $ip, SOCKET_PORT)) {
          log::add(__CLASS__, 'debug', 'error connecting socket on '.$ip);
          log::add(__CLASS__, 'debug', ' error = '.socket_strerror(socket_last_error($socket)));
      }
      // prepare value; this is necessary for register set point to multiply the value because it expects temperature this way (4 digits)
      $v = $value * 100;
      $szV = strval($v);
      $padded = str_pad($szV,12,'0', STR_PAD_LEFT);
      // format write command as ["SEC","1","<prefix>RRRRRVVVVVVVVVVVV"]
      $command = '["SEC","1","'.($prefix==null?'J':$prefix).$register.$padded.'"]';
      log::add(__CLASS__, 'debug', 'command='.$command);
      if (!socket_send($socket, $command, strlen($command), 0)) {
        log::add(__CLASS__, 'debug', ' error sending = '.socket_strerror(socket_last_error($socket)));
      } else {
          if(($bytereceived = socket_recv($socket,$stove_return,BUFFER_SIZE, 0)) == false) {
            log::add(__CLASS__, 'debug', ' error rceiving = '.socket_strerror(socket_last_error($socket)));
          }
        socket_close($socket);
        // unpack answer
        return  $this->readregisters($stove_return);         
      }
    }
  }

  private function getStoveValue($ip, $port, $command) {
      /* interroge depuis ici 
        le principe est d'échanger des messages ASCII avec un format propriétaire à base de registres de taille fixe
        le retour renvoie toujours ["SEL","N=nb d'items", "ITEM 1", ..."ITEM N" ]
        la chaine est numérique et doit être convertie en entiers pour certains registres et pas d'autres
        à noter que les températures de la sonde déportée et de consigne sont envoyés sur 4 chiffes et doivent être divisés par 100 pour avoir la température à afficher

        le flux est 
        DATA_QUERY -> STOVE, STOVE -> jeedomm,renvoie la liste des registres au format 
        "JRRRRRVVVVVVVVVVVV", J=préfixe, RRRRR=no du registre VVVVVVVVVVVV=Valeur sur 12 chiffres
        ERROR QUERY -> STOVE, STOVE -> jeedomm, renvoie le registre avec le code d'erreur à afficher (voir constante ERROR_NAMES)
        en cas d'erreur 9, le poele est bloqué (manque de granule ? trappe ouverte ? ), dans ce cas, il faut :
        - envoyer une alerte jeedom pour révenir et éventuellement mettre un scénario
        - envoyer une commande de déblocage UNBLOCK_CMD une fois l'erreur corrigée, il n'y a aucun retour particulier, soit l'erreur est à soit ça se débloque
        pour allumer le système il faut envoyer la commande ON_CMD, il n'y a aucun retour particulier
        pour demander l'extinction du système il faut envoyer la commande OFF_CMD, il n'y a aucun retour particulier   
        */
      $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
      if (!$socket) {
        log::add(__CLASS__, 'debug', 'error opening socket');
      } else {
        if (!socket_connect($socket, $ip, $port)) {
            log::add(__CLASS__, 'debug', 'error connecting socket on '.$ip);
            log::add(__CLASS__, 'debug', ' error = '.socket_strerror(socket_last_error($socket)));
        }
        // query status
        
        if (!socket_send($socket, $command, strlen($command), 0)) {
          log::add(__CLASS__, 'debug', ' error sending = '.socket_strerror(socket_last_error($socket)));
        } else {
            if(($bytereceived = socket_recv($socket,$stove_return,BUFFER_SIZE, 0)) == false) {
              log::add(__CLASS__, 'debug', ' error rceiving = '.socket_strerror(socket_last_error($socket)));
            }
          socket_close($socket);
          return $stove_return;
        }
    }
  }

  private function getInformationFomStove($jee4heat) {
    if ($jee4heat->getIsEnable()) {
      if (($modele = $jee4heat->getConfiguration('modele')) != '') {
      /* lire les infos de l'équipement ici */
        $ip = $jee4heat->getConfiguration('ip');
        $id = $jee4heat->getId();
        log::add(__CLASS__, 'debug', "refresh : ID=".$id);
        log::add(__CLASS__, 'debug', "refresh : IP du poele=".$ip);
        log::add(__CLASS__, 'debug', "refresh : modele=".$modele);
        if ($jee4heat->getConfiguration('modele') != '') {
           $stove_return = $jee4heat->getStoveValue($ip,SOCKET_PORT, DATA_QUERY); // send query
           if ($jee4heat->readregisters($stove_return)) // translate registers to jeedom values, return true if successful
              log::add(__CLASS__, 'debug', 'refresh socket has returned ='.$stove_return);
            else
              log::add(__CLASS__, 'debug', 'refresh socket has returned a message which is not unpackable ='.$stove_return);
          }
        }
      }
  }

  /*
  la fonction CRON permet d'interroger les registres toutes les minutes. 
  le temps de mise à jour du poele peut aller de 1 à 5 minutes selon la source qui a déclenché le réglage
  depuis l'application cloud c'est plus long à être pris en compte
  */
  public static function cron() {
    foreach (eqLogic::byType(__CLASS__, true) as $jee4heat) {
      if ($jee4heat->getIsEnable()) {
        if (($modele = $jee4heat->getConfiguration('modele')) != '') {
        /* lire les infos de l'équipement ici */
          $ip = $jee4heat->getConfiguration('ip');
          $id = $jee4heat->getId();
          log::add(__CLASS__, 'debug', "cron : ID=".$id);
          log::add(__CLASS__, 'debug', "cron : IP du poele=".$ip);
          log::add(__CLASS__, 'debug', "cron : modele=".$modele);
          if ($jee4heat->getConfiguration('modele') != '') {
             $stove_return = $jee4heat->getStoveValue($ip,SOCKET_PORT, DATA_QUERY); // send query
             if ($jee4heat->readregisters($stove_return)) // translate registers to jeedom values, return true if successful
                log::add(__CLASS__, 'debug', 'cron socket has returned ='.$stove_return);
              else
                log::add(__CLASS__, 'debug', 'cron socket has returned which is not unpackable ='.$stove_return);
            }
          }
        }
    }
  }

  public function readregisters($buffer) {
    if ($buffer=='') return false; // check if buffer is empty, if yes, then do nothing 
    $message = substr($buffer,2, strlen($buffer) -4); // trim leading and trailing characters
    $ret = explode('","', $message); // translate string to array
    log::add(__CLASS__, 'debug', 'unpack $message ='.$message);
  //  log::add(__CLASS__, 'debug', 'unpack $ret ='.$ret[0]);
    if(($ret[0] != "SEL") && ($ret[0] != "SEC")) return false; // check for message consistency
    $nargs = intval($ret[1]);
    log::add(__CLASS__, 'debug', 'number of registers returned ='.$ret[1]);
    if($nargs <= 2) return false; // check for message consistency
    
    for ($i = 2; $i < $nargs+2; $i++) { // extract all parameters
      $prefix = substr($ret[$i],0, 1);
      $register = substr($ret[$i],1, 5); // extract register number from value
      $registervalue = intval(substr($ret[$i],-12)); // convert string to int to remove leading 'O'
    // if (substr($register,0,1) == "0") $registervalue = intval($registervalue);
      log::add(__CLASS__, 'debug', "cron : register (prefix $prefix) $register=$registervalue");
      $Command = $this->getCmd(null, 'jee4heat_'.$register); // now set value of jeedom object
      if (is_object($Command)) { 
        //log::add(__CLASS__, 'debug', ' store ['.$registervalue.'] value in logicalid='.$register); 
        if ($register == STATE_REGISTER) { // regular stove state feedback storage
          // update state information according to value
          $cmdState = $this->getCmd(null, 'jee4heat_stovestate');
          if ($cmdState != null) $cmdState->event($registervalue != 0);
          $cmdMessage = $this->getCmd(null, 'jee4heat_stovemessage');
          if ($cmdMessage != null) $cmdMessage->event(MODE_NAMES[$registervalue]);
          // if state == 9, the stove is in blocked mode, so we set the binary indicator to TRUE else FALSE
          $cmdBlocked = $this->getCmd(null, 'jee4heat_stoveblocked');
          $cmdBlocked->event(($registervalue == 9));
        }
        if (($register == ERROR_REGISTER) && ($registervalue > 0)) { // in the case of ERROR query set feddback in message field and overwrite default stove state message
          // update error information according to value
          $cmdMessage = $this->getCmd(null, 'jee4heat_stovemessage');
          if ($cmdMessage != null) $cmdMessage->event(ERROR_NAMES[$registervalue]);
        }
        $Command->setConfiguration('jee4heat_prefix',$prefix);
        $Command->event($registervalue);
      } else {
        log::add(__CLASS__, 'debug', 'could not find command '.$register);
      }
    }
    return true;
  }
  /*
  This function is defined to create the action buttons of equipment
  the actions will be called by desktop through execute function by their logical ID
  this function is called by postsave
  */
  public function AddAction($actionName, $actionTitle, $template = null, $generic_type=null, $SubType='other') {
    log::add(__CLASS__, 'debug', ' add action '.$actionName);
    $createCmd = true;
    $command = $this->getCmd(null, $actionName);
    if (!is_object($command)) { // check if action is already defined, if yes avoid duplicating
        $command = cmd::byEqLogicIdCmdName($this->getId(), $actionTitle);
        if (is_object($command)) $createCmd = false;
    }
    if ($createCmd) { // only if action is not yet defined
        if (!is_object($command)) {
          $command = new jee4heatCmd();
          $command->setLogicalId($actionName);
          $command->setIsVisible(1);
          $command->setName($actionTitle);
        }
        if ($template != null) {
          $command->setTemplate('dashboard', $template);
          $command->setTemplate('mobile', $template);
        }
        $command->setType('action');
        $command->setSubType($SubType);
        $command->setEqLogic_id($this->getId());
        if ($generic_type != null) $command->setGeneric_type($generic_type);

        $command->save();
    }
  }  
  /*
  this function create an information based on stove registers
  it can set most of the useful paramters based on the json array defined by stove, such as :
    subtype, widget template, generic type, unit, min and max values, evaluation formula, history flag, specific icon, ...
  if you need to set an attribute for a register, change json depending on stove registers
    */
  public function AddCommand($Name, $_logicalId, $Type = 'info', $SubType = 'binary', $Template = null, $unite = null, $generic_type = null, $IsVisible = 1, $icon = 'default', $forceLineB = 'default', $valuemin = 'default', $valuemax = 'default', $_order = null, $IsHistorized = '0', $repeatevent = false, $_iconname = null, $_calculValueOffset = null, $_historizeRound = null, $_noiconname = null, $_warning = null, $_danger = null, $_invert = 0)
  {
    $numargs = func_num_args();
    $arg_list = func_get_args();
    for ($i = 0; $i < $numargs; $i++) {
      log::add(__CLASS__, 'debug', "Argument $i is: " . $arg_list[$i]);
    }

    $Command = $this->getCmd(null, $_logicalId);

    if (!is_object($Command)) {
        log::add(__CLASS__, 'debug', ' add record for '.$Name. "". $_logicalId);
        // basic settings
        $Command = new jee4heatCmd();  
       // $Command->setId(null);
        $Command->setLogicalId($_logicalId);
        $Command->setEqLogic_id($this->getId());
        $Command->setName($Name);
        $Command->setType($Type);
        $Command->setSubType($SubType);
        $Command->setIsVisible($IsVisible);
        $Command->setIsHistorized($IsHistorized);
        log::add(__CLASS__, 'debug', 'try to save A');
        $Command->save();
        // add parameters if defined
        if ($Template != null) {
          $Command->setTemplate('dashboard', $Template);
            $Command->setTemplate('mobile', $Template);
        }
        log::add(__CLASS__, 'debug', 'try to save B');
        $Command->save();
        if ($unite != null && $SubType == 'numeric') $Command->setUnite($unite);
        if ($icon != 'default') $Command->setdisplay('icon', '<i class="' . $icon . '"></i>');
        if ($forceLineB != 'default') $Command->setdisplay('forceReturnLineBefore', 1);
        if ($_iconname != 'default') $Command->setdisplay('showIconAndNamedashboard', 1);
        if ($_noiconname != null) $Command->setdisplay('showNameOndashboard', 0);
        if ($_calculValueOffset != null) $Command->setConfiguration('calculValueOffset', $_calculValueOffset);
        if ($_historizeRound != null) $Command->setConfiguration('historizeRound', $_historizeRound);
        if ($generic_type != null) $Command->setGeneric_type($generic_type);
        if ($repeatevent == true && $Type == 'info') $Command->setConfiguration('repeatEventManagement', 'never');
        if ($valuemin != 'default') $Command->setConfiguration('minValue', $valuemin);
        if ($valuemax != 'default') $Command->setConfiguration('maxValue', $valuemax);
        if ($_warning != null) $Command->setDisplay("warningif", $_warning);
        if ($_order != null) $Command->setOrder($_order);
        if ($_danger != null) $Command->setDisplay("dangerif", $_danger);
        if ($_invert != 0) $Command->setConfiguration('invertBinary', $_invert);
        log::add(__CLASS__, 'debug', ' invert='.$_invert);
        log::add(__CLASS__, 'debug', 'try to save C');
        $Command->save();
        log::add(__CLASS__, 'debug', 'command saved');
      }
      log::add(__CLASS__, 'debug', ' addcommand end');
      return $Command;
  }

  /**
   * this command toggles state of the stove to ON
   * if must be called only when the stove is in OFF mode (Etat=0)
   */
  public function state_on()
  {
    $id = $this->getId();
    $ip = $this->getConfiguration('ip');
    log::add(__CLASS__, 'debug', "on : ID=".$id);
    log::add(__CLASS__, 'debug', "on : IP du poele=".$ip);
          
    if ($ip !='') {
       $stove_return = $this->getStoveValue($ip,SOCKET_PORT, ON_CMD);
          log::add(__CLASS__, 'debug', 'command on sent, socket has returned ='.$stove_return);
          // expected return "I" ["SEC","1","I30253000000000000"]
        }
  }

  /**
   * this command toggles state of the stove to OFF
   * if must be called only when the stove is in ON mode (run state) and cannot be called if an error is raised
   */
  public function state_off()
  {
    $id = $this->getId();
    $ip = $this->getConfiguration('ip');
    log::add(__CLASS__, 'debug', "off : ID=".$id);
    log::add(__CLASS__, 'debug', "off : IP du poele=".$ip);
          
    if ($ip !='') {
       $stove_return = $this->getStoveValue($ip,SOCKET_PORT, OFF_CMD);
          log::add(__CLASS__, 'debug', 'command off sent, socket has returned ='.$stove_return);
          // expected return "I" ["SEC","1","I30254000000000000"]
      }
  }

    /**
   * this command allows to unblock the stove if an error is raised
   * if must be called only when the error is cleared (e.g. add pellets, etc)
   * the stove will the attempt to recover from the blocking state 
   * if it succeeds it lights the stove again, if not it will stay as is
   */
  public function unblock()
  {
    $id = $this->getId();
    $ip = $this->getConfiguration('ip');
    log::add(__CLASS__, 'debug', "unblock : ID=".$id);
    log::add(__CLASS__, 'debug', "unblock : IP du poele=".$ip);
          
    if ($ip !='') {
       $stove_return = $this->getStoveValue($ip,SOCKET_PORT, UNBLOCK_CMD);
          log::add(__CLASS__, 'debug', 'unblock called, socket has returned ='.$stove_return);
           // expected return "I" ["SEC","1","I30255000000000000"]

      }
  }

  public function updatesetpoint($step)
  {
    $id = $this->getId(); 
    $logicalid = $this->getLogicalId();
    $ip = $this->getConfiguration('ip');
    $_generic_type = 'THERMOSTAT_SETPOINT';
    $_type = '';
    $_multiple = '';

    $cmds = cmd::byGenericType($_generic_type, null, false) ;
		
    $n =0;

    foreach ($cmds as $cmd) {
      $name = $cmd->getName();
      $setpoint = $cmd->getLogicalId();
      $eqID = $cmd->getEqLogic_id();
      $ip = $this->getConfiguration('ip');
      log::add(__CLASS__, 'debug', "setpoint : name found=".$name);
      log::add(__CLASS__, 'debug', "setpoint : logicalID found=".$setpoint);
      log::add(__CLASS__, 'debug', "setpoint : parent ID found=".$eqID);
      $n++;
      if ($eqID==$id) break;
    }
    if ($n==0) 
      log::add(__CLASS__, 'debug', "setpoint : command not found");
    else {
      log::add(__CLASS__, 'debug', "setpoint : command found!");
      $v=(floatval($cmd->execCmd())+$step);
      log::add(__CLASS__, 'debug', "setpoint : new set point set to ".$v);
      if ($v > 0) {
        $register = substr($setpoint,-5);
        $prefix = $cmd->getConfiguration("jee4heat_prefix");
        log::add(__CLASS__, 'debug', "setpoint : trim logical ID".$setpoint.' to '.$register);
        $r=$this->setStoveValue($ip, $register, $v, $prefix);
        log::add(__CLASS__, 'debug', "setpoint : stove return ".$r);        
      }
    }
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
      // item name must match to json structure table items names, if not it takes null
      if ($item['name'] != '' && $item['logicalId'] != '') {
        $Equipement->AddCommand($item['name'], 'jee4heat_'.$item['logicalId'], $item['type'], $item['subtype'], 'tile',$item['unit'] , $item['generictype'], ($item['visible']!=''?$item['visible']:'1'), 'default', 'default', $item['min'], $item['max'], $order, $item['history'], true, 'default', $item['offset'], 2, null, $item['warningif'], $item['dangerif'], $item['invert']);
        $order++;
      }
    }
    $Equipement->AddCommand(__('Etat', __FILE__), 'jee4heat_stovestate', "info", "binary", 'heat','' , 'THERMOSTAT_STATE', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 0);
    $Equipement->AddCommand(__('Bloqué', __FILE__), 'jee4heat_stoveblocked', "info", "binary", 'alert','' , '', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 1);
    $Equipement->AddCommand(__('Message', __FILE__), 'jee4heat_stovemessage', "info", "string", 'line','' , '', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 0);
    $Equipement->setConfiguration('jee4heat_stovestate',STATE_REGISTER);
    log::add(__CLASS__, 'debug', 'check refresh in postsave');

    /* create on, off, unblock and refresh actions */
    $Equipement->AddAction("jee4heat_on", "ON");
    $Equipement->AddAction("jee4heat_off", "OFF");
    $Equipement->AddAction("jee4heat_unblock", __('Débloquer', __FILE__), 'lock');
    $Equipement->AddAction("refresh", __('Rafraichir', __FILE__));
    $Equipement->AddAction("jee4heat_stepup", "+", null, 'THERMOST_SET_SETPOINT');
    $Equipement->AddAction("jee4heat_stepdown", "-", null, 'THERMOST_SET_SETPOINT');
    //$Equipement->AddAction("jee4heat_setvalue", "VV",  null, 'THERMOST_SET_SETPOINT', "slider");

    log::add(__CLASS__, 'debug', 'postsave stop');
  }

  public function preUpdate()
  {
      if (!$this->getIsEnable()) 
        throw new Exception(__((__("L'équipement est désactivé, impossible de régler : ", __FILE__)) . $this->getName(), __FILE__));

      if ($this->getConfiguration('ip') == '') {
          throw new Exception(__((__('Le champ IP ne peut être vide pour l\'équipement : ', __FILE__)) . $this->getName(), __FILE__));
      }
  }

  public function postUpdate()
  {
    log::add(__CLASS__, 'debug', 'postupdate start');
    //$this->getInformations();
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
    $this->getInformationFomStove($this);
    log::add(__CLASS__, 'debug', 'getinformation stop');
  }


  public function getjee4heat() {
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

  private function toggleVisible($_logicalId, $state)
  {
    $Command = $this->getCmd(null, $_logicalId);
    if (is_object($Command)) {
        log::add(__CLASS__, 'debug', 'toggle visible state of '.$_logicalId." to ".$state);
        // basic settings
        $Command->setIsVisible($state);
        $Command->save();
        return true;
    }
    return false;
  }

  public function execute($_options = null)
  {
    $action = $this->getLogicalId() ;
    log::add(__CLASS__, 'debug', 'execute action ' . $action);
    switch ($action) {
      case 'refresh':
        $this->getEqLogic()->getInformations();
        break;
        case 'jee4heat_stepup':
          $this->getEqLogic()->updatesetpoint(0.5);
          break;
          case 'jee4heat_stepdown':
            $this->getEqLogic()->updatesetpoint(-0.5);
            break;
          case 'jee4heat_on':
        $this->getEqLogic()->state_on();
        break;
      case 'jee4heat_off':
        $this->getEqLogic()->state_off();
        break;
        default:
      }
    return;
  }
}
