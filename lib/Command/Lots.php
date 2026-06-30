<?php
/**
 * occ gbm:lots <user>
 *
 * Recompute FIFO lots from the user's orders, persist open lots to gbm_lots,
 * and print realised P&L + coverage. Coverage matters for GBM: the order
 * history is incomplete, so realised P&L is only for instruments traded
 * within the captured window.
 */

namespace OCA\Gbm\Command;

use OCA\Gbm\Service\LotsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Lots extends Command {
	/** @var LotsService */
	private $lots;

	public function __construct(LotsService $lots) {
		parent::__construct();
		$this->lots = $lots;
	}

	protected function configure() {
		$this->setName('gbm:lots')
			->setDescription('Recompute FIFO lots + realized P&L from orders')
			->addArgument('user', InputArgument::REQUIRED, 'ownCloud user id');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$uid = (string) $input->getArgument('user');
		try {
			$r = $this->lots->recompute($uid);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $uid . ': ' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(sprintf(
			'<info>%s</info>  realized P&L=%.2f  open lots=%d  securities w/orders=%d  unmatched sell qty=%s',
			$uid, $r['realized_total'], $r['open_lots'], $r['securities_with_orders'],
			rtrim(rtrim(sprintf('%.4f', $r['unmatched_qty']), '0'), '.')
		));
		if ($r['unmatched_qty'] > 0.0001) {
			$output->writeln('  <comment>note: unmatched sells = sold qty with no buy in the captured order window (GBM gap) — excluded from realized P&L</comment>');
		}
		foreach ($r['per_security'] as $s) {
			$output->writeln(sprintf(
				'  %-12s realized=%.2f  (buys=%d sells=%d%s)',
				substr($s['ext_id'], 0, 12), $s['realized_pl'], $s['buys'], $s['sells'],
				$s['unmatched'] > 0.0001 ? ' unmatched=' . rtrim(rtrim(sprintf('%.4f', $s['unmatched']), '0'), '.') : ''
			));
		}
		return 0;
	}
}
