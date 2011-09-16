<?php
	define('ROOT',	dirname(dirname(__FILE__)));
	define('WWW',	ROOT.'/www');
	define('SRC',	ROOT.'/src');
	define('APPS',	SRC.'/apps');
	define('LYT',	SRC.'/layouts');
	define('MDL',	SRC.'/models');
	define('HLP', 	SRC.'/helpers');
	define('TMP',	ROOT.'/tmp');
	define('EXT',	ROOT.'/ext');


	$folders = array(ROOT.'/lib/core', MDL);
	
	foreach($folders as $folder)
	{
		$dir = opendir($folder);

		while(false !== ($file = readdir($dir)))
		{ if ($file[0] != ".") { require_once($folder.'/'.$file); } }

		closedir($dir);
	}


?>