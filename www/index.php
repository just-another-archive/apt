<?php

	require('../lib/App.php');
	
	try{ App::dispatch('dev'); }
	catch(Exception $e) { die($e->getMessage()); }

?>