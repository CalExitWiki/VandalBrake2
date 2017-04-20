<?php

class VandalBrake {

  //logging

  static function vandallogparolehandler( $type, $action, $title = NULL, $skin = NULL,
                                   $params = array(), $filterWikilinks = false )
  {
    if ($type == 'vandal' && $action = 'parole')
    {
      if( !$skin ) {
        return wfMsgExt('vandallogparole', array( 'replaceafter' ), $title->getPrefixedText());
      }

      if( substr( $title->getText(), 0, 1 ) == '#' ) {
        $titleLink = $title->getText();
      } else {
        $id = User::idFromName( $title->getText() );
        $titleLink = $skin->userLink( $id, $title->getText() )
        . $skin->userToolLinks( $id, $title->getText(), false, Linker::TOOL_LINKS_NOBLOCK );
      }
      return wfMsgExt('vandallogparole', array( 'replaceafter' ), $titleLink);
    }
  }

  static function vandallogvandalhandler( $type, $action, $title = NULL, $skin = NULL,
                                   $params = array(), $filterWikilinks = false )
  {
    if( !$skin ) {
      return wfMsgExt('vandallogvandal', array( 'replaceafter' ), $title->getPrefixedText(), $params[0]);
    }
    if ($type == 'vandal' && $action = 'vandal')
    {
      $id = User::idFromName( $title->getText() );
      $titleLink = $skin->userLink( $id, $title->getText() )
      . $skin->userToolLinks( $id, $title->getText(), false, Linker::TOOL_LINKS_NOBLOCK );
      //$parolelink = $skin->link( SpecialPage::getTitleFor( 'VandalBin' ),  wfMsgExt( 'parole', array( 'escape' ) ),
      //                           array(),array( 'action' => 'parole', 'wpVandAddress' => $title->getText() ), 'known' );
      return wfMsgExt('vandallogvandal', array( 'replaceafter' ), $titleLink, $params[0]);
    }
  }

  static function ModifyLog($log_type, $log_action, $title, $paramArray, &$comment, &$revert, $time)
  {
    if ($log_type === 'vandal' && $log_action === 'vandal') {
      global $wgUser;
      $revert = '('.$wgUser->getSkin()->link( SpecialPage::getTitleFor( 'VandalBin' ),  wfMsgExt( 'parolelink', array( 'escape' ) ),
                                          array(),array( 'action' => 'parole', 'wpVandAddress' => $title->getText() ), 'known' ) . ')';
    }
    return true;
  }

  static function doVandal($address,$userId,$reason,$blockCreation,$autoblock,$anononly,$dolog = true, $vandaler = null, $automatic = false) {
    global $wgUser;
    if (!$vandaler) $vandaler = $wgUser;
    $dbw = wfGetDB(DB_MASTER);
    if ($userId == 0)
    {
      $autoblock = false;
    } else {
      $anononly = false;
    }
    $a = array('vand_address' => $address,
               'vand_user' => $userId,
               'vand_by' => $vandaler->getId(),
               'vand_reason' => $reason,
               'vand_timestamp' => wfTimestampNow(),
               'vand_account' => $blockCreation,
               'vand_autoblock' => $autoblock,
               'vand_anon_only' => $anononly,
               'vand_auto' => $automatic
              );
    $dbw->insert('vandals',$a,'VandalBrake::doVandal');
    $dbw->commit();
    # autoblock
    if ($autoblock)
    {
      $res_ip = $dbw->select('recentchanges','rc_ip',array('rc_user_text' => $address),'VandalForm::doVandal');
      if ($row = $res_ip->fetchRow()) {
        $ip = $row['rc_ip'];
        $res_ip->free();
        # parole first to prevent duplicate rows
        VandalBrake::doParole(0,$ip,'',false);
        wfLoadExtensionMessages( 'VandalBrake' );
        $a = array('vand_address' => $ip,
                   'vand_user' => 0,
                   'vand_by' => $vandaler->getId(),
                   'vand_reason' => wfMsgReplaceArgs(wfMsg('vandallogauto'), array( $address , $reason ) ),
                   'vand_timestamp' => wfTimestampNow(),
                   'vand_account' => $blockCreation,
                   'vand_autoblock' => false,
                   'vand_anon_only' => false,
                   'vand_auto' => true,
                  );
        $dbw->insert('vandals',$a,'VandalBrake::doVandal');
        $dbw->commit();
      }
    }
    # Log:
    if ($dolog)
    {
      $log = new LogPage('vandal');
      $flags = array();
      if ($anononly)
      {
        $flags[] = wfMsg( 'block-log-flags-anononly' );
      }
      if ($blockCreation)
      {
        $flags[] = wfMsg( 'block-log-flags-nocreate' );
      }
      if (!$autoblock && $userId)
      {
        $flags[] = wfMsg( 'block-log-flags-noautoblock' );
      }
      $params = array();
      $params[] = implode(', ',$flags);
      $log->addEntry('vandal',Title::makeTitle( NS_USER, $address),$reason,$params,$vandaler);
    }
  }

  static function doParole($userId,$address,$reason,$dolog = true, $id = null) {
    global $wgUser;
    if ($userId != 0) {
      $cond = array('vand_user' => $userId);
    } elseif ($address) {
      $cond = array('vand_address' => $address);
    } else {
      $cond = array('vand_id' => $id);
    }
    $dbw = wfGetDB(DB_MASTER);
    $dbw->delete('vandals',$cond,'VandalBrake::doParole');
    $dbw->commit();
    if ($dolog)
    {
      $log = new LogPage('vandal');
      $target = Title::makeTitle( NS_USER, $address ? $address : "#$id");
      $log->addEntry('parole',$target,$reason,null,$wgUser);
    }
  }

