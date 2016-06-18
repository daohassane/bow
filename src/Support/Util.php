<?php
namespace Bow\Support;

use DateTime;
use InvalidArgumentException;
use Bow\Exception\UtilException;
use Bow\Exception\RouterException;

/**
 * Class Util
 *
 * @author Franck Dakia <dakiafranck@gmail.com>
 * @package Bow\Support
 */
class Util
{
	/**
	 * définir le type de retoure chariot CRLF ou LF
	 * @var string
	 */
	private static $sep;

	/**
	 * @var array
	 */
	private static $names = [];

	/**
	 * Configuration de date en francais.
	 * @var array
	 */
	private static $angMounth = [
		"Jan"  => "Jan", "Fév"  => "Feb",
		"Mars" => "Mar", "Avr"  => "Apr",
		"Mai"  => "Mai", "Juin" => "Jun",
		"Juil" => "Jul", "Août" => "Aug",
		"Sept" => "Sep", "Oct"  => "Oct",
		"Nov"  => "Nov", "Déc"  => "Dec"
	];

	/**
	 * @var array
	 */
	private static $month = [
		"Jan"  => "Janvier",  "Fév"  => "Fevrier",
		"Mars" => "Mars",     "Avr"  => "Avril",
		"Mai"  => "Mai",      "Juin" => "Juin",
		"Juil" => "Juillet",  "Août" => "Août",
		"Sept" => "Septembre", "Oct" => "Octobre",
		"Nov"  => "Novembre",  "Déc" => "Décembre"
	];

	/**
	 * buildSerialization, fonction permettant de construire des sérialisation
	 *
	 * @param string $file
	 * @param mixed $args
	 * @return string
	 */
	public static function serialization($file, $args)
	{
		// Sérialisation d'un mixed dans un fichier.
		return (bool) @file_put_contents($file, serialize($args));
	}

	/**
	 * UnBuildSerializationVariable, fonction permettant de récrier la variable sérialisé
	 *
	 * @param string $filePath
	 * @return mixed
	 */
	public static function deSerialization($filePath)
	{
		// Ouverture du fichier de sérialisation.
		$serializedData = @file_get_contents($filePath);

		if (is_string($serializedData)) {
			// On retourne l'element dé-sérialisé
			return @unserialize($serializedData);
		}

		return $serializedData;
	}

	/**
	 * dateDifference, faire la différence entre deux dates
	 *
	 * @param DateTime $date1
	 * @param DateTime $date2
	 * @return DateTime|void
	 */
	public static function dateDifference($date1, $date2)
	{
		return date_diff(date_create($date1), date_create($date2));
	}

	/**
	 * setTimeZone, modifie la zone horaire.
	 *
	 * @param string $zone
	 *
	 * @throws \ErrorException
	 */
	public static function setTimezone($zone)
	{
		if (count(explode("/", $zone)) != 2) {
			throw new UtilException("La définition de la zone est invalide");
		}

		date_default_timezone_set($zone);
	}

