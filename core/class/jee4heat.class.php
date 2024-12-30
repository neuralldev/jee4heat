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

const DIRECTORY_DEVICELIST = '/../config/devices/';
const STATE_REGISTER = 30001;
const ERROR_REGISTER = 30002;
const COMMANDS = [30253, 30254];
const BUFFER_SIZE = 2048;
const SOCKET_PORT = 80;
const DATA_QUERY = '["SEL","0"]';
const ERROR_QUERY = '["SEC","1","I30002000000000000"]';
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
  11 => "En Veille",
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

class jee4heat extends eqLogic
{

  /**
   *
   * Full Story: http://brian.moonspot.net/socket-connect-timeout
   *
   * Copyright (c) 2015, Brian Moon of DealNews.com, Inc.
   * All rights reserved.
   *
   * Redistribution and use in source and binary forms, with or without
   * modification, are permitted provided that the following conditions
   * are met:
   *
   *  * Redistributions of source code must retain the above copyright
   *    notice, this list of conditions and the following disclaimer.
   *  * Redistributions in binary form must reproduce the above
   *    copyright notice, this list of conditions and the following
   *    disclaimer in the documentation and/or other materials provided
   *    with the distribution.
   *  * Neither the name of DealNews.com Inc. nor the names of its
   *    contributors may be used to endorse or promote products derived
   *    from this software without specific prior written permission.
   *
   * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
   * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
   * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
   * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
   * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
   * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
   * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
   * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
   * HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
   * STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
   * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
   * OF THE POSSIBILITY OF SUCH DAMAGE.
   *
   */

  public function pull($_options = null)
  {
  }

