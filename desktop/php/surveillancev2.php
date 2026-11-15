<?php
/* =====================================================================================================
 *  PLUGIN surveillancev2 - FICHIER D'AFFICHAGE (desktop/php/surveillancev2.php)
 * =====================================================================================================
 *  Ce fichier décrit TOUS les écrans du plugin. Pour retrouver un élément, cherchez son repère :
 *
 *    ÉCRAN 1  : page d'accueil (liste des équipements, boutons Ajouter / Configuration / Logs)
 *    ÉCRAN 2  : page d'un équipement, composée de 3 onglets
 *       ONGLET A  « Equipement »
 *          BLOC A1  Identité de l'équipement (nom, objet parent, catégorie, activer, visible)
 *          BLOC A2  Commande surveillée (type, commande, inverser, compteur général)
 *          BLOC A3  Réglages de la mesure (analogique) : seuils actif / inactif, délai, heure alarme 1
 *          BLOC A4  Alarmes
 *                     A4-gauche  onglets Défaut / Lundi ... Dimanche (un champ par alarme)
 *                     A4-droite  paramètres communs : fréquence, répétition, remise à zéro périodique
 *       ONGLET B  « Commandes » (tableau rempli par le JavaScript)
 *       ONGLET C  « Actions »   (lignes remplies par le JavaScript : desktop/js/surveillancev2.js)
 *    STYLE    : feuille de style en fin de fichier (arrondis, hauteurs, espacements, alignements)
 *
 *  RÈGLES POUR MODIFIER SANS RIEN CASSER
 *    - Ne changez JAMAIS les attributs data-l1key / data-l2key et la classe eqLogicAttr d'un champ :
 *      c'est ce qui enregistre sa valeur.
 *    - Les textes entre {{ }} sont traduits ; si vous les changez, la traduction ne s'applique plus.
 *    - Largeurs : grille Bootstrap à 12 colonnes. Dans une ligne (form-group), le total des col-sm-N
 *      doit faire 12 (ex. libellé col-sm-6 + champ col-sm-3 + case col-sm-3).
 *    - Après modification : Ctrl+F5 dans le navigateur (le cache de Jeedom).
 * ===================================================================================================== */

if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('surveillancev2');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/* -----------------------------------------------------------------------------------------------------
 *  DONNÉES DU BLOC A4 : jours et champs d'alarme
 *  Modifiez ici l'ORDRE, les LIBELLÉS ou les CODES, sans toucher au HTML plus bas.
 * ----------------------------------------------------------------------------------------------------- */

/* Onglets de jours : id de l'onglet => [libellé, préfixe des clés de configuration]
 * (le préfixe vide '' = onglet « Défaut » ; 1 = Lundi ... 7 = Dimanche, comme avant). */
$ethJours = array(
	'defaut'   => array('{{Défaut}}',    ''),
	'lundi'    => array('{{Lundi}}',     '1'),
	'mardi'    => array('{{Mardi}}',     '2'),
	'mercredi' => array('{{Mercredi}}',  '3'),
	'jeudi'    => array('{{Jeudi}}',     '4'),
	'vendredi' => array('{{Vendredi}}',  '5'),
	'samedi'   => array('{{Samedi}}',    '6'),
	'dimanche' => array('{{Dimanche}}',  '7'),
);

/* Champs d'alarme affichés dans chaque onglet de jour, DANS L'ORDRE D'AFFICHAGE :
 * [code d'alarme, clé de configuration (sans préfixe), libellé]
 * La case « Répéter » de chaque ligne enregistre la clé  repeat<code>  (ex. repeat4). */
$ethChampsAlarme = array(
	array(2,  'tempsmini',           '{{Temps mini surveillance active (min)}}'),
	array(4,  'tempsmax',            '{{Temps max surveillance active (min)}}'),
	array(8,  'expectedstoppedtime', '{{Heure prévue surveillance inactive (HHMM)}}'),
	array(16, 'expectedstartedtime', '{{Heure prévue équipement en Marche (HHMM)}}'),
	array(64, 'inactivitemax',       '{{Durée d\'inactivité max surveillance (min)}}'),
	array(32, 'cptalarmehaute',      '{{Valeur compteur haut}}'),
);
?>

