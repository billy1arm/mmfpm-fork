<?php


require_once 'header.php';
require_once 'libs/char_lib.php';
require_once 'libs/item_lib.php';
require_once 'libs/spell_lib.php';
require_once 'libs/map_zone_lib.php';
valid_login($action_permission['read']);

//########################################################################################################################
// SHOW GENERAL CHARACTERS INFO
//########################################################################################################################
function char_main(&$sqlr, &$sqlc)
{
  global $output, $lang_global, $lang_char, $lang_item,
    $realm_id, $realm_db, $characters_db, $world_db, $server, $mmfpm_db,
    $action_permission, $user_lvl, $user_name, $user_id,
    $item_datasite, $spell_datasite , $showcountryflag;

  // this page uses wowhead tooltops
  wowhead_tt();

  // we need at least an id or we would have nothing to show
  if (empty($_GET['id']))
    error($lang_global['empty_fields']);

  // this is multi realm support, as of writing still under development
  //  this page is already implementing it
  if (empty($_GET['realm']))
    $realmid = $realm_id;
  else
  {
    $realmid = $sqlr->quote_smart($_GET['realm']);
    if (is_numeric($realmid))
      $sqlc->connect($characters_db[$realmid]['addr'], $characters_db[$realmid]['user'], $characters_db[$realmid]['pass'], $characters_db[$realmid]['name']);
    else
      $realmid = $realm_id;
  }

  $id = $sqlc->quote_smart($_GET['id']);
  if (is_numeric($id));
  else error($lang_global['empty_fields']);

  $result = $sqlc->query('SELECT account, race FROM characters WHERE guid = '.$id.' LIMIT 1');

  if ($sqlc->num_rows($result))
  {
    //resrict by owner's gmlvl
    $owner_acc_id = $sqlc->result($result, 0, 'account');
    $query = $sqlr->query('SELECT gmlevel, username FROM account WHERE id = '.$owner_acc_id.'');
    $owner_gmlvl = $sqlr->result($query, 0, 'gmlevel');
    $owner_name = $sqlr->result($query, 0, 'username');

    if($user_lvl || $server[$realmid]['both_factions'])
    {
      $side_v = 0;
      $side_p = 0;
    }
    else
    {
      $side_p = (in_array($sqlc->result($result, 0, 'race'),array(2,5,6,8,10))) ? 1 : 2;
      $result_1 = $sqlc->query('SELECT race FROM characters WHERE account = '.$user_id.' LIMIT 1');
      if ($sqlc->num_rows($result))
        $side_v = (in_array($sqlc->result($result_1, 0, 'race'), array(2,5,6,8,10))) ? 1 : 2;
      else
        $side_v = 0;
      unset($result_1);
    }

    if ($user_lvl >= $owner_gmlvl && (($side_v === $side_p) || !$side_v))
    {
      $result = $sqlc->query('
				SELECT c.account, c.name, c.race, c.class, c.level, c.zone, c.map, c.online, c.totaltime, c.gender,
					c.totalHonorPoints, c.arenaPoints, c.totalKills,
					cs.blockPct, cs.dodgePct, cs.parryPct, cs.critPct, cs.rangedCritPct, cs.spellCritPct,
					cs.strength, cs.agility, cs.stamina, cs.intellect, cs.spirit, 
					c.health, c.power1, c.power2, c.power3, c.power4, c.power5, c.power6, c.power7,
					cs.maxhealth, cs.maxpower1, cs.maxpower2, cs.maxpower3, cs.maxpower4, cs.maxpower5, cs.maxpower6, cs.maxpower7,
					cs.resHoly, cs.resFire, cs.resNature, cs.resFrost, cs.resShadow, cs.resArcane, 
					cs.attackPower, cs.rangedAttackPower, cs.spellPower, cs.armor,
					c.equipmentCache, 
					COALESCE(guild_member.guildid,0) AS guildid, COALESCE(guild_member.rank,0) AS rank
				FROM characters c
					LEFT JOIN character_stats cs ON c.guid = cs.guid 
					LEFT JOIN guild_member ON c.guid = guild_member.guid 
				WHERE c.guid = '.$id);

      $char = $sqlc->fetch_assoc($result);
      $eq_data = explode(' ',$char['equipmentCache']);

      $online = ($char['online']) ? $lang_char['online'] : $lang_char['offline'];

      if($char['guildid'])
      {
        $guild_name = $sqlc->result($sqlc->query('SELECT name FROM guild WHERE guildid ='.$char['guildid'].''), 0, 'name');
        $guild_name = '<a href="guild.php?action=view_guild&amp;realm='.$realmid.'&amp;error=3&amp;id='.$char['guildid'].'" >'.$guild_name.'</a>';
        $mrank = $char['rank'];
        $guild_rank = $sqlc->result($sqlc->query('SELECT rname FROM guild_rank WHERE guildid ='.$char['guildid'].' AND rid='.$mrank.''), 0, 'rname');
      }
      else
      {
        $guild_name = $lang_global['none'];
        $guild_rank = $lang_global['none'];
      }

      $block       = round($char['blockPct'],2);
      $dodge       = round($char['dodgePct'],2);
      $parry       = round($char['parryPct'],2);
      $crit        = round($char['critPct'],2);
      $ranged_crit = round($char['rangedCritPct'],2);
      $maxdamage   = round($char['attackPower'],2);
      $mindamage   = round($char['attackPower'],2);
      $maxrangeddamage = round($char['rangedAttackPower'],2);
      $minrangeddamage = round($char['rangedAttackPower'],2);
      $spell_crit = round($char['spellCritPct'],2);
      $spell_damage = $char['spellPower'];
      $rage       = round($char['power2'] / 10);
      $maxrage    = round($char['maxpower2'] / 10);
      
      define('CHAR_DATA_OFFSET_MELEE_HIT',0);
      define('CHAR_DATA_OFFSET_SPELL_HEAL',1);
      define('CHAR_DATA_OFFSET_SPELL_HIT',2);
      define('CHAR_DATA_OFFSET_SPELL_HASTE_RATING',3);
      define('CHAR_DATA_OFFSET_RESILIENCE',4);
      define('CHAR_DATA_OFFSET_RANGE_HIT',5);
      $char_data = array(0,0,0,0,0,0);
      
      $expertise  = 0;
      $EQU_HEAD      = $eq_data[0];
      $EQU_NECK      = $eq_data[2];
      $EQU_SHOULDER  = $eq_data[4];
      $EQU_SHIRT     = $eq_data[6];
      $EQU_CHEST     = $eq_data[8];
      $EQU_BELT      = $eq_data[10];
      $EQU_LEGS      = $eq_data[12];
      $EQU_FEET      = $eq_data[14];
      $EQU_WRIST     = $eq_data[16];
      $EQU_GLOVES    = $eq_data[18];
      $EQU_FINGER1   = $eq_data[20];
      $EQU_FINGER2   = $eq_data[22];
      $EQU_TRINKET1  = $eq_data[24];
      $EQU_TRINKET2  = $eq_data[26];
      $EQU_BACK      = $eq_data[28];
      $EQU_MAIN_HAND = $eq_data[30];
      $EQU_OFF_HAND  = $eq_data[32];
      $EQU_RANGED    = $eq_data[34];
      $EQU_TABARD    = $eq_data[36];
/*
// reserved incase we want to use back minimanagers' built in tooltip, instead of wowheads'
// minimanagers' item tooltip needs updating, but it can show enchantments and sockets.

      $equiped_items = array
      (
         1 => array(($EQU_HEAD      ? get_item_tooltip($EQU_HEAD)      : 0),($EQU_HEAD      ? get_item_icon($EQU_HEAD)      : 0),($EQU_HEAD      ? get_item_border($EQU_HEAD)      : 0)),
         2 => array(($EQU_NECK      ? get_item_tooltip($EQU_NECK)      : 0),($EQU_NECK      ? get_item_icon($EQU_NECK)      : 0),($EQU_NECK      ? get_item_border($EQU_NECK)      : 0)),
         3 => array(($EQU_SHOULDER  ? get_item_tooltip($EQU_SHOULDER)  : 0),($EQU_SHOULDER  ? get_item_icon($EQU_SHOULDER)  : 0),($EQU_SHOULDER  ? get_item_border($EQU_SHOULDER)  : 0)),
         4 => array(($EQU_SHIRT     ? get_item_tooltip($EQU_SHIRT)     : 0),($EQU_SHIRT     ? get_item_icon($EQU_SHIRT)     : 0),($EQU_SHIRT     ? get_item_border($EQU_SHIRT)     : 0)),
         5 => array(($EQU_CHEST     ? get_item_tooltip($EQU_CHEST)     : 0),($EQU_CHEST     ? get_item_icon($EQU_CHEST)     : 0),($EQU_CHEST     ? get_item_border($EQU_CHEST)     : 0)),
         6 => array(($EQU_BELT      ? get_item_tooltip($EQU_BELT)      : 0),($EQU_BELT      ? get_item_icon($EQU_BELT)      : 0),($EQU_BELT      ? get_item_border($EQU_BELT)      : 0)),
         7 => array(($EQU_LEGS      ? get_item_tooltip($EQU_LEGS)      : 0),($EQU_LEGS      ? get_item_icon($EQU_LEGS)      : 0),($EQU_LEGS      ? get_item_border($EQU_LEGS)      : 0)),
         8 => array(($EQU_FEET      ? get_item_tooltip($EQU_FEET)      : 0),($EQU_FEET      ? get_item_icon($EQU_FEET)      : 0),($EQU_FEET      ? get_item_border($EQU_FEET)      : 0)),
         9 => array(($EQU_WRIST     ? get_item_tooltip($EQU_WRIST)     : 0),($EQU_WRIST     ? get_item_icon($EQU_WRIST)     : 0),($EQU_WRIST     ? get_item_border($EQU_WRIST)     : 0)),
        10 => array(($EQU_GLOVES    ? get_item_tooltip($EQU_GLOVES)    : 0),($EQU_GLOVES    ? get_item_icon($EQU_GLOVES)    : 0),($EQU_GLOVES    ? get_item_border($EQU_GLOVES)    : 0)),
        11 => array(($EQU_FINGER1   ? get_item_tooltip($EQU_FINGER1)   : 0),($EQU_FINGER1   ? get_item_icon($EQU_FINGER1)   : 0),($EQU_FINGER1   ? get_item_border($EQU_FINGER1)   : 0)),
        12 => array(($EQU_FINGER2   ? get_item_tooltip($EQU_FINGER2)   : 0),($EQU_FINGER2   ? get_item_icon($EQU_FINGER2)   : 0),($EQU_FINGER2   ? get_item_border($EQU_FINGER2)   : 0)),
        13 => array(($EQU_TRINKET1  ? get_item_tooltip($EQU_TRINKET1)  : 0),($EQU_TRINKET1  ? get_item_icon($EQU_TRINKET1)  : 0),($EQU_TRINKET1  ? get_item_border($EQU_TRINKET1)  : 0)),
        14 => array(($EQU_TRINKET2  ? get_item_tooltip($EQU_TRINKET2)  : 0),($EQU_TRINKET2  ? get_item_icon($EQU_TRINKET2)  : 0),($EQU_TRINKET2  ? get_item_border($EQU_TRINKET2)  : 0)),
        15 => array(($EQU_BACK      ? get_item_tooltip($EQU_BACK)      : 0),($EQU_BACK      ? get_item_icon($EQU_BACK)      : 0),($EQU_BACK      ? get_item_border($EQU_BACK)      : 0)),
        16 => array(($EQU_MAIN_HAND ? get_item_tooltip($EQU_MAIN_HAND) : 0),($EQU_MAIN_HAND ? get_item_icon($EQU_MAIN_HAND) : 0),($EQU_MAIN_HAND ? get_item_border($EQU_MAIN_HAND) : 0)),
        17 => array(($EQU_OFF_HAND  ? get_item_tooltip($EQU_OFF_HAND)  : 0),($EQU_OFF_HAND  ? get_item_icon($EQU_OFF_HAND)  : 0),($EQU_OFF_HAND  ? get_item_border($EQU_OFF_HAND)  : 0)),
        18 => array(($EQU_RANGED    ? get_item_tooltip($EQU_RANGED)    : 0),($EQU_RANGED    ? get_item_icon($EQU_RANGED)    : 0),($EQU_RANGED    ? get_item_border($EQU_RANGED)    : 0)),
        19 => array(($EQU_TABARD    ? get_item_tooltip($EQU_TABARD)    : 0),($EQU_TABARD    ? get_item_icon($EQU_TABARD)    : 0),($EQU_TABARD    ? get_item_border($EQU_TABARD)    : 0))
      );
*/

      $sqlm = new SQL;
      $sqlm->connect($mmfpm_db['addr'], $mmfpm_db['user'], $mmfpm_db['pass'], $mmfpm_db['name']);

      $sqlw = new SQL;
      $sqlw->connect($world_db[$realmid]['addr'], $world_db[$realmid]['user'], $world_db[$realmid]['pass'], $world_db[$realmid]['name']);

      $equiped_items = array
      (
         1 => array('',($EQU_HEAD      ? get_item_icon($EQU_HEAD, $sqlm, $sqlw)      : 0),($EQU_HEAD      ? get_item_border($EQU_HEAD, $sqlw)      : 0)),
         2 => array('',($EQU_NECK      ? get_item_icon($EQU_NECK, $sqlm, $sqlw)      : 0),($EQU_NECK      ? get_item_border($EQU_NECK, $sqlw)      : 0)),
         3 => array('',($EQU_SHOULDER  ? get_item_icon($EQU_SHOULDER, $sqlm, $sqlw)  : 0),($EQU_SHOULDER  ? get_item_border($EQU_SHOULDER, $sqlw)  : 0)),
         4 => array('',($EQU_SHIRT     ? get_item_icon($EQU_SHIRT, $sqlm, $sqlw)     : 0),($EQU_SHIRT     ? get_item_border($EQU_SHIRT, $sqlw)     : 0)),
         5 => array('',($EQU_CHEST     ? get_item_icon($EQU_CHEST, $sqlm, $sqlw)     : 0),($EQU_CHEST     ? get_item_border($EQU_CHEST, $sqlw)     : 0)),
         6 => array('',($EQU_BELT      ? get_item_icon($EQU_BELT, $sqlm, $sqlw)      : 0),($EQU_BELT      ? get_item_border($EQU_BELT, $sqlw)      : 0)),
         7 => array('',($EQU_LEGS      ? get_item_icon($EQU_LEGS, $sqlm, $sqlw)      : 0),($EQU_LEGS      ? get_item_border($EQU_LEGS, $sqlw)      : 0)),
         8 => array('',($EQU_FEET      ? get_item_icon($EQU_FEET, $sqlm, $sqlw)      : 0),($EQU_FEET      ? get_item_border($EQU_FEET, $sqlw)      : 0)),
         9 => array('',($EQU_WRIST     ? get_item_icon($EQU_WRIST, $sqlm, $sqlw)     : 0),($EQU_WRIST     ? get_item_border($EQU_WRIST, $sqlw)     : 0)),
        10 => array('',($EQU_GLOVES    ? get_item_icon($EQU_GLOVES, $sqlm, $sqlw)    : 0),($EQU_GLOVES    ? get_item_border($EQU_GLOVES, $sqlw)    : 0)),
        11 => array('',($EQU_FINGER1   ? get_item_icon($EQU_FINGER1, $sqlm, $sqlw)   : 0),($EQU_FINGER1   ? get_item_border($EQU_FINGER1, $sqlw)   : 0)),
        12 => array('',($EQU_FINGER2   ? get_item_icon($EQU_FINGER2, $sqlm, $sqlw)   : 0),($EQU_FINGER2   ? get_item_border($EQU_FINGER2, $sqlw)   : 0)),
        13 => array('',($EQU_TRINKET1  ? get_item_icon($EQU_TRINKET1, $sqlm, $sqlw)  : 0),($EQU_TRINKET1  ? get_item_border($EQU_TRINKET1, $sqlw)  : 0)),
        14 => array('',($EQU_TRINKET2  ? get_item_icon($EQU_TRINKET2, $sqlm, $sqlw)  : 0),($EQU_TRINKET2  ? get_item_border($EQU_TRINKET2, $sqlw)  : 0)),
        15 => array('',($EQU_BACK      ? get_item_icon($EQU_BACK, $sqlm, $sqlw)      : 0),($EQU_BACK      ? get_item_border($EQU_BACK, $sqlw)      : 0)),
        16 => array('',($EQU_MAIN_HAND ? get_item_icon($EQU_MAIN_HAND, $sqlm, $sqlw) : 0),($EQU_MAIN_HAND ? get_item_border($EQU_MAIN_HAND, $sqlw) : 0)),
        17 => array('',($EQU_OFF_HAND  ? get_item_icon($EQU_OFF_HAND, $sqlm, $sqlw)  : 0),($EQU_OFF_HAND  ? get_item_border($EQU_OFF_HAND, $sqlw)  : 0)),
        18 => array('',($EQU_RANGED    ? get_item_icon($EQU_RANGED, $sqlm, $sqlw)    : 0),($EQU_RANGED    ? get_item_border($EQU_RANGED, $sqlw)    : 0)),
        19 => array('',($EQU_TABARD    ? get_item_icon($EQU_TABARD, $sqlm, $sqlw)    : 0),($EQU_TABARD    ? get_item_border($EQU_TABARD, $sqlw)    : 0))
      );

      if (($user_lvl > $owner_gmlvl)||($owner_name === $user_name))
      {
        $output .= '
          <!-- start of char.php -->
          <center>
            <div id="tab_content">
              <div id="tab">
                <ul>
                  <li><a href="char_inv.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['inventory'].'</a></li>
                  <li><a href="char_extra.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['extra'].'</a></li>
                  '.(($char['level'] < 10) ? '' : '<li><a href="char_talent.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['talents'].'</a></li>').'
                  <li><a href="char_achieve.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['achievements'].'</a></li>
                  <li><a href="char_rep.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['reputation'].'</a></li>
                  <li><a href="char_skill.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['skills'].'</a></li>
                  <li><a href="char_quest.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['quests'].'</a></li>';
        if (char_get_class_name($char['class']) === 'Hunter' )
          $output .= '
                  <li><a href="char_pets.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['pets'].'</a></li>';
          $output .= '
                  <li><a href="char_friends.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['friends'].'</a></li>
				  <li><a href="char_spell.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['spells'].'</a></li>
				  <li><a href="char_mail.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['mail'].'</a></li>
                </ul>
                <ul>';
          // selected char tab at last 
          $output .= '
                  <li id="selected"><a href="char.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['char_sheet'].'</a></li>';
      }
      else
        $output .='
          <center>
            <div id="tab">
              <ul>
                <li id="selected"><a href="char.php?id='.$id.'&amp;realm='.$realmid.'">'.$lang_char['char_sheet'].'</a></li>
              </ul>
            </div>
            <div id="tab_content">
              <div id="tab">
                <ul>';
      $output .='
                </ul>
              </div>
              <div id="tab_content2">
                <table class="lined" style="width: 580px;">
                  <tr>
                    <td colspan="2">
                      <div>
                        <img src="'.char_get_avatar_img($char['level'], $char['gender'], $char['race'], $char['class'], 0).'" alt="avatar" />
                      </div>
                      <div>';
      $a_results = $sqlc->query('SELECT DISTINCT spell FROM character_aura WHERE guid = '.$id.'');
      if ($sqlc->num_rows($a_results))
      {
        while ($aura = $sqlc->fetch_assoc($a_results))
        {
                 $output .= '
                        <a style="padding:2px;" href="'.$spell_datasite.$aura['spell'].'" target="_blank">
                          <img src="'.spell_get_icon($aura['spell'], $sqlm).'" alt="'.$aura['spell'].'" width="24" height="24" />
                        </a>';
        }
      }
      $output .= '
                      </div>
                    </td>
                    <td colspan="4">
                      <font class="bold">
                        '.htmlentities($char['name'],ENT_QUOTES, 'UTF-8').' -
                        <img src="img/c_icons/'.$char['race'].'-'.$char['gender'].'.gif" onmousemove="toolTip(\''.char_get_race_name($char['race']).'\', \'item_tooltip\')" onmouseout="toolTip()" alt="" />
                        <img src="img/c_icons/'.$char['class'].'.gif" onmousemove="toolTip(\''.char_get_class_name($char['class']).'\', \'item_tooltip\')" onmouseout="toolTip()" alt="" />
                        - lvl '.char_get_level_color($char['level']).'
                      </font>
                      <br />'.get_map_name($char['map'], $sqlm).' - '.get_zone_name($char['zone'], $sqlm).'
                      <br />'.$lang_char['honor_points'].': '.$char['totalHonorPoints'].' / '.$char['arenaPoints'].' - '.$lang_char['honor_kills'].': '.$char['totalKills'].'
                      <br />'.$lang_char['guild'].': '.$guild_name.' | '.$lang_char['rank'].': '.htmlentities($guild_rank,ENT_QUOTES, 'UTF-8').'
                      <br />'.(($char['online']) ? '<img src="img/up.gif" onmousemove="toolTip(\'Online\', \'item_tooltip\')" onmouseout="toolTip()" alt="online" />' : '<img src="img/down.gif" onmousemove="toolTip(\'Offline\', \'item_tooltip\')" onmouseout="toolTip()" alt="offline" />');
      if ($showcountryflag)
      {
        require_once 'libs/misc_lib.php';
        $country = misc_get_country_by_account($char['account'], $sqlr, $sqlm);
        $output .= ' - '.(($country['code']) ? '<img src="img/flags/'.$country['code'].'.png" onmousemove="toolTip(\''.($country['country']).'\', \'item_tooltip\')" onmouseout="toolTip()" alt="" />' : '-');
        unset($country);
      }
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="6%">';
      if (($equiped_items[1][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_HEAD.'" target="_blank">
                        <img src="'.$equiped_items[1][1].'" class="'.$equiped_items[1][2].'" alt="Head" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_head.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td class="half_line" colspan="2" align="center" width="50%">
                      <div class="gradient_p">'.$lang_item['health'].':</div>
                      <div class="gradient_pp">'.$char['health'].'</div>';
      if ($char['class'] == 11) //druid
        $output .= '
                      </br>
                      <div class="gradient_p">'.$lang_item['energy'].':</div>
                      <div class="gradient_pp">'.$char['power4'].'</div>';
      $output .= '
                    </td>
                    <td class="half_line" colspan="2" align="center" width="50%">';
      if ($char['class'] == 1) // warrior
      {
        $output .= '
                      <div class="gradient_p">'.$lang_item['rage'].':</div>
                      <div class="gradient_pp">'.$rage.'</div>';
      }
      elseif ($char['class'] == 4) // rogue
      {
        $output .= '
                      <div class="gradient_p">'.$lang_item['energy'].':</div>
                      <div class="gradient_pp">'.$char['power4'].'</div>';
      }
      elseif ($char['class'] == 6) // death knight
      {
        // Don't know if FOCUS is the right one need to verify with Death Knight player.
        $output .= '
                      <div class="gradient_p">'.$lang_item['runic'].':</div>
                      <div class="gradient_pp">'.$char['power4'].'</div>';
      }
      elseif ($char['class'] == 11) // druid
      {
        $output .= '
                      <div class="gradient_p">'.$lang_item['mana'].':</div>
                      <div class="gradient_pp">'.$char['power1'].'</div>
                      </br>
                      <div class="gradient_p">'.$lang_item['rage'].':</div>
                      <div class="gradient_pp">'.$rage.'</div>';
      }
      elseif ($char['class'] == 2 || // paladin
              $char['class'] == 3 || // hunter
              $char['class'] == 5 || // priest
              $char['class'] == 7 || // shaman
              $char['class'] == 8 || // mage
              $char['class'] == 9)   // warlock
      {
        $output .= '
                      <div class="gradient_p">'.$lang_item['mana'].':</div>
                      <div class="gradient_pp">'.$char['power1'].'</div>';
      }
      $output .= '
                    </td>
                    <td width="6%">';
      if (($equiped_items[10][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_GLOVES.'" target="_blank">
                        <img src="'.$equiped_items[10][1].'" class="'.$equiped_items[10][2].'" alt="Gloves" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_gloves.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="1%">';
      if (($equiped_items[2][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_NECK.'" target="_blank">
                        <img src="'.$equiped_items[2][1].'" class="'.$equiped_items[2][2].'" alt="Neck" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_neck.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td class="half_line" colspan="2" rowspan="3" align="center" width="50%">
                      <div class="gradient_p">
                        '.$lang_item['strength'].':<br />
                        '.$lang_item['agility'].':<br />
                        '.$lang_item['stamina'].':<br />
                        '.$lang_item['intellect'].':<br />
                        '.$lang_item['spirit'].':<br />
                        '.$lang_item['armor'].':
                      </div>
                      <div class="gradient_pp">
                        '.$char['strength'].'<br />
                        '.$char['agility'].'<br />
                        '.$char['stamina'].'<br />
                        '.$char['intellect'].'<br />
                        '.$char['spirit'].'<br />
                        '.$char['armor'].'
                      </div>
                    </td>
                    <td class="half_line" colspan="2" rowspan="3" align="center" width="50%">
                      <div class="gradient_p">
                        '.$lang_item['res_holy'].':<br />
                        '.$lang_item['res_arcane'].':<br />
                        '.$lang_item['res_fire'].':<br />
                        '.$lang_item['res_nature'].':<br />
                        '.$lang_item['res_frost'].':<br />
                        '.$lang_item['res_shadow'].':
                      </div>
                      <div class="gradient_pp">
                        '.$char['resHoly'].'<br />
                        '.$char['resArcane'].'<br />
                        '.$char['resFire'].'<br />
                        '.$char['resNature'].'<br />
                        '.$char['resFrost'].'<br />
                        '.$char['resShadow'].'
                      </div>
                    </td>
                    <td width="1%">';
      if (($equiped_items[6][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_BELT.'" target="_blank">
                        <img src="'.$equiped_items[6][1].'" class="'.$equiped_items[6][2].'" alt="Belt" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_waist.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="1%">';
      if (($equiped_items[3][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_SHOULDER.'" target="_blank">
                        <img src="'.$equiped_items[3][1].'" class="'.$equiped_items[3][2].'" alt="Shoulder" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_shoulder.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td width="1%">';
      if (($equiped_items[7][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_LEGS.'" target="_blank">
                        <img src="'.$equiped_items[7][1].'" class="'.$equiped_items[7][2].'" alt="Legs" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_legs.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="1%">';
      if (($equiped_items[15][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_BACK.'" target="_blank">
                        <img src="'.$equiped_items[15][1].'" class="'.$equiped_items[15][2].'" alt="Back" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_chest_back.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td width="1%">';
      if (($equiped_items[8][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_FEET.'" target="_blank">
                        <img src="'.$equiped_items[8][1].'" class="'.$equiped_items[8][2].'" alt="Feet" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_feet.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="1%">';
      if (($equiped_items[5][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_CHEST.'" target="_blank">
                        <img src="'.$equiped_items[5][1].'" class="'.$equiped_items[5][2].'" alt="Chest" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_chest_back.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td class="half_line" colspan="2" rowspan="2" align="center" width="50%">
                      <div class="gradient_p">
                        '.$lang_char['melee_d'].':<br />
                        '.$lang_char['melee_ap'].':<br />
                        '.$lang_char['melee_hit'].':<br />
                        '.$lang_char['melee_crit'].':<br />
                        '.$lang_char['expertise'].':<br />
                      </div>
                      <div class="gradient_pp">
                        '.$mindamage.'-'.$maxdamage.'<br />
                        '.($char_data[CHAR_DATA_OFFSET_AP]+$char_data[CHAR_DATA_OFFSET_AP_MOD]).'<br />
                        '.$char_data[CHAR_DATA_OFFSET_MELEE_HIT].'<br />
                        '.$crit.'%<br />
                        '.$expertise.'<br />
                      </div>
                    </td>
                    <td class="half_line" colspan="2" rowspan="2" align="center" width="50%">
                      <div class="gradient_p">
                        '.$lang_char['spell_d'].':<br />
                        '.$lang_char['spell_heal'].':<br />
                        '.$lang_char['spell_hit'].':<br />
                        '.$lang_char['spell_crit'].':<br />
                        '.$lang_char['spell_haste'].'
                      </div>
                      <div class="gradient_pp">
                        '.$spell_damage.'<br />
                        '.$char_data[CHAR_DATA_OFFSET_SPELL_HEAL].'<br />
                        '.$char_data[CHAR_DATA_OFFSET_SPELL_HIT].'<br />
                        '.$spell_crit.'%<br />
                        '.$char_data[CHAR_DATA_OFFSET_SPELL_HASTE_RATING].'
                      </div>
                    </td>
                    <td width="1%">';
      if (($equiped_items[11][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_FINGER1.'" target="_blank">
                        <img src="'.$equiped_items[11][1].'" class="'.$equiped_items[11][2].'" alt="Finger1" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_finger.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="1%">';
      if (($equiped_items[4][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_SHIRT.'" target="_blank">
                        <img src="'.$equiped_items[4][1].'" class="'.$equiped_items[4][2].'" alt="Shirt" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_shirt.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td width="1%">';
      if (($equiped_items[12][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_FINGER2.'" target="_blank">
                        <img src="'.$equiped_items[12][1].'" class="'.$equiped_items[12][2].'" alt="Finger2" />
                      </a>';
      else $output .= '
                      <img src="img/INV/INV_empty_finger.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="1%">';
      if (($equiped_items[19][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_TABARD.'" target="_blank">
                        <img src="'.$equiped_items[19][1].'" class="'.$equiped_items[19][2].'" alt="Tabard" />
                      </a>';
      else $output .= '
                      <img src="img/INV/INV_empty_tabard.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td class="half_line" colspan="2" rowspan="2" align="center" width="50%">
                      <div class="gradient_p">
                        '.$lang_char['dodge'].':<br />
                        '.$lang_char['parry'].':<br />
                        '.$lang_char['block'].':<br />
                        '.$lang_char['resilience'].':<br />
                      </div>
                      <div class="gradient_pp">
                        '.$dodge.'%<br />
                        '.$parry.'%<br />
                        '.$block.'%<br />
                        '.$char_data[CHAR_DATA_OFFSET_RESILIENCE].'<br />
                      </div>
                    </td>
                    <td class="half_line" colspan="2" rowspan="2" align="center" width="50%">
                      <div class="gradient_p">
                        '.$lang_char['ranged_d'].':<br />
                        '.$lang_char['ranged_ap'].':<br />
                        '.$lang_char['ranged_hit'].':<br />
                        '.$lang_char['ranged_crit'].':<br />
                      </div>
                      <div class="gradient_pp">
                        '.$minrangeddamage.'-'.$maxrangeddamage.'<br />
                        '.($char_data[CHAR_DATA_OFFSET_RANGED_AP]+$char_data[CHAR_DATA_OFFSET_RANGED_AP_MOD]).'<br />
                        '.$char_data[CHAR_DATA_OFFSET_RANGE_HIT].'<br />
                        '.$ranged_crit.'%<br />
                      </div>
                    </td>
                    <td width="1%">';
      if (($equiped_items[13][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_TRINKET1.'" target="_blank">
                        <img src="'.$equiped_items[13][1].'" class="'.$equiped_items[13][2].'" alt="Trinket1" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_trinket.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td width="1%">';
      if (($equiped_items[9][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_WRIST.'" target="_blank">
                        <img src="'.$equiped_items[9][1].'" class="'.$equiped_items[9][2].'" alt="Wrist" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_wrist.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td width="1%">';
      if (($equiped_items[14][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_TRINKET2.'" target="_blank">
                        <img src="'.$equiped_items[14][1].'" class="'.$equiped_items[14][2].'" alt="Trinket2" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_trinket.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                  </tr>
                  <tr>
                    <td></td>
                    <td width="15%">';
      if (($equiped_items[16][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_MAIN_HAND.'" target="_blank">
                        <img src="'.$equiped_items[16][1].'" class="'.$equiped_items[16][2].'" alt="MainHand" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_main_hand.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td width="15%">';
      if (($equiped_items[17][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_OFF_HAND.'" target="_blank">
                        <img src="'.$equiped_items[17][1].'" class="'.$equiped_items[17][2].'" alt="OffHand" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_off_hand.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td width="15%">';
      if (($equiped_items[18][1]))
        $output .= '
                      <a style="padding:2px;" href="'.$item_datasite.$EQU_RANGED.'" target="_blank">
                        <img src="'.$equiped_items[18][1].'" class="'.$equiped_items[18][2].'" alt="Ranged" />
                      </a>';
      else
        $output .= '
                      <img src="img/INV/INV_empty_ranged.png" class="icon_border_0" alt="empty" />';
      $output .= '
                    </td>
                    <td width="15%"></td>
                    <td></td>
                  </tr>';
      if (($user_lvl > $owner_gmlvl)||($owner_name === $user_name))
      {
        //total time played
        $tot_time = $char['totaltime'];
        $tot_days = (int)($tot_time/86400);
        $tot_time = $tot_time - ($tot_days*86400);
        $total_hours = (int)($tot_time/3600);
        $tot_time = $tot_time - ($total_hours*3600);
        $total_min = (int)($tot_time/60);

        $output .= '
                  <tr>
                    <td colspan="6">
                      '.$lang_char['tot_paly_time'].': '.$tot_days.' '.$lang_char['days'].' '.$total_hours.' '.$lang_char['hours'].' '.$total_min.' '.$lang_char['min'].'
                    </td>
                  </tr>';
      }
      $output .= '
                </table>
              </div>
              <br />
            </div>
            <br />
            <table class="hidden">
              <tr>
                <td>';
                  // button to user account page, user account page has own security
                  makebutton($lang_char['chars_acc'], 'user.php?action=edit_user&amp;id='.$owner_acc_id.'', 130);
      $output .= '
                </td>
                <td>';

      // only higher level GM with delete access can edit character
      //  character edit allows removal of character items, so delete permission is needed
      if ( ($user_lvl > $owner_gmlvl) && ($user_lvl >= $action_permission['delete']) )
      {
                  makebutton($lang_char['edit_button'], 'char_edit.php?id='.$id.'&amp;realm='.$realmid.'', 130);
        $output .= '
                </td>
                <td>';
      }
      // only higher level GM with delete access, or character owner can delete character
      if ( ( ($user_lvl > $owner_gmlvl) && ($user_lvl >= $action_permission['delete']) ) || ($owner_name === $user_name) )
      {
                  makebutton($lang_char['del_char'], 'char_list.php?action=del_char_form&amp;check%5B%5D='.$id.'" type="wrn', 130);
        $output .= '
                </td>
                <td>';
      }
      // only GM with update permission can send mail, mail can send items, so update permission is needed
      if ($user_lvl >= $action_permission['update'])
      {
                  makebutton($lang_char['send_mail'], 'mail.php?type=ingame_mail&amp;to='.$char['name'].'', 130);
        $output .= '
                </td>
                <td>';
      }
                  makebutton($lang_global['back'], 'javascript:window.history.back()" type="def', 130);
      $output .= '
                </td>
              </tr>
            </table>
            <br />
          </center>
          <!-- end of char.php -->';
    }
    else
      error($lang_char['no_permission']);
  }
  else
    error($lang_char['no_char_found']);

}


//########################################################################################################################
// MAIN
//########################################################################################################################

// action variable reserved for future use
//$action = (isset($_GET['action'])) ? $_GET['action'] : NULL;

// load language
$lang_char = lang_char();

$output .= '
          <div class="top">
            <h1>'.$lang_char['character'].'</h1>
          </div>';

// we getting links to realm database and character database left behind by header
// header does not need them anymore, might as well reuse the link
char_main($sqlr, $sqlc);

//unset($action);
unset($action_permission);
unset($lang_char);

require_once 'footer.php';


?>
