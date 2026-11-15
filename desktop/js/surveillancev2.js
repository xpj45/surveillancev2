
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


//$("#table_cmd").sortable({axis: "y", cursor: "move", items: ".cmd", placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true});
$("#div_ethalEqAction").sortable({axis: "y", cursor: "move", items: ".ethalEqAction", placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true});


/*
 * Fonction pour l'ajout de commande, appellé automatiquement par plugin.template
 */


/* Ouverture de la fenêtre de choix de commande du cœur Jeedom.
   Un verrou court évite deux ouvertures simultanées (double clic) : la fenêtre est chargée en
   asynchrone et un second appel avant la fin du chargement provoque "mod_insertCmd is not defined". */
var ethSelectBusy = false;
function ethSelectCmdModal(_options, _callback) {
    if (ethSelectBusy) {
        return;
    }
    ethSelectBusy = true;
    setTimeout(function () {
        ethSelectBusy = false;
    }, 1500);
    jeedom.cmd.getSelectModal(_options, _callback);
}

$('body').off('click.surveillancev2', '.ethSurvSelectCmd').on('click.surveillancev2', '.ethSurvSelectCmd', function() {
    var el = $(this).closest('.input-group').find('.eqLogicAttr');
    ethSelectCmdModal({cmd: {type: 'info'}}, function (result) {
         el.val(result.human);
    });
});

/* Affichage des champs selon le type de commande (logique / analogique) et le mode "compteur général" */
function ethRefreshCmdTypeDisplay() {
    var type = $('.eqLogicAttr[data-l1key=configuration][data-l2key=cmdequipementtype]').val();
    var general = $('.eqLogicAttr[data-l1key=configuration][data-l2key=general]').is(':checked');

    $('.cmdequipementtype').hide();
    $('.cmdequipementtype.' + type).show();

    if (type == 'analogique') {
        if (general) {
            $('.cmdequipementtype.logique.not_general').hide();
            $('.cmdequipementtype.analogique.not_general').hide();
            $('.cmdequipementtype.analogique.general').show();
        } else {
            $('.cmdequipementtype.analogique.general').hide();
            $('.cmdequipementtype.logique.not_general').show();
            $('.cmdequipementtype.analogique.not_general').show();
        }
    }
}

$('.eqLogicAttr[data-l1key=configuration][data-l2key=cmdequipementtype]').on('change', ethRefreshCmdTypeDisplay);
$('.eqLogicAttr[data-l1key=configuration][data-l2key=general]').on('change', ethRefreshCmdTypeDisplay);


$('#btn_addethalEqAction').on('click', function () {
    addAction({}, 'ethalEqAction', '{{Action}}');
});


 /**************** Commun ***********/
 $('body').off('click.surveillancev2', '.ethSurvListCmdAction').on('click.surveillancev2', '.ethSurvListCmdAction', function () {
    var type = $(this).attr('data-type');
    var el = $(this).closest('.' + type).find('.expressionAttr[data-l1key=cmd]');
    ethSelectCmdModal({cmd: {type: 'action'}}, function (result) {
        el.val(result.human);
        jeedom.cmd.displayActionOption(el.val(), '', function (html) {
            el.closest('.' + type).find('.actionOptions').html(html);
            jeedomUtils.taAutosize();
        });
    });
});

$('body').off('click.surveillancev2', '.ethSurvListAction').on('click.surveillancev2', '.ethSurvListAction', function () {
  var type = $(this).attr('data-type');
  var el = $(this).closest('.' + type).find('.expressionAttr[data-l1key=cmd]');
  jeedom.getSelectActionModal({}, function (result) {
    el.val(result.human);
    jeedom.cmd.displayActionOption(el.val(), '', function (html) {
      el.closest('.' + type).find('.actionOptions').html(html);
      jeedomUtils.taAutosize();
  });
});
});

 $('body').off('click.surveillancev2', '.bt_removeAction').on('click.surveillancev2', '.bt_removeAction', function () {
    var type = $(this).attr('data-type');
    $(this).closest('.' + type).remove();
});

 $('body').off('focusout.surveillancev2', '.cmdAction.expressionAttr[data-l1key=cmd]').on('focusout.surveillancev2', '.cmdAction.expressionAttr[data-l1key=cmd]', function (event) {
    var type = $(this).attr('data-type')
    var expression = ethGetValues($(this).closest('.' + type), '.expressionAttr');
    var el = $(this);
    jeedom.cmd.displayActionOption($(this).val(), init(expression[0].options), function (html) {
        el.closest('.' + type).find('.actionOptions').html(html);
        jeedomUtils.taAutosize();
    })
});

 $('.nav-tabs li a').on('click',function(){
   setTimeout(function(){ 
    jeedomUtils.taAutosize();
}, 50);
})

/**************** End Commun ***********/


/* Accès aux valeurs des champs : API Jeedom 4.4+ (getJeeValues / setJeeValues) avec repli sur l'ancienne API jQuery */
function ethGetValues(_el, _attr) {
    var nodes = $(_el).get();
    if (nodes.length && typeof nodes[0].getJeeValues === 'function') {
        var res = [];
        nodes.forEach(function (node) {
            res = res.concat(node.getJeeValues(_attr));
        });
        return res;
    }
    return $(_el).getValues(_attr);
}

function ethSetValues(_el, _values, _attr) {
    var node = $(_el).get(0);
    if (node && typeof node.setJeeValues === 'function') {
        node.setJeeValues(_values, _attr);
    } else {
        $(_el).setValues(_values, _attr);
    }
}

