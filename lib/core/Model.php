<?php

	class Model
	{
		public static $table = 'null';
		public static $fields = array();

		public static function request($sql, $placeholders = null)
		{
			$statement = App::$db->prepare($sql);
			
			if ($placeholders != null)
			{
				foreach($placeholders as $key => $value)
				{ $statement->bindValue(':'.$key, $value, self::cast($value, true)); }
			}
			
			$data = $statement->execute();
			
			if (strpos($sql, 'SELECT') === 0) { return $statement->fetchAll(PDO::FETCH_OBJ); }
			
			return $data;
		}

		public static function get($elements = 'all', $order = null, $simplify = true)
		{
			$caller = get_called_class();
			
			$table = '`'.$caller::$table.'`';
			$fields = '`'.implode('`, `', array_keys($caller::$fields)).'`';

			$id_field = array_keys($caller::$fields);
				$id_field = $id_field[0];

			$where = '';
			$placeholders = array();

			if ($elements != 'all')
			{
				$where = ' WHERE ';
				if (is_numeric($elements))
				{
					$elements = '`'.$id_field.'` = :'.$id_field;
					$placeholders[$id_field] = $elements;
				}
				else if (is_array($elements))
				{
					$conditions = array();
					$possibilities = array();
					
					foreach($elements as $key => $value)
					{
						if (!is_array($value))
						{
							array_push($conditions, '`'.$key.'` = :'.$key);
							$placeholders[$key] = $value;
						}
						else
						{
							for ($j = 0; $j < count($value); $j++)
							{
								array_push($possibilities, '`'.$key.'` = :'.$key.$j);
								$placeholders[$key.$j] = $value[$j];
							}
						}
					}

					$elements = '';
					$elements .= implode(' AND ', $conditions);
					$elements .= implode(' OR ', $possibilities);
				}

				$where .= $elements;

				if ($simplify === null) { $simplify = true; }
			}

			if ($order != null) { $order = ' ORDER BY '.$order; }

			$statement = App::$db->prepare('SELECT '.$fields.' FROM '.$table.$where.$order);
			
			foreach($placeholders as $key => $value) { $statement->bindValue(':'.$key, $value, self::cast($value, true)); }

			$statement->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, $caller);
			$statement->execute();

			$data = $statement->fetchAll();

			if ($data != null)
			{
				if ($simplify === true && count($data) == 1) { return $data[0]; }
				else { return $data; }
			}
			else { return null; }
		}

		private static function pdoize($data)
		{
			$type = self::cast($data, false);
			
			switch($type)
			{
				case 'int':
					$data = (int)$data;
					break;
					
				case 'string':
					$data = (string)$data;
					break;
					
				case 'bool':
					$data = (bool)$data;
					break;
			}
			
			$type = self::cast($data, true);
			
			return array($data, $type);
		}

		private static function cast($data, $pdo = false)
		{
			if ($data === NULL) { return 'null'; }

			switch(true)
			{
				case is_numeric($data):		return !$pdo ? 'int' : PDO::PARAM_INT;
				case is_string($data):		return !$pdo ? 'string' : PDO::PARAM_STR;
				case is_bool($data):		return !$pdo ? 'bool' : PDO::PARAM_INT;
			}
		}



		protected $data = null;

		public function __construct() {}

		protected function initialize()
		{
			$caller = get_called_class();
			foreach($caller::$fields as $field => $type) { $this->data[$field] = null; }
		}

		public function keys()
		{
			$caller = get_called_class();

			$keys = array();
			foreach($caller::$fields as $field => $type) { array_push($keys, $field); }

			return $keys;
		}

		public function __get($name)
		{
			if ($this->data == null) { $this->initialize(); }

			$caller = get_called_class();

			return array_key_exists($name, $caller::$fields) ? $this->data[$name] : NULL;
		}

		public function __set($name, $value)
		{
			if ($this->data == null) { $this->initialize(); }

			$caller = get_called_class();
			if (array_key_exists($name, $caller::$fields))
			{
				switch($caller::$fields[$name])
				{
					case 'string':
						$value = stripslashes((string)$value);
						break;

					case 'int':
						$value = (int)$value;
						break;

					case 'float':
						$value = (float)$value;
						break;

					case 'bool':
						$value = in_array($value, array(1, '1', true, 'true'));
						break;
				}

				$this->data[$name] = $value;
			}
		}

		public function save()
		{
			$caller = get_called_class();

			$fields = array_keys($caller::$fields);
				$id_field	= $fields[0];
				$id_type	= $caller::$fields[$id_field];

			$fork = $this->$id_field == null;

			$placeholders = array();

			if ($fork)
			{
				foreach($caller::$fields as $field => $type) { $placeholders[$field] = $this->$field; }

				$sql  = 'INSERT INTO `'.$caller::$table.'` ';
				$sql .= '(`'.implode('`, `', array_keys($placeholders)).'`) ';
					unset($placeholders[$id_field]);
				$sql .= 'VALUES (NULL, :'.implode(', :', array_keys($placeholders)).');';
			}
			else
			{
				$sql  = 'UPDATE `'.$caller::$table.'` ';
				$sql .= 'SET ';

				$dump = array();

				foreach($caller::$fields as $field => $type)
				{
					$placeholders[$field] = $this->$field;
					$sql .= '`'.$field.'`=:'.$field.', ';
				}

				$sql = substr($sql, 0, -2).' WHERE `'.$id_field.'`=:'.$id_field.';';

				App::debug($caller.' (ID='.$this->$id_field.') updated.');
			}

			$statement = App::$db->prepare($sql);
			foreach($placeholders as $key => $value)
			{
				list($data, $type) = self::pdoize($value);
				$statement->bindValue(':'.$key, $data, $type);
			}

			$execution = $statement->execute();

			App::debug('query'.($execution ? '' : ' NOT').' executed', 2);

			if ($fork)
			{
				$this->$id_field = App::$db->lastInsertId();
				App::debug($caller.' (ID='.$this->$id_field.') inserted.');
			}
		}

		public function delete()
		{
			$caller = get_called_class();

			$fields = array_keys($caller::$fields);
				$id_field 	= $fields[0];
				$id_value	= $this->$id_field;

			$sql = 'DELETE FROM '.$caller::$table.' WHERE `'.$id_field.'` = :id_value;';

			$statement = App::$db->prepare($sql);
				$statement->bindValue(':id_value', $id_value, self::cast($id_value, true));

			return $statement->execute();
		}

		public function export($fields = null)
		{
			$caller = get_called_class();
			if ($fields == null) { $fields = $this->keys(); }

			$clone = new StdClass();

			foreach($fields as $field) { $clone->$field = $this->$field; }

			return $clone;
		}
	}
	
	
	
	
	
	
	
	
	
	
	
	class Data
	{
		private $data;

		public function __construct($data) { $this->data = $data != null ? $data : new Blank();}
		public function __get($value)
		{
			if (get_class($this->data) == 'stdClass') { return isset($this->data->$value) ? $this->data->$value : new Blank(); }
			else { return $this->data->$value !== null ? $this->data->$value : new Blank(); }
		}

		public function __call($value, $default) { return $this->$value != '' ? $this->$value : $default[0]; }
	}
	
	
	
	
	
	
	
	
	
	
	
	class Blank extends StdClass
	{
		public function __get($value) { return ''; }
		public function __toString() { return ''; }
	}
		
?>