  static function checkVandal($ip, $userId, &$reason, &$vandaler, &$accountallowed, &$vand_id, &$checkip) {
    //get master to ensure that lag does not allow vandal to escape block
    $dbr = wfGetDB(DB_MASTER);
    # check for username block
    $performautoblock = false;
    $usernamefound = false;
    $checkip = false;
    if ($userId != 0)
    {
      $cond = array(
                    'vand_user' => $userId,
                   );
      $res = $dbr->select('vandals','vand_id, vand_address, vand_user, vand_anon_only, vand_autoblock, vand_account, vand_reason, vand_by',$cond,'VandalBrake::checkVandal');
      if ($res->numRows() != 0)
      {
        $row = $res->fetchRow();
        $accountallowed = $row['vand_account'];
        $accountallowed = !$accountallowed;
        $vand_id = $row['vand_id'];
        # perform autoblock if needed
        $autoblock = $row['vand_autoblock'];
        if ( $autoblock )
        {
          $checkip = true;
          $performautoblock = true;
        }
        $reason = $row['vand_reason'];
        $vandaler = User::newFromId($row['vand_by']);
        $res->free();
        $usernamefound = true;
      } else {
        $res->free();
      }
    }

    # check if the user is ip-blocked
    if ($ip != 0) {
      $cond = array(
                    'vand_address' => $ip,
                   );
      $res = $dbr->select('vandals','vand_id, vand_address, vand_user, vand_anon_only, vand_autoblock, vand_account, vand_reason, vand_by',$cond,'VandalBrake::checkVandal');
      if ($res->numRows() != 0)
      {
        #user is ip blocked, return true if also username blocked
        # if the user is logged in and anon_only is set, don't apply ip block
        $row = $res->fetchRow();
        $anononly = $row['vand_anon_only'];
        $vand_id = $row['vand_id'];
        if ($usernamefound)
        {
          # if there was no autoblock, but the ip block is not anon only, then we have to check the ip
          if (!$checkip) {
            $checkip = !$anononly;
          }
          $res->free();
          return true;
        } else if (!$anononly || $userId == 0)
        {
          if (!$checkip) {
            $checkip = !$anononly;
          }
          $accountallowed = !$row['vand_account'];
          $reason = $row['vand_reason'];
          $vandaler = User::newFromId($row['vand_by']);
          $res->free();
          return true;
        }
      } elseif ($performautoblock) {
        $res->free();
        $user = User::newFromId($userId);
        # parole to prevent duplicate rows
        VandalBrake::doParole(0,$ip,'',false);
        wfLoadExtensionMessages( 'VandalBrake' );
        $reason_new = wfMsgReplaceArgs( wfMsg('vandallogauto'), array( $user->getName() , $reason ) );
        $vandaler = User::newFromId($row['vand_by']);
        VandalBrake::doVandal($ip,0,$reason_new,!$accountallowed, false, false, false,$vandaler,true);
        return true;
      } elseif ($usernamefound) {
        return true;
      }
      $res->free();
    } else {
      # special case for userGetRights hook
      if ($usernamefound) {
        return true;
      }
    }

    $accountallowed = true;
    return false;
  }

  static function getLastEdit($user) {
    if ($user->isAnon())
    {
      $condrev = array('rev_user_text' => $user->getName());
      $condar = array('ar_user_text' => $user->getName());
    } else {
      $condrev = array('rev_user' => $user->getId());
      $condar = array('ar_user' => $user->getId());
    }
    $dbr = wfGetDB(DB_SLAVE);
    $res1 = $dbr->select('revision','rev_timestamp',$condrev,'VandalBrake::getLastEdit',array('ORDER BY' => 'rev_timestamp desc'));
    $res2 = $dbr->select('archive','ar_timestamp',$condar,'VandalBrake::getLastEdit',array('ORDER BY' => 'ar_timestamp desc'));
    $t3 = 0;
    # If we have the user's IP, we can also check the recent changes table to see if there's a logged in edit
    # If we are checking an anon user, this should count. If we are checking a logged in user's IP, this should only count if they are autoblocked
    if ($user->isAnon()) {
      $res3 = $dbr->select('recentchanges','rc_timestamp',array('rc_ip' => $user->getName()),'VandalBrake::getLastEdit',array('ORDER BY' => 'rc_timestamp desc'));
      if ($res3->numRows() != 0)
      {
        $row = $res3->fetchRow();
        $t3 = $row['rc_timestamp'];
      }
    }
    $t1 = 0;
    $t2 = 0;
    if ($res1->numRows() != 0)
    {
      $row = $res1->fetchRow();
      $t1 = $row['rev_timestamp'];
    }
    if ($res2->numRows() != 0)
    {
      $row = $res2->fetchRow();
      $t2 = $row['ar_timestamp'];
    }
    if (max($t1,$t2,$t3) != 0)
    {
      $t = wfTimestamp(TS_UNIX,max($t1,$t2,$t3));
      return $t;
    } else {
      return 0;
    }
  }

  static function userGetRights(&$user, &$aRights) {
    # we cannot be sure that wfGetIP belongs to $user, so we skip the ip checking
    if (VandalBrake::checkVandal(0, $user->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked)) {
      $t = VandalBrake::getLastEdit($user);
      global $wgVandalBrakeConfigLimit, $wgVandalBrakeConfigRemoveRights, $wgVandalBrakeConfigLimitRights ;
      $dt = time() - $t;
      $dt = $wgVandalBrakeConfigLimit - $dt;
      $aRights = array_diff($aRights, $wgVandalBrakeConfigRemoveRights);
      if ($dt > 0)
      {
        # user is binned and brake is active
        $aRights = array_diff($aRights, $wgVandalBrakeConfigLimitRights);
      }
    }
    return true;
  }