	/**
	 * Lanceur de callback
	 *
	 * @param callable $cb
	 * @param mixed $param
	 * @param array $names
	 * @throws RouterException
	 * @return mixed
	 */
	public static function launchCallback($cb, $param = null, array $names = [])
	{

		$param = is_array($param) ? $param : [$param];
		$function_list = [];

		if (!isset($names["namespace"])) {
			return static::execute_function($cb, $param);
		}

		static::$names = $names;

		if (!file_exists($names["autoload"] . ".php")) {
			throw new RouterException("L'autoload n'est pas défini dans le fichier de configuration", E_ERROR);
		}


		if (!isset($names["namespace"]["app"])) {
			throw new RouterException("Le namespace d'autoload n'est pas défini dans le fichier de configuration");
		}

		// Chargement de l'autoload
		@require $names["autoload"] . ".php";
		$autoload = $names["namespace"]["app"];
		@$autoload::register();
		$middleware = null;

		if (is_callable($cb)) {
			return call_user_func_array($cb, $param);
		}

		if (is_string($cb)) {
			return call_user_func_array(static::loadController($cb), $param);
		}

		if (is_array($cb)) {
			if (array_key_exists("middleware", $cb)) {
				$middleware = $cb["middleware"];
				unset($cb["middleware"]);
			}

			if (array_key_exists("uses", $cb)) {
				if (is_array($cb["uses"])) {
					if (isset($cb["uses"]["with"]) && isset($cb["uses"]["call"])) {
						if (is_string($cb["uses"]["call"])) {
							$controller = $cb["uses"]["with"] . "@" . $cb["uses"]["call"];
							array_push($function_list, static::loadController($controller));
						} else {
							foreach($cb["uses"]["call"] as $method) {
								$controller = $cb["uses"]["with"] . "@" . $method;
								array_push($function_list,  static::loadController($controller));
							}
						}
					} else {
						foreach($cb["uses"] as $controller) {
							if (is_string($controller)) {
								array_push($function_list,  static::loadController($controller));
							} else if (is_callable($controller)) {
								array_push($function_list, $controller);
							}
						}
					}
				} else {
					if (is_string($cb["uses"])) {
						array_push($function_list, static::loadController($cb["uses"]));
					} else {
						array_push($function_list, $cb["uses"]);
					}
				}

				unset($cb["uses"]);
			}

			if (count($cb) > 0) {
				foreach($cb as $func) {
					if (is_callable($func)) {
						array_push($function_list, $func);
					} else if (is_string($func)) {
						array_push($function_list, static::loadController($func));
					}
				}
			}
		}

		// Status permettant de bloquer la suite du programme.
		$status = true;

		// Execution du middleware si define.
		if (is_string($middleware)) {
			if (!in_array(ucfirst($middleware), $names["middlewares"], true)) {
				throw new RouterException($middleware . " n'est pas un middleware definir.", E_ERROR);
			}

			// Chargement du middleware
			$classMiddleware = $names["namespace"]["middleware"] . "\\" . ucfirst($middleware);

			// On vérifie si le middleware définie est une middleware valide.
			if (!class_exists($classMiddleware)) {
				throw new RouterException($middleware . " n'est pas un class Middleware.");
			}

			$instance = new $classMiddleware();
			$handler = [$instance, "handle"];
			$status = call_user_func_array($handler, $param);

			// Le middleware est un callback. les middleware peuvent être// définir comme des callback par l'utilisteur
		} else if (is_callable($middleware)) {
			$status = call_user_func_array($middleware, $param);
		}

		// On arrêt tout en case de status false.
		if ($status == false) {
			return false;
		}

		// Lancement de l'execution de la liste
		// fonction a execute suivant un ordre
		// conforme au middleware.
		if (!empty($function_list)) {
			$status = true;

			foreach($function_list as $func) {
				$status = call_user_func_array($func, $param);
				if ($status == false) {
					return $status;
				}
			}
		}

		return $status;
	}

	/**
	 * Next, lance successivement une liste de fonction.
	 *
	 * @param array|callable $arr
	 * @param array|callable $arg
	 * @return mixed|void
	 */
	private static function execute_function($arr, $arg)
	{
		if (is_callable($arr)) {
			return call_user_func_array($arr, $arg);
		}

		if (is_array($arr)) {
			// Lancement de la procedure de lancement recursive.
			array_reduce($arr, function($next, $cb) use ($arg) {
				// $next est-il null
				if (is_null($next)) {
					// On lance la loader de controller si $cb est un String
					if (is_string($cb)) {
						$cb = static::loadController($cb);
					}

					return call_user_func_array($cb, $arg);
				} else {
					// $next est-il a true.
					if ($next == true) {
						// On lance la loader de controller si $cb est un String
						if (is_string($cb)) {
							$cb = static::loadController($cb);
						}

						return call_user_func_array($cb, $arg);
					} else {
						die();
					}
				}

				return $next;
			});
		} else {
			// On lance la loader de controller si $cb est un String
			$cb = static::loadController($arr);

			if ($cb !== null) {
				return call_user_func_array($cb, $arg);
			}

			return null;
		}
	}

	/**
	 * Charge les controlleurs
	 *
	 * @param string $controllerName. Le nom du controlleur a utilisé
	 *
	 * @return array
	 */
	private static function loadController($controllerName)
	{
		// Récupération de la classe et de la methode à lancer.
		if (is_null($controllerName)) {
			return null;
		}

		list($class, $method) = preg_split("#\.|@#", $controllerName);
		$class = static::$names["namespace"]["controller"] . "\\" . ucfirst($class);

		return [new $class(), $method];
	}

	/**
	 * hourToLetter, convert une heure en letter Format: HH:MM:SS
	 *
	 * @param string $hour
	 * @return string
	 */
	public static function hourToLetter($hour)
	{
		if (!is_string($hour)) {
			return null;
		}

		if (preg_match("/[0-9]{1,2}(:[0-9]{1,2}){1,2}/", $hour)) {
			$hourPart = explode(":", $hour);
			$heures   = static::number2Letter($hourPart[0]) . " heure";
			$minutes  = static::number2Letter($hourPart[1]) . " minute";
			$secondes = " ";

			// accord des heures.
			if ($hourPart[0] > 1) {
				$heures .= "s";
			}

			// accord des minutes
			if ($hourPart[1] > 1) {
				$minutes .= "s";
			}

			// Ajout de secondes
			if (isset($hourPart[2]) && $hourPart[2] > 0) {
				$secondes .= static::number2Letter($hourPart[2]) . " secondes";
			}

			return strtolower($heures . " " . $minutes . $secondes);
		}

		// Retourne
		return null;
	}

