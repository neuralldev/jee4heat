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
    // commands added by a release are created here, then each equipment
    // is migrated step by step up to SCHEMA_VERSION (see upgradeSchema)
    // an unusable model is skipped, like in postSave: it gets migrated on
    // the first save after its model is fixed
    if ($eqLogic->createCommands()) {
      $eqLogic->upgradeSchema();
    }
  }
}

function jee4heat_remove() {
}


