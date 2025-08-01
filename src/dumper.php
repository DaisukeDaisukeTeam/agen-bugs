<?php

declare(strict_types=1);


namespace Daisukedaisuketeam\AgenBugs;

final class dumper{

	private function __construct(){
		//NOOP
	}

	/**
	 * Generator which forces array keys to string during iteration.
	 * This is necessary because PHP has an anti-feature where it casts numeric string keys to integers, leading to
	 * various crashes.
	 *
	 * @phpstan-template TKeyType of string
	 * @phpstan-template TValueType
	 * @phpstan-param array<TKeyType, TValueType> $array
	 * @phpstan-return \Generator<TKeyType, TValueType, void, void>
	 */
	public static function stringifyKeys(array $array) : \Generator{
		foreach($array as $key => $value){ // @phpstan-ignore-line - this is where we fix the stupid bullshit with array keys :)
			yield (string) $key => $value;
		}
	}

	/**
	 * Returns a readable identifier for the given Closure, including file and line.
	 *
	 * @phpstan-param anyClosure $closure
	 * @throws \ReflectionException
	 */
	public static function getNiceClosureName(\Closure $closure) : string{
		$func = new \ReflectionFunction($closure);
		if(!str_contains($func->getName(), '{closure')){
			//closure wraps a named function, can be done with reflection or fromCallable()
			//isClosure() is useless here because it just tells us if $func is reflecting a Closure object

			$scope = $func->getClosureScopeClass();
			if($scope !== null){ //class method
				return
					$scope->getName() .
					($func->getClosureThis() !== null ? "->" : "::") .
					$func->getName(); //name doesn't include class in this case
			}

			//non-class function
			return $func->getName();
		}
		$filename = $func->getFileName();

		return "closure@" . ($filename !== false ?
				$filename . "#L" . $func->getStartLine() :
				"internal"
			);
	}


	/**
	 * Returns a string that can be printed, replaces non-printable characters
	 */
	public static function printable(mixed $str) : string{
		if(!is_string($str)){
			return gettype($str);
		}

		return preg_replace('#([^\x20-\x7E])#', '.', $str);
	}

