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

class surveillancev2 extends eqLogic
{
    /*
     * Fonction exécutée automatiquement toutes les minutes par Jeedom */
    public static function cron()
    {
        foreach (eqLogic::byType('surveillancev2', true) as $eq) {
            $equipementType      = '';
            $etat                = $eq->ethGetValue('etat');
            $pGeneral            = $eq->getConfiguration('general', '');
            $configCmdEquipement = $eq->getConfiguration('cmdequipement', '');

            $cmdEquipement = self::ethGetCmd($configCmdEquipement);
            if (is_object($cmdEquipement)) {
                if ($cmdEquipement->getSubType() === 'numeric') {
                    $equipementType = 'numeric';
                } elseif ($cmdEquipement->getSubType() === 'binary') {
                    $equipementType = 'binary';
                }
            }
            $_option = array('equipement_id' => $eq->getId());

            log::add('surveillancev2', 'debug', $eq->getName() . ' : cron : commande surveillée->' . $configCmdEquipement
                . ' type->' . ($equipementType !== '' ? $equipementType : 'inconnu/introuvable')
                . ' valeur->' . (is_object($cmdEquipement) ? var_export($cmdEquipement->execCmd(), true) : 'n/a')
                . ' etat plugin->' . $etat . ' compteur général->' . ($pGeneral == '1' ? 'oui' : 'non'));

            if ($equipementType === 'numeric' && $pGeneral != '1') {
                /* Filet de sécurité : la mesure est aussi vérifiée chaque minute, même si le listener ne se déclenche pas */
                log::add('surveillancev2', 'debug', $eq->getName() . ' : cron : mesure numérique => appel checkequipement');
                self::checkequipement(array('equipement_id' => $eq->getId(), 'origine' => 'cron'));
            } else {
                log::add('surveillancev2', 'debug', $eq->getName() . ' : cron : checkequipement non appelé par le cron (réservé à : commande numérique + pas de compteur général ; sinon traité sur changement de valeur de la commande)');
            }

            /* temps de fonctionnement / d'inactivité et alarmes périodiques (selon la fréquence de vérification) */
            try {
                self::ethCheckAlarmes($eq);
            } catch (\Throwable $e) {
                log::add('surveillancev2', 'error', $eq->getName() . ' : ethCheckAlarmes : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            }
        }
    }

    /**
     * Vérification périodique des alarmes (1, 4, 8, 16 et 64), appelée chaque minute par cron().
     * Les mises à jour des temps (fonctionnement / inactivité) sont faites à chaque appel ;
     * les tests d'alarme ne sont exécutés que toutes les "frequenceverif" minutes (défaut 5).
     */
    /*
     * Remise à zéro périodique : tous les N jours, remet à zéro le temps d'activité total
     * et/ou la durée d'inactivité selon les cases cochées dans la configuration.
     */
    private static function ethRazPeriodique($surveillancev2, $currentTime)
    {
        $jours = (int) self::ethNum($surveillancev2->getConfiguration('razjours', 0));
        $razAct = (int) self::ethNum($surveillancev2->getConfiguration('razactivite', 0));
        $razInact = (int) self::ethNum($surveillancev2->getConfiguration('razinactivite', 0));
        if ($jours <= 0 || ($razAct != 1 && $razInact != 1)) {
            return;
        }
        $last = (int) self::ethNum($surveillancev2->getConfiguration('lastrazperiodique', 0));
        if ($last <= 0) {
            /* première exécution : le décompte des jours commence maintenant */
            $surveillancev2->setConfiguration('lastrazperiodique', $currentTime);
            $surveillancev2->save();
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : RAZ périodique initialisée, prochaine dans ' . $jours . ' jour(s)');
            return;
        }
        if (($currentTime - $last) < $jours * 86400) {
            return;
        }
        $etat = $surveillancev2->ethGetValue('etat');
        if ($razAct == 1) {
            $enCours = ($etat == 1) ? ($currentTime - (int) self::ethNum($surveillancev2->getConfiguration('startedtime', 0))) : 0;
            /* si l'équipement tourne, on compense la période en cours pour que le total reparte de 0 */
            $surveillancev2->setConfiguration('previoustpsfct', -$enCours);
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal', 0);
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal_hms', '-');
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : RAZ périodique du temps d\'activité total');
        }
        if ($razInact == 1) {
            if ($etat == 0) {
                $surveillancev2->setConfiguration('stoppedtime', $currentTime);
                self::ethClearCode($surveillancev2, 64);
            }
            $surveillancev2->checkAndUpdateCmd('tempsinactif', 0);
            $surveillancev2->checkAndUpdateCmd('tempsinactif_hms', $surveillancev2->ethFormatTpsFct(0));
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : RAZ périodique de la durée d\'inactivité');
        }
        $surveillancev2->setConfiguration('lastrazperiodique', $currentTime);
        $surveillancev2->save();
    }

    private static function ethCheckAlarmes($surveillancev2)
    {
        $currentTime = time();

        /* remise à zéro périodique des temps d'activité / d'inactivité */
        self::ethRazPeriodique($surveillancev2, $currentTime);

        $etat                 = $surveillancev2->ethGetValue('etat');
        $alarme               = $surveillancev2->ethGetValue('alarme');
        $currentTempsFct      = $currentTime - self::ethNum($surveillancev2->getConfiguration('startedtime', 0));
        $currentTempsFctTotal = self::ethNum($surveillancev2->getConfiguration('previoustpsfct', 0)) + $currentTempsFct;

        /* mise à jour des commandes de mesure de temps si l'équipement est actif */
        if ($etat == 1) {
            $surveillancev2->checkAndUpdateCmd('tempsfct', $currentTempsFct);
            $surveillancev2->checkAndUpdateCmd('tempsfct_hms', $surveillancev2->ethFormatTpsFct($currentTempsFct));
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal', $currentTempsFctTotal);
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal_hms', $surveillancev2->ethFormatTpsFct($currentTempsFctTotal));
        }

        /* durée d'inactivité = temps écoulé depuis le dernier arrêt */
        $stopRef = self::ethNum($surveillancev2->getConfiguration('stoppedtime', 0));
        if ($etat == 0 && $stopRef <= 0) {
            /* aucun arrêt connu (équipement jamais démarré) : le comptage commence maintenant */
            $stopRef = $currentTime;
            $surveillancev2->setConfiguration('stoppedtime', $stopRef);
            $surveillancev2->save();
        }
        $dureeInactif = ($etat == 0 && $stopRef > 0) ? max(0, $currentTime - $stopRef) : 0;
        $surveillancev2->checkAndUpdateCmd('tempsinactif', $dureeInactif);
        $surveillancev2->checkAndUpdateCmd('tempsinactif_hms', $surveillancev2->ethFormatTpsFct($dureeInactif));

        /* fréquence de vérification des alarmes */
        $frequence = max(1, (int) self::ethNum($surveillancev2->getConfiguration('frequenceverif', 5), 5));
        $keyCheck  = 'surveillancev2::lastalarmcheck::' . $surveillancev2->getId();
        $lastCheck = (int) cache::byKey($keyCheck)->getValue(0);
        if ($lastCheck > 0 && ($currentTime - $lastCheck) < ($frequence * 60 - 20)) {
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : prochaine vérification des alarmes dans ' . (($frequence * 60) - ($currentTime - $lastCheck)) . ' s (fréquence ' . $frequence . ' min)');
            return;
        }
        cache::set($keyCheck, $currentTime);
        /* fenêtre de tolérance des heures prévues : au moins une période de vérification */
        $fenetre = ($frequence * 60) + 10;

        $expectedStoppedTime    = -1;
        $expectedStartedTime    = -1;
        $expectedStartedTimeMin = -1;
        $expectedStartedTimeMax = -1;
        $expectedStoppedTimeMin = -1;
        $expectedStoppedTimeMax = -1;

        $configDebutheure = $surveillancev2->getConfiguration('debutheure', '');

        $configExpectedStoppedTime = $surveillancev2->ethGetDayValue($currentTime, 'expectedstoppedtime', '');
        $configExpectedStartedTime = $surveillancev2->ethGetDayValue($currentTime, 'expectedstartedtime', '');
        $configTempsMini           = self::ethNum($surveillancev2->ethGetDayValue($currentTime, 'tempsmini', 0));
        $configTempsMax            = self::ethNum($surveillancev2->ethGetDayValue($currentTime, 'tempsmax', 0));

        log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : min temps set to->' . $configTempsMini .
                'max temps set to->' . $configTempsMax);