	/**
	 * dateToLetter, convert une date sous forme de letter
	 *
	 * @param string $dateString
	 * @return string
	 */
	public static function dateToLetter($dateString)
	{
		if (preg_match("/^([0-9]{2,4})(?:-|\/)([0-9]{1,2})(?:-|\/)([0-9]{1,2})$/", $dateString, $m)) {
			array_shift($m);
			$r = static::number2Letter($m[2]). " ". static::toMonth((int) $m[1]) . " " . static::number2Letter($m[0]);
		} else if (preg_match("/^([0-9]{1,2})(?:-|\/)([0-9]{1,2})(?:-|\/)([0-9]{2,4})$/", $dateString, $m)) {
			array_shift($m);
			$r = static::number2Letter($m[0]) . " ". static::toMonth((int) $m[1]) . " " . static::number2Letter($m[2]);
		} else {
			$dateString = date("Y-m-d", strtotime($dateString));
			$m = explode("-", $dateString);
			$r = static::number2Letter($m[2]). " ". static::toMonth((int) $m[1]) . " " . static::number2Letter($m[0]);
		}

		$p = explode(" ", $r);

		if (strtolower($p[0]) == "un") {
			$p[0] = "permier";
		}

		return strtolower(trim(implode(" ", $p)));
	}

	/**
	 * Lance un var_dump sur les variables passées en paramètre.
	 *
	 * @throws InvalidArgumentException
	 * @return void
	 */
	public static function dump()
	{
		if (func_num_args() == 0) {
			throw new InvalidArgumentException(__METHOD__ ."(): Vous devez donner un paramètre à la fonction", E_ERROR);
		}

		$html = "";

		foreach (func_get_args() as $key => $value) {
			ob_start();
			// if (is_array($value) || is_object($value)) {
			// 	$len = ':len=' . count($value);
			// } else if (is_string($value)) {
			// 	$len = ":len=" . strlen($value);
			// }
			// echo gettype($value) . $len . ' <span id="toggle" class="show" style="border:1px solid #eee; padding:0.1px 0.2px;font-size:10px;color:#888"> > </span><div style="position: relative; left:25px; top:5px"><div class="contains">';
			var_dump($value);
			echo '</div></div>';
			echo "\n\n";

			$content = ob_get_clean();
			$content = preg_replace("~\s?\{\n\s?\}~i", "[]", $content);
			$content = preg_replace('~\((\d+)\)~im', "<span style=\"color: #498\">($1)</span>", $content);
			$content = preg_replace('~\s(".+")~im', "<span style=\"color: #458\"> $1</span>", $content);
			$content = preg_replace("~(=>)(\n\s+?)+~im", "", $content);
			$content = preg_replace('~\["(.+)"\]~im', "<span style=\"color:#666\"><span style=\"color: black\"></span>$1 =><span style=\"color: black\"></span></span>", $content);
			$content = preg_replace('~\[(.+)\]~im', "<span style=\"color:#666\"><span style=\"color: black\"></span>$1 =><span style=\"color: black\"></span></span>", $content);
			$content = "<pre><tt><div style=\"font-family: monaco, courier; font-size: 13px\">$content</div></tt></pre>";
			$html .= $content;
		}
		$script = <<<JS
			var toggleElement = document.querySelectorAll("#toggle");
			if (Object.prototype.toString.call(toggleElement) === "[object NodeList]") {
				for (var i = toggleElement.length - 1; i >= 0; i--) {
					toggleElement[i].style.cursor = "pointer";
					toggleElement[i].addEventListener("click", function(e) {
						var div = this.nextElementSibling;
						if (this.className == "show") {
							this.innerHTML = " > ";
							this.className = "hide";
							div.firstElementChild.style.display = "block";
						} else {
							this.className = "show";
							this.innerHTML = " < ";
							div.firstElementChild.style.display = "none";
						}
					}, false);
				}
			}
JS;
		die($html . '<script type="text/javascript">'.$script.'</script>');
	}