	/**
	 * Static memory dumper accessible from any thread.
	 */
	public static function dumpMemory(mixed $startingObject, string $outputFolder, int $maxNesting, int $maxStringSize) : void{
		$gcEnabled = gc_enabled();
		gc_disable();

		if(!file_exists($outputFolder)){
			mkdir($outputFolder, 0777, true);
		}
		$objects = [];

		$refCounts = [];

		$instanceCounts = [];

		$staticProperties = [];
		$staticCount = 0;

		$functionStaticVars = [];
		$functionStaticVarsCount = 0;

		foreach(get_declared_classes() as $className){
			$reflection = new \ReflectionClass($className);
			$staticProperties[$className] = [];
			foreach($reflection->getProperties() as $property){
				if(!$property->isStatic() || $property->getDeclaringClass()->getName() !== $className){
					continue;
				}

				if(!$property->isInitialized()){
					continue;
				}

				$staticCount++;
				$staticProperties[$className][$property->getName()] = self::continueDump($property->getValue(), $objects, $refCounts, 0, $maxNesting, $maxStringSize);
			}

			if(count($staticProperties[$className]) === 0){
				unset($staticProperties[$className]);
			}

			foreach($reflection->getMethods() as $method){
				if($method->getDeclaringClass()->getName() !== $reflection->getName()){
					continue;
				}
				$methodStatics = [];
				foreach($method->getStaticVariables() as $name => $variable){
					$methodStatics[$name] = self::continueDump($variable, $objects, $refCounts, 0, $maxNesting, $maxStringSize);
				}
				if(count($methodStatics) > 0){
					$functionStaticVars[$className . "::" . $method->getName()] = $methodStatics;
					$functionStaticVarsCount += count($functionStaticVars);
				}
			}
		}

		//var_dump(json_encode($staticProperties, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

		$globalVariables = [];
		$globalCount = 0;

		$ignoredGlobals = [
			'GLOBALS' => true,
			'_SERVER' => true,
			'_REQUEST' => true,
			'_POST' => true,
			'_GET' => true,
			'_FILES' => true,
			'_ENV' => true,
			'_COOKIE' => true,
			'_SESSION' => true
		];

		foreach($GLOBALS as $varName => $value){
			if(isset($ignoredGlobals[$varName])){
				continue;
			}

			$globalCount++;
			$globalVariables[$varName] = self::continueDump($value, $objects, $refCounts, 0, $maxNesting, $maxStringSize);
		}

		foreach(get_defined_functions()["user"] as $function){
			$reflect = new \ReflectionFunction($function);

			$vars = [];
			foreach($reflect->getStaticVariables() as $varName => $variable){
				$vars[$varName] = self::continueDump($variable, $objects, $refCounts, 0, $maxNesting, $maxStringSize);
			}
			if(count($vars) > 0){
				$functionStaticVars[$function] = $vars;
				$functionStaticVarsCount += count($vars);
			}
		}

		$data = self::continueDump($startingObject, $objects, $refCounts, 0, $maxNesting, $maxStringSize);

		do{
			$continue = false;
			foreach(self::stringifyKeys($objects) as $hash => $object){
				if(!is_object($object)){
					continue;
				}
				$continue = true;

				$className = get_class($object);
				if(!isset($instanceCounts[$className])){
					$instanceCounts[$className] = 1;
				}else{
					$instanceCounts[$className]++;
				}

				$objects[$hash] = true;
				$info = [
					"information" => "$hash@$className",
				];
				if($object instanceof \Closure){
					$info["definition"] = self::getNiceClosureName($object);
					$info["referencedVars"] = [];
					$reflect = new \ReflectionFunction($object);
					if(($closureThis = $reflect->getClosureThis()) !== null){
						$info["this"] = self::continueDump($closureThis, $objects, $refCounts, 0, $maxNesting, $maxStringSize);
					}

					foreach($reflect->getStaticVariables() as $name => $variable){
						$info["referencedVars"][$name] = self::continueDump($variable, $objects, $refCounts, 0, $maxNesting, $maxStringSize);
					}
				}else{
					$reflection = new \ReflectionObject($object);

					$info["properties"] = [];

					for($original = $reflection; $reflection !== false; $reflection = $reflection->getParentClass()){
						foreach($reflection->getProperties() as $property){
							if($property->isStatic()){
								continue;
							}

							$name = $property->getName();
							if($reflection !== $original){
								if($property->isPrivate()){
									$name = $reflection->getName() . ":" . $name;
								}else{
									continue;
								}
							}
							if(!$property->isInitialized($object)){
								continue;
							}

							$info["properties"][$name] = self::continueDump($property->getValue($object), $objects, $refCounts, 0, $maxNesting, $maxStringSize);
						}
					}
				}

				var_dump(json_encode($info, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			}

		}while($continue);

		foreach($refCounts as $id => $refCount){
			if($refCount !== 1){
				echo $id . " has $refCount references\n";
			}
		}
	}

	/**
	 * @param object[]|true[] $objects   reference parameter
	 * @param int[]           $refCounts reference parameter
	 *
	 * @phpstan-param array<string, object|true> $objects
	 * @phpstan-param array<string, int> $refCounts
	 * @phpstan-param-out array<string, object|true> $objects
	 * @phpstan-param-out array<string, int> $refCounts
	 */
	private static function continueDump(mixed $from, array &$objects, array &$refCounts, int $recursion, int $maxNesting, int $maxStringSize) : mixed{
		if($maxNesting <= 0){
			return "(error) NESTING LIMIT REACHED";
		}

		--$maxNesting;

		if(is_object($from)){
			if(!isset($objects[$hash = spl_object_hash($from)])){
				$objects[$hash] = $from;
				$refCounts[$hash] = 0;
			}

			++$refCounts[$hash];

			$data = "(object) $hash";
		}elseif(is_array($from)){
			if($recursion >= 5){
				return "(error) ARRAY RECURSION LIMIT REACHED";
			}
			$data = [];
			$numeric = 0;
			foreach($from as $key => $value){
				$data[$numeric] = [
					"k" => self::continueDump($key, $objects, $refCounts, $recursion + 1, $maxNesting, $maxStringSize),
					"v" => self::continueDump($value, $objects, $refCounts, $recursion + 1, $maxNesting, $maxStringSize),
				];
				$numeric++;
			}
		}elseif(is_string($from)){
			$data = "(string) len(" . strlen($from) . ") " . substr(self::printable($from), 0, $maxStringSize);
		}elseif(is_resource($from)){
			$data = "(resource) " . print_r($from, true);
		}elseif(is_float($from)){
			$data = "(float) $from";
		}else{
			$data = $from;
		}

		return $data;
	}
}
