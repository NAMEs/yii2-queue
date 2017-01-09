<?php
/**
 *
 */

namespace tests\helpers;


use djeux\queue\interfaces\Queueable;
use djeux\queue\jobs\BaseJob;
use yii\helpers\FileHelper;

class TestJob implements Queueable
{
    private $content;

    public function __construct($content = '')
    {
        $this->content = $content;
    }

    public function handle(BaseJob $job)
    {
        $dir = __DIR__ . '/../runtime';
        if (!is_dir($dir)) {
            FileHelper::createDirectory($dir);
        }
        $filename = $dir . DIRECTORY_SEPARATOR . 'touch.txt';
        if (file_exists($filename)) {
            unlink($filename);
        }

        $fh = fopen($filename, 'a');
        fwrite($fh, $this->content);
        fclose($fh);

        $job->delete();
    }

    public function makeFile(BaseJob $job, $data)
    {
        $dir = __DIR__ . '/../runtime';
        if (!is_dir($dir)) {
            FileHelper::createDirectory($dir);
        }
        $filename = $dir . DIRECTORY_SEPARATOR . 'touch.txt';
        if (file_exists($filename)) {
            unlink($filename);
        }

        $fh = fopen($filename, 'a');
        fwrite($fh, $data);
        fclose($fh);

        $job->delete();
    }
}