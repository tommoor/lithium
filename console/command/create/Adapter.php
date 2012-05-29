<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2012, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\console\command\create;

use RuntimeException;
use lithium\util\String;
use lithium\util\Inflector;
use lithium\core\Libraries;
use lithium\analysis\Inspector;

/**
 * Generate an adapter class in the `--library` namespace, according to the `--type` specified.
 */
class Adapter extends \lithium\console\command\Create {

	/**
	 * The class to create an adapter for. Can be a short class name, i.e. `Cache`, or a
	 * fully-qualified class name, i.e. `lithium\storage\Session`. The class must have an
	 * `$_adapters` property defined, and may also have a custom class type registered with
	 * `Libraries::paths()`.
	 *
	 * @see lithium\core\Adaptable::$_adapters
	 * @see lithium\core\Libraries::paths()
	 * @var string
	 */
	public $type;

	/**
	 * The base class that the generated adapter should extend.
	 *
	 * @var string
	 */
	public $parent = '\lithium\core\Object';

	protected $_methodDef = "\tpublic function {:name}({:params}) {\n\t\t\n\t}";

	protected $_methodDefEnabled = "\tpublic static function enabled() {\n\t\t\n\t}";

	protected $_typeClass;

	protected function _init() {
		parent::_init();

		$findOptions = array(
			'recursive' => true,
			'filter' => '/' . preg_quote($this->type, '/') . '/',
			'exclude' => '/Mock|Test|\\\\adapter\\\\/i'
		);

		switch (true) {
			case (class_exists($this->type)):
				$this->_typeClass = $this->type;
			break;
			case (($located = Libraries::find(true, $findOptions)) && count($located) == 1):
				$this->_typeClass = reset($located);
			break;
			case ($located && count($located) > 1):
				$this->out("Multiple matches found for '{$this->type}'");

				foreach ($located as $i => $class) {
					$i++;
					$this->out("$i) {$class}");
				}
				$located = $located[intval($this->in('Please select one:')) - 1];
			break;
			default:
				$msg = "Unabled to find class for which to generate adapter. Please use the ";
				$msg .= "--type flag to specify a fully-qualified class name.";
				throw new RuntimeException($msg);
		}
	}

	/**
	 * Get the class name for the adapter.
	 *
	 * @param string $request
	 * @return string
	 */
	protected function _class($request) {
		return Inflector::camelize($request->action);
	}

	protected function _namespace($request, $options = array()) {
		$path = explode('.', $this->_adapterPath($this->_typeClass));
		$type = array_shift($path);
		$paths = Libraries::paths($type);

		$result = str_replace('\\\\', '\\', String::insert(reset($paths), array(
			'library' => $this->_library['prefix'],
			'namespace' => join('\\', $path),
			'class' => null,
			'name' => null
		)));
		return rtrim($result, '\\');
	}

	/**
	 * Gets the dot-separated adapter lookup path for the given adaptable class.
	 *
	 * @param string $class The fully-namespaced class name of the class to get the path for.
	 * @return string Returns the dot-separated service lookup path used to find adapters for the
	 *         given class.
	 */
	protected function _adapterPath($class) {
		if (!$result = Inspector::info($class . '::$_adapters')) {
			$msg = "Class `{$class}` is not a valid adapter-supporting class, ";
			$msg .= "no `\$_adapters` class property found.";
			throw new RuntimeException($msg);
		}
		return $result['value'];
	}

	protected function _parent($request) {
		if (class_exists($this->parent)) {
			return '\\' . ltrim($this->parent, '\\');
		}
		$result = Libraries::find(true, array(
			'recursive' => true,
			'filter' => '/' . preg_quote($this->parent, '/') . '/',
			'exclude' => '/Mock|Test|\\\\adapter\\\\/i'
		));
		return reset($result);
	}

	protected function _methods($request) {
		$methods = array();

		Inspector::methods($this->_typeClass)->map(function($method) use (&$methods) {
			$methods[$method->getName()] = array_map(
				function($param) {
					$name = '$' . $param->getName();

					if (!$param->isOptional()) {
						return $name;
					}
					$name .= ' = ';

					switch (true) {
						case ($param->getDefaultValue() === null):
							$name .= 'null';
						break;
						case ($param->getDefaultValue() === array()):
							$name .= 'array()';
						break;
						default:
							$name .= var_export($param->getDefaultValue(), true);
						break;
					}

					if ($name === '$options = array()' || $name === '$config = array()') {
						$name = "array {$name}";
					}
					return $name;
				},
				$method->getParameters()
			);
		});
		$result = array();

		foreach ($methods as $name => $params) {
			if (!$params || array_shift($params) !== '$name') {
				continue;
			}
			$params = join(', ', $params);
			$result[] = String::insert($this->_methodDef, compact('name', 'params'));
		}
		$result[] = $this->_methodDefEnabled;

		return join("\n\n", $result);
	}
}

?>