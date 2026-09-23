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
const BUFFER_SIZE = 2048;
const SOCKET_PORT = 80;
const SOCKET_TIMEOUT = 5; // seconds, for connect/send/recv
const MAX_REPLY_SIZE = 16384; // hard cap on a stove reply
const COMMAND_ATTEMPTS = 3; // tries for a write before giving up
const SETPOINT_MIN = 10; // fallback setpoint clamp when the slider has no range
const SETPOINT_MAX = 25;
const DATA_QUERY = '["SEL","0"]';
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
  // transient (underscore-prefixed properties are not persisted by DB::save)
  private $_modelChanged = false;

  /**
   *   Temperature set point function, used to ask the stove to modulate up to this value
   * @param string $_ip
   * @param string $_register 5-digit register number
   * @param float $_value value in display units (sent as value*100)
   * @param string $_prefix register prefix as returned by the stove
   * @return bool true when the stove acknowledged the write
   */
  private function setStoveValue($_ip, $_register, $_value, $_prefix = 'J')
  {
    log::add(__CLASS__, 'debug', 'set value ' . $_register . '=' . $_value);
    // integer centi-units, always exactly 12 digits (caller clamps the range)
    $padded = sprintf('%012d', (int) round($_value * 100));
    $command = '["SEC","1","' . $_prefix . $_register . $padded . '"]';
    log::add(__CLASS__, 'debug', 'command=' . $command);
    $stove_return = $this->getStoveValue($_ip, $command);
    if ($stove_return === "ERROR") {
      return false;
    }
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
   * @param string $_ip
   * @param string $_command
   * @return string raw stove reply, or "ERROR"
   */
  private function getStoveValue($_ip, $_command)
  {
    log::add(__CLASS__, 'debug', 'getstovevalue start');
    // serialize exchanges with a given stove (cron vs user action): the
    // stove module may not handle concurrent TCP connections
    $lock = self::lockStove($_ip);
    try {
      $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
      if (!$socket) {
        log::add(__CLASS__, 'error', 'error opening socket');
        return "ERROR";
      }
      // avoid the socket (and therefore the cron) hanging forever if the stove is unreachable
      socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => SOCKET_TIMEOUT, 'usec' => 0]);
      socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => SOCKET_TIMEOUT, 'usec' => 0]);
      if (!@socket_connect($socket, $_ip, SOCKET_PORT)) {
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
      // TCP may deliver the reply in several segments: read until the
      // closing bracket, peer close, timeout, or size cap
      $stove_return = '';
      while (strlen($stove_return) < MAX_REPLY_SIZE) {
        $chunk = '';
        $received = socket_recv($socket, $chunk, BUFFER_SIZE, 0);
        if ($received === false) {
          if ($stove_return === '') {
            log::add(__CLASS__, 'debug', ' error receiving = ' . socket_strerror(socket_last_error($socket)));
            socket_close($socket);
            return "ERROR";
          }
          break; // timeout after partial data: readregisters drops incomplete items
        }
        if ($received === 0) {
          break;
        }
        $stove_return .= $chunk;
        if (substr(rtrim($stove_return), -1) === ']') {
          break;
        }
      }
      socket_close($socket);
      log::add(__CLASS__, 'debug', 'getstovevalue end');
      return $stove_return;
    } finally {
      self::unlockStove($lock);
    }
  }

  /**
   * Acquire an exclusive per-stove lock (blocking).
   * @param string $_ip
   * @return resource|false lock handle, false if the lock file can't be opened
   */
  private static function lockStove($_ip)
  {
    $file = jeedom::getTmpFolder(__CLASS__) . '/stove_' . preg_replace('/[^0-9A-Za-z.-]/', '_', $_ip) . '.lock';
    $fp = @fopen($file, 'c');
    if ($fp === false) {
      log::add(__CLASS__, 'debug', 'cannot open lock file ' . $file . ', proceeding without lock');
      return false;
    }
    flock($fp, LOCK_EX);
    return $fp;
  }

  /**
   * @param resource|false $_lock
   */
  private static function unlockStove($_lock)
  {
    if ($_lock !== false) {
      flock($_lock, LOCK_UN);
      fclose($_lock);
    }
  }

  /**
   * Slecture des informations pour l'équipement donné en paramètre
   * @param jee4heat $_jee4heat
   * @param bool $_retry whether to retry on error (false = single best-effort attempt)
   * @return bool
   */
  private function getInformationFomStove($_jee4heat, $_retry = true)
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

    $stove_return = $this->getStoveValue($ip, DATA_QUERY);
    $attempts = 0;
    // best-effort callers (e.g. postSave) skip retries; the cron will catch up later
    while ($_retry && $attempts < 3 && $stove_return == "ERROR") {
      sleep(3);
      $stove_return = $this->getStoveValue($ip, DATA_QUERY);
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
        // best-effort: an unreachable stove must not delay the read of
        // other equipments; the next cron tick retries anyway
        $jee4heat->getInformationFomStove($jee4heat, false);
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
    $_buffer = rtrim((string) $_buffer);
    if ($_buffer == '')
      return false; // check if buffer is empty, if yes, then do nothing 
    $message = substr($_buffer, 2, strlen($_buffer) - 4); // trim leading and trailing characters
    $ret = explode('","', $message); // translate string to array
    log::add(__CLASS__, 'debug', 'unpack $message =' . $message);
    //  log::add(__CLASS__, 'debug', 'unpack $ret ='.$ret[0]);
    if (($ret[0] != "SEL") && ($ret[0] != "SEC"))
      return false; // check for message consistency
    $nargs = intval($ret[1] ?? 0);
    log::add(__CLASS__, 'debug', 'number of registers returned =' . ($ret[1] ?? ''));
    if ($ret[0] == "SEC") {
      log::add(__CLASS__, 'debug', 'SEC status returned');
      // no register storage required  
      return true;
    }
    if ($nargs <= 2)
      return false; // check for message consistency

    // registers are read from the model JSON (single source of truth)
    $cfg = self::getDeviceDefinition($this->getConfiguration('modele'))['configuration'] ?? array();
    $_state = $cfg['state'] ?? STATE_REGISTER;
    $_error = $cfg['error'] ?? ERROR_REGISTER;
    for ($i = 2; $i < $nargs + 2; $i++) { // extract all parameters
      $item = $ret[$i] ?? ''; // guard against a truncated/short buffer (partial TCP read)
      // strict shape check: prefix + 5-digit register + 12-char value;
      // anything else is a truncated item and must not reach a command
      if (!preg_match('/^(.)(\d{5})([-\d]\d{11})$/', $item, $parts)) {
        log::add(__CLASS__, 'debug', "readregisters: malformed or truncated item at index $i: '$item'");
        continue;
      }
      $prefix = $parts[1];
      $register = $parts[2];
      $registervalue = intval($parts[3]); // drop leading zeros
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
            $cmdMessage->event(MODE_NAMES[$registervalue] ?? '-');
          // if state == 9, the stove is in blocked mode, so we set the binary indicator to TRUE else FALSE
          $cmdBlocked = $this->getCmd(null, 'jee4heat_stoveblocked');
          if (is_object($cmdBlocked))
            $cmdBlocked->event(($registervalue == 9));
          $cmdUnblock = $this->getCmd(null, 'jee4heat_unblock');
          $unblockVisible = ($registervalue == 9 ? 1 : 0);
          if (is_object($cmdUnblock) && $cmdUnblock->getIsVisible() != $unblockVisible) {
            $cmdUnblock->setIsVisible($unblockVisible);
            $cmdUnblock->save();
          }
        }
        if (($register == $_error) && ($registervalue > 0)) { // in the case of ERROR query set feddback in message field and overwrite default stove state message
          // update error information according to value
          $cmdMessage = $this->getCmd(null, 'jee4heat_stovemessage');
          if (is_object($cmdMessage))
            $cmdMessage->event("Erreur : " . (ERROR_NAMES[$registervalue] ?? ('code ' . $registervalue)));
        }
        // persist the prefix only when it changes (avoids a DB write per register per read)
        if ($Command->getConfiguration('jee4heat_prefix') !== $prefix) {
          $Command->setConfiguration('jee4heat_prefix', $prefix);
          $Command->save();
        }
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
    if (!is_object($command)) { // check if action is already defined by logicalId, if yes avoid duplicating
      $command = cmd::byEqLogicIdCmdName($this->getId(), $_actionTitle);
      if (!is_object($command)) { // also avoid duplicating an action with the same name
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
    $Command = $command; // default return value when the command already exists
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

  /**
   * Send a fire-and-forget command frame, retrying on transport errors,
   * then do a single best-effort refresh (the stove may take 1-5 min to
   * reflect the change, so a retried refresh would only block the caller).
   * @param string $_frame
   * @param string $_label short name for logs
   * @return bool true if the frame was delivered
   */
  private function sendStoveCommand($_frame, $_label)
  {
    $ip = $this->getConfiguration('ip');
    log::add(__CLASS__, 'debug', $_label . ' : ID=' . $this->getId() . ' IP du poele=' . $ip);
    if ($ip == '') {
      return false;
    }
    for ($attempt = 1; $attempt <= COMMAND_ATTEMPTS; $attempt++) {
      $stove_return = $this->getStoveValue($ip, $_frame);
      if ($stove_return !== "ERROR") {
        log::add(__CLASS__, 'debug', $_label . ' sent, socket has returned =' . $stove_return);
        return true;
      }
      if ($attempt < COMMAND_ATTEMPTS) {
        sleep(3);
      }
    }
    log::add(__CLASS__, 'warning', $_label . ' : le poêle n\'a pas répondu après ' . COMMAND_ATTEMPTS . ' tentatives');
    return false;
  }

  /**
   * this command toggles state of the stove to ON
   * if must be called only when the stove is in OFF mode (Etat=0)
   * @return void
   */
  public function state_on()
  {
    if ($this->sendStoveCommand(ON_CMD, 'on')) {
      $this->checkAndUpdateCmd('jee4heat_mode', 'heat');
      $this->getInformations(false);
    }
  }

  /**
   * this command toggles state of the stove to OFF
   * if must be called only when the stove is in ON mode (run state) and cannot be called if an error is raised
   * @return void
   */
  public function state_off()
  {
    if ($this->sendStoveCommand(OFF_CMD, 'off')) {
      $this->checkAndUpdateCmd('jee4heat_mode', 'off');
      $this->getInformations(false);
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
    $v = is_array($_options) && isset($_options['slider']) && is_numeric($_options['slider']) ? floatval($_options['slider']) : 0;
    log::add(__CLASS__, 'debug', 'slider value=' . $v);
    if ($v > 0)
      $this->updatesetpoint($v, true);
    else
      log::add(__CLASS__, 'debug', 'invalid slider value in eq=' . $this->getId());
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
    if ($this->sendStoveCommand(UNBLOCK_CMD, 'unblock')) {
      $this->getInformations(false);
    }
  }

  /**
   * Find this equipment's setpoint info command: the register declared as
   * "setpoint" in the model JSON, falling back to the THERMOSTAT_SETPOINT
   * generic type (user-customized or legacy equipments).
   * @return jee4heatCmd|null
   */
  private function getSetpointCmd()
  {
    $cfg = self::getDeviceDefinition($this->getConfiguration('modele'))['configuration'] ?? array();
    if (isset($cfg['setpoint'])) {
      $cmd = $this->getCmd('info', 'jee4heat_' . $cfg['setpoint']);
      if (is_object($cmd)) {
        return $cmd;
      }
    }
    $cmd = cmd::byGenericType('THERMOSTAT_SETPOINT', $this->getId(), true);
    return is_object($cmd) ? $cmd : null;
  }

  /**
   * mise à jour de la valeur de la consigne soit par incrément, soit par valeur qui vient écraser l'existante
   * @param float $_value incrément ou valeur à remplacer
   * @param bool $_absolute vrai vient écraser la valeur existante de consigne par $_value, faux vient ajouter $_valeur à la valeur actuelle de consigne
   * @return void
   */
  public function updatesetpoint($_value, $_absolute = false)
  {
    $ip = $this->getConfiguration('ip');
    $cmd = $this->getSetpointCmd();
    if (!is_object($cmd)) {
      log::add(__CLASS__, 'debug', "setpoint : command not found");
      return;
    }
    $setpoint = $cmd->getLogicalId();
    log::add(__CLASS__, 'debug', "setpoint : command found, logicalID=" . $setpoint);
    $v = $_absolute ? floatval($_value) : floatval($cmd->execCmd()) + $_value;

    // clamp to the range exposed by the slider so a +/- burst or a
    // scenario value can't push an out-of-range setpoint to the stove
    $slider = $this->getCmd('action', 'jee4heat_slider');
    $min = is_object($slider) && is_numeric($slider->getConfiguration('minValue')) ? floatval($slider->getConfiguration('minValue')) : SETPOINT_MIN;
    $max = is_object($slider) && is_numeric($slider->getConfiguration('maxValue')) ? floatval($slider->getConfiguration('maxValue')) : SETPOINT_MAX;
    if ($v < $min || $v > $max) {
      log::add(__CLASS__, 'debug', "setpoint : $v clamped to [$min, $max]");
      $v = max($min, min($max, $v));
    }
    log::add(__CLASS__, 'debug', "setpoint : new set point set to " . $v);

    $register = substr($setpoint, -5);
    // the prefix is only known after a first successful read
    $prefix = $cmd->getConfiguration('jee4heat_prefix') ?: 'J';
    for ($attempt = 1; $attempt <= COMMAND_ATTEMPTS; $attempt++) {
      if ($this->setStoveValue($ip, $register, $v, $prefix)) {
        log::add(__CLASS__, 'debug', "setpoint : acknowledged by stove");
        $this->getInformations(false);
        return;
      }
      if ($attempt < COMMAND_ATTEMPTS) {
        sleep(3);
      }
    }
    log::add(__CLASS__, 'warning', 'setpoint : le poêle n\'a pas acquitté la consigne ' . $v);
  }

  /**
   * stocke la référence de la consigne dans le curseur lors de la création
   * @param string $_slider ID logique du curseur
   * @return void
   */
  public function linksetpoint($_slider)
  {
    $Command = $this->getCmd('action', $_slider);
    if (!(is_object($Command))) {
      log::add(__CLASS__, 'debug', 'cannot find jee4heat_slider command in eq=' . $this->getId());
      return;
    }
    $cmd = $this->getSetpointCmd();
    if (!is_object($cmd)) {
      log::add(__CLASS__, 'debug', "setpoint : command not found");
      return;
    }
    if ($Command->getValue() != $cmd->getId()) {
      $Command->setValue($cmd->getId());
      $Command->save();
      log::add(__CLASS__, 'debug', "setpoint ID " . $cmd->getId() . " stored");
    }
  }

  /**
   * Load and cache the decoded device definition for a given model.
   * The model JSON (config/devices/<model>.json) is the single source of
   * truth for state/error registers and command list, so nothing is
   * denormalized into the eqLogic configuration.
   * @param string $_modele
   * @return array decoded device definition, or empty array if missing/invalid
   */
  private static function getDeviceDefinition($_modele)
  {
    static $cache = array();
    if ($_modele === null || $_modele === '') {
      return array();
    }
    if (array_key_exists($_modele, $cache)) {
      return $cache[$_modele];
    }
    $file = __DIR__ . DIRECTORY_DEVICELIST . $_modele . '.json';
    if (!is_file($file)) {
      return $cache[$_modele] = array();
    }
    $content = file_get_contents($file);
    if (!is_json($content)) {
      return $cache[$_modele] = array();
    }
    $device = json_decode($content, true);
    return $cache[$_modele] = (is_array($device) ? $device : array());
  }

  /**
   * Flag a model change so postSave can resync existing commands.
   */
  public function preSave()
  {
    $this->_modelChanged = false;
    if ($this->getId() != '') {
      $previous = self::byId($this->getId());
      if (is_object($previous) && $previous->getConfiguration('modele') != $this->getConfiguration('modele')) {
        log::add(__CLASS__, 'info', 'Changement de modèle pour ' . $this->getName() . ' : ' . $previous->getConfiguration('modele') . ' -> ' . $this->getConfiguration('modele'));
        $this->_modelChanged = true;
      }
    }
  }

  /**
   * Re-apply the model-driven attributes of an existing info command
   * (used after a model change; name, visibility and history are left to the user).
   * @param cmd $_cmd
   * @param array $_item command entry of the device JSON
   * @return void
   */
  private function syncCommandDefinition($_cmd, $_item)
  {
    $subtype = $_item['subtype'] ?? 'string';
    $template = $_item['template'] ?? 'tile';
    $_cmd->setSubType($subtype);
    $_cmd->setTemplate('dashboard', $template);
    $_cmd->setTemplate('mobile', $template);
    $_cmd->setUnite($subtype == 'numeric' ? ($_item['unit'] ?? '') : '');
    $_cmd->setGeneric_type($_item['generictype'] ?? '');
    $_cmd->setConfiguration('calculValueOffset', $_item['offset'] ?? '');
    $_cmd->setConfiguration('minValue', $_item['min'] ?? '');
    $_cmd->setConfiguration('maxValue', $_item['max'] ?? '');
    $_cmd->setDisplay('warningif', $_item['warningif'] ?? '');
    $_cmd->setDisplay('dangerif', $_item['dangerif'] ?? '');
    $_cmd->save();
  }

  public function postSave()
  {
    log::add(__CLASS__, 'debug', 'postsave start');

    $_eqName = $this->getName();
    log::add(__CLASS__, 'info', 'Sauvegarde de l\'équipement [postSave()] : ' . $_eqName);
    // the model JSON is the single source of truth: nothing is denormalized
    // into the eqLogic configuration here (registers are read from the JSON
    // at runtime by readregisters)
    $device = self::getDeviceDefinition($this->getConfiguration('modele'));
    if (empty($device) || !isset($device['commands'])) {
      log::add(__CLASS__, 'debug', 'postsave no usable device definition for ' . $_eqName . ', then do nothing');
      return;
    }
    $order = 0;
    log::add(__CLASS__, 'debug', 'postsave add commands on ID ' . $this->getId());
    foreach ($device['commands'] as $item) {
      log::add(__CLASS__, 'debug', 'postsave found commands array name=' . json_encode($item));
      // item name must match to json structure table items names, if not it takes null
      if (!empty($item['name']) && !empty($item['logicalId'])) {
        $existing = $this->getCmd(null, 'jee4heat_' . $item['logicalId']);
        if ($this->_modelChanged && is_object($existing)) {
          $this->syncCommandDefinition($existing, $item);
        }
        $this->AddCommand(
          $item['name'],
          'jee4heat_' . $item['logicalId'],
          $item['type'] ?? 'info',
          $item['subtype'] ?? 'string',
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

    $this->AddCommand(__('Etat', __FILE__), 'jee4heat_stovestate', "info", "binary", 'heat', '', 'THERMOSTAT_STATE', 1, 'default', 'default', 'default', 'default', $order++, '0', true, 'default', null, 2, null, null, null, 0);
    $this->AddCommand(__('Mode', __FILE__), 'jee4heat_mode', "info", "string", 'heat', '', 'THERMOSTAT_MODE', 0, 'default', 'default', 'default', 'default', $order++, '0', true, 'default', null, 2, null, null, null, 0);
    $this->AddCommand(__('Bloqué', __FILE__), 'jee4heat_stoveblocked', "info", "binary", 'jee4heat::mylocked', '', '', 1, 'default', 'default', 'default', 'default', $order++, '0', true, 'default', null, 2, null, null, null, 1);
    $this->AddCommand(__('Message', __FILE__), 'jee4heat_stovemessage', "info", "string", 'line', '', '', 1, 'default', 'default', 'default', 'default', $order++, '0', true, 'default', null, 2, null, null, null, 0);

    /* create on, off, unblock and refresh actions */
    $this->AddAction("jee4heat_on", "heat","default", "THERMOSTAT_MODE", 1);
    $this->AddAction("jee4heat_auto", "Auto","default", "THERMOSTAT_MODE", 0);
    $this->AddAction("jee4heat_off", "off","default", "THERMOSTAT_MODE", 1);
    $this->AddAction("jee4heat_unblock", __('Débloquer', __FILE__), "jee4heat::mylock");
    $this->AddAction("refresh", __('Rafraichir', __FILE__));
    $this->AddAction("jee4heat_stepup", "+", null, null, 0);
    $this->AddAction("jee4heat_stepdown", "-", null, null, 0);
    $this->AddAction("jee4heat_slider", "Régler consigne", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", 10, 25, 0.5);
    $this->linksetpoint("jee4heat_slider");
    //$this->AddAction("jee4heat_setvalue", "VV",  null, 'THERMOST_SET_SETPOINT', "slider");

    log::add(__CLASS__, 'debug', 'postsave stop');
    // best-effort refresh so saving never blocks on an unreachable stove;
    // the cron will retry within a minute and the next read refreshes the display
    $this->getInformations(false);
  }

  public function preUpdate()
  {
    log::add(__CLASS__, 'debug', 'preupdate start');

    if ($this->getConfiguration('ip') == '') {
      throw new Exception(__('Le champ IP ne peut être vide pour l\'équipement ', __FILE__) . $this->getName());
    }
    log::add(__CLASS__, 'debug', 'preupdate stop');
  }


  public function getInformations($_retry = true)
  {
    log::add(__CLASS__, 'debug', 'getinformation start');
    $this->getInformationFomStove($this, $_retry);
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
        array('operation' => '#value# == 10', 'state_light' => 'extinction', 'state_dark' => 'extinction'),
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
    $eqLogic = $this->getEqLogic();
    if (!is_object($eqLogic)) {
      log::add(__CLASS__, 'warning', 'execute: equipment not found for command ' . $this->getId());
      return;
    }
    switch ($action) {
      case 'refresh':
        $eqLogic->getInformations();
        break;
      case 'jee4heat_stepup':
        $eqLogic->updatesetpoint(0.5);
        break;
      case 'jee4heat_stepdown':
        $eqLogic->updatesetpoint(-0.5);
        break;
      case 'jee4heat_on':
      case 'jee4heat_auto':
        $eqLogic->state_on();
        break;
      case 'jee4heat_off':
        $eqLogic->state_off();
        break;
      case 'jee4heat_slider':
        // setpoint adjustment
        $eqLogic->set_setpoint($_options);
        break;
      case "jee4heat_unblock":
        $eqLogic->unblock();
        break;
      default:
        log::add(__CLASS__, 'warning', 'action to execute not found');
    }
  }
}