  public static function deadCmd()
  {
    log::add(__CLASS__, 'debug', 'deadcmd start');
    $return = array();
    foreach (eqLogic::byType('jee4heat') as $jee4heat) {
      foreach ($jee4heat->getCmd() as $cmd) {
        foreach (['modele', 'ip'] as $config) {
          preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration($config, ''), $matches);
          foreach ($matches[1] as $cmd_id) {
            if (!cmd::byId($cmd_id)) {
              $return[] = array(
                'detail' => __('jee4heat', __FILE__) . ' ' . $jee4heat->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(),
                'help' => __($config, __FILE__),
                'who' => '#' . $cmd_id . '#'
              );
            }
          }
        }
      }
    }
    log::add(__CLASS__, 'debug', 'deadcmd end');
    return $return;
  }

  /**
   *   Temperature set point function, used to ask the stove to modulate up to this value
   * @param mixed $_ip
   * @param mixed $_register
   * @param mixed $_value
   * @param mixed $_prefix
   * @return bool|string
   */
  private function setStoveValue($_ip, $_register, $_value, $_prefix = 'J')
  {
    log::add(__CLASS__, 'debug', 'set value ' . $_register . '=' . $_value);
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$socket) {
      log::add(__CLASS__, 'error', 'setstovevalue: error opening socket setting stove value');
      return "ERROR";
    }
    if (!socket_connect($socket, $_ip, SOCKET_PORT)) {
      log::add(__CLASS__, 'debug', 'error connecting socket on ' . $_ip);
      log::add(__CLASS__, 'error', ' error = ' . socket_strerror(socket_last_error($socket)));
      socket_close($socket);
      return "ERROR";
    }
    $padded = str_pad(strval($_value * 100), 12, '0', STR_PAD_LEFT);
    $command = '["SEC","1","' . $_prefix . $_register . $padded . '"]';
    log::add(__CLASS__, 'debug', 'command=' . $command);
    if (!socket_send($socket, $command, strlen($command), 0)) {
      log::add(__CLASS__, 'debug', ' error sending = ' . socket_strerror(socket_last_error($socket)));
      socket_close($socket);
      return "ERROR";
    }
    if (($bytereceived = socket_recv($socket, $stove_return, BUFFER_SIZE, 0)) === false) {
      log::add(__CLASS__, 'debug', ' error receiving = ' . socket_strerror(socket_last_error($socket)));
      socket_close($socket);
      return "ERROR";
    }
    socket_close($socket);
    return $this->readregisters($stove_return);
  }

  /**
   *  interroge depuis ici 
   *   le principe est d'échanger des messages ASCII avec un format propriétaire à base de registres de taille fixe
   *  le retour renvoie toujours ["SEL","N=nb d'items", "ITEM 1", ..."ITEM N" ]
   *   la chaine est numérique et doit être convertie en entiers pour certains registres et pas d'autres
   *   à noter que les températures de la sonde déportée et de consigne sont envoyés sur 4 chiffes et doivent être divisés par 100 pour avoir la température à afficher
   *      le flux est 
   *      DATA_QUERY -> STOVE, STOVE -> jeedomm,renvoie la liste des registres au format 
   *      "JRRRRRVVVVVVVVVVVV", J=préfixe, RRRRR=no du registre VVVVVVVVVVVV=Valeur sur 12 chiffres
   *      ERROR QUERY -> STOVE, STOVE -> jeedomm, renvoie le registre avec le code d'erreur à afficher (voir constante ERROR_NAMES)
   *      en cas d'erreur 9, le poele est bloqué (manque de granule ? trappe ouverte ? ), dans ce cas, il faut :
   *      - envoyer une alerte jeedom pour révenir et éventuellement mettre un scénario
   *      - envoyer une commande de déblocage UNBLOCK_CMD une fois l'erreur corrigée, il n'y a aucun retour particulier, soit l'erreur est à soit ça se débloque
   *      pour allumer le système il faut envoyer la commande ON_CMD, il n'y a aucun retour particulier
   *      pour demander l'extinction du système il faut envoyer la commande OFF_CMD, il n'y a aucun retour particulier        
   * @param mixed $_ip
   * @param mixed $_port
   * @param mixed $_command
   * @return string|null
   */
  private function getStoveValue($_ip, $_port, $_command)
  {
    log::add(__CLASS__, 'debug', 'getstovevalue start');
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$socket) {
      log::add(__CLASS__, 'error', 'error opening socket');
      return "ERROR";
    }
    if (!socket_connect($socket, $_ip, $_port)) {
      log::add(__CLASS__, 'error', 'getstovevalue: error connecting socket on ' . $_ip);
      log::add(__CLASS__, 'debug', ' error = ' . socket_strerror(socket_last_error($socket)));
      socket_close($socket);
      return "ERROR";
    }
    if (!socket_send($socket, $_command, strlen($_command), 0)) {
      log::add(__CLASS__, 'debug', ' error sending = ' . socket_strerror(socket_last_error($socket)));
      socket_close($socket);
      return "ERROR";
    }
    if (($bytereceived = socket_recv($socket, $stove_return, BUFFER_SIZE, 0)) === false) {
      log::add(__CLASS__, 'debug', ' error receiving = ' . socket_strerror(socket_last_error($socket)));
      socket_close($socket);
      return "ERROR";
    }
    socket_close($socket);
    log::add(__CLASS__, 'debug', 'getstovevalue end');
    return $stove_return;
  }

  /**
   * Slecture des informations pour l'équipement donné en paramètre
   * @param jee4heat $_jee4heat
   * @return bool
   */
  private function getInformationFomStove($_jee4heat)
  {
    if (!$_jee4heat->getIsEnable()) {
      log::add(__CLASS__, 'debug', 'getInformationFomStove: equipment is not enabled in Jeedom');
      return true;
    }

    $modele = $_jee4heat->getConfiguration('modele');
    if ($modele == '') {
      return false;
    }

    $ip = $_jee4heat->getConfiguration('ip');
    $id = $_jee4heat->getId();
    log::add(__CLASS__, 'debug', "refresh : ID=" . $id);
    log::add(__CLASS__, 'debug', "refresh : IP du poele=" . $ip);
    log::add(__CLASS__, 'debug', "refresh : modele=" . $modele);

    $stove_return = $this->getStoveValue($ip, SOCKET_PORT, DATA_QUERY);
    $attempts = 0;
    while ($attempts < 3 && $stove_return == "ERROR") {
      sleep(3);
      $stove_return = $this->getStoveValue($ip, SOCKET_PORT, DATA_QUERY);
      $attempts++;
    }

    if ($stove_return == "ERROR") {
      log::add(__CLASS__, 'debug', 'getInformationFomStove: error reading information from stove');
      return false;
    }

    if ($_jee4heat->readregisters($stove_return)) {
      log::add(__CLASS__, 'debug', 'refresh socket has returned =' . $stove_return);
      return true;
    } else {
      log::add(__CLASS__, 'debug', 'refresh socket has returned a message which is not unpackable =' . $stove_return);
      return false;
    }
  }

  /**
   * la fonction CRON permet d'interroger les registres toutes les minutes. 
   * le temps de mise à jour du poele peut aller de 1 à 5 minutes selon la source qui a déclenché le réglage
   * depuis l'application cloud c'est plus long à être pris en compte
   */
  public static function cron()
  {
    log::add(__CLASS__, 'debug', 'cron start');
    foreach (eqLogic::byType(__CLASS__, true) as $jee4heat) {
      if ($jee4heat->getIsEnable()) 
        $jee4heat->getInformationFomStove($jee4heat);
      else 
        log::add(__CLASS__, 'debug', 'equipment is disabled, cron skipped');
    }
    log::add(__CLASS__, 'debug', 'cron end');
  }

  /**
   * décodage du buffer contenant les registres et stockage des valeurs dans les commandes informations jeedom
   * @param string $_buffer
   * @return bool
   */
  public function readregisters($_buffer)
  {
    if ($_buffer == '')
      return false; // check if buffer is empty, if yes, then do nothing 
    $message = substr($_buffer, 2, strlen($_buffer) - 4); // trim leading and trailing characters
    $ret = explode('","', $message); // translate string to array
    log::add(__CLASS__, 'debug', 'unpack $message =' . $message);
    //  log::add(__CLASS__, 'debug', 'unpack $ret ='.$ret[0]);
    if (($ret[0] != "SEL") && ($ret[0] != "SEC"))
      return false; // check for message consistency
    $nargs = intval($ret[1]);
    log::add(__CLASS__, 'debug', 'number of registers returned =' . $ret[1]);
    if ($ret[0] == "SEC") {
      log::add(__CLASS__, 'debug', 'SEC status returned');
      // no register storage required  
      return true;
    }
    if ($nargs <= 2)
      return false; // check for message consistency

    $_state = $this->getConfiguration("state_register") ?: STATE_REGISTER;
    $_error = $this->getConfiguration("error_register") ?: ERROR_REGISTER;
    for ($i = 2; $i < $nargs + 2; $i++) { // extract all parameters
      $prefix = substr($ret[$i], 0, 1);
      $register = substr($ret[$i], 1, 5); // extract register number from value
      $registervalue = intval(substr($ret[$i], -12)); // convert string to int to remove leading 'O'
      log::add(__CLASS__, 'debug', "cron : register (prefix $prefix) $register=$registervalue");
      $Command = $this->getCmd(null, 'jee4heat_' . $register); // now set value of jeedom object
      if (is_object($Command)) {
        if ($register == $_state) { // regular stove state feedback storage
          // update state information according to value
          $cmdState = $this->getCmd(null, 'jee4heat_stovestate');
          if (is_object($cmdState)) {
            $cmdState->event($registervalue != 0);
            $this->checkAndUpdateCmd('jee4heat_mode', $registervalue == 0 ? 'off' : 'heat');
          }
          $cmdMessage = $this->getCmd(null, 'jee4heat_stovemessage');
          if (is_object($cmdMessage))
            $cmdMessage->event(MODE_NAMES[$registervalue]);
          // if state == 9, the stove is in blocked mode, so we set the binary indicator to TRUE else FALSE
          $cmdBlocked = $this->getCmd(null, 'jee4heat_stoveblocked');
          if (is_object($cmdBlocked))
            $cmdBlocked->event(($registervalue == 9));
          $cmdUnblock = $this->getCmd(null, 'jee4heat_unblock');
          if (is_object($cmdUnblock)) {
            $cmdUnblock->setIsVisible(($registervalue == 9 ? 1 : 0));
            $cmdUnblock->save();
          }
        }
        if (($register == $_error) && ($registervalue > 0)) { // in the case of ERROR query set feddback in message field and overwrite default stove state message
          // update error information according to value
          $cmdMessage = $this->getCmd(null, 'jee4heat_stovemessage');
          if (!is_object($cmdMessage))
            $cmdMessage->event("Erreur : " . ERROR_NAMES[$registervalue]);
        }
        $Command->setConfiguration('jee4heat_prefix', $prefix);
        $Command->save();
        $Command->event($registervalue);
      } else {
        log::add(__CLASS__, 'debug', 'could not find command ' . $register);
      }
    }
    return true;
  }
 
  /**
   * This function is defined to create the action buttons of equipment
   * the actions will be called by desktop through execute function by their logical ID
   * this function is called by postsave
   * @param mixed $_actionName
   * @param mixed $_actionTitle
   * @param mixed $_template
   * @param mixed $_generic_type
   * @param mixed $_visible
   * @param mixed $_SubType
   * @param mixed $_min
   * @param mixed $_max
   * @param mixed $_step
   * @return void
   */
  public function AddAction($_actionName, $_actionTitle, $_template = null, $_generic_type = null, $_visible = 1, $_SubType = 'other', $_min = null, $_max = null, $_step = null)
  {
    log::add(__CLASS__, 'debug', ' add action ' . $_actionName);
    $command = $this->getCmd(null, $_actionName);
    if ($createCmd=!is_object($command)) { // check if action is already defined, if yes avoid duplicating
      $command = cmd::byEqLogicIdCmdName($this->getId(), $_actionTitle);
      $createCmd |= is_object($command);
      if ($createCmd) { // only if action is not yet defined
          $command = new jee4heatCmd();
          $command->setLogicalId($_actionName);
          $command->setIsVisible($_visible);
          $command->setName($_actionTitle);
          if ($_template != null) {
            $command->setTemplate('dashboard', $_template);
            $command->setTemplate('mobile', $_template);
          }
          $command->setType('action');
          $command->setSubType($_SubType);
          $command->setEqLogic_id($this->getId());
          if ($_generic_type != null)
            $command->setGeneric_type($_generic_type);
          if ($_min != null)
            $command->setConfiguration('minValue', $_min);
          if ($_max != null)
            $command->setConfiguration('maxValue', $_max);
          if ($_step != null)
            $command->setDisplay('step', $_step);
          $command->save();
           }
    }
   
  }
  /*
  this function create an information based on stove registers
  it can set most of the useful paramters based on the json array defined by stove, such as :
    subtype, widget template, generic type, unit, min and max values, evaluation formula, history flag, specific icon, ...
  if you need to set an attribute for a register, change json depending on stove registers
    */
  public function AddCommand(
    $Name,
    $_logicalId,
    $Type = 'info',
    $SubType = 'binary',
    $Template = null,
    $unite = null,
    $generic_type = null,
    $IsVisible = 1,
    $icon = 'default',
    $forceLineB = 'default',
    $valuemin = 'default',
    $valuemax = 'default',
    $_order = null,
    $IsHistorized = 0,
    $repeatevent = false,
    $_iconname = null,
    $_calculValueOffset = null,
    $_historizeRound = null,
    $_noiconname = null,
    $_warning = null,
    $_danger = null,
    $_invert = 0
  ) {
 
    log::add(__CLASS__, 'debug', ' add record for ' . $Name);
 
    $command = $this->getCmd(null, $_logicalId);
    if (!is_object($command)) { // check if action is already defined, if yes avoid duplicating
      $Command = new jee4heatCmd();
      // $Command->setId(null);
      $Command->setLogicalId($_logicalId);
      $Command->setEqLogic_id($this->getId());
      $Command->setName($Name);
      $Command->setType($Type);
      $Command->setSubType($SubType);
      $Command->setIsVisible($IsVisible);
      if ($IsHistorized != null)
        $Command->setIsHistorized(strval($IsHistorized));
      if ($Template != null) {
        $Command->setTemplate('dashboard', $Template);
        $Command->setTemplate('mobile', $Template);
      }
      if ($unite != null && $SubType == 'numeric')
        $Command->setUnite($unite);
      if ($icon != 'default')
        $Command->setdisplay('icon', '<i class="' . $icon . '"></i>');
      if ($forceLineB != 'default')
        $Command->setdisplay('forceReturnLineBefore', 1);
      if ($_iconname != 'default')
        $Command->setdisplay('showIconAndNamedashboard', 1);
      if ($_noiconname != null)
        $Command->setdisplay('showNameOndashboard', 0);
      if ($_calculValueOffset != null)
        $Command->setConfiguration('calculValueOffset', $_calculValueOffset);
      if ($_historizeRound != null)
        $Command->setConfiguration('historizeRound', $_historizeRound);
      if ($generic_type != null)
        $Command->setGeneric_type($generic_type);
      if ($repeatevent == true && $Type == 'info')
        $Command->setConfiguration('repeatEventManagement', 'never');
      if ($valuemin != 'default')
        $Command->setConfiguration('minValue', $valuemin);
      if ($valuemax != 'default')
        $Command->setConfiguration('maxValue', $valuemax);
      if ($_warning != null)
        $Command->setDisplay("warningif", $_warning);
      if ($_order != null)
        $Command->setOrder($_order);
      if ($_danger != null)
        $Command->setDisplay("dangerif", $_danger);
      if ($_invert != null)
        $Command->setDisplay('invertBinary', $_invert);
      $Command->save();
    }
    
      log::add(__CLASS__, 'debug', ' addcommand end');
    return $Command;
  }

  private function toggleVisible($_logicalId, $state)
  {
    $Command = $this->getCmd(null, $_logicalId);
    if (is_object($Command)) {
      log::add(__CLASS__, 'debug', 'toggle visible state of ' . $_logicalId . " to " . $state);
      // basic settings
      $Command->setIsVisible($state);
      $Command->save();
      return true;
    }
    return false;
  }

  /**
   * this command toggles state of the stove to ON
   * if must be called only when the stove is in OFF mode (Etat=0)
   * @return void
   */
  public function state_on()
  {
    $id = $this->getId();
    $ip = $this->getConfiguration('ip');
    log::add(__CLASS__, 'debug', "on : ID=" . $id);
    log::add(__CLASS__, 'debug', "on : IP du poele=" . $ip);

    if ($ip != '') {
      $attempts = 0;
      do {
      $stove_return = $this->getStoveValue($ip, SOCKET_PORT, ON_CMD);
      if ($stove_return != "ERROR") {
        log::add(__CLASS__, 'debug', 'command on sent, socket has returned =' . $stove_return);
        $this->checkAndUpdateCmd('jee4heat_mode', 'heat');
        $this->getInformations();
        break;
      }
      sleep(3);
      $attempts++;
      } while ($attempts < 3);
    }
  }

  /**
   * this command toggles state of the stove to OFF
   * if must be called only when the stove is in ON mode (run state) and cannot be called if an error is raised
   * @return void
   */
  public function state_off()
  {
    $id = $this->getId();
    $ip = $this->getConfiguration('ip');
    log::add(__CLASS__, 'debug', "off : ID=" . $id);
    log::add(__CLASS__, 'debug', "off : IP du poele=" . $ip);

    if ($ip != '') {
      $attempts = 0;
      do {
      $stove_return = $this->getStoveValue($ip, SOCKET_PORT, OFF_CMD);
      if ($stove_return != "ERROR") {
        log::add(__CLASS__, 'debug', 'command off sent, socket has returned =' . $stove_return);
        $this->checkAndUpdateCmd('jee4heat_mode', 'off');
        $this->getInformations();
        break;
      }
      sleep(3);
      $attempts++;
      } while ($attempts < 3);
    }
  }
  /**
   * fixe la valeur de consigne à partir du curseur de sélection
   * @param array $_options
   * @return void
   */
  public function set_setpoint($_options)
  {
    log::add(__CLASS__, 'debug', 'set setpoint start');
    log::add(__CLASS__, 'debug', 'options from execute=' . json_encode($_options));
    $v = $_options["slider"];
    log::add(__CLASS__, 'debug', 'slider value=' . $v);
    //find setpoint value and store it on stove as it after slider move
    if ($v > 0) {
      $this->updatesetpoint($v, true);
      // now refresh display  
      $this->getInformations();
    }
    else
      log::add(__CLASS__, 'debug', 'cannot find jee4heat_slider command in eq=' . $this->getId());
    log::add(__CLASS__, 'debug', 'set setpoint end');
  }

  /**
   * this command allows to unblock the stove if an error is raised
   * if must be called only when the error is cleared (e.g. add pellets, etc)
   * the stove will the attempt to recover from the blocking state 
   * if it succeeds it lights the stove again, if not it will stay as is
   * @return void
   */
  public function unblock()
  {
    $id = $this->getId();
    $ip = $this->getConfiguration('ip');
    log::add(__CLASS__, 'debug', "unblock : ID=" . $id);
    log::add(__CLASS__, 'debug', "unblock : IP du poele=" . $ip);

    if ($ip != '') {
      $stove_return = $this->getStoveValue($ip, SOCKET_PORT, UNBLOCK_CMD);
      $attempts = 0;
      while ($stove_return == "ERROR" && $attempts < 3) {
        sleep(3);
        $stove_return = $this->getStoveValue($ip, SOCKET_PORT, UNBLOCK_CMD);
        $attempts++;
      }
      log::add(__CLASS__, 'debug', 'unblock called, socket has returned =' . $stove_return);
      if ($stove_return != "ERROR") {
      $this->getInformations();
      }
    }
  }

  /**
   * mise à jour de la valeur de la consigne soit par incrément, soit par valeur qui vient écraser l'existante
   * @param float $_value incrément ou valeur à remplacer
   * @param bool $_absolute vrai vient écraser la valeur existante de consigne par $_value, faux vient ajouter $_valeur à la valeur actuelle de consigne
   * @return void
   */
  public function updatesetpoint($_value, $_absolute = false)
  {
    $id = $this->getId();
    $ip = $this->getConfiguration('ip');
    $_generic_type = 'THERMOSTAT_SETPOINT';

    $cmds = cmd::byGenericType($_generic_type, null, false);
    $n = 0;
    foreach ($cmds as $cmd) {
      $name = $cmd->getName();
      $setpoint = $cmd->getLogicalId();
      $eqID = $cmd->getEqLogic_id();
      $ip = $this->getConfiguration('ip');
      log::add(__CLASS__, 'debug', "setpoint : name found=" . $name);
      log::add(__CLASS__, 'debug', "setpoint : logicalID found=" . $setpoint);
      log::add(__CLASS__, 'debug', "setpoint : parent ID found=" . $eqID);
      $n++;
      if ($eqID == $id)
        break;
    }
    if ($n == 0)
      log::add(__CLASS__, 'debug', "setpoint : command not found");
    else {
      log::add(__CLASS__, 'debug', "setpoint : command found!");
      $v = $_absolute ? floatval($_value) : floatval($cmd->execCmd()) + $_value;
      log::add(__CLASS__, 'debug', "setpoint : new set point set to " . $v);
      if ($v > 0) {
        $register = substr($setpoint, -5);
        $prefix = $cmd->getConfiguration("jee4heat_prefix");
        log::add(__CLASS__, 'debug', "setpoint : trim logical ID" . $setpoint . ' to ' . $register);
        for ($i = 0; $i < 3; $i++) {
          $r = $this->setStoveValue($ip, $register, $v, $prefix);
          if ($r != "ERROR") {
            $this->getInformations();
            break;
          }
          sleep(3);
        }
        log::add(__CLASS__, 'debug', "setpoint : stove return " . $r);
      }
    }
  }

  /**
   * stocke la référence de la consigne dans le curseur lors de la création
   * @param string $_slider ID logique du curseur
   * @return void
   */
  public function linksetpoint($_slider)
  {
    $Command = cmd::byEqLogicIdAndLogicalId($this->getId(), $_slider);
    if (!(is_object($Command))) {
      log::add(__CLASS__, 'debug', 'cannot find jee4heat_slider command in eq=' . $this->getId());
      return;
    }
    $id = $this->getId();
    $_generic_type = 'THERMOSTAT_SETPOINT';
    $cmds = cmd::byGenericType($_generic_type, null, false);
    $n = 0;
    foreach ($cmds as $cmd) {
      $n++;
      if ($cmd->getEqLogic_id()== $id)
        break;
    }
    if ($n == 0)
      log::add(__CLASS__, 'debug', "setpoint : command not found");
    else {
      log::add(__CLASS__, 'debug', "setpoint : command found!");
      $Command->setValue($cmd->getId());
      $Command->save();
      log::add(__CLASS__, 'debug', "setpoint ID ".$cmd->getId()." stored");
    }
  }
  public function refresh()
  {
    log::add(__CLASS__, 'debug', 'refresh triggers cron');
    self::cron();
  }

  public function postSave()
  {
    log::add(__CLASS__, 'debug', 'postsave start');

    $_eqName = $this->getName();
    log::add(__CLASS__, 'info', 'Sauvegarde de l\'équipement [postSave()] : ' . $_eqName);
    $order = 1;

    if (!is_file(__DIR__ . DIRECTORY_DEVICELIST . $this->getConfiguration('modele') . '.json')) {
      log::add(__CLASS__, 'debug', 'postsave no file found for ' . $_eqName . ', then do nothing');
      return;
    }
    $content = file_get_contents(__DIR__ . DIRECTORY_DEVICELIST . $this->getConfiguration('modele') . '.json');
    if (!is_json($content)) {
      log::add(__CLASS__, 'debug', 'postsave content is not json ' . $this->getConfiguration('modele'));
      return;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      log::add(__CLASS__, 'debug', 'postsave array cannot be decoded ');
      return true;
    }
    $Equipement = eqlogic::byId($this->getId());
    $Equipement->setConfiguration('state_register', $device['configuration']['state']);
    $Equipement->setConfiguration('error_register', $device['configuration']['error']);
    $order = 0;
    log::add(__CLASS__, 'debug', 'postsave add commands on ID ' . $this->getId());
    foreach ($device['commands'] as $item) {
      log::add(__CLASS__, 'debug', 'postsave found commands array name=' . json_encode($item));
      // item name must match to json structure table items names, if not it takes null
      if ($item['name'] != '' && $item['logicalId'] != '') {
        $Equipement->AddCommand(
          $item['name'],
          'jee4heat_' . $item['logicalId'],
          $item['type'],
          $item['subtype'],
          (!isset($item['template']) ? 'tile' : $item['template']),
          // $item['template'] ?? 'tile',
          (!array_key_exists('unit', $item) ? '' : $item['unit']),
          (!array_key_exists('generictype', $item) ? '' : $item['generictype']),
          (!array_key_exists('visible', $item) ? '1' : $item['visible']),
          'default',
          'default',
          (!array_key_exists('min', $item) ? '' : $item['min']),
          (!array_key_exists('max', $item) ? '' : $item['max']),
          $order,
          (!array_key_exists('history', $item) ? '' : $item['history']),
          false,
          'default',
          (!array_key_exists('offset', $item) ? '' : $item['offset']),
          null,
          null,
          (!array_key_exists('warningif', $item) ? '' : $item['warningif']),
          (!array_key_exists('dangerif', $item) ? '' : $item['dangerif']),
          (!array_key_exists('invert', $item) ? '' : $item['invert'])
        );
        $order++;
      }
    }

    $Equipement->AddCommand(__('Etat', __FILE__), 'jee4heat_stovestate', "info", "binary", 'heat', '', 'THERMOSTAT_STATE', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 0);
    $Equipement->AddCommand(__('Mode', __FILE__), 'jee4heat_mode', "info", "string", 'heat', '', 'THERMOSTAT_MODE', 0, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 0);
    $Equipement->AddCommand(__('Bloqué', __FILE__), 'jee4heat_stoveblocked', "info", "binary", 'jee4heat::mylocked', '', '', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 1);
    $Equipement->AddCommand(__('Message', __FILE__), 'jee4heat_stovemessage', "info", "string", 'line', '', '', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 0);
    $Equipement->setConfiguration('jee4heat_stovestate', $device['configuration']['state']);

    /* create on, off, unblock and refresh actions */
    $Equipement->AddAction("jee4heat_on", "heat","default", "THERMOSTAT_MODE", 1);
    $Equipement->AddAction("jee4heat_auto", "Auto","default", "THERMOSTAT_MODE", 0);
    $Equipement->AddAction("jee4heat_off", "off","default", "THERMOSTAT_MODE", 1);
    $Equipement->AddAction("jee4heat_unblock", __('Débloquer', __FILE__), "jee4heat::mylock");
    $Equipement->AddAction("refresh", __('Rafraichir', __FILE__));
    $Equipement->AddAction("jee4heat_stepup", "+", null, null, 0);
    $Equipement->AddAction("jee4heat_stepdown", "-", null, null, 0);
    $Equipement->AddAction("jee4heat_slider", "Régler consigne", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", 10, 25, 0.5);
    $Equipement->linksetpoint("jee4heatslider");
    //$Equipement->AddAction("jee4heat_setvalue", "VV",  null, 'THERMOST_SET_SETPOINT', "slider");

    log::add(__CLASS__, 'debug', 'postsave stop');
    // now refresh
    $this->getInformations();
  }

  public function preUpdate()
  {
    log::add(__CLASS__, 'debug', 'preupdate start');

    if ($this->getConfiguration('ip') == '') {
      throw new Exception(__((__('Le champ IP ne peut être vide pour l\'équipement ', __FILE__)) . $this->getName(), __FILE__));
    }
    log::add(__CLASS__, 'debug', 'preupdate stop');
  }


  public function getInformations()
  {
    log::add(__CLASS__, 'debug', 'getinformation start');
    $this->getInformationFomStove($this);
    log::add(__CLASS__, 'debug', 'getinformation stop');
  }


  public static function templateWidget()
  {
    $return = array('action' => array('string' => array()), 'info' => array('string' => array()));
    $return['action']['other']['mylock'] = array(
      'template' => 'tmplicon',
      'replace' => array(
        '#_icon_on_#' => '<i class=\'icon_green icon jeedom-lock-ouvert\'></i>',
        '#_icon_off_#' => '<i class=\'icon_red icon jeedom-lock-ferme\'></i>'
      )
    );
    $return['info']['string']['mypellets'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => 'Arrêt', 'state_dark' => 'Arrêt'),
        array('operation' => '#value# >= 1 && #value# <= 9', 'state_light' => '#value#', 'state_dark' => '#value#'),
        array('operation' => '#value# == 10 && #value# <= 5', 'state_light' => 'extinction', 'state_dark' => 'extinction'),
        array('operation' => '#value# == 255', 'state_light' => 'Allumage', 'state_dark' => 'Allumage')
      )
    );
    $return['info']['string']['mypower'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => 'Arrêt', 'state_dark' => 'Arrêt'),
        array('operation' => '#value# >= 1 && #value# <= 3', 'state_light' => '#value# Basse', 'state_dark' => '#value# Basse'),
        array('operation' => '#value# >= 4 && #value# <= 5', 'state_light' => '#value# Moyenne', 'state_dark' => '#value# Moyenne'),
        array('operation' => '#value# == 6', 'state_light' => '#value# Haute', 'state_dark' => '#value# Haute'),
        array('operation' => '#value# == 7', 'state_light' => 'Auto', 'state_dark' => 'Auto')
      )
    );
    $return['info']['binary']['mylocked'] = array(
      'template' => 'tmplicon',
      'replace' => array(
        '#_icon_on_#' => '<span style="font-size:20px!important;color:green;"><br/>Non</span>',
        '#_icon_off_#' => '<span style="font-size:20px!important;color:red;"><br/>Oui</span>'
      )
    );
    $return['info']['numeric']['myerror'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => '<span style="font-size:20px!important;color:green;"><br/>Non</span>', 'state_dark' => '<span style="font-size:20px!important;color:green;"><br/>Non</span>'),
        array('operation' => '#value# != 0', 'state_light' => '<span style="font-size:20px!important;color:red;"><br/>#value#</span>', 'state_dark' => '<span style="font-size:20px!important;color:red;"><br/>#value#</span>')
      )
    );
    return $return;
  }


}
class jee4heatCmd extends cmd
{
  public function dontRemoveCmd()
  {
    if ($this->getLogicalId() == 'refresh') {
      return true;
    }
    return false;
  }

  public function execute($_options = null)
  {
    $action = $this->getLogicalId();
    log::add(__CLASS__, 'debug', 'execute action ' . $action);
    switch ($action) {
      case 'refresh':
        $this->getEqLogic()->getInformations();
        break;
      case 'jee4heat_stepup':
        $this->getEqLogic()->updatesetpoint(0.5);
        $this->getEqLogic()->getInformations();
        break;
      case 'jee4heat_stepdown':
        $this->getEqLogic()->updatesetpoint(-0.5);
        $this->getEqLogic()->getInformations();
        break;
      case 'jee4heat_on':
      case 'jee4heat_auto':
          $this->getEqLogic()->state_on();
        break;
      case 'jee4heat_off':
        $this->getEqLogic()->state_off();
        break;
      case 'jee4heat_slider':
        // réglage de la consigne
        $this->getEqLogic()->set_setpoint($_options);
        break;
      case "jee4heat_unblock":
        $this->getEqLogic()->unblock();
      default:
        log::add(__CLASS__, 'warning', 'action to execute not found');
    }
    return;
  }
}
