<?php

namespace AspenDigital\Resizer\Classes;

/**
 * Add "upscale" option (default false) to base resizer
 */
class ExtendedResizer extends \October\Rain\Database\Attach\Resizer
{
    /**
     * @inheritdoc
     */
    public static function open($file)
    {
        return new static($file);
    }
    
    public function setOptions($options)
    {
        $this->options = array_merge([
            'mode'      => 'auto',
            'offset'    => [0, 0],
            'sharpen'   => 0,
            'interlace' => false,
            'upscale'   => false,
            'quality'   => 90
        ], $options);

        return $this;
    }
    
    /**
     * @inheritdoc
     */
    public function resize($newWidth, $newHeight, $options = [])
    {
        $this->setOptions($options);
        
        /*
         * Sanitize input
         */
        $newWidth = (int) $newWidth;
        $newHeight = (int) $newHeight;

        if (!$newWidth && !$newHeight) {
            $newWidth = $this->width;
            $newHeight = $this->height;
        }
        elseif (!$newWidth) {
            $newWidth = $this->getSizeByFixedHeight($newHeight);
        }
        elseif (!$newHeight) {
            $newHeight = $this->getSizeByFixedWidth($newWidth);
        }
        
        // If not upscaling, constrain dimensions
        $mode = $this->getOption('mode');
        $upscale = $this->getOption('upscale');
        if ($mode !== 'exact' && !$upscale) {
            list($newWidth, $newHeight) = $this->limitDimensions($newWidth, $newHeight);
        }
        
        return parent::resize($newWidth, $newHeight, $options);
    }
    
    /**
     * @param int $newWidth
     * @param int $newHeight
     * @return array
     */
    protected function limitDimensions($newWidth, $newHeight)
    {
        $mode = $this->getOption('mode');
        $newRatio = $newWidth / $newHeight;
        
        if ($newWidth <= $this->width && $newHeight <= $this->height) {
            return [$newWidth, $newHeight];
        }
        
        $limitedByWidth = [$this->width, round($this->width / $newRatio)];
        $limitedByHeight = [round($this->height * $newRatio), $this->height];
        
        if ($mode === 'crop') { // Constrain crops to the limiting dimension
            $imageRatio = $this->width / $this->height;
            
            if ($newRatio >= $imageRatio && $newWidth > $this->width) {
                return $limitedByWidth;
            }
            return $limitedByHeight;
        }
        
        // Constrain to larger dimension
        if ($this->height >= $this->width && $newHeight > $this->height) {
            return $limitedByHeight;
        }
        
        return $limitedByWidth;
    }
    
}