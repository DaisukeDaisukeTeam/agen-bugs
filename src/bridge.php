<?php

declare(strict_types=1);


namespace Daisukedaisuketeam\AgenBugs;

use SOFe\AwaitGenerator\Await;

class bridge{
	private $list = [];
	public function __construct(){

	}

	public function rateChild(int $id) : \Generator{
		try{
			yield from Await::promise(function(\Closure $resolve, \Closure $reject) use ($id){
				$this->list[$id] = [$resolve, $reject];
			});
		}catch(\Throwable $e){}
	}

	/**
	 * @param array<\Generator<mixed>> $array
	 * @throws \Throwable
	 */
	public function race(\Generator ...$array) : void{
		Await::g2c(Await::safeRace($array));
	}

	public function reject(int $id, \Throwable $throwable) : void{
		[$resolve, $reject] = $this->list[$id];
		($reject)($throwable);
	}

	public function solve(int $id, mixed $text) : void{
		[$resolve, $reject] = $this->list[$id];
		($resolve)($text);
	}
}