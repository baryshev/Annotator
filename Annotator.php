<?php

class AnnotatorException extends Exception {
}

class Annotator {

	const INFO = 0;
	const BEFORE = 1;
	const AFTER = 2;
	const AROUND = 3;

	public static $annotations = array();

	/**
	 * Register annotation handler
	 *
	 * @param string $annotation Annotation code
	 * @param callable $handler Annotation handler
	 * @param int $type Annotation type
	 */
	public static function register($annotation, $handler, $type) {
		if (!preg_match('!^[a-z0-9_]+$!i', $annotation)) {
			throw new AnnotatorException('Invalid annotation');
		}
		if (in_array($annotation, array('before', 'after', 'around'))) {
			throw new AnnotatorException('Annotation "' . $annotation . '" is reserved');
		}
		if (isset(self::$annotations[$annotation])) {
			throw new AnnotatorException('Annotation "' . $annotation . '" already registered');
		}
		if (!is_callable($handler)) {
			throw new AnnotatorException('Handler must be callable');
		}
		if (!is_int($type) || ($type < 0) || ($type > 3)) {
			throw new AnnotatorException('Invalid type');
		}
		self::$annotations[$annotation] = array('type' => $type, 'handler' => $handler);
	}

	/**
	 * Compile annotated class
	 *
	 * @param string $class Class name
	 */
	public static function compile($class) {
		try {
			$class = new ReflectionClass($class);
		} catch (Exception $e) {
			throw new AnnotatorException('Class ' . $class . ' does not exist');
		}
		$methods = $class->getMethods();

		$annotations = array('info', 'before', 'after', 'around');
		$annotations = array_merge($annotations, array_keys(self::$annotations));
		$pattern = '!\*\s*@((' . implode('|', $annotations) . ')[ \t]*([^\n\r]*))!';

		foreach ($methods as $method) {
			$comment = $method->getDocComment();

			if (empty($comment)) {
				continue;
			}

			preg_match_all($pattern, $comment, $matches);
			if (empty($matches[1])) {
				continue;
			}

			$flags = 0;
			$before = '';
			$after = '';
			$around = '';

			if ($method->isStatic()) {
				$treatment = 'self::';
				$callable = 'array(\'' . $class->name . '\', \'' . $method->name . '\')';
				$flags = $flags | RUNKIT_ACC_STATIC;
			} else {
				$treatment = '$this->';
				$callable = 'array($this, \'' . $method->name . '\')';
			}

			foreach ($matches[2] as $position => $annotation) {
				$options = split('[ 	]+', $matches[3][$position]);
				switch ($annotation) {
					case 'info' :
						call_user_func(array_shift($options), array($class->name, $method->name), $options);
						break;
					case 'before' :
						$before .= array_shift($options) . '(' . $callable . ', $__annotator_parameters, ' . var_export($options, true) . ');' . PHP_EOL;
						break;
					case 'after' :
						$after .= '$__annotator_result = ' . array_shift($options) . '(' . $callable . ', $__annotator_parameters, ' . var_export($options, true) . ', $__annotator_result);' . PHP_EOL;
						break;
					case 'around' :
						$around .= '$__annotator_proceed = function() use($__annotator_proceed, $__annotator_parameters) {
					return ' . array_shift($options) . '(' . $callable . ', $__annotator_parameters, ' . var_export($options, true) . ', $__annotator_proceed);
				};';
						break;
					default :
						switch (self::$annotations[$annotation]['type']) {
							case self::INFO :
								call_user_func(self::$annotations[$annotation]['handler'], array($class->name, $method->name), $options);
								break;
							case self::BEFORE :
								$before .= 'call_user_func(Annotator::$annotations[\'' . $annotation . '\'][\'handler\'], ' . $callable . ', $__annotator_parameters, ' . var_export($options, true) . ');' . PHP_EOL;
								break;
							case self::AFTER :
								$after .= '$__annotator_result = call_user_func(Annotator::$annotations[\'' . $annotation . '\'][\'handler\'], ' . $callable . ', $__annotator_parameters, ' . var_export($options, true) . ', $__annotator_result);' . PHP_EOL;
								break;
							case self::AROUND :
								$around .= '$__annotator_proceed = function() use($__annotator_proceed, $__annotator_parameters) {
					return call_user_func(Annotator::$annotations[\'' . $annotation . '\'][\'handler\'], ' . $callable . ', $__annotator_parameters, ' . var_export($options, true) . ', $__annotator_proceed);
				};';
								break;
						}
						break;
				}
			}

			if (empty($before) && empty($after) && empty($around)) {
				continue;
			}

			if ($method->isPublic()) {
				$flags = $flags | RUNKIT_ACC_PUBLIC;
			} elseif ($method->isProtected()) {
				$flags = $flags | RUNKIT_ACC_PROTECTED;
			} elseif ($method->isPrivate()) {
				$flags = $flags | RUNKIT_ACC_PRIVATE;
			}

			$parameters = $method->getParameters();
			$parametersForDefinition = array();
			$parametersForCall = array();
			$parametersForAdvice = array();
			$parametersForUse = array();
			foreach ($parameters as $parameter) {
				$value = '';
				$reference = '';
				if ($parameter->isDefaultValueAvailable()) {
					$value = '=' . var_export($parameter->getDefaultValue(), true);
				}
				if ($parameter->isPassedByReference()) {
					$reference = '&';
				}
				$parametersForDefinition[] = $reference . '$' . $parameter->name . $value;
				$parametersForCall[] = $reference . '$' . $parameter->name;
				$parametersForAdvice[] = '$__annotator_parameters[\'' . $parameter->name . '\'] = ' . '&$' . $parameter->name . ';';
				$parametersForUse[] = '$' . $parameter->name;
			}
			$use = '';
			if (!empty($parametersForUse)) {
				$use = 'use (' . implode(', ', $parametersForUse) . ')';
			}

			runkit_method_rename($class->name, $method->name, '__annotator_' . $method->name);
			$function = '
				$__annotator_parameters = array();
				' . implode(' ', $parametersForAdvice) . '
				' . $before . '
				$__annotator_proceed = function() ' . $use . ' {
					return ' . $treatment . '__annotator_' . $method->name . '(' . implode(', ', $parametersForCall) . ');
				};
				' . $around . '
				$__annotator_result = $__annotator_proceed();
				' . $after . '
				return $__annotator_result;
			';
			runkit_method_add(
				$class->name,
				$method->name,
				implode(',', $parametersForDefinition),
				$function,
				$flags
			);
		}
	}
}