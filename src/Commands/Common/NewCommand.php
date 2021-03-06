<?php namespace Wireshell\Commands\Common;

/*
 * This file is part of the Symfony Installer package.
 *
 * https://github.com/symfony/symfony-installer
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Distill\Distill;
use Distill\Exception\IO\Input\FileCorruptedException;
use Distill\Exception\IO\Input\FileEmptyException;
use Distill\Exception\IO\Output\TargetDirectoryNotWritableException;
use Distill\Strategy\MinimumSize;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Progress\Progress;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Wireshell\Helpers\Installer;
use Wireshell\Helpers\PwConnector;

/**
 * Class NewCommand
 *
 * Downloads ProcessWire in current or in specified folder
 * Methods and approach based on T. Otwell's Laravel installer script: https://github.com/laravel/installer
 * Methods based on P. Urlich's ProcessWire online Installer script: https://github.com/somatonic/PWOnlineInstaller
 *
 * @package Wireshell
 * @author Taylor Otwell
 * @author Fabien Potencier
 * @author Philipp Urlich
 * @author Marcus Herrmann
 * @author Hari KT
 * @author Tabea David
 *
 */
class NewCommand extends Command {

    /**
     * @var Filesystem
     */
    private $fs;
    private $projectName;
    private $projectDir;
    private $version;
    private $compressedFilePath;
    private $requirementsErrors = array();
    private $installer;

    /**
     * @var OutputInterface
     */
    private $output;

