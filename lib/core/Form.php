<?php

	class Form
	{
		private $data;
		private $fields = array();
		
		public $errors = array();
		
		public function __construct($src = null)
		{
			$this->data = $src != null ? $src : $_POST;
		}
	
		public function add($name, $validator = 'not_empty', $mandatory = true, $possible_values = null)
		{
			if ($validator == 'checkbox')
			{
				self::checkbox($name);
				$validator = Validator::$BOOLEAN;
			}
			else if (is_string($validator)) { $validator = Validator::get($validator); }
			
			if ($possible_values != null) { if (!is_array($possible_values)) { $posisble_values = array($possible_values); } }
			
			array_push($this->fields, array($name, $validator, $mandatory, $possible_values));
			
			return $this;
		}
	
		public function remove($name)
		{			
			if (is_array($name))
			{
				foreach($name as $item) { $this->remove($item); }
				return;
			}
		
			for ($i = 0; $i < count($this->fields); $i++)
			{ if ($name == $this->fields[$i][0]) { array_slice($this->fields, $i, 1); break; } }
			
			unset($this->data[$name]);
		}
	
		public function validate()
		{
			$this->errors = array();
			$validation = true;
		
			foreach($this->fields as $field)
			{
				list($name, $validator, $mandatory, $possible_values) = $field;
				
				if (!isset($this->data[$name]) || ($mandatory && empty($this->data[$name])))
				{
					array_push($this->errors, $name);
					$validation = false;
					continue;
				}
				else { $this->data[$name] = trim($this->data[$name]); }
					
				$not_empty = strlen($this->data[$name]) > 0;	
								
				if ($possible_values != null)
				{
					if (in_array($this->data[$name], $possible_values)) { continue; }
					else if ($mandatory || $not_empty)
					{
						array_push($this->errors, $name);
						$validation = false;
						continue;
					}
				}
				else
				{	
					$format = filter_var($this->data[$name], FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => $validator->regex))) !== false;
					
					if ($format)
					{
						foreach($validator->filters as $filter)
						{
							$filtering = filter_var($this->data[$name], $filter);

							if ($filtering === false)
							{
								array_push($this->errors, $name);
								$validation = false;
								break;
							}
							else { $this->data[$name] = $filtering; }
						}
					}
					else if ($mandatory || $not_empty)
					{
						array_push($this->errors, $name);
						$validation = false;
						continue;
					}
				}
			}
			
			return $validation;
		}
		
		public function apply($to)
		{
			$type = 'object';

			if ($to == null) { $to = new StdClass(); }
			else if (is_array($to)) { $type = 'array'; }
		
			foreach($this->fields as $field)
			{
				switch($type)
				{
					case 'object':
						$to->$field[0] = $this->data[$field[0]];
						break;
						
					case 'array':
						$to[$field[0]] = $this->data[$field[0]];
						break;
				}
			}
			
			return $to;
		}
		
		private function checkbox($name)
		{
			$values = array('off' => false, 'on' => true);
			
			if (!isset($this->data[$name]) || empty($this->data[$name]) || !array_key_exists($this->data[$name], $values))
			{ $this->data[$name] = 'off'; }
			
			$this->data[$name] = $values[$this->data[$name]];
		}
	}
	
	
	
	
	
	
	
	
	
	
	
	class Validator
	{
		private static $CUSTOM = array();
			public static function get($key) { return isset(self::$CUSTOM[$key]) ? self::$CUSTOM[$key] : self::$NOT_EMPTY; }

		public static $NOT_EMPTY;
		public static $HTML;							
		public static $ALPHANUMERIC;			
		public static $NUMERIC;							
		public static $ENCODABLE;				
		public static $ZIPCODE;				
		public static $EMAIL;
		public static $DATE;
		public static $DATETIME;
		public static $BOOLEAN;

		public static function initialize()
		{
			self::$NOT_EMPTY	= new Validator('not_empty');
			
			self::$HTML			= new Validator('html');
			
			self::$ALPHANUMERIC	= new Validator('alphanumeric',
												'#^([a-zA-Z0-9_\-àéèêëiïôçù,\s]+)$#i',
												array(FILTER_SANITIZE_STRING, FILTER_SANITIZE_SPECIAL_CHARS));
			
			self::$NUMERIC 		= new Validator('numeric',
												'#^([0-9]+)$#i',
												FILTER_SANITIZE_NUMBER_INT);
			
			self::$ENCODABLE	= new Validator('encodable',
												'#^[a-z0-9\-_]+$#',
												FILTER_SANITIZE_ENCODED);
												
			self::$ZIPCODE		= new Validator('zipcode',
												'#^[0-9]{5}$#');
			
			self::$EMAIL		= new Validator('email',
												'#^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,4}$#i',
												array(FILTER_VALIDATE_EMAIL, FILTER_SANITIZE_EMAIL));		
			
			self::$DATE			= new Validator('date',
												'#^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$#');
						
			self::$DATETIME		= new Validator('datetime',
												'#^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$#');
												
			self::$BOOLEAN		= new Validator('checkbox', '#^[true|false]$#');
		}


									
		private $name;	
		
		public $regex;
		public $filters;
		
		public function __construct($name, $regex = '#^.*$#i', $filters = null)
		{
			$this->name = $name;
			$this->regex = $regex;
			
			if ($filters != null) { $this->filters = is_array($filters) ? $filters : array($filters); }
			else { $this->filters = array(); }
			
			Validator::$CUSTOM[$name] = $this;
		}
	}
	
	Validator::initialize();

?>