  static function userGetGroups(&$user, &$aUserGroups) {
    # we cannot be sure that wfGetIP belongs to $user, so we skip the ip checking
    if (VandalBrake::checkVandal(0, $user->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked)) {
      $t = VandalBrake::getLastEdit($user);
      global $wgVandalBrakeConfigLimit;
      $dt = time() - $t;
      $dt = $wgVandalBrakeConfigLimit - $dt;
      if ($dt > 0)
      {
        # user is binned and brake is active
        $aUserGroups[] = "vandalbrake";
        array_unique($aUserGroups);
      }
    }
    return true;
  }

  static function getBlockedStatus($user) {
    # we cannot be sure that wfGetIP belongs to $user, so we skip the ip checking
    $userip = $user->isAnon() ? $user->getName() : 0;
    $userid = $user->getId();
    if (VandalBrake::checkVandal($userip, $userid, $reason, $vandaler, $accountallowed, $vand_id, $autoblocked)) {
      $t = VandalBrake::getLastEdit($user);
      global $wgVandalBrakeConfigLimit;
      $dt = time() - $t;
      $dt = $wgVandalBrakeConfigLimit - $dt;
      if ($dt > 0)
      {
        # user is binned and brake is active
        $user->mBlockedby = $vandaler->getName();
        wfLoadExtensionMessages( 'VandalBrake' );
        $user->mBlockreason = wfMsgExt('vandalbrakenoticeblock',array( 'escape' ), $reason, round($dt / 60) );
        $user->mBlock->mId = $vand_id;
      }
    }
    return true;
  }

  static function onEditFilterMerged($editor, $text, $section, &$error) {
    global $wgUser;
    $t = false;
    if (VandalBrake::checkVandal(wfGetIP(), $wgUser->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked)) {
      $t = VandalBrake::getLastEdit($wgUser);
      # Check the user's IP too, for logged out edits or edits from another account
      # but only if the user is autoblocked or if the block is on the IP, not the user
      if ( !$wgUser->isAnon() && $autoblocked ) {
        $t2 = VandalBrake::getLastEdit(new User);
        $t = max($t,$t2);
      }
      global $wgVandalBrakeConfigLimit;
      $dt = time() - $t;
      $dt = $wgVandalBrakeConfigLimit - $dt;
      if ($dt > 0)
      {
        wfLoadExtensionMessages( 'VandalBrake' );
        global $wgOut;
        $text = wfMsgExt( 'vandalbrakenotice', array( 'parse' ), round($dt / 60), $vandaler->getName(), $reason, $vand_id );
        $wgOut->addHtml( $text );
        $editor->showEditForm();
        return false;
      }
    }
    $anon = $wgUser->isAnon();
    $limited = !in_array('noratelimit',$wgUser->getRights());
    if ($anon || $limited)
    {
      global $wgVandalBrakeConfigAnonLimit, $wgVandalBrakeConfigUserLimit;
      if (!$t) $t = VandalBrake::getLastEdit($wgUser);
      $dt = time() - $t;
      $dt = ($anon ? $wgVandalBrakeConfigAnonLimit : $wgVandalBrakeConfigUserLimit) - $dt;
      if ($dt > 0)
      {
        global $wgOut;
        wfLoadExtensionMessages( 'VandalBrake' );
        $text = wfMsgExt( 'editlimitnotice', array( 'parse' ), $dt );
        $wgOut->addHtml( $text );
        $editor->showEditForm();
        return false;
      }
    }
    return true;
  }

  static function onEditFilter($editor, $text, &$error) {
    global $wgUser;
    $t = false;
    if (VandalBrake::checkVandal(wfGetIP(), $wgUser->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked)) {
      $t = VandalBrake::getLastEdit($wgUser);
      # Check the user's IP too, for logged out edits or edits from another account
      # but only if the user is autoblocked or if the block is on the IP, not the user
      if ( !$wgUser->isAnon() && $autoblocked ) {
        $t2 = VandalBrake::getLastEdit(new User);
        $t = max($t,$t2);
      }
      global $wgVandalBrakeConfigLimit;
      $dt = time() - $t;
      $dt = $wgVandalBrakeConfigLimit - $dt;
      if ($dt > 0)
      {
        wfLoadExtensionMessages( 'VandalBrake' );
        global $wgOut;
        global $wgMessageCache;
        $messages = $wgMessageCache->getExtensionMessagesFor( 'en' );
        $text = wfMsgExt( 'vandalbrakenotice', array( 'parse' ), round($dt / 60), $vandaler->getName(), $reason, $vand_id );
        $wgOut->addHtml( $text );
        $editor->showEditForm();
        return false;
      }
    }
    $anon = $wgUser->isAnon();
    $limited = !in_array('noratelimit',$wgUser->getRights());
    if ($anon || $limited)
    {
      global $wgVandalBrakeConfigAnonLimit, $wgVandalBrakeConfigUserLimit;
      if (!$t) $t = VandalBrake::getLastEdit($wgUser);
      $dt = time() - $t;
      $dt = ($anon ? $wgVandalBrakeConfigAnonLimit : $wgVandalBrakeConfigUserLimit) - $dt;
      if ($dt > 0)
      {
        global $wgOut;
        wfLoadExtensionMessages( 'VandalBrake' );
        $text = wfMsgExt( 'editlimitnotice', array( 'parse' ), $dt );
        $wgOut->addHtml( $text );
        $editor->showEditForm();
        return false;
      }
    }
    return true;
  }

