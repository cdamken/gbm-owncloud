<?php
/**
 * occ gbm_next:analyze <user>
 *
 * Prints the per-stock analysis (unrealized P&L, yield-on-cost) + portfolio
 * summary + concentration, computed from the DB. A read-only verification
 * surface for the Fase 5 analytics; the same AnalysisService backs the UI.
 */

namespace OCA\GbmNext\Command;

use OCA\GbmNext\Service\AnalysisService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Analyze extends Command {
	/** @var AnalysisService */
	private $analysis;

	public function __construct(AnalysisService $analysis) {
		parent::__construct();
		$this->analysis = $analysis;
	}

	protected function configure() {
		$this->setName('gbm_next:analyze')
			->setDescription('Per-stock analysis (unrealized P&L, yield-on-cost) from the DB')
			->addArgument('user', InputArgument::REQUIRED, 'ownCloud user id');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$uid = (string) $input->getArgument('user');
		try {
			$a = $this->analysis->perUser($uid);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $uid . ': ' . $e->getMessage() . '</error>');
			return 1;
		}
		$s = $a['summary'];
		$output->writeln(sprintf(
			'<info>%s</info>  positions=%d  cost=%.2f  market=%.2f  U-P&L=%.2f (%.2f%%)  div=%.2f  YoC=%.2f%%',
			$uid, $s['positions'], $s['cost_basis'], $s['market_value'],
			$s['unrealized_pl'], $s['unrealized_pct'], $s['dividends'], $s['yield_on_cost_pct']
		));
		$c = $a['concentration'];
		$output->writeln(sprintf(
			'concentration: top%d=%.1f%%  largest=%s %.1f%%  (total incl. cash=%.2f)',
			$c['top_n'], $c['top_n_pct'], $c['largest_ext_id'], $c['largest_pct'], $c['total_value']
		));
		$output->writeln('');
		$output->writeln(sprintf('  %-12s %10s %12s %12s %9s %9s', 'STOCK', 'QTY', 'COST', 'MARKET', 'U-P&L%', 'YoC%'));
		foreach ($a['per_stock'] as $r) {
			$output->writeln(sprintf(
				'  %-12s %10s %12.2f %12.2f %8.1f%% %8.1f%%',
				substr($r['ext_id'], 0, 12), rtrim(rtrim(sprintf('%.4f', $r['quantity']), '0'), '.'),
				$r['cost_basis'], $r['market_value'], $r['unrealized_pct'], $r['yield_on_cost_pct']
			));
		}
		return 0;
	}
}
