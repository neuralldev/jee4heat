
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
  
  $(".li_eqLogic").on('click', function (event) {
    if (event.ctrlKey) {
      var type = $('body').attr('data-page')
      var url = '/index.php?v=d&m=' + type + '&p=' + type + '&id=' + $(this).attr('data-eqlogic_id')
      window.open(url).focus()
    } else {
      jeedom.eqLogic.cache.getCmd = Array();
      if ($('.eqLogicThumbnailDisplay').html() != undefined) {
        $('.eqLogicThumbnailDisplay').hide();
      }
      $('.eqLogic').hide();
      if ('function' == typeof (prePrintEqLogic)) {
        prePrintEqLogic($(this).attr('data-eqLogic_id'));
      }
      if (isset($(this).attr('data-eqLogic_type')) && isset($('.' + $(this).attr('data-eqLogic_type')))) {
        $('.' + $(this).attr('data-eqLogic_type')).show();
      } else {
        $('.eqLogic').show();
      }
      $(this).addClass('active');
      $('.nav-tabs a:not(.eqLogicAction)').first().click()
      $.showLoading()
      jeedom.eqLogic.print({
        type: isset($(this).attr('data-eqLogic_type')) ? $(this).attr('data-eqLogic_type') : eqType,
        id: $(this).attr('data-eqLogic_id'),
        status: 1,
        error: function (error) {
          $.hideLoading();
          $('#div_alert').showAlert({ message: error.message, level: 'danger' });
        },
        success: function (data) {
          $('body .eqLogicAttr').value('');
          if (isset(data) && isset(data.timeout) && data.timeout == 0) {
            data.timeout = '';
          }
          $('body').setValues(data, '.eqLogicAttr');
          if ('function' == typeof (printEqLogic)) {
            printEqLogic(data);
          }
          if ('function' == typeof (addCmdToTable)) {
            $('.cmd').remove();
            for (var i in data.cmd) {
              addCmdToTable(data.cmd[i]);
            }
          }
          $('body').delegate('.cmd .cmdAttr[data-l1key=type]', 'change', function () {
            jeedom.cmd.changeType($(this).closest('.cmd'));
          });
  
          $('body').delegate('.cmd .cmdAttr[data-l1key=subType]', 'change', function () {
            jeedom.cmd.changeSubType($(this).closest('.cmd'));
          });
          addOrUpdateUrl('id', data.id);
          $.hideLoading();
          modifyWithoutSave = false;
          setTimeout(function () {
            modifyWithoutSave = false;
          }, 1000)
        }
      });
    }
    return false;
  });
  
  function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
      var _cmd = { configuration: {} };
    }
    if (!isset(_cmd.configuration)) {
      _cmd.configuration = {};
    }
  
    if (init(_cmd.type) == 'info') {
      var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
      tr += '<td>';
      tr += '<span class="cmdAttr" data-l1key="id"></span>';
      tr += '</td>';
      tr += '<td>';
      tr += '<div class="row">';
      tr += '<div class="col-xs-7">';
      tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom de la commande}}">';
      tr += '</div>';
      tr += '<div class="col-xs-5">';
      tr += '<a class="cmdAction btn btn-default btn-sm" data-l1key="chooseIcon"><i class="fas fa-flag"></i> {{Icône}}</a>';
      tr += '<span class="cmdAttr" data-l1key="display" data-l2key="icon" style="margin-left : 10px;"></span>';
      tr += '</div>';
      tr += '</div>';
      tr += '<td>';
      tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isVisible" checked/>{{Afficher}}</label></span> ';
      if (_cmd.subType == 'numeric' || _cmd.subType == 'binary') {
        tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isHistorized" checked/>{{Historiser}}</label></span> ';
      }
      tr += '</td>';
      tr += '<td>';
      if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
      }
      tr += '</td>';
      tr += '</tr>';
      $('#table_cmd tbody').append(tr);
      $('#table_cmd tbody tr:last').setValues(_cmd, '.cmdAttr');
    } 
  }
  