  static function onAPIEditBeforeSave(&$EditPage, $text, &$resultArr) {
    global $wgUser;
    $t = false;
    if (VandalBrake::checkVandal(wfGetIP(), $wgUser->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked)) {
      $t = VandalBrake::getLastEdit($wgUser);
      # Check the user's IP too, for logged out edits or edits from another account
      # but only if the user is autoblocked or if the block is on the IP, not the user
      if ( !$wgUser->isAnon() && $autoblocked ) {
        $t2 = VandalBrake::getLastEdit(new User);
        $t = max($t,$t2);
      }
      global $wgVandalBrakeConfigLimit;
      $dt = time() - $t;
      $dt = $wgVandalBrakeConfigLimit - $dt;
      if ($dt > 0)
      {
        wfLoadExtensionMessages( 'VandalBrake' );
        $resultArr = array('error' => wfMsgExt( 'vandalbrakenoticeapi', array( 'replaceafter' ), round($dt / 60), $vandaler->getName(), $reason, $vand_id ));
        return false;
      }
    }
    $anon = $wgUser->isAnon();
    $limited = !in_array('noratelimit',$wgUser->getRights());
    if ($anon || $limited)
    {
      global $wgVandalBrakeConfigAnonLimit, $wgVandalBrakeConfigUserLimit;
      if (!$t) $t = VandalBrake::getLastEdit($wgUser);
      $dt = time() - $t;
      $dt = ($anon ? $wgVandalBrakeConfigAnonLimit : $wgVandalBrakeConfigUserLimit) - $dt;
      if ($dt > 0)
      {
        global $wgOut;
        wfLoadExtensionMessages( 'VandalBrake' );
        $resultArr = array('error' => wfMsgExt( 'editlimitnotice', array( 'parse' ), $dt ));
        return false;
      }
    }
    return true;
  }

  static function onAccountCreation($user, $message) {
    global $wgUser;
    if (VandalBrake::checkVandal(wfGetIP(), $wgUser->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked)) {
      if (!$accountallowed)
      {
        wfLoadExtensionMessages( 'VandalBrake' );
        $message = wfMsgExt( 'vandalbrakenoticeaccountcreation', array( 'parse' ), $vandaler->getName(), $reason, $vand_id );
        return false;
      } else {
        return true;
      }
    } else {
      return true;
    }
  }

  //FIXME: Doesn't work, no appropriate hook
/*  static function onRC(&$changeslist, &$s, &$rc)
  {
    global $wgUser;
    if( $wgUser->isAllowed( 'block' ) ) {
      if( !$changeslist->isDeleted($rc,Revision::DELETED_USER) ) {
        $link = $wgUser->getSkin()->makeKnownLinkObj( SpecialPage::getTitleFor( 'VandalBrake' ),
                                                      wfMsgHtml( 'vandalbin-contribs' ),
                                                      'wpVandAddress=' . urlencode( $rc->getAttribute(rc_user_text) ) );
        $s .= $link;
        //$s .= implode(',',$rc->mAttribs) . ' keys: ' . implode(',',array_keys($rc->mAttribs));
      }
    }
    return true;
  }
*/

  static function onContribs($id, $title, &$tools)
  {
    global $wgUser;
    wfLoadExtensionMessages( 'VandalBrake' );
    if( $wgUser->isAllowed( 'block' ) ) {
      //insert at end
      $tools[] = $wgUser->getSkin()->makeKnownLinkObj( SpecialPage::getTitleFor( 'VandalBrake' ),
                                                       wfMsgHtml( 'vandalbin-contribs' ),
                                                       'wpVandAddress=' . urlencode( $title->getText() ) );
      //insert into arbitrary position
      //$link = $wgUser->getSkin()->makeKnownLinkObj( SpecialPage::getTitleFor( 'VandalBrake' ),
      //                                              wfMsgHtml( 'vandalbin-contribs' ),
      //                                              'wpVandAddress=' . urlencode( $title->getText() ) );
      //array_splice($tools,2,0,array($link));
    }
    //insert vandal log
    $tools[] = $wgUser->getSkin()->makeKnownLinkObj( SpecialPage::getTitleFor( 'Log' ),
                                                     wfMsgHtml( 'vandallog-contribs' ),
                                                     'type=vandal&page=' . urlencode( $title->getPrefixedUrl() ) );
    return true;

  }

}

class VandalForm {
  var $VandAddress, $Reason, $VandAccount, $VandAutoblock, $VandAnonOnly;
  function VandalForm( $par )
  {
    global $wgRequest;
    $this->VandAddress = $wgRequest->getVal('wpVandAddress', $par );
    $this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
    $this->Reason = $wgRequest->getText( 'wpVandReason' );
    $this->VandReasonList = $wgRequest->getText( 'wpVandReasonList' );
    # checkboxes
    $byDefault = !$wgRequest->wasPosted();
    $this->VandAccount = $wgRequest->getBool('preventaccount',false);
    $this->VandAutoblock = $wgRequest->getBool('autoblock',false);
    $this->VandAnonOnly = $wgRequest->getBool('anononly',false);
  }

