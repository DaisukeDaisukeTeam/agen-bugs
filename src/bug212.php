<?php

declare(strict_types=1);


namespace Daisukedaisuketeam\AgenBugs;

class bug212 implements Bugs{
	public function main() : void{
		$bridge = new bridge();
		$this->forceCollectCycles(1);
		$races = [$bridge->rateChild(1)];
		$bridge->race(...$races);
		$this->forceCollectCycles(2);
		$bridge->solve(1, "1");
		$this->forceCollectCycles(3);
		$this->forceCollectCycles(4);
		$this->dump();
	}

	public function dump() : void{
		echo "===========gc===========" . PHP_EOL;
		$bridge = new bridge();
		$this->forceCollectCycles(1);
		$races = [$bridge->rateChild(1)];
		$bridge->race(...$races);
		dumper::dumpMemory($bridge, "a", 100, 20000000000);
		$this->forceCollectCycles(2);
		$bridge->solve(1, "1");
		$this->forceCollectCycles(3);
	}

	public function forceCollectCycles(int $runs) : int{
		$rootsBefore = gc_status()["roots"];

		$start = hrtime(true);
		$cycles = gc_collect_cycles();
		$end = hrtime(true);

		$rootsAfter = gc_status()["roots"];

		$time = $end - $start;
		echo (sprintf(
			"Run #%d took %s ms (%s -> %s roots, %s cycles collected) - cumulative GC time: %s ms" . PHP_EOL,
			$runs,
			number_format($time / 1_000_000, 2),
			$rootsBefore,
			$rootsAfter,
			$cycles,
			0,
		));

		return $cycles;
	}
}

