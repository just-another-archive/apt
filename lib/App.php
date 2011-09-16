<?php

	require_once('load.php');

	class App
	{
		private static $post_dispatch_data;

		public static $log = '';
		private static $buffer = '';
		
		public static $version = '0.1';

		public static $db;
		public static $br = '<br/>';

		public static $config;		// xml config of the app
		public static $routes;		// routes of the app
		public static $subdomain;	// subdomain of the app
		public static $domain;		// domain of the app

		public static $root = '';	// document_root of the application
		public static $path = '';	// absolute path of the app
		public static $real = '';	// name of the app in the url
		public static $name = '';	// name of the app
		public static $admn = '';	// admin of the app

		public static $dev = false;
		public static $i18n = null;
		public static $data;
		
		public static function dispatch($env, $directory = '/')
		{

			self::$data = new StdClass();
			self::$root = $directory;

			//environment file parsing
			$conf = simplexml_load_file(SRC.'/config/'.$env.'.xml');
			
			//database and constants solving
			foreach($conf->database->children() as $dbconf)	{ define('DB_'.strtoupper((string)$dbconf->getName()), (string)$dbconf); }

			if (isset($conf->constants))
			{
				foreach($conf->constants->constant as $constant)
				{ if (!defined(strtoupper((string)$constant['name']))) define(strtoupper((string)$constant['name']), (string)$constant['value']); }
			}

			if (isset($conf->timezone)) { date_default_timezone_set((string)$conf->timezone); }

			//domain and routes solving
			$domain = (string)$conf->routes['domain'];
			$routes = array();

			foreach($conf->routes->subdomain as $subdomain)
			{
				$modules = null;
				if (count($subdomain->module) > 1)
				{
					$modules = array();
					foreach($subdomain->module as $module) { $modules[(string)$module['url']] = (string)$module['name']; }
				}
				else { $modules = (string)$subdomain->module['name']; }

				$routes[(string)$subdomain['name']] = $modules;
			}

			App::$routes = $routes;
			App::$config = $conf;
			
			//subdomain parsing
			$subdomain = substr($_SERVER['SERVER_NAME'], 0, strpos($_SERVER['SERVER_NAME'], $domain) - 1);
			if (!array_key_exists($subdomain, $routes)) { self::error(403, 0); }
			
			self::$subdomain = $subdomain;
			self::$domain = $domain;

			$routes = isset($routes[$subdomain]) ? $routes[$subdomain] : App::error(403, 1);
				if (!is_array($routes)) { $routes = array('/' => $routes); }

			//module parsing
			$uri = substr($_SERVER['REQUEST_URI'], 1);
				if(strpos($uri, '?') !== false) { $uri = substr($uri, 0, strpos($uri, '?')); }
				if (strlen($uri) > 0 && $uri[strlen($uri) - 1] == '/') { $uri = substr($uri, 0, -1); }

			$rewrite = file_get_contents('.htaccess');

			$matches = array();
				preg_match('/RewriteBase (.*)/', $rewrite, $matches);
				$rewrite = substr($matches[1], 1);
			
			$execute = explode('/', $uri);
				if ($execute[0] == $rewrite) { array_shift($execute); }
			
			$app = '/';

			if (array_key_exists('/'.$execute[0], $routes))
			{
				$app .= $execute[0];
				array_shift($execute);
			}

			self::$path = APPS.'/'.$routes[$app];
			self::$real = $app;
			self::$name = $routes[$app];

			$module = self::$config->xpath('//module[@name="'.(self::$name).'"]');
				if (is_array($module)) { $module = $module[0]; }

			//local constant solving
			if (isset($module->constants))
			{
				foreach($module->constants->constant as $constant)
				{ if (!defined(strtoupper((string)$constant['name']))) define(strtoupper((string)$constant['name']), (string)$constant['value']); }
			}

			//local dev solving
			self::$dev	= isset($module->debug);

			if (self::$dev)
			{
				error_reporting(E_ALL);
				ini_set('display_errors', 1);
			}

			//http(s) fallback if needed (NEED MORE CONF)
			if (false && $_SERVER['HTTPS'] != 'on' && self::ssl(self::$name, false))
			{
				if (isset($module['fallback'])) { App::redirect('http://'.(string)$module['fallback'], 307, false); }
				else { App::error(401); }
			}			
			
			define('APP', self::$path);			
			define('TPL', self::$path.'/templates');

			if (!file_exists(self::$path)) { self::error(404); }

			//database initialization
			try
			{
				self::$db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
				self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				self::$db->query('SET NAMES "utf8"');
			}
			catch(PDOException $e) { App::debug($e->getMessage()); }

			//loading a static key-value dictionary for translation
			if (file_exists(self::$path.'/i18n.xml')) { self::$i18n = simplexml_load_file(self::$path.'/i18n.xml'); }

			//loading application functions
			$file = self::$path.'/functions.php';
			if (file_exists($file)) { require_once($file); }

			if (function_exists('launch')) { launch(); }
			if (!Session::$id) { Session::start(); }

			//opening the main buffer of the application
			$bool = ob_start();
				self::debug($bool ? 'output buffering successfully started' : 'output buffering error', 6);

			//try to use manual front controller or execute typical controller with pre/post dispatch hooks
			if (function_exists('handle'))
			{
				if (function_exists('pre_dispatch')) { pre_dispatch($execute); }

				self::helpers();
				self::$post_dispatch_data = handle($execute);
			}
			else
			{
				$redirect = false;

				switch(count($execute))
				{
					case 0:
						$controller = $action = 'index';
						break;

					case 1:
						$controller = $execute[0];
						$action = 'index';

						$redirect = ($execute[0] == 'index');
						if ($redirect) { array_splice($execute, 0); }
						break;

					case 2:
						$controller = $execute[0];
						$action = $execute[1];

						$redirect = ($execute[1] == 'index');
						if ($redirect)
						{
							array_splice($execute, 1);
							if ($controller == 'index') { array_shift($execute); }
						}
						break;
				}

				if ($redirect) { self::redirect(implode('/', $execute)); }

				$controller = count($execute) > 0 ? $execute[0] : 'index';
				$action = count($execute) > 1 ? $execute[1] : 'index';

					array_shift($execute);
					array_shift($execute);

				if (in_array($action, array('handle', 'pre_dispatch', 'post_dispatch', 'i18n', 'wildcard'))) { self::redirect($controller.'/'.implode('/', $execute)); }

				self::debug('controller: '.$controller, 5);
				self::debug('action: '.$action, 5);
				self::debug('rest: '.print_r($execute, true), 5);

				if (self::function_exists('i18n'))
				{
					$translation = i18n($controller, $action);
					$translation = explode('/', $translation);

					$controller = $translation[0];
					$action = $translation[1];
				}

				$file = self::$path.'/controllers/'.$controller.'.php';
				
				
				if (file_exists($file))
				{
					require_once($file);

					if (self::function_exists($action))
					{
						self::helpers();

						if (function_exists('pre_dispatch')) { pre_dispatch(array(self::$name, $controller, $action) + $execute); }
						self::$post_dispatch_data = call_user_func($action, $execute);
					}
					else if (self::function_exists('wildcard'))
					{
						self::helpers();

						array_unshift($execute, $action);

						if (self::function_exists('pre_dispatch')) { pre_dispatch(array(self::$name, $controller, $action) + $execute); }
						self::$post_dispatch_data = call_user_func('wildcard', $execute);
					}
					else { self::error(404); }
				}
				else { self::error(404); }
			}

			self::display();
		}

		public static function render($tpl, $data = null)
		{
			if (is_array($tpl))
			{
				if (!is_array($data)) { foreach($tpl as $file) { self::render($file, $data); } }
				else { for($i = 0; $i < count($tpl); $i++) { self::render($tpl[$i], $data[$i]); } }

				return;
			}

			$view = new Data($data);
			include($tpl);
		}

		public static function partial($tpl, $datas)
		{
			$i = 0;
			if (!is_array($datas)) { $datas = array($datas); }
			
			$bool = ob_start();

			foreach($datas as $data)
			{
				if ($data != null)
				{
					$data->index = $i++;
					$view = new Data($data);
					include($tpl);
				}
			}

			return ob_get_clean();
		}

		public static function display($override = null)
		{
			if (function_exists('post_dispatch'))
			{
				post_dispatch(self::$post_dispatch_data);
				self::$post_dispatch_data = null;
			}

			if ($override == null) { $override = array(); }
				$override['{APP_ROOT}'] = self::$root;
				$override['{APP_NAME}'] = self::$name;
			
			if (self::$dev) { $override['{DEBUG}'] = self::$log; }

			$echo = ob_get_clean();

			self::debug('output buffering released', 6);

			if (self::$dev && strpos($echo, '{DEBUG}') === false) { $echo = $echo.'{DEBUG}'; }

			foreach($override as $tag => $content) { $echo = str_replace($tag, $content, $echo); }

			die($echo);
		}

		public static function link($to, $application = '')
		{
			$method = self::ssl(($application == '' ? self::$name : $application));	
			
			if ($application == '') { return $method.self::$subdomain.'.'.self::$domain.str_replace('//', '/', self::$real.$to); }
			else return $method.str_replace('//', '/', self::module($application).$to);
		}

		public static function module($application)
		{
			$urls = array();

			foreach(App::$routes as $subdomain => $configuration):
				if (!is_array($configuration)) { $configuration = array('/' => $configuration); }

				foreach($configuration as $subdirectory => $app_name):
					if ($app_name == $application) { array_push($urls, self::ssl($app_name).$subdomain.'.'.self::$domain.($subdirectory != '/' ? $subdirectory : '')); }
				endforeach;
			endforeach;

			return count($urls) > 1 ? $urls : $urls[0];
		}

		public static function ssl($app, $protocol = true)
		{
			$module = self::$config->xpath('//module[@name="'.($app).'"]');
				$module = $module[0];
				
			$method = isset($module['ssl']) ? ((string)$module['ssl'] == 'true') : false;
			
			if (!$protocol) { return $method; }
			else { return $method ? 'https://' : 'http://'; }
		}
		
		public static function redirect($url, $code = 302, $auto = true) { header('Location: '.($auto ? self::link($url) : $url), true, $code); }

		public static function crossdomain($url)
		{
			header('Access-Control-Allow-Origin: '.$url);
			header('Access-Control-Allow-Credentials: true');
		}

		public static function debug($content, $level = 0, $decorator = false)
		{
			if ($decorator == true)
			{
				switch($level)
				{
					default:
					case 0:	$color = '#222'; break;	

					case 1:	$color = '#b00000'; break;
					case 2:	$color = '#ff9900'; break;
					case 3:	$color = '#99ff00'; break;
					case 4:	$color = '#00ffbb'; break;
					case 5:	$color = '#0099ff'; break;
					case 6:	$color = '#ff0099'; break;
				}

				$content = '<p style="margin:0;padding:0;font-family:Verdana;font-size:9px;color:'.$color.'">'.(!is_string($content) ? print_r($content, true) : $content).'</p>';
			}
			
			if (strlen(self::$log) != 0) { self::$log .= "\r\n"; }
			self::$log .= $content;

			return $content;
		}

		public static function error($number, $msg = '')
		{
			ob_end_clean();

			switch($number)
			{
				case 401:
					header($_SERVER['SERVER_PROTOCOL'].' 401 Authorization Required');
					die('<h1>Authorization Required '.$msg.'</h1>');

				case 403:
					header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden');
					die('<h1>Forbidden Access '.$msg.'</h1>');

				case 404:
					header($_SERVER['SERVER_PROTOCOL'].' 404 Not Found');
					die('<h1>Page not found '.$msg.'</h1>');

				case 500:
					header($_SERVER['SERVER_PROTOCOL'].' 500 Internal Server Error');
					die('<h1>Internal Server Error '.$msg.'</h1>');
			}
		}

		private static function helpers()
		{
			$folders = array(HLP, APP.'/helpers');
			
			foreach($folders as $folder)
			{
				$dir = @opendir($folder);
					
				if ($dir !== false)
				{
	
					while(false !== ($file = readdir($dir)))
					{ if ($file[0] != ".") { require_once($folder.'/'.$file); } }
	
					closedir($dir);
				}
			}
		}

		private static function function_exists($arg)
		{
			$methods = get_defined_functions();
			$methods = $methods['user'];
			
			foreach ($methods as $method) { if ($method == $arg) { return true; } }
			
			return false;
		}

		public static function random($length = null) { return $length != null ? substr(md5(uniqid(microtime())), 0, $length) : md5(uniqid(microtime()));  }
	}

?>