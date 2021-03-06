<?php

namespace djeux\queue\controllers;
use djeux\queue\BaseQueueManager;
use djeux\queue\WorkerConfiguration;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use yii\caching\Cache;
use yii\console\Controller;
use yii\helpers\Console;
use Yii;

/**
 * Handler for the queue processes
 */
class QueueController extends Controller
{
    /**
     * @var BaseQueueManager
     */
    private $queueApplicationComponent;

    /**
     * @var string
     */
    private $commandPath;

    /**
     * @var WorkerConfiguration
     */
    private $configuration;

    /**
     * @var string|Cache
     */
    private $cache;

    /**
     * Write memory statistics about running workers to redis
     *
     * @var bool
     */
    public $stats = false;

    public function init()
    {
        parent::init();
        $this->commandPath = Yii::$app->basePath;

        if (!$this->cache instanceof Cache) {
            $this->cache = $this->getQueueComponent()->getCache();
        }

        if ($this->stats) { // If user decided to write statistics to memory, check that redis is available
            Yii::$app->get('redis');
        }

        $this->configuration = $this->getQueueComponent()->getWorkerConfiguration();
    }

    /**
     * @param string $actionID
     * @return array
     */
    public function options($actionID)
    {
        return ['stats'];
    }

    /**
     * @return array
     */
    public function optionAliases()
    {
        return ['s' => 'stats'];
    }

    /**
     * @return BaseQueueManager
     */
    public function getQueueComponent()
    {
        if (!$this->queueApplicationComponent) {
            $this->queueApplicationComponent = Yii::$app->get('queue');
        }

        return $this->queueApplicationComponent;
    }

    /**
     * @param \yii\base\Action $action
     * @return bool
     */
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($action->id !== 'restore' && $this->isStopped()) {
            sleep(10); // check only every 10 secs
            $this->stdout("Queue is stopped\n", Console::FG_RED);
            return false;
        }

