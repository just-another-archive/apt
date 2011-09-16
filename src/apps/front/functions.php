<?php

	function pre_dispatch($path)
	{
		App::render(TPL.'/header.php');
	}

	function post_dispatch($data)
	{
		App::render(TPL.'/footer.php');
	}

?>