        /* verification debut heure */
        if (self::ethHHMMToTimestamp($configDebutheure) !== null) {
            $debutHeure    = self::ethHHMMToTimestamp($configDebutheure);
            $debutHeureMin = $debutHeure - 120;
            $debutHeureMax = $debutHeure + 120;
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : debut heures set to->' . date('H:i:s', $debutHeureMin) . '/' . date('H:i:s', $debutHeure) . '/' . date('H:i:s', $debutHeureMax));
        } else {
            $debutHeure    = $currentTime;
            $debutHeureMin = $debutHeure;
            $debutHeureMax = $debutHeure;
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : debut heure default set to->' . date('H:i:s', $debutHeureMin) . '/' . date('H:i:s', $debutHeure) . '/' . date('H:i:s', $debutHeureMax));
        }

        if (self::ethHHMMToTimestamp($configExpectedStoppedTime) === null) {
            $expectedStoppedTime    = -1;
            $expectedStoppedTimeMin = -1;
            $expectedStoppedTimeMax = -1;
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Arret prévu set to->' . $expectedStoppedTime);
        } else {
            $expectedStoppedTime    = self::ethHHMMToTimestamp($configExpectedStoppedTime);
            $expectedStoppedTimeMin = $expectedStoppedTime;
            $expectedStoppedTimeMax = $expectedStoppedTime + $fenetre;
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Arret prévu entre-> ' . date('H:i:s', $expectedStoppedTimeMin) . ' et ' . date('H:i:s', $expectedStoppedTimeMax));
        }

        if (self::ethHHMMToTimestamp($configExpectedStartedTime) === null) {
            $expectedStartedTime    = -1;
            $expectedStartedTimeMin = -1;
            $expectedStartedTimeMax = -1;
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Marche prévu set to->' . $expectedStartedTime);
        } else {
            $expectedStartedTime    = self::ethHHMMToTimestamp($configExpectedStartedTime);
            $expectedStartedTimeMin = $expectedStartedTime;
            $expectedStartedTimeMax = $expectedStartedTime + $fenetre;
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Marche prévu entre-> ' . date('H:i:s', $expectedStartedTimeMin) . ' et ' . date('H:i:s', $expectedStartedTimeMax));
        }

