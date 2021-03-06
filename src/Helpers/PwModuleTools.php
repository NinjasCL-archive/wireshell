<?php namespace Wireshell\Helpers;

use ProcessWire\WireHttp;
use Distill\Distill;
use Distill\Exception\IO\Input\FileCorruptedException;
use Distill\Exception\IO\Input\FileEmptyException;
use Distill\Exception\IO\Output\TargetDirectoryNotWritableException;
use Distill\Strategy\MinimumSize;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Progress\Progress;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * PwModuleTools
 *
 * Reusable methods for module generation, download, activation
 *
 * @package Wireshell
 * @author Tabea David <td@kf-interactive.com>
 * @author Marcus Herrmann
 */
class PwModuleTools extends PwConnector {
   /**
    * @var Filesystem
    */
    private $fs;

    const timeout = 4.5;

    /**
     * check if a module already exists
     *
     * @param string $module
     * @return boolean
     */
    public function checkIfModuleExists($module)
    {
        $moduleDir = \ProcessWire\wire('config')->paths->siteModules . $module;
        if (\ProcessWire\wire('modules')->getModule($module, array('noPermissionCheck' => true, 'noInit' => true))) {
            $return = true;
        }

        if (is_dir($moduleDir) && !$this->isEmptyDirectory($moduleDir)) {
            $return = true;
        }

        return (isset($return)) ? $return : false;
    }

    /**
     * Checks whether the given directory is empty or not.
     *
     * @param  string $dir the path of the directory to check
     * @return bool
     */
    public function isEmptyDirectory($dir)
    {
        // glob() cannot be used because it doesn't take into account hidden files
        // scandir() returns '.'  and '..'  for an empty dir
        return 2 === count(scandir($dir . '/'));
    }

    /**
     * Check all site modules for newer versions from the directory
     *
     * @param bool $onlyNew Only return array of modules with new versions available
     * @param OutputInterface $output
     * @return array of array(
     *  'ModuleName' => array(
     *    'title' => 'Module Title',
     *    'local' => '1.2.3', // current installed version
     *     'remote' => '1.2.4', // directory version available, or boolean false if not found in directory
     *     'new' => true|false, // true if newer version available, false if not
     *     'requiresVersions' => array('ModuleName' => array('>', '1.2.3')), // module requirements
     *   )
     * )
     * @throws WireException
     *
     */
    public function getModuleVersions($onlyNew = false, $output) {
        $url = \ProcessWire\wire('config')->moduleServiceURL .
            "?apikey=" . \ProcessWire\wire('config')->moduleServiceKey .
            "&limit=100" .
            "&field=module_version,version,requires_versions" .
            "&class_name=";

        $names = array();
        $versions = array();

        foreach (\ProcessWire\wire('modules') as $module) {
            $name = $module->className();
            $info = \ProcessWire\wire('modules')->getModuleInfoVerbose($name);
            if ($info['core']) continue;
            $names[] = $name;
            $versions[$name] = array(
                'title' => $info['title'],
                'local' => \ProcessWire\wire('modules')->formatVersion($info['version']),
                'remote' => false,
                'new' => 0,
                'requiresVersions' => $info['requiresVersions']
            );
        }

        if (!count($names)) return array();
        $url .= implode(',', $names);

        $http = new WireHttp();
        $http->setTimeout(self::timeout);
        $data = $http->getJSON($url);

        if (!is_array($data)) {
            $error = $http->getError();
            if (!$error) $error = 'Error retrieving modules directory data';
            $output->writeln("<error>$error</error>");
            return array();
        }

        foreach ($data['items'] as $item) {
            $name = $item['class_name'];
            $versions[$name]['remote'] = $item['module_version'];
            $new = version_compare($versions[$name]['remote'], $versions[$name]['local']);
            $versions[$name]['new'] = $new;
            if ($new <= 0) {
                // local is up-to-date or newer than remote
                if ($onlyNew) unset($versions[$name]);
            } else {
                // remote is newer than local
                $versions[$name]['requiresVersions'] = $item['requires_versions'];
            }
        }

        if ($onlyNew) foreach($versions as $name => $data) {
            if($data['remote'] === false) unset($versions[$name]);
        }

        return $versions;
    }

