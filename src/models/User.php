<?php

	class User extends Model
	{
		public static $table = 'users';
		public static $fields = array(	'id'		=> 'int',
										'login'		=> 'string',
										'password'	=> 'string',
										'nicename'	=> 'string',
										'email'		=> 'string'
									);

		public function __construct() {}
	}
?>