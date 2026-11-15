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

    if (!isConnect()) {
        throw new \Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    if (init('action') == 'ethGetData') {

        $return = array('eq' => array('eqName' => '', 'htmlMaster' => '', 'html' => '', 'ethCumulTps' => ''));

        if (init('eqid') == '') {
            ajax::success($return);
        }

        $surveillancev2 = surveillancev2::byId(init('eqid'));
        if (!is_object($surveillancev2) || $surveillancev2->getEqType_name() != 'surveillancev2') {
            throw new \Exception(__('Equipement surveillancev2 introuvable : ', __FILE__) . init('eqid'));
        }

        $date = array(
            'start' => init('dateStart'),
            'end' => init('dateEnd'),
        );
        if ($date['start'] == '') {
            $date['start'] = date('Y-m-d', strtotime('-1 months ' . date('Y-m-d')));
        }
        if ($date['end'] == '') {
            $date['end'] = date('Y-m-d', strtotime('+1 days ' . date('Y-m-d')));
        }

        $cmdEquipement = $surveillancev2->getConfiguration('cmdequipement', '');
        $eqMaster = null;
        try {
            $cmdMaster = cmd::byString($cmdEquipement);
            if (is_object($cmdMaster)) {
                $eqMaster = $cmdMaster->getEqLogic();
            }
        } catch (\Throwable $e) {
            $eqMaster = null;
        }
        if (!is_object($eqMaster)) {
            throw new \Exception(__('Equipement Master introuvable pour la commande: ', __FILE__) . $cmdEquipement);
        }

        if ($surveillancev2->getIsEnable() == 1) {
            $return['eq'] = array(
                'eqName' => $surveillancev2->getName(),
                'htmlMaster' => $eqMaster->toHtml('dashboard'),
                'html' => $surveillancev2->toHtml('dashboard'),
                'ethCumulTps' => array_values($surveillancev2->ethCumulTps($date['start'], $date['end'])),
            );
        }

        ajax::success($return);
    }

    throw new \Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
} catch (\Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
