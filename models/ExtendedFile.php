<?php

namespace AspenDigital\Resizer\Models;

use AspenDigital\Resizer\Classes\ExtendedResizer as Resizer;
use File as FileHelper;
use October\Rain\Database\Attach\BrokenImage;
use System\Models\File;

/**
 * This class exposes existing functionality in protected methods.
 */
class ExtendedFile extends File
{
    /**
     * @param int $width
     * @param int $height
     * @param array $options
     * @return array [0 => Public path to thumbnail, 1 => Whether the thumbnail exists]
     */
    public function resizerThumbInfo($width, $height, $options = [])
    {
        $options = $this->getDefaultThumbOptions($options);
        $thumbFile = $this->getThumbFilename($width, $height, $options);

        return [
            $this->getPublicPath() . $this->getPartitionDirectory() . $thumbFile,
            $this->hasFile($thumbFile)
        ];
    }

    /**
     * @see parent::getDefaultThumbOptions()
     */
    public function resizerGetDefaultThumbOptions($options = [])
    {
        return $this->getDefaultThumbOptions($options);
    }
    
    /**
     * @see parent::getThumbFilename()
     */
    protected function getThumbFilename($width, $height, $options)
    {
        // Include upscale setting
        return str_replace(".$options[extension]", '_'. (empty($options['upscale']) ? 'no_up' : 'up') . ".$options[extension]", parent::getThumbFilename($width, $height, $options));
    }
    
    
    /*******************************************************************
     * The below functions are copied directly from parent, but with a *
     * different reference to the Resizer class.                       *
     *******************************************************************/
    
    
    /**
     * @inheritdoc
     */
    protected function makeThumbLocal($thumbFile, $thumbPath, $width, $height, $options)
    {
        $rootPath = $this->getLocalRootPath();
        $filePath = $rootPath.'/'.$this->getDiskPath();
        $thumbPath = $rootPath.'/'.$thumbPath;

        /*
         * Handle a broken source image
         */
        if (!$this->hasFile($this->disk_name)) {
            BrokenImage::copyTo($thumbPath);
        }
        /*
         * Generate thumbnail
         */
        else {
            try {
                Resizer::open($filePath)
                    ->resize($width, $height, $options)
                    ->save($thumbPath)
                ;
            }
            catch (Exception $ex) {
                BrokenImage::copyTo($thumbPath);
            }
        }

        FileHelper::chmod($thumbPath);
    }

    /**
     * @inheritdoc
     */
    protected function makeThumbStorage($thumbFile, $thumbPath, $width, $height, $options)
    {
        $tempFile = $this->getLocalTempPath();
        $tempThumb = $this->getLocalTempPath($thumbFile);

        /*
         * Handle a broken source image
         */
        if (!$this->hasFile($this->disk_name)) {
            BrokenImage::copyTo($tempThumb);
        }
        /*
         * Generate thumbnail
         */
        else {
            $this->copyStorageToLocal($this->getDiskPath(), $tempFile);

            try {
                Resizer::open($tempFile)
                    ->resize($width, $height, $options)
                    ->save($tempThumb)
                ;
            }
            catch (Exception $ex) {
                BrokenImage::copyTo($tempThumb);
            }

            FileHelper::delete($tempFile);
        }

        /*
         * Publish to storage and clean up
         */
        $this->copyLocalToStorage($tempThumb, $thumbPath);
        FileHelper::delete($tempThumb);
    }
}