<?php

$extDir = __DIR__;
$wgAutoloadClasses['FXUsingData'] = $extDir . 'UsingData.hooks.php';
$wgExtensionMessagesFiles['UsingData'] = $extDir . 'UsingData.i18n.php';
$wgExtensionCredits['parserhook'][] = array(
	'name' => 'UsingData',
	'author' => 'foxlit',
	'descriptionmsg' => 'usingdata-description',
	'version' => '1.2.8',
);
$wgHooks['LanguageGetMagic'][] = 'efUsingDataMagic';
$wgHooks['MagicWordwgVariableIDs'][] = 'efUsingDataMagicVars';
$wgHooks['ParserFirstCallInit'][] = 'FXUsingData::onParserFirstCallInit';
$wgHooks['ParserGetVariableValueSwitch'][] = 'FXUsingData::ancestorNameVar';


function efUsingDataMagic(&$magicWords, $langCode) {
	switch ($langCode) {
		
	default:
		$magicWords['data'] = array(0, 'data');
		$magicWords['using'] = array(0, 'using');
		$magicWords['usingarg'] = array(0, 'usingarg');
		$magicWords['ancestorname'] = array(0, 'ancestorname');
		$magicWords['selfname'] = array(0, 'selfname');
		$magicWords['parentname'] = array(0, 'parentname', 'ancestorname');
	}
	return true;
}
function efUsingDataMagicVars(&$magicWords) {
	$magicWords[] = 'parentname';
	$magicWords[] = 'selfname';
	return true;
}
$wgMessagesDirs['UsingData']					= "{$extDir}/i18n";
?>