  function showForm( $err )
  {
    global $wgOut, $wgUser;
    $wgOut->setPagetitle( wfMsg('vandalbrake') );
    $wgOut->addWikiMsg( 'vandalbraketext' );
    $mIpaddress = Xml::label( wfMsg( 'ipadressorusername' ), 'mw-bi-target');
    $mReason = Xml::label( wfMsg( 'ipbreason' ), 'wpVandReasonList' );
    $mReasonother = Xml::label( wfMsg('ipbotherreason'), 'vand-reason' );
    $user = User::newFromName( $this->VandAddress );

    if ( $err ) {
      $key = array_shift($err);
      $msg = wfMsgReal($key,$err);
      $wgOut->setSubtitle( wfMsgHtml('formerror') );
      $wgOut->addHTML( Xml::tags('p', array('class' => 'error'), $msg ) );
    }

    $reasonDropDown = Xml::listDropDown( 'wpVandReasonList',
      wfMsgForContent( 'ipbreason-dropdown' ),
      wfMsgForContent( 'ipbreasonotherlist' ), $this->VandReasonList, 'wpVandDropDown', 4 );
    $titleObject = SpecialPage::getTitleFor( 'VandalBrake' );
    global $wgStylePath, $wgStyleVersion;
    $wgOut->addHTML(
      Xml::tags( 'script', array( 'type' => 'text/javascript', 'src' => "$wgStylePath/common/block.js?$wgStyleVersion" ), '' ) .
      Xml::openElement('form', array( 'method' => 'post', 'action' => $titleObject->getLocalURL("action=submit"), 'id' => 'vand' ) ) .
      Xml::openElement( 'fieldset' ) .
      Xml::element( 'legend', null, wfMsg( 'vandalbrake' ) ) .
      Xml::openElement( 'table', array( 'border' => '0', 'id' => 'mw-vandal-table') ) .
      "<tr>
        <td class='mw-label'>
          {$mIpaddress}
        </td>
        <td class='mw-input'>"
    );
    $wgOut->addHTML(
      Xml::input( 'wpVandAddress', 45, $this->VandAddress,
                   array( 'tabindex' => '1',
                          'id' => 'mw-bi-target',
                          'onchange' => 'updateBlockOptions()' ) )
    );
    $wgOut->addHTML(
        "</td>
      </tr>
      <tr>"
    );
    $wgOut->addHTML("
      </tr>
        <td class='mw-label'>
          {$mReason}
        </td>
        <td class='mw-input'>
          {$reasonDropDown}
        </td>
      </tr>
      <tr id='wpVandReason'>
        <td class='mw-label'>
          {$mReasonother}
        </td>
        <td class='mw-imput'>" .
          Xml::input( 'wpVandReason', 45, $this->Reason,
                       array('tabindex' => 2,
                             'id' => 'mw-vandal-reason',
                             'maxlength' => '200' ) ) . "
        </td>
      </tr>" .
      "<tr id='wpAnonOnlyRow'>
        <td>&nbsp;</td>
        <td class='mw-input'>".
          Xml::checkLabel(wfMsg('ipbanononly'), 'anononly', 'anononly', $this->VandAnonOnly, array( 'tabindex' => '3' ) ) ."
        </td>
      </tr>" .
      "<tr id='wpCreateAccountRow'>
        <td>&nbsp;</td>
        <td class='mw-input'>".
          Xml::checkLabel(wfMsg('ipbcreateaccount'), 'preventaccount', 'preventaccount', $this->VandAccount, array( 'tabindex' => '4' ) ) ."
        </td>
      </tr>" .
      "<tr id='wpEnableAutoblockRow'>
        <td>&nbsp;</td>
        <td class='mw-input'>".
          Xml::checkLabel(wfMsg('ipbenableautoblock'), 'autoblock', 'autoblock', $this->VandAutoblock, array( 'tabindex' => '5' ) ) ."
        </td>
      </tr>"
    );
    $wgOut->addHTML("
      <tr>
        <td style='padding-top: 1em;'>" .
          Xml::submitButton( wfMsg( 'vandal' ),
                             array('name' => 'wpVandal',
                                   'tabindex' => '6',
                                   'accesskey' => 's') ) . "
        </td>
      </tr>" .
      Xml::closeElement('table') .
      Xml::hidden('wpEditToken', $wgUser->editToken() ) .
      Xml::closeElement( 'fieldset' ) .
      Xml::closeElement( 'form' ) .
      Xml::tags( 'script', array( 'type' => 'text/javascript' ), 'updateBlockOptions()' ) . "\n"
    );

    $wgOut->addHTML( $this->getConvenienceLinks() );

    if( is_object( $user ) ) {
      $this->showLogFragment( $wgOut, $user->getUserPage() );
    } elseif( preg_match( '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $this->VandAddress ) ) {
      $this->showLogFragment( $wgOut, Title::makeTitle( NS_USER, $this->VandAddress ) );
    } elseif( preg_match( '/^\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}/', $this->VandAddress ) ) {
      $this->showLogFragment( $wgOut, Title::makeTitle( NS_USER, $this->VandAddress ) );
    }
  }

  function showLogFragment( $out, $title ) {
    global $wgUser;
    $out->addHTML( Xml::element( 'h2', NULL, LogPage::logName( 'vandal' ) ) );
    $count = LogEventsList::showLogExtract( $out, 'vandal', $title->getPrefixedText(), '', 10 );
    if($count > 10)
    {
      $out->addHTML( $wgUser->getSkin()->link( SpecialPage::getTitleFor( 'Log' ),
                                               wfMsgHtml( 'vandallog-fulllog' ), array(),
                                               array('type' => 'vandal',
                                                     'page' => $title->getPrefixedText() ) ) );
    }
  }


  private function getConvenienceLinks() {
    global $wgUser;
    $skin = $wgUser->getSkin();
    if( $this->VandAddress ) {
      $links[] = $this->getContribsLink( $skin );
    }
    $links[] = $this->getUnblockLink( $skin );
    $links[] = $this->getVandListLink( $skin );
    $links[] = $skin->makeLink ( 'MediaWiki:Ipbreason-dropdown', wfMsgHtml( 'ipb-edit-dropdown' ) );
    return '<p class="mw-ipb-conveniencelinks">' . implode( ' | ', $links ) . '</p>';
  }

  private function getContribsLink( $skin ) {
    $contribsPage = SpecialPage::getTitleFor( 'Contributions', $this->VandAddress );
    return $skin->link( $contribsPage, wfMsgExt( 'ipb-blocklist-contribs', 'escape', $this->VandAddress ) );
  }

  private function getUnblockLink( $skin ) {
    $list = SpecialPage::getTitleFor( 'VandalBin' );
    if( $this->VandAddress ) {
      $addr = htmlspecialchars( strtr( $this->VandAddress, '_', ' ' ) );
      return $skin->makeKnownLinkObj( $list, wfMsgHtml( 'parole-addr', $addr ),
                                      'action=parole&wpVandAddress=' . urlencode( $this->VandAddress ) );
    } else {
      return $skin->makeKnownLinkObj( $list, wfMsgHtml( 'parole-any' ), 'action=parole' );
    }
  }

  private function getVandListLink( $skin ) {
    $list = SpecialPage::getTitleFor( 'VandalBin' );
    if( $this->VandAddress ) {
      $addr = htmlspecialchars( strtr( $this->VandAddress, '_', ' ' ) );
      return $skin->makeKnownLinkObj( $list, wfMsgHtml( 'vandalbin-addr', $addr ),
                                      'wpVandAddress=' . urlencode( $this->VandAddress ) );
    } else {
      return $skin->makeKnownLinkObj( $list, wfMsgHtml( 'vandalbin-any' ) );
    }
  }

  function doVandal()
  {
    global $wgUser;
    $userId = 0;
    $this->VandAddress = IP::sanitizeIP($this->VandAddress);

    $rxIP4 = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';
    $rxIP6 = '\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}';
    $rxIP = "($rxIP4|$rxIP6)";
    if ( !preg_match( "/^$rxIP$/", $this->VandAddress ) ) {
      # username
      $user = User::newFromName( $this->VandAddress );
      if ( !is_null( $user ) && $user->getId() )
      {
        $userId = $user->getId();
        $this->VandAddress = $user->getName();
      } else {
        return array('nosuchusershort', htmlspecialchars($user ? $user->getName() : $this->VandAddress ) );
      }
    }

    $reasonstr = $this->VandReasonList;
    if ( $reasonstr != 'other' && $this->Reason != '')
    {
      $reasonstr .= ': ' . $this->Reason;
    } elseif ( $reasonstr == 'other' ) {
      $reasonstr = $this->Reason;
    }

    $dbr = wfGetDB(DB_SLAVE);
    if ($userId != 0) {
      $cond = array('vand_user' => $userId);
    } elseif ($this->VandAddress) {
      $cond = array('vand_address' => $this->VandAddress);
    }
    $res = $dbr->select('vandals','vand_id, vand_address, vand_user',$cond,'VandalForm::doVandal');
    $found = ($res->numRows() != 0);
    $res->free();
    if ($found) {
      return array('vandalalready');
    }

    VandalBrake::doVandal($this->VandAddress,$userId,$reasonstr,$this->VandAccount,$this->VandAutoblock,$this->VandAnonOnly);

    return array();

  }

  function doSubmit()
  {
    global $wgOut;
    $retval = $this->doVandal();
    if (empty($retval)) {
      $titleObj = SpecialPage::getTitleFor('VandalBrake');
      $wgOut->redirect($titleObj->getFullURL('action=success&' . 'wpVandAddress=' . urlencode($this->VandAddress) ));
      return;
    }
    $this->showForm($retval);
  }

  function showSuccess() {
    global $wgOut;
    $wgOut->setPagetitle( wfMsg( 'vandalbrake' ) );
    $wgOut->setSubtitle( wfMsg( 'vandalsuccessub' ) );
    $text = wfMsgExt( 'vandalsuccesstext', array( 'parse' ), $this->VandAddress );
    $wgOut->addHTML( $text );
  }
}

class ParoleForm {
  var $VandAddress, $Reason, $VandId;
  function ParoleForm( $par )
  {
    global $wgRequest;
    $this->VandAddress = $wgRequest->getVal('wpVandAddress', $par );
    $this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
    $this->VandId = $wgRequest->getVal('id');
    $this->Reason = $wgRequest->getText( 'wpVandReason' );
  }