	/**
	 * systeme de débugage avec message d'info
	 *
	 * @param string $message
	 * @param callable $cb
	 *
	 * @return void
	 */
	public static function it($message, $cb = null)
	{
		echo "<h2>{$message}</h2>";

		if (is_callable($cb)) {
			call_user_func_array($cb, [static::class]);
		} else {
			static::dump(array_slice(func_get_args(), 1, func_num_args()));
		}
	}

	/**
	 * Permettant de convertir des chiffres en letter
	 *
	 * @param string $nombre
	 * @return string
	 */
	public static function number2Letter($nombre)
	{
		$nombre = (int) $nombre;

		if ($nombre === 0) {
			return "zéro";
		}

		/**
		 * Definition des elements de convertion.
		 */
		$nombreEnLettre = [
			"unite" => [
				null, "un", "deux", "trois", "quatre",
				"cinq", "six", "sept", "huit", "neuf",
				"dix", "onze", "douze", "treize", "quartorze",
				"quinze", "seize", "dix-sept", "dix-huit", "dix-neuf"
			],
			"ten" => [
				null, "dix", "vingt", "trente", "quarante", "cinquante",
				"soixante", "soixante",  "quatre-vingt", "quatre-vingt"
			]
		];

		/**
		 * Calcule des:
		 * - Unité
		 * - Dixaine
		 * - Centaine
		 * - Millieme
		 */
		$unite = $nombre % 10;
		$dixaine = ($nombre % 100 - $unite) / 10;
		$cent = ($nombre % 1000 - $nombre % 100) / 100;
		$millieme = ($nombre % 10000 - $nombre % 1000) / 1000;

		/**
		 * Calcule des unites
		 */
		$unitsOut = ($unite === 1 && $dixaine > 0 && $dixaine !== 8 ? 'et-' : '') . $nombreEnLettre['unite'][$unite];

		/**
		 * Calcule des dixaines
		 */
		if ($dixaine === 1 && $unite > 0) {
			$tensOut = $nombreEnLettre["unite"][10 + $unite];
			$unitsOut = "";
		} else if ($dixaine === 7 || $dixaine === 9) {
			$tensOut = $nombreEnLettre["ten"][$dixaine] . '-' . ($dixaine === 7 && $unite === 1 ? "et-" : "") . $nombreEnLettre["unite"][10 + $unite];
			$unitsOut = "";
		} else {
			$tensOut = $nombreEnLettre["ten"][$dixaine];
		}

		/**
		 * Calcule des centaines
		 */
		$tensOut .= ($unite === 0 && $dixaine === 8 ? "s": "");
		$centsOut = ($cent > 1 ? $nombreEnLettre["unite"][(int)$cent].' ' : '').($cent > 0 ? 'cent' : '').($cent > 1 && $dixaine == 0 && $unite == 0 ? '' : '');
		$tmp = $centsOut.($centsOut && $tensOut ? ' ': '').$tensOut.(($centsOut && $unitsOut) || ($tensOut && $unitsOut) ? '-': '').$unitsOut;

		/**
		 * Retourne avec les millieme associer.
		 */
		return ($millieme === 1 ? "mil":($millieme > 1 ? $nombreEnLettre["unite"][(int) $millieme]." mil" : "")).($millieme ? " ".$tmp : $tmp);
	}

	/**
	 * permettant de convertir mois en lettre.
	 *
	 * @param  string | integer $value
	 * @return string|null
	 */
	public static function toMonth($value)
	{
		if (!empty($value)) {
			if (is_string($value)) {
				// définition du tableau composants les mois  avec key en string
				if (strlen($value) == 3) {
					$value = ucfirst($value);
					$month = static::$month;
				} else {
					return null;
				}
			} else {
				$value = (int) $value;
				// définition du tableau composants les mois
				if ($value > 0 && $value <= 12) {
					$value -= 1;
				} else {
					return null;
				}

				$month = array_values(static::$month);
			}

			return $month[$value];
		}

		return null;
	}

	/**
	 * Formateur de donnée. key => :value
	 *
	 * @param array $data
	 * @return array $resultat
	 */
	public function add2points(array $data)
	{
		$resultat = [];

		foreach ($data as $key => $value) {
			$resultat[$value] = ":$value";
		}

		return $resultat;
	}

	/**
	 * sep, séparateur \r\n or \n
	 *
	 * @return string
	 */
	public static function sep()
	{
		if (static::$sep !== null) {
			return static::$sep;
		}

		if (defined('PHP_EOL')) {
			static::$sep = PHP_EOL;
		} else {
			static::$sep = (strpos(PHP_OS, 'WIN') === false) ? "\n" : "\r\n";
		}

		return static::$sep;
	}
}
