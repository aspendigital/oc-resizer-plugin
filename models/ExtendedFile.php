<?php

namespace AspenDigital\Resizer\Models;

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
}