  function showForm( $err )
  {
    global $wgOut, $wgUser;
    $wgOut->setPagetitle( wfMsg('paroletitle') );
    $wgOut->addWikiMsg( 'paroletext' );
    $mIpaddress = Xml::label( wfMsg( 'ipadressorusername' ), 'mw-bi-target');
    $mReason = Xml::label( wfMsg( 'ipbreason' ), 'vand-reason' );

    if ( $err ) {
      $key = array_shift($err);
      $msg = wfMsgReal($key,$err);
      $wgOut->setSubtitle( wfMsgHtml('formerror') );
      $wgOut->addHTML( Xml::tags('p', array('class' => 'error'), $msg ) );
    }

    $titleObject = SpecialPage::getTitleFor( 'VandalBin' );
    global $wgStylePath, $wgStyleVersion;
    $wgOut->addHTML(
      Xml::openElement('form', array( 'method' => 'post', 'action' => $titleObject->getLocalURL("action=submit"), 'id' => 'parole' ) ) .
      Xml::openElement( 'fieldset' ) .
      Xml::element( 'legend', null, wfMsg( 'parolelegend' ) ) .
      Xml::openElement( 'table', array( 'border' => '0', 'id' => 'mw-parole-table') ) .
      "<tr>
        <td class='mw-label'>
          {$mIpaddress}
        </td>
        <td class='mw-input'>"
    );

    if ($this->VandAddress)
    {
      $wgOut->addHTML(
        Xml::input( 'wpVandAddress', 45, $this->VandAddress,
                     array( 'tabindex' => '1',
                            'id' => 'mw-bi-target', ) )
      );
    } else {
      $wgOut->addHTML(
         "#$this->VandId" . Xml::hidden( 'id', $this->VandId )
      );
    }
    $wgOut->addHTML(
        "</td>
      </tr>
      <tr>"
    );
    $wgOut->addHTML("
      <tr id='wpVandReason'>
        <td class='mw-label'>
          {$mReason}
        </td>
        <td class='mw-imput'>" .
          Xml::input( 'wpVandReason', 45, $this->Reason,
                       array('tabindex' => 2,
                             'id' => 'mw-vandal-reason',
                             'maxlength' => '200' ) ) . "
        </td>
      </tr>"
    );
    $wgOut->addHTML("
      <tr>
        <td style='padding-top: 1em;'>" .
          Xml::submitButton( wfMsg( 'parole' ),
                             array('name' => 'wpParole',
                                   'tabindex' => '6',
                                   'accesskey' => 's') ) . "
        </td>
      </tr>" .
      Xml::closeElement('table') .
      Xml::hidden('wpEditToken', $wgUser->editToken() ) .
      Xml::closeElement( 'fieldset' ) .
      Xml::closeElement( 'form' )
    );
  }
  function doParole()
  {
    if ($this->VandAddress)
    {
      $userId = 0;
      $this->VandAddress = IP::sanitizeIP($this->VandAddress);

      $rxIP4 = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';
      $rxIP6 = '\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}';
      $rxIP = "($rxIP4|$rxIP6)";
      if ( !preg_match( "/^$rxIP$/", $this->VandAddress ) ) {
        # username
        $user = User::newFromName( $this->VandAddress );
        if ( !is_null( $user ) && $user->getId() )
        {
          $userId = $user->getId();
          $this->VandAddress = $user->getName();
        } else {
          return array('nosuchusershort', htmlspecialchars($user ? $user->getName() : $this->VandAddress ) );
        }
      }
    }

    $reasonstr = $this->Reason;

    $dbr = wfGetDB(DB_SLAVE);
    if ($userId != 0) {
      $cond = array('vand_user' => $userId);
    } elseif ($this->VandAddress) {
      $cond = array('vand_address' => $this->VandAddress);
    } else {
      $cond = array('vand_id' => $this->VandId);
    }
    $res = $dbr->select('vandals','vand_id, vand_address, vand_user',$cond,'VandalForm::doVandal');
    $found = ($res->numRows() != 0);
    $res->free();
    if (!$found) {
      return array('vandalnot');
    }

    VandalBrake::doParole($userId,$this->VandAddress,$reasonstr,true,$this->VandId);

    return array();
  }

