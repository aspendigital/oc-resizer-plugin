<?php

namespace AspenDigital\Resizer\Classes;

use AspenDigital\Resizer\Jobs\ResizeFanout;
use AspenDigital\Resizer\Models\ExtendedFile;
use Config;
use File as FileHelper;
use October\Rain\Database\Attach\BrokenImage;
use Queue;
use Storage;
use System\Models\File;
use URL;

class ResizeService
{
    /** @var bool */
    public $useQueue;

    /** @var string */
    public $thumbDirName;

    /** @var array */
    protected $seen = [];

    /** @var array */
    protected $priorityConfig = [];

    /** @var string */
    protected $defaultPriority;

    /** @var array */
    protected $queues = [];

    /** @var int */
    protected $fanoutSize;


    public function __construct()
    {
        $this->useQueue = Config::get('aspendigital.resizer::useQueue');
        if ($this->useQueue === null) {
            $connection = Queue::connection();
            $this->useQueue = !($connection instanceof \Illuminate\Queue\SyncQueue || $connection instanceof \Illuminate\Queue\NullQueue);
        }

        $this->thumbDirName = Config::get('aspendigital.resizer::thumbDirName', '_resized');

        foreach (Config::get('aspendigital.resizer::priorityLevels', []) as $name=>$settings) {
            $this->priorityConfig[$name] = [
                'isPriority' => array_get($settings, 'isPriority', false),
                'queue' => array_get($settings, 'queue', null)
            ];

            $this->queues[$name] = [];
        }

        $this->defaultPriority = Config::get('aspendigital.resizer::defaultPriority', array_get(array_keys($this->priorityConfig), 0));

        $this->fanoutSize = Config::get('aspendigital.resizer::fanoutSize', 50);
    }

    /**
     * @see \System\Models\File::getThumb()
     *
     * @param File|string $image
     * @param int $width
     * @param int $height
     * @param array|string $options
     * @param string $priority
     * @return string Public path to thumbnail
     */
    public function thumb($image, $width, $height, $options = [], $priority = null)
    {
        if (!$priority) {
            $priority = $this->defaultPriority;
        }

        if ($image instanceof File) {
            return $this->modelThumb($image, $width, $height, $options, $priority);
        }
        return $this->fileThumb($image, $width, $height, $options, $priority);
    }

    public function flush()
    {
        $keys = array_keys($this->queues);
        $sent = [];
        foreach ($keys as $queue) {
            if ($this->priorityConfig[$queue]['isPriority']) {
                $sent[] = $queue;
                $this->sendQueue($queue);
            }
        }

        foreach (array_diff($keys, $sent) as $queue) {
            $this->sendQueue($queue);
        }
    }

    /**
     * @param string $fromPath
     * @param int $width
     * @param int $height
     * @param array $options
     * @return array
     */
    public function fileThumbInfo($fromPath, $width, $height, $options)
    {
        $type = 'media';
        $uploadsPath = Config::get('cms.storage.uploads.path');
        if (substr($fromPath, 0, strlen($uploadsPath)) === $uploadsPath) {
            $type = 'uploads';
            $fromPath = substr($fromPath, strlen($uploadsPath));
        }

        $storageSettings = Config::get("cms.storage.$type");

        // Use same options as used for file attachments
        $fileName = basename($fromPath);
        $extendedFile = new ExtendedFile(['file_name'=>$fileName]);
        $options = $extendedFile->resizerGetDefaultThumbOptions($options);

        $disk = Storage::disk($storageSettings['disk']);
        $diskFromPath = $storageSettings['folder'] . $fromPath;

        $hashData = $options;
        $hashData[] = (int) $width;
        $hashData[] = (int) $height;
        $hashData[] = $disk->exists($diskFromPath) ? $disk->lastModified($diskFromPath) : 0;
        $hash = md5(serialize($hashData));

        $toFileName = pathinfo($fileName, PATHINFO_FILENAME) . "-$hash.$options[extension]";
        $partition = substr($hash, 0, 3);

        return [
            'type' => $type,
            'disk' => $disk,
            'isLocal' => $storageSettings['disk'] === 'local',
            'diskFrom' => $diskFromPath,
            'diskTo' => $storageSettings['folder'] . "/$this->thumbDirName/$partition/$toFileName",
            'publicTo' => $storageSettings['path'] . '/' . join('/', array_map('rawurlencode', [$this->thumbDirName, $partition, $toFileName])),
            'options' => $options
        ];
    }

    /**
     * More or less duplicate file attachment thumb generation
     *
     * @param string $fromPath
     * @param int $width
     * @param int $height
     * @param array|string $options
     * @return string Public path to generated thumbnail
     */
    public function generateFileThumb($fromPath, $width, $height, $options)
    {
        $thumbInfo = $this->fileThumbInfo($fromPath, $width, $height, $options);
        if (!$thumbInfo['disk']->exists($thumbInfo['diskTo'])) {

            try {
                if ($thumbInfo['isLocal']) {
                    $this->generateFileThumbLocal($thumbInfo, $width, $height);
                }
                else {
                    $this->generateFileThumbStorage($thumbInfo, $width, $height);
                }

            } catch (Exception $e) {
                \Log::warning("Thumbnail generation: " . $e->getMessage());
            }
        }

        return $thumbInfo['publicTo'];
    }