        log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : état->' . $etat . ' alarme->' . $alarme
            . ' temps de fonctionnement courant(s)->' . ($etat == 1 ? $currentTempsFct : 0) . ' heure->' . date('H:i:s', $currentTime));

        /* Alarme code 1 si pas demarré a l'heure prevu + temps mini de fonctionnement et debut heure non vide */
        if (self::ethLogTest($surveillancev2, 'alarmes', 'alarme 1 (pas démarré à l\'heure prévue + temps mini)', [
            'heure actuelle ' . date('H:i:s', $currentTime) . ' >= début+temps mini ' . date('H:i:s', $debutHeure + ($configTempsMini * 60)) => $currentTime >= ($debutHeure + ($configTempsMini * 60)),
            'état=0 (arrêté)' => $etat == 0,
            'temps mini(' . $configTempsMini . ' min) != 0' => $configTempsMini != 0,
        ])) {
            $firstAlarm = ($alarme == 0);
            self::ethRaiseAlarme($surveillancev2, 1, $alarme);
            if ($firstAlarm) {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Alarme debut heure->' . date('H:i:s', $debutHeure));
            }
        }
        /* alarme code 4 */
        if (self::ethLogTest($surveillancev2, 'alarmes', 'alarme 4 (temps max dépassé)', [
            'temps de fct ' . $currentTempsFct . ' s >= temps max ' . ($configTempsMax * 60) . ' s' => $currentTempsFct >= ($configTempsMax * 60),
            'état=1 (marche)' => $etat === 1,
            'temps max(' . $configTempsMax . ' min) != 0' => $configTempsMax != 0,
        ])) {
            $firstAlarm = ($alarme == 0);
            self::ethRaiseAlarme($surveillancev2, 4, $alarme);
            if ($firstAlarm) {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Alarme Temps Max->' . $currentTempsFct);
            }
        }

        /* alarme code 8 */
        if (self::ethLogTest($surveillancev2, 'alarmes', 'alarme 8 (arrêt prévu non respecté)', [
            'heure actuelle ' . date('H:i:s', $currentTime) . ' dans la fenêtre d\'arrêt prévu' => $currentTime >= $expectedStoppedTimeMin && $currentTime <= $expectedStoppedTimeMax,
            'état=1 (marche)' => $etat == 1,
            'arrêt prévu configuré' => $expectedStoppedTime != -1,
        ])) {
            $firstAlarm = ($alarme == 0);
            self::ethRaiseAlarme($surveillancev2, 8, $alarme);
            if ($firstAlarm) {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Alarme Expected Stopped Time->' . date('H:i:s', $expectedStoppedTimeMin) . '<' . date('H:i:s', $currentTime) . '>' . date('H:i:s', $expectedStoppedTimeMax));
            }
        }

        /* alarme code 16 */
        if (self::ethLogTest($surveillancev2, 'alarmes', 'alarme 16 (marche prévue non respectée)', [
            'heure actuelle ' . date('H:i:s', $currentTime) . ' dans la fenêtre de marche prévue' => $currentTime >= $expectedStartedTimeMin && $currentTime <= $expectedStartedTimeMax,
            'état=0 (arrêté)' => $etat == 0,
            'marche prévue configurée' => $expectedStartedTime != -1,
        ])) {
            $firstAlarm = ($alarme == 0);
            self::ethRaiseAlarme($surveillancev2, 16, $alarme);
            if ($firstAlarm) {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Alarme Expected Started Time->' . date('H:i:s', $expectedStartedTimeMin) . '<' . date('H:i:s', $currentTime) . '>' . date('H:i:s', $expectedStartedTimeMax));
            }
        }
        /* alarme code 64 : inactivité trop longue (sa répétition éventuelle est gérée comme pour toutes les alarmes) */
        $inactiviteMax = self::ethNum($surveillancev2->ethGetDayValue($currentTime, 'inactivitemax', 0));
        if (self::ethLogTest($surveillancev2, 'alarmes', 'alarme 64 (inactivité trop longue)', [
            'état=0 (arrêté)' => $etat == 0,
            'durée d\'inactivité max (' . $inactiviteMax . ' min) != 0' => $inactiviteMax != 0,
            'inactif depuis ' . $dureeInactif . ' s >= max ' . ($inactiviteMax * 60) . ' s' => $dureeInactif >= ($inactiviteMax * 60),
        ])) {
            $firstAlarm = ($alarme == 0);
            self::ethRaiseAlarme($surveillancev2, 64, $alarme);
            if ($firstAlarm) {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : Alarme inactivité->' . $dureeInactif . ' s');
            }
        }

        /* répétition des alarmes actives dont la case « Répéter » est cochée */
        self::ethRepeterAlarmes($surveillancev2, $currentTime);
    }

    /*
     * Répète les actions des alarmes actives pour lesquelles la répétition est activée
     * (case configuration "repeatN"), avec une période commune "inactiviterepeat" (minutes).
     */
    private static function ethRepeterAlarmes($surveillancev2, $currentTime)
    {
        $periode = max(1, (int) self::ethNum($surveillancev2->getConfiguration('inactiviterepeat', 15), 15));
        $actifs  = self::ethActiveCodes($surveillancev2->getCmd(null, 'code_alarme')->execCmd());
        foreach ($actifs as $code) {
            if ((int) self::ethNum($surveillancev2->getConfiguration('repeat' . $code, 0)) != 1) {
                continue;
            }
            $key  = 'surveillancev2::lastrepeat::' . $surveillancev2->getId() . '::' . $code;
            $last = (int) cache::byKey($key)->getValue(0);
            if ($last == 0) {
                /* alarme active avant l'activation de la répétition : le décompte commence maintenant */
                cache::set($key, $currentTime);
                continue;
            }
            if (($currentTime - $last) >= ($periode * 60 - 20)) {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : répétition de l\'alarme ' . $code . ' (période ' . $periode . ' min)');
                self::doAction('ethalEqAction', 'alarme', 0, $surveillancev2, $code, [], true);
                cache::set($key, $currentTime);
            } else {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : alarmes : alarme ' . $code . ' répétée dans ' . (($periode * 60) - ($currentTime - $last)) . ' s');
            }
        }
    }

    /* Efface un seul code d'alarme (ex. 64 après remise à zéro de l'inactivité) */
    private static function ethClearCode($eq, $code)
    {
        $cmd = $eq->getCmd(null, 'code_alarme');
        if ($cmd->getConfiguration('ethalarmecode' . $code) != 1) {
            return;
        }
        $reste = max(0, (int) self::ethNum($cmd->execCmd()) - $code);
        $eq->checkAndUpdateCmd('code_alarme', $reste);
        $cmd->setConfiguration('ethalarmecode' . $code, 0);
        $cmd->save();
        cache::set('surveillancev2::lastrepeat::' . $eq->getId() . '::' . $code, 0);
        if ($reste == 0) {
            $eq->checkAndUpdateCmd('alarme', 0);
        }
        log::add('surveillancev2', 'debug', $eq->getName() . ' : code d\'alarme ' . $code . ' effacé');
    }

    /*
     * Fonction exécutée automatiquement toutes les heures par Jeedom
      public static function cronHourly() {

      }
     */

    /*
     * Fonction exécutée automatiquement tous les jours par Jeedom
      public static function cronDayly() {

      }
     */

    public static function deadCmd()
    {
        /* @var $matches array */
        $result = [];
        foreach (eqLogic::byType('surveillancev2') as $surveillancev2) {
            /* @var $matches array */
            $matches = [];
            preg_match_all("/#([0-9]*)#/", $surveillancev2->getConfiguration('cmdequipement', ''), $matches);
            foreach ($matches[1] as $cmd_id) {
                if (!cmd::byId(str_replace('#', '', $cmd_id))) {
                    $result[] = array('detail' => 'Ethal Surveillance ' . $surveillancev2->getHumanName(), 'help' => 'Type de commande', 'who' => '#' . $cmd_id . '#');
                }
            }
        }
        return $result;
    }

    /* public function preInsert() {

      }

      public function postInsert() {

      }

      public function preSave() {

      }

      public function postSave() {

      }

      public function preUpdate() {

      } */

    public function postSave()
    {
        try {
            $this->ethCreateCmd('surveillancev2');
        } catch (\Throwable $e) {
            /* l'erreur est aussi écrite dans le log du plugin pour pouvoir la copier */
            log::add('surveillancev2', 'error', $this->getName() . ' : postSave : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            log::add('surveillancev2', 'debug', $e->getTraceAsString());
            throw $e;
        }
    }

    public function preRemove()
    {
        $listener = listener::byClassAndFunction('surveillancev2', 'checkequipement', ['equipement_id' => $this->getId()]);
        if (is_object($listener)) {
            log::add('surveillancev2', 'debug', 'Suppression du listener->checkequipement');
            $listener->remove();
        }
    }

    /*  public function postRemove() {

      }

      /*
     * Non obligatoire mais permet de modifier l'affichage du widget si vous en avez besoin
      public function toHtml($_version = 'dashboard') {

      }
     */

    /* public Ethal Surveillance plugin function */
    public static function checkequipement($_option)
    {
        log::add('surveillancev2', 'debug', 'checkequipement started, déclencheur->' . json_encode($_option));

        $surveillancev2 = surveillancev2::byId($_option['equipement_id']);
        if (is_object($surveillancev2) && $surveillancev2->getIsEnable() == 1) {

            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : equipement trouvé et actif');

            $equipementType         = '';
            $currentTime            = time();
            $minPuissanceDelaiReach = 0;

            $expectedStoppedTime    = -1;
            $expectedStartedTime    = -1;
            $expectedStartedTimeMin = -1;
            $expectedStartedTimeMax = -1;
            $expectedStoppedTimeMin = -1;
            $expectedStoppedTimeMax = -1;

            $etat   = $surveillancev2->ethGetValue('etat');
            $alarme = $surveillancev2->ethGetValue('alarme');

            $configCmdEquipement = $surveillancev2->getConfiguration('cmdequipement', '');
            $puissance           = self::ethNum($surveillancev2->getConfiguration('puissance', -100000), -100000);
            $minPuissance        = self::ethNum($surveillancev2->getConfiguration('minpuissance', 0));
            $maxPuissance        = self::ethNum($surveillancev2->getConfiguration('maxpuissance', -10000), -10000);
            $pGeneral            = $surveillancev2->getConfiguration('general', '');
            $memoPuissance       = $surveillancev2->getConfiguration('memopuissance', ''); // Feature used
            $configDebutheure    = $surveillancev2->getConfiguration('debutheure', '');

            $minPuissanceDelai     = self::ethNum($surveillancev2->getConfiguration('minpuissancedelai', 0));
            $memoCurrentTime       = self::ethNum($surveillancev2->getConfiguration('memocurrenttime', 0));
            $memoCurrentTimeStatus = self::ethNum($surveillancev2->getConfiguration('memocurrenttimestatus', 0));

            $configExpectedStoppedTime = $surveillancev2->ethGetDayValue($currentTime, 'expectedstoppedtime', '');
            $configExpectedStartedTime = $surveillancev2->ethGetDayValue($currentTime, 'expectedstartedtime', '');
            $configTempsMini           = self::ethNum($surveillancev2->ethGetDayValue($currentTime, 'tempsmini', 0));
            $configTempsMax            = self::ethNum($surveillancev2->ethGetDayValue($currentTime, 'tempsmax', 0));
            $configCptAlarmeHaute      = self::ethNum($surveillancev2->ethGetDayValue($currentTime, 'cptalarmehaute', 0));

            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : min temps set to ->' . $configTempsMini .
                    ' max temps set to ->' . $configTempsMax .
                    ' min puissance set to->' . $minPuissance .
                    ' max puissance set to->' . $maxPuissance .
                    ' puissance set to ->' . $puissance);

            /* verification debut heure */
            if (self::ethHHMMToTimestamp($configDebutheure) !== null) {
                $debutHeure    = self::ethHHMMToTimestamp($configDebutheure);
                $debutHeureMin = $debutHeure - 120;
                $debutHeureMax = $debutHeure + 120;
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : debut heures set to->' . date('H:i:s', $debutHeureMin) . '/' . date('H:i:s', $debutHeure) . '/' . date('H:i:s', $debutHeureMax));
            } else {
                $debutHeure    = $currentTime;
                $debutHeureMin = $debutHeure;
                $debutHeureMax = $debutHeure;
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : debut heure default set to->' . date('H:i:s', $debutHeureMin) . '/' . date('H:i:s', $debutHeure) . '/' . date('H:i:s', $debutHeureMax));
            }

            if (self::ethHHMMToTimestamp($configExpectedStoppedTime) === null) {
                $expectedStoppedTime = -1;
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Arret prévu set to->' . $expectedStoppedTime);
            } else {
                $expectedStoppedTime    = self::ethHHMMToTimestamp($configExpectedStoppedTime);
                $expectedStoppedTimeMin = $expectedStoppedTime;
                $expectedStoppedTimeMax = $expectedStoppedTime + 310;
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Arret prévu entre ->' . date('H:i:s', $expectedStoppedTimeMin) . ' et ' . date('H:i:s', $expectedStoppedTimeMax));
            }

            if (self::ethHHMMToTimestamp($configExpectedStartedTime) === null) {
                $expectedStartedTime = -1;
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Marche prévu set to->' . $expectedStartedTime);
            } else {
                $expectedStartedTime    = self::ethHHMMToTimestamp($configExpectedStartedTime);
                $expectedStartedTimeMin = $expectedStartedTime;
                $expectedStartedTimeMax = $expectedStartedTime + 310;
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Marche prévu entre ->' . date('H:i:s', $expectedStartedTimeMin) . ' et ' . date('H:i:s', $expectedStartedTimeMax));
            }

            /* Verification de la commande de mesure de l'equipement */
            $cmdEquipement = self::ethGetCmd($configCmdEquipement);
            if (is_object($cmdEquipement)) {
                if ($cmdEquipement->getSubType() === 'numeric') {
                    $equipementType = 'numeric';
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Numeric : Power measurement name->' . $cmdEquipement->getHumanName() . ' Power measurement value->' . $cmdEquipement->execCmd());
                } elseif ($cmdEquipement->getSubType() === 'binary') {
                    $equipementType = 'binary';
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Binary : Equipement Cmd name->' . $cmdEquipement->getHumanName() . ' Cmd equipement state->' . $cmdEquipement->execCmd());
                } else {
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : NOT Binary/Numeric : Equipement Cmd name->' . $cmdEquipement->getHumanName());
                }
            } else {
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Equipment cmd not found');
                return;
            }

            $cmdValue = $cmdEquipement->execCmd();
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : valeur brute lue sur ' . $cmdEquipement->getHumanName() . '->' . var_export($cmdValue, true) . ' (type ' . $equipementType . ')');
            $compteur = self::ethNum($surveillancev2->getCmd(null, 'count')->execCmd());

            if ($pGeneral == '1' && $equipementType == 'numeric') {
                $cmdValue          = $cmdValue - $puissance;
                $minPuissance      = 0;
                $maxPuissance      = 0;
                $minPuissanceDelai = 0;
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : cpt général =1, min/max cmd set to->0');
            }

            if ($equipementType == 'binary') {
                $minPuissance      = 0;
                $maxPuissance      = 1;
                $minPuissanceDelai = 0;
                $inverse           = $surveillancev2->getConfiguration('inverse', '0');
                if ($inverse == '0') {
                    $cmdValue = $cmdValue;
                }
                if ($inverse == '1') {
                    $cmdValue = !$cmdValue;
                }
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : binary cmd, max cmd set to->1 min cmd set to->0 inverse->' . $inverse);
            }
            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value etat->' . $etat . ' value cmd ->' . $cmdValue . ' min cmd->' . $minPuissance . ' max cmd->' . $maxPuissance);
            /*
              0903 < 2330 < 0907
              2327 < 2330 < 2333
             */

            if ($equipementType == 'numeric' || $equipementType == 'binary') {

                if (self::ethLogTest($surveillancev2, 'checkequipement', 'démarrage équipement', [
                    'valeur ' . $cmdValue . ' >= seuil marche ' . $maxPuissance => $cmdValue >= $maxPuissance,
                    'état=0 (arrêté)' => $etat == 0,
                    'dans la plage d\'heure de début (ou aucune heure de début)' => (($currentTime >= $debutHeureMin && $currentTime <= $debutHeureMax) || $debutHeureMin == $debutHeure),
                ])) {

                    $etat = 1;
                    $surveillancev2->checkAndUpdateCmd('etat', $etat);

                    $surveillancev2->checkAndUpdateCmd('startedtime', date('H:i:s', $currentTime));
                    $surveillancev2->checkAndUpdateCmd('stoppedtime', '-');

                    $surveillancev2->checkAndUpdateCmd('count', $compteur + 1);

                    $surveillancev2->setConfiguration('startedtime', $currentTime);
                    $surveillancev2->setConfiguration('memopuissance', $cmdValue);
                    $surveillancev2->save();

                    //$alCode32 n'est pas utilsé, à supprimer ?
                    $alCode32 = $surveillancev2->getCmd(null, 'code_alarme')->getConfiguration('ethalarmecode32');
                    $alarme   = $surveillancev2->ethGetValue('alarme');
                    if ($alarme == 1) {
                        self::ethResetAlarme($surveillancev2);
                    }
                    $alarme = 0;

                    if (self::ethLogTest($surveillancev2, 'checkequipement', 'alarme 32 (compteur haut)', [
                        'compteur ' . ($compteur + 1) . ' >= seuil ' . $configCptAlarmeHaute => ($compteur + 1) >= $configCptAlarmeHaute,
                        'seuil != 0' => $configCptAlarmeHaute != 0,
                    ])) {
                        $firstAlarm = ($alarme == 0);
                        self::ethRaiseAlarme($surveillancev2, 32, $alarme);
                        if ($firstAlarm) {
                            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Valeur compteur haute->' . $surveillancev2->getCmd(null, 'count')->execCmd());
                        }
                        log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value change etat->' . $etat . ' compteur->' . $surveillancev2->getCmd(null, 'count')->execCmd());
                    }
                    self::doAction('ethalEqAction', 'etat', 0, $surveillancev2);
                }

                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value memoCurrentTimeStatus->' . $memoCurrentTimeStatus . ' value minPuissanceDelaiReach->' . $minPuissanceDelaiReach . ' value memoCurrentTime+minPuissanceDelai->' . ($memoCurrentTime + ($minPuissanceDelai * 60)));
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value change etat->' . $etat . ' compteur->' . $surveillancev2->getCmd(null, 'count')->execCmd());

                /* gestion du delai sur la puissance mini */
                if ($cmdValue <= $minPuissance && $etat == 1 && $equipementType == 'numeric' && $pGeneral != '1' && $memoCurrentTimeStatus == 0) {
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : valeur ' . $cmdValue . ' <= seuil arrêt ' . $minPuissance . ' => début du délai avant arrêt (' . $minPuissanceDelai . ' min)');
                    $memoCurrentTime       = $currentTime;
                    $memoCurrentTimeStatus = 1;
                    $surveillancev2->setConfiguration('memocurrenttime', $memoCurrentTime);
                    $surveillancev2->setConfiguration('memocurrenttimestatus', $memoCurrentTimeStatus);
                    $surveillancev2->save();
                }

                if ($cmdValue >= $minPuissance && $etat == 1 && $equipementType == 'numeric' && $pGeneral != '1' && $memoCurrentTimeStatus == 1) {
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : valeur ' . $cmdValue . ' >= seuil arrêt ' . $minPuissance . ' => délai avant arrêt annulé');
                    $memoCurrentTime       = $currentTime;
                    $memoCurrentTimeStatus = 0;
                    $surveillancev2->setConfiguration('memocurrenttime', $memoCurrentTime);
                    $surveillancev2->setConfiguration('memocurrenttimestatus', $memoCurrentTimeStatus);
                    $surveillancev2->save();
                }
                /* End gestion du delai sur la puissance mini */

                if ($currentTime >= ($memoCurrentTime + ($minPuissanceDelai * 60)) && $memoCurrentTimeStatus == 1) {
                    $minPuissanceDelaiReach = 1;
                }

                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value memoCurrentTimeStatus->' . $memoCurrentTimeStatus . ' value minPuissanceDelaiReach->' . $minPuissanceDelaiReach . ' value memoCurrentTime+minPuissanceDelai->' . ($memoCurrentTime + ($minPuissanceDelai * 60)));
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value change etat->' . $etat . ' compteur->' . $surveillancev2->getCmd(null, 'count')->execCmd());


                $stopDirect = ($cmdValue <= $minPuissance && ($equipementType == 'binary' || $pGeneral == '1') && $etat == 1);
                $stopDelai  = ($minPuissanceDelaiReach == 1 && $etat == 1);
                log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : TEST arrêt équipement : '
                    . 'arrêt direct (valeur ' . $cmdValue . ' <= seuil arrêt ' . $minPuissance . ' ; binaire ou compteur général ; état=1) = ' . ($stopDirect ? 'OUI' : 'non')
                    . ' ; arrêt après délai (délai atteint=' . $minPuissanceDelaiReach . ' ; état=1) = ' . ($stopDelai ? 'OUI' : 'non')
                    . ' => ' . (($stopDirect || $stopDelai) ? 'DÉCLENCHÉ' : 'non déclenché'));
                if ($stopDirect || $stopDelai) {

                    $etat                   = 0;
                    $minPuissanceDelaiReach = 0;

                    $surveillancev2->setConfiguration('memocurrenttime', 0);
                    $surveillancev2->setConfiguration('memocurrenttimestatus', 0);

                    $surveillancev2->checkAndUpdateCmd('etat', $etat);
                    $surveillancev2->checkAndUpdateCmd('stoppedtime', date('H:i:s', $currentTime));

                    $surveillancev2->setConfiguration('stoppedtime', $currentTime);
                    $surveillancev2->setConfiguration('memopuissance', 0);

                    $currentTempsFct      = $currentTime - self::ethNum($surveillancev2->getConfiguration('startedtime', 0));
                    $currentTempsFctTotal = self::ethNum($surveillancev2->getConfiguration('previoustpsfct', 0)) + $currentTempsFct;

                    $surveillancev2->setConfiguration('previoustpsfct', $currentTempsFctTotal);
                    $surveillancev2->save();

                    $fmtCurrentTempsFct      = $surveillancev2->ethFormatTpsFct($currentTempsFct);
                    $fmtCurrentTempsFctTotal = $surveillancev2->ethFormatTpsFct($currentTempsFctTotal);

                    $surveillancev2->checkAndUpdateCmd('tempsfct', $currentTempsFct);
                    $surveillancev2->checkAndUpdateCmd('tempsfct_hms', $fmtCurrentTempsFct);
                    $surveillancev2->checkAndUpdateCmd('tempsfcttotal', $currentTempsFctTotal);
                    $surveillancev2->checkAndUpdateCmd('tempsfcttotal_hms', $fmtCurrentTempsFctTotal);

                    /* Alarme Code 2 */
                    if (self::ethLogTest($surveillancev2, 'checkequipement', 'alarme 2 (arrêt avant temps mini)', [
                        'temps de fct ' . $currentTempsFct . ' s <= temps mini ' . ($configTempsMini * 60) . ' s' => $currentTempsFct <= ($configTempsMini * 60),
                        'temps mini(' . $configTempsMini . ' min) != 0' => $configTempsMini != 0,
                    ])) {
                        $firstAlarm = ($alarme == 0);
                        self::ethRaiseAlarme($surveillancev2, 2, $alarme);
                        if ($firstAlarm) {
                            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Temps min, alarme set to->1');
                        }
                    }

                    if (self::ethLogTest($surveillancev2, 'checkequipement', 'alarme 4 à l\'arrêt (temps max dépassé)', [
                        'temps de fct ' . $currentTempsFct . ' s >= temps max ' . ($configTempsMax * 60) . ' s' => $currentTempsFct >= ($configTempsMax * 60),
                        'temps max(' . $configTempsMax . ' min) != 0' => $configTempsMax != 0,
                    ])) {
                        $firstAlarm = ($alarme == 0);
                        self::ethRaiseAlarme($surveillancev2, 4, $alarme);
                        if ($firstAlarm) {
                            log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Temps max, alarme set to->1');
                        }
                    }

                    self::doAction('ethalEqAction', 'etat', 1, $surveillancev2);

                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : Started Time->' . $surveillancev2->getConfiguration('startedtime') . ' Stopped Time->' . $currentTime);
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value Temps mini(sec)->' . ($configTempsMini * 60) . ' Valeur Current Temps de fct->' . $currentTempsFct);
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value Temps max(sec)->' . ($configTempsMax * 60) . ' Valeur Current Temps de fct->' . $currentTempsFct);
                    log::add('surveillancev2', 'debug', $surveillancev2->getName() . ' : checkequipement : value change etat->' . $etat);
                }
            }

            $currentTempsFct      = $currentTime - self::ethNum($surveillancev2->getConfiguration('startedtime', 0));
            $currentTempsFctTotal = self::ethNum($surveillancev2->getConfiguration('previoustpsfct', 0)) + $currentTempsFct;

            /* Les alarmes périodiques (1, 4, 8, 16, 64) sont vérifiées par ethCheckAlarmes() selon la fréquence de vérification configurée */

            if ($etat == 1) {
                $fmtCurrentTempsFct      = $surveillancev2->ethFormatTpsFct($currentTempsFct);
                $fmtCurrentTempsFctTotal = $surveillancev2->ethFormatTpsFct($currentTempsFctTotal);

                $surveillancev2->checkAndUpdateCmd('tempsfct', $currentTempsFct);
                $surveillancev2->checkAndUpdateCmd('tempsfct_hms', $fmtCurrentTempsFct);
                $surveillancev2->checkAndUpdateCmd('tempsfcttotal', $currentTempsFctTotal);
                $surveillancev2->checkAndUpdateCmd('tempsfcttotal_hms', $fmtCurrentTempsFctTotal);
            }
        }
    }

    /* plugin private static function */

    /**
     * Journalise (niveau debug) un test avec le détail de chacune de ses conditions
     * et retourne true si TOUTES les conditions sont vraies.
     */
    private static function ethLogTest($_eq, $_ctx, $_label, $_conds)
    {
        $parts  = [];
        $result = true;
        foreach ($_conds as $desc => $ok) {
            $parts[] = $desc . ' = ' . ($ok ? 'OUI' : 'non');
            if (!$ok) {
                $result = false;
            }
        }
        log::add('surveillancev2', 'debug', $_eq->getName() . ' : ' . $_ctx . ' : TEST ' . $_label . ' : ' . implode(' ; ', $parts) . ' => ' . ($result ? 'DÉCLENCHÉ' : 'non déclenché'));
        return $result;
    }

    /**
     * Retourne un nombre (int ou float) à partir d'une valeur de configuration / de commande
     * éventuellement vide ou non numérique (PHP 8 lève une TypeError sur '' + 1).
     */
    public static function ethNum($_value, $_default = 0)
    {
        if (is_int($_value) || is_float($_value)) {
            return $_value;
        }
        if (is_string($_value) && is_numeric(trim($_value))) {
            return trim($_value) + 0;
        }
        return $_default;
    }

    /**
     * Convertit une heure saisie au format HHMM (ex: 2330, 0905, 905) en timestamp du jour.
     * Retourne null si la valeur est vide ou invalide.
     */
    private static function ethHHMMToTimestamp($_value)
    {
        $value = trim((string) $_value);
        if ($value === '' || !ctype_digit($value) || strlen($value) > 4) {
            return null;
        }
        $value = str_pad($value, 4, '0', STR_PAD_LEFT);
        $hour  = (int) substr($value, 0, 2);
        $min   = (int) substr($value, 2, 2);
        if ($hour > 23 || $min > 59) {
            return null;
        }
        $date = new \DateTime();
        $date->setTime($hour, $min, 0);
        return $date->getTimestamp();
    }

    /**
     * Retourne la commande surveillée ou null (cmd::byString lève une exception si elle est introuvable).
     */
    private static function ethGetCmd($_human)
    {
        if ($_human === null || $_human === '') {
            return null;
        }
        try {
            $cmd = cmd::byString($_human);
            return is_object($cmd) ? $cmd : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function ethResetAlarme($eq)
    {

        $activeCodes = self::ethActiveCodes($eq->getCmd(null, 'code_alarme')->execCmd());
        $eq->checkAndUpdateCmd('alarme', 0);
        $eq->checkAndUpdateCmd('code_alarme', 0);
        $eq->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode1', 0);
        $eq->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode2', 0);
        $eq->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode4', 0);
        $eq->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode8', 0);
        $eq->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode16', 0);
        $eq->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode32', 0);
        $eq->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode64', 0);

        $eq->getCmd(null, 'code_alarme')->save();
        foreach ([1, 2, 4, 8, 16, 32, 64] as $c) {
            cache::set('surveillancev2::lastrepeat::' . $eq->getId() . '::' . $c, 0);
        }
        self::doAction('ethalEqAction', 'alarme', 1, $eq, null, $activeCodes);
        log::add('surveillancev2', 'debug', $eq->getName() . ' : ethResetAlarme : Alarme Reset');
    }

    private function ethAlarmeCode($code)
    {

        //$alCode = $eq->getConfiguration('alarmecode'.$code);
        $alCode = $this->getCmd(null, 'code_alarme')->getConfiguration('ethalarmecode' . $code);
        log::add('surveillancev2', 'debug', $this->getName() . ' : ethAlarmeCode : check Alarme Code ' . $code . ' current value->' . $alCode);

        if ($alCode != 1) {
            $this->checkAndUpdateCmd('code_alarme', self::ethNum($this->getCmd(null, 'code_alarme')->execCmd()) + $code);
            $this->getCmd(null, 'code_alarme')->setConfiguration('ethalarmecode' . $code, 1);
            $this->getCmd(null, 'code_alarme')->save();
            log::add('surveillancev2', 'debug', $this->getName() . ' : ethAlarmeCode : Alarme Code set to->' . $code);
            return true;
        }
        return false;
    }

    /**
     * Active le code d'alarme puis exécute les actions d'alarme :
     * - actions filtrées sur des codes : exécutées dès que leur code devient actif (ou à chaque répétition de l'alarme 64) ;
     * - actions sans filtre : exécutées uniquement quand c'est la première alarme (alarme passe de 0 à 1), ou à chaque répétition.
     */
    private static function ethRaiseAlarme($_eq, $_code, &$_alarme, $_repeat = false)
    {
        $isNew = $_eq->ethAlarmeCode($_code);
        if ($isNew) {
            cache::set('surveillancev2::lastrepeat::' . $_eq->getId() . '::' . $_code, time());
        }
        $first = ($_alarme == 0);
        if ($first) {
            $_alarme = 1;
            $_eq->checkAndUpdateCmd('alarme', 1);
        }
        if ($first || $isNew || $_repeat) {
            self::doAction('ethalEqAction', 'alarme', 0, $_eq, $_code, [], ($first || $_repeat));
        } else {
            log::add('surveillancev2', 'debug', $_eq->getName() . ' : alarme ' . $_code . ' déjà active : actions non relancées (elles ne repartent qu\'au prochain démarrage de l\'équipement, ou à chaque période si la case « Répéter » de l\'alarme est cochée)');
        }
    }

    /* Liste des codes d'alarme actifs à partir de la somme des codes (code_alarme) */
    private static function ethActiveCodes($_sum)
    {
        $active = [];
        foreach ([1, 2, 4, 8, 16, 32, 64] as $c) {
            if (((int) $_sum & $c) === $c) {
                $active[] = $c;
            }
        }
        return $active;
    }

    /* Convertit "1, 4;64" en [1, 4, 64] */
    private static function ethParseCodes($_value)
    {
        if (!is_string($_value) && !is_numeric($_value)) {
            return [];
        }
        $codes = [];
        foreach (preg_split('/[^0-9]+/', (string) $_value, -1, PREG_SPLIT_NO_EMPTY) as $c) {
            $codes[] = (int) $c;
        }
        return $codes;
    }

    private function ethFormatTpsFct($_val)
    {
        $result = '';
        if ((floor($_val / (3600 * 24))) == 0) {
            $result = gmdate('H:i:s', $_val);
        } else {
            $result = strval(floor($_val / (3600 * 24))) . 'j ' . gmdate('H:i:s', $_val);
        }
        log::add('surveillancev2', 'debug', 'Function : ethFormatTpsFct : Temps Fct->' . $result);
        return $result;
    }

    private function ethGetValue($_name,$_default = 0)
    {
        $result = $this->getCmd(null, $_name)->execCmd();
        if ($result === null || $result === '' || !is_numeric($result)) {
            $raw = $result;
            $this->checkAndUpdateCmd($_name, $_default);
            $result = $_default;
            log::add('surveillancev2', 'debug', $this->getName() . ' : ethGetValue : ' . $_name . ' valeur non initialisée (' . var_export($raw, true) . ') => initialisée à->' . $result);
        } else {
            $result = (int) $result;
            log::add('surveillancev2', 'debug', $this->getName() . ' : ethGetValue : ' . $_name . ' return value->' . $result);
        }
        return $result;
    }

    private function ethGetDayValue($_currentTime, $_key, $_default)
    {
        /* valeur propre au jour (1=lundi ... 7=dimanche) si renseignée, sinon valeur "Défaut", sinon valeur par défaut du plugin */
        $dayConfig = $this->getConfiguration(date('N', $_currentTime) . $_key, '');
        if ($dayConfig !== '' && $dayConfig !== null) {
            return $dayConfig;
        }
        $result = $this->getConfiguration($_key, '');
        if ($result === '' || $result === null) {
            return $_default;
        }
        return $result;
    }

    private static function doAction($_action, $_type, $_sens, $_eq, $_code = null, $_activeCodes = [], $_first = true)
    {

        $actions = $_eq->getConfiguration($_action, []);
        if (!is_array($actions)) {
            return;
        }
        log::add('surveillancev2', 'debug', $_eq->getName() . ' : doAction : recherche des actions de type->' . $_type . ' sens->' . $_sens . ' parmi ' . count($actions) . ' action(s) configurée(s)');
        foreach ($actions as $action) {
            if (!isset($action['cmd'], $action['actionSens'], $action['actionType'])) {
                continue;
            }
            /* A revoir pas tres clair
              if (is_object($cmd) && $this->getId() == $cmd->getEqLogic_id()) {
              log::add('surveillancev2', 'debug', 'Action-> Oups Cmd probleme');
              continue;
              }
             */
            // IF a revoir pas terrible
            if ($action['actionSens'] == $_sens && $action['actionType'] == $_type) {
                /* filtre par code d'alarme (champ "Codes d'alarme" de l'action, vide = toutes les alarmes) */
                if ($_type == 'alarme') {
                    $codes = self::ethParseCodes(isset($action['alarmCodes']) ? $action['alarmCodes'] : '');
                    if ($_sens == 0) {
                        if (!empty($codes) && !in_array((int) $_code, $codes, true)) {
                            log::add('surveillancev2', 'debug', $_eq->getName() . ' : doAction : action ' . $action['cmd'] . ' ignorée (réservée aux alarmes ' . implode(',', $codes) . ', alarme courante ' . $_code . ')');
                            continue;
                        }
                        if (empty($codes) && !$_first) {
                            log::add('surveillancev2', 'debug', $_eq->getName() . ' : doAction : action ' . $action['cmd'] . ' ignorée (sans filtre : exécutée seulement à la première alarme)');
                            continue;
                        }
                    } elseif (!empty($codes) && count(array_intersect($codes, $_activeCodes)) == 0) {
                        log::add('surveillancev2', 'debug', $_eq->getName() . ' : doAction : action ' . $action['cmd'] . ' ignorée à la fin d\'alarme (réservée aux alarmes ' . implode(',', $codes) . ', actives ' . implode(',', $_activeCodes) . ')');
                        continue;
                    }
                }
                try {
                    $options = [];
                    if (isset($action['options'])) {
                        $options = $action['options'];
                    }
                    log::add('surveillancev2', 'debug', 'Prepare for Action->' . $action['cmd'] . ' type->' . $action['actionType'] . '/' . $_type . ' Sens->' . $action['actionSens'] . '/' . $_sens);
                    if (isset($options['enable']) && $options['enable'] == '1') {
                        log::add('surveillancev2', 'debug', 'Done Action->' . $action['cmd'] . ' type->' . $action['actionType'] . '/' . $_type . ' Sens->' . $action['actionSens'] . '/' . $_sens . ' options->' . json_encode($options));
                        scenarioExpression::createAndExec('action', $action['cmd'], $options);
                    } else {
                        log::add('surveillancev2', 'debug', $_eq->getName() . ' : doAction : action ' . $action['cmd'] . ' ignorée (case « Activer » décochée)');
                    }
                } catch (\Throwable $e) {
                    log::add('surveillancev2', 'error', __('Erreur lors de l\'éxecution de ', __FILE__) . $action['cmd'] . __('. Détails : ', __FILE__) . $e->getMessage());
                }
            }
        }
    }

    private function ethCreateCmd($_name)
    {
        /* commande alarme code fonctionnement
          debut heure : 1
          Temps mini : 2
          Temps maxi : 4
          Arret prevu : 8
          Marche prevu : 16
          Compteur haut : 32
          Inactivité trop longue : 64
         */
        /* verify if the file existe */
        if (!is_file(dirname(__FILE__) . '/../config/devices/' . $_name . '.json')) {
            log::add('surveillancev2', 'error', 'fichier commande pas trouvé');
            return;
        }
        /* verify if the content is json type */
        $content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $_name . '.json');
        if (!is_json($content)) {
            log::add('surveillancev2', 'error', 'fichier commande impossible à lire');
            return;
        }
        /* verify if the content is well formated */
        $device = json_decode($content, true);
        if (!is_array($device) || !isset($device['commands'])) {
            log::add('surveillancev2', 'error', 'format fichier commande json mauvais');
            return;
        }
        log::add('surveillancev2', 'debug', 'fichier commande ok');
        /* create command */
        $commands = $device['commands'];
        $existingCmds = $this->getCmd();
        foreach ($commands as $command) {
            $cmd            = null;
            /* locate existing command */
            foreach ($existingCmds as $existingCmd) {
                if ((isset($command['logicalId']) && $existingCmd->getLogicalId() == $command['logicalId'])) {
                    $cmd = $existingCmd;
                    break;
                }
            }
            // if not exist create the command
            if ($cmd === null || !is_object($cmd)) {
                $cmd = new surveillancev2Cmd();
                $cmd->setEqLogic_id($this->getId());
                utils::a2o($cmd, $command);
                $cmd->save();
                log::add('surveillancev2', 'debug', 'Creation de la commande->' . $command['logicalId']);
            /*
            } else {
                $cmd = surveillancev2Cmd::byEqLogicIdAndLogicalId($this->getId(), $command['logicalId']);
                utils::a2o($cmd, $command);
                $cmd->save();
                log::add('surveillancev2', 'debug', 'Mise à jour de la commande->' . $command['logicalId']);
            */
            }

        }

        /* listener de la mesure de puissance our de la commande d'etat */
        $cmdSurveillee = trim((string) $this->getConfiguration('cmdequipement', ''));
        if ($this->getIsEnable() == 1 && $cmdSurveillee === '') {
            /* nouvel équipement : pas encore de commande à surveiller, donc pas de listener */
            $listener = listener::byClassAndFunction('surveillancev2', 'checkequipement', array('equipement_id' => $this->getId()));
            if (is_object($listener)) {
                $listener->remove();
            }
            log::add('surveillancev2', 'debug', $this->getName() . ' : aucune commande surveillée renseignée, listener non créé');
        } elseif ($this->getIsEnable() == 1) {
            $listener = listener::byClassAndFunction('surveillancev2', 'checkequipement', array('equipement_id' => $this->getId()));
            if (!is_object($listener)) {
                log::add('surveillancev2', 'debug', 'Création du listener->checkequipement');
                $listener = new listener();
            }
            $listener->setClass('surveillancev2');
            $listener->setFunction('checkequipement');
            $listener->setOption(array('equipement_id' => $this->getId()));
            $listener->emptyEvent();
            $listener->addEvent($this->getConfiguration('cmdequipement'));

            $listener->save();
            log::add('surveillancev2', 'debug', $this->getName() . ' : Mise à jour du listener->checkequipement id->' . $listener->getId() . ' événement surveillé->' . $this->getConfiguration('cmdequipement') . ' option->' . json_encode($listener->getOption()));
        }
    }

    /* public plugin function */

    public function ethCumulTps($_startDate = null, $_endDate = null)
    {
        $result       = [];
        $prevValue    = 0;
        $prevDatetime = 0;
        $day          = null;

        $etatCmd      = $this->getCmd(null, 'etat');
        if (!is_object($etatCmd)) {
            return $result;
        }
        foreach ($etatCmd->getHistory($_startDate, $_endDate) as $history) {
            if (date('Y-m-d', strtotime($history->getDatetime())) != $day && $prevValue == 1 && $day != null) {
                if (strtotime($day . ' 23:59:59') > $prevDatetime) {
                    $result[$day][1] += (strtotime($day . ' 23:59:59') - $prevDatetime) / 3600;
                }
                $prevDatetime = strtotime(date('Y-m-d 00:00:00', strtotime($history->getDatetime())));
            }
            $day = date('Y-m-d', strtotime($history->getDatetime()));
            if (!isset($result[$day])) {
                $result[$day] = array(strtotime($day . ' 00:00:00 UTC') * 1000, 0);
            }
            if ($history->getValue() == 1 && $prevValue == 0) {
                $prevDatetime = strtotime($history->getDatetime());
                $prevValue    = 1;
            }
            if ($history->getValue() == 0 && $prevValue == 1) {
                if ($prevDatetime > 0 && strtotime($history->getDatetime()) > $prevDatetime) {
                    $result[$day][1] += (strtotime($history->getDatetime()) - $prevDatetime) / 3600;
                }
                $prevValue = 0;
            }
        }

        return $result;
    }

    // Not used , work in progress
    public function ethCumulCpt($startDate = null, $endDate = null)
    {
        $result       = [];
        $prevValue    = 0;
        $prevDatetime = 0;
        $day          = null;

        $cptCmd       = $this->getCmd(null, 'count');
        if (!is_object($cptCmd)) {
            return $result;
        }
        foreach ($cptCmd->getHistory($startDate, $endDate) as $history) {
            if (date('Y-m-d', strtotime($history->getDatetime())) != $day && $prevValue == 1 && $day !== null) {
                if (strtotime($day . ' 23:59:59') > $prevDatetime) {
                    $result[$day][1] += (strtotime($day . ' 23:59:59') - $prevDatetime) / 3600;
                }
                $prevDatetime = strtotime(date('Y-m-d 00:00:00', strtotime($history->getDatetime())));
            }
            $day = date('Y-m-d', strtotime($history->getDatetime()));
            if (!isset($result[$day])) {
                $result[$day] = array(strtotime($day . ' 00:00:00 UTC') * 1000, 0);
            }
            if ($history->getValue() == 1 && $prevValue == 0) {
                $prevDatetime = strtotime($history->getDatetime());
                $prevValue    = 1;
            }
            if ($history->getValue() == 0 && $prevValue == 1) {
                if ($prevDatetime > 0 && strtotime($history->getDatetime()) > $prevDatetime) {
                    $result[$day][1] += (strtotime($history->getDatetime()) - $prevDatetime) / 3600;
                }
                $prevValue = 0;
            }
        }

        return $result;
    }

}