  function doSubmit()
  {
    global $wgOut;
    $retval = $this->doParole();
    if (empty($retval)) {
      $titleObj = SpecialPage::getTitleFor('VandalBin');
      $wgOut->redirect($titleObj->getFullURL('action=success&' . 'wpVandAddress=' . urlencode($this->VandAddress) ));
      return;
    }
    $this->showForm($retval);
  }

  function showSuccess() {
    global $wgOut;
    $wgOut->setPagetitle( wfMsg( 'VandalBin' ) );
    $wgOut->setSubtitle( wfMsg( 'parolesuccessub' ) );
    $text = $this->VanAddress ? (wfMsgExt( 'parolesuccesstext', array( 'parse' ), $this->VandAddress ))
                   : wfMsg('parolesuccesstextanon');
    $wgOut->addHTML( $text );
  }
}


# Special:VandalBrake page
class SpecialVandal extends SpecialPage {
  function __construct() {
    parent::__construct('VandalBrake','block');
    #SpecialPage::setGroup('VandalBrake','users');
    global $wgSpecialPageGroups;
    $wgSpecialPageGroups['VandalBrake']='users';
    wfLoadExtensionMessages('VandalBrake');
  }
  function execute( $par ) {
    global $wgRequest, $wgOut, $wgUser;
    if( wfReadOnly() ) {
		  $wgOut->readOnlyPage();
		  return;
		}

		if( !$wgUser->isAllowed( 'vandalbin' ) ) {
      $wgOut->permissionRequired( 'vandalbin' );
      return;
    }

		$form = new VandalForm( $par );

		$action = $wgRequest->getVal( 'action' );
		if ( 'success' == $action )
		{
		  $form->showSuccess();
		} else if ( $wgRequest->wasPosted() && 'submit' == $action &&
		            $wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) ) ) {
		  $form->doSubmit();
		} else {
		  $form->showForm( '' );
		}
  }
}

# Special:VandalBin page
class SpecialVandalbin extends SpecialPage {
  var $VandAddress;
  function __construct() {
    parent::__construct('VandalBin');
    #SpecialPage::setGroup('VandalBrake','users');
    global $wgSpecialPageGroups;
    $wgSpecialPageGroups['VandalBin']='users';
    wfLoadExtensionMessages('VandalBrake');
  }
  function searchForm() {
    global $wgScript, $wgTitle, $wgRequest;
    return
      Xml::tags( 'form', array( 'action' => $wgScript ),
        Xml::hidden( 'title', $wgTitle->getPrefixedDbKey() ) .
        Xml::openElement( 'fieldset' ) .
        Xml::element( 'legend', null, wfMsg( 'vandalbin-legend' ) ) .
        Xml::inputLabel( wfMsg( 'ipblocklist-username' ), 'wpVandAddress', 'wpVandAddress', /* size */ false, $this->VandAddress ) .
        '&nbsp;' .
        Xml::submitButton( wfMsg( 'ipblocklist-submit' ) ) . '<br />' .
        Xml::closeElement( 'fieldset' )
      );
  }