        return true;
    }

    /**
     * Run a listener for each tube
     *
     * @return integer
     * @throws \yii\base\InvalidConfigException
     */
    public function actionManager()
    {
        // Fetch tubes that we should listen to
        $listenTubes = $this->configuration->listen;

        $runningProcesses = [];

        try {
            $this->stdout("Listening jobs from: " . implode(', ', $listenTubes) ."\n", Console::FG_GREEN);

            // Traverse all tubes and start a process for each one
            foreach ($listenTubes as $tube) {
                $command = $this->createCommand($tube);
                $process = new Process($command, $this->commandPath);
                $this->stdout("Running worker for tube: {$tube}\n");
                $process->start([$this, 'handleOutput']);

                // Add the process to our stack
                $runningProcesses[$tube] = $process;
            }
        } catch (ProcessFailedException $e) {
            Yii::error($e->getMessage(), 'queue/process');
            $this->stderr($e->getMessage(), Console::FG_RED);
        }

        // Reset the array pointer so that we start from the beginning
        reset($runningProcesses);

        $lastTube = array_pop(array_keys($runningProcesses));

        while (list($name, $runningProcess) = each($runningProcesses)) {
            /* @var $runningProcess Process */

            if (!$runningProcess->isRunning()) {
                // If the listener is expected to stop, we do not restart stopped processes
                // Stopping the process itself is being taken care of in the process itself
                if ($this->stopProcesses()) {
                    unset($runningProcesses[$name]);
                } else {
                    $runningProcesses[$name] = $runningProcess->restart([$this, 'handleOutput']);
                    $this->stdout("Restarting worker for: {$name}");
                }
            }

            // If we've checked the last process, move the pointer to beginning of our stack
            if ($name == $lastTube) {
                reset($runningProcesses);
            }

            usleep(10000); // there's no need to monitor processes every microsecond.
        }

        // If we only needed to restart, remove the key from cache, so that supervisor will restart the listener
        if ($this->shouldRestart()) {
            $this->cache->delete($this->configuration->restartCacheKey);
        }

        return self::EXIT_CODE_NORMAL;
    }

    /**
     * @return bool
     */
    public function stopProcesses()
    {
        return $this->isStopped() || $this->shouldRestart();
    }

    /**
     * @param string $type
     * @param string $line
     */
    public function handleOutput($type, $line)
    {
        if ($type === Process::ERR)
            $this->stderr($line);
        else
            $this->stdout($line);
    }

    /**
     * Create a command to run which listen to a tube
     *
     * @param string $tubeName
     * @return string
     */
    private function createCommand($tubeName)
    {
        $command = 'exec ' . PHP_BINARY . ' ' . Yii::$app->request->getScriptFile() . ' queue/work ' . $tubeName;
        if ($this->stats) {
            $command .= ' --stats=1';
        }

        return $command;
    }

    /**
     * Listen to a tube
     *
     * @param string $tubeName
     * @return int
     * @internal param string $queue
     */
    public function actionWork($tubeName)
    {
        $queue = $this->getQueueComponent();

        $run = true;
        $startTime = time();
        $failBox = [];

        while ($run) {
            // Pop a whelp from the eggs
            $whelp = $queue->pop($tubeName);

            // If a whelp is pending, Handle IT!
            if (null !== $whelp) {
                $whelpId = $whelp->getId();
                if (isset($failBox[$whelpId])) {
                    $countOfFails = $failBox[$whelpId];
                } else {
                    $failBox[$whelpId] = $countOfFails = 0;
                }

                try {
                    $whelp->handle();

                    if ($queue->deleteByDefault) {
                        $whelp->delete();
                    }
                } catch (\Exception $e) {
                    $countOfFails++;
                    $failBox[$whelpId] = $countOfFails;
                    $this->stderr($e->getMessage() . "\n");
                    Yii::error($e->getMessage(), 'queue/' . $tubeName);

                    if ($countOfFails > 2) {
                        $this->stdout("Job $whelpId buried\n");
                        $whelp->bury();
                        unset($failBox[$whelpId]);
                    } else {
                        $whelp->release();
                    }
                }
            }

            if ($this->stopProcesses()) {
                $run = false; // stop the process if listener is set to stop
            }

            usleep(10000);

            if ($this->stats) {
                $this->memoryStats($tubeName, $startTime);
            }
        }

        $this->stdout("Terminating on request");
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Restart the worker after all currently running jobs finish
     *
     * @return integer
     */
    public function actionRestart()
    {
        if ($this->cache->set($this->configuration->restartCacheKey, time())) {
            $this->stdout("Restart command issued\n", Console::FG_GREEN);
            return self::EXIT_CODE_NORMAL;
        }

        $this->stderr("Unable to order restart\n", Console::FG_RED);
        return self::EXIT_CODE_ERROR;
    }

    /**
     * Stop the queue workers from processing further jobs
     *
     * @return int
     */
    public function actionStop()
    {
        if ($this->cache->set($this->configuration->stopCacheKey, time())) {
            $this->stdout("Stopped\n", Console::FG_GREEN);
            return self::EXIT_CODE_NORMAL;
        }

        $this->stderr("Unable to order processes to stop\n", Console::FG_RED);
        return self::EXIT_CODE_ERROR;
    }

    /**
     * @param string $tubeName
     * @param integer $startTime
     */
    protected function memoryStats($tubeName, &$startTime)
    {
        // Write in interval of at least 3 seconds
        if (time() - $startTime > 3) {
            $redis = Yii::$app->get('redis');
            $key = "queue:stats:{$tubeName}";
            $data = time() . ':' . memory_get_usage(true);
            $redis->lpush($key, $data);
            $redis->ltrim($key, 0, 99);
            $startTime = time();
        }
    }

    /**
     * @return boolean
     */
    public function shouldRestart()
    {
        if ($this->cache->exists($this->configuration->restartCacheKey)) {
            $this->stdout("Restarting worker\n");
            return true;
        }

        return false;
    }

    /**
     * Restore the queue to its default state
     *
     * @return boolean
     */
    public function actionRestore()
    {
        $this->cache->delete($this->configuration->stopCacheKey);
        $this->stdout("Restored\n");

        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Check whether the queue process should stop
     *
     * @return boolean
     */
    public function isStopped()
    {
        return $this->cache->exists($this->configuration->stopCacheKey);
    }
}