    /**
     * Check all site modules for newer versions from the directory
     *
     * @param bool $onlyNew Only return array of modules with new versions available
     * @param OutputInterface $output
     * @return array of array(
     *  'ModuleName' => array(
     *    'title' => 'Module Title',
     *    'local' => '1.2.3', // current installed version
     *     'remote' => '1.2.4', // directory version available, or boolean false if not found in directory
     *     'new' => true|false, // true if newer version available, false if not
     *     'requiresVersions' => array('ModuleName' => array('>', '1.2.3')), // module requirements
     *   )
     * )
     * @throws WireException
     *
     */
    public function getModuleVersion($onlyNew = false, $output, $module) {
        // get current module data
        $info = \ProcessWire\wire('modules')->getModuleInfoVerbose($module);
        $versions = array(
            'title' => $info['title'],
            'local' => \ProcessWire\wire('modules')->formatVersion($info['version']),
            'remote' => false,
            'new' => 0,
            'requiresVersions' => $info['requiresVersions']
        );

        // get latest module data
        $url = trim(\ProcessWire\wire('config')->moduleServiceURL, '/') . "/$module/?apikey=" . \ProcessWire\wire('sanitizer')->name(\ProcessWire\wire('config')->moduleServiceKey);
        $http = new WireHttp();
        $data = $http->getJSON($url);

        if (!$data || !is_array($data)) {
            $output->writeln("<error>Error retrieving data from web service URL - {$http->getError()}</error>");
            return array();
        }

        if ($data['status'] !== 'success') {
            $error = \ProcessWire\wire('sanitizer')->entities($data['error']);
            $output->writeln("<error>Error reported by web service: $error</error>");
            return array();
        }

        // yeah, received data sucessfully!
        // get versions and compare them
        $versions['remote'] = $data['module_version'];
        $new = version_compare($versions['remote'], $versions['local']);
        $versions['new'] = $new;
        $versions['download_url'] = $data['project_url'] . '/archive/master.zip';

        // local is up-to-date or newer than remote
        if ($new <= 0) {
            if ($onlyNew) $versions = array();
        } else {
            // remote is newer than local
            $versions['requiresVersions'] = $data['requires_versions'];
        }

        if ($onlyNew && !$versions['remote']) $versions = array();

        return $versions;
    }

    /**
     * Removes all the temporary files and directories created to
     * download the project and removes ProcessWire-related files that don't make
     * sense in a proprietary project.
     *
     * @param string $module
     * @param OutputInterface $output
     * @return NewCommand
     */
    public function cleanUpTmp($module, $output) {
        $fs = new Filesystem();
        $fs->remove(dirname($this->compressedFilePath));
        $output->writeln("<info> Module {$module} downloaded successfully.</info>\n");
    }

    /**
     * Extracts the compressed Symfony file (ZIP or TGZ) using the
     * native operating system commands if available or PHP code otherwise.
     *
     * @param string $module
     * @param OutputInterface $output
     * @return NewCommand
     *
     * @throws \RuntimeException if the downloaded archive could not be extracted
     */
    public function extractModule($module, $output) {
        $output->writeln(" Preparing module...\n");

        try {
            $distill = new Distill();
            $extractionSucceeded = $distill->extractWithoutRootDirectory($this->compressedFilePath,
                \ProcessWire\wire('config')->paths->siteModules . $module);
            $dir = \ProcessWire\wire('config')->paths->siteModules . $module;
            if (is_dir($dir)) {
                chmod($dir, 0755);
            }
        } catch (FileCorruptedException $e) {
            throw new \RuntimeException(
                "This module can't be downloaded because the downloaded package is corrupted.\n" .
                "To solve this issue, try installing the module again.\n"
            );
        } catch (FileEmptyException $e) {
            throw new \RuntimeException(
                "This module can't be downloaded because the downloaded package is empty.\n" .
                "To solve this issue, try installing the module again.\n"
            );
        } catch (TargetDirectoryNotWritableException $e) {
            throw new \RuntimeException(sprintf(
                "This module can't be downloaded because the installer doesn't have enough\n" .
                "permissions to uncompress and rename the package contents.\n" .
                "To solve this issue, check the permissions of the %s directory and\n" .
                "try installing this module again.\n",
                getcwd()
            ));
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf(
                "This module can't be downloaded because the downloaded package is corrupted\n" .
                "or because the installer doesn't have enough permissions to uncompress and\n" .
                "rename the package contents.\n" .
                "To solve this issue, check the permissions of the %s directory and\n" .
                "try installing this module again.\n",
                getcwd()
            ));
        }

