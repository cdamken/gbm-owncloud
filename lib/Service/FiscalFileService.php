<?php
/**
 * Writes generated report files into the user's ownCloud Files area under a
 * GBM/ folder (created if missing), overwriting in place. Thin wrapper over
 * OCP\Files\IRootFolder — the only framework-coupled unit of the fiscal feature.
 *
 * getUserFolder($uid) already returns the user's Files root, so the target is
 * "GBM/<name>" — do NOT prepend "files/".
 */

namespace OCA\Gbm\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class FiscalFileService {
	/** @var IRootFolder */
	private $rootFolder;

	public function __construct(IRootFolder $rootFolder) {
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @param array<string,string> $files  filename => CSV content
	 * @return string[] relative paths written (e.g. "GBM/reporte-fiscal-resumen.csv")
	 */
	public function writeFiles(string $uid, array $files): array {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			$folder = $userFolder->get('GBM');
			if (!($folder instanceof Folder)) {
				throw new \RuntimeException('Existe un archivo llamado "GBM" en tus archivos; renombralo o muevelo para poder generar el reporte.');
			}
		} catch (NotFoundException $e) {
			$folder = $userFolder->newFolder('GBM');
		}
		$written = [];
		foreach ($files as $name => $content) {
			try {
				$folder->get($name)->putContent($content);
			} catch (NotFoundException $e) {
				$folder->newFile($name)->putContent($content);
			}
			$written[] = 'GBM/' . $name;
		}
		return $written;
	}
}
