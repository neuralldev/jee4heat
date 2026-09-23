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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function jee4heat_install() {
}

function jee4heat_update() {
  foreach (eqLogic::byType('jee4heat') as $eqLogic) {
    foreach ($eqLogic->getCmd('info') as $cmd) {
      $changed = false;
      // widget names were declared with a single colon, which core never resolves
      foreach (array('dashboard', 'mobile') as $version) {
        $template = $cmd->getTemplate($version, '');
        if (in_array($template, array('jee4heat:mypower', 'jee4heat:mypellets'))) {
          $cmd->setTemplate($version, str_replace('jee4heat:', 'jee4heat::', $template));
          $changed = true;
        }
      }
      // generic model exposed raw temperatures (x100) for ambient and setpoint
      if ($eqLogic->getConfiguration('modele') == 'generic'
        && in_array($cmd->getLogicalId(), array('jee4heat_50006', 'jee4heat_50138'))
        && $cmd->getConfiguration('calculValueOffset', '') == '') {
        $cmd->setConfiguration('calculValueOffset', '#value#/100');
        $changed = true;
      }
      if ($changed) {
        $cmd->save();
      }
    }
  }
}

function jee4heat_remove() {
}