        if (!$extractionSucceeded) {
            throw new \RuntimeException(
                "This module can't be downloaded because the downloaded package is corrupted\n" .
                "or because the uncompress commands of your operating system didn't work."
            );
        }

        return $this;
    }

    /**
     * Utility method to show the number of bytes in a readable format.
     *
     * @param int $bytes The number of bytes to format
     * @return string The human readable string of bytes (e.g. 4.32MB)
     */
    private function formatSize($bytes) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = $bytes ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return number_format($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Chooses the best compressed file format to download (ZIP or TGZ) depending upon the
     * available operating system uncompressing commands and the enabled PHP extensions
     * and it downloads the file.
     *
     * @param string $url
     * @param string $module
     * @param OutputInterface $output
     * @return NewCommand
     *
     * @throws \RuntimeException if the ProcessWire archive could not be downloaded
     */
    public function downloadModule($url, $module, $output) {
        $output->writeln(" Downloading module $module...");

        $distill = new Distill();
        $pwArchiveFile = $distill
            ->getChooser()
            ->setStrategy(new MinimumSize())
            ->addFile($url)
            ->getPreferredFile();

        /** @var ProgressBar|null $progressBar */
        $progressBar = null;
        $downloadCallback = function ($size, $downloaded, $client, $request, Response $response) use (&$progressBar, &$output) {
            // Don't initialize the progress bar for redirects as the size is much smaller
            if ($response->getStatusCode() >= 300) {
                return;
            }

            if (null === $progressBar) {
                ProgressBar::setPlaceholderFormatterDefinition('max', function (ProgressBar $bar) {
                    return $this->formatSize($bar->getMaxSteps());
                });
                ProgressBar::setPlaceholderFormatterDefinition('current', function (ProgressBar $bar) {
                    return str_pad($this->formatSize($bar->getStep()), 11, ' ', STR_PAD_LEFT);
                });

                $progressBar = new ProgressBar($output, $size);
                $progressBar->setFormat('%current%/%max% %bar%  %percent:3s%%');
                $progressBar->setRedrawFrequency(max(1, floor($size / 1000)));
                $progressBar->setBarWidth(60);

                if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $progressBar->setEmptyBarCharacter('░'); // light shade character \u2591
                    $progressBar->setProgressCharacter('');
                    $progressBar->setBarCharacter('▓'); // dark shade character \u2593
                }

                $progressBar->start();
            }

            $progressBar->setProgress($downloaded);
        };

        $client = new Client();
        $client->getEmitter()->attach(new Progress(null, $downloadCallback));

        // store the file in a temporary hidden directory with a random name
        $this->compressedFilePath = \ProcessWire\wire('config')->paths->siteModules . '.' . uniqid(time()) . DIRECTORY_SEPARATOR . $module . '.' . pathinfo($pwArchiveFile,
                PATHINFO_EXTENSION);

        try {
            $response = $client->get($pwArchiveFile);
        } catch (ClientException $e) {
            if ($e->getCode() === 403 || $e->getCode() === 404) {
                throw new \RuntimeException(
                    "The selected module $module cannot be downloaded because it does not exist.\n"
                );
            } else {
                throw new \RuntimeException(sprintf(
                    "The selected module (%s) couldn't be downloaded because of the following error:\n%s",
                    $module,
                    $e->getMessage()
                ));
            }
        }

        $fs = new Filesystem();
        $fs->dumpFile($this->compressedFilePath, $response->getBody());

        if (null !== $progressBar) {
            $progressBar->finish();
            $output->writeln("\n");
        }

        return $this;
    }

}