    protected function configure() {
        $this
            ->setName('new')
            ->setDescription('Creates a new ProcessWire project')
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory where the new project will be created')
            ->addOption('dbUser', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('dbPass', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('dbName', null, InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('dbHost', null, InputOption::VALUE_REQUIRED, 'Database host, default: `localhost`')
            ->addOption('dbPort', null, InputOption::VALUE_REQUIRED, 'Database port, default: `3306`')
            ->addOption('dbEngine', null, InputOption::VALUE_REQUIRED, 'Database engine, default: `MyISAM`')
            ->addOption('dbCharset', null, InputOption::VALUE_REQUIRED, 'Database characterset, default: `utf8`')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Timezone')
            ->addOption('chmodDir', null, InputOption::VALUE_REQUIRED, 'Directory mode, default `755`')
            ->addOption('chmodFile', null, InputOption::VALUE_REQUIRED, 'File mode, defaults `644`')
            ->addOption('httpHosts', null, InputOption::VALUE_REQUIRED, 'Hostname without `www` part')
            ->addOption('adminUrl', null, InputOption::VALUE_REQUIRED, 'Admin url')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Admin username')
            ->addOption('userpass', null, InputOption::VALUE_REQUIRED, 'Admin password')
            ->addOption('useremail', null, InputOption::VALUE_REQUIRED, 'Admin email address')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Default site profile: `path/to/profile.zip` OR one of `beginner, blank, classic, default, languages`')
            ->addOption('src', null, InputOption::VALUE_REQUIRED, 'Path to pre-downloaded folder, zip or tgz: `path/to/src`')
            ->addOption('sha', null, InputOption::VALUE_REQUIRED, 'Download specific commit')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Disable installation')
            ->addOption('v', null, InputOption::VALUE_NONE, 'verbose');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->fs = new Filesystem();
        $this->projectDir = $this->getDirectory($input);
        $this->projectName = basename($this->projectDir);
        $this->src = $input->getOption('src') ? $this->getAbsolutePath($input->getOption('src')) : null;
        $srcStatus = $this->checkExtractedSrc();
        $v = $input->getOption('v') ? true : false;

        $this->output = $output;
        $profile = $input->getOption('profile');
        $branch = $this->getZipURL($input);
        $logger = new Logger('name');
        $logger->pushHandler(new StreamHandler("php://output"));
        $this->installer = new Installer($logger, $this->projectDir, $v);
        $this->version = PwConnector::getVersion();

        try {
            if (!$this->checkAlreadyDownloaded() && $srcStatus !== 'extracted') {
                if (!$srcStatus) {
                    $this
                        ->checkProjectName()
                        ->download($branch);
                }

                $this->extract();
            }

            $this->cleanUp();
        } catch (Exception $e) {
        }

        try {
            $install = ($input->getOption('no-install')) ? false : true;
            if ($install) {
                $profile = $this->extractProfile($profile);
                $this->installer->getSiteFolder($profile);
                $this->checkProcessWireRequirements();

                $helper = $this->getHelper('question');

                $post = array(
                    'dbName' => '',
                    'dbUser' => '',
                    'dbPass' => '',
                    'dbHost' => 'localhost',
                    'dbPort' => '3306',
                    'dbEngine' => 'MyISAM',
                    'dbCharset' => 'utf8',
                    'timezone' => '',
                    'chmodDir' => '755',
                    'chmodFile' => '644',
                    'httpHosts' => ''
                );

                $dbName = $input->getOption('dbName');
                if (!$dbName) {
                    $question = new Question('Please enter the database name : ', 'dbName');
                    $dbName = $helper->ask($input, $output, $question);
                }
                $post['dbName'] = $dbName;

                $dbUser = $input->getOption('dbUser');
                if (!$dbUser) {
                    $question = new Question('Please enter the database user name : ', 'dbUser');
                    $dbUser = $helper->ask($input, $output, $question);
                }
                $post['dbUser'] = $dbUser;

                $dbPass = $input->getOption('dbPass');
                if (!$dbPass) {
                    $question = new Question('Please enter the database password : ', null);
                    $question->setHidden(true);
                    $question->setHiddenFallback(false);
                    $dbPass = $helper->ask($input, $output, $question);
                }
                $post['dbPass'] = $dbPass;

                $dbHost = $input->getOption('dbHost');
                if ($dbHost) $post['dbHost'] = $dbHost;

                $dbPort = $input->getOption('dbPort');
                if ($dbPort) $post['dbPort'] = $dbPort;

                $dbEngine = $input->getOption('dbEngine');
                if ($dbEngine) $post['dbEngine'] = $dbEngine;

                $dbCharset = $input->getOption('dbCharset');
                if ($dbCharset) $post['dbCharset'] = $dbCharset;

                $bundleNames = array('AcmeDemoBundle', 'AcmeBlogBundle', 'AcmeStoreBundle');
                $timezone = $input->getOption('timezone');
                if (!$timezone) {
                    $question = new Question('Please enter the timezone : ', 'timezone');
                    $question->setAutocompleterValues(timezone_identifiers_list());
                    $timezone = $helper->ask($input, $output, $question);
                }
                $post['timezone'] = $timezone;

                $chmodDir = $input->getOption('chmodDir');
                if ($chmodDir) {
                    $post['chmodDir'] = $chmodDir;
                }

                $chmodFile = $input->getOption('chmodFile');
                if ($chmodFile) {
                    $post['chmodFile'] = $chmodFile;
                }

                $httpHosts = $input->getOption('httpHosts');
                if (!$httpHosts) {
                    $question = new Question('Please enter the hostname without www. Eg: pw.dev : ', 'httpHosts');
                    $httpHosts = $helper->ask($input, $output, $question);
                }
                $post['httpHosts'] = $httpHosts . "\n" . "www." . $httpHosts;

                $accountInfo = array(
                    'admin_name' => 'processwire',
                    'username' => '',
                    'userpass' => '',
                    'userpass_confirm' => '',
                    'useremail' => '',
                    'color' => 'classic',
                );

                $adminUrl = $input->getOption('adminUrl');
                if ($adminUrl) {
                    $accountInfo['admin_name'] = $adminUrl;
                }

                $username = $input->getOption('username');
                if (!$username) {
                    $question = new Question('Please enter admin user name : ', 'username');
                    $username = $helper->ask($input, $output, $question);
                }
                $accountInfo['username'] = $username;

                $userpass = $input->getOption('userpass');
                if (!$userpass) {
                    $question = new Question('Please enter admin password : ', 'password');
                    $question->setHidden(true);
                    $question->setHiddenFallback(false);
                    $userpass = $helper->ask($input, $output, $question);
                }
                $accountInfo['userpass'] = $userpass;
                $accountInfo['userpass_confirm'] = $userpass;

                $useremail = $input->getOption('useremail');
                if (!$useremail) {
                    $question = new Question('Please enter admin email address : ', 'useremail');
                    $useremail = $helper->ask($input, $output, $question);
                }
                $accountInfo['useremail'] = $useremail;
                $this->installProcessWire($post, $accountInfo);
                $this->cleanUpInstallation();
                $this->output->writeln("\n<info>Congratulations, ProcessWire has been successfully installed.</info>");
            }
        } catch (\Exception $e) {
            $this->cleanUp();
            throw $e;
        }
    }

    private function getAbsolutePath($path) {
        return $this->fs->isAbsolutePath($path) ? $path : getcwd() . DIRECTORY_SEPARATOR . $path;
    }

    private function checkExtractedSrc() {
        $status = null;

        if ($this->src && $this->fs->exists($this->src)) {
            switch(filetype($this->src)) {
                case 'dir':
                    // copy extracted src files to projectDir
                    $this->fs->mirror($this->src, $this->projectDir);
                    $status = 'extracted';
                    break;
                case 'file':
                    // check for zip or tgz filetype
                    if (in_array(pathinfo($this->src)['extension'], array('zip', 'tgz'))) {
                        $status = 'compressed';
                    }
                    break;
            }
        }

        return $status;
    }

    private function getDirectory($input) {
        $directory = getcwd();

        if ($d = $input->getArgument('directory')) {
          $directory = rtrim(trim($d), DIRECTORY_SEPARATOR);
        } else {
          if (!$directory) {
              $output->writeln("<error>No such file or directory,\nyou may have to refresh the current directory by executing for example `cd \$PWD`.</error>");
              return;
          }
          chdir(dirname($directory));
        }

        return $this->getAbsolutePath($directory);
    }

    private function getZipURL($input) {
        if ($input->getOption('sha')) {
            $targetBranch = $input->getOption('sha');
        } else {
            $targetBranch = PwConnector::BRANCH_MASTER;
        }

        $branch = str_replace('{branch}', $targetBranch, PwConnector::zipURL);
        $check = str_replace('{branch}', $targetBranch, PwConnector::versionURL);

        try {
            $ch = curl_init($check);
        } catch (Exception $e) {
            $messages = array(
                'Curl request failed.',
                'Please check whether the php curl extension is enabled, uncomment the following line in your php.ini:',
                '`;extension=php_curl.dll` and restart the server. Check your phpinfo() to see whether curl has been properly enabled or not.'
            );
            throw new \RuntimeException(implode("\n", $messages));
        }

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $retcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string)curl_error($ch);
        curl_close($ch);

        if ((int)$retcode !== 200) {
            throw new \RuntimeException(
                "Error loading sha `$targetBranch`, curl request failed (status code: $retcode, url: $check).\ncURL error: $curlError"
            );
        }

        return $branch;
    }

    /**
     * Checks whether it's safe to create a new project for the given name in the
     * given directory.
     *
     * @return NewCommand
     *
     * @throws \RuntimeException if a project with the same does already exist
     */
    private function checkProjectName() {
        if (is_dir($this->projectDir) && !$this->isEmptyDirectory($this->projectDir)) {
            throw new \RuntimeException(sprintf(
                "There is already a '%s' project in this directory (%s).\n" .
                "Change your project name or create it in another directory.",
                $this->projectName, $this->projectDir
            ));
        }

        return $this;
    }

    private function checkAlreadyDownloaded() {
        return file_exists($this->projectDir . '/site/install') ? true : false;
    }

    /**
     * Chooses the best compressed file format to download (ZIP or TGZ) depending upon the
     * available operating system uncompressing commands and the enabled PHP extensions
     * and it downloads the file.
     *
     * @return NewCommand
     *
     * @throws \RuntimeException if the ProcessWire archive could not be downloaded
     */
    private function download($branch) {
        $this->output->writeln("\n Downloading ProcessWire...");

        $distill = new Distill();
        $pwArchiveFile = $distill
            ->getChooser()
            ->setStrategy(new MinimumSize())
            ->addFile($branch)
            ->getPreferredFile();

        /** @var ProgressBar|null $progressBar */
        $progressBar = null;
        $downloadCallback = function ($size, $downloaded, $client, $request, Response $response) use (&$progressBar) {
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

                $progressBar = new ProgressBar($this->output, $size);
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
        $this->compressedFilePath = $this->projectDir . DIRECTORY_SEPARATOR . '.' . uniqid(time()) . DIRECTORY_SEPARATOR . 'pw.' . pathinfo($pwArchiveFile,
                PATHINFO_EXTENSION);

        try {
            $response = $client->get($pwArchiveFile);
        } catch (ClientException $e) {
            if ($e->getCode() === 403 || $e->getCode() === 404) {
                throw new \RuntimeException(sprintf(
                    "The selected version (%s) cannot be installed because it does not exist.\n" .
                    "Try the special \"latest\" version to install the latest stable ProcessWire release:\n" .
                    '%s %s %s latest',
                    $this->version,
                    $_SERVER['PHP_SELF'],
                    $this->getName(),
                    $this->projectDir
                ));
            } else {
                throw new \RuntimeException(sprintf(
                    "The selected version (%s) couldn't be downloaded because of the following error:\n%s",
                    $this->version,
                    $e->getMessage()
                ));
            }
        }

        $this->fs->dumpFile($this->compressedFilePath, $response->getBody());

        if (null !== $progressBar) {
            $progressBar->finish();
            $this->output->writeln("\n");
        }

        return $this;
    }

    /**
     * Extracts the compressed Symfony file (ZIP or TGZ) using the
     * native operating system commands if available or PHP code otherwise.
     *
     * @return NewCommand
     *
     * @throws \RuntimeException if the downloaded archive could not be extracted
     */
    private function extract() {
        $this->output->writeln(" Preparing project...\n");
        $cfp = $this->src ? $this->src : $this->compressedFilePath;

        try {
            $distill = new Distill();
            $extractionSucceeded = $distill->extractWithoutRootDirectory($cfp, $this->projectDir);
        } catch (FileCorruptedException $e) {
            throw new \RuntimeException(sprintf(
                "ProcessWire can't be installed because the downloaded package is corrupted.\n" .
                "To solve this issue, try installing ProcessWire again.\n%s",
                $this->getExecutedCommand()
            ));
        } catch (FileEmptyException $e) {
            throw new \RuntimeException(sprintf(
                "ProcessWire can't be installed because the downloaded package is empty.\n" .
                "To solve this issue, try installing ProcessWire again.\n%s",
                $this->getExecutedCommand()
            ));
        } catch (TargetDirectoryNotWritableException $e) {
            throw new \RuntimeException(sprintf(
                "ProcessWire can't be installed because the installer doesn't have enough\n" .
                "permissions to uncompress and rename the package contents.\n" .
                "To solve this issue, check the permissions of the %s directory and\n" .
                "try installing ProcessWire again.\n%s",
                $this->projectDir, $this->getExecutedCommand()
            ));
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf(
                "ProcessWire can't be installed because the downloaded package is corrupted\n" .
                "or because the installer doesn't have enough permissions to uncompress and\n" .
                "rename the package contents.\n" .
                "To solve this issue, check the permissions of the %s directory and\n" .
                "try installing ProcessWire again.\n%s",
                $this->projectDir, $this->getExecutedCommand()
            ));
        }

        if (!$extractionSucceeded) {
            throw new \RuntimeException(
                "ProcessWire can't be installed because the downloaded package is corrupted\n" .
                "or because the uncompress commands of your operating system didn't work."
            );
        }

        return $this;
    }

    /**
     * Removes all the temporary files and directories created to
     * download the project and removes ProcessWire-related files that don't make
     * sense in a proprietary project.
     *
     * @return NewCommand
     */
    private function cleanUp() {
        $this->fs->remove(dirname($this->compressedFilePath));

        try {
            $licenseFile = array($this->projectDir . '/LICENSE');
            $upgradeFiles = glob($this->projectDir . '/UPGRADE*.md');
            $changelogFiles = glob($this->projectDir . '/CHANGELOG*.md');

            $filesToRemove = array_merge($licenseFile, $upgradeFiles, $changelogFiles);
            $this->fs->remove($filesToRemove);

            $readmeContents = sprintf("%s\n%s\n\nA ProcessWire project created on %s.\n", $this->projectName,
                str_repeat('=', strlen($this->projectName)), date('F j, Y, g:i a'));
            $this->fs->dumpFile($this->projectDir . '/README.md', $readmeContents);
        } catch (\Exception $e) {
            // don't throw an exception in case any of the ProcessWire-related files cannot
            // be removed, because this is just an enhancement, not something mandatory
            // for the project
        }

        return $this;
    }

    /**
     * Removes all the temporary files and directories created to
     * install the project and removes ProcessWire-related files that don't make
     * sense in a running project.
     *
     * @return NewCommand
     */
    private function cleanUpInstallation() {
        $this->fs->remove(dirname($this->compressedFilePath));

        try {
            $siteDirs = glob($this->projectDir . '/site-*');
            $installDir = array($this->projectDir . '/site/install');
            $installFile = array($this->projectDir . '/install.php');

            $this->fs->remove(array_merge($siteDirs, $installDir, $installFile));
            if ($this->v) $this->output->writeln("Remove ProcessWire-related files that don't make sense in a running project.");
        } catch (\Exception $e) {
            // don't throw an exception in case any of the ProcessWire-related files cannot
            // be removed, because this is just an enhancement, not something mandatory
            // for the project
        }

        return $this;
    }

    /**
     * Checks if environment meets ProcessWire requirements
     *
     * @return OneclickCommand
     */
    private function checkProcessWireRequirements() {
        $this->installer->compatibilityCheck();

        return $this;
    }

    private function installProcessWire($post, $accountInfo) {
        $this->installer->dbSaveConfig($post, $accountInfo);

        return $this;
    }

    /**
     * Utility method to show the number of bytes in a readable format.
     *
     * @param int $bytes The number of bytes to format
     *
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
     * Formats the error message contained in the given Requirement item
     * using the optional line length provided.
     *
     * @param \Requirement $requirement The ProcessWire requirements
     * @param int $lineSize The maximum line length
     *
     * @return string
     */
    private function getErrorMessage(\Requirement $requirement, $lineSize = 70) {
        if ($requirement->isFulfilled()) {
            return;
        }

        $errorMessage = wordwrap($requirement->getTestMessage(), $lineSize - 3, PHP_EOL . '   ') . PHP_EOL;
        $errorMessage .= '   > ' . wordwrap($requirement->getHelpText(), $lineSize - 5, PHP_EOL . '   > ') . PHP_EOL;

        return $errorMessage;
    }

    /**
     * Returns the executed command.
     *
     * @return string
     */
    private function getExecutedCommand() {
        $version = '';
        if ('latest' !== $this->version) $version = $this->version;

        $pathDirs = explode(PATH_SEPARATOR, $_SERVER['PATH']);
        $executedCommand = $_SERVER['PHP_SELF'];
        $executedCommandDir = dirname($executedCommand);

        if (in_array($executedCommandDir, $pathDirs)) $executedCommand = basename($executedCommand);

        return sprintf('%s new %s %s', $executedCommand, $this->projectName, $version);
    }

    /**
     * Checks whether the given directory is empty or not.
     *
     * @param  string $dir the path of the directory to check
     * @return bool
     */
    private function isEmptyDirectory($dir) {
        // glob() cannot be used because it doesn't take into account hidden files
        // scandir() returns '.'  and '..'  for an empty dir
        return 2 === count(scandir($dir . '/'));
    }

    private function extractProfile($profile) {
        if (!$profile || !preg_match('/^.*\.zip$/', $profile)) return $profile;

        $this->output->writeln(" Extracting profile...\n");

        try {
            $distill = new Distill();
            $extractPath = $this->projectDir . DIRECTORY_SEPARATOR . '.' . uniqid(time()) . DIRECTORY_SEPARATOR . 'pwprofile';
            $extractionSucceeded = $distill->extractWithoutRootDirectory($profile, $extractPath);

            foreach (new \DirectoryIterator($extractPath) as $fileInfo) {
                if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                  $dir = $fileInfo->getFilename();
                  break;
                }
            }

            if ($extractionSucceeded) {
                try {
                    $this->fs->mirror($extractPath, $this->projectDir . '/');
                } catch (\Exception $e) {
                }
                // cleanup
                $this->fs->remove($extractPath);

                try {
                    $process = new Process("cd $this->projectDir;");
                    $process->run(function ($type, $buffer) {
                        if (Process::ERR === $type) {
                            echo ' ' . $buffer;
                        } else {
                            echo ' ' . $buffer;
                        }
                    });
                } catch (\Exception $e) {
                }
            }
        } catch (FileCorruptedException $e) {
            throw new \RuntimeException(
                "The profile can't be installed because the downloaded package is corrupted.\n"
            );
        } catch (FileEmptyException $e) {
            throw new \RuntimeException(
                "The profile can't be installed because the downloaded package is empty.\n"
            );
        } catch (TargetDirectoryNotWritableException $e) {
            throw new \RuntimeException(
                "The profile can't be installed because the installer doesn't have enough\n" .
                "permissions to uncompress and rename the package contents.\n"
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "The profile can't be installed because the downloaded package is corrupted\n" .
                "or because the installer doesn't have enough permissions to uncompress and\n" .
                "rename the package contents.\n" .
                $e->getMessage()
            );
        }

        if (!$extractionSucceeded) {
            throw new \RuntimeException(
                "The profile can't be installed because the downloaded package is corrupted\n" .
                "or because the uncompress commands of your operating system didn't work."
            );
        }

        return $dir;
    }
}
