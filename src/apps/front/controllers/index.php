<?php
	function index($args = null)
	{
		App::render(TPL.'/index.php');

		$replacements = array(	'{TITLE}'		=> 'Accueil',
								'{BODYCLASS}'	=> 'accueil' );

		App::display($replacements);
	}

 ?>