function saveEqLogic(_eqLogic) {
    if (!isset(_eqLogic.configuration)) {
        _eqLogic.configuration = {};
    }
    _eqLogic.configuration.ethalEqAction = ethGetValues('#div_ethalEqAction .ethalEqAction', '.expressionAttr');
    return _eqLogic;
}


function printEqLogic(_eqLogic) {
    actionOptions = [];
    ethRefreshCmdTypeDisplay();
    $('#div_ethalEqAction').empty();
    if (isset(_eqLogic.configuration.ethalEqAction)) {
        for (var i in _eqLogic.configuration.ethalEqAction) {
            addAction(_eqLogic.configuration.ethalEqAction[i], 'ethalEqAction', '{{Action}}');
        }
    }
    jeedom.cmd.displayActionsOption({
        params : actionOptions,
        async : false,
        error: function (error) {
            jeedomUtils.showAlert({message: error.message, level: 'danger'});
        },
        success : function(data){
            for(var i in data){
                $('#'+data[i].id).append(data[i].html.html);
            }
            jeedomUtils.taAutosize();
        }
    });
}

function addAction(_action, _type, _name, _el) {
    if (!isset(_action)) {
        _action = {};
    }
    if (!isset(_action.options)) {
        _action.options = {};
    }
    var input = '';
    var div = '<div class="' + _type + '">';
    div += '<div class="form-group ">';
    div += '<label class="col-sm-1 control-label">' + _name + '</label>';
    div += '<div class="col-sm-2">';
    div += '<i class="fas fa-arrows-alt-v pull-left" style="margin-top : 9px; margin-right: 10px; "></i>';
    div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable" checked title="{{Décocher pour desactiver l\'action}}" />';
    //div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="background" title="{{Cocher pour que la commande s\'éxecute en parrallele des autres actions}}" />';
    div += '<select class="expressionAttr form-control input-sm" data-l1key="actionType" style="width:calc(100% - 50px);display:inline-block">';
    div += '<option value="etat">{{Etat}}</option>';
    div += '<option value="alarme">{{Alarme}}</option>';
    div += '</select>';
    div += '</div>';
    div += '<div class="col-sm-1">';
    div += '<label class="checkbox-inline"><input type="checkbox" class="expressionAttr" data-l1key="actionSens"/>{{Inverser}}</label>';
    div += '</div>';
    div += '<div class="col-sm-1 ethAlarmCodes">';
    div += '<input type="text" class="expressionAttr form-control input-sm" data-l1key="alarmCodes" placeholder="{{toutes}}" title="{{Codes d\'alarme (vide = toutes les alarmes) : 1, 2, 4, 8, 16, 32, 64. Exemple : 4,64}}" />';
    div += '</div>';
    div += '<div class="col-sm-3">';
    div += '<div class="input-group">';
    div += '<span class="input-group-btn">';

    div += '<a class="btn btn-default bt_removeAction btn-sm" data-type="' + _type + '"><i class="fas fa-minus-circle"></i></a>';
    div += '</span>';
    div += '<input class="expressionAttr form-control input-sm cmdAction" data-l1key="cmd" data-type="' + _type + '" />';
    div += '<span class="input-group-btn">';
    div += '<a class="btn btn-default btn-sm ethSurvListAction" data-type="' + _type + '" title="{{Sélectionner un mot-clé}}"><i class="fas fa-tasks"></i></a>';
    div += '<a class="btn btn-default btn-sm ethSurvListCmdAction" data-type="' + _type + '"><i class="fas fa-list-alt"></i></a>';
    div += '</span>';
    div += '</div>';
    div += '</div>';
    var actionOption_id = jeedomUtils.uniqId();
    div += '<div class="col-sm-4 actionOptions" id="'+actionOption_id+'">';
    div += '</div>';
    div += '</div>';
    if (isset(_el)) {
        _el.find('.div_' + _type).append(div);
        ethSetValues(_el.find('.' + _type).last(), _action, '.expressionAttr');
    } else {
        $('#div_' + _type).append(div);
        ethSetValues($('#div_' + _type + ' .' + _type).last(), _action, '.expressionAttr');
    }
    ethToggleAlarmCodes(_el ? _el.find('.' + _type).last() : $('#div_' + _type + ' .' + _type).last());
    actionOptions.push({
        expression : init(_action.cmd, ''),
        options : _action.options,
        id : actionOption_id
    });
}


function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="type"></span>';
    tr += '<br/>';
    tr += '<span class="cmdAttr" data-l1key="subType"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isVisible" checked/>{{Afficher}}</label></span> ';
    if (_cmd.subType == 'numeric') {
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
    var $tr = $('#table_cmd tbody tr').last();
    ethSetValues($tr, _cmd, '.cmdAttr');
    if (isset(_cmd.type)) {
        $tr.find('.cmdAttr[data-l1key=type]').val(init(_cmd.type));
    }
    jeedom.cmd.changeType($tr, init(_cmd.subType));
}

/* Bouton "Logs" de la page du plugin : ouvre le log surveillancev2 */
$('.eqLogicAction[data-action=gotoLogs]').off('click').on('click', function () {
    window.location.href = 'index.php?v=d&p=log&logfile=surveillancev2';
});

/* Le filtre "codes d'alarme" n'a de sens que pour les actions de type Alarme */
function ethToggleAlarmCodes(_row) {
    var isAlarm = $(_row).find('select[data-l1key=actionType]').val() == 'alarme';
    $(_row).find('.ethAlarmCodes').css('visibility', isAlarm ? 'visible' : 'hidden');
}
$('body').off('change.surveillancev2', 'select.expressionAttr[data-l1key=actionType]').on('change.surveillancev2', 'select.expressionAttr[data-l1key=actionType]', function () {
    ethToggleAlarmCodes($(this).closest('.ethalEqAction'));
});