    /**
     * @param string|null $queue
     */
    protected function sendQueue($queue)
    {
        if (empty($this->queues[$queue])) {
            return;
        }

        $jobs = [];
        $target = $this->priorityConfig[$queue]['queue'];
        foreach (array_chunk($this->queues[$queue], $this->fanoutSize) as $chunk) {
            $jobs[] = new ResizeFanout($chunk, $target);
        }

        Queue::bulk($jobs, '', $target);

        $this->queues[$queue] = [];
    }

    /**
     * @param File $image
     * @param int $width
     * @param int $height
     * @param array|string $options
     * @param string $priority
     * @return string Public path to generated thumbnail
     */
    protected function modelThumb(File $image, $width, $height, $options, $priority)
    {
        $index = $image->id . '-' . $width . '-' . $height . base64_encode(serialize($options));
        if (!array_key_exists($index, $this->seen)) {
            $this->seen[$index] = $this->modelThumbUncached($image, $width, $height, $options, $priority);
        }

        return $this->seen[$index];
    }

    /**
     * @see modelThumb()
     */
    protected function modelThumbUncached(File $image, $width, $height, $options, $priority)
    {
        $file = new ExtendedFile();
        $file->attributes = $image->attributes;
        list($url, $exists) = $file->resizerThumbInfo($width, $height, $options);
        if ($exists) {
            return $url;
        }

        if ($this->useQueue) {
            $this->queue($priority, ['type'=>'model', 'id'=>$file->id, 'width'=>$width, 'height'=>$height, 'options'=>$options]);
        }

        return URL::to('/_resize/model', [$image->id, $width, $height, base64_encode(serialize($options))]);
    }

    /**
     * @param string $path
     * @param int $width
     * @param int $height
     * @param array|string $options
     * @param string $priority
     * @return string Public path to generated thumbnail
     */
    protected function fileThumb($path, $width, $height, $options, $priority)
    {
        if (empty($path) || strstr($path, '://')) {
            return $path;
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $index = $path . '-' . $width . '-' . $height . serialize($options);
        if (!array_key_exists($index, $this->seen)) {
            $this->seen[$index] = $this->fileThumbUncached($path, $width, $height, $options, $priority);
        }

        return $this->seen[$index];
    }

    /**
     * @see fileThumb()
     */
    protected function fileThumbUncached($fromPath, $width, $height, $options, $priority)
    {
        $thumbInfo = $this->fileThumbInfo($fromPath, $width, $height, $options);

        if ($thumbInfo['disk']->exists($thumbInfo['diskTo'])) {
            return $thumbInfo['publicTo'];
        }

        if ($this->useQueue) {
            $this->queue($priority, ['type'=>'file', 'path'=>$fromPath, 'width'=>$width, 'height'=>$height, 'options'=>$options]);
        }

        return URL::to('/_resize/file', [base64_encode($fromPath), $width, $height, base64_encode(serialize($options))]);
    }

    /**
     * @param array $thumbInfo
     * @param int $width
     * @param int $height
     */
    protected function generateFileThumbLocal($thumbInfo, $width, $height)
    {
        $from = $thumbInfo['disk']->getAdapter()->applyPathPrefix($thumbInfo['diskFrom']);
        $to = $thumbInfo['disk']->getAdapter()->applyPathPrefix($thumbInfo['diskTo']);

        if (FileHelper::exists($from)) {
            $this->generateFileThumbBase($from, $to, $width, $height, $thumbInfo['options']);
        }
        else {
            BrokenImage::copyTo($to);
        }

        FileHelper::chmod($to);
    }

    /**
     * @param array $thumbInfo
     * @param int $width
     * @param int $height
     */
    protected function generateFileThumbStorage($thumbInfo, $width, $height)
    {
        $tempPath = temp_path('_resize');
        if (!FileHelper::exists($tempPath)) {
            FileHelper::makeDirectory($tempPath, 0777, true);
        }

        $from = $tempPath . '/' . md5($thumbInfo['publicTo']);
        $to = $from . '-to';

        if ($thumbInfo['disk']->exists($thumbInfo['diskFrom'])) {
            FileHelper::put($from, $thumbInfo['disk']->get($thumbInfo['diskFrom']));
            $this->generateFileThumbBase($from, $to, $width, $height, $thumbInfo['options']);
        }
        else {
            BrokenImage::copyTo($to);
        }

        $thumbInfo['disk']->put($thumbInfo['diskTo'], FileHelper::get($to));

        FileHelper::delete([$from, $to]);
    }

    /**
     * @param string $from Absolute local path to original image
     * @param string $to Absolute local path to write resized image
     * @param int $width
     * @param int $height
     * @param array $options
     */
    protected function generateFileThumbBase($from, $to, $width, $height, $options)
    {
        $directory = dirname($to);
        if (!FileHelper::exists($directory)) {
            FileHelper::makeDirectory($directory, 0777, true);
        }

        ExtendedResizer::open($from)
            ->resize($width, $height, $options)
            ->save($to);
    }

    /**
     * @param string $priority
     * @param array $data
     */
    protected function queue($priority, $data)
    {
        $this->queues[$priority][] = $data;
        if ($this->priorityConfig[$priority]['isPriority'] && count($this->queues[$priority]) >= $this->fanoutSize) {
            $this->sendQueue($priority);
        }
    }
}