class surveillancev2Cmd extends cmd
{
    /*
     * Non obligatoire permet de demander de ne pas supprimer les commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
      public function dontRemoveCmd() {
      return true;
      }
     */

    public function execute($_options = [])
    {
        $surveillancev2 = $this->getEqLogic();

        log::add('surveillancev2', 'debug', 'action->' . $this->getLogicalId());

        if ($this->getLogicalId() == 'setcountplus') {
            $compteur = surveillancev2::ethNum($surveillancev2->getCmd(null, 'count')->execCmd());
            $surveillancev2->checkAndUpdateCmd('count', $compteur + 1);
            log::add('surveillancev2', 'debug', 'Set Compteurs plus 1');
        }
        if ($this->getLogicalId() == 'setcountmoins') {
            $compteur = surveillancev2::ethNum($surveillancev2->getCmd(null, 'count')->execCmd());
            $surveillancev2->checkAndUpdateCmd('count', $compteur - 1);
            log::add('surveillancev2', 'debug', 'Set Compteurs moins 1');
        }
        if ($this->getLogicalId() == 'razcount') {
            $surveillancev2->checkAndUpdateCmd('count', 0);
            log::add('surveillancev2', 'debug', 'RAZ Compteurs');
        }
        if ($this->getLogicalId() == 'raztempsfcttotal') {
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal', 0);
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal_hms', '-');
            $surveillancev2->setConfiguration('previoustpsfct', 0);
            $surveillancev2->save();
            log::add('surveillancev2', 'debug', 'RAZ Temps Fct Total');
        }
        if ($this->getLogicalId() == 'razall') {
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal', 0);
            $surveillancev2->checkAndUpdateCmd('tempsfcttotal_hms', '-');
            $surveillancev2->setConfiguration('previoustpsfct', 0);
            $surveillancev2->checkAndUpdateCmd('count', 0);
            $surveillancev2->save();
            log::add('surveillancev2', 'debug', 'RAZ All');
        }
    }

}