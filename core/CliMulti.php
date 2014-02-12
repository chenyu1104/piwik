<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik;

use Piwik\CliMulti\Process;
use Piwik\CliMulti\Output;

/**
 * Class CliMulti.
 */
class CliMulti {

    /**
     * If set to true or false it will overwrite whether async is supported or not.
     *
     * @var null|bool
     */
    public $supportsAsync = null;

    /**
     * @var \Piwik\CliMulti\Process[]
     */
    private $processes = array();

    /**
     * @var \Piwik\CliMulti\Output[]
     */
    private $outputs = array();

    private $acceptInvalidSSLCertificate = false;

    public function __construct()
    {
        $this->supportsAsync = $this->supportsAsync();
    }

    /**
     * It will request all given URLs in parallel (async) using the CLI and wait until all requests are finished.
     * If multi cli is not supported (eg windows) it will initiate an HTTP request instead (not async).
     *
     * @param string[]  $piwikUrls   An array of urls, for instance:
     *                               array('http://www.example.com/piwik?module=API...')
     * @return array The response of each URL in the same order as the URLs. The array can contain null values in case
     *               there was a problem with a request, for instance if the process died unexpected.
     */
    public function request(array $piwikUrls)
    {
        $this->start($piwikUrls);

        do {
            usleep(100000); // 100 * 1000 = 100ms
        } while (!$this->hasFinished());

        $results = $this->getResponse($piwikUrls);
        $this->cleanup();

        self::cleanupNotRemovedFiles();

        return $results;
    }

    /**
     * Ok, this sounds weird. Why should we care about ssl certificates when we are in CLI mode? It is needed for
     * our simple fallback mode for Windows where we initiate HTTP requests instead of CLI.
     * @param $acceptInvalidSSLCertificate
     */
    public function setAcceptInvalidSSLCertificate($acceptInvalidSSLCertificate)
    {
        $this->acceptInvalidSSLCertificate = $acceptInvalidSSLCertificate;
    }

    private function start($piwikUrls)
    {
        foreach ($piwikUrls as $index => $url) {
            $cmdId  = $this->generateCommandId($url) . $index;
            $output = new Output($cmdId);

            if ($this->supportsAsync) {
                $this->executeAsyncCli($url, $output, $cmdId);
            } else {
                $this->executeNotAsyncHttp($url, $output);
            }

            $this->outputs[] = $output;
        }
    }

    private function buildCommand($query, $outputFile)
    {
        $bin = $this->findPhpBinary();

        return sprintf('%s %s/console climulti:request %s > %s 2>&1 &',
                       $bin, PIWIK_INCLUDE_PATH, escapeshellarg($query), $outputFile);
    }

    private function getResponse()
    {
        $response = array();

        foreach ($this->outputs as $output) {
            $response[] = $output->get();
        }

        return $response;
    }

    private function hasFinished()
    {
        foreach ($this->processes as $index => $process) {
            $hasStarted = $process->hasStarted();

            if (!$hasStarted && 8 <= $process->getSecondsSinceCreation()) {
                // if process was created more than 8 seconds ago but still not started there must be something wrong.
                // ==> declare the process as finished
                $process->finishProcess();
                continue;

            } elseif (!$hasStarted) {
                return false;
            }

            if ($process->isRunning()) {
                return false;
            }

            if ($process->hasFinished()) {
                // prevent from checking this process over and over again
                unset($this->processes[$index]);
            }
        }

        return true;
    }

    private function generateCommandId($command)
    {
        return substr(Common::hash($command . microtime(true) . rand(0, 99999)), 0, 100);
    }

    /**
     * What is missing under windows? Detection whether a process is still running in Process::isProcessStillRunning
     * and how to send a process into background in start()
     */
    private function supportsAsync()
    {
        return !SettingsServer::isWindows() && Process::isSupported() && $this->findPhpBinary();
    }

    private function cleanup()
    {
        foreach ($this->processes as $pid) {
            $pid->finishProcess();
        }

        foreach ($this->outputs as $output) {
            $output->destroy();
        }

        $this->processes = array();
        $this->outputs   = array();
    }

    /**
     * Remove files older than one week. They should be cleaned up automatically after each request but for whatever
     * reason there can be always some files left.
     */
    public static function cleanupNotRemovedFiles()
    {
        $timeOneWeekAgo = strtotime('-1 week');

        foreach (_glob(self::getTmpPath() . '/*') as $file) {
            $timeLastModified = filemtime($file);

            if ($timeOneWeekAgo > $timeLastModified) {
                unlink($file);
            }
        }
    }

    public static function getTmpPath()
    {
        $dir = PIWIK_INCLUDE_PATH . '/tmp/climulti';
        return SettingsPiwik::rewriteTmpPathWithHostname($dir);
    }

    private function findPhpBinary()
    {
        if (defined('PHP_BINARY')) {
            return PHP_BINARY;
        }

        $bin = shell_exec('which php');

        if (empty($bin)) {
            $bin = shell_exec('which php5');
        }

        if (empty($bin) && defined('PHP_BINDIR') && Common::isPhpCliMode() && !empty($_SERVER['_']) && is_executable($_SERVER['_'])) {
            if (0 === strpos($_SERVER['_'], PHP_BINDIR)) {
                $bin = $_SERVER['_'];
            }
        }

        if (!empty($bin)) {
            return trim($bin);
        }
    }

    private function executeAsyncCli($url, Output $output, $cmdId)
    {
        $this->processes[] = new Process($cmdId);

        $url     = $this->appendTestmodeParamToUrlIfNeeded($url);
        $query   = Url::getQueryFromUrl($url, array('pid' => $cmdId));
        $command = $this->buildCommand($query, $output->getPathToFile());

        shell_exec($command);
    }

    private function executeNotAsyncHttp($url, Output $output)
    {
        try {
            $response = Http::sendHttpRequestBy('curl', $url, $timeout = 0, $userAgent = null, $destinationPath = null, $file = null, $followDepth = 0, $acceptLanguage = false, $this->acceptInvalidSSLCertificate);
            $output->write($response);
        } catch (\Exception $e) {
            $message = "Got invalid response from API request: $url. ";

            if (empty($response)) {
                $message .= "The response was empty. This usually means a server error. This solution to this error is generally to increase the value of 'memory_limit' in your php.ini file. Please check your Web server Error Log file for more details.";
            } else {
                $message .= "Response was '" . $e->getMessage() . "'";
            }

            $output->write($message);
        }
    }

    private function appendTestmodeParamToUrlIfNeeded($url)
    {
        $isTestMode = $url && false !== strpos($url, 'tests/PHPUnit/proxy');

        if ($isTestMode && false === strpos($url, '?')) {
            $url .= "?testmode=1";
        } elseif ($isTestMode) {
            $url .= "&testmode=1";
        }

        return $url;
    }
}