<div class="row row-overflow">

  <!-- ===================================================================================================
       ÉCRAN 1 : PAGE D'ACCUEIL DU PLUGIN
       =================================================================================================== -->
  <div class="col-xs-12 eqLogicThumbnailDisplay">

    <!-- Boutons de gestion : Ajouter / Configuration du plugin / Logs -->
    <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
    <div class="eqLogicThumbnailContainer">
      <div class="cursor eqLogicAction logoPrimary" data-action="add">
        <i class="fas fa-plus-circle"></i>
        <br>
        <span>{{Ajouter}}</span>
      </div>
      <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
        <i class="fas fa-wrench"></i>
        <br>
        <span>{{Configuration}}</span>
      </div>
      <div class="cursor eqLogicAction logoSecondary" data-action="gotoLogs">
        <i class="fas fa-file-alt"></i>
        <br>
        <span>{{Logs}}</span>
      </div>
    </div>

    <!-- Recherche + vignettes des équipements existants -->
    <legend><i class="fas fa-table"></i> {{Mes equipements à surveiller}}</legend>
    <div class="input-group" style="margin:5px;">
      <input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic" />
      <div class="input-group-btn">
        <a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>
      </div>
    </div>
    <div class="eqLogicThumbnailContainer">
      <?php
      foreach ($eqLogics as $eqLogic) {
        $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
        echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
        echo '<img src="' . $eqLogic->getImage() . '"/>';
        echo '<br>';
        echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
        echo '</div>';
      }
      ?>
    </div>
  </div>

  <!-- ===================================================================================================
       ÉCRAN 2 : PAGE D'UN ÉQUIPEMENT (masquée tant qu'aucun équipement n'est ouvert)
       =================================================================================================== -->
  <div class="col-xs-12 eqLogic" style="display: none;">

    <!-- Barre de boutons en haut à droite : Configuration avancée / Sauvegarder / Supprimer -->
    <div class="input-group pull-right" style="display:inline-flex;">
      <span class="input-group-btn">
        <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
      </span>
    </div>

    <!-- Les 3 onglets de l'équipement : Equipement / Commandes / Actions -->
    <ul class="nav nav-tabs" role="tablist" id="etheqtab">
      <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="tab" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
      <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="tab" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
      <li role="presentation"><a href="#commandtab" aria-controls="tab" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
      <li role="presentation"><a href="#actiontab" aria-controls="tab" role="tab" data-toggle="tab"><i class="fas fa-cogs"></i> {{Actions}}</a></li>
    </ul>

    <div class="tab-content">

      <!-- =================================================================================================
           ONGLET A : « EQUIPEMENT »
           ================================================================================================= -->
      <div role="tabpanel" class="tab-pane active" id="eqlogictab">
      <br/>
      <form class="form-horizontal">
        <fieldset>

          <!-- ---------------- BLOC A1 : IDENTITÉ DE L'ÉQUIPEMENT ---------------- -->
          <div class="form-group">
            <label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
            <div class="col-sm-3">
              <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
              <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}"/>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{{Objet parent}}</label>
            <div class="col-sm-3">
              <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                <option value="">{{Aucun}}</option>
                <?php
                foreach (jeeObject::all() as $jeeObject) {
                  echo '<option value="' . $jeeObject->getId() . '">' . $jeeObject->getName() . '</option>';
                }
                ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{{Catégorie}}</label>
            <div class="col-sm-9">
              <?php
              foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                echo '<label class="checkbox-inline">';
                echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
                echo '</label>';
              }
              ?>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label"></label>
            <div class="col-sm-9">
              <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" />{{Activer}}</label>
              <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" />{{Visible}}</label>
            </div>
          </div>

          <hr>

          <!-- ---------------- BLOC A2 : COMMANDE SURVEILLÉE ----------------
               Les blocs « cmdequipementtype » sont affichés / masqués par le JavaScript selon le type choisi
               (classes logique / analogique / general / not_general : à conserver). -->
          <div class="form-group">
            <label class="col-sm-3 control-label">{{Type de commande}}</label>
            <div class="col-sm-4">
              <select class="eqLogicAttr form-control" data-l1key='configuration' data-l2key='cmdequipementtype'>
                <option value='logique'>Logique</option>
                <option value='analogique' >Analogique</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{{Commande équipement}}</label>
            <div class="col-sm-4">
              <div class="input-group">
                <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="cmdequipement"/>
                <span class="input-group-btn">
                  <a class="btn btn-default ethSurvSelectCmd"><i class="fas fa-list-alt"></i></a>
                </span>
              </div>
            </div>
            <!-- Case « Compteur Général » (type analogique uniquement) -->
            <div class="cmdequipementtype analogique" style="display: none;">
              <div class="col-sm-4">
                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="general" />{{Compteur Général}}</label>
              </div>
            </div>
            <!-- Case « Inverser » (type logique uniquement) -->
            <div class="cmdequipementtype logique" style="display: none;">
              <div class="col-sm-4">
                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="inverse" />{{Inverser}}</label>
              </div>
            </div>
          </div>

          <!-- ---------------- BLOC A3 : RÉGLAGES DE LA MESURE (type analogique) ---------------- -->

          <!-- A3a : si « Compteur Général » est coché : heure de surveillance prévue (alarme 1) + valeur active -->
          <div class="cmdequipementtype analogique general" style="display: none;">
            <div class="form-group">
              <label class="col-sm-3 control-label">{{Heure de surveillance prévue +/- 2 min (HHMM)}}<span> (1<sup>*</sup>)</span></label>
              <div class="col-sm-2">
                <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="debutheure" />
              </div>
              <!-- Case « Répéter » de l'alarme 1 -->
              <div class="col-sm-2">
                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="repeat1" />{{Répéter l'alarme}}</label>
              </div>
              <label class="col-sm-2 control-label">{{Valeur surveillance active}}</label>
              <div class="col-sm-2">
                <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="puissance" />
              </div>
            </div>
          </div>

          <!-- A3b : sinon : seuil inactif, délai avant arrêt, seuil actif -->
          <div class="cmdequipementtype analogique not_general" style="display: none;">
            <div class="form-group">
              <label class="col-sm-3 control-label">{{Valeur surveillance inactive}}</label>
              <div class="col-sm-1">
                <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="minpuissance" />
              </div>
              <label class="col-sm-2 control-label">{{Délai valeur surveillance inactive (min)}}</label>
              <div class="col-sm-1">
                <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="minpuissancedelai" />
              </div>
              <label class="col-sm-2 control-label">{{Valeur surveillance active}}</label>
              <div class="col-sm-1">
                <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="maxpuissance" />
              </div>
            </div>
          </div>

          <hr>

          <!-- ---------------- BLOC A4 : ALARMES ----------------
               Deux moitiés côte à côte : A4-gauche (onglets par jour) et A4-droite (paramètres communs). -->
          <div class="row">

            <!-- ===== A4-GAUCHE : onglets Défaut / Lundi ... Dimanche ===== -->
            <div class="col-sm-6">

              <!-- Barre des onglets de jours (générée depuis le tableau $ethJours en haut du fichier) -->
              <ul class="nav nav-tabs" role="tablist">
                <?php
                foreach ($ethJours as $jourId => $jour) {
                  echo '<li role="' . $jourId . '"' . ($jourId == 'defaut' ? ' class="active"' : '') . '>';
                  echo '<a href="#' . $jourId . '" role="tab" aria-controls="' . $jourId . '" aria-expanded="' . ($jourId == 'defaut' ? 'true' : 'false') . '" data-toggle="tab">' . $jour[0] . '</a>';
                  echo '</li>';
                }
                ?>
              </ul>

              <!-- Contenu de chaque onglet : une ligne par alarme, générée depuis $ethChampsAlarme.
                   Chaque ligne = [ libellé (col-sm-6) ][ champ (col-sm-3) ][ case « Répéter » (col-sm-3) ].
                   La case « Répéter » n'existe que dans l'onglet Défaut (elle vaut pour tous les jours). -->
              <div class="tab-content">
                <?php
                foreach ($ethJours as $jourId => $jour) {
                  $prefixe = $jour[1];
                  echo '<div role="tabpanel" class="tab-pane' . ($jourId == 'defaut' ? ' active' : '') . '" id="' . $jourId . '" aria-labelledby="' . $jourId . '-tab">';

                  /* En-tête de la colonne des cases (onglet Défaut seulement) */
                  echo '<div class="form-group ethEntete">';
                  echo '<div class="col-sm-9"></div>';
                  echo '<div class="col-sm-3 ethCaseCol">' . ($jourId == 'defaut' ? '{{Répéter les alarmes}}' : '') . '</div>';
                  echo '</div>';

                  foreach ($ethChampsAlarme as $champ) {
                    list($code, $cle, $libelle) = $champ;
                    echo '<div class="form-group">';
                    /* Libellé + code d'alarme entre parenthèses (le * renvoie à la légende « Code d'alarme ») */
                    echo '<label class="col-sm-6 control-label">' . $libelle . '<span> (' . $code . '<sup>*</sup>)</span></label>';
                    /* Champ de saisie : clé = préfixe du jour + nom du champ (ex. 3tempsmax = mercredi) */
                    echo '<div class="col-sm-3">';
                    echo '<input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="' . $prefixe . $cle . '" />';
                    echo '</div>';
                    /* Case « Répéter l'alarme » (onglet Défaut seulement) */
                    echo '<div class="col-sm-3 ethCaseCol">';
                    if ($jourId == 'defaut') {
                      echo '<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="repeat' . $code . '" />';
                    }
                    echo '</div>';
                    echo '</div>';
                  }
                  echo '</div>';
                }
                ?>
              </div>
              <div class="ethLegende">(*) {{Code d'alarme}}</div>
            </div>

            <!-- ===== A4-DROITE : paramètres communs à toutes les alarmes =====
                 Le padding-top (classe ethDroite, voir STYLE) aligne la 1re ligne avec la 1re ligne de gauche. -->
            <div class="col-sm-6 ethDroite">

              <div class="form-group">
                <label class="col-sm-8 control-label">{{Fréquence de vérification des alarmes (min)}}</label>
                <div class="col-sm-4">
                  <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="frequenceverif" placeholder="5" />
                </div>
              </div>

              <div class="form-group">
                <label class="col-sm-8 control-label">{{Répétition des alarmes (min)}}</label>
                <div class="col-sm-4">
                  <!-- ancienne clé conservée : inactiviterepeat (période commune à toutes les alarmes) -->
                  <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="inactiviterepeat" placeholder="15" />
                </div>
              </div>

              <div class="form-group">
                <label class="col-sm-8 control-label">{{Remise à zéro tous les (jours, 0 = jamais)}}</label>
                <div class="col-sm-4">
                  <input type="text" class="eqLogicAttr form-control tooltips" data-l1key="configuration" data-l2key="razjours" placeholder="0" />
                </div>
              </div>

              <div class="form-group">
                <label class="col-sm-8 control-label ethMultiLigne">{{Remettre à zéro}}</label>
                <div class="col-sm-4">
                  <!-- Encadré regroupant les deux cases (classe ethGroupeCases, voir STYLE) -->
                  <div class="ethGroupeCases">
                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="razactivite" />{{Temps d'activité}}</label>
                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="razinactivite" />{{Durée d'inactivité}}</label>
                  </div>
                </div>
              </div>

            </div><!-- /A4-droite -->
          </div><!-- /BLOC A4 -->

        </fieldset>
      </form>
      </div><!-- /ONGLET A -->

      <!-- =================================================================================================
           ONGLET B : « COMMANDES »  (le tableau est rempli par addCmdToTable() dans le JavaScript)
           ================================================================================================= -->
      <div role="tabpanel" class="tab-pane" id="commandtab">
        <br/>
        <div class="table-responsive">
          <table id="table_cmd" class="table table-bordered table-condensed">
            <thead>
              <tr>
                <th>{{Nom}}</th><th>{{Type}}</th><th>{{Options}}</th><th></th>
              </tr>
            </thead>
            <tbody>
            </tbody>
          </table>
        </div>
      </div><!-- /ONGLET B -->

      <!-- =================================================================================================
           ONGLET C : « ACTIONS »  (les lignes sont ajoutées par addAction() dans le JavaScript)
           ================================================================================================= -->
      <div role="tabpanel" class="tab-pane" id="actiontab">
        <br/>
        <a class="btn btn-success btn-xs pull-right" id="btn_addethalEqAction"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
        <br/><br/>
        <form class="form-horizontal">
          <div id="div_ethalEqAction"></div>
        </form>
      </div><!-- /ONGLET C -->

    </div><!-- /.tab-content -->
  </div><!-- /ÉCRAN 2 -->
</div><!-- /.row -->

<!-- =====================================================================================================
     STYLE : apparence de l'écran équipement. Chaque règle est commentée ; modifiez les valeurs ici.
     ===================================================================================================== -->
<style>
/* Tous les champs de saisie : coins arrondis (border-radius), hauteur (height) et marge interne (padding) */
#eqlogictab .form-control, #actiontab .form-control, #commandtab .form-control { border-radius: 8px; height: 32px; padding: 4px 10px; }

/* Champ avec bouton collé (sélecteur de commande) : arrondi à gauche pour le champ, à droite pour le bouton */
#eqlogictab .input-group .form-control:not(:last-child) { border-radius: 8px 0 0 8px; }
#eqlogictab .input-group-btn .btn, #actiontab .input-group-btn .btn { border-radius: 0 8px 8px 0; height: 32px; }

/* Onglets des jours : coins du haut arrondis */
#eqlogictab .nav-tabs > li > a { border-radius: 8px 8px 0 0; }
/* Onglets des jours : marge interne réduite pour que les 8 onglets tiennent sur une seule ligne */
#eqlogictab .row .nav-tabs > li > a { padding: 8px 10px; }

/* Lignes de l'onglet Equipement : espace entre deux lignes (margin-bottom) et hauteur d'une ligne (min-height) */
#eqlogictab .form-group { margin-bottom: 14px; min-height: 32px; }

/* Libellés : alignés à droite et centrés verticalement sur le champ (6px + 20px + 6px = 32px, la hauteur du champ).
   Un libellé trop long passe sur 2 lignes sans casser l'alignement du champ voisin. */
#eqlogictab .form-group .control-label { line-height: 20px; padding-top: 6px; margin-bottom: 0; text-align: right; }
/* « Remettre à zéro » : aligné sur la première case */
#eqlogictab .form-group .control-label.ethMultiLigne { padding-top: 6px; }

/* Colonne des cases « Répéter » : cases centrées dans leur colonne, à la hauteur du champ voisin */
#eqlogictab .ethCaseCol { text-align: center; line-height: 32px; }
#eqlogictab .ethCaseCol input[type=checkbox] { margin: 0; vertical-align: middle; }

/* Ligne d'en-tête « Répéter les alarmes » au-dessus des cases (gras, hauteur réduite) */
#eqlogictab .ethEntete { min-height: 20px; margin-bottom: 4px; }
#eqlogictab .ethEntete .ethCaseCol { font-weight: bold; line-height: 20px; }

/* Colonne de droite (A4-droite) : décalage vers le bas pour tomber en face de la 1re ligne de gauche
   (hauteur des onglets + en-tête). Augmentez / diminuez cette valeur pour remonter / descendre la colonne. */
#eqlogictab .ethDroite { padding-top: 62px; }

/* Encadré autour des deux cases « Remettre à zéro » : bordure (border), arrondi, marge interne.
   Pour le supprimer : mettre « border: 0; padding: 0; » */
#eqlogictab .ethGroupeCases { border: 2px solid rgba(128,128,128,.35); border-radius: 6px; padding: 6px 10px; display: inline-block; }
#eqlogictab .ethGroupeCases .checkbox-inline { display: block; margin: 0; padding: 2px 0 2px 20px; line-height: 20px; }
#eqlogictab .ethGroupeCases .checkbox-inline input[type=checkbox] { margin: 4px 0 0 -20px; }

/* Légende « (*) Code d'alarme » sous les onglets */
#eqlogictab .ethLegende { margin-top: 10px; }
</style>

<?php include_file('desktop', 'surveillancev2', 'js', 'surveillancev2');?>
<?php include_file('core', 'plugin.template', 'js');?>