  function execute( $par ) {
    global $wgRequest, $wgOut, $wgUser;
    if( wfReadOnly() ) {
		  $wgOut->readOnlyPage();
		  return;
		}

    global $wgRequest;
    $this->VandAddress = $wgRequest->getVal('wpVandAddress', $par );
    $this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
    $action = $wgRequest->getText( 'action' );

    $pform = new ParoleForm( $par );
    if ($action == 'parole')
    {
      # Check permissions
      if( !$wgUser->isAllowed( 'vandalbin' ) ) {
        $wgOut->permissionRequired( 'vandalbin' );
        return;
      }
      # Check for database lock
      if( wfReadOnly() ) {
        $wgOut->readOnlyPage();
        return;
      }
      $pform->showForm('');
    } elseif ($action == 'submit' && $wgRequest->wasPosted() &&
              $wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) ) )
    {
      # Check permissions
      if( !$wgUser->isAllowed( 'vandalbin' ) ) {
        $wgOut->permissionRequired( 'vandalbin' );
        return;
      }
      # Check for database lock
      if( wfReadOnly() ) {
        $wgOut->readOnlyPage();
        return;
      }
      $pform->doSubmit();
    } elseif ($action == 'success') {
      $pform->showSuccess();
      $this->VandAddress = null;
    }

    #$wgOut->addWikiMsg( 'vandalbintext' );
		$wgOut->addHTML($this->searchForm());
		$this->setHeaders();
		$dbr = wfGetDB(DB_SLAVE);
		$conds = array();
		if ($this->VandAddress)
		{
		  $conds['vand_address'] = $this->VandAddress;
		}
		$pager = new VandalbinPager($conds);
		if ( $pager->getNumRows() ) {
		  $wgOut->addHTML(
		    $pager->getNavigationBar() .
		    Xml::tags( 'ul', null, $pager->getBody() ) .
		    $pager->getNavigationBar()
		  );
		} else {
		  if ($this->VandAddress)
		  {
        $wgOut->addWikiMsg( 'vandalbin-notfound' );
		  } else {
        $wgOut->addWikiMsg( 'vandalbin-empty' );
		  }
		}
  }

}

class VandalbinPager extends ReverseChronologicalPager {
  public $mConds;

  function __construct( $conds = array() ) {
    $this->mConds = $conds;
    parent::__construct();
  }

  function getStartBody() {
    wfProfileIn( __METHOD__ );
    # Do a link batch query
    $this->mResult->seek( 0 );
    $lb = new LinkBatch;

    while ( $row = $this->mResult->fetchObject() ) {
      $user = User::newFromId( $row->vand_by );
      $name = str_replace( ' ', '_', $user->getName() );
      $lb->add( NS_USER, $name );
      $lb->add( NS_USER_TALK, $name );
      $name = str_replace( ' ', '_', $row->vand_address );
      $lb->add( NS_USER, $name );
    $lb->add( NS_USER_TALK, $name );
    }
    $lb->execute();
    wfProfileOut( __METHOD__ );
    return '';
	}

  function formatRow( $row ) {
    global $wgUser, $wgLang;
    static $sk=null;
    if( is_null( $sk ) )
    {
      $sk = $wgUser->getSkin();
    }
    $vand_by_id = $row->vand_by;
    $vand_by_user = User::newFromId($vand_by_id);
    $vand_by_name = $vand_by_user->getName();
    $vandaler = $sk->userLink($vand_by_id,$vand_by_name) . $sk->userToolLinks($vand_by_id,$vand_by_name);
    $reason = ($row->vand_reason) ? "$row->vand_reason" : '' ;
    $action = array('action' => 'parole');
    if ($row->vand_auto)
    {
      $action['id'] = $row->vand_id;
      $target = "#$row->vand_id";
    } else {
      $action['wpVandAddress'] = $row->vand_address;
      $target = $sk->userLink($row->vand_user, $row->vand_address ) . $sk->userToolLinks($row->vand_user, $row->vand_address, false, Linker::TOOL_LINKS_NOBLOCK);
    }
    $formattedTime = $wgLang->timeanddate( $row->vand_timestamp, true );
    $line = wfMsgReplaceArgs( wfMsg('vandalbinmsg'), array( $formattedTime, $vandaler, $target ) );

    $parolelink = $sk->link( SpecialPage::getTitleFor( 'vandalbin' ), wfMsg('parolelink'),array(),$action , 'known');



    $flags = array();
    if ($row->vand_anon_only)
    {
      $flags[] = wfMsg( 'block-log-flags-anononly' );
    }
    if ($row->vand_account)
    {
      $flags[] = wfMsg( 'block-log-flags-nocreate' );
    }
    if (!$row->vand_autoblock && $row->vand_user)
    {
      $flags[] = wfMsg( 'block-log-flags-noautoblock' );
    }
    $flagsstr = implode(', ',$flags);
    $comment = $sk->commentBlock($reason);
    return "<li>$line ($flagsstr) $comment ($parolelink)</li>\n";
  }

  function getQueryInfo() {
    $conds = $this->mConds;
    return array(
      'tables' => 'vandals',
      'fields' => '*',
      'conds' => $conds,
    );
  }

  function getIndexField() {
    return 'vand_timestamp';
  }
}
