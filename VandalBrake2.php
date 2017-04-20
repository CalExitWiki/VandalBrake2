<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

## time limit, default 30 minutes
$wgVandalBrakeConfigLimit=1800;
## time limits for non vandaled users
$wgVandalBrakeConfigAnonLimit = 30;
$wgVandalBrakeConfigUserLimit = 15;

# give sysops the right to bin users
$wgGroupPermissions['sysop']['vandalbin'] = true;

# which rights to remove from a vandal:
$wgVandalBrakeConfigRemoveRights = array ( 'move', 'skipcaptcha', 'rollback' );
# which rights to limit by the edit limit (i.e. they will be available only if more than $wgVandalBrakeConfigLimit seconds have passed since the user's last action):
$wgVandalBrakeConfigLimitRights = array ( );

$wgExtensionCredits['other'][] = array(
	'name' => 'VandalBrake2',
  'author' => '[http://rationalwiki.com/wiki/User:Nx Nx]',
	'url' => 'http://rationalwiki.com',
	'description' => 'Limits the editing rate of vandals'
);

## Normal edit hooks
if ( defined( 'MW_SUPPORTS_EDITFILTERMERGED' ) ) {
	$wgHooks['EditFilterMerged'][] = 'VandalBrake::onEditFilterMerged';
} else {
	$wgHooks['EditFilter'][] = 'VandalBrake::onEditFilter';
}


## API hook
$wgHooks['APIEditBeforeSave'][] = 'VandalBrake::onAPIEditBeforeSave';

## Account creation hook
$wgHooks['AbortNewAccount'][] = 'VandalBrake::onAccountCreation';

## add vandal link to Special:Contributions and recent changes
//$wgHooks['OldChangesListRecentChangesLine'][] = 'VandalBrake::onRC'; //FIXME: Doesn't work
$wgHooks['ContributionsToolLinks'][] = 'VandalBrake::onContribs';

## Remove certain user rights from vandals
$wgHooks['UserGetRights'][] = 'VandalBrake::userGetRights';

## Dinamically asign the vandalbrake user group
#$wgHooks['UserEffectiveGroups'][] = 'VandalBrake::userGetGroups';

## Dinamically alter the block status
#$wgHooks['GetBlockedStatus'][] = 'VandalBrake::getBlockedStatus';

## Register special pages
$wgSpecialPages['VandalBrake'] = 'SpecialVandal';
$wgSpecialPages['VandalBin'] = 'SpecialVandalbin';

## Create new log type
$wgLogTypes[] = 'vandal';
$wgLogNames['vandal'] = 'vandallogname';
$wgLogHeaders['vandal'] = 'vandallogheader';
$wgLogActionsHandlers['vandal/parole'] = 'VandalBrake::vandallogparolehandler';
$wgLogActionsHandlers['vandal/vandal'] = 'VandalBrake::vandallogvandalhandler';

## Modify vandal log lines to show parole link
$wgHooks['LogLine'][] = 'VandalBrake::ModifyLog';

## init function, uncomment to check for the existence of the required table
# $wgExtensionFunctions[] = "setupVandalBrake";

## include path
$wgVandalBrakeIP = dirname( __FILE__ );
$wgExtensionMessagesFiles['VandalBrake'] = "$wgVandalBrakeIP/VandalBrake2.i18n.php";

## Load classes
$wgAutoloadClasses['VandalBrake'] =  "$wgVandalBrakeIP/VandalBrake2.body.php";
$wgAutoloadClasses['SpecialVandal'] =  "$wgVandalBrakeIP/VandalBrake2.body.php";
$wgAutoloadClasses['SpecialVandalbin'] =  "$wgVandalBrakeIP/VandalBrake2.body.php";

//Avoid unstubbing $wgParser on setHook() too early on modern (1.12+) MW versions, as per r35980
if ( defined( 'MW_SUPPORTS_PARSERFIRSTCALLINIT' ) ) {
	$wgHooks['ParserFirstCallInit'][] = 'vandalbrakeinit';
} else { // Otherwise do things the old fashioned way
	$wgExtensionFunctions[] = 'vandalbrakeinit';
}

function vandalbrakeinit()
{
  wfLoadExtensionMessages( 'VandalBrake' );
  return true;
}

function setupVandalBrake()
{
  ## Check if the table exists
  $dbr = wfGetDB(DB_SLAVE);
  if (!$dbr->tableExists('vandals'))
  {
    throw new Exception("Missing vandals table from database");
    ##  create table /*$wgDBprefix*/vandals (vand_id int NOT NULL auto_increment, vand_address tinyblob NOT NULL, vand_user int unsigned NOT NULL default '0', vand_by int unsigned NOT NULL default '0', vand_reason tinyblob NOT NULL, vand_timestamp binary(14) NOT NULL default '', vand_account bool NOT NULL default 0, vand_autoblock bool NOT NULL default 0, vand_anon_only bool NOT NULL default 0, vand_auto bool NOT NULL default 0, PRIMARY KEY vand_id (vand_id), INDEX vand_user (vand_user)) /*$wgDBTableOptions*/;
    die(-